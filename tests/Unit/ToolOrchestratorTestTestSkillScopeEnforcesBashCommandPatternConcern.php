<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\ToolOrchestrator;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\FileRead\FileReadTool;
use HaoCode\Tools\FileWrite\FileWriteTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait ToolOrchestratorTestTestSkillScopeEnforcesBashCommandPatternConcern
{

    public function test_skill_scope_enforces_bash_command_pattern(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Skill', fn () => ToolResult::success('loaded', [
            'allowed_tools' => ['Bash(cargo:*)'],
            'context' => 'inline',
        ])));
        $bashCalls = [];
        $registry->register($this->makeTool('Bash', function (array $input) use (&$bashCalls) {
            $bashCalls[] = $input['command'] ?? '';

            return ToolResult::success('ok');
        }));
        $orchestrator = $this->makeOrchestrator($registry);

        $orchestrator->executeToolBlock(
            ['id' => 'skill-1', 'name' => 'Skill', 'input' => []],
            $this->context(),
        );
        $allowed = $orchestrator->executeToolBlock(
            ['id' => 'bash-1', 'name' => 'Bash', 'input' => ['command' => 'cargo test']],
            $this->context(),
        );
        $denied = $orchestrator->executeToolBlock(
            ['id' => 'bash-2', 'name' => 'Bash', 'input' => ['command' => 'rm -rf /tmp']],
            $this->context(),
        );

        $this->assertFalse($allowed['is_error']);
        $this->assertTrue($denied['is_error']);
        $this->assertStringContainsString('active skill scope', $denied['content']);
        $this->assertSame(['Bash(cargo:*)'], $orchestrator->getActiveSkillAllowedTools());
        $this->assertSame(['cargo test'], $bashCalls);
    }

    public function test_multiple_skill_scopes_intersect_allowed_tools(): void
    {
        $registry = new ToolRegistry;
        $skillResults = [
            ToolResult::success('one', ['allowed_tools' => ['Read', 'Grep']]),
            ToolResult::success('two', ['allowed_tools' => ['Read', 'Bash']]),
        ];
        $registry->register($this->makeTool('Skill', function () use (&$skillResults) {
            return array_shift($skillResults);
        }));
        $orchestrator = $this->makeOrchestrator($registry);

        $orchestrator->executeToolBlock(['id' => 's1', 'name' => 'Skill', 'input' => []], $this->context());
        $orchestrator->executeToolBlock(['id' => 's2', 'name' => 'Skill', 'input' => []], $this->context());

        $this->assertSame(['Read'], $orchestrator->getActiveSkillAllowedTools());
    }

    public function test_forked_skill_does_not_restrict_parent_tool_scope(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Skill', fn () => ToolResult::success('child result', [
            'allowed_tools' => ['Read'],
            'model_override' => 'child-model',
            'context' => 'fork',
        ])));
        $orchestrator = $this->makeOrchestrator($registry);

        $orchestrator->executeToolBlock(['id' => 'fork-1', 'name' => 'Skill', 'input' => []], $this->context());

        $this->assertNull($orchestrator->getActiveSkillAllowedTools());
        $this->assertNull($orchestrator->getActiveSkillModelOverride());
        $this->assertSame('fork', $orchestrator->getActiveSkillContext());
    }

    public function test_restored_skill_scope_keeps_disallowed_tools_blocked_after_interrupt(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Read', fn () => ToolResult::success('read')));
        $registry->register($this->makeTool('Write', fn () => ToolResult::success('must not run')));
        $orchestrator = $this->makeOrchestrator($registry);

        $orchestrator->setResumeAllowedTools(['Read']);
        $orchestrator->restoreSkillScope(['Read'], 'skill-model', 'inline');

        $read = $orchestrator->executeToolBlock(
            ['id' => 'read-1', 'name' => 'Read', 'input' => []],
            $this->context(),
        );
        $write = $orchestrator->executeToolBlock(
            ['id' => 'write-1', 'name' => 'Write', 'input' => []],
            $this->context(),
        );

        $this->assertFalse($read['is_error']);
        $this->assertTrue($write['is_error']);
        $this->assertStringContainsString('active skill scope', $write['content']);
        $this->assertSame(['Read'], $orchestrator->getAdvertisedAllowedTools());
        $this->assertSame('skill-model', $orchestrator->getActiveSkillModelOverride());
    }
}
