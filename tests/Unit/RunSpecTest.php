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
            ephemeral: false,
            images: [['type' => 'text', 'text' => 'image-probe']],
            responseSchema: ['type' => 'object'],
            contextPreset: 'generic',
        );

        $legacy = RunSpec::fromConfig($config);
        $modern = RunSpec::fromAgent(
            Agent::fromConfig($config),
            RunOptions::fromConfig($config),
        );

        $this->assertEquals($modern->config, $legacy->config);
        $this->assertEquals($modern->limits, $legacy->limits);
        $this->assertSame(17, $legacy->limits->maxTurns);
        $this->assertSame(1.25, $legacy->limits->maxBudgetUsd);
        $this->assertSame('generic', $legacy->config->contextPreset);
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

        $this->assertSame(9, $spec->config->maxTurns);
        $this->assertTrue($spec->config->ephemeral);
        $this->assertSame(0.5, $spec->config->maxBudgetUsd);
        $this->assertSame(['type' => 'string'], $spec->config->responseSchema);
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
