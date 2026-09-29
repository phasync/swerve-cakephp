<?php

namespace Swerve\CakePHP;

use Cake\Core\Configure;
use Cake\Core\ContainerApplicationInterface;
use Cake\Core\HttpApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Event\EventManager;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Http\Response;
use Cake\Http\Runner;
use Cake\Http\Server;
use Cake\Http\UriFactory;
use Cake\I18n\I18n;
use Cake\Routing\Middleware\RoutingMiddleware as CakeRoutingMiddleware;
use Cake\Routing\Router;
use DebugKit\DebugKitPlugin;
use Laminas\Diactoros\CallbackStream;
use phasync\Psr\UnbufferedStream;
use phasync\Util\Synchronized;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A CakePHP application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\CakePHP\Handler(__DIR__);
 *
 * Once per worker: the application is bootstrapped with its plugins, as Cake\Http\Server does
 * it, its routes connected, and the session configured. The state after that is kept.
 *
 * Per request, one at a time per worker: Configure, Router's request and base URL, the global
 * event manager, the application's container, the table registry, DebugKit's panels, the locale
 * and the time zone are put back as they were after boot, as a fresh PHP-FPM process has them.
 * swerve's request becomes a Cake\Http\ServerRequest and runs through the middleware queue,
 * built for it as Cake\Http\Server::run() builds it, with our RoutingMiddleware in place of
 * Cake's; the session is written and closed. Cookies of the response's CookieCollection, which
 * only Cake's ResponseEmitter would send, become Set-Cookie headers.
 *
 * Concurrency: Cake keeps the current request, Configure, the locale, the global event manager
 * and the table registry in static properties, and $_SESSION and the output buffers are the
 * process's: two requests of a worker must not overlap. Requests, Server.terminate listeners and
 * CallbackStream callbacks wait for each other with phasync\Util\Synchronized; a worker runs one
 * Cake request at a time, as a PHP-FPM process does, while swerve's own work (connections,
 * static files, WebSockets and streams after their response) goes on. See docs/concurrency.md.
 */
final class Handler implements RequestHandlerInterface
{
    private readonly HttpApplicationInterface $app;
    private readonly Server $server;
    private readonly Session $session;
    /** Puts the state that a request may change back as it was after boot. */
    private readonly \Closure $reset;
    /** Replaces Cake's RoutingMiddleware in a middleware queue with ours, which doesn't connect the routes again. */
    private readonly \Closure $routing;

    /**
     * @param string $root        the application's root directory, where composer.json is
     * @param string $application the application class
     */
    public function __construct(string $root, string $application = 'App\Application')
    {
        $this->app    = $app = new $application($root . '/config');
        $this->server = new Server($app);

        $app->bootstrap();
        if ($app instanceof PluginApplicationInterface) {
            $app->pluginBootstrap();
        }
        // What RoutingMiddleware does for each request, once
        $builder = Router::createRouteBuilder('/');
        $app->routes($builder);
        if ($app instanceof PluginApplicationInterface) {
            $app->pluginRoutes($builder);
        }
        $this->routing = \Closure::bind(static function (MiddlewareQueue $queue) {
            foreach ($queue->queue as $i => $middleware) {
                if ($middleware instanceof CakeRoutingMiddleware && CakeRoutingMiddleware::class === $middleware::class) {
                    $queue->queue[$i] = RoutingMiddleware::from($middleware);
                }
            }
        }, null, MiddlewareQueue::class);

        // As ServerRequestFactory::fromGlobals() configures it, once: PHP's session settings are the process's
        ['webroot' => $webroot] = UriFactory::marshalUriAndBaseFromSapi(['REQUEST_URI' => '/']);
        $this->session          = Session::create((array) Configure::read('Session') + ['defaults' => 'php', 'cookiePath' => $webroot]);

        $configure = Configure::read();
        $events    = \Closure::bind(static function () {
            $manager = EventManager::instance();
            $state   = [$manager->_listeners, $manager->_eventList, $manager->_trackEvents];

            return static function () use ($manager, $state) {
                [$manager->_listeners, $manager->_eventList, $manager->_trackEvents] = $state;
            };
        }, null, EventManager::class)();
        $locale    = I18n::getLocale();
        $timezone  = \date_default_timezone_get();
        $router    = \Closure::bind(static function () {
            $state = [static::$_fullBaseUrl, static::$_requestContext];

            return static function () use ($state) {
                [static::$_fullBaseUrl, static::$_requestContext] = $state;
                static::$_request                                 = null;
            };
        }, null, Router::class)();
        $values    = \Closure::bind(static fn (array $values) => static::$_values = $values, null, Configure::class);
        // DebugKit loads its panels for each request, into a registry it creates once
        $debugKit  = $app instanceof BaseApplication && $app->getPlugins()->has('DebugKit') ? \Closure::bind(fn () => $this->service, $app->getPlugins()->get('DebugKit'), DebugKitPlugin::class)() : null;
        $container = \Closure::bind(static function (BaseApplication $app) {
            $app->container         = null;
            $app->controllerFactory = null;
        }, null, BaseApplication::class);

        $this->reset = static function () use ($app, $configure, $events, $locale, $timezone, $router, $values, $container, $debugKit) {
            $values($configure);
            $router();
            $events();
            $debugKit?->registry()->reset();
            FactoryLocator::get('Table')->clear();
            I18n::setLocale($locale);
            \date_default_timezone_set($timezone);
            if ($app instanceof BaseApplication) {
                $container($app);
            }
        };
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Synchronized::run($this, fn () => $this->run($request));
    }

    private function run(ServerRequestInterface $request): ResponseInterface
    {
        ($this->reset)();
        $this->session->begin($request->getCookieParams());
        $cake = RequestFactory::fromPsr($request, $this->session);
        try {
            // As Cake\Http\Server::run() builds it: middleware such as DebugKit's keeps a request's state
            $app        = $this->app;
            $middleware = $app->middleware(new MiddlewareQueue([], $app instanceof ContainerApplicationInterface ? $app->getContainer() : null));
            if ($app instanceof PluginApplicationInterface) {
                $middleware = $app->pluginMiddleware($middleware);
            }
            $this->server->dispatchEvent('Server.buildMiddleware', ['middleware' => $middleware]);
            ($this->routing)($middleware);
            $response = (new Runner())->run($middleware, $cake, $app);
        } finally {
            $headers = $this->session->finish();
        }

        // Cake keeps cookies beside the headers; its ResponseEmitter sends them
        if ($response instanceof Response) {
            foreach ($response->getCookieCollection() as $cookie) {
                $response = $response->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
            }
        }
        foreach ($headers as $name => $value) {
            if ('Set-Cookie' === $name) {
                $response = $response->withAddedHeader($name, $value);
            } elseif (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        if ($this->server->getEventManager()->listeners('Server.terminate')) {
            $terminated = Router::getRequest() ?? $cake;
            \phasync::go(fn () => Synchronized::run($this, fn () => $this->server->dispatchEvent('Server.terminate', ['request' => $terminated, 'response' => $response])));
        }

        // Cake's streamed responses have a CallbackStream, whose callback echoes the body
        $body = $response->getBody();
        if ($body instanceof CallbackStream && null !== $callback = $body->detach()) {
            $response = $response->withBody($stream = new UnbufferedStream());
            \phasync::go(fn () => Synchronized::run($this, fn () => self::stream($callback, $stream)));
        }

        return $response;
    }

    /**
     * Run a CallbackStream's callback, sending what it echoes, and returns, as it comes. It runs
     * while no Cake request does, since PHP's output buffers are the process's.
     */
    private static function stream(callable $callback, UnbufferedStream $stream): void
    {
        $gone  = false;
        $level = \ob_get_level();
        \ob_start(static function (string $chunk) use ($stream, &$gone): string {
            if ('' !== $chunk && !$gone) {
                try {
                    $stream->append($chunk);
                } catch (\Throwable) {
                    $gone = true; // the client left
                }
            }

            return '';
        }, 1);
        try {
            $result = $callback();
            if (\is_string($result)) {
                echo $result;
            }
        } finally {
            while (\ob_get_level() > $level) {
                \ob_end_flush();
            }
            $stream->end();
        }
    }
}
