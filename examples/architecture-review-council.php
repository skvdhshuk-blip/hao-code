#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run a small read-only council of HaoCode agents against this repository.
 *
 * Three specialists inspect separate architecture contracts. A lead reviewer
 * invokes all three through Agent::asTool() and synthesizes one evidence-based
 * verdict. The whole council shares one provider and usage ledger. A USD budget
 * can be enabled for models with trusted pricing.
 *
 * Usage:
 *   php examples/architecture-review-council.php
 *   ARCH_COUNCIL_BASE_URL=https://api.example.com/anthropic \
 *   ARCH_COUNCIL_MODEL=example-model \
 *   ARCH_COUNCIL_BUDGET_USD=0.50 \
 *     php examples/architecture-review-council.php "Focus on recovery semantics"
 */

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Runner;
use HaoCode\Support\Runtime\SdkRuntime;

$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

SdkRuntime::boot(basePath: $packageRoot);

$environment = static function (string $name): ?string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
};

$apiKey = $environment('ANTHROPIC_API_KEY');
$model = $environment('ARCH_COUNCIL_MODEL');
$baseUrl = $environment('ARCH_COUNCIL_BASE_URL');
$providerType = $environment('ARCH_COUNCIL_PROVIDER_TYPE') ?? 'anthropic';
$budgetValue = $environment('ARCH_COUNCIL_BUDGET_USD');
$budget = $budgetValue === null ? null : (float) $budgetValue;
if ($budget !== null && (! is_finite($budget) || $budget <= 0)) {
    fwrite(STDERR, "ARCH_COUNCIL_BUDGET_USD must be greater than zero.\n");
    exit(1);
}

$focus = trim(implode(' ', array_slice($argv, 1)));
$focus = $focus !== '' ? $focus : 'Judge whether the architecture closure has one authority per concern.';

$outcomeFiles = [
    'app/Contracts/RunTerminationReason.php',
    'app/Services/Agent/AgentRunOutcome.php',
    'app/Services/Agent/RunStateLifecycle.php',
];
$streamFiles = [
    'app/Sdk/Internal/FiberMessageStream.php',
    'app/Sdk/Runner.php',
    'app/Sdk/ConversationConstructConcern.php',
];
$runtimeFiles = [
    'app/Services/Agent/BackgroundAgentCapacityConcern.php',
    'app/Services/Agent/BackgroundAgentStateStore.php',
    'app/Sdk/Internal/LegacyHaoCodeConfigAdapter.php',
    'app/Sdk/Internal/RunBootstrap.php',
    'scripts/runtime-dependency-check.php',
    'scripts/php-aggregate-size-check.php',
];
$asFileList = static fn (array $files): string => implode(
    "\n",
    array_map(static fn (string $file): string => "- {$file}", $files),
);
$outcomeFileList = $asFileList($outcomeFiles);
$streamFileList = $asFileList($streamFiles);
$runtimeFileList = $asFileList($runtimeFiles);

$specialist = static function (string $name, string $mission) use (
    $apiKey,
    $model,
    $baseUrl,
    $providerType,
): Agent {
    return new Agent(
        name: $name,
        model: $model,
        apiKey: $apiKey,
        baseUrl: $baseUrl,
        providerType: $providerType,
        maxTokens: 1_400,
        maxTurns: 4,
        systemPrompt: <<<PROMPT
You are {$name}, a read-only PHP architecture specialist.

Mission: {$mission}

Use Read, Grep, and Glob to inspect only files named in the delegated task.
Do not modify files and do not propose new product features. Return at most
450 words with exactly these sections: Verdict, Confirmed evidence, Risks.
Every risk must cite a concrete file and symbol; say "none found" when the
available evidence does not prove a defect.
PROMPT,
        permissionMode: 'bypass_permissions',
        allowedTools: ['Read', 'Grep', 'Glob'],
        ephemeral: true,
        contextPreset: 'generic',
    );
};

$outcomeAuditor = $specialist(
    'outcome-contract-auditor',
    'Trace typed termination from AgentRunOutcome through run-state events and public SDK results. Check that compatibility text is not used as control state.',
);
$streamAuditor = $specialist(
    'stream-lifecycle-auditor',
    'Trace Runner and Conversation streaming through FiberMessageStream. Check terminal release ordering, abandonment, HITL preservation, handler ownership, and fork safety.',
);
$runtimeAuditor = $specialist(
    'runtime-capacity-auditor',
    'Check background admission/mailbox atomicity, legacy ownership semantics, immutable run bootstrap, config adaptation, and dependency gates.',
);

$tools = [
    $outcomeAuditor->asTool(
        'audit_outcome_contract',
        'Audit typed run termination and public/result propagation in the supplied files.',
    ),
    $streamAuditor->asTool(
        'audit_stream_lifecycle',
        'Audit Fiber streaming lifecycle, cleanup ordering, HITL, and fork ownership.',
    ),
    $runtimeAuditor->asTool(
        'audit_runtime_capacity',
        'Audit background capacity plus runtime/config dependency boundaries.',
    ),
];

$lead = new Agent(
    name: 'architecture-council-lead',
    model: $model,
    apiKey: $apiKey,
    baseUrl: $baseUrl,
    providerType: $providerType,
    maxTokens: 1_800,
    maxTurns: 7,
    systemPrompt: <<<'PROMPT'
You chair a strict architecture review council. For every task, call each of
the three audit tools exactly once before answering. Never call Read, Grep, or
Glob yourself; those tools exist only so delegated agents can inherit them.
Reconcile specialist disagreements using cited evidence; do not average
opinions. After all three tools return, call no more tools.

Return concise Chinese Markdown with: one-line verdict, confirmed strengths,
P0/P1/P2 risks, and a final "是否收口" decision. Do not invent risks and do not
turn the review into a feature roadmap. The final response must be non-empty
and begin with "结论：".
PROMPT,
    permissionMode: 'bypass_permissions',
    allowedTools: [
        'Read', 'Grep', 'Glob',
        'audit_outcome_contract',
        'audit_stream_lifecycle',
        'audit_runtime_capacity',
    ],
    tools: $tools,
    ephemeral: true,
    contextPreset: 'generic',
);

$prompt = <<<PROMPT
Review the current hao-code architecture closure.

Focus: {$focus}

Delegate these exact slices:

audit_outcome_contract:
{$outcomeFileList}

audit_stream_lifecycle:
{$streamFileList}

audit_runtime_capacity:
{$runtimeFileList}

Require file-and-symbol evidence. Distinguish a proven defect from a design
tradeoff or missing test. The final verdict must be useful to a maintainer
deciding whether this closure is ready to merge.
PROMPT;

$startedAt = microtime(true);
$startedTools = [];
$completedTools = [];
$completedToolDetails = [];
$result = Runner::run(
    $lead,
    $prompt,
    new RunOptions(
        cwd: $packageRoot,
        maxBudgetUsd: $budget,
        onTurnStart: static function (int $turn): void {
            fwrite(STDERR, "[council] turn {$turn}\n");
        },
        onToolStart: static function (string $name, array $input) use (&$startedTools): void {
            $startedTools[] = $name;
            fwrite(STDERR, "[council] start {$name}\n");
        },
        onToolComplete: static function (string $name, mixed $toolResult) use (
            &$completedTools,
            &$completedToolDetails,
        ): void {
            $completedTools[] = $name;
            $isError = (bool) ($toolResult->isError ?? true);
            $outcome = $isError ? 'error' : 'ok';
            $bytes = strlen((string) ($toolResult->output ?? ''));
            $completedToolDetails[] = [
                'name' => $name,
                'is_error' => $isError,
                'output_bytes' => $bytes,
            ];
            fwrite(STDERR, "[council] complete {$name} {$outcome} {$bytes} bytes\n");
        },
    ),
);

$expectedTools = [
    'audit_outcome_contract',
    'audit_stream_lifecycle',
    'audit_runtime_capacity',
];
$startedCounts = array_count_values($startedTools);
$completedCounts = array_count_values($completedTools);
$specialistsComplete = array_reduce(
    $expectedTools,
    static function (bool $complete, string $name) use (
        $startedCounts,
        $completedCounts,
        $completedToolDetails,
    ): bool {
        $details = array_values(array_filter(
            $completedToolDetails,
            static fn (array $detail): bool => $detail['name'] === $name,
        ));

        return $complete
            && ($startedCounts[$name] ?? 0) === 1
            && ($completedCounts[$name] ?? 0) === 1
            && count($details) === 1
            && $details[0]['is_error'] === false
            && $details[0]['output_bytes'] > 0;
    },
    true,
);
$councilOk = trim($result->text) !== ''
    && $result->terminationReason->value === 'normal'
    && $specialistsComplete;

echo "# HaoCode Architecture Council\n\n";
echo $result->text."\n\n";
echo "---\n";
echo json_encode([
    'requested_model' => $model,
    'requested_base_url' => $baseUrl,
    'requested_provider_type' => $providerType,
    'termination_reason' => $result->terminationReason->value,
    'turns_used' => $result->turnsUsed,
    'usage' => $result->usage,
    'cost_usd' => $result->cost,
    'wall_seconds' => round(microtime(true) - $startedAt, 2),
    'budget_usd' => $budget,
    'started_tools' => $startedTools,
    'completed_tools' => $completedTools,
    'completed_tool_details' => $completedToolDetails,
    'council_ok' => $councilOk,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

if (! $councilOk) {
    fwrite(STDERR, "[council] failed: expected one completed call per specialist and a non-empty normal result.\n");
    exit(2);
}
