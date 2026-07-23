<?php

namespace Tests\Feature;

use HaoCode\Services\Agent\ContextBuilder;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\Memory\MemoryWriteTool;
use HaoCode\Tools\ToolRegistry;
use Tests\TestCase;

class ContextBuilderTest extends TestCase
{
    private function makeBuilder(array $overrides = []): ContextBuilder
    {
        $settings = $overrides['settings'] ?? $this->makeSettings();
        $toolRegistry = $overrides['toolRegistry'] ?? $this->createMock(ToolRegistry::class);
        $sessionMemory = $overrides['sessionMemory'] ?? $this->makeSessionMemory();
        $skillLoader = $overrides['skillLoader'] ?? $this->makeSkillLoader();
        $gitContext = $overrides['gitContext'] ?? $this->makeGitContext();
        $styleLoader = $overrides['styleLoader'] ?? null;

        return new ContextBuilder($settings, $toolRegistry, $sessionMemory, $skillLoader, $gitContext, $styleLoader);
    }

    private function makeSettings(array $stubs = []): SettingsManager
    {
        $m = $this->createMock(SettingsManager::class);
        $m->method('getSystemPrompt')->willReturn($stubs['systemPrompt'] ?? null);
        $m->method('getAppendSystemPrompt')->willReturn($stubs['appendPrompt'] ?? null);
        $m->method('getOutputStyle')->willReturn($stubs['outputStyle'] ?? null);
        $m->method('getMemorySummaryLevel')->willReturn($stubs['memorySummaryLevel'] ?? 'l0');
        return $m;
    }

    private function makeSessionMemory(string $memories = ''): MemoryStoreInterface
    {
        $m = $this->createMock(MemoryStoreInterface::class);
        $m->method('all')->willReturn($memories === '' ? [] : ['fixture' => $memories]);
        return $m;
    }

    private function makeSkillLoader(string $descriptions = ''): SkillLoader
    {
        $m = $this->createMock(SkillLoader::class);
        $m->method('getSkillDescriptions')->willReturn($descriptions);
        return $m;
    }

    private function makeGitContext(string $diffContext = ''): GitContext
    {
        $m = $this->createMock(GitContext::class);
        $m->method('getDiffContext')->willReturn($diffContext);
        return $m;
    }

    // ─── buildSystemPrompt — return shape ─────────────────────────────────

    public function test_returns_array(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertIsArray($result);
    }

    public function test_returns_single_element_array(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertCount(1, $result);
    }

    public function test_element_has_type_text(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertSame('text', $result[0]['type']);
    }

    public function test_element_has_cache_control_ephemeral(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertSame(['type' => 'ephemeral'], $result[0]['cache_control']);
    }

    public function test_text_field_is_string(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertIsString($result[0]['text']);
    }

    // ─── buildSystemPrompt — content sections ─────────────────────────────

    public function test_prompt_contains_environment_section(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringContainsString('# Environment', $result[0]['text']);
    }

    public function test_current_date_is_kept_out_of_cache_stable_system_prompt(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString(date('Y-m-d'), $result[0]['text']);
    }

    public function test_initial_turn_context_contains_current_date(): void
    {
        $context = $this->makeBuilder()->buildTurnContext();

        $this->assertStringContainsString(date('Y-m-d'), $context);
    }

    public function test_prompt_uses_explicit_working_directory_instead_of_process_cwd(): void
    {
        $workingDirectory = sys_get_temp_dir().'/haocode_context_builder_'.uniqid('', true);
        mkdir($workingDirectory, 0755, true);

        try {
            $builder = new ContextBuilder(
                $this->makeSettings(),
                $this->createMock(ToolRegistry::class),
                $this->makeSessionMemory(),
                $this->makeSkillLoader(),
                $this->makeGitContext(),
                null,
                $workingDirectory,
            );

            $result = $builder->buildSystemPrompt();

            $this->assertStringContainsString("Working directory: {$workingDirectory}", $result[0]['text']);
        } finally {
            rmdir($workingDirectory);
        }
    }

    public function test_project_instruction_file_is_truncated_to_hard_budget(): void
    {
        $workingDirectory = sys_get_temp_dir().'/haocode_context_budget_'.uniqid('', true);
        mkdir($workingDirectory, 0755, true);
        file_put_contents($workingDirectory.'/HAOCODE.md', str_repeat('instruction-', 5000));

        try {
            $builder = new ContextBuilder(
                $this->makeSettings(),
                $this->createMock(ToolRegistry::class),
                $this->makeSessionMemory(),
                $this->makeSkillLoader(),
                $this->makeGitContext(),
                null,
                $workingDirectory,
            );

            $text = $builder->buildSystemPrompt()[0]['text'];

            $this->assertStringContainsString('context truncated by Hao Code budget', $text);
            $this->assertLessThanOrEqual(160_100, mb_strlen($text));
        } finally {
            unlink($workingDirectory.'/HAOCODE.md');
            rmdir($workingDirectory);
        }
    }

    public function test_project_agents_file_is_loaded(): void
    {
        $workingDirectory = sys_get_temp_dir().'/haocode_agents_instructions_'.uniqid('', true);
        mkdir($workingDirectory, 0755, true);
        file_put_contents($workingDirectory.'/AGENTS.md', 'Follow the project agent rules.');

        try {
            $builder = new ContextBuilder(
                $this->makeSettings(),
                $this->createMock(ToolRegistry::class),
                $this->makeSessionMemory(),
                $this->makeSkillLoader(),
                $this->makeGitContext(),
                null,
                $workingDirectory,
            );

            $text = $builder->buildSystemPrompt()[0]['text'];

            $this->assertStringContainsString('AGENTS.md', $text);
            $this->assertStringContainsString('Follow the project agent rules.', $text);
        } finally {
            unlink($workingDirectory.'/AGENTS.md');
            rmdir($workingDirectory);
        }
    }

    public function test_project_instructions_can_be_omitted_for_specialized_agents(): void
    {
        $workingDirectory = sys_get_temp_dir().'/haocode_omit_instructions_'.uniqid('', true);
        mkdir($workingDirectory, 0755, true);
        file_put_contents($workingDirectory.'/AGENTS.md', 'This project-only instruction must be omitted.');

        try {
            $builder = new ContextBuilder(
                $this->makeSettings(['appendPrompt' => 'Specialized agent system instructions.']),
                $this->createMock(ToolRegistry::class),
                $this->makeSessionMemory(),
                $this->makeSkillLoader(),
                $this->makeGitContext(),
                null,
                $workingDirectory,
                false,
                false,
                true,
            );

            $text = $builder->buildSystemPrompt()[0]['text'];

            $this->assertStringContainsString('Specialized agent system instructions.', $text);
            $this->assertStringNotContainsString('This project-only instruction must be omitted.', $text);
            $this->assertStringNotContainsString('# Project Instructions', $text);
        } finally {
            unlink($workingDirectory.'/AGENTS.md');
            rmdir($workingDirectory);
        }
    }

    public function test_text_only_prompt_omits_coding_agent_context(): void
    {
        $settings = $this->makeSettings(['appendPrompt' => 'Keep the answer factual.']);
        $builder = new ContextBuilder(
            $settings,
            new ToolRegistry(),
            $this->makeSessionMemory('persistent memory'),
            $this->makeSkillLoader('/review — Review code'),
            $this->makeGitContext("# Git Status\n- Working tree: dirty"),
            null,
            getcwd() ?: '/',
            true,
        );

        $text = $builder->buildSystemPrompt()[0]['text'];

        $this->assertLessThan(1000, strlen($text));
        $this->assertStringContainsString('Keep the answer factual.', $text);
        $this->assertStringContainsString('You have no tools', $text);
        $this->assertStringNotContainsString('# Skills', $text);
        $this->assertStringNotContainsString('# Git Status', $text);
        $this->assertStringNotContainsString('# Project Instructions', $text);
        $this->assertStringNotContainsString('# Long-Term Memory', $text);
    }

    public function test_text_only_prompt_includes_explicitly_configured_long_term_memory(): void
    {
        $builder = new ContextBuilder(
            $this->makeSettings(),
            new ToolRegistry(),
            $this->makeSessionMemory('user prefers concise responses'),
            $this->makeSkillLoader(),
            $this->makeGitContext(),
            null,
            getcwd() ?: '/',
            true,
            true,
        );

        $text = $builder->buildSystemPrompt()[0]['text'];

        $this->assertStringContainsString('# Long-Term Memory', $text);
        $this->assertStringContainsString('user prefers concise responses', $text);
        $this->assertStringNotContainsString('# Project Instructions', $text);
    }

    public function test_prompt_includes_truthful_validation_instructions(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();

        $this->assertStringContainsString('When you claim something was validated or tested', $result[0]['text']);
        $this->assertStringContainsString('capture and report the exact HTTP status code', $result[0]['text']);
        $this->assertStringContainsString('Do not say "all tests passed"', $result[0]['text']);
        $this->assertStringContainsString('negative or invalid-input check', $result[0]['text']);
        $this->assertStringContainsString('do not send a giant multiline payload in one Write or Bash call', $result[0]['text']);
        $this->assertStringContainsString('Do not use Agent or Skill as a fallback for ordinary file creation or editing errors.', $result[0]['text']);
    }

    public function test_prompt_requires_finishing_requested_work_before_stopping(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();

        $this->assertStringContainsString('keep going until that work is actually finished', $result[0]['text']);
        $this->assertStringContainsString('Do not stop after describing a plan', $result[0]['text']);
        $this->assertStringContainsString('Do not end your response with "I\'ll do X next"', $result[0]['text']);
        $this->assertStringContainsString('do not send a giant multiline payload in one Write or Bash call', $result[0]['text']);
        $this->assertStringContainsString('Do not use Agent or Skill as a fallback', $result[0]['text']);
    }

    public function test_append_system_prompt_is_included(): void
    {
        $settings = $this->makeSettings(['appendPrompt' => 'Extra instructions here']);
        $result = $this->makeBuilder(['settings' => $settings])->buildSystemPrompt();
        $this->assertStringContainsString('Extra instructions here', $result[0]['text']);
    }

    public function test_system_prompt_override_replaces_default_prompt(): void
    {
        $settings = $this->makeSettings(['systemPrompt' => 'You are a startup override.']);
        $result = $this->makeBuilder(['settings' => $settings])->buildSystemPrompt();

        $this->assertStringContainsString('You are a startup override.', $result[0]['text']);
        $this->assertStringNotContainsString('You are Hao Code, an embedded PHP agent SDK', $result[0]['text']);
    }

    public function test_session_memory_is_included_when_non_empty(): void
    {
        $sessionMemory = $this->makeSessionMemory("Remember: user prefers concise responses");
        $result = $this->makeBuilder(['sessionMemory' => $sessionMemory])->buildSystemPrompt();
        $this->assertStringContainsString('# Long-Term Memory', $result[0]['text']);
        $this->assertStringContainsString('user prefers concise responses', $result[0]['text']);
    }

    public function test_session_memory_section_absent_when_empty(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('# Long-Term Memory', $result[0]['text']);
    }

    public function test_memory_write_tool_adds_explicit_update_policy(): void
    {
        $memoryStore = $this->makeSessionMemory();
        $registry = new ToolRegistry();
        $registry->register(new MemoryWriteTool($memoryStore));

        $text = $this->makeBuilder([
            'sessionMemory' => $memoryStore,
            'toolRegistry' => $registry,
        ])->buildSystemPrompt()[0]['text'];

        $this->assertStringContainsString('# Long-Term Memory Update Policy', $text);
        $this->assertStringContainsString('explicitly asks to remember', $text);
        $this->assertStringContainsString('Never store credentials', $text);
    }

    public function test_skills_section_included_when_non_empty(): void
    {
        $skillLoader = $this->makeSkillLoader("/commit — Create a commit");
        $result = $this->makeBuilder(['skillLoader' => $skillLoader])->buildSystemPrompt();
        $this->assertStringContainsString('# Skills', $result[0]['text']);
        $this->assertStringContainsString('/commit', $result[0]['text']);
    }

    public function test_skills_section_includes_progressive_disclosure_protocol(): void
    {
        $skillLoader = $this->makeSkillLoader("/commit — Create a commit");
        $result = $this->makeBuilder(['skillLoader' => $skillLoader])->buildSystemPrompt();
        $text = $result[0]['text'];

        $this->assertStringContainsString('How to use skills', $text);
        $this->assertStringContainsString('Progressive disclosure', $text);
        $this->assertStringContainsString('read its SKILL.md body completely', $text);
        $this->assertStringContainsString('${HAOCODE_SKILL_DIR}', $text);
        $this->assertStringContainsString('references/', $text);
    }

    public function test_skills_protocol_omitted_when_no_skills_available(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('How to use skills', $result[0]['text']);
    }

    public function test_skills_section_absent_when_empty(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('# Skills', $result[0]['text']);
    }

    public function test_prompt_contains_haocode_conventions_for_skill_paths(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();

        $this->assertStringContainsString('# Hao Code Conventions', $result[0]['text']);
        $this->assertStringContainsString('.haocode/skills/', $result[0]['text']);
        $this->assertStringContainsString('not `.claude`', $result[0]['text']);
    }

    public function test_git_context_is_kept_out_of_cache_stable_system_prompt(): void
    {
        $gitContext = $this->makeGitContext("Branch: main\n# Git Status\nclean");
        $result = $this->makeBuilder(['gitContext' => $gitContext])->buildSystemPrompt();

        $this->assertStringNotContainsString('Branch: main', $result[0]['text']);
        $this->assertStringNotContainsString('# Git Status', $result[0]['text']);
    }

    public function test_git_context_is_available_as_initial_turn_context(): void
    {
        $gitContext = $this->makeGitContext("Branch: main\n# Git Status\nclean");
        $context = $this->makeBuilder(['gitContext' => $gitContext])->buildTurnContext();

        $this->assertStringContainsString('# Runtime', $context);
        $this->assertStringContainsString("Branch: main\n# Git Status\nclean", $context);
    }

    public function test_git_context_absent_when_empty(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('Branch:', $result[0]['text']);
    }

    public function test_git_snapshot_is_released_when_prompt_building_fails(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getSystemPrompt')->willReturn(null);
        $settings->method('getAppendSystemPrompt')->willThrowException(new \RuntimeException('fixture failure'));

        $gitContext = $this->createMock(GitContext::class);
        $gitContext->expects($this->once())->method('beginSnapshot');
        $gitContext->expects($this->once())->method('endSnapshot');

        $builder = $this->makeBuilder([
            'settings' => $settings,
            'gitContext' => $gitContext,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fixture failure');

        $builder->buildSystemPrompt();
    }

    public function test_text_only_prompt_does_not_open_git_snapshot(): void
    {
        $gitContext = $this->createMock(GitContext::class);
        $gitContext->expects($this->never())->method('beginSnapshot');
        $gitContext->expects($this->never())->method('endSnapshot');

        $builder = new ContextBuilder(
            $this->makeSettings(),
            new ToolRegistry(),
            $this->makeSessionMemory(),
            $this->makeSkillLoader(),
            $gitContext,
            null,
            getcwd() ?: '/',
            true,
        );

        $this->assertSame('text', $builder->buildSystemPrompt()[0]['type']);
    }

    public function test_output_style_injected_when_set(): void
    {
        $settings = $this->makeSettings(['outputStyle' => 'terse']);

        $styleLoader = $this->createMock(OutputStyleLoader::class);
        $styleLoader->method('getActiveStyleContent')
            ->with('terse')
            ->willReturn('Be very brief.');

        $result = $this->makeBuilder(['settings' => $settings, 'styleLoader' => $styleLoader])
            ->buildSystemPrompt();

        $this->assertStringContainsString('# Output Style Instructions', $result[0]['text']);
        $this->assertStringContainsString('Be very brief.', $result[0]['text']);
    }

    public function test_output_style_absent_when_null(): void
    {
        $result = $this->makeBuilder()->buildSystemPrompt();
        $this->assertStringNotContainsString('# Output Style Instructions', $result[0]['text']);
    }

    public function test_output_style_absent_when_loader_not_provided(): void
    {
        $settings = $this->makeSettings(['outputStyle' => 'verbose']);
        // No styleLoader passed
        $result = $this->makeBuilder(['settings' => $settings])->buildSystemPrompt();
        $this->assertStringNotContainsString('# Output Style Instructions', $result[0]['text']);
    }
}
