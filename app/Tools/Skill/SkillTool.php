<?php

namespace HaoCode\Tools\Skill;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class SkillTool extends BaseTool
{
    /** @var (\Closure(string, SkillDefinition, ToolUseContext): string)|null */
    private readonly ?\Closure $forkRunner;

    public function __construct(
        private readonly ?SkillLoader $skillLoader = null,
        mixed $forkRunner = null,
    ) {
        $this->forkRunner = $forkRunner instanceof \Closure ? $forkRunner : null;
    }

    public function name(): string
    {
        return 'Skill';
    }

    public function description(): string
    {
        return <<<DESC
Execute a skill (slash command). Skills are predefined prompts that can be invoked by name.

Usage: Call with the skill name (with or without leading /) and optional arguments.
When executed, the skill's prompt is expanded and injected into the conversation.

To list available skills, call with no arguments or use list action.
For large catalogs, use action="search" with query, or paginate list with page/per_page.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'skill' => [
                    'type' => 'string',
                    'description' => 'The skill name to invoke (e.g., "commit" or "/commit")',
                ],
                'args' => [
                    'type' => 'string',
                    'description' => 'Optional arguments to pass to the skill',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['run', 'list', 'search'],
                    'description' => 'Action: "run" to execute, "list" to paginate, "search" to find matching skills',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Name or description text used by the search action',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => '1-based result page for list/search',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'description' => 'Results per page (1-50, default 25)',
                ],
            ],
            'required' => [],
        ], [
            'skill' => 'nullable|string',
            'args' => 'nullable|string',
            'action' => 'nullable|string|in:run,list,search',
            'query' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        /** @var SkillLoader $loader */
        $loader = $this->skillLoader ?? \HaoCode\Support\Runtime\SdkRuntime::app(SkillLoader::class);
        $skillName = ltrim((string) ($input['skill'] ?? ''), '/');
        $args = $input['args'] ?? '';
        $action = $input['action'] ?? ($skillName === '' ? 'list' : 'run');

        if ($action === 'list' || $skillName === 'list') {
            return $this->listSkills(
                $loader,
                page: (int) ($input['page'] ?? 1),
                perPage: (int) ($input['per_page'] ?? 25),
            );
        }

        if ($action === 'search') {
            return $this->listSkills(
                $loader,
                page: (int) ($input['page'] ?? 1),
                perPage: (int) ($input['per_page'] ?? 25),
                query: trim((string) ($input['query'] ?? $skillName)),
            );
        }

        $skill = $loader->findSkill($skillName);
        if ($skill === null) {
            return $this->listSkills($loader, "Unknown skill: /{$skillName}");
        }

        // Expand the skill prompt with argument substitution
        $prompt = $this->expandPrompt($skill, $args, $context);
        if ($skill->skillDir !== '') {
            $directory = htmlspecialchars($skill->skillDir, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $name = htmlspecialchars($skillName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $prompt = <<<PROMPT
<skill_context name="{$name}" directory="{$directory}">
Resolve every relative reference, script, asset, and template path against this directory.
</skill_context>

{$prompt}
PROMPT;
        }

        if ($skill->context === 'fork') {
            if ($this->forkRunner === null) {
                return ToolResult::error("Skill /{$skillName} requires fork context, but no fork runner is available.");
            }

            try {
                $prompt = ($this->forkRunner)($prompt, $skill, $context);
            } catch (\Throwable $e) {
                return ToolResult::error("Forked skill /{$skillName} failed: {$e->getMessage()}");
            }
        }

        return ToolResult::success($prompt, [
            'skill' => $skillName,
            'allowed_tools' => $skill->allowedTools,
            'model_override' => $skill->model,
            'context' => $skill->context,
            'skill_dir' => $skill->skillDir !== '' ? $skill->skillDir : null,
        ]);
    }

    private function listSkills(
        SkillLoader $loader,
        string $prefix = '',
        int $page = 1,
        int $perPage = 25,
        string $query = '',
    ): ToolResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $skills = $loader->loadSkills();
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $skills = array_filter($skills, static function (SkillDefinition $skill, string $name) use ($needle): bool {
                return str_contains(mb_strtolower($name), $needle)
                    || str_contains(mb_strtolower($skill->description), $needle);
            }, ARRAY_FILTER_USE_BOTH);
        }
        if (empty($skills)) {
            $msg = $prefix ? $prefix . "\n\n" : '';
            $msg .= $query === ''
                ? 'No skills available. Add skills to ~/.haocode/skills/ or .haocode/skills/'
                : "No skills matched query: {$query}";
            return ToolResult::success($msg);
        }

        $total = count($skills);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $skills = array_slice($skills, ($page - 1) * $perPage, $perPage, true);
        $lines = [];
        if ($prefix) $lines[] = $prefix;
        $label = $query === '' ? 'Available skills' : "Skills matching \"{$query}\"";
        $lines[] = "{$label} (page {$page}/{$pages}, {$total} total):";
        foreach ($skills as $name => $skill) {
            $hint = $skill->argumentHint ? " <{$skill->argumentHint}>" : '';
            $lines[] = "  /{$name}{$hint} — {$skill->description}";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function expandPrompt(SkillDefinition $skill, string $args, ToolUseContext $context): string
    {
        $prompt = $skill->getPrompt();

        // Substitute $ARGUMENTS
        $prompt = str_replace('$ARGUMENTS', $args, $prompt);

        // Substitute session variables
        $prompt = str_replace('${CLAUDE_SESSION_ID}', $context->sessionId, $prompt);
        $prompt = str_replace('${HAOCODE_SESSION_ID}', $context->sessionId, $prompt);

        // Substitute skill directory
        $prompt = str_replace('${CLAUDE_SKILL_DIR}', $skill->skillDir, $prompt);
        $prompt = str_replace('${HAOCODE_SKILL_DIR}', $skill->skillDir, $prompt);

        // Render only standalone inline shell directives. Requiring the whole
        // line prevents ordinary Markdown such as `panic!` ... `unwrap()` from
        // being interpreted as a command spanning two unrelated code spans.
        $prompt = preg_replace_callback('/^[ \t]*!`([^`\r\n]+)`[ \t]*$/m', function (array $match): string {
            return $this->renderShellDirective($match[1]);
        }, $prompt);

        // Render shell blocks only when both fences are standalone lines.
        $prompt = preg_replace_callback('/^```![ \t]*\R(.*?)^```[ \t]*$/ms', function (array $match): string {
            return $this->renderShellDirective($match[1]);
        }, $prompt);

        return $prompt;
    }

    private function renderShellDirective(string $command): string
    {
        $command = trim($command);

        return <<<DIRECTIVE
<skill_shell_directive>
Execute the following command through the Bash tool in the active working directory, then use its output to continue the skill:
{$command}
</skill_shell_directive>
DIRECTIVE;
    }

    public function isReadOnly(array $input): bool
    {
        $skillName = ltrim((string) ($input['skill'] ?? ''), '/');
        $skill = $skillName !== '' ? $this->skillLoader?->findSkill($skillName) : null;

        return $skill !== null && $skill->context !== 'fork';
    }

    public function isConcurrencySafe(array $input): bool
    {
        return false;
    }
}
