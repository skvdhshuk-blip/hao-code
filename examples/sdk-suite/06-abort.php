#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\AbortController;

$abort  = new AbortController;
$chunks = 0;
$config = new HaoCodeConfig(
    apiKey: getenv('ANTHROPIC_API_KEY'), maxTurns: 1, allowedTools: [],
    abortController: $abort,
    onText: function (string $delta) use ($abort, &$chunks): void {
        if (++$chunks === 1) { echo "[aborting]\n"; $abort->abort(); }
    },
);

echo "isAborted before: ".($abort->isAborted() ? 'true' : 'false')."\n";
try {
    foreach (HaoCode::stream('Write a 500-word essay about the ocean.', $config) as $msg) {
        if ($msg->type === 'text') echo $msg->text;
        if ($msg->type === 'error') echo "\nerror: {$msg->text}\n";
    }
} catch (Throwable $e) { echo "\naborted: {$e->getMessage()}\n"; }
echo "isAborted after:  ".($abort->isAborted() ? 'true' : 'false')."\n";
