<?php

namespace HaoCode\Tools\Skill;

/**
 * Loads skill definitions from markdown files with YAML frontmatter.
 *
 * Skill discovery paths:
 * 1. ~/.haocode/skills/<name>/SKILL.md
 * 2. .haocode/skills/<name>/SKILL.md
 * 3. .haocode/commands/<name>.md (legacy)
 */
class SkillLoader
{
    /** @var array<string, SkillDefinition> */
    private ?array $skills = null;

    /**
     * Load and return all available skills.
     * @return array<string, SkillDefinition>
     */
    public function loadSkills(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $this->skills = [];

        // Load from multiple sources
        $dirs = $this->getSkillDirectories();

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $this->loadFromDirectory($dir);
        }

        return $this->skills;
    }

    /**
     * Find a skill by name.
     */
    public function findSkill(string $name): ?SkillDefinition
    {
        $name = ltrim($name, '/');
        $skills = $this->loadSkills();
        return $skills[$name] ?? null;
    }

    /**
     * Per-entry hard cap on the rendered "description (— when_to_use)" string.
     * Mirrors claude-code's MAX_LISTING_DESC_CHARS=250 — verbose whenToUse text
     * in a discovery listing wastes tokens without improving match rate, since
     * the full SKILL.md is loaded on invoke anyway.
     */
    private const SKILL_LISTING_DESC_CAP = 250;

    /**
     * Default char budget for the skill listing in the system prompt.
     * Roughly 1% of a 200K-token window at ~4 chars/token, matching claude-code's
     * SKILL_BUDGET_CONTEXT_PERCENT. Caller can override with a smaller value
     * when running on a tighter model.
     */
    private const DEFAULT_LISTING_CHAR_BUDGET = 8000;

    /**
     * Get skill descriptions for system prompt injection.
     *
     * Renders one line per skill ("- /name: description"). If the full listing
     * would exceed the budget, trailing skills are dropped and a warning line
     * is appended so the model knows additional skills exist but weren't shown
     * — silently truncating gives the model a false "this is everything" signal
     * and lets it miss matches it would otherwise make.
     */
    public function getSkillDescriptions(int $maxChars = self::DEFAULT_LISTING_CHAR_BUDGET): string
    {
        $skills = $this->loadSkills();
        if (empty($skills)) {
            return '';
        }

        $header = "Available skills (slash commands):\n";
        $lines = [];
        $used = mb_strlen($header);
        $total = count($skills);
        $omitted = 0;

        foreach ($skills as $name => $skill) {
            $line = $this->formatSkillListingLine($name, $skill);
            // +1 for the newline that joins this line into the body.
            $cost = mb_strlen($line) + 1;
            if ($used + $cost > $maxChars && ! empty($lines)) {
                $omitted = $total - count($lines);
                break;
            }
            $lines[] = $line;
            $used += $cost;
        }

        $body = $header . implode("\n", $lines);

        if ($omitted > 0) {
            $noun = $omitted === 1 ? 'skill was' : 'skills were';
            $body .= "\n\n"
                . "Warning: skills listing budget exceeded. {$omitted} additional {$noun} "
                . "not shown above. Ask the user for the exact skill name, or invoke "
                . "the Skill tool with action=\"list\" to discover the full catalog.";
        }

        return $body;
    }

    /**
     * Render a single "- /name: description (— when_to_use)" line, capped at
     * SKILL_LISTING_DESC_CAP characters so verbose entries can't dominate the
     * listing budget.
     */
    private function formatSkillListingLine(string $name, SkillDefinition $skill): string
    {
        $desc = $skill->description;
        if ($skill->whenToUse !== null && $skill->whenToUse !== '') {
            $desc .= ' — ' . $skill->whenToUse;
        }
        if (mb_strlen($desc) > self::SKILL_LISTING_DESC_CAP) {
            $desc = mb_substr($desc, 0, self::SKILL_LISTING_DESC_CAP - 1) . '…';
        }
        return "- /{$name}: {$desc}";
    }

    /**
     * List all skills as arrays for display.
     * @return array<int, array{name: string, description: string, user_invocable: bool}>
     */
    public function listSkills(): array
    {
        $skills = $this->loadSkills();
        $list = [];
        foreach ($skills as $name => $skill) {
            $list[] = [
                'name' => $name,
                'description' => $skill->description,
                'user_invocable' => $skill->userInvocable,
            ];
        }
        return $list;
    }

    /**
     * Register a skill programmatically (e.g., from SDK config).
     * Overwrites any existing skill with the same name.
     */
    public function registerSkillDefinition(SkillDefinition $skill): void
    {
        // Ensure skills cache is initialized
        $this->loadSkills();
        $this->skills[$skill->name] = $skill;
    }

    private function getSkillDirectories(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $cwd = getcwd();

        return [
            "{$home}/.haocode/skills",
            $cwd . '/.haocode/skills',
            $cwd . '/.haocode/commands', // legacy
        ];
    }

    private function loadFromDirectory(string $dir): void
    {
        // New format: <name>/SKILL.md
        foreach (glob($dir . '/*/SKILL.md') as $file) {
            $name = basename(dirname($file));
            $this->registerSkill($name, $file);
        }

        // Legacy format: <name>.md
        foreach (glob($dir . '/*.md') as $file) {
            $name = basename($file, '.md');
            if (!isset($this->skills[$name])) {
                $this->registerSkill($name, $file);
            }
        }
    }

    private function registerSkill(string $name, string $file): void
    {
        $content = file_get_contents($file);
        $def = $this->parseSkillFile($name, $content, dirname($file), $file);
        $this->skills[$name] = $def;
    }

    private function parseSkillFile(string $name, string $content, string $dir, ?string $sourcePath = null): SkillDefinition
    {
        $frontmatter = [];
        $body = $content;

        // Parse YAML frontmatter
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $m)) {
            $frontmatter = $this->parseYaml($m[1]);
            $body = $m[2];
        }

        // When loaded from a file, hand the path to SkillDefinition and let it
        // re-read the body lazily — avoids holding every skill's full markdown
        // in memory just so the model can see the description.
        $promptInline = $sourcePath !== null ? null : trim($body);

        return new SkillDefinition(
            name: $name,
            description: $frontmatter['description'] ?? $this->firstLine($body),
            whenToUse: $frontmatter['when_to_use'] ?? null,
            prompt: $promptInline,
            allowedTools: $this->parseList($frontmatter['allowed-tools'] ?? ''),
            model: $frontmatter['model'] ?? null,
            context: $frontmatter['context'] ?? 'inline',
            userInvocable: ($frontmatter['user-invocable'] ?? 'true') !== 'false',
            argumentHint: $frontmatter['argument-hint'] ?? null,
            skillDir: $dir,
            promptPath: $sourcePath,
        );
    }

    private function parseYaml(string $yaml): array
    {
        $result = [];
        foreach (explode("\n", $yaml) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2], '"\' ');
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function parseList(string $value): array
    {
        if (empty($value)) return [];
        return array_map('trim', explode(',', $value));
    }

    private function firstLine(string $text): string
    {
        $line = trim(explode("\n", trim($text))[0] ?? '');
        return mb_substr($line, 0, 100);
    }
}
