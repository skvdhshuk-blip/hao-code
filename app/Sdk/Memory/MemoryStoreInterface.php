<?php

namespace HaoCode\Sdk\Memory;

/**
 * Persistent long-term memory storage used by an SDK run.
 *
 * @api
 */
interface MemoryStoreInterface
{
    /**
     * Store or replace one memory entry.
     *
     * @api
     */
    public function write(string $key, string $value, string $type = 'note'): void;

    /**
     * Read one entry at the requested summary level: l0, l1, or l2.
     *
     * @api
     */
    public function read(string $key, string $level = 'l2'): ?string;

    /**
     * Delete one memory entry.
     *
     * @api
     */
    public function delete(string $key): bool;

    /**
     * Return all entries at the requested summary level, keyed by memory key.
     *
     * @api
     *
     * @return array<string, string>
     */
    public function all(string $level = 'l0'): array;
}
