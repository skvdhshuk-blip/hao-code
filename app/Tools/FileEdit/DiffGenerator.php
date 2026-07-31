<?php

namespace HaoCode\Tools\FileEdit;

use HaoCode\Services\Git\HardenedGitRunner;

/**
 * Generates unified diffs and structured patches for file edits,
 * matching claude-code's diff output format.
 */
class DiffGenerator
{
    /**
     * Generate a unified diff between old and new content.
     */
    public static function unifiedDiff(string $oldContent, string $newContent, string $filePath = 'file'): string
    {
        if ($oldContent === $newContent) {
            return '';
        }

        $oldLines = self::splitContentLines($oldContent);
        $newLines = self::splitContentLines($newContent);

        $oldCount = count($oldLines);
        $newCount = count($newLines);

        $prefix = 0;
        while ($prefix < $oldCount && $prefix < $newCount && $oldLines[$prefix] === $newLines[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < ($oldCount - $prefix)
            && $suffix < ($newCount - $prefix)
            && $oldLines[$oldCount - $suffix - 1] === $newLines[$newCount - $suffix - 1]) {
            $suffix++;
        }

        $context = 3;
        $oldChangeEnd = $oldCount - $suffix;
        $newChangeEnd = $newCount - $suffix;
        $hunkOldStart = max(0, $prefix - $context);
        $hunkNewStart = max(0, $prefix - $context);
        $hunkOldEnd = min($oldCount, $oldChangeEnd + $context);
        $hunkNewEnd = min($newCount, $newChangeEnd + $context);

        $oldLength = $hunkOldEnd - $hunkOldStart;
        $newLength = $hunkNewEnd - $hunkNewStart;
        $oldStartLine = $oldLength === 0 ? $hunkOldStart : $hunkOldStart + 1;
        $newStartLine = $newLength === 0 ? $hunkNewStart : $hunkNewStart + 1;

        $diff = "--- {$filePath}\n";
        $diff .= "+++ {$filePath}\n";
        $diff .= sprintf(
            "@@ -%d,%d +%d,%d @@\n",
            $oldStartLine,
            $oldLength,
            $newStartLine,
            $newLength,
        );

        for ($i = $hunkOldStart; $i < $prefix; $i++) {
            $diff .= ' '.$oldLines[$i]."\n";
        }
        for ($i = $prefix; $i < $oldChangeEnd; $i++) {
            $diff .= '-'.$oldLines[$i]."\n";
        }
        for ($i = $prefix; $i < $newChangeEnd; $i++) {
            $diff .= '+'.$newLines[$i]."\n";
        }
        for ($i = $oldChangeEnd; $i < $hunkOldEnd; $i++) {
            $diff .= ' '.$oldLines[$i]."\n";
        }

        return $diff;
    }

    /**
     * Generate a git diff if the file is in a git repository.
     * Returns empty string if not in a git repo or git is unavailable.
     */
    public static function gitDiff(string $filePath): string
    {
        return (new HardenedGitRunner())->diffForFile($filePath);
    }

    /**
     * Generate a structured patch (array of hunks) from old and new content.
     *
     * Each hunk: ['oldStart' => int, 'oldLines' => int, 'newStart' => int, 'newLines' => int, 'lines' => string[]]
     *
     * @return array<int, array{oldStart: int, oldLines: int, newStart: int, newLines: int, lines: string[]}>
     */
    public static function structuredPatch(string $oldContent, string $newContent, string $filePath = 'file'): array
    {
        $diff = self::unifiedDiff($oldContent, $newContent, $filePath);
        if ($diff === '') {
            return [];
        }

        $lines = explode("\n", $diff);
        $hunks = [];
        $currentHunk = null;

        foreach ($lines as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m)) {
                if ($currentHunk !== null) {
                    $hunks[] = $currentHunk;
                }
                $currentHunk = [
                    'oldStart' => (int) $m[1],
                    'oldLines' => isset($m[2]) ? (int) $m[2] : 1,
                    'newStart' => (int) $m[3],
                    'newLines' => isset($m[4]) ? (int) $m[4] : 1,
                    'lines' => [],
                ];
                continue;
            }

            if ($currentHunk !== null && ($line === '' || $line[0] === ' ' || $line[0] === '+' || $line[0] === '-')) {
                $currentHunk['lines'][] = $line;
            }
        }

        if ($currentHunk !== null) {
            $hunks[] = $currentHunk;
        }

        return $hunks;
    }

    /**
     * Format a compact summary of changes (additions/deletions).
     */
    public static function changeSummary(string $oldContent, string $newContent): string
    {
        $oldLines = substr_count($oldContent, "\n") + ($oldContent !== '' ? 1 : 0);
        $newLines = substr_count($newContent, "\n") + ($newContent !== '' ? 1 : 0);
        $added = max(0, $newLines - $oldLines);
        $removed = max(0, $oldLines - $newLines);

        $parts = [];
        if ($added > 0) {
            $parts[] = "+{$added}";
        }
        if ($removed > 0) {
            $parts[] = "-{$removed}";
        }
        if ($parts === []) {
            $parts[] = '~modified';
        }

        return implode(' ', $parts) . ' lines';
    }

    /** @return list<string> */
    private static function splitContentLines(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }
}
