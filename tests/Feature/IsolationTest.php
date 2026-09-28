<?php

/*
 * Overlapping requests never see each other's state: each stores its value in the request, the
 * route, the container, the session, the authenticated identity and Configure, waits while the
 * others run, and reads its own back. In debug mode without APP_FULL_BASE_URL, so that full URLs
 * come from each request's Host header.
 */

beforeAll(function () {
    $GLOBALS['app'] = app_start(2, ['DEBUG' => 'true', 'APP_FULL_BASE_URL' => '']);
});

afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});

it('keeps each of 10 overlapping requests to itself', function () {
    $addr    = $GLOBALS['app'][1];
    $sockets = [];
    for ($i = 1; $i <= 10; ++$i) {
        $sockets[$i] = http_send($addr, 'GET', "/swerve-test/isolation/v$i?wait=0.1", ['Host' => "host$i.test"]);
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
                'session'   => "v$i",
                'identity'  => "user-v$i",
                'configure' => "v$i",
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
