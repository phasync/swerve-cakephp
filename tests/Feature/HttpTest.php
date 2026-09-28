<?php

/*
 * Requests as the skeleton serves them under PHP-FPM, in production mode: routes, errors,
 * forms with CSRF protection, JSON bodies, uploads.
 */

beforeAll(function () {
    $GLOBALS['app'] = app_start();
});

afterAll(function () {
    app_stop($GLOBALS['app'][0]);
});

it('serves a JSON route', function () {
    $response = http($GLOBALS['app'][1], 'GET', '/swerve-test/json');

    expect($response['status'])->toBe(200)
        ->and($response['headers']['content-type'][0])->toStartWith('application/json')
        ->and(\json_decode($response['body'], true))->toBe(['hello' => 'world']);
});

it('answers 404 with the application\'s error page', function () {
    $response = http($GLOBALS['app'][1], 'GET', '/no/such/page');

    expect($response['status'])->toBe(404)
        ->and($response['body'])->toContain('Not Found');
});

it('accepts a form POST with the CSRF token and refuses one without', function () {
    $addr    = $GLOBALS['app'][1];
    $cookies = new Cookies();
    $form    = $cookies->update(http($addr, 'GET', '/swerve-test/form'));
    \preg_match('/name="_csrfToken" value="([^"]+)"/', $form['body'], $token);

    expect($cookies->values)->toHaveKey('csrfToken')
        ->and(http($addr, 'POST', '/swerve-test/form', $cookies->header(), ['_csrfToken' => $token[1], 'name' => 'Ada'])['body'])->toBe('Hello Ada')
        ->and(http($addr, 'POST', '/swerve-test/form', $cookies->header(), ['name' => 'Ada'])['status'])->toBe(403)
        ->and(http($addr, 'POST', '/swerve-test/form', [], ['name' => 'Ada'])['status'])->toBe(403);
});

it('parses a JSON POST', function () {
    $addr    = $GLOBALS['app'][1];
    $cookies = new Cookies();
    $cookies->update(http($addr, 'GET', '/swerve-test/form'));
    $response = http($addr, 'POST', '/swerve-test/echo', $cookies->header(['Content-Type' => 'application/json', 'X-CSRF-Token' => $cookies->values['csrfToken']]), '{"name":"Ada","list":[1,2]}');

    expect(\json_decode($response['body'], true))->toBe(['data' => ['name' => 'Ada', 'list' => [1, 2]], 'method' => 'POST']);
});

it('parses a urlencoded PUT and honours _method', function () {
    $addr    = $GLOBALS['app'][1];
    $cookies = new Cookies();
    $cookies->update(http($addr, 'GET', '/swerve-test/form'));
    $headers = $cookies->header(['X-CSRF-Token' => $cookies->values['csrfToken']]);

    expect(\json_decode(http($addr, 'PUT', '/swerve-test/echo', $headers, ['name' => 'Ada'])['body'], true))->toBe(['data' => ['name' => 'Ada'], 'method' => 'PUT'])
        ->and(\json_decode(http($addr, 'POST', '/swerve-test/echo', $headers, ['_method' => 'PATCH', 'name' => 'Bo'])['body'], true))->toBe(['data' => ['name' => 'Bo'], 'method' => 'PATCH']);
});

it('receives an upload', function () {
    $addr    = $GLOBALS['app'][1];
    $cookies = new Cookies();
    $cookies->update(http($addr, 'GET', '/swerve-test/form'));
    $content = \random_bytes(200_000);
    $body    = "--XyZ\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nA file\r\n"
        . "--XyZ\r\nContent-Disposition: form-data; name=\"file\"; filename=\"data.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n$content\r\n"
        . "--XyZ--\r\n";
    $response = http($addr, 'POST', '/swerve-test/upload', $cookies->header(['Content-Type' => 'multipart/form-data; boundary=XyZ', 'X-CSRF-Token' => $cookies->values['csrfToken']]), $body);

    expect(\json_decode($response['body'], true))->toBe(['name' => 'data.bin', 'size' => 200_000, 'md5' => \md5($content), 'title' => 'A file']);
});
