# Concurrency

`Handler` runs one CakePHP request at a time per worker, with `phasync\Util\Synchronized`. A
request that waits (a database query, `usleep()` with phasync-ext) holds its worker, and the
next request of that worker waits for it: size `--workers` as for PHP-FPM's `pm.max_children`.
swerve's own work (accepting connections, static files, WebSockets and streams after their
response) goes on meanwhile.

The lock is taken in three places (`src/Handler.php`): around each request, around the
`Server.terminate` listeners, and around a `CallbackStream`'s callback.

## Why

Each reason below is shown by `tests/Feature/IsolationTest.php`, which passes with the lock. It
runs one worker; ten overlapping requests each store their own value, wait halfway (every other
one also waits in a middleware first), and read it back. With the lock removed, three rounds
gave the counts below, the same without phasync-ext, with it, and with the request run through
`Swerve\Http\Virtual::run()` (phasync-ext's `virtualize()`), except where noted. Vendor paths are
under `vendor/cakephp/cakephp/src/`.

- **The current request.** `Router::$_request` and `Router::$_requestContext` are static
  (`Routing/Router.php:107`, `:128`), set by `Router::setRequest()` at every middleware step
  (`Http/Runner.php:75`). `Router::getRequest()` gave another request's route parameter in 27 of
  30 requests, and `Router::url('/', true)` another request's host.
- **Configure.** One static array (`Core/Configure.php:41`): `Configure::read()` gave another
  request's value in 27 of 30.
- **Locale and time zone.** `I18n::setLocale()` sets the process's `Locale::setDefault()`
  (`I18n/I18n.php:271`); `date_default_timezone_set()` is the process's. Both leaked in 27 of 30,
  also under `virtualize()`.
- **The global event manager.** `EventManager::instance()` is a static singleton
  (`Event/EventManager.php:45`): a listener a request attaches fires in the others (30 of 30
  saw several).
- **Tables.** `FactoryLocator`'s registry is static (`Datasource/FactoryLocator.php:32`): a
  table fetched again after the wait was another request's, or a new one (27 of 30).
- **The container.** `BaseApplication` keeps one container (`Http/BaseApplication.php:306`) and
  adds each request to it (`:372`), with the controller's component registry
  (`Controller/ControllerFactory.php:86`). When a request waits before its controller, the
  injected `ServerRequest`, the container's `ServerRequest` and the Authentication identity were
  another request's in 12 of 30 (15 under `virtualize()`).
- **The session.** Cake's `Session` reads and writes `$_SESSION` (`Http/Session.php:437`,
  `:462`, `:528`), one per process. With one `Session` per request, the value read back was
  wrong in 30 of 30, also under `virtualize()`, which gives each request its own `session_id()`
  but not its own `$_SESSION` (phasync-ext 0.5.0-alpha13). With the adapter's one `Session` per
  worker, 19 of 30 failed at once: a request's `begin()` takes the session from the one before
  (`src/Session.php:56`, `:66`).
- **Output buffers.** Views render into `ob_start()` (`View/View.php:1191`, `:1204`); the stack
  is the process's. An element that waits halfway got another request's output first (30 of 30).
  `virtualize()` removes this one: 0 of 30.
- **The adapter's reset.** `Handler` puts Configure, Router, the event manager, the table
  registry, the locale and the time zone back as they were after boot as each request starts
  (`src/Handler.php:118`): a request starting while another waits wipes the other's state.
- **`Server.terminate`.** Its listeners do "potentially heavy tasks" (`Http/Server.php:123`) and
  are application code like any request: one that waits, then writes Configure, overwrote the
  value of the request running meanwhile ("runs Server.terminate listeners between requests"
  fails without that lock, in all three configurations).
- **Streamed bodies.** A `CallbackStream`'s callback echoes, and `Handler::stream()` catches it
  with `ob_start()`. Without that lock, a page rendered meanwhile sent `v1:` into the stream and
  rendered nothing ("keeps a streamed body and a page rendered meanwhile apart"). Under
  `virtualize()`, with the callback run inside the request, it passes.

`$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` and `$_SERVER` are not a reason: the adapter builds
Cake's request from swerve's (`src/RequestFactory.php`), not from superglobals.

## What was tried

- **A pool of applications**, as swerve-symfony pools kernels. A second `Application`, booted
  in the same worker, costs 0.3 ms and 5 KiB (the first: 22 ms, 2 MiB), because Cake's state is
  static rather than the application's. That is also why it fails: with a pool, the container
  and identity no longer leaked (0 of 30), and everything static above still did.
- **Resetting or rebinding per request.** Cake reads its statics directly (`static::$_request`,
  `static::$_values`), and phasync has no hook on coroutine switches to swap them; the adapter's
  reset can only run between requests.
- **`Virtual::run()`.** Removes output buffers only; see above.
- **A narrower lock.** The state is used from the first middleware to the last byte of the
  view, which is where requests wait; a lock around less than that leaks as above.
