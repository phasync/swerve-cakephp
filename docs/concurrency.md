# Concurrency

`Handler` runs one CakePHP request at a time per worker, holding a `phasync\Util\Synchronized`
lock around it (`src/Handler.php`). The same lock is held around the `Server.terminate`
listeners and around a `CallbackStream`'s callback, which run after the request but still as the
worker's own work. Capacity scales with `--workers`: size it as PHP-FPM's `pm.max_children`. A
request that waits (a database query, `usleep()`) holds its worker until it is done, exactly as a
PHP-FPM worker would; swerve's own work (accepting connections, static files, WebSockets and
streams after their response) goes on meanwhile, since none of it needs the lock.

## Why a lock

CakePHP keeps the following in static properties and superglobals: one copy per worker process,
not per request.

- **The current request and URL.** `Router`'s current request and request context are static,
  set at every middleware step; `Router::getRequest()` and `Router::url()` read them.
- **`Configure`.** `Configure::read()` and `Configure::write()` work on one static array.
- **Locale and time zone.** `I18n::setLocale()` sets the process's default locale;
  `date_default_timezone_set()` sets the process's time zone.
- **The global event manager.** `EventManager::instance()` is a static singleton: a listener one
  request attaches fires for every other request too.
- **Tables.** `FactoryLocator`'s table registry is static.
- **The container.** The application keeps one container and controller factory, added to as
  each request is handled.
- **The session.** Cake's `Session` reads and writes `$_SESSION`, one array for the process.
- **Output buffers.** Views render into `ob_start()`; the buffer stack is the process's.

Two overlapping requests would read and write all of this as if they were one request.
`tests/Feature/IsolationTest.php` pins that the lock keeps them apart: of ten requests that
overlap deliberately, each gets back only its own route, container, session, authenticated
identity, `Configure` value, locale, time zone, event and table, and a stream and a page rendered
at the same time don't mix their output.

`$_GET`, `$_POST`, `$_COOKIE`, `$_FILES` and `$_SERVER` are not a reason: the adapter builds
Cake's request from swerve's (`src/RequestFactory.php`), not from superglobals.

## The session

The adapter's `Session` (`src/Session.php`) uses PHP's session module and Cake's configured
engine, as under PHP-FPM, with one difference the lack of a real per-request process needs: the
session id comes from the request's cookie, not from PHP's own cookie lookup, and the
`Set-Cookie` and cache headers PHP would send with `header()` are returned by `finish()` and
added to the response instead. `finish()` also clears `$_SESSION` and forgets the id, so a
visitor without a session cookie gets a new session on the next request, never the previous
visitor's; `renew()` (Cake's name for regenerating the id, used at login) gives a new one.

Only the request's own code may read or write the session: `started()` checks that it still runs
in the request's own `Scope`, and throws `LogicException` otherwise. A coroutine that outlives
its request, such as a WebSocket callback, would otherwise see whichever request's session the
worker has open by the time it runs, or restart the last one's.

## What this means for your code

- **A request that waits blocks the rest of its worker.** Add workers for concurrency, sized
  like PHP-FPM's `pm.max_children`.
- **Move waiting or long-lived work out of the locked part.** A WebSocket callback
  (`WebSocketResponse::from()`) runs after its request, beside the worker's next Cake requests,
  without the lock: see [WebSockets](../README.md#websockets). A stream filled from a coroutine
  with `phasync::go()` (the Server-Sent Events pattern in [Before you
  deploy](../README.md#before-you-deploy)) runs the same way: the worker keeps serving other
  requests while it waits. Code that runs inside the request itself, or in a `Server.terminate`
  listener or a `CallbackStream` callback, stays serialized with the worker's other Cake
  requests.
- **Routes are connected once per worker**, not reconnected under the lock: a route connected
  during a request, or logic depending on one, doesn't apply until the worker reloads
  (`--watch`, `SIGHUP`).

## What could remove the lock

Dropping it would need CakePHP's state above to be per-request rather than per-process, which
has two routes; the adapter uses neither for this today.

- **Scope's own per-request state.** Swerve runs each request in a `\Scope` of its own
  (`Scope::getCurrent()` identifies it). With the phasync extension's `Scope` component loaded
  (or its userland polyfill), `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, the default
  time zone and the `intl` default locale already follow the current scope: a new scope gets its
  own copies, and a write in one doesn't reach another's. This adapter already builds Cake's
  request from swerve's rather than the superglobals, and still saves and restores the locale and
  the time zone itself (`src/Handler.php`) rather than relying on this. `$_SESSION` is explicitly
  left out of this per-scope state, so it would need the other route.
- **`phasync::onScopeChange(callable $fn)`.** Called with the new and the previous `Scope`
  whenever the coroutine the event loop runs next belongs to a different one, it is where
  process-global state that isn't scope-aware can be swapped for a `WeakMap<\Scope, …>` entry
  instead. `Router`'s current request and context, `Configure`'s values, `FactoryLocator`'s
  table registry, `EventManager::instance()` and `$_SESSION` are all read and written as
  CakePHP's own static properties and superglobal, directly, not through a lookup this adapter
  controls; moving any of them off the lock this way would need CakePHP itself to read a
  per-scope store instead, which it doesn't today.

## Design notes

- **One lock, not a narrower one.** The state above is used from the first middleware to the
  last byte of the view, which is where a request can wait; a lock around less than that would
  let another request run into it.
- **Reset before each request, not swapped around every wait inside one.** Cake reads its
  statics directly (`Router::$_request`, `Configure`'s values, and the rest), not from a
  per-scope store, so nothing swaps them automatically when a request's own coroutines switch.
  `Handler` puts them back as they were right after boot before each request starts, which is
  the same moment `phasync::onScopeChange()` would fire for a request's `Scope` (see above), done
  directly instead of as a registered hook.
- **A pool of applications doesn't avoid the lock.** CakePHP keeps the state above in static
  properties of the framework's own classes, not the application instance's, so a second
  `Cake\Http\BaseApplication` booted in the same worker would still share all of it with the
  first.
