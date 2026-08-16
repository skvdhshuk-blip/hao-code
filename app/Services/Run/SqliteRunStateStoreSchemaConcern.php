<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

use HaoCode\Services\Session\SessionJsonlStore;

trait SqliteRunStateStoreSchemaConcern
{
    private function initializeSchema(): void
    {
        $this->transaction(function (): void {
            $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS run_schema_meta (
                version INTEGER NOT NULL
            );
            SQL);

            $versions = $this->pdo->query('SELECT version FROM run_schema_meta')->fetchAll();
            if (count($versions) > 1) {
                throw new \RuntimeException('Run store schema metadata contains multiple versions.');
            }
            if ($versions !== []) {
                $version = (int) ($versions[0]['version'] ?? 0);
                if ($version !== self::SCHEMA_VERSION) {
                    throw new \RuntimeException(
                        'Run store schema mismatch: expected '.self::SCHEMA_VERSION.", found {$version}.",
                    );
                }
            }

            $this->pdo->exec(<<<'SQL'

            CREATE TABLE IF NOT EXISTS run_events (
                run_id TEXT NOT NULL,
                sequence INTEGER NOT NULL,
                event_id TEXT NOT NULL UNIQUE,
                invocation_id TEXT NOT NULL,
                causation_id TEXT,
                phase TEXT NOT NULL,
                event_type TEXT NOT NULL,
                dedupe_key TEXT NOT NULL,
                occurred_at TEXT NOT NULL,
                schema_version INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                PRIMARY KEY (run_id, sequence),
                UNIQUE (run_id, dedupe_key)
            );

            CREATE TABLE IF NOT EXISTS run_checkpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                invocation_id TEXT NOT NULL,
                event_sequence INTEGER NOT NULL,
                status TEXT NOT NULL,
                state_delta_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                schema_version INTEGER NOT NULL,
                UNIQUE (run_id, event_sequence, status)
            );
            CREATE INDEX IF NOT EXISTS run_checkpoints_latest
                ON run_checkpoints (run_id, event_sequence DESC, id DESC);

            CREATE TABLE IF NOT EXISTS tool_executions (
                idempotency_key TEXT PRIMARY KEY,
                run_id TEXT NOT NULL,
                invocation_id TEXT NOT NULL,
                tool_use_id TEXT NOT NULL,
                tool_name TEXT NOT NULL,
                input_hash TEXT NOT NULL,
                read_only INTEGER NOT NULL,
                state TEXT NOT NULL,
                owner_id TEXT,
                lease_expires_at_ms INTEGER NOT NULL,
                fencing_token INTEGER NOT NULL,
                result_json TEXT,
                updated_at_ms INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS tool_executions_recovery
                ON tool_executions (run_id, state, lease_expires_at_ms);

            CREATE TABLE IF NOT EXISTS run_leases (
                run_id TEXT PRIMARY KEY,
                owner_id TEXT,
                lease_expires_at_ms INTEGER NOT NULL,
                fencing_token INTEGER NOT NULL,
                updated_at_ms INTEGER NOT NULL
            );
            SQL);

            if ($versions === []) {
                $statement = $this->pdo->prepare('INSERT INTO run_schema_meta (version) VALUES (?)');
                $statement->execute([self::SCHEMA_VERSION]);
            }
        });
    }

    /** @template T @param callable(): T $callback @return T */
    private function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // Preserve the failure that caused the transaction rollback.
            }
            throw $error;
        }
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode(
            SessionJsonlStore::sanitizeForJson($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($encoded === false) {
            throw new \RuntimeException('Could not serialize run store value.');
        }

        return $encoded;
    }

    private function decodeJson(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Run store contains invalid JSON: '.json_last_error_msg());
        }

        return $decoded;
    }
}
