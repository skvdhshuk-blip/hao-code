<?php

namespace HaoCode\Services\Session;

/**
 * Bounded JSONL storage primitives shared by session transcript and interrupt
 * lifecycle code.
 *
 * @internal
 */
final class SessionJsonlStore
{
    public function __construct(
        private readonly int $maxEntryBytes,
        private readonly int $maxSessionBytes,
    ) {
    }

    public function readEntries(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Could not open session transcript: {$path}");
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Could not lock session transcript for reading: {$path}");
            }

            $sessionId = basename($path, '.jsonl');
            for ($attempt = 0; $attempt < 2; $attempt++) {
                rewind($handle);
                clearstatcache(true, $path);
                $contents = stream_get_contents($handle);
                if ($contents === false) {
                    throw new \RuntimeException("Could not read session transcript: {$sessionId}");
                }

                $entries = [];
                $lines = explode("\n", $contents);
                $hasTrailingNewline = str_ends_with($contents, "\n");
                if ($hasTrailingNewline) {
                    array_pop($lines);
                }

                $retryFinalLine = false;
                foreach ($lines as $index => $line) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $entries[] = $decoded;

                        continue;
                    }

                    $lineNumber = $index + 1;
                    $isUnterminatedFinalLine = ! $hasTrailingNewline && $index === array_key_last($lines);
                    if ($attempt === 0 && $isUnterminatedFinalLine) {
                        $retryFinalLine = true;
                        break;
                    }

                    throw new \RuntimeException(
                        "Session {$sessionId} contains invalid JSON on line {$lineNumber}: "
                        .json_last_error_msg(),
                    );
                }

                if (! $retryFinalLine) {
                    return $entries;
                }

                usleep(1_000);
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }

        throw new \RuntimeException('Could not read session transcript.');
    }

    public static function findLatestInterruptEntry($handle, string $interruptId): ?array
    {
        rewind($handle);
        $latest = null;
        $sawInterruptLine = false;
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                // Once interrupt lifecycle has started for this id, a corrupt
                // later line must not allow state to roll back (e.g. resolving
                // → pending). Fail closed as indeterminate.
                if ($sawInterruptLine || self::lineMentionsInterruptId($trimmed, $interruptId)) {
                    throw new \RuntimeException(
                        "Interrupt {$interruptId} state is indeterminate: session JSONL contains a corrupt line after interrupt activity began. Manual recovery required.",
                    );
                }
                continue;
            }
            if (in_array($entry['type'] ?? null, [
                'interrupt_pending',
                'interrupt_resolving',
                'interrupt_resolved',
                'interrupt_cancelled',
                'interrupt_failed',
            ], true)
                && ($entry['interrupt']['id'] ?? null) === $interruptId) {
                $sawInterruptLine = true;
                $latest = $entry;
            }
        }

        return $latest;
    }

    public static function lineMentionsInterruptId(string $line, string $interruptId): bool
    {
        return $interruptId !== '' && str_contains($line, $interruptId);
    }

    public static function encodeEntryForJsonl(array $entry): string
    {
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode(self::sanitizeForJson($entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($encoded === false) {
            $encoded = json_encode($entry, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($encoded === false) {
            throw new \RuntimeException('Could not serialize session interrupt checkpoint.');
        }

        return $encoded;
    }

    /** @param resource $handle */
    public function appendJsonLine($handle, array $entry): void
    {
        $line = self::encodeEntryForJsonl($entry)."\n";
        $this->assertAppendFits($handle, $line);
        fseek($handle, 0, SEEK_END);
        $written = fwrite($handle, $line);
        if ($written === false || $written !== strlen($line) || ! fflush($handle)) {
            throw new \RuntimeException('Could not persist session interrupt checkpoint.');
        }
    }

    public function appendLineToSessionFile(string $sessionPath, string $path, string $line, string $purpose): void
    {
        if (! is_dir($sessionPath)
            && ! @mkdir($sessionPath, 0700, true)
            && ! is_dir($sessionPath)) {
            throw new \RuntimeException(
                "Could not create session directory for {$purpose}: {$sessionPath}",
            );
        }

        $handle = @fopen($path, 'a');
        if ($handle === false) {
            throw new \RuntimeException(
                "Could not open session file for {$purpose}: {$path}",
            );
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Could not lock session file for {$purpose}.");
            }
            $this->assertAppendFits($handle, $line);
            $written = fwrite($handle, $line);
            if ($written === false || $written !== strlen($line)) {
                throw new \RuntimeException("Could not write {$purpose} to session file.");
            }
            if (! fflush($handle)) {
                throw new \RuntimeException("Could not flush {$purpose} to disk.");
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource $handle */
    public function assertAppendFits($handle, string $line): void
    {
        $lineBytes = strlen($line);
        if ($lineBytes > $this->maxEntryBytes) {
            throw new \RuntimeException(
                'Session entry exceeds the 32 MiB persistence limit.',
            );
        }

        if (fseek($handle, 0, SEEK_END) !== 0) {
            throw new \RuntimeException('Could not inspect session file size.');
        }
        $currentBytes = ftell($handle);
        if ($currentBytes === false || $currentBytes + $lineBytes > $this->maxSessionBytes) {
            throw new \RuntimeException(
                'Session transcript exceeds the 128 MiB persistence limit.',
            );
        }
    }

    /**
     * Recursively scrub invalid UTF-8 byte sequences and non-finite doubles
     * so json_encode cannot fail on tool payloads.
     */
    public static function sanitizeForJson(mixed $value): mixed
    {
        if (is_string($value)) {
            if (preg_match('//u', $value) === 1) {
                return $value;
            }
            $scrubbed = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return $scrubbed !== false ? $scrubbed : '';
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[self::sanitizeForJson($key)] = self::sanitizeForJson($item);
            }

            return $sanitized;
        }

        return $value;
    }

}
