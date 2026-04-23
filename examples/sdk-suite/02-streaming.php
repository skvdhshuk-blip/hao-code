#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$config = new HaoCodeConfig(apiKey: getenv('ANTHROPIC_API_KEY'), maxTurns: 1, allowedTools: []);

echo "Streaming: ";
foreach (HaoCode::stream('Count from 1 to 5, one number per line.', $config) as $msg) {
    if ($msg->type === 'text') echo $msg->text;
    elseif ($msg->type === 'result') echo "\ncost: \${$msg->cost}\n";
}
