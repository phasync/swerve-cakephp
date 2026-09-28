<?php

/*
 * Cake's sessions, flash messages and the authentication plugin's login, as under PHP-FPM:
 * PHP's session module and Cake's configured engine, shared by the workers.
 */

beforeAll(function () {
    $GLOBALS['app'] = app_start(4);
});

afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});

it('counts across requests landing on different workers', function () {
    $cookies = new Cookies();
    $pids    = [];
    for ($i = 1; $i <= 20; ++$i) {
        $data = \json_decode($cookies->update(http($GLOBALS['app'][1], 'GET', '/swerve-test/counter', $cookies->header()))['body'], true);
        expect($data['count'])->toBe($i);
        $pids[$data['pid']] = true;
    }

    expect(\count($pids))->toBeGreaterThan(1);
});

it('sends the session cookie and PHP\'s no-cache headers', function () {
    $response = http($GLOBALS['app'][1], 'GET', '/swerve-test/counter');
    $cookie   = \array_values(\array_filter($response['headers']['set-cookie'], fn ($c) => \str_starts_with($c, 'PHPSESSID=')))[0];

    expect($cookie)->toMatch('/^PHPSESSID=[a-zA-Z0-9,-]+; path=\/; samesite=Lax; httponly$/')
        ->and($response['headers']['cache-control'])->toBe(['no-store, no-cache, must-revalidate'])
        ->and($response['headers']['pragma'])->toBe(['no-cache']);
});

it('shows a flash message once', function () {
    $addr     = $GLOBALS['app'][1];
    $cookies  = new Cookies();
    $redirect = $cookies->update(http($addr, 'GET', '/swerve-test/flash'));
    $first    = http($addr, 'GET', '/swerve-test/page', $cookies->header());
    $second   = http($addr, 'GET', '/swerve-test/page', $cookies->header());

    expect($redirect['status'])->toBe(302)
        ->and($first['body'])->toContain('Flashed once')
        ->and($second['body'])->not->toContain('Flashed once');
});

it('logs in and out, with a new session id at login', function () {
    $addr    = $GLOBALS['app'][1];
    $cookies = new Cookies();
    $cookies->update(http($addr, 'GET', '/swerve-test/counter'));
    $before = $cookies->values['PHPSESSID'];
    $csrf   = ['X-CSRF-Token' => $cookies->values['csrfToken']];

    $wrong = \json_decode($cookies->update(http($addr, 'POST', '/swerve-test/login', $cookies->header($csrf), ['username' => 'ada', 'password' => 'wrong']))['body'], true);
    $login = \json_decode($cookies->update(http($addr, 'POST', '/swerve-test/login', $cookies->header($csrf), ['username' => 'ada', 'password' => 'secret']))['body'], true);
    $after = $cookies->values['PHPSESSID'];
    $who   = \json_decode(http($addr, 'GET', '/swerve-test/whoami', $cookies->header())['body'], true);
    $other = \json_decode(http($addr, 'GET', '/swerve-test/whoami')['body'], true);
    $cookies->update(http($addr, 'POST', '/swerve-test/logout', $cookies->header($csrf)));
    $gone = \json_decode(http($addr, 'GET', '/swerve-test/whoami', $cookies->header())['body'], true);

    expect($wrong)->toBe(['ok' => false, 'user' => null])
        ->and($login)->toBe(['ok' => true, 'user' => 'ada'])
        ->and($after)->not->toBe($before)
        ->and($who)->toBe(['user' => 'ada'])
        ->and($other)->toBe(['user' => null])
        ->and($gone)->toBe(['user' => null]);
});

it('keeps sessions working after the application echoed', function () {
    [$proc, $addr] = app_start(1);
    try {
        $stray   = \json_decode(http($addr, 'GET', '/swerve-test/stray')['body'], true);
        $cookies = new Cookies();
        $cookies->update(http($addr, 'GET', '/swerve-test/counter'));
        $csrf   = ['X-CSRF-Token' => $cookies->values['csrfToken']];
        $before = $cookies->values['PHPSESSID'];
        $login  = \json_decode($cookies->update(http($addr, 'POST', '/swerve-test/login', $cookies->header($csrf), ['username' => 'bo', 'password' => 'secret']))['body'], true);
        $who    = \json_decode(http($addr, 'GET', '/swerve-test/whoami', $cookies->header())['body'], true);
    } finally {
        app_stop($proc);
    }

    expect($stray['headers_sent'])->toBeTrue()
        ->and($login)->toBe(['ok' => true, 'user' => 'bo'])
        ->and($cookies->values['PHPSESSID'])->not->toBe($before)
        ->and($who)->toBe(['user' => 'bo']);
});
