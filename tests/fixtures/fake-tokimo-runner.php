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

    $output = ($request['command'] ?? '').'|'.($request['cwd'] ?? '');
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'stdout_base64' => base64_encode($output),
        'stderr_base64' => base64_encode(''),
        'exit_code' => 0,
        'timed_out' => false,
    ])."\n");
    fflush(STDOUT);
}
