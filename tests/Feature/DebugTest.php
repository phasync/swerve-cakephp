<?php

/*
 * Development mode: the skeleton's home page, which exists only with debug on, and DebugKit,
 * which the skeleton loads then and which keeps a request's state in its middleware.
 */

beforeAll(function () {
    $GLOBALS['app'] = app_start(1, ['DEBUG' => 'true', 'APP_FULL_BASE_URL' => '']);
});

afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});

it('serves the skeleton\'s home page', function () {
    $response = http($GLOBALS['app'][1], 'GET', '/');

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('Welcome to CakePHP');
});

it('runs DebugKit on every request, with its toolbar', function () {
    $addr = $GLOBALS['app'][1];
    for ($i = 0; $i < 3; ++$i) {
        $page = http($addr, 'GET', '/');
        $json = http($addr, 'GET', '/swerve-test/json');
        expect($page['status'])->toBe(200)
            ->and($page['body'])->toContain('debug_kit/js/inject-iframe.js')
            ->and($json['headers'])->toHaveKey('x-debugkit-id');
    }
    $toolbar = http($addr, 'GET', '/debug-kit/toolbar/' . $json['headers']['x-debugkit-id'][0]);

    expect($toolbar['status'])->toBe(200)
        ->and($toolbar['body'])->toContain('Debug Kit Toolbar')
        ->and($toolbar['body'])->toContain('Request');
});
