#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$result = HaoCode::query('Say "Hello from HaoCode SDK!" and nothing else.', new HaoCodeConfig(
    apiKey: getenv('ANTHROPIC_API_KEY'), maxTurns: 1, allowedTools: [],
));
echo $result."\n";
echo "cost: \${$result->cost}\n";
