# swerve for CakePHP

[![CI](https://github.com/phasync/swerve-cakephp/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-cakephp/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-cakephp)](https://packagist.org/packages/phasync/swerve-cakephp)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-cakephp/php)](https://packagist.org/packages/phasync/swerve-cakephp)
![License](https://img.shields.io/github/license/phasync/swerve-cakephp)

**Your CakePHP application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a CakePHP application unchanged.

```bash
composer config minimum-stability beta   # while swerve is in beta
composer config prefer-stable true         # everything else stays stable
composer require phasync/swerve-cakephp
```

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\CakePHP\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=webroot swerve.php
```

That's the whole setup. `webroot/index.php` stays as it is, so the same application still runs
under PHP-FPM. An application class other than `App\Application` is named with
`new Handler(__DIR__, application: My\Application::class)`.

## WebSockets

A controller action answers a WebSocket with `Swerve\Http\WebSocket::from()`. Cake wants its own
`Response` from an action; `CakeResponse::from()` makes one of the 101, headers and connection
unchanged:

```php
use Cake\Http\Response;
use Swerve\CakePHP\CakeResponse;
use Swerve\Http\WebSocket;

public function echo(): Response
{
    return CakeResponse::from(WebSocket::from($this->request, function (WebSocket $ws) {
        foreach ($ws as $message) {      // ends when the client leaves
            $ws->send("echo: $message");
        }
    }));
}
```

An ordinary GET to the action is answered 426. The callback runs in a coroutine of its own after
the action has returned, beside the worker's next Cake requests: an open WebSocket doesn't hold
the worker's turn (the tests serve ordinary requests promptly with 250 open in one worker).

**Server push.** A callback that only forwards a topic ends when its client leaves; any route, in
any worker, publishes:

```php
public function news(): Response
{
    return CakeResponse::from(WebSocket::from($this->request, function (WebSocket $ws) {
        foreach (Swerve::subscribe('news') as $message) {
            $ws->send($message);
        }
    }));
}

public function post(): Response
{
    Swerve::publish('news', json_encode($this->request->getData()));

    return $this->response->withStatus(204);
}
```

**The user.** Take what the callback needs from the request before `WebSocket::from()`:

```php
$user = $this->Authentication->getIdentity();         // or the session's:
$room = $this->request->getSession()->read('room');

return CakeResponse::from(WebSocket::from($this->request, function (WebSocket $ws) use ($user, $room) {
    // $user and $room are this connection's
}));
```

Inside the callback the request is over. `Router::getRequest()` and `Router::url()` describe the
request the worker serves last, maybe another user's; the session throws a `LogicException`
(reading it there would read whichever session the worker has open then). Don't use Cake's
database connection in the callback either: it belongs to the worker's requests, which run
meanwhile. A shutdown or reload closes open WebSockets with 1001.

## What changes

| CakePHP 5.4 skeleton, 4 workers | PHP-FPM | swerve | swerve + phasync-ext |
|---|---:|---:|---:|
| JSON route | 2,369 req/s | 9,060 req/s (3.8×) | 9,257 req/s (3.9×) |
| Page with session | 2,279 req/s | 7,462 req/s (3.3×) | 7,729 req/s (3.4×) |

PHP-FPM behind nginx against swerve, `wrk -t4 -c64 -d10s`, opcache on, debug off, on a Xeon
E5-2697 v3. The routes are two controller actions of the test application; the session is
PHP's file handler. [Script and raw results](benchmarks/).

A request costs about 0.4 ms of CPU in a warm worker, so a worker serves about 2,300 a
second. Most of it is Cake's own work, the same under PHP-FPM: the middleware (CSRF tokens,
routing), the controller with its components, and building Cake's `ServerRequest` (about
45 µs, from swerve's request here, from PHP's globals under PHP-FPM). What this package adds
(state put back, container and middleware queue built for each request) is about 25 µs.

## How it runs

- **Once per worker:** the application is bootstrapped with its plugins, as
  `Cake\Http\Server` does it, its routes connected, and the session configured.
- **Per request:** `Configure`, the router's current request and base URL, the global event
  manager, the application's container, the table registry, DebugKit's panels, the locale and
  the time zone are put back as they were after boot, as a fresh PHP-FPM process has them. The
  middleware queue is built as `Cake\Http\Server::run()` builds it, with a `RoutingMiddleware`
  that doesn't connect the routes again; swerve's request becomes a `Cake\Http\ServerRequest`,
  with its body and uploaded files, and runs through it. The response's cookies, which only
  Cake's `ResponseEmitter` would send, become `Set-Cookie` headers.
- **Concurrency:** one Cake request at a time per worker, with `phasync\Util\Synchronized`:
  size `--workers` as PHP-FPM's `pm.max_children`. Cake keeps the current request, Configure,
  the locale, the global event manager and the table registry in static properties, and
  `$_SESSION` and the output buffers are the process's: overlapping requests see each other's.
  swerve's own work (connections, static files, WebSockets and streams after their response)
  goes on meanwhile. The evidence, and what was tried: [docs/concurrency.md](docs/concurrency.md).
- **Sessions:** PHP's session module and Cake's configured engine, as under PHP-FPM, shared by
  the workers. The session id comes from the request's cookie, the `Set-Cookie` and no-cache
  headers PHP would send go into the response, and the id is forgotten after each request, so a
  visitor without a cookie gets a new session. `renew()` (at login) gives a new id. Only the
  request's own code may use it: a coroutine that outlives the request, such as a WebSocket
  callback, gets a `LogicException`.

## Before you deploy

- `exit` and `dd()` end the worker, and the requests it is serving with it.
- **Configuration.** Set `APP_FULL_BASE_URL` and `SECURITY_SALT` (and `DEBUG=false`, since
  `app_local.php` defaults to debug on), as for PHP-FPM: with debug off the skeleton's
  `HostHeaderMiddleware` refuses every request while `App.fullBaseUrl` is empty. With debug on,
  full URLs come from each request's `Host` header.
- **One request at a time per worker.** A worker waiting for the database waits alone, as a
  PHP-FPM process does; size `--workers` as you would `pm.max_children`.
- **Routes are connected once per worker.** Routes connected during a request, or depending on
  it, don't apply. `--watch` and a reload (`SIGHUP`) pick up changed routes.
- **Cake sees the CLI** (`PHP_SAPI` is `cli`): the skeleton's `config/bootstrap.php` logs to
  `logs/cli-debug.log` and `logs/cli-error.log`, plugins marked `onlyCli` (Bake, Migrations) are
  loaded, and in debug mode PHP warnings and notices go to the worker's standard error instead
  of the page.
- **Echoed streams hold the worker.** A `CallbackStream` whose callback echoes (Cake's streamed
  responses, `JsonStreamResponse`) is sent as it echoes, but runs while no other Cake request
  does: PHP's output buffers belong to the process. For Server-Sent Events and other long
  streams, fill a stream from a coroutine instead; the worker serves other requests meanwhile:

  ```php
  $stream = new \phasync\Psr\UnbufferedStream(1, 60);
  \phasync::go(function () use ($stream) {
      foreach (\Swerve\Swerve::subscribe('prices') as $price) {
          $stream->append("data: $price\n\n");
      }
  });

  return $this->response->withType('text/event-stream')->withBody($stream);
  ```

  Such a coroutine runs beside the worker's Cake requests: don't use Cake in it (the router,
  the session, the ORM's database connection).
- **WebSocket callbacks** and other coroutines run after their request: see
  [WebSockets](#websockets) for what they may take from Cake.
- **`Server.terminate` listeners** run after the response is handed to swerve, in turn with
  the worker's requests, with the session closed.
- **Static state of your own** (static properties, singletons) lives as long as the worker;
  `Configure::write()` during a request is undone after it.

## Compatibility

| CakePHP | PHP | phasync-ext |
|---|---|---|
| 5.1 – 5.4 | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
