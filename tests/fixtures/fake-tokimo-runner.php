<?php

$start = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($start['op'] ?? null) !== 'start' || ($start['protocol_version'] ?? null) !== 1) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'invalid start request'])."\n");
    exit(1);
}

fwrite(STDOUT, json_encode(['ok' => true, 'backend' => 'fake'])."\n");
fflush(STDOUT);

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (($request['op'] ?? null) === 'shutdown') {
        fwrite(STDOUT, json_encode(['ok' => true])."\n");
        fflush(STDOUT);
        exit(0);
    }
    if (($request['op'] ?? null) !== 'exec') {
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'unsupported operation'])."\n");
        fflush(STDOUT);
        continue;
    }

    if (($request['command'] ?? '') === 'long-running') {
        usleep(5_000_000);
    }

    $output = match ($request['command'] ?? '') {
        'large-output' => str_repeat('x', 150000),
        'oversized-response' => str_repeat('x', 3 * 1024 * 1024),
        default => ($request['command'] ?? '').'|'.($request['cwd'] ?? ''),
    };
    $response = json_encode([
        'ok' => true,
        'stdout_base64' => base64_encode($output),
        'stderr_base64' => base64_encode(''),
        'exit_code' => 0,
        'timed_out' => false,
    ])."\n";
    if (($request['command'] ?? '') === 'fragmented-response') {
        $splitAt = max(1, intdiv(strlen($response), 2));
        fwrite(STDOUT, substr($response, 0, $splitAt));
        fflush(STDOUT);
        usleep(50_000);
        fwrite(STDOUT, substr($response, $splitAt));
        fflush(STDOUT);

        continue;
    }
    fwrite(STDOUT, $response);
    fflush(STDOUT);
}
