<?php

/*
 * Overlapping requests never see each other's state: each stores its value in the request, the
 * route, the container, the session, the authenticated identity, Configure, the locale, the time
 * zone, a global event listener and a table, renders an element that waits halfway while the
 * others run, and reads its own back; every other one also waits in a middleware first. One worker, so that they all overlap in it; in debug mode
 * without APP_FULL_BASE_URL, so that full URLs come from each request's Host header. See
 * docs/concurrency.md.
 */

const ZONES = ['UTC', 'Europe/Oslo', 'Asia/Tokyo', 'America/Lima', 'Africa/Cairo', 'Australia/Perth', 'Europe/Paris', 'Asia/Dubai', 'America/Denver', 'Pacific/Fiji'];

beforeAll(function () {
    $GLOBALS['app'] = app_start(1, ['DEBUG' => 'true', 'APP_FULL_BASE_URL' => '']);
});

afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});

it('keeps each of 10 overlapping requests to itself', function () {
    $addr    = $GLOBALS['app'][1];
    $sockets = [];
    for ($i = 1; $i <= 10; ++$i) {
        $sockets[$i] = http_send($addr, 'GET', "/swerve-test/isolation/v$i?wait=0.1&tz=" . ZONES[$i - 1] . ($i % 2 ? '&prewait=0.05' : ''), ['Host' => "host$i.test"]);
    }
    foreach ($sockets as $i => $socket) {
        $response = http_response($socket);
        expect($response['status'])->toBe(200)
            ->and(\json_decode($response['body'], true))->toBe([
                'attribute' => "v$i",
                'route'     => "v$i",
                'url'       => "/swerve-test/isolation/v$i",
                'fullUrl'   => "http://host$i.test/",
                'service'   => "v$i",
                'container' => "v$i",
                'session'   => "v$i",
                'identity'  => "user-v$i",
                'configure' => "v$i",
                'locale'    => "en_v$i",
                'timezone'  => ZONES[$i - 1],
                'events'    => ["v$i"],
                'table'     => true,
                'rendered'  => "v$i:v$i",
            ]);
        $cookie = null;
        foreach ($response['headers']['set-cookie'] as $header) {
            $cookie = \str_starts_with($header, 'PHPSESSID=') ? $header : $cookie;
        }
        $cookies[$i] = $cookie;
    }

    expect(\array_unique($cookies))->toHaveCount(10);
});

it('gives a visitor without a session cookie a new session, after one with', function () {
    $addr    = $GLOBALS['app'][1];
    $cookies = new Cookies();
    $cookies->update(http($addr, 'GET', '/swerve-test/counter'));
    $cookies->update(http($addr, 'GET', '/swerve-test/counter'));

    // One worker, so that the next request lands where the session was last used
    [$proc, $one] = app_start(1);
    try {
        $with    = new Cookies();
        $first   = \json_decode($with->update(http($one, 'GET', '/swerve-test/counter'))['body'], true);
        $second  = \json_decode(http($one, 'GET', '/swerve-test/counter', $with->header())['body'], true);
        $without = http($one, 'GET', '/swerve-test/session');
        $new     = http($one, 'GET', '/swerve-test/counter');
    } finally {
        app_stop($proc);
    }

    expect($first['count'])->toBe(1)
        ->and($second['count'])->toBe(2)
        ->and(\json_decode($without['body'], true))->toBe(['session' => [], 'id' => ''])
        ->and(\implode("\n", $without['headers']['set-cookie'] ?? []))->not->toContain('PHPSESSID')
        ->and(\json_decode($new['body'], true)['count'])->toBe(1)
        ->and($new['headers']['set-cookie'])->not->toContain($with->values['PHPSESSID']);
});

it('keeps a streamed body and a page rendered meanwhile apart', function () {
    $stream = http_send($GLOBALS['app'][1], 'GET', '/swerve-test/stream'); // echoes first, waits 0.5 s, echoes last
    \usleep(100_000);
    $page = http($GLOBALS['app'][1], 'GET', '/swerve-test/isolation/v1?wait=1');

    expect(http_response($stream)['body'])->toBe("first\nlast\n")
        ->and(\json_decode($page['body'], true)['rendered'])->toBe('v1:v1');
});

it('runs Server.terminate listeners between requests, not during one', function () {
    [$proc, $addr] = app_start(1, ['SWERVE_TEST_TERMINATE' => '1']);
    try {
        $first = http_send($addr, 'GET', '/swerve-test/isolation/v1?wait=0.1');
        \usleep(50_000);
        $second = http_send($addr, 'GET', '/swerve-test/isolation/v2?wait=0.3');
        $first  = \json_decode(http_response($first)['body'], true);
        $second = \json_decode(http_response($second)['body'], true);
    } finally {
        app_stop($proc);
    }

    expect($first['configure'])->toBe('v1')
        ->and($second['configure'])->toBe('v2');
});
