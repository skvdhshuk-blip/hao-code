#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$config = new HaoCodeConfig(apiKey: getenv('ANTHROPIC_API_KEY'), maxTurns: 1, allowedTools: []);
$conv   = HaoCode::conversation($config);

$r1 = $conv->send('My favourite fruit is mango. Just say "got it".');
echo "Turn 1: {$r1}\n";
$sessionId = $r1->sessionId;

$r2 = $conv->send('What fruit did I just mention?');
echo "Turn 2: {$r2}\n";

echo "\nResuming session {$sessionId}...\n";
$conv2 = HaoCode::resume($sessionId, $config);
$r3    = $conv2->send('Repeat my favourite fruit one more time.');
echo "Resumed: {$r3}\n";
