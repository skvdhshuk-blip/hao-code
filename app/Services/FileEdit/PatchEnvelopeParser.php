<?php

namespace HaoCode\Services\FileEdit;

/**
 * Parses codex envelope format patches into PatchOperation arrays.
 *
 * Envelope format:
 *   *** Begin Patch
 *   *** Add File: path/to/file
 *   <file content lines>
 *   *** Update File: path/to/file
 *   *** Begin Hunk
 *   <context/diff lines (- removed, + added, space context)>
 *   *** End Hunk
 *   *** Delete File: path/to/file
 *   *** End Patch
 *
 * @experimental Windows rename atomicity is weak; use with caution on Windows.
 */
class PatchEnvelopeParser
{
    public const MAX_FILES = 50;

    public const MAX_BYTES = 1024 * 1024; // 1 MiB

    /**
     * Parse a patch envelope string into an array of PatchOperation.
     *
     * @return PatchOperation[]
     *
     * @throws \InvalidArgumentException on malformed envelope or limit exceeded
     */
    public function parse(string $envelope): array
    {
        if (strlen($envelope) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(
                'Patch envelope exceeds 1 MiB limit ('.strlen($envelope).' bytes).'
            );
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $envelope));
        $operations = [];
        $i = 0;
        $total = count($lines);

        while ($i < $total && trim($lines[$i]) !== '*** Begin Patch') {
            $i++;
        }
        if ($i >= $total) {
            throw new \InvalidArgumentException('Missing "*** Begin Patch" marker.');
        }
        $i++;

        while ($i < $total) {
            $line = trim($lines[$i]);

            if ($line === '*** End Patch') {
                break;
            }

            if (str_starts_with($line, '*** Add File: ')) {
                $path = trim(substr($line, 14));
                $i++;
                [$content, $i] = $this->readUntilNextDirective($lines, $i, $total);
                $operations[] = new PatchOperation('add', $path, null, $content);

                continue;
            }

            if (str_starts_with($line, '*** Delete File: ')) {
                $path = trim(substr($line, 17));
                $operations[] = new PatchOperation('delete', $path, null, null);
                $i++;

                continue;
            }

            if (str_starts_with($line, '*** Update File: ')) {
                $path = trim(substr($line, 17));
                $i++;
                $hunks = [];
                while ($i < $total) {
                    $inner = trim($lines[$i]);
                    if ($inner === '*** Begin Hunk') {
                        $i++;
                        [$hunkLines, $i] = $this->readHunk($lines, $i, $total);
                        $hunks[] = $hunkLines;

                        continue;
                    }
                    if (str_starts_with($inner, '*** ')) {
                        break;
                    }
                    $i++;
                }
                $operations[] = new PatchOperation('update', $path, $hunks, null);

                continue;
            }

            $i++;
        }

        if (count($operations) > self::MAX_FILES) {
            throw new \InvalidArgumentException(
                'Patch exceeds maximum file count of '.self::MAX_FILES.'.'
            );
        }

        return $operations;
    }

    /**
     * Count total lines across all operations for telemetry.
     *
     * @param  PatchOperation[]  $operations
     */
    public function countLines(array $operations): int
    {
        $count = 0;
        foreach ($operations as $op) {
            if ($op->type === 'add' && $op->newContent !== null) {
                $count += substr_count($op->newContent, "\n") + 1;
            }
            if ($op->type === 'update' && $op->hunks !== null) {
                foreach ($op->hunks as $hunk) {
                    $count += count($hunk);
                }
            }
        }

        return $count;
    }

    /**
     * Count total hunks across update operations.
     *
     * @param  PatchOperation[]  $operations
     */
    public function countHunks(array $operations): int
    {
        $count = 0;
        foreach ($operations as $op) {
            if ($op->type === 'update' && $op->hunks !== null) {
                $count += count($op->hunks);
            }
        }

        return $count;
    }

    /** @return array{string, int} [content, next_index] */
    private function readUntilNextDirective(array $lines, int $i, int $total): array
    {
        $content = [];
        while ($i < $total) {
            $line = $lines[$i];
            if (str_starts_with(trim($line), '*** ')) {
                break;
            }
            $content[] = $line;
            $i++;
        }

        return [implode("\n", $content), $i];
    }

    /** @return array{string[], int} [hunk_lines, next_index] */
    private function readHunk(array $lines, int $i, int $total): array
    {
        $hunkLines = [];
        while ($i < $total) {
            $line = $lines[$i];
            if (trim($line) === '*** End Hunk') {
                $i++;
                break;
            }
            $hunkLines[] = $line;
            $i++;
        }

        return [$hunkLines, $i];
    }
}
