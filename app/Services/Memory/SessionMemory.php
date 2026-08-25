<?php

declare(strict_types=1);

namespace HaoCode\Services\Memory;

/**
 * Persistent key-value memory with tiered summaries (L0/L1/L2).
 *
 * Each entry stores full content plus L0 (one-liner ~50 tokens) and L1
 * (structured overview ~500 tokens). The system prompt loads L0 summaries
 * so the agent has a compact index; L1/L2 detail is fetched on demand
 * via MemoryReadTool.
 */
class SessionMemory
{
    private ?array $memories = null;
    private string $path;
    private ?TieredSummarizer $summarizer;

    private ?string $loadedFileSignature = null;

    public function __construct(?string $storagePath = null, ?TieredSummarizer $summarizer = null)
    {
        $this->summarizer = $summarizer;
        if ($storagePath !== null) {
            $this->path = $storagePath;
        } else {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
            $this->path = "{$home}/.haocode/memory.json";
        }
    }

    /**
     * Store a memory entry with auto-generated tiered summaries.
     */
    public function set(string $key, string $value, string $type = 'note'): void
    {
        $summaries = $this->getSummarizer()->summarize($value);

        $this->withExclusiveLock(function () use ($key, $value, $type, $summaries): void {
            $this->load(true);
            $existing = $this->memories[$key] ?? null;
            $now = date('c');

            $this->memories[$key] = [
                'value' => $value,
                'type' => $type,
                'updated_at' => $now,
                'created_at' => $existing['created_at'] ?? $now,
                'l0' => $summaries['l0'],
                'l1' => $summaries['l1'],
                'l0_tokens' => $summaries['l0_tokens'],
                'l1_tokens' => $summaries['l1_tokens'],
                'l2_tokens' => $summaries['l0_tokens'] > 0
                    ? $this->getSummarizer()->countTokens($value)
                    : 0,
                'summary_mode' => $summaries['mode'],
                'summary_generated_at' => $now,
            ];
            $this->save();
        });
    }

    /**
     * Get a memory entry.
     */
    public function get(string $key): ?string
    {
        $this->load(true);
        return $this->memories[$key]['value'] ?? null;
    }

    /**
     * Get the full entry array for a key (including summaries).
     */
    public function getEntry(string $key): ?array
    {
        $this->load(true);
        return $this->memories[$key] ?? null;
    }

    /**
     * Get a specific summary tier for a key.
     *
     * @param  string  $key   Memory key.
     * @param  string  $level  'l0', 'l1', or 'l2' (full content).
     */
    public function getSummary(string $key, string $level = 'l1'): ?string
    {
        $this->load(true);
        $entry = $this->memories[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($level === 'l2') {
            return $entry['value'] ?? null;
        }

        return $entry[$level] ?? ($entry['value'] ?? null);
    }

    /**
     * Delete a memory entry.
     */
    public function delete(string $key): bool
    {
        return $this->withExclusiveLock(function () use ($key): bool {
            $this->load(true);
            if (! isset($this->memories[$key])) {
                return false;
            }
            unset($this->memories[$key]);
            $this->save();

            return true;
        });
    }

    /**
     * List all memory entries.
     */
    public function list(): array
    {
        $this->load(true);

        return $this->memories ?? [];
    }

    /**
     * Search memories by keyword (searches keys and L0/L1 summaries).
     */
    public function search(string $query): array
    {
        $this->load(true);
        $results = [];
        $query = strtolower($query);

        foreach ($this->memories ?? [] as $key => $entry) {
            $searchable = $key . ' ' . ($entry['l0'] ?? '') . ' ' . ($entry['l1'] ?? '') . ' ' . ($entry['value'] ?? '');
            if (str_contains(strtolower($searchable), $query)) {
                $results[$key] = $entry;
            }
        }

        return $results;
    }

    /**
     * Get memories formatted for system prompt injection.
     *
     * Uses L0 summaries (one-liners) to give the agent a compact index
     * of all memories. The agent can use MemoryReadTool to fetch L1/L2
     * for specific entries when needed.
     *
     * @param  int     $maxChars  Maximum character budget for the memory section.
     * @param  string  $level     Summary level: 'l0' (compact), 'l1' (detailed), 'l2' (full).
     */
    public function forSystemPrompt(int $maxChars = 3000, string $level = 'l0'): string
    {
        $this->load(true);
        if (empty($this->memories)) {
            return '';
        }

        $header = "Persistent memories from previous sessions (use MemoryRead tool to fetch full detail):";
        $lines = [$header];
        $totalLen = strlen($header);

        foreach ($this->memories as $key => $entry) {
            $content = match ($level) {
                'l2' => $entry['value'] ?? '',
                'l1' => $entry['l1'] ?? $entry['value'] ?? '',
                default => $entry['l0'] ?? $this->fallbackL0($entry['value'] ?? ''),
            };

            if ($content === '') {
                continue;
            }

            $mode = ($entry['summary_mode'] ?? '') === 'llm' ? '' : ' [raw]';
            $line = "- {$key}{$mode}: {$content}";
            if ($totalLen + strlen($line) > $maxChars) {
                break;
            }
            $lines[] = $line;
            $totalLen += strlen($line);
        }

        return implode("\n", $lines);
    }

    /**
     * Regenerate summaries for all entries or a specific key.
     *
     * Useful after upgrading from a version without summaries,
     * or when the original content has been updated.
     */
    public function regenerateSummaries(?string $key = null): int
    {
        $this->load(true);
        $updates = [];

        $keys = $key !== null ? [$key] : array_keys($this->memories ?? []);

        foreach ($keys as $k) {
            if (! isset($this->memories[$k])) {
                continue;
            }

            $value = $this->memories[$k]['value'] ?? '';
            if ($value === '') {
                continue;
            }

            $summaries = $this->getSummarizer()->summarize($value);
            $updates[$k] = ['value' => $value, 'summaries' => $summaries];
        }

        if ($updates === []) {
            return 0;
        }

        return $this->withExclusiveLock(function () use ($updates): int {
            $this->load(true);
            $count = 0;

            foreach ($updates as $key => $update) {
                if (($this->memories[$key]['value'] ?? null) !== $update['value']) {
                    continue;
                }
                $summaries = $update['summaries'];
                $this->memories[$key]['l0'] = $summaries['l0'];
                $this->memories[$key]['l1'] = $summaries['l1'];
                $this->memories[$key]['l0_tokens'] = $summaries['l0_tokens'];
                $this->memories[$key]['l1_tokens'] = $summaries['l1_tokens'];
                $this->memories[$key]['l2_tokens'] = $this->getSummarizer()->countTokens($update['value']);
                $this->memories[$key]['summary_mode'] = $summaries['mode'];
                $this->memories[$key]['summary_generated_at'] = date('c');
                $count++;
            }

            if ($count > 0) {
                $this->save();
            }

            return $count;
        });
    }

    /**
     * Compact old memories when they exceed a threshold.
     */
    public function compact(int $maxEntries = 100): int
    {
        return $this->withExclusiveLock(function () use ($maxEntries): int {
            $this->load(true);
            $count = count($this->memories);

            if ($count <= $maxEntries) {
                return 0;
            }

            uasort($this->memories, fn ($a, $b) => strtotime($b['updated_at'] ?? '') <=> strtotime($a['updated_at'] ?? ''));
            $this->memories = array_slice($this->memories, 0, $maxEntries, true);
            $this->save();

            return $count - $maxEntries;
        });
    }

    private function getSummarizer(): TieredSummarizer
    {
        if ($this->summarizer === null) {
            $this->summarizer = new TieredSummarizer;
        }

        return $this->summarizer;
    }

    /**
     * Generate a quick L0 from a value when summaries haven't been generated yet.
     */
    private function fallbackL0(string $value): string
    {
        $value = str_replace("\n", ' ', trim($value));
        if (mb_strlen($value) <= 200) {
            return $value;
        }

        return mb_substr($value, 0, 197) . '...';
    }

    private function load(bool $force = false): void
    {
        if (! $force && $this->memories !== null) {
            return;
        }

        $signature = $this->fileSignature();
        if ($this->memories !== null && $signature === $this->loadedFileSignature) {
            return;
        }

        if ($signature !== null) {
            $contents = file_get_contents($this->path);
            if ($contents === false) {
                throw new \RuntimeException("Unable to read memory file {$this->path}.");
            }
            $trimmed = trim($contents);
            // Missing file is empty; existing non-empty corrupt JSON must fail closed
            // so a later write cannot silently wipe prior memories.
            if ($trimmed === '') {
                $this->memories = [];
            } else {
                try {
                    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException(
                        "Memory file is corrupt and will not be overwritten: {$this->path}. "
                        .$e->getMessage(),
                        0,
                        $e,
                    );
                }
                if (! is_array($data)) {
                    throw new \RuntimeException(
                        "Memory file is corrupt (JSON root must be an object/array): {$this->path}.",
                    );
                }
                $this->memories = $data;
            }
        } else {
            $this->memories = [];
        }

        $this->loadedFileSignature = $signature;
    }

    private function save(): void
    {
        $this->ensureDirectory();
        $dir = dirname($this->path);
        $temporaryPath = tempnam($dir, '.memory-');
        if ($temporaryPath === false) {
            throw new \RuntimeException("Unable to create a temporary memory file in {$dir}.");
        }

        try {
            $json = json_encode($this->memories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write temporary memory file {$temporaryPath}.");
            }
            chmod($temporaryPath, 0600);
            if (! rename($temporaryPath, $this->path)) {
                throw new \RuntimeException("Unable to replace memory file {$this->path}.");
            }
            $this->loadedFileSignature = $this->fileSignature();
        } finally {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function withExclusiveLock(callable $callback): mixed
    {
        $this->ensureDirectory();
        $lockPath = $this->path.'.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open memory lock {$lockPath}.");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to acquire memory lock {$lockPath}.");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create memory directory {$dir}.");
        }
    }

    private function fileSignature(): ?string
    {
        clearstatcache(true, $this->path);
        $stat = @stat($this->path);
        if ($stat === false) {
            return null;
        }

        // PHP exposes filesystem timestamps with second precision and some
        // platforms do not provide a useful inode. Include a fast content
        // fingerprint so same-size writes in the same second are not missed.
        static $hashAlgorithm = null;
        $hashAlgorithm ??= in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $contentHash = @hash_file($hashAlgorithm, $this->path);

        return implode(':', [
            (string) ($stat['dev'] ?? ''),
            (string) ($stat['ino'] ?? ''),
            (string) ($stat['size'] ?? ''),
            (string) ($stat['mtime'] ?? ''),
            (string) ($stat['ctime'] ?? ''),
            $contentHash === false ? 'unreadable' : $contentHash,
        ]);
    }
}
