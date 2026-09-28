# Changelog

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
