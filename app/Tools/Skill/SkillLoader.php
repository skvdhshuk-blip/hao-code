<?php

namespace HaoCode\Tools\Skill;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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

    /** @var array<string, SkillDefinition> */
    private array $registeredDefinitions = [];

    public function __construct(
        private readonly ?string $workingDirectory = null,
        /** @var string[] */
        private readonly array $additionalDirectories = [],
        private readonly bool $recursive = false,
    ) {}

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

        foreach ($this->getSkillDirectories() as $dir) {
            if (!is_dir($dir)) continue;
            $this->loadFromDirectory($dir, false);
        }

        foreach ($this->getLegacyCommandDirectories() as $dir) {
            if (! is_dir($dir)) continue;
            $this->loadFromDirectory($dir, true);
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
        $nameIndex = 'Exact name index: '.implode(', ', array_map(
            static fn (string $name): string => '/'.$name,
            array_keys($skills),
        ))."\n\nDescriptions:\n";
        $maxIndexChars = max(0, min(3000, intdiv($maxChars, 2)));
        if (mb_strlen($nameIndex) > $maxIndexChars) {
            $nameIndex = mb_substr($nameIndex, 0, max(0, $maxIndexChars - 25))."… (index truncated)\n\n";
        }
        $lines = [];
        $used = mb_strlen($header.$nameIndex);
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

        $body = $header.$nameIndex.implode("\n", $lines);

        if ($omitted > 0) {
            $noun = $omitted === 1 ? 'skill was' : 'skills were';
            $body .= "\n\n"
                . "Warning: skills listing budget exceeded. {$omitted} additional {$noun} "
                . "not shown above. Exact names remain callable. Use the Skill tool with "
                . "action=\"search\" and query=<intent> to inspect matching descriptions.";
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
        $this->registeredDefinitions[$skill->name] = $skill;
        // Ensure skills cache is initialized
        $this->loadSkills();
        $this->skills[$skill->name] = $skill;
    }

    /**
     * Rebase project-local discovery while preserving only programmatically
     * registered SDK skills and explicit additional directories.
     *
     * @internal
     */
    public function forWorkingDirectory(string $workingDirectory): self
    {
        $loader = new self($workingDirectory, $this->additionalDirectories, $this->recursive);
        foreach ($this->registeredDefinitions as $definition) {
            $loader->registerSkillDefinition($definition);
        }

        return $loader;
    }

    private function getSkillDirectories(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $cwd = $this->workingDirectory ?? getcwd();

        return [
            "{$home}/.haocode/skills",
            $cwd . '/.haocode/skills',
            ...$this->additionalDirectories,
        ];
    }

    private function getLegacyCommandDirectories(): array
    {
        $cwd = $this->workingDirectory ?? getcwd();

        return [$cwd . '/.haocode/commands'];
    }

    private function loadFromDirectory(string $dir, bool $loadLegacyMarkdown = true): void
    {
        $files = $this->recursive
            ? $this->discoverRecursively($dir)
            : (glob(rtrim($dir, '/').'/*/SKILL.md') ?: []);
        usort($files, static function (string $left, string $right) use ($dir): int {
            $leftDepth = substr_count(ltrim(substr($left, strlen(rtrim($dir, '/'))), '/'), '/');
            $rightDepth = substr_count(ltrim(substr($right, strlen(rtrim($dir, '/'))), '/'), '/');

            return $leftDepth <=> $rightDepth ?: strcmp($left, $right);
        });

        $seenInDirectory = [];
        foreach ($files as $file) {
            $name = basename(dirname($file));
            if (isset($seenInDirectory[$name])) {
                continue;
            }
            $seenInDirectory[$name] = true;
            $this->registerSkill($name, $file);
        }

        if (! $loadLegacyMarkdown) {
            return;
        }

        foreach (glob(rtrim($dir, '/').'/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            if (!isset($this->skills[$name])) {
                $this->registerSkill($name, $file);
            }
        }
    }

    /** @return string[] */
    private function discoverRecursively(string $root): array
    {
        $queue = [rtrim($root, '/')];
        $visited = [];
        $files = [];

        while ($queue !== []) {
            $directory = array_shift($queue);
            $real = realpath($directory) ?: $directory;
            if (isset($visited[$real]) || ! is_dir($directory)) {
                continue;
            }
            $visited[$real] = true;

            $skillFile = $directory.'/SKILL.md';
            if (is_file($skillFile)) {
                $files[] = $skillFile;
            }

            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $item) {
                if ($item->isDir()) {
                    $queue[] = $item->getPathname();
                }
            }
        }

        return array_values(array_unique($files));
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
            description: $this->stringValue($frontmatter['description'] ?? null) ?? $this->firstLine($body),
            whenToUse: $this->stringValue($frontmatter['when_to_use'] ?? $frontmatter['when-to-use'] ?? null),
            prompt: $promptInline,
            allowedTools: $this->parseList($frontmatter['allowed-tools'] ?? []),
            model: $this->stringValue($frontmatter['model'] ?? null),
            context: in_array($frontmatter['context'] ?? 'inline', ['inline', 'fork'], true)
                ? $frontmatter['context'] ?? 'inline'
                : 'inline',
            userInvocable: $this->boolValue($frontmatter['user-invocable'] ?? true),
            argumentHint: $this->stringValue($frontmatter['argument-hint'] ?? null),
            skillDir: $dir,
            promptPath: $sourcePath,
        );
    }

    private function parseYaml(string $yaml): array
    {
        try {
            $parsed = Yaml::parse($yaml);

            return is_array($parsed) ? $parsed : [];
        } catch (ParseException) {
            return $this->parseLegacyYaml($yaml);
        }
    }

    /**
     * Tolerant fallback for existing Claude skills with slightly invalid YAML.
     * Supports top-level scalars, block strings, and simple dash lists.
     */
    private function parseLegacyYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", $yaml);
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $key = $match[1];
            $value = trim($match[2]);
            if ($value === '|' || $value === '>') {
                $parts = [];
                while ($index + 1 < $count && preg_match('/^[ \t]+/', $lines[$index + 1]) === 1) {
                    $parts[] = trim($lines[++$index]);
                }
                $result[$key] = trim(implode($value === '|' ? "\n" : ' ', $parts));
                continue;
            }

            if ($value === '') {
                $items = [];
                while ($index + 1 < $count && preg_match('/^[ \t]+-\s*(.*)$/', $lines[$index + 1], $item) === 1) {
                    $items[] = trim($item[1], " \t\n\r\0\x0B\"'");
                    $index++;
                }
                $result[$key] = $items;
                continue;
            }

            $result[$key] = trim($value, "\"'");
        }

        return $result;
    }

    private function parseList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            )));
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = str_contains($value, ',')
            ? explode(',', $value)
            : preg_split('/\s+/', trim($value));

        return array_values(array_filter(array_map('trim', $parts ?: [])));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array(strtolower(trim((string) $value)), ['false', '0', 'no', 'off'], true);
    }

    private function firstLine(string $text): string
    {
        $line = trim(explode("\n", trim($text))[0] ?? '');
        return mb_substr($line, 0, 100);
    }
}
