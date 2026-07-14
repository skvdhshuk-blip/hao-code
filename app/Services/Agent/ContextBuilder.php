<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Git\GitContext;
use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;

class ContextBuilder
{
    private const MAX_BASE_PROMPT_CHARS = 60_000;

    private const MAX_APPEND_PROMPT_CHARS = 20_000;

    private const MAX_INSTRUCTION_FILE_CHARS = 40_000;

    private const MAX_PROJECT_INSTRUCTIONS_CHARS = 100_000;

    private const MAX_LONG_TERM_MEMORY_CHARS = 3_000;

    private const MAX_OUTPUT_STYLE_CHARS = 20_000;

    private const MAX_SYSTEM_PROMPT_CHARS = 160_000;

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ToolRegistry $toolRegistry,
        private readonly MemoryStoreInterface $memoryStore,
        private readonly SkillLoader $skillLoader,
        private readonly GitContext $gitContext,
        private readonly ?OutputStyleLoader $outputStyleLoader = null,
        private readonly ?string $workingDirectory = null,
        private readonly bool $textOnly = false,
        private readonly bool $includeMemoryInTextOnly = false,
    ) {}

    public function buildSystemPrompt(): array
    {
        if ($this->textOnly) {
            return $this->buildTextOnlySystemPrompt();
        }

        $this->gitContext->beginSnapshot();

        try {
            return $this->buildAgentSystemPrompt();
        } finally {
            $this->gitContext->endSnapshot();
        }
    }

    private function buildAgentSystemPrompt(): array
    {
        $prompt = $this->truncateFragment($this->getDefaultSystemPrompt(), self::MAX_BASE_PROMPT_CHARS);

        $appendPrompt = $this->settings->getAppendSystemPrompt();
        if ($appendPrompt) {
            $prompt .= "\n\n" . $this->truncateFragment($appendPrompt, self::MAX_APPEND_PROMPT_CHARS);
        }

        $prompt .= $this->getEnvironmentContext();

        // Load memory files (HAOCODE.md / CLAUDE.md)
        $memoryContent = $this->loadMemoryFiles();
        if ($memoryContent) {
            $prompt .= "\n\n# Project Instructions (from memory files)\n\n" . $memoryContent;
        }

        $prompt = $this->appendLongTermMemory($prompt);

        // Load available skills + the progressive-disclosure protocol that
        // tells the model how to consume them. The listing alone is not enough:
        // without this protocol the model will either ignore matching skills
        // or bulk-load every reference/script in a skill directory.
        $skillDescs = $this->skillLoader->getSkillDescriptions();
        if ($skillDescs) {
            $prompt .= "\n\n# Skills\n\n" . $skillDescs
                . "\n\n" . $this->getSkillsHowToUse();
        }

        $prompt .= $this->getHaoCodeConventions();

        // Append git context (current diff, branch info)
        $gitContext = $this->gitContext->getDiffContext();
        if ($gitContext) {
            $prompt .= $gitContext;
        }

        // Inject active output style instructions
        $activeStyle = $this->settings->getOutputStyle();
        if ($activeStyle && $this->outputStyleLoader) {
            $styleContent = $this->outputStyleLoader->getActiveStyleContent($activeStyle);
            if ($styleContent) {
                $prompt .= "\n\n# Output Style Instructions\n\n"
                    . $this->truncateFragment($styleContent, self::MAX_OUTPUT_STYLE_CHARS);
            }
        }

        $prompt = $this->truncateFragment($prompt, self::MAX_SYSTEM_PROMPT_CHARS);

        return [['type' => 'text', 'text' => $prompt, 'cache_control' => ['type' => 'ephemeral']]];
    }

    /**
     * 为未开放任何工具的基础调用构造精简系统提示。
     *
     * 该提示保留用户显式传入的 systemPrompt 和 appendSystemPrompt，
     * 但不注入工具、Skill、Git、项目文件或持久记忆等 coding-agent 上下文。
     */
    private function buildTextOnlySystemPrompt(): array
    {
        // 基础调用使用的最小默认提示，明确模型没有工具可调用。
        $prompt = $this->settings->getSystemPrompt();
        if (! is_string($prompt) || trim($prompt) === '') {
            $prompt = <<<'PROMPT'
You are Hao Code, an embedded PHP AI assistant. Answer the user's request directly and concisely.

You have no tools in this request. Do not claim to have read files, run commands, searched the web, or changed external state.
PROMPT;
        }

        $prompt = $this->truncateFragment($prompt, self::MAX_BASE_PROMPT_CHARS);
        $appendPrompt = $this->settings->getAppendSystemPrompt();
        if (is_string($appendPrompt) && trim($appendPrompt) !== '') {
            $prompt .= "\n\n".$this->truncateFragment($appendPrompt, self::MAX_APPEND_PROMPT_CHARS);
        }

        if ($this->includeMemoryInTextOnly) {
            $prompt = $this->appendLongTermMemory($prompt);
        }

        return [[
            'type' => 'text',
            'text' => $this->truncateFragment($prompt, self::MAX_SYSTEM_PROMPT_CHARS),
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    }

    private function appendLongTermMemory(string $prompt): string
    {
        $level = $this->settings->getMemorySummaryLevel();
        if (! in_array($level, ['l0', 'l1', 'l2'], true)) {
            $level = 'l0';
        }
        $entries = $this->memoryStore->all($level);

        if ($entries !== []) {
            $header = 'Reference data learned in previous sessions. It may be stale or incorrect; prefer the current user request and verified evidence.';
            $lines = [$header];
            $length = strlen($header);

            foreach ($entries as $key => $content) {
                $line = "- {$key}: {$content}";
                if ($length + strlen($line) > self::MAX_LONG_TERM_MEMORY_CHARS) {
                    break;
                }
                $lines[] = $line;
                $length += strlen($line);
            }

            $prompt .= "\n\n# Long-Term Memory\n\n".implode("\n", $lines);
        }

        if ($this->toolRegistry->has('MemoryWrite') || $this->toolRegistry->has('MemoryDelete')) {
            $prompt .= <<<'PROMPT'


# Long-Term Memory Update Policy

- Use MemoryWrite only when the user explicitly asks to remember or update durable information.
- Use MemoryDelete only when the user explicitly asks to forget or remove stored information.
- Never store credentials, access tokens, passwords, or other secrets.
PROMPT;
        }

        return $prompt;
    }

    private function getDefaultSystemPrompt(): string
    {
        $override = $this->settings->getSystemPrompt();
        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        $path = \HaoCode\Support\Runtime\SdkRuntime::resourcePath('prompts/system.md');
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return $this->getFallbackSystemPrompt();
    }

    private function getFallbackSystemPrompt(): string
    {
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

    private function getEnvironmentContext(): string
    {
        $cwd = $this->workingDirectory ?? getcwd();
        $date = date('Y-m-d');
        $shell = getenv('SHELL') ?: 'unknown';

        $context = "\n\n# Environment\n";
        $context .= "- Current date: {$date}\n";
        $context .= "- Working directory: {$cwd}\n";
        $context .= "- Shell: {$shell}\n";
        $context .= "- PHP: " . PHP_VERSION . "\n";
        $context .= "- OS: " . PHP_OS_FAMILY . ' ' . php_uname('r') . "\n";

        $isGitRepo = $this->gitContext->isGitRepo();
        $context .= '- Is git repo: ' . ($isGitRepo ? 'true' : 'false') . "\n";

        if ($isGitRepo) {
            $gitBranch = $this->gitContext->getCurrentBranch();
            if ($gitBranch !== '') {
                $context .= "- Git branch: {$gitBranch}\n";
            }
            $mainBranch = $this->gitContext->getDefaultBranch();
            if ($mainBranch !== '') {
                $context .= "- Main branch: {$mainBranch}\n";
            }
        }

        return $context;
    }

    /**
     * Load memory/instruction files from the project hierarchy.
     * Checks for: HAOCODE.md, CLAUDE.md, .haocode/instructions.md
     */
    private function loadMemoryFiles(): string
    {
        $content = '';
        $cwd = $this->workingDirectory ?? getcwd();
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        // Global user instructions
        $globalPaths = [
            "{$home}/.haocode/HAOCODE.md",
            "{$home}/.haocode/CLAUDE.md",
        ];

        foreach ($globalPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content .= "## Global Instructions ({$path})\n";
                $content .= $this->readInstructionFile($path) . "\n\n";
            }
        }

        // Walk parent directories from cwd to root for CLAUDE.md / HAOCODE.md
        $visited = [];
        $dir = $cwd;
        while ($dir !== '' && $dir !== '/' && $dir !== $home) {
            $realDir = realpath($dir);
            if ($realDir === false || isset($visited[$realDir])) {
                break;
            }
            $visited[$realDir] = true;

            $label = $realDir === realpath($cwd) ? 'Project' : 'Parent';
            $candidates = [
                "{$realDir}/AGENTS.md",
                "{$realDir}/HAOCODE.md",
                "{$realDir}/CLAUDE.md",
                "{$realDir}/.haocode/instructions.md",
                "{$realDir}/.haocode/HAOCODE.md",
                "{$realDir}/.haocode/CLAUDE.md",
                "{$realDir}/.claude/CLAUDE.md",
            ];

            foreach ($candidates as $path) {
                if (file_exists($path) && is_readable($path)) {
                    $content .= "## {$label} Instructions ({$path})\n";
                    $content .= $this->readInstructionFile($path) . "\n\n";
                }
            }

            // Load rule files from .haocode/rules/*.md and .claude/rules/*.md
            foreach (["{$realDir}/.haocode/rules", "{$realDir}/.claude/rules"] as $rulesDir) {
                if (is_dir($rulesDir)) {
                    foreach (glob("{$rulesDir}/*.md") as $ruleFile) {
                        $content .= "## Rule: " . basename($ruleFile) . " ({$rulesDir})\n";
                        $content .= $this->readInstructionFile($ruleFile) . "\n\n";
                    }
                }
            }

            $dir = dirname($dir);
        }

        return trim($this->truncateFragment($content, self::MAX_PROJECT_INSTRUCTIONS_CHARS));
    }

    /**
     * 读取单个项目指令文件，并限制其进入模型上下文的最大长度。
     */
    private function readInstructionFile(string $path): string
    {
        $content = file_get_contents($path, false, null, 0, self::MAX_INSTRUCTION_FILE_CHARS + 1);

        return $this->truncateFragment(is_string($content) ? $content : '', self::MAX_INSTRUCTION_FILE_CHARS);
    }

    /**
     * 截断单个上下文片段并附加可观察的省略标记。
     */
    private function truncateFragment(string $content, int $maxChars): string
    {
        if (mb_strlen($content) <= $maxChars) {
            return $content;
        }

        return mb_substr($content, 0, $maxChars)."\n[... context truncated by Hao Code budget ...]";
    }

    /**
     * Progressive-disclosure protocol for skills. Mirrors the structure of
     * codex's SKILLS_HOW_TO_USE so the model treats SKILL.md as a workflow
     * entry point rather than a self-contained answer — it should load
     * references/scripts/assets only on demand and announce which skill it
     * picked. The wording is deliberately short; long protocol text would
     * itself defeat the budget the listing tries to respect.
     */
    private function getSkillsHowToUse(): string
    {
        return <<<'TEXT'
## How to use skills

- Discovery: the list above is the skills available in this session (name + description). Skill bodies live on disk under `~/.haocode/skills/<name>/SKILL.md` or `<project>/.haocode/skills/<name>/SKILL.md`.
- Trigger: if the user names a skill (`/name` or plain text) OR the task clearly matches a skill's description, invoke it via the Skill tool for that turn. Multiple matches → use them all. Do not carry skills across turns unless re-mentioned.
- Exact names: when the user names a skill, invoke that exact name even if its description was omitted by the listing budget. Do not infer that an unshown name is missing.
- Discovery: for an implicit match that is not obvious from the displayed descriptions, call the Skill tool with action="search" and a short intent query before falling back.
- Missing/blocked: only report a named skill as missing after an exact Skill invocation or search confirms it is unavailable.
- Progressive disclosure:
  1) After deciding to use a skill, invoke it via the Skill tool and read its SKILL.md body completely before taking task actions.
  2) The Skill tool result includes a `<skill_context directory="...">` header. Resolve relative paths (e.g. `scripts/foo.sh`, `references/api.md`) against that exact directory; never guess `~/.haocode` or `.claude`.
  3) If SKILL.md points to `references/`, use its routing instructions to identify the specific files needed for this request — don't bulk-load.
  4) If `scripts/` exist, prefer running or patching them over retyping large code blocks.
  5) If `assets/` or templates exist, reuse them instead of recreating from scratch.
- Coordination: if multiple skills apply, pick the minimal set and state the order. Announce which skill you're using (one short line). If you skip an obvious skill, say why.
- Context hygiene: progressive disclosure applies to selecting relevant files, not partially reading a selected instruction file. Avoid deep reference-chasing — open only files directly linked from SKILL.md unless blocked.
- Safety: if a skill can't be applied cleanly (missing files, unclear instructions), state the issue, pick the next-best approach, and continue.
TEXT;
    }

    private function getHaoCodeConventions(): string
    {
        return <<<'TEXT'


# Hao Code Conventions

- Hao Code-owned files and generated artifacts must use `.haocode`, not `.claude`.
- Store skills under `~/.haocode/skills/` or `.haocode/skills/`.
- If imported compatibility instructions mention Claude Code paths like `.claude/...`, translate them to the Hao Code equivalent under `.haocode/...`.
- Do not create or modify `.claude/` files unless the user explicitly asks for Claude Code compatibility work.
TEXT;
    }
}
