<?php

/*
 * The tests run the CakePHP application in tests/Fixtures/app (made by tests/create-app.sh)
 * on a real swerve, the way users run it. SWERVE_PHP_ARGS adds PHP options, such as loading
 * phasync-ext: CI runs the suite without and with it.
 */

/**
 * Start swerve on a free port with the fixture application and wait until it answers. The
 * application runs as in production (DEBUG=false, APP_FULL_BASE_URL set) unless $env says
 * otherwise.
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address, its log file
 */
function app_start(int $workers = 2, array $env = []): array
{
    $socket = \stream_socket_server('tcp://127.0.0.1:0');
    $addr   = \stream_socket_get_name($socket, false);
    \fclose($socket);
    $log      = \tempnam(\sys_get_temp_dir(), 'swerve-log');
    $app      = __DIR__ . '/Fixtures/app';
    $php      = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd      = 'exec ' . \PHP_BINARY . " $php " . \escapeshellarg("$app/vendor/bin/swerve") . " --workers=$workers --grace=2 --http=$addr --log=" . \escapeshellarg($log) . ' ' . \escapeshellarg("$app/swerve.php");
    $env += ['DEBUG' => 'false', 'APP_FULL_BASE_URL' => "http://$addr"];
    $proc     = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $app, $env + \getenv());
    $deadline = \microtime(true) + 20;
    \set_error_handler(fn () => true); // PHPUnit reports warnings even when silenced with @
    try {
        while (false === \file_get_contents("http://$addr/", false, \stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]))) {
            if (\microtime(true) > $deadline) {
                throw new RuntimeException("swerve did not start:\n" . \file_get_contents($log));
            }
            \usleep(100_000);
        }
    } finally {
        \restore_error_handler();
    }

    return [$proc, $addr, $log];
}

/** Stop swerve as SIGTERM does (a graceful drain), and return its exit code. */
function app_stop($proc): int
{
    \proc_terminate($proc, \SIGTERM);
    $deadline = \microtime(true) + 10;
    while (\proc_get_status($proc)['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }

    return \proc_close($proc);
}

/**
 * Send a request on a new connection, without waiting for the response: read it with
 * http_response(). An array body is sent as a urlencoded form.
 *
 * @return resource
 */
function http_send(string $addr, string $method, string $path, array $headers = [], string|array $body = '')
{
    if (\is_array($body)) {
        $body = \http_build_query($body);
        $headers += ['Content-Type' => 'application/x-www-form-urlencoded'];
    }
    $headers += ['Host' => $addr, 'Connection' => 'close'];
    if ('' !== $body || \in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $headers['Content-Length'] = \strlen($body);
    }
    $head = "$method $path HTTP/1.1\r\n";
    foreach ($headers as $name => $value) {
        $head .= "$name: $value\r\n";
    }
    $socket = \stream_socket_client("tcp://$addr", $errno, $error, 5);
    \stream_set_timeout($socket, 10);
    \fwrite($socket, "$head\r\n$body");

    return $socket;
}

/**
 * Read a response to its end (the connection closes).
 *
 * @return array{status: int, headers: array<string, list<string>>, body: string}
 */
function http_response($socket): array
{
    $raw = \stream_get_contents($socket);
    \fclose($socket);
    [$head, $body] = \explode("\r\n\r\n", $raw, 2) + [1 => ''];
    $lines         = \explode("\r\n", $head);
    $status        = (int) \explode(' ', \array_shift($lines))[1];
    $headers       = [];
    foreach ($lines as $line) {
        [$name, $value]                = \explode(':', $line, 2);
        $headers[\strtolower($name)][] = \trim($value);
    }
    if ('chunked' === ($headers['transfer-encoding'][0] ?? null)) {
        $decoded = '';
        while (0 < $size = \hexdec(\strstr($body, "\r\n", true))) {
            $start = \strpos($body, "\r\n") + 2;
            $decoded .= \substr($body, $start, $size);
            $body = \substr($body, $start + $size + 2);
        }
        $body = $decoded;
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

/** @return array{status: int, headers: array<string, list<string>>, body: string} */
function http(string $addr, string $method, string $path, array $headers = [], string|array $body = ''): array
{
    return http_response(http_send($addr, $method, $path, $headers, $body));
}

/** A browser's cookies: send them with header(), keep those a response sets with update(). */
final class Cookies
{
    public array $values = [];

    public function update(array $response): array
    {
        foreach ($response['headers']['set-cookie'] ?? [] as $cookie) {
            [$name, $value]      = \explode('=', \explode(';', $cookie, 2)[0], 2);
            $this->values[$name] = \urldecode($value);
        }

        return $response;
    }

    public function header(array $headers = []): array
    {
        $pairs = [];
        foreach ($this->values as $name => $value) {
            $pairs[] = $name . '=' . \urlencode($value);
        }

        return $pairs ? $headers + ['Cookie' => \implode('; ', $pairs)] : $headers;
    }
}

/**
 * Open a WebSocket: the handshake sent and its 101 read.
 *
 * @return resource
 */
function ws_open(string $addr, string $path, array $headers = [])
{
    $socket = http_send($addr, 'GET', $path, $headers + ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Key' => \base64_encode(\random_bytes(16)), 'Sec-WebSocket-Version' => '13']);
    $head   = '';
    while (!\str_ends_with($head, "\r\n\r\n")) {
        $byte = \fread($socket, 1);
        if ('' === $byte || false === $byte) {
            throw new RuntimeException("No 101 from $path: $head");
        }
        $head .= $byte;
    }
    if (!\str_starts_with($head, 'HTTP/1.1 101')) {
        throw new RuntimeException("No 101 from $path: $head");
    }

    return $socket;
}

/** Send a masked frame, as a client must: opcode 1 text, 2 binary, 8 close. */
function ws_send($socket, string $payload, int $opcode = 1): void
{
    $mask = \random_bytes(4);
    $n    = \strlen($payload);
    $len  = $n < 126 ? \chr(0x80 | $n) : \chr(0x80 | 126) . \pack('n', $n);
    \fwrite($socket, \chr(0x80 | $opcode) . $len . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($n, 4) + 1), 0, $n)));
}

/**
 * The next frame from the server, as [opcode, payload], or null when the connection ended.
 *
 * @return array{0: int, 1: string}|null
 */
function ws_read($socket): ?array
{
    $read = static function (int $n) use ($socket): ?string {
        $bytes = '';
        while (\strlen($bytes) < $n) {
            $chunk = \fread($socket, $n - \strlen($bytes));
            if ('' === $chunk || false === $chunk) {
                return null;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    };
    if (null === $head = $read(2)) {
        return null;
    }
    $n = \ord($head[1]) & 0x7F;
    if (126 === $n) {
        $n = \unpack('n', $read(2))[1];
    } elseif (127 === $n) {
        $n = \unpack('J', $read(8))[1];
    }

    return [\ord($head[0]) & 0x0F, $n > 0 ? $read($n) : ''];
}

/** The next text or binary message, skipping pings; null when the server closed. */
function ws_message($socket): ?string
{
    while (null !== $frame = ws_read($socket)) {
        if (1 === $frame[0] || 2 === $frame[0]) {
            return $frame[1];
        }
        if (8 === $frame[0]) {
            return null;
        }
    }

    return null;
}
