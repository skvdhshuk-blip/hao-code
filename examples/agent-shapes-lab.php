#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Exercise five distinct HaoCode agent shapes against one provider:
 * custom-tool, structured, streaming, conversation, and delegated.
 *
 * Usage:
 *   AGENT_SHAPES_API_KEY=... \
 *   AGENT_SHAPES_BASE_URL=https://api.example.com/anthropic \
 *   AGENT_SHAPES_MODEL=example-model \
 *     php examples/agent-shapes-lab.php
 *
 * Run one shape with --shape=tool|structured|stream|conversation|delegate.
 * Use --list to inspect the contracts without making a model request.
 */

use HaoCode\Contracts\RunTerminationReason;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Runner;
use HaoCode\Sdk\SdkTool;
use HaoCode\Support\Runtime\SdkRuntime;

$packageRoot = dirname(__DIR__);
require_once $packageRoot.'/vendor/autoload.php';

SdkRuntime::boot(basePath: $packageRoot);

final class InspectComposerContractTool extends SdkTool
{
    public function __construct(private readonly string $manifestPath) {}

    public function name(): string
    {
        return 'InspectComposerContract';
    }

    public function description(): string
    {
        return 'Read selected package, runtime, or quality-gate facts from composer.json.';
    }

    public function parameters(): array
    {
        return [
            'section' => [
                'type' => 'string',
                'description' => 'The manifest facts to return.',
                'required' => true,
                'enum' => ['identity', 'runtime', 'gates'],
            ],
        ];
    }

    public function handle(array $input): string
    {
        $manifest = json_decode(
            (string) file_get_contents($this->manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $facts = match ($input['section']) {
            'identity' => [
                'name' => $manifest['name'] ?? null,
                'description' => $manifest['description'] ?? null,
            ],
            'runtime' => [
                'php' => $manifest['require']['php'] ?? null,
                'symfony_console' => $manifest['require']['symfony/console'] ?? null,
            ],
            'gates' => [
                'test' => $manifest['scripts']['test'] ?? null,
                'lint' => $manifest['scripts']['lint'] ?? null,
                'aggregate_size' => $manifest['scripts']['quality:aggregate-size'] ?? null,
                'dependency_direction' => $manifest['scripts']['quality:dependency-direction'] ?? null,
            ],
            default => throw new InvalidArgumentException('Unknown composer contract section.'),
        };

        return json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

}

$contracts = [
    'tool' => 'A custom read-only tool is called exactly once and produces a non-empty final report.',
    'structured' => 'The response passes JSON Schema and makes the expected incident decision.',
    'stream' => 'Text arrives incrementally and matches one normal terminal result.',
    'conversation' => 'The second turn uses facts supplied only in the first turn.',
    'delegate' => 'A lead calls one specialist exactly once and rejects an empty/error child result.',
];

if (in_array('--list', $argv, true)) {
    echo json_encode($contracts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

$environment = static function (string $name): ?string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
};
$option = static function (string $prefix) use ($argv): ?string {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }

    return null;
};

$selected = $option('--shape=');
if ($selected !== null && ! array_key_exists($selected, $contracts)) {
    fwrite(STDERR, "Unknown shape: {$selected}\n");
    exit(1);
}

$apiKey = $environment('AGENT_SHAPES_API_KEY') ?? $environment('ANTHROPIC_API_KEY');
if ($apiKey === null) {
    fwrite(STDERR, "AGENT_SHAPES_API_KEY or ANTHROPIC_API_KEY is required.\n");
    exit(1);
}

$model = $environment('AGENT_SHAPES_MODEL');
$baseUrl = $environment('AGENT_SHAPES_BASE_URL');
$providerType = $environment('AGENT_SHAPES_PROVIDER_TYPE') ?? 'anthropic';
$shapeIds = $selected === null ? array_keys($contracts) : [$selected];

$makeAgent = static function (
    string $name,
    string $systemPrompt,
    array $allowedTools = [],
    array $tools = [],
    int $maxTurns = 2,
    int $maxTokens = 700,
) use ($apiKey, $model, $baseUrl, $providerType): Agent {
    return new Agent(
        name: $name,
        model: $model,
        apiKey: $apiKey,
        baseUrl: $baseUrl,
        providerType: $providerType,
        maxTokens: $maxTokens,
        maxTurns: $maxTurns,
        systemPrompt: $systemPrompt,
        permissionMode: 'bypass_permissions',
        allowedTools: $allowedTools,
        tools: $tools,
        ephemeral: true,
        contextPreset: 'generic',
    );
};

$makeConfig = static function (
    string $systemPrompt,
    int $maxTurns = 2,
    int $maxTokens = 700,
) use ($apiKey, $model, $baseUrl, $providerType, $packageRoot): HaoCodeConfig {
    return new HaoCodeConfig(
        apiKey: $apiKey,
        model: $model,
        baseUrl: $baseUrl,
        providerType: $providerType,
        maxTokens: $maxTokens,
        maxTurns: $maxTurns,
        systemPrompt: $systemPrompt,
        permissionMode: 'bypass_permissions',
        allowedTools: [],
        ephemeral: true,
        cwd: $packageRoot,
        contextPreset: 'generic',
    );
};

$runners = [];

$runners['tool'] = static function () use ($makeAgent, $packageRoot): array {
    $tool = new InspectComposerContractTool($packageRoot.'/composer.json');
    $started = [];
    $completed = [];
    $agent = $makeAgent(
        'quality-gate-reader',
        'You are a release engineer. Call InspectComposerContract exactly once with section=gates. '
            .'Then write a concise maintainer note naming the test, lint, aggregate-size, and dependency-direction gates.',
        ['InspectComposerContract'],
        [$tool],
        2,
    );
    $result = Runner::run(
        $agent,
        'Read the actual Composer quality gates and report them.',
        new RunOptions(
            cwd: $packageRoot,
            onToolStart: static function (string $name, array $_input) use (&$started): void {
                if ($name === 'InspectComposerContract') {
                    $started[] = $name;
                }
            },
            onToolComplete: static function (string $name, object $toolResult) use (&$completed): void {
                if ($name === 'InspectComposerContract') {
                    $completed[] = [
                        'is_error' => (bool) ($toolResult->isError ?? true),
                        'output_bytes' => strlen((string) ($toolResult->output ?? '')),
                    ];
                }
            },
        ),
    );
    $execution = count($completed) === 1 ? $completed[0] : null;

    return [
        'ok' => count($started) === 1
            && count($completed) === 1
            && is_array($execution)
            && $execution['is_error'] === false
            && $execution['output_bytes'] > 0
            && trim($result->text) !== ''
            && $result->terminationReason === RunTerminationReason::Normal,
        'tool_calls' => count($started),
        'tool_results' => $completed,
        'termination_reason' => $result->terminationReason->value,
        'usage' => $result->usage,
        'output' => $result->text,
    ];
};

$runners['structured'] = static function () use ($makeConfig): array {
    $result = HaoCode::structured(
        'Incident facts: checkout error rate reached 12% immediately after payments build 8421; '
            .'a tested rollback is ready; there is no data corruption. Choose the immediate response.',
        [
            'type' => 'object',
            'properties' => [
                'severity' => ['type' => 'string', 'enum' => ['p1', 'p2', 'p3']],
                'owner' => ['type' => 'string', 'enum' => ['payments', 'platform', 'support']],
                'action' => ['type' => 'string', 'enum' => ['rollback', 'investigate', 'monitor']],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['severity', 'owner', 'action', 'reason'],
            'additionalProperties' => false,
        ],
        $makeConfig('You are an incident commander. Prefer the safest reversible action supported by the facts.'),
    );
    $data = $result->toArray();

    return [
        'ok' => ($data['severity'] ?? null) === 'p1'
            && ($data['owner'] ?? null) === 'payments'
            && ($data['action'] ?? null) === 'rollback'
            && trim((string) ($data['reason'] ?? '')) !== '',
        'termination_reason' => $result->queryResult?->terminationReason->value,
        'usage' => $result->queryResult?->usage,
        'output' => $data,
    ];
};

$runners['stream'] = static function () use ($makeAgent, $packageRoot): array {
    $agent = $makeAgent(
        'streaming-release-coach',
        'You are a terse release coach. Answer with exactly three short Markdown bullets and no preamble.',
        [],
        [],
        1,
    );
    $deltas = '';
    $events = [];
    $terminal = null;
    foreach (Runner::stream(
        $agent,
        'State three checks that protect a PHP SDK release: tests, public API compatibility, and dependency security.',
        new RunOptions(cwd: $packageRoot),
    ) as $message) {
        $events[] = $message->type;
        if ($message->type === 'text') {
            $deltas .= $message->text;
        }
        if ($message->isResult()) {
            $terminal = $message;
        }
    }

    return [
        'ok' => $terminal instanceof Message
            && trim($deltas) !== ''
            && $deltas === $terminal->text
            && $terminal->terminationReason === RunTerminationReason::Normal
            && count(array_filter($events, static fn (string $type): bool => $type === 'result')) === 1,
        'event_counts' => array_count_values($events),
        'termination_reason' => $terminal?->terminationReason?->value,
        'usage' => $terminal?->usage,
        'output' => $terminal?->text,
    ];
};

$runners['conversation'] = static function () use ($makeConfig): array {
    $conversation = HaoCode::conversation($makeConfig(
        'You are an incident commander. Preserve exact build identifiers across turns and answer concisely.',
        1,
    ));
    try {
        $first = $conversation->send(
            'Context for the next turn: checkout errors began after payments build 8421; rollback is tested and ready. '
                .'Acknowledge the incident in one sentence without proposing a plan yet.',
        );
        $second = $conversation->send(
            'Now give one recovery instruction. Name the build and the reversible action from the previous turn.',
        );
    } finally {
        $conversation->close();
    }

    $remembersBuild = str_contains($second->text, '8421');
    $remembersAction = str_contains(strtolower($second->text), 'rollback')
        || str_contains($second->text, '回滚');

    return [
        'ok' => trim($first->text) !== ''
            && trim($second->text) !== ''
            && $first->terminationReason === RunTerminationReason::Normal
            && $second->terminationReason === RunTerminationReason::Normal
            && $remembersBuild
            && $remembersAction,
        'turn_count' => $conversation->getTurnCount(),
        'termination_reason' => $second->terminationReason->value,
        'usage' => $second->usage,
        'output' => [
            'acknowledgement' => $first->text,
            'recovery_instruction' => $second->text,
        ],
    ];
};

$runners['delegate'] = static function () use ($makeAgent, $packageRoot): array {
    $specialist = $makeAgent(
        'termination-contract-specialist',
        'Read only the file named in the task. Return a short evidence note listing every enum value and its role.',
        ['Read'],
        [],
        3,
        700,
    );
    $toolName = 'audit_termination_enum';
    $lead = $makeAgent(
        'contract-review-lead',
        "Call {$toolName} exactly once. Do not call Read yourself. After it returns, write one concise Chinese maintainer note.",
        ['Read', $toolName],
        [$specialist->asTool($toolName, 'Inspect the run termination enum and return file-backed evidence.')],
        3,
        700,
    );
    $started = [];
    $completed = [];
    $result = Runner::run(
        $lead,
        'Audit app/Contracts/RunTerminationReason.php. Report what control-flow values SDK consumers can rely on.',
        new RunOptions(
            cwd: $packageRoot,
            onToolStart: static function (string $name, array $_input) use (&$started, $toolName): void {
                if ($name === $toolName) {
                    $started[] = $name;
                }
            },
            onToolComplete: static function (string $name, object $toolResult) use (
                &$completed,
                $toolName,
            ): void {
                if ($name === $toolName) {
                    $completed[] = [
                        'is_error' => (bool) ($toolResult->isError ?? true),
                        'output_bytes' => strlen((string) ($toolResult->output ?? '')),
                    ];
                }
            },
        ),
    );
    $child = $completed[0] ?? null;

    return [
        'ok' => count($started) === 1
            && count($completed) === 1
            && is_array($child)
            && $child['is_error'] === false
            && $child['output_bytes'] > 0
            && trim($result->text) !== ''
            && $result->terminationReason === RunTerminationReason::Normal,
        'delegate_calls' => count($started),
        'delegate_result' => $child,
        'termination_reason' => $result->terminationReason->value,
        'usage' => $result->usage,
        'output' => $result->text,
    ];
};

$results = [];
foreach ($shapeIds as $shapeId) {
    fwrite(STDERR, "[agent-shapes] start {$shapeId}\n");
    $startedAt = microtime(true);
    try {
        $details = $runners[$shapeId]();
        $details['duration_seconds'] = round(microtime(true) - $startedAt, 2);
        $results[$shapeId] = $details;
    } catch (Throwable $exception) {
        $results[$shapeId] = [
            'ok' => false,
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            'error' => $exception->getMessage(),
            'exception' => $exception::class,
        ];
    }
    $status = ($results[$shapeId]['ok'] ?? false) ? 'ok' : 'failed';
    fwrite(STDERR, "[agent-shapes] {$shapeId} {$status}\n");
}

$passed = count(array_filter($results, static fn (array $result): bool => (bool) ($result['ok'] ?? false)));
$report = [
    'requested_provider' => [
        'type' => $providerType,
        'model' => $model,
        'base_url' => $baseUrl,
    ],
    'contracts' => array_intersect_key($contracts, array_flip($shapeIds)),
    'summary' => [
        'total' => count($shapeIds),
        'passed' => $passed,
        'failed' => count($shapeIds) - $passed,
        'suite_ok' => $passed === count($shapeIds),
    ],
    'results' => $results,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
exit($report['summary']['suite_ok'] ? 0 : 2);
