<?php

namespace HaoCode\Skill\Registry;

use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\Skill\SkillDefinition;
use HaoCode\Tools\Skill\SkillLoader;

/**
 * Fetches skills from a remote git repository via proc_open (zero dependencies).
 *
 * Cache location: ~/.hao-code/skill-cache/<sha1(url)>/
 * Uses --depth 1 shallow clone to minimize network usage.
 * Auth: public HTTPS or pre-configured SSH keys only.
 */
class GitSkillSource implements SkillSource
{
    private string $cacheDir;

    public function __construct(
        private readonly string $url,
        private readonly string $sourceAlias,
        private readonly GitRunner $gitRunner,
        private readonly ?PhoenixTracer $tracer = null,
        ?string $baseCacheDir = null,
    ) {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $base = $baseCacheDir ?? "{$home}/.hao-code/skill-cache";
        $this->cacheDir = $base.'/'.sha1($url);
    }

    public function alias(): string
    {
        return $this->sourceAlias;
    }

    public function load(): array
    {
        if (! is_dir($this->cacheDir)) {
            $fetched = $this->cloneRepo();
            if ($fetched < 0) {
                return [];
            }
        }

        return $this->loadFromCache();
    }

    public function sync(): int
    {
        $startMs = (int) round(microtime(true) * 1000);
        $span = $this->tracer?->startSpan('skill.source.sync', PhoenixTracer::KIND_TOOL, [
            'source_url_hash' => sha1($this->url),
        ]);

        $success = false;
        $count = -1;

        try {
            if (! is_dir($this->cacheDir)) {
                $count = $this->cloneRepo();
            } else {
                $count = $this->pullRepo();
            }

            $success = $count >= 0;
        } finally {
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            if ($span !== null) {
                $span->setAttribute('fetched_count', max(0, $count));
                $span->setAttribute('duration_ms', $durationMs);
                $span->setAttribute('success', $success);
                $span->end();
            }
        }

        return $count;
    }

    public function isSyncable(): bool
    {
        return true;
    }

    private function cloneRepo(): int
    {
        $parent = dirname($this->cacheDir);
        if (! is_dir($parent)) {
            mkdir($parent, 0700, true);
        }

        [$exit, , $stderr] = $this->gitRunner->run(
            ['clone', '--depth', '1', $this->url, $this->cacheDir]
        );

        if ($exit !== 0) {
            return -1;
        }

        return count($this->loadFromCache());
    }

    private function pullRepo(): int
    {
        [$exit] = $this->gitRunner->run(['pull', '--depth', '1'], $this->cacheDir);

        if ($exit !== 0) {
            return -1;
        }

        return count($this->loadFromCache());
    }

    /**
     * @return array<string, SkillDefinition>
     */
    private function loadFromCache(): array
    {
        if (! is_dir($this->cacheDir)) {
            return [];
        }

        $loader = new SkillLoader;
        $skills = [];

        // Load from skills subdirectory or root
        $skillsDir = $this->cacheDir.'/skills';
        $scanDir = is_dir($skillsDir) ? $skillsDir : $this->cacheDir;

        // New format: <name>/SKILL.md
        foreach (glob($scanDir.'/*/SKILL.md') ?: [] as $file) {
            $name = basename(dirname($file));
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $def = $this->parseSkillFile($name, $content, dirname($file), $file);
            $skills[$name] = $def;
        }

        // Legacy format: <name>.md
        foreach (glob($scanDir.'/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            if (isset($skills[$name])) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $def = $this->parseSkillFile($name, $content, dirname($file), $file);
            $skills[$name] = $def;
        }

        return $skills;
    }

    private function parseSkillFile(string $name, string $content, string $dir, ?string $sourcePath = null): SkillDefinition
    {
        $frontmatter = [];
        $body = $content;

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $m)) {
            $frontmatter = $this->parseYaml($m[1]);
            $body = $m[2];
        }

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
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $m)) {
                $result[$m[1]] = trim($m[2], '"\' ');
            }
        }

        return $result;
    }

    private function parseList(string $value): array
    {
        if (empty($value)) {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }

    private function firstLine(string $text): string
    {
        $line = trim(explode("\n", trim($text))[0] ?? '');

        return mb_substr($line, 0, 100);
    }
}
