#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY (required), OPENAI_API_KEY (optional)
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$prompt = 'Reply with exactly: "provider:ok"';
$providers = [
    ['anthropic (claude-haiku-4-5)', new HaoCodeConfig(
        apiKey: getenv('ANTHROPIC_API_KEY'), model: 'claude-haiku-4-5-20251001',
        providerType: 'anthropic', maxTurns: 1, allowedTools: [])],
];
if ($ok = getenv('OPENAI_API_KEY')) {
    $providers[] = ['openai (gpt-4o-mini)', new HaoCodeConfig(
        apiKey: $ok, model: 'gpt-4o-mini', providerType: 'openai', maxTurns: 1, allowedTools: [])];
    $providers[] = ['openai_chat (gpt-4o-mini)', new HaoCodeConfig(
        apiKey: $ok, model: 'gpt-4o-mini', providerType: 'openai_chat', maxTurns: 1, allowedTools: [])];
}

printf("%-35s %-8s %s\n", 'Provider', 'Status', 'Response');
echo str_repeat('-', 70)."\n";
foreach ($providers as [$label, $cfg]) {
    try {
        printf("%-35s %-8s %s\n", $label, 'pass', trim((string)HaoCode::query($prompt, $cfg)));
    } catch (Throwable $e) {
        printf("%-35s %-8s %s\n", $label, 'error', $e->getMessage());
    }
}
