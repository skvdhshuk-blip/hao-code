<?php

namespace HaoCode\Tools\Skill;

class SkillDefinition
{
    /**
     * Lazily-loaded prompt body. When constructed from a file, this stays null
     * until getPrompt() is first called, so disk reads (and the memory cost of
     * holding every SKILL.md body) are deferred until a skill is actually invoked.
     */
    private ?string $promptCache;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $whenToUse,
        ?string $prompt,
        public readonly array $allowedTools = [],
        public readonly ?string $model = null,
        public readonly string $context = 'inline',  // 'inline' or 'fork'
        public readonly bool $userInvocable = true,
        public readonly ?string $argumentHint = null,
        public readonly string $skillDir = '',
        public readonly ?string $promptPath = null,
    ) {
        $this->promptCache = $prompt;
    }

    /**
     * Returns the skill prompt body. Reads from `promptPath` on first call when
     * the body wasn't supplied inline, then caches the result.
     */
    public function getPrompt(): string
    {
        if ($this->promptCache !== null) {
            return $this->promptCache;
        }

        if ($this->promptPath !== null && is_readable($this->promptPath)) {
            $raw = file_get_contents($this->promptPath);
            if ($raw !== false) {
                $this->promptCache = self::stripFrontmatter($raw);
                return $this->promptCache;
            }
        }

        $this->promptCache = '';
        return '';
    }

    /**
     * Back-compat for callers that still read `$skill->prompt` directly.
     * Routes the property access through getPrompt() so lazy-load behaviour
     * is preserved.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'prompt') {
            return $this->getPrompt();
        }
        throw new \Error('Undefined property: '.self::class.'::$'.$name);
    }

    public function __isset(string $name): bool
    {
        return $name === 'prompt';
    }

    /**
     * Strip a leading YAML frontmatter block and return the trimmed body.
     */
    public static function stripFrontmatter(string $content): string
    {
        if (preg_match('/^---\s*\n.*?\n---\s*\n(.*)/s', $content, $m)) {
            return trim($m[1]);
        }
        return trim($content);
    }
}
