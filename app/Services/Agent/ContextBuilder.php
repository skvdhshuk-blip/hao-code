<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Buddy\BuddyManager;
use HaoCode\Services\Git\GitContext;
use HaoCode\Services\Memory\SessionMemory;
use HaoCode\Services\OutputStyle\OutputStyleLoader;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolRegistry;

class ContextBuilder
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ToolRegistry $toolRegistry,
        private readonly SessionMemory $sessionMemory,
        private readonly SkillLoader $skillLoader,
        private readonly GitContext $gitContext,
        private readonly ?OutputStyleLoader $outputStyleLoader = null,
    ) {}

    public function buildSystemPrompt(): array
    {
        $prompt = $this->getDefaultSystemPrompt();

        $appendPrompt = $this->settings->getAppendSystemPrompt();
        if ($appendPrompt) {
            $prompt .= "\n\n" . $appendPrompt;
        }

        $prompt .= $this->getEnvironmentContext();

        // Load memory files (HAOCODE.md / CLAUDE.md)
        $memoryContent = $this->loadMemoryFiles();
        if ($memoryContent) {
            $prompt .= "\n\n# Project Instructions (from memory files)\n\n" . $memoryContent;
        }

        // Load persistent session memory at the configured summary level.
        // When a custom storage path is configured, use an isolated SessionMemory
        // instance so SDK consumers get their own memory namespace.
        $memoryLevel = $this->settings->getMemorySummaryLevel();
        $memoryPath = $this->settings->getMemoryStoragePath();
        $memorySource = $memoryPath !== null
            ? new \HaoCode\Services\Memory\SessionMemory($memoryPath)
            : $this->sessionMemory;
        $memories = $memorySource->forSystemPrompt(level: $memoryLevel);
        if ($memories) {
            $prompt .= "\n\n# Session Memory\n\n" . $memories;
        }

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

        // Inject companion intro if hatched
        $buddy = app(BuddyManager::class);
        $companionIntro = $buddy->getCompanionIntroText();
        if ($companionIntro) {
            $prompt .= "\n\n# Companion\n\n" . $companionIntro;
        }

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
                $prompt .= "\n\n# Output Style Instructions\n\n" . $styleContent;
            }
        }

        return [['type' => 'text', 'text' => $prompt, 'cache_control' => ['type' => 'ephemeral']]];
    }

    private function getDefaultSystemPrompt(): string
    {
        $override = $this->settings->getSystemPrompt();
        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        $path = resource_path('prompts/system.md');
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
        $cwd = getcwd();
        $date = date('Y-m-d');
        $shell = getenv('SHELL') ?: 'unknown';

        $context = "\n\n# Environment\n";
        $context .= "- Current date: {$date}\n";
        $context .= "- Working directory: {$cwd}\n";
        $context .= "- Shell: {$shell}\n";
        $context .= "- PHP: " . PHP_VERSION . "\n";
        $context .= "- OS: " . PHP_OS_FAMILY . ' ' . php_uname('r') . "\n";

        exec('git rev-parse --is-inside-work-tree 2>/dev/null', $gitCheck, $gitExit);
        $isGitRepo = $gitExit === 0;
        $context .= '- Is git repo: ' . ($isGitRepo ? 'true' : 'false') . "\n";

        if ($isGitRepo) {
            $gitBranch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
            if ($gitBranch !== '') {
                $context .= "- Git branch: {$gitBranch}\n";
            }
            $mainBranch = trim((string) shell_exec('git symbolic-ref refs/remotes/origin/HEAD 2>/dev/null'));
            if ($mainBranch !== '') {
                $mainBranch = str_replace('refs/remotes/origin/', '', $mainBranch);
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
        $cwd = getcwd();
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        // Global user instructions
        $globalPaths = [
            "{$home}/.haocode/HAOCODE.md",
            "{$home}/.haocode/CLAUDE.md",
        ];

        foreach ($globalPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content .= "## Global Instructions ({$path})\n";
                $content .= file_get_contents($path) . "\n\n";
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
                    $content .= file_get_contents($path) . "\n\n";
                }
            }

            // Load rule files from .haocode/rules/*.md and .claude/rules/*.md
            foreach (["{$realDir}/.haocode/rules", "{$realDir}/.claude/rules"] as $rulesDir) {
                if (is_dir($rulesDir)) {
                    foreach (glob("{$rulesDir}/*.md") as $ruleFile) {
                        $content .= "## Rule: " . basename($ruleFile) . " ({$rulesDir})\n";
                        $content .= file_get_contents($ruleFile) . "\n\n";
                    }
                }
            }

            $dir = dirname($dir);
        }

        return trim($content);
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
- Missing/blocked: if a named skill isn't in the list, say so briefly and continue with the best fallback.
- Progressive disclosure:
  1) After deciding to use a skill, invoke it via the Skill tool and read its SKILL.md body completely before taking task actions.
  2) When SKILL.md references relative paths (e.g. `scripts/foo.sh`, `references/api.md`), resolve them under the skill directory (`${HAOCODE_SKILL_DIR}`).
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
