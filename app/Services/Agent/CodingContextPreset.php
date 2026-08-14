<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\GitContext;
use HaoCode\Support\Runtime\SdkRuntime;

/**
 * Coding-only context layered onto the generic agent prompt assembly.
 *
 * @internal
 */
final class CodingContextPreset
{
    private const MAX_INSTRUCTION_FILE_CHARS = 40_000;

    private const MAX_PROJECT_INSTRUCTIONS_CHARS = 100_000;

    public function __construct(
        private readonly GitContext $gitContext,
        private readonly ?string $workingDirectory = null,
        private readonly bool $omitProjectInstructions = false,
    ) {}

    public function beginSnapshot(): void
    {
        $this->gitContext->beginSnapshot();
    }

    public function endSnapshot(): void
    {
        $this->gitContext->endSnapshot();
    }

    public function defaultSystemPrompt(): string
    {
        $path = SdkRuntime::resourcePath('prompts/system.md');
        if (file_exists($path)) {
            return file_get_contents($path);
        }

        return <<<'PROMPT'
You are Hao Code, an embedded PHP agent SDK powered by a large language model. You help users with software engineering tasks from inside the host application.

# System

- All text you output outside of tool use is displayed to the user.
- Tools are executed in a user-selected permission mode.

# Doing tasks

- The user will primarily request you to perform software engineering tasks.
- In general, do not propose changes to code you haven't read.
- Do not create files unless they're absolutely necessary.
- Be careful not to introduce security vulnerabilities.

# Tone and style

- Only use emojis if the user explicitly requests it.
- Your responses should be short and concise.
- Lead with the answer or action, not the reasoning.
PROMPT;
    }

    public function environmentContext(): string
    {
        $cwd = $this->workingDirectory ?? getcwd();
        $shell = getenv('SHELL') ?: 'unknown';

        $context = "\n\n# Environment\n";
        $context .= "- Working directory: {$cwd}\n";
        $context .= "- Shell: {$shell}\n";
        $context .= '- PHP: '.PHP_VERSION."\n";
        $context .= '- OS: '.PHP_OS_FAMILY.' '.php_uname('r')."\n";
        $context .= '- Is git repo: '.($this->gitContext->isGitRepo() ? 'true' : 'false')."\n";

        return $context;
    }

    public function projectInstructionsContext(): string
    {
        if ($this->omitProjectInstructions) {
            return '';
        }

        $content = $this->loadProjectInstructions();

        return $content === ''
            ? ''
            : "\n\n# Project Instructions (from memory files)\n\n{$content}";
    }

    public function conventionsContext(): string
    {
        return <<<'TEXT'


# Hao Code Conventions

- Hao Code-owned files and generated artifacts must use `.haocode`, not `.claude`.
- Store skills under `~/.haocode/skills/` or `.haocode/skills/`.
- If imported compatibility instructions mention Claude Code paths like `.claude/...`, translate them to the Hao Code equivalent under `.haocode/...`.
- Do not create or modify `.claude/` files unless the user explicitly asks for Claude Code compatibility work.
TEXT;
    }

    public function turnContext(): string
    {
        $context = '# Runtime'."\n- Current date: ".date('Y-m-d');
        $gitContext = trim($this->gitContext->getDiffContext());
        if ($gitContext !== '') {
            $context .= "\n\n{$gitContext}";
        }

        return $context;
    }

    private function loadProjectInstructions(): string
    {
        $content = '';
        $cwd = $this->workingDirectory ?? getcwd();
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        foreach (["{$home}/.haocode/HAOCODE.md", "{$home}/.haocode/CLAUDE.md"] as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content .= "## Global Instructions ({$path})\n";
                $content .= $this->readInstructionFile($path)."\n\n";
            }
        }

        $visited = [];
        $dir = $cwd;
        while ($dir !== '' && $dir !== '/' && $dir !== $home) {
            $realDir = realpath($dir);
            if ($realDir === false || isset($visited[$realDir])) {
                break;
            }
            $visited[$realDir] = true;

            $label = $realDir === realpath($cwd) ? 'Project' : 'Parent';
            foreach ([
                "{$realDir}/AGENTS.md",
                "{$realDir}/HAOCODE.md",
                "{$realDir}/CLAUDE.md",
                "{$realDir}/.haocode/instructions.md",
                "{$realDir}/.haocode/HAOCODE.md",
                "{$realDir}/.haocode/CLAUDE.md",
                "{$realDir}/.claude/CLAUDE.md",
            ] as $path) {
                if (file_exists($path) && is_readable($path)) {
                    $content .= "## {$label} Instructions ({$path})\n";
                    $content .= $this->readInstructionFile($path)."\n\n";
                }
            }

            foreach (["{$realDir}/.haocode/rules", "{$realDir}/.claude/rules"] as $rulesDir) {
                if (! is_dir($rulesDir)) {
                    continue;
                }
                foreach (glob("{$rulesDir}/*.md") ?: [] as $ruleFile) {
                    $content .= '## Rule: '.basename($ruleFile)." ({$rulesDir})\n";
                    $content .= $this->readInstructionFile($ruleFile)."\n\n";
                }
            }

            $dir = dirname($dir);
        }

        return trim(ContextBudget::truncateFragment($content, self::MAX_PROJECT_INSTRUCTIONS_CHARS));
    }

    private function readInstructionFile(string $path): string
    {
        // The prompt contract is measured in characters, while file reads are
        // measured in bytes. Four bytes cover one valid UTF-8 code point, so
        // this keeps multibyte instructions on the same character budget
        // without reading an unbounded file.
        $content = file_get_contents(
            $path,
            false,
            null,
            0,
            (self::MAX_INSTRUCTION_FILE_CHARS * 4) + 1,
        );

        return ContextBudget::truncateFragment(
            is_string($content) ? $content : '',
            self::MAX_INSTRUCTION_FILE_CHARS,
        );
    }
}
