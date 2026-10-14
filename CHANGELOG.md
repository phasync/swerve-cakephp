# Changelog

## Unreleased

- Requires phasync and swerve `dev-main`, and swerve-psr15 (from its GitHub repository, until it is on
  Packagist): swerve serves an exchange with a `Swerve\RequestHandler` over a `Swerve\ClientRequest`,
  no longer a PSR-15 handler. `Handler` stays a PSR-15 handler, served through swerve-psr15's `Bridge`.
- The `cakephp` adapter: `vendor/bin/swerve --adapter=cakephp` serves `App\Application` with no
  `swerve.php`. A `swerve.php` (for another application class) returns
  `Swerve\Psr15\Bridge::wrap(new Swerve\CakePHP\Handler(__DIR__))` and runs with `--adapter=swerve`.
- WebSockets: `CakeResponse::from(Swerve\Psr15\WebSocketResponse::from($this->request, ...))`; the
  callback gets swerve's `Swerve\WebSocket` (`Swerve\Http\WebSocket` is gone from swerve).
- The session tells its request by the request's `Scope` (phasync's contexts are gone).
- The handler loads the application's Composer autoloader: swerve's worker loads only swerve's own
  dependencies.
- A stopping worker ends its requests at once (swerve's new stop): a request in flight is answered
  `503` by swerve. Cake's error middleware neither logs that `WorkerStoppingException` nor renders it.
- A `CallbackStream`'s output handler only collects what the callback echoes; a coroutine of its own
  appends it to the response body. PHP calls output handlers from C, where a wait (the body's
  back-pressure) need not let other coroutines run: with phasync-ext the worker would wait for a
  reader that could not run.

## 0.1.0-alpha2

- `CakeResponse::from()`: Cake's Response from any PSR-7 response, so a controller action
  returns `WebSocket::from()`'s 101 in one line.
- The session refuses code outside its request with a `LogicException`: a WebSocket callback
  that read it got the session of the worker's last request, another visitor's.
- Requires phasync/swerve 0.1.0-alpha15: a callback that only forwards a subscription ends
  when its client leaves.
- Tests: WebSockets both ways, server push over two workers, clients leaving, identity,
  250 open sockets in one worker, drain with 1001, refusal with 426.

## 0.1.0-alpha1

- First release: CakePHP 5 on swerve.
