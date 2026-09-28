<?php

/*
 * WebSockets from Cake controllers: both ways, server push through publish/subscribe, clients
 * leaving, the user taken before the callback, the worker serving meanwhile, drain and refusal.
 */

beforeAll(function () {
    \array_map('unlink', \glob(__DIR__ . '/../Fixtures/app/tmp/ws-live-*'));
    $GLOBALS['app'] = app_start(2);
});

afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});

/** The news callbacks running: ['total' => n, 'workers' => [pid => n]]. */
function ws_live(string $addr): array
{
    return \json_decode(http($addr, 'GET', '/swerve-test/live')['body'], true);
}

/** Wait until $test(ws_live()) holds, for up to $seconds. */
function ws_live_until(string $addr, Closure $test, float $seconds = 5): array
{
    $deadline = \microtime(true) + $seconds;
    while (!$test($live = ws_live($addr)) && \microtime(true) < $deadline) {
        \usleep(50_000);
    }

    return $live;
}

/** Log in on a new session, and return its cookies. */
function ws_login(string $addr, string $user): Cookies
{
    $cookies = new Cookies();
    $cookies->update(http($addr, 'GET', '/swerve-test/counter'));
    $cookies->update(http($addr, 'POST', '/swerve-test/login', $cookies->header(['X-CSRF-Token' => $cookies->values['csrfToken']]), ['username' => $user, 'password' => 'secret']));

    return $cookies;
}

it('echoes text and binary messages from a controller, several in a row', function () {
    $socket = ws_open($GLOBALS['app'][1], '/swerve-test/ws');
    $binary = \random_bytes(1000);
    ws_send($socket, 'hello');
    ws_send($socket, $binary, 2);
    ws_send($socket, 'world');
    $replies = [ws_read($socket), ws_read($socket), ws_read($socket)];
    ws_send($socket, "\x03\xE8", 8);
    $close = ws_read($socket);
    \fclose($socket);

    expect($replies)->toBe([[1, 'echo: hello'], [2, $binary], [1, 'echo: world']])
        ->and($close)->toBe([8, "\x03\xE8"]);
});

it('forwards every publish to every subscribed client, in order, over both workers', function () {
    $addr    = $GLOBALS['app'][1];
    $clients = [];
    for ($i = 0; $i < 16; ++$i) {
        $clients[] = ws_open($addr, '/swerve-test/news');
    }
    $live = ws_live_until($addr, fn ($live) => 16 === $live['total']);
    for ($i = 1; $i <= 20; ++$i) {
        http($addr, 'GET', "/swerve-test/publish?m=news-$i");
    }
    $received = [];
    foreach ($clients as $client) {
        $messages = [];
        for ($i = 1; $i <= 20; ++$i) {
            $messages[] = ws_message($client);
        }
        $received[] = $messages;
        \fclose($client);
    }

    expect($live['total'])->toBe(16)
        ->and($live['workers'])->toHaveCount(2)
        ->and($received)->toBe(\array_fill(0, 16, \array_map(fn ($i) => "news-$i", \range(1, 20))));
    ws_live_until($addr, fn ($live) => 0 === $live['total']);
});

it('ends every callback when its client leaves, with or without a close frame', function () {
    [, $addr, $log] = $GLOBALS['app'];
    $clients        = [];
    for ($i = 0; $i < 16; ++$i) {
        $clients[] = ws_open($addr, '/swerve-test/news');
    }
    $open    = ws_live_until($addr, fn ($live) => 16 === $live['total']);
    $replies = [];
    foreach ($clients as $i => $client) {
        if ($i % 2) {
            ws_send($client, "\x03\xE8", 8);
            $replies[] = ws_read($client);
        }
        \fclose($client);
    }
    $after = ws_live_until($addr, fn ($live) => 0 === $live['total']);

    expect($open['total'])->toBe(16)
        ->and($replies)->toBe(\array_fill(0, 8, [8, "\x03\xE8"]))
        ->and($after['total'])->toBe(0)
        ->and(\file_get_contents($log))->not->toMatch('/error|warning|fatal|exception/i');
});

it('gives the callback the user taken before WebSocket::from(); Router::getRequest() inside it is somebody else\'s', function () {
    [$proc, $addr, $log] = app_start(1);
    try {
        $ada   = ws_login($addr, 'ada');
        $bob   = ws_login($addr, 'bob');
        $adaWs = ws_open($addr, '/swerve-test/identity', $ada->header());
        $bobWs = ws_open($addr, '/swerve-test/identity', $bob->header()); // the worker's last request
        ws_send($adaWs, 'who');
        ws_send($bobWs, 'who');
        $first = [\json_decode(ws_message($adaWs), true), \json_decode(ws_message($bobWs), true)];
        http($addr, 'GET', '/swerve-test/json'); // an anonymous request
        ws_send($adaWs, 'who');
        $second = \json_decode(ws_message($adaWs), true);
        \fclose($adaWs);
        \fclose($bobWs);

        expect($first)->toBe([['user' => 'ada', 'router' => 'bob'], ['user' => 'bob', 'router' => 'bob']])
            ->and($second)->toBe(['user' => 'ada', 'router' => null])
            ->and(\file_get_contents($log))->not->toMatch('/error|warning|fatal|exception/i');
    } finally {
        app_stop($proc);
    }
});

it('refuses the session inside the callback, and the worker\'s requests keep theirs', function () {
    [$proc, $addr] = app_start(1);
    try {
        $ada    = ws_login($addr, 'ada');
        $bob    = ws_login($addr, 'bob');
        $socket = ws_open($addr, '/swerve-test/wrong-session', $ada->header());
        http($addr, 'GET', '/swerve-test/whoami', $bob->header()); // the worker's last request
        ws_send($socket, 'session?');
        $inside = \json_decode(ws_message($socket), true);
        \fclose($socket);
        $who = [http($addr, 'GET', '/swerve-test/whoami', $bob->header()), http($addr, 'GET', '/swerve-test/whoami', $ada->header())];

        expect($inside['error'] ?? null)->toStartWith('The session belongs to its request')
            ->and(\array_column($who, 'status'))->toBe([200, 200])
            ->and(\array_map(fn ($r) => \json_decode($r['body'], true)['user'], $who))->toBe(['bob', 'ada']);
    } finally {
        app_stop($proc);
    }
});

it('answers ordinary requests promptly while 250 WebSockets hold subscriptions in one worker', function () {
    [$proc, $addr, $log] = app_start(1);
    try {
        $clients = [];
        for ($i = 0; $i < 250; ++$i) {
            $clients[] = ws_open($addr, '/swerve-test/news');
        }
        $echo  = ws_open($addr, '/swerve-test/ws'); // one waiting for a message, too
        $live  = ws_live_until($addr, fn ($live) => 250 === $live['total']);
        $times = [];
        for ($i = 0; $i < 20; ++$i) {
            $start    = \microtime(true);
            $response = http($addr, 'GET', '/swerve-test/json');
            $times[]  = \microtime(true) - $start;
            expect($response['status'])->toBe(200);
        }
        http($addr, 'GET', '/swerve-test/publish?m=still-here');
        $received = \array_map(fn ($client) => ws_message($client), $clients);
        ws_send($echo, 'ping');
        $echoed = ws_message($echo);
        \array_map('fclose', [...$clients, $echo]);

        expect($live['total'])->toBe(250)
            ->and(\max($times))->toBeLessThan(0.25)
            ->and($received)->toBe(\array_fill(0, 250, 'still-here'))
            ->and($echoed)->toBe('echo: ping');
        ws_live_until($addr, fn ($live) => 0 === $live['total']);
        expect(\file_get_contents($log))->not->toMatch('/error|warning|fatal|exception/i');
    } finally {
        app_stop($proc);
    }
});

it('closes open WebSockets with 1001 on SIGTERM, ends their callbacks and exits 0', function () {
    [$proc, $addr, $log] = app_start(2);
    $clients             = [];
    for ($i = 0; $i < 8; ++$i) {
        $clients[] = ws_open($addr, '/swerve-test/news');
    }
    $clients[] = ws_open($addr, '/swerve-test/ws');
    $live      = ws_live_until($addr, fn ($live) => 8 === $live['total']);
    \proc_terminate($proc, \SIGTERM);
    $closes = [];
    foreach ($clients as $client) {
        do {
            $frame = ws_read($client);
        } while (null !== $frame && 8 !== $frame[0]);
        $closes[] = $frame;
        \fclose($client);
    }
    // PHP before 8.3 has the exit code only from the proc_get_status() that saw the exit
    while (($status = \proc_get_status($proc))['running']) {
        \usleep(50_000);
    }
    \proc_close($proc);
    $counts = \array_map(fn ($pid) => \file_get_contents(__DIR__ . "/../Fixtures/app/tmp/ws-live-$pid"), \array_keys($live['workers']));

    expect($closes)->toBe(\array_fill(0, 9, [8, "\x03\xE9"]))
        ->and($status['exitcode'])->toBe(0)
        ->and($counts)->toBe(\array_fill(0, \count($live['workers']), '0'))
        ->and(\file_get_contents($log))->not->toMatch('/error|warning|fatal|exception/i');
});

it('answers an ordinary GET to a WebSocket action with 426', function () {
    $response = http($GLOBALS['app'][1], 'GET', '/swerve-test/news');

    expect($response['status'])->toBe(426)
        ->and($response['headers']['upgrade'])->toBe(['websocket'])
        ->and($response['body'])->toBe('This address speaks WebSocket');
});
