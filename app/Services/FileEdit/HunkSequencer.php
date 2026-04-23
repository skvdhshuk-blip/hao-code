<?php

namespace HaoCode\Services\FileEdit;

/**
 * Applies a single hunk to file lines using seek_sequence matching.
 *
 * Each hunk line prefix:
 *   ' ' (space) = context — must match file; advance both pointers
 *   '-'         = remove  — skip file line
 *   '+'         = add     — insert into output
 */
class HunkSequencer
{
    /**
     * Apply all hunks from one update operation to file content.
     *
     * @param  string[][]  $hunks
     *
     * @throws \RuntimeException if any hunk's seek_sequence does not match
     */
    public function applyHunks(string $original, array $hunks, string $filePath): string
    {
        $lines = explode("\n", $original);

        foreach ($hunks as $hunkLines) {
            $lines = $this->applyHunk($lines, $hunkLines, $filePath);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  string[]  $fileLines
     * @param  string[]  $hunkLines
     * @return string[]
     *
     * @throws \RuntimeException if seek_sequence not found
     */
    public function applyHunk(array $fileLines, array $hunkLines, string $filePath): array
    {
        $seekLines = $this->extractSeekLines($hunkLines);
        $pos = $this->seekSequence($fileLines, $seekLines, $filePath);

        $output = array_slice($fileLines, 0, $pos);
        $fileIdx = $pos;

        foreach ($hunkLines as $hunkLine) {
            $prefix = $hunkLine !== '' ? $hunkLine[0] : ' ';
            $content = substr($hunkLine, 1);

            if ($prefix === ' ') {
                $output[] = $fileLines[$fileIdx] ?? $content;
                $fileIdx++;
            } elseif ($prefix === '-') {
                $fileIdx++;
            } elseif ($prefix === '+') {
                $output[] = $content;
            }
        }

        foreach (array_slice($fileLines, $fileIdx) as $remaining) {
            $output[] = $remaining;
        }

        return $output;
    }

    /**
     * Extract context + removed lines to form the seek sequence.
     *
     * @param  string[]  $hunkLines
     * @return string[]
     */
    private function extractSeekLines(array $hunkLines): array
    {
        $seek = [];
        foreach ($hunkLines as $line) {
            $prefix = $line !== '' ? $line[0] : ' ';
            if ($prefix === ' ' || $prefix === '-') {
                $seek[] = substr($line, 1);
            }
        }

        return $seek;
    }

    /**
     * Find the starting index where $seekLines appear in sequence in $fileLines.
     *
     * @param  string[]  $fileLines
     * @param  string[]  $seekLines
     *
     * @throws \RuntimeException
     */
    private function seekSequence(array $fileLines, array $seekLines, string $filePath): int
    {
        if ($seekLines === []) {
            return 0;
        }

        $fileCount = count($fileLines);
        $seekCount = count($seekLines);

        for ($i = 0; $i <= $fileCount - $seekCount; $i++) {
            $match = true;
            for ($j = 0; $j < $seekCount; $j++) {
                if (rtrim($fileLines[$i + $j]) !== rtrim($seekLines[$j])) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $i;
            }
        }

        throw new \RuntimeException(
            "Hunk seek_sequence not found in {$filePath}. ".
            'Context lines may not match current file content.'
        );
    }
}
