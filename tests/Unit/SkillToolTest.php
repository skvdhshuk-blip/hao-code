<?php

namespace Tests\Unit;

use HaoCode\Tools\Skill\SkillDefinition;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

/** Test fixture that resolves the bound loader before constructing the production tool. */
class SkillTool extends \HaoCode\Tools\Skill\SkillTool
{
    public function __construct(?SkillLoader $skillLoader = null, mixed $forkRunner = null)
    {
        parent::__construct(
            $skillLoader ?? app(SkillLoader::class),
            $forkRunner,
        );
    }
}

class SkillToolTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'sess_test_123',
        );
    }

    private function makeLoader(array $skills = []): SkillLoader
    {
        $loader = $this->createMock(SkillLoader::class);
        $loader->method('loadSkills')->willReturn($skills);
        $loader->method('findSkill')->willReturnCallback(function (string $name) use ($skills) {
            $name = ltrim($name, '/');
            return $skills[$name] ?? null;
        });
        return $loader;
    }

    private function makeSkill(string $name, string $prompt, array $overrides = []): SkillDefinition
    {
        return new SkillDefinition(
            name: $name,
            description: $overrides['description'] ?? 'A skill',
            whenToUse: null,
            prompt: $prompt,
            allowedTools: $overrides['allowedTools'] ?? [],
            model: $overrides['model'] ?? null,
            context: $overrides['context'] ?? 'inline',
            userInvocable: true,
            argumentHint: $overrides['argumentHint'] ?? null,
            skillDir: '/skills/dir',
        );
    }

    private function callTool(SkillTool $tool, array $input): \HaoCode\Tools\ToolResult
    {
        return $tool->call($input, $this->context);
    }

    // ─── expandPrompt — $ARGUMENTS substitution ───────────────────────────

    public function test_arguments_substitution_in_prompt(): void
    {
        $skill = $this->makeSkill('deploy', 'Deploy to $ARGUMENTS environment');
        $loader = $this->makeLoader(['deploy' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'deploy', 'args' => 'production'], $this->context);

        $this->assertStringContainsString('Deploy to production environment', $result->output);
    }

    public function test_arguments_empty_string_when_not_provided(): void
    {
        $skill = $this->makeSkill('test', 'Run tests with $ARGUMENTS flags');
        $loader = $this->makeLoader(['test' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'test'], $this->context);

        $this->assertStringContainsString('Run tests with  flags', $result->output);
    }

    // ─── expandPrompt — session variable substitution ─────────────────────

    public function test_session_id_substitution(): void
    {
        $skill = $this->makeSkill('log', 'Session: ${CLAUDE_SESSION_ID}');
        $loader = $this->makeLoader(['log' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'log'], $this->context);

        $this->assertStringContainsString('Session: sess_test_123', $result->output);
    }

    public function test_haocode_session_id_substitution(): void
    {
        $skill = $this->makeSkill('log', 'ID: ${HAOCODE_SESSION_ID}');
        $loader = $this->makeLoader(['log' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'log'], $this->context);

        $this->assertStringContainsString('ID: sess_test_123', $result->output);
    }

    public function test_skill_dir_substitution(): void
    {
        $skill = $this->makeSkill('info', 'Dir: ${CLAUDE_SKILL_DIR}');
        $loader = $this->makeLoader(['info' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'info'], $this->context);

        $this->assertStringContainsString('Dir: /skills/dir', $result->output);
    }

    // ─── expandPrompt — shell command substitution ────────────────────────

    public function test_inline_shell_command_is_rendered_as_normal_bash_tool_directive(): void
    {
        $skill = $this->makeSkill('echo', "Before\n!`echo hello`\nAfter");
        $loader = $this->makeLoader(['echo' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'echo'], $this->context);

        $this->assertStringContainsString('<skill_shell_directive>', $result->output);
        $this->assertStringContainsString('echo hello', $result->output);
    }

    public function test_inline_shell_syntax_does_not_match_ordinary_markdown_code_spans(): void
    {
        $prompt = 'Do not use `panic!` in production and avoid `unwrap()`.';
        $skill = $this->makeSkill('rust', $prompt);
        $loader = $this->makeLoader(['rust' => $skill]);
        $tool = new SkillTool($loader);

        $result = $tool->call(['skill' => 'rust'], $this->context);

        $this->assertStringEndsWith($prompt, $result->output);
    }

    public function test_inline_shell_command_is_not_executed_while_loading_skill(): void
    {
        $target = sys_get_temp_dir().'/haocode-skill-shell-'.bin2hex(random_bytes(4));
        $skill = $this->makeSkill('shell', '!`touch '.escapeshellarg($target).'`');
        $loader = $this->makeLoader(['shell' => $skill]);
        $tool = new SkillTool($loader);

        $result = $tool->call(['skill' => 'shell'], $this->context);

        $this->assertFileDoesNotExist($target);
        $this->assertStringContainsString('touch', $result->output);
    }

    // ─── leading slash stripped ───────────────────────────────────────────

    public function test_leading_slash_in_skill_name_stripped(): void
    {
        $skill = $this->makeSkill('commit', 'Commit the changes');
        $loader = $this->makeLoader(['commit' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => '/commit'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Commit the changes', $result->output);
    }

    // ─── unknown skill falls back to list ─────────────────────────────────

    public function test_unknown_skill_shows_list_with_error_prefix(): void
    {
        $skill = $this->makeSkill('commit', 'Commit');
        $loader = $this->makeLoader(['commit' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'no-such-skill'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Unknown skill', $result->output);
        $this->assertStringContainsString('/commit', $result->output);
    }

    // ─── list action ──────────────────────────────────────────────────────

    public function test_list_action_shows_skills(): void
    {
        $skill = $this->makeSkill('review', 'Review code', ['argumentHint' => 'pr-number']);
        $loader = $this->makeLoader(['review' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'list', 'action' => 'list'], $this->context);

        $this->assertStringContainsString('/review', $result->output);
        $this->assertStringContainsString('pr-number', $result->output);
    }

    public function test_list_action_with_no_skills_shows_help_message(): void
    {
        $loader = $this->makeLoader([]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'list'], $this->context);

        $this->assertStringContainsString('No skills available', $result->output);
    }

    public function test_list_action_accepts_no_skill_field(): void
    {
        $skill = $this->makeSkill('review', 'Review code');
        $loader = $this->makeLoader(['review' => $skill]);
        $tool = new SkillTool($loader);

        $validated = $tool->inputSchema()->validate(['action' => 'list']);
        $result = $tool->call($validated, $this->context);

        $this->assertStringContainsString('/review', $result->output);
    }

    public function test_search_filters_by_name_and_description(): void
    {
        $loader = $this->makeLoader([
            'mysql-standards' => $this->makeSkill('mysql-standards', 'Prompt', ['description' => 'Check database schemas']),
            'humanizer' => $this->makeSkill('humanizer', 'Prompt', ['description' => 'Rewrite Chinese prose']),
        ]);
        $tool = new SkillTool($loader);

        $result = $tool->call(['action' => 'search', 'query' => 'database'], $this->context);

        $this->assertStringContainsString('/mysql-standards', $result->output);
        $this->assertStringNotContainsString('/humanizer', $result->output);
    }

    public function test_list_results_are_paginated(): void
    {
        $skills = [];
        for ($i = 1; $i <= 30; $i++) {
            $name = sprintf('skill-%02d', $i);
            $skills[$name] = $this->makeSkill($name, 'Prompt');
        }
        $tool = new SkillTool($this->makeLoader($skills));

        $result = $tool->call(['action' => 'list', 'page' => 2, 'per_page' => 10], $this->context);

        $this->assertStringContainsString('page 2/3', $result->output);
        $this->assertStringContainsString('/skill-11', $result->output);
        $this->assertStringNotContainsString('/skill-01 ', $result->output);
    }

    // ─── result metadata ──────────────────────────────────────────────────

    public function test_successful_run_includes_skill_name_in_metadata(): void
    {
        $skill = $this->makeSkill('build', 'Build the project', ['allowedTools' => ['Bash']]);
        $loader = $this->makeLoader(['build' => $skill]);
        app()->instance(SkillLoader::class, $loader);

        $tool = new SkillTool;
        $result = $tool->call(['skill' => 'build'], $this->context);

        $this->assertSame('build', $result->metadata['skill']);
        $this->assertSame(['Bash'], $result->metadata['allowed_tools']);
        $this->assertSame('/skills/dir', $result->metadata['skill_dir']);
        $this->assertStringContainsString('directory="/skills/dir"', $result->output);
    }

    // ─── isReadOnly ───────────────────────────────────────────────────────

    public function test_is_not_read_only(): void
    {
        $this->assertFalse((new SkillTool)->isReadOnly([]));
    }

    public function test_inline_skill_is_read_only_but_forked_skill_is_not(): void
    {
        $loader = $this->makeLoader([
            'inline' => $this->makeSkill('inline', 'Prompt'),
            'forked' => $this->makeSkill('forked', 'Prompt', ['context' => 'fork']),
        ]);
        $tool = new SkillTool($loader);

        $this->assertTrue($tool->isReadOnly(['skill' => 'inline']));
        $this->assertFalse($tool->isReadOnly(['skill' => 'forked']));
    }

    public function test_is_not_concurrency_safe_because_shell_directives_may_run(): void
    {
        $this->assertFalse((new SkillTool)->isConcurrencySafe([]));
    }

    public function test_fork_context_runs_with_configured_fork_runner(): void
    {
        $skill = $this->makeSkill('research', 'Inspect $ARGUMENTS', ['context' => 'fork']);
        $loader = $this->makeLoader(['research' => $skill]);
        $receivedPrompt = null;
        $tool = new SkillTool(
            skillLoader: $loader,
            forkRunner: function (string $prompt) use (&$receivedPrompt): string {
                $receivedPrompt = $prompt;

                return 'child result';
            },
        );

        $result = $tool->call(['skill' => 'research', 'args' => 'repository'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringEndsWith('Inspect repository', (string) $receivedPrompt);
        $this->assertSame('child result', $result->output);
        $this->assertSame('fork', $result->metadata['context']);
    }

    public function test_fork_context_fails_explicitly_without_runner(): void
    {
        $skill = $this->makeSkill('research', 'Inspect repository', ['context' => 'fork']);
        $loader = $this->makeLoader(['research' => $skill]);

        $result = (new SkillTool($loader))->call(['skill' => 'research'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('no fork runner', $result->output);
    }
}
