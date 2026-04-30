<?php

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
    private ?TieredSummarizer $summarizer = null;

    public function __construct(?string $storagePath = null)
    {
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
        $this->load();

        $existing = $this->memories[$key] ?? null;

        $entry = [
            'value' => $value,
            'type' => $type,
            'updated_at' => date('c'),
            'created_at' => $existing['created_at'] ?? date('c'),
        ];

        // Generate tiered summaries
        $summaries = $this->getSummarizer()->summarize($value);
        $entry['l0'] = $summaries['l0'];
        $entry['l1'] = $summaries['l1'];
        $entry['l0_tokens'] = $summaries['l0_tokens'];
        $entry['l1_tokens'] = $summaries['l1_tokens'];
        $entry['l2_tokens'] = $summaries['l0_tokens'] > 0
            ? $this->getSummarizer()->countTokens($value)
            : 0;
        $entry['summary_mode'] = $summaries['mode'];
        $entry['summary_generated_at'] = date('c');

        $this->memories[$key] = $entry;
        $this->save();
    }

    /**
     * Get a memory entry.
     */
    public function get(string $key): ?string
    {
        $this->load();
        return $this->memories[$key]['value'] ?? null;
    }

    /**
     * Get the full entry array for a key (including summaries).
     */
    public function getEntry(string $key): ?array
    {
        $this->load();
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
        $this->load();
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
        $this->load();
        if (! isset($this->memories[$key])) {
            return false;
        }
        unset($this->memories[$key]);
        $this->save();

        return true;
    }

    /**
     * List all memory entries.
     */
    public function list(): array
    {
        $this->load();

        return $this->memories ?? [];
    }

    /**
     * Search memories by keyword (searches keys and L0/L1 summaries).
     */
    public function search(string $query): array
    {
        $this->load();
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
        $this->load();
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
        $this->load();
        $count = 0;

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
            $this->memories[$k]['l0'] = $summaries['l0'];
            $this->memories[$k]['l1'] = $summaries['l1'];
            $this->memories[$k]['l0_tokens'] = $summaries['l0_tokens'];
            $this->memories[$k]['l1_tokens'] = $summaries['l1_tokens'];
            $this->memories[$k]['l2_tokens'] = $this->getSummarizer()->countTokens($value);
            $this->memories[$k]['summary_mode'] = $summaries['mode'];
            $this->memories[$k]['summary_generated_at'] = date('c');
            $count++;
        }

        if ($count > 0) {
            $this->save();
        }

        return $count;
    }

    /**
     * Compact old memories when they exceed a threshold.
     */
    public function compact(int $maxEntries = 100): int
    {
        $this->load();
        $count = count($this->memories);

        if ($count <= $maxEntries) {
            return 0;
        }

        uasort($this->memories, fn ($a, $b) => strtotime($b['updated_at'] ?? '') <=> strtotime($a['updated_at'] ?? ''));
        $this->memories = array_slice($this->memories, 0, $maxEntries, true);
        $this->save();

        return $count - $maxEntries;
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

    private function load(): void
    {
        if ($this->memories !== null) {
            return;
        }

        if (file_exists($this->path)) {
            $data = json_decode(file_get_contents($this->path), true);
            $this->memories = is_array($data) ? $data : [];
        } else {
            $this->memories = [];
        }
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->path, json_encode($this->memories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
