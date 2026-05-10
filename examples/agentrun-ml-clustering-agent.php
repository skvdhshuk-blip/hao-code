#!/usr/bin/env php
<?php

/**
 * AgentRun ML Clustering Agent Demo
 *
 * Runs a real HaoCode agent with Read/Write/Bash tools backed by Alibaba Cloud
 * AgentRun Sandbox. The agent creates synthetic data, writes a pure-Python
 * deterministic k-means script, executes it in the sandbox, and reports the
 * clustering result.
 *
 * Usage:
 *   AGENTRUN_ACCOUNT_ID=1887527099427005 \
 *   AGENTRUN_API_KEY=ak_xxx \
 *   AGENTRUN_TEMPLATE_NAME=sandbox-lagal \
 *   php examples/agentrun-ml-clustering-agent.php
 */

$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

\HaoCode\Support\Runtime\SdkRuntime::boot(basePath: $packageRoot);

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Tools\ToolResult;

$accountId = getenv('AGENTRUN_ACCOUNT_ID') ?: getenv('ALIBABA_CLOUD_ACCOUNT_ID') ?: '';
$apiKey = getenv('AGENTRUN_API_KEY') ?: '';
$templateName = getenv('AGENTRUN_TEMPLATE_NAME') ?: 'sandbox-lagal';
$region = getenv('AGENTRUN_REGION') ?: getenv('ALIBABA_CLOUD_REGION_ID') ?: 'cn-hangzhou';
$endpoint = getenv('AGENTRUN_DATA_ENDPOINT') ?: null;
$timeoutSeconds = (int) (getenv('AGENTRUN_TIMEOUT_SECONDS') ?: 90);

$llmApiKey = getenv('HAOCODE_API_KEY') ?: getenv('ANTHROPIC_API_KEY') ?: getenv('OPENAI_API_KEY') ?: getenv('KIMI_API_KEY') ?: null;
$llmBaseUrl = getenv('HAOCODE_BASE_URL') ?: getenv('ANTHROPIC_BASE_URL') ?: getenv('OPENAI_BASE_URL') ?: getenv('KIMI_BASE_URL') ?: null;
$llmModel = getenv('HAOCODE_MODEL') ?: getenv('ANTHROPIC_MODEL') ?: getenv('OPENAI_MODEL') ?: getenv('KIMI_MODEL') ?: null;
$llmProviderType = getenv('HAOCODE_PROVIDER_TYPE') ?: null;
if ($llmProviderType === null && is_string($llmBaseUrl) && str_contains(strtolower($llmBaseUrl), 'api.openai.com')) {
    $llmProviderType = 'openai_chat';
}

$missing = [];
if ($accountId === '') {
    $missing[] = 'AGENTRUN_ACCOUNT_ID';
}
if ($apiKey === '') {
    $missing[] = 'AGENTRUN_API_KEY';
}
if ($missing !== []) {
    fwrite(STDERR, "Missing required environment variable(s): ".implode(', ', $missing)."\n");
    fwrite(STDERR, "Example: AGENTRUN_ACCOUNT_ID=1887527099427005 AGENTRUN_API_KEY=ak_xxx AGENTRUN_TEMPLATE_NAME=sandbox-lagal php examples/agentrun-ml-clustering-agent.php\n");
    exit(1);
}

function short_value(string $value): string
{
    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4).'...'.substr($value, -4);
}

echo "AgentRun ML Clustering Agent Demo\n";
echo "Sandbox template: {$templateName}\n";
echo "Region: {$region}\n";
echo "Account: ".short_value($accountId)."\n";
if (is_string($llmModel) && $llmModel !== '') {
    echo "LLM model: {$llmModel}\n";
}
echo "\n";

$prompt = <<<'PROMPT'
You are running inside an Alibaba Cloud AgentRun sandbox through HaoCode sandbox tools.
Do not write to the host project. Store generated demo files under /tmp/workspace inside the sandbox.

Goal: create a deterministic ML clustering demo and run it.

Tasks:
1. Use Bash to run: mkdir -p /tmp/workspace
2. Use Write to create /tmp/workspace/points.csv with 45 two-dimensional samples and columns x,y. Generate three obvious clusters around (1,1), (5,5), and (9,1). Keep the data deterministic.
3. Use Write to create /tmp/workspace/cluster_demo.py. The script must:
   - use only the Python standard library, no sklearn and no numpy;
   - read /tmp/workspace/points.csv;
   - run deterministic k-means with k=3 and fixed initial centers based on the first, middle, and last thirds of the sorted points;
   - stop after convergence or 20 iterations;
   - write /tmp/workspace/cluster_summary.json with sample_count, iterations, centers, cluster_counts, and assignments;
   - print a concise human-readable summary.
4. Use Bash to run: python3 /tmp/workspace/cluster_demo.py
5. Use Read to inspect /tmp/workspace/cluster_summary.json.
6. Final answer must include:
   - whether the script succeeded;
   - sample count;
   - iterations;
   - the three centers rounded to 3 decimals;
   - cluster counts;
   - generated sandbox file paths.
PROMPT;

$config = new HaoCodeConfig(
    apiKey: $llmApiKey !== false ? $llmApiKey : null,
    model: $llmModel !== false ? $llmModel : null,
    baseUrl: $llmBaseUrl !== false ? $llmBaseUrl : null,
    providerType: $llmProviderType !== false ? $llmProviderType : null,
    maxTokens: (int) (getenv('HAOCODE_DEMO_MAX_TOKENS') ?: 4096),
    permissionMode: 'bypass_permissions',
    allowedTools: ['Read', 'Write', 'Bash'],
    sandbox: SandboxConfig::agentRun(
        accountId: $accountId,
        templateName: $templateName,
        apiKey: $apiKey,
        region: $region,
        endpoint: $endpoint,
        mode: 'full',
        remoteCwd: '/tmp',
        timeoutSeconds: $timeoutSeconds,
    ),
    maxTurns: (int) (getenv('HAOCODE_DEMO_MAX_TURNS') ?: 12),
    onToolStart: function (string $toolName, array $input): void {
        $target = $input['file_path'] ?? $input['command'] ?? '';
        $target = is_string($target) ? ' '.mb_substr($target, 0, 100) : '';
        fwrite(STDERR, "[tool:start] {$toolName}{$target}\n");
    },
    onToolComplete: function (string $toolName, ToolResult $result): void {
        $status = $result->isError ? 'error' : 'ok';
        $snippet = trim(mb_substr($result->output, 0, 240));
        $suffix = $snippet !== '' ? ' - '.str_replace("\n", ' | ', $snippet) : '';
        fwrite(STDERR, "[tool:done] {$toolName} {$status}{$suffix}\n");
    },
);

try {
    $result = HaoCode::query($prompt, $config);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}

echo "\n--- Agent Result ---\n";
echo trim($result->text)."\n";
echo "\nUsage: ".$result->inputTokens()." input / ".$result->outputTokens()." output tokens";
if (($result->cost ?? 0.0) > 0) {
    echo ", cost $".number_format($result->cost, 6);
}
echo "\n";
