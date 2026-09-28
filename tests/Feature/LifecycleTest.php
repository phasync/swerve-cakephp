<?php

/*
 * The worker's life: a graceful drain, and memory over many requests.
 */

it('finishes a slow request when told to stop, without errors', function () {
    [$proc, $addr, $log] = app_start(1);
    $socket              = http_send($addr, 'GET', '/swerve-test/slow?t=1');
    \usleep(300_000);
    \proc_terminate($proc, \SIGTERM);
    $response = http_response($socket);
    // PHP before 8.3 has the exit code only from the proc_get_status() that saw the exit
    while (($status = \proc_get_status($proc))['running']) {
        \usleep(50_000);
    }
    \proc_close($proc);
    $code = $status['exitcode'];

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toBe('slow done')
        ->and($code)->toBe(0)
        ->and(\file_get_contents($log))->not->toMatch('/error|warning|fatal|exception/i');
});

it('keeps memory flat over 10,000 requests after 2,000', function () {
    [$proc, $addr] = app_start(1);
    try {
        $socket = \stream_socket_client("tcp://$addr");
        $get    = function (string $path, array $headers = []) use ($socket, $addr): string {
            $head = "GET $path HTTP/1.1\r\nHost: $addr\r\n";
            foreach ($headers as $name => $value) {
                $head .= "$name: $value\r\n";
            }
            \fwrite($socket, "$head\r\n");
            $response = '';
            while (!\str_contains($response, "\r\n\r\n")) {
                $response .= \fread($socket, 65536);
            }
            \preg_match('/Content-Length: (\d+)/i', $response, $length);
            while (\strlen($response) < \strpos($response, "\r\n\r\n") + 4 + $length[1]) {
                $response .= \fread($socket, 65536);
            }

            return \substr($response, \strpos($response, "\r\n\r\n") + 4);
        };
        // A leak grows memory between nearly all checkpoints; a hash table that grew once to its
        // working size between one or two
        $cookie = ['Cookie' => 'PHPSESSID=' . \bin2hex(\random_bytes(16))];
        $paths  = ['/swerve-test/json', '/swerve-test/counter', '/swerve-test/page', '/bench/json'];
        $memory = [];
        for ($i = 1; $i <= 12_000; ++$i) {
            $get($paths[$i % 4], $cookie);
            if (0 === $i % 1_000 && $i >= 2_000) {
                $memory[] = \json_decode($get('/swerve-test/memory'), true)['memory'];
            }
        }
        \fclose($socket);
    } finally {
        app_stop($proc);
    }
    $grew = 0;
    for ($i = 1; $i < \count($memory); ++$i) {
        $grew += $memory[$i] > $memory[$i - 1] ? 1 : 0;
    }

    expect($grew)->toBeLessThanOrEqual(2)
        ->and(\end($memory) - $memory[0])->toBeLessThan(2 * 1024 * 1024);
});
