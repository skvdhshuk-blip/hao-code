<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/** Owns background-agent JSON paths, locks, and atomic persistence. @internal */
final class BackgroundAgentStateStore
{
    public function __construct(private readonly string $root)
    {
        if (trim($this->root) === '') {
            throw new \InvalidArgumentException('Background agent storage root must be non-empty.');
        }
        if (! is_dir($this->root)
            && ! mkdir($this->root, 0755, true)
            && ! is_dir($this->root)) {
            throw new \RuntimeException("Unable to create background agent storage: {$this->root}");
        }
    }

    public function root(): string { return $this->root; }
    public function statePath(string $id): string { return $this->root."/{$id}.state.json"; }
    public function mailboxPath(string $id): string { return $this->root."/{$id}.mailbox.json"; }

    public function mutate(string $id, callable $callback): ?array
    {
        return $this->withLock($id, LOCK_EX, function () use ($id, $callback): ?array {
            $current = $this->read($this->statePath($id));
            if ($current === null) {
                return null;
            }
            $next = $callback($current);
            if (! is_array($next)) {
                throw new \RuntimeException('Background agent state mutation must return an array.');
            }
            $next['updated_at'] = time();
            $this->write($this->statePath($id), $next);

            return $next;
        });
    }

    public function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function write(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = tempnam($this->root, '.haocode-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary background-agent state file.');
        }
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new \RuntimeException("Unable to persist background agent state: {$path}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function withLock(string $id, int $operation, callable $callback): mixed
    {
        $handle = fopen($this->root."/{$id}.lock", 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open background agent lock: {$id}");
        }
        try {
            if (! flock($handle, $operation)) {
                throw new \RuntimeException("Unable to lock background agent state: {$id}");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
