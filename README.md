# swerve for CakePHP

[![CI](https://github.com/phasync/swerve-cakephp/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-cakephp/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-cakephp)](https://packagist.org/packages/phasync/swerve-cakephp)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-cakephp/php)](https://packagist.org/packages/phasync/swerve-cakephp)
![License](https://img.shields.io/github/license/phasync/swerve-cakephp)

**Your CakePHP application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a CakePHP application unchanged.

A worker runs one CakePHP request at a time, behind a lock: `Router`'s current request,
`Configure`, the global event manager, the table registry, the locale, the time zone, the
session and the output buffers are CakePHP's static, per-process state, so two requests of a
worker must not use them at once. A request that waits (a database query, a remote call) holds
its worker meanwhile, exactly as a PHP-FPM worker would: size `--workers` as `pm.max_children`.
A WebSocket callback or a stream fed from a coroutine runs outside that lock, beside the
worker's other requests; see [WebSockets](#websockets) and [docs/concurrency.md](docs/concurrency.md).

The framework running correctly on swerve does not mean your application does: state your own
code keeps in statics, globals or shared services, and connections shared between requests, need
the same care. See [Making your application safe for concurrent requests](https://phasync.github.io/guides/concurrent-requests-in-your-application/).
[phasync/doctor](https://github.com/phasync/doctor) lists the candidates in your code:
`composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`.

```bash
composer require phasync/swerve-cakephp
```

```bash
vendor/bin/swerve --adapter=cakephp --http=0.0.0.0:8080 --public=webroot
```

That's the whole setup: the `cakephp` adapter serves `App\Application`. `--adapter=cakephp` picks
it over swerve-psr15's adapter, which comes with it (or put `"extra": {"swerve": {"adapter":
"cakephp"}}` in your `composer.json`). `webroot/index.php` stays as it is, so the same application
still runs under PHP-FPM. Another application class, or other settings, take a `swerve.php`, run
with `--adapter=swerve`:

```php
<?php // swerve.php, next to composer.json

return Swerve\Psr15\Bridge::wrap(new Swerve\CakePHP\Handler(__DIR__, application: My\Application::class));
```

The handler is a PSR-15 request handler, which [swerve-psr15](https://github.com/phasync/swerve-psr15)'s
`Bridge` serves.

## WebSockets

A controller action answers a WebSocket with swerve-psr15's `WebSocketResponse::from()`, and its
callback gets swerve's `WebSocket`. Cake wants its own `Response` from an action;
`CakeResponse::from()` makes one of the 101, headers and body unchanged:

```php
use Cake\Http\Response;
use Swerve\CakePHP\CakeResponse;
use Swerve\Psr15\WebSocketResponse;
use Swerve\WebSocket;

public function echo(): Response
{
    return CakeResponse::from(WebSocketResponse::from($this->request, function (WebSocket $ws) {
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
    return CakeResponse::from(WebSocketResponse::from($this->request, function (WebSocket $ws) {
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

**The user.** Take what the callback needs from the request before `WebSocketResponse::from()`:

```php
$user = $this->Authentication->getIdentity();         // or the session's:
$room = $this->request->getSession()->read('room');

return CakeResponse::from(WebSocketResponse::from($this->request, function (WebSocket $ws) use ($user, $room) {
    // $user and $room are this connection's
}));
```

Inside the callback the request is over. `Router::getRequest()` and `Router::url()` describe the
request the worker serves last, maybe another user's; the session throws a `LogicException`
(reading it there would read whichever session the worker has open then). Don't use Cake's
database connection in the callback either: it belongs to the worker's requests, which run
meanwhile. A shutdown or reload closes open WebSockets with 1001.

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
  `$_SESSION` and the output buffers are the process's: overlapping requests would see each
  other's, so the lock keeps them apart. swerve's own work (connections, static files,
  WebSockets and streams after their response) goes on meanwhile. Details:
  [docs/concurrency.md](docs/concurrency.md).
- **Sessions:** PHP's session module and Cake's configured engine, as under PHP-FPM, shared by
  the workers. The session id comes from the request's cookie, the `Set-Cookie` and no-cache
  headers PHP would send go into the response, and the id is forgotten after each request, so a
  visitor without a cookie gets a new session. `renew()` (at login) gives a new id. Only the
  request's own code may use it: a coroutine that outlives the request, such as a WebSocket
  callback, gets a `LogicException`.

## Before you deploy

- `exit` and `dd()` end the worker, and the requests it is serving with it.
- **A stop ends the requests in flight.** On a shutdown or reload swerve stops a worker at once:
  a request still waiting then ends, answered `503` by swerve when nothing was sent yet (Cake
  neither logs it nor renders an error page); clients retry.
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

| CakePHP | PHP | ext-phasync |
|---|---|---|
| 5.1 – 5.4 | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
