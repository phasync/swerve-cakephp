<?php

namespace Swerve\CakePHP;

use Cake\Http\Cookie\Cookie;

/**
 * CakePHP's Session, for a worker that serves one request after another.
 *
 * Cake's own Session sees PHP_SAPI 'cli' and pretends: one fake session, id 'cli', nothing
 * stored. This one uses PHP's session module and Cake's configured engine as under PHP-FPM, with
 * two differences that the CLI requires. The session id comes from the request's cookie, not
 * from PHP (its cookie lookup reads the process's $_COOKIE); and the Set-Cookie and cache
 * headers PHP would send with header() are returned by finish() instead, since header() goes
 * nowhere in the CLI. Only the `nocache` cache limiter (PHP's default) sends headers.
 *
 * Nothing here depends on headers_sent(), which in the CLI turns true for the rest of the
 * worker's life at the first `echo`: the session keeps working after one.
 *
 * @internal created and driven by Handler, one per worker
 */
final class Session extends \Cake\Http\Session
{
    /** The id the request's cookie names, if it is one PHP accepts. */
    private ?string $cookieId = null;

    /** Whether this request started the session. */
    private bool $used = false;

    private string $cacheLimiter;

    public function __construct(array $config = [])
    {
        $this->cacheLimiter = (string) \ini_get('session.cache_limiter');
        parent::__construct($config);
        $this->_isCLI = false;
    }

    public function options(array $options): void
    {
        $this->cacheLimiter = (string) ($options['session.cache_limiter'] ?? $this->cacheLimiter);
        parent::options(['session.use_cookies' => 0, 'session.use_trans_sid' => 0, 'session.cache_limiter' => ''] + $options);
    }

    /** A request begins: its cookies name its session, if any. */
    public function begin(array $cookies): void
    {
        $id             = $cookies[\session_name()] ?? null;
        $this->cookieId = \is_string($id) && 1 === \preg_match('/^[a-zA-Z0-9,-]{1,256}$/D', $id) ? $id : null;
        $this->_started = false;
        $this->used     = false;
    }

    public function start(): bool
    {
        if (!$this->_started && \PHP_SESSION_ACTIVE !== \session_status()) {
            \session_id($this->cookieId ?? '');
        }

        return $this->used = parent::start() || $this->used;
    }

    protected function _hasSession(): bool
    {
        return null !== $this->cookieId;
    }

    /**
     * What session_regenerate_id(true) does, which Cake's renew() calls: it refuses once
     * anything was echoed, and Cake's setcookie() for the old id would go nowhere anyway.
     */
    public function renew(): void
    {
        if (!$this->_hasSession()) {
            return;
        }
        $this->start();
        $data = $_SESSION;
        \session_destroy();
        \session_id(\session_create_id());
        \session_start();
        $_SESSION = $data;
    }

    /**
     * The request ends: write and close the session, forget it for the next request, and return
     * the headers PHP would have sent for it.
     *
     * @return array<string, string>
     */
    public function finish(): array
    {
        if (\PHP_SESSION_ACTIVE === \session_status()) {
            \session_write_close();
        }
        $id = \session_id();
        \session_id('');
        $_SESSION       = [];
        $this->_started = false;

        $headers = [];
        if ('' !== $id && $id !== $this->cookieId) {
            $params  = \session_get_cookie_params();
            $options = [
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => '' !== $params['samesite'] ? $params['samesite'] : null,
            ];
            if ($params['lifetime'] > 0) {
                $options['expires'] = \time() + $params['lifetime'];
            }
            $headers['Set-Cookie'] = Cookie::create(\session_name(), $id, $options)->toHeaderValue();
        }
        if ($this->used && 'nocache' === $this->cacheLimiter) {
            $headers += [
                'Expires'       => 'Thu, 19 Nov 1981 08:52:00 GMT',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
            ];
        }

        return $headers;
    }
}
