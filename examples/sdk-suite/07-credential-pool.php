#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY (required), ANTHROPIC_API_KEY_2 (optional)
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\Credential;

$pool = new CredentialPool;
$pool->add('anthropic', Credential::make(getenv('ANTHROPIC_API_KEY'), priority: 10, meta: ['label' => 'primary']));
if ($k2 = getenv('ANTHROPIC_API_KEY_2')) {
    $pool->add('anthropic', Credential::make($k2, priority: 5, meta: ['label' => 'secondary']));
}

echo "Pool stats: ".json_encode($pool->getStats())."\n";
echo "Picked:     ".$pool->pickNext('anthropic')->idHash()."\n";

echo HaoCode::query('Say "pool works!"', new HaoCodeConfig(
    maxTurns: 1, allowedTools: [], credentialPool: $pool,
))."\n";
