<?php

namespace HaoCode\Sdk\Memory;

use HaoCode\Services\Memory\SessionMemory;

/**
 * JSON-backed long-term memory store.
 *
 * @api
 */
final class JsonMemoryStore implements MemoryStoreInterface
{
    private readonly SessionMemory $memory;

    /**
     * @api
     */
    public function __construct(?string $storagePath = null)
    {
        $this->memory = new SessionMemory($storagePath);
    }

    /** @api */
    public function write(string $key, string $value, string $type = 'note'): void
    {
        $this->memory->set($key, $value, $type);
    }

    /** @api */
    public function read(string $key, string $level = 'l2'): ?string
    {
        $this->assertLevel($level);

        return $this->memory->getSummary($key, $level);
    }

    /** @api */
    public function delete(string $key): bool
    {
        return $this->memory->delete($key);
    }

    /** @api */
    public function all(string $level = 'l0'): array
    {
        $this->assertLevel($level);
        $entries = [];

        foreach ($this->memory->list() as $key => $entry) {
            $content = match ($level) {
                'l2' => $entry['value'] ?? '',
                'l1' => $entry['l1'] ?? $entry['value'] ?? '',
                default => $entry['l0'] ?? $entry['value'] ?? '',
            };
            if ($content !== '') {
                $entries[(string) $key] = (string) $content;
            }
        }

        return $entries;
    }

    private function assertLevel(string $level): void
    {
        if (! in_array($level, ['l0', 'l1', 'l2'], true)) {
            throw new \InvalidArgumentException('Memory level must be l0, l1, or l2.');
        }
    }
}
