<?php

/*
 * Streamed responses arrive as they are produced: Cake's CallbackStream, whose callback
 * echoes, and a stream filled by a coroutine (Server-Sent Events).
 */

beforeAll(function () {
    $GLOBALS['app'] = app_start();
});

afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});

/** Read a response, noting when each piece of the body arrived. @return list<array{float, string}> */
function timed_read($socket): array
{
    $start  = \microtime(true);
    $pieces = [];
    while (!\feof($socket) && false !== $data = \fread($socket, 65536)) {
        if ('' !== $data) {
            $pieces[] = [\microtime(true) - $start, $data];
        }
    }
    \fclose($socket);

    return $pieces;
}

it('streams what a CallbackStream echoes as it echoes it', function () {
    $pieces = timed_read(http_send($GLOBALS['app'][1], 'GET', '/swerve-test/stream'));
    $all    = \implode('', \array_column($pieces, 1));
    $first  = \array_values(\array_filter($pieces, fn ($p) => \str_contains($p[1], "first\n")))[0][0];
    $last   = \array_values(\array_filter($pieces, fn ($p) => \str_contains($p[1], "last\n")))[0][0];

    expect($all)->toContain('HTTP/1.1 200')
        ->and($all)->toContain('Transfer-Encoding: chunked')
        ->and($first)->toBeLessThan(0.3)
        ->and($last - $first)->toBeGreaterThan(0.4);
});

it('streams Server-Sent Events from a coroutine', function () {
    $pieces = timed_read(http_send($GLOBALS['app'][1], 'GET', '/swerve-test/events'));
    $all    = \implode('', \array_column($pieces, 1));

    expect($all)->toContain('Content-Type: text/event-stream')
        ->and(\substr_count($all, 'data: event'))->toBe(3)
        ->and(\end($pieces)[0] - $pieces[0][0])->toBeGreaterThan(0.3);
});

it('serves other requests while a Server-Sent Events stream is open', function () {
    [$proc, $addr] = app_start(1);
    try {
        $events = http_send($addr, 'GET', '/swerve-test/events');
        \fread($events, 1);
        $start = \microtime(true);
        $json  = http($addr, 'GET', '/swerve-test/json');
        $took  = \microtime(true) - $start;
        \fclose($events);
    } finally {
        app_stop($proc);
    }

    expect($json['status'])->toBe(200)
        ->and($took)->toBeLessThan(0.3);
});
