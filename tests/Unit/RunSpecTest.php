<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Internal\RunSpec;
use HaoCode\Sdk\RunOptions;
use HaoCode\Services\Agent\RunLimits;
use PHPUnit\Framework\TestCase;

class RunSpecTest extends TestCase
{
    public function test_agent_and_legacy_config_resolve_to_the_same_runtime_spec(): void
    {
        $onText = static function (string $text): void {};
        $onThinking = static function (string $text): void {};
        $onToolStart = static function (string $name, array $input): void {};
        $onToolComplete = static function (string $name, object $result): void {};
        $onTurnStart = static function (int $turn): void {};
        $tool = $this->createStub(\HaoCode\Sdk\SdkTool::class);
        $skill = new \HaoCode\Sdk\SdkSkill('review', 'Review code', 'Review $ARGUMENTS');
        $abortController = new \HaoCode\Sdk\AbortController;
        $credentialPool = new \HaoCode\Sdk\CredentialPool;
        $sandbox = \HaoCode\Sdk\Sandbox\SandboxConfig::local(root: sys_get_temp_dir().'/sandbox');
        $memoryStore = new \HaoCode\Sdk\Memory\JsonMemoryStore(
            sys_get_temp_dir().'/haocode-run-spec-memory-'.bin2hex(random_bytes(4)).'.json',
        );
        $config = new HaoCodeConfig(
            apiKey: 'test-key',
            model: 'claude-test',
            baseUrl: 'https://example.test',
            providerType: 'anthropic',
            maxTokens: 2048,
            cwd: sys_get_temp_dir(),
            maxTurns: 17,
            maxBudgetUsd: 1.25,
            permissionMode: 'plan',
            allowedTools: ['Read'],
            disallowedTools: ['Bash'],
            systemPrompt: 'system',
            appendSystemPrompt: 'append',
            thinkingEnabled: true,
            thinkingBudget: 512,
            onText: $onText,
            onThinking: $onThinking,
            onToolStart: $onToolStart,
            onToolComplete: $onToolComplete,
            onTurnStart: $onTurnStart,
            ephemeral: false,
            tools: [$tool],
            skills: [$skill],
            abortController: $abortController,
            sessionId: 'session-round-trip',
            continueSession: true,
            images: [['type' => 'text', 'text' => 'image-probe']],
            responseSchema: ['type' => 'object'],
            credentialPool: $credentialPool,
            sandbox: $sandbox,
            memorySummaryLevel: 'l2',
            memoryStoragePath: sys_get_temp_dir().'/memory.json',
            skillDirectories: [sys_get_temp_dir().'/skills'],
            recursiveSkillDiscovery: true,
            interruptOn: ['Bash' => false],
            enableAskUser: true,
            memoryStore: $memoryStore,
            hitlMode: 'ask',
            hitlReviewModel: 'review-model',
            hitlAllowlistPath: sys_get_temp_dir().'/allowlist.json',
            oauthBearer: true,
            headers: ['X-Trace' => 'round-trip'],
            structuredMaxRetries: 3,
            webfetchAllowPrivateNetworks: true,
            webfetchPrivateAllowList: ['127.0.0.1/32'],
            webfetchMaxBytes: 123456,
            contextPreset: 'generic',
            allowCwdOverride: true,
        );

        $legacy = RunSpec::fromConfig($config);
        $modern = RunSpec::fromAgent(
            Agent::fromConfig($config),
            RunOptions::fromConfig($config),
        );

        $this->assertEquals($modern->agent, $legacy->agent);
        $this->assertEquals($modern->options, $legacy->options);
        $this->assertEquals($modern->limits, $legacy->limits);
        $this->assertSame(17, $legacy->limits->maxTurns);
        $this->assertSame(1.25, $legacy->limits->maxBudgetUsd);
        $this->assertSame('generic', $legacy->agent->contextPreset);
        $this->assertEquals($config, $legacy->options->toConfig($legacy->agent));
        $this->assertTrue(
            $legacy->options->withCwd(sys_get_temp_dir())->toConfig($legacy->agent)->allowCwdOverride,
        );
    }

    public function test_run_options_override_only_run_scoped_values(): void
    {
        $agent = new Agent(
            name: 'canonical',
            apiKey: 'test-key',
            maxTurns: 9,
            ephemeral: false,
        );
        $options = new RunOptions(
            cwd: sys_get_temp_dir(),
            ephemeral: true,
            maxBudgetUsd: 0.5,
            responseSchema: ['type' => 'string'],
        );

        $spec = RunSpec::fromAgent($agent, $options);

        $this->assertSame(9, $spec->agent->maxTurns);
        $this->assertTrue($spec->options->effectiveEphemeral($spec->agent));
        $this->assertSame(0.5, $spec->options->maxBudgetUsd);
        $this->assertSame(['type' => 'string'], $spec->options->responseSchema);
    }

    public function test_runtime_default_changes_only_affect_contexts_created_after_the_change(): void
    {
        $oldHitlMode = \HaoCode\Support\Runtime\SdkRuntime::config('haocode.hitl_mode');
        $directory = sys_get_temp_dir().'/haocode-runtime-snapshot-'.bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);

        try {
            \HaoCode\Support\Runtime\SdkRuntime::config(['haocode.hitl_mode' => 'ask']);
            $first = \HaoCode\Sdk\AgentRunContextFactory::make(new HaoCodeConfig(cwd: $directory));

            \HaoCode\Support\Runtime\SdkRuntime::config(['haocode.hitl_mode' => 'auto']);
            $second = \HaoCode\Sdk\AgentRunContextFactory::make(new HaoCodeConfig(cwd: $directory));

            $this->assertSame('ask', $first->hitlMode);
            $this->assertSame('auto', $second->hitlMode);
        } finally {
            \HaoCode\Support\Runtime\SdkRuntime::config(['haocode.hitl_mode' => $oldHitlMode]);
            @rmdir($directory);
        }
    }

    public function test_resume_limits_can_only_tighten_current_limits(): void
    {
        $limits = new RunLimits(maxTurns: 20, maxBudgetUsd: 10.0);

        $this->assertSame(5.0, $limits->budgetForResume(['budget_limit_usd' => 5.0]));
        $this->assertSame(10.0, $limits->budgetForResume(['budget_limit_usd' => 15.0]));
        $this->assertSame(7, $limits->turnsForResume(['max_turns_remaining' => 7]));
        $this->assertSame(20, $limits->turnsForResume(['max_turns_remaining' => 30]));
    }

    public function test_invalid_limits_fail_before_runtime_resources_are_created(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxTurns');

        RunSpec::fromAgent(new Agent(maxTurns: 0));
    }
}
