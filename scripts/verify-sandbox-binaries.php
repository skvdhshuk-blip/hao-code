#!/usr/bin/env php
<?php

declare(strict_types=1);

$binDir = dirname(__DIR__).'/bin';
$required = [
    'haocode-sandbox-darwin-arm64',
    'haocode-sandbox-linux-amd64',
    'haocode-sandbox-linux-arm64',
    'haocode-sandbox-windows-amd64.exe',
    'haocode-sandbox-svc-windows-amd64.exe',
];

$lines = file($binDir.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    fwrite(STDERR, "Unable to read bin/SHA256SUMS.\n");
    exit(1);
}

$checksums = [];
foreach ($lines as $line) {
    if (preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $matches) !== 1) {
        fwrite(STDERR, "Invalid checksum line: {$line}\n");
        exit(1);
    }
    $checksums[$matches[2]] = $matches[1];
}

foreach ($required as $name) {
    $path = $binDir.'/'.$name;
    if (! is_file($path)) {
        fwrite(STDERR, "Missing sandbox binary: bin/{$name}\n");
        exit(1);
    }
    if (! str_ends_with($name, '.exe') && ! is_executable($path)) {
        fwrite(STDERR, "Sandbox binary is not executable: bin/{$name}\n");
        exit(1);
    }
    $actual = hash_file('sha256', $path);
    if (! isset($checksums[$name]) || ! is_string($actual) || ! hash_equals($checksums[$name], $actual)) {
        fwrite(STDERR, "Sandbox binary checksum mismatch: bin/{$name}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Verified ".count($required)." packaged sandbox binaries.\n");
