<?php

namespace HaoCode\Tools;

use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Cache\FileState;
use HaoCode\Services\Cache\FileStateCache;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Support\Filesystem\CanonicalPathResolver;

trait ToolUseContextMergeWithExistingReadRevisionConcern
{

    /** @param array<string, mixed> $revision */
    private function mergeWithExistingReadRevision(string $key, array $revision): array
    {
        $existing = $this->pendingReadFileState[$key] ?? ($this->readFileState[$key] ?? null);
        if (! is_array($existing)) {
            return $revision;
        }

        $existingRevision = FileRevision::fromArray($existing);
        $newRevision = FileRevision::fromArray($revision);
        if ($existingRevision === null || $newRevision === null || ! $existingRevision->sameVersion($newRevision)) {
            return $revision;
        }

        $coverage = array_merge(
            $this->lineCoverageFromReceipt($existing),
            $this->lineCoverageFromReceipt($revision),
        );
        if ($coverage === []) {
            return ($revision['observed_at_micros'] ?? 0) >= ($existing['observed_at_micros'] ?? 0)
                ? $revision
                : $existing;
        }

        $merged = ($revision['observed_at_micros'] ?? 0) >= ($existing['observed_at_micros'] ?? 0)
            ? $revision
            : $existing;
        $merged['total_lines'] = $this->totalLinesFromReceipt($revision) ?? $this->totalLinesFromReceipt($existing);
        $merged['line_coverage'] = $this->mergeLineCoverage($coverage);
        $merged['complete'] = ($existing['complete'] ?? false) === true
            || ($revision['complete'] ?? false) === true
            || $this->coverageCoversCompleteFile($merged);
        $merged['observed_at_micros'] = max(
            (int) ($existing['observed_at_micros'] ?? 0),
            (int) ($revision['observed_at_micros'] ?? 0),
        );

        return $merged;
    }

    /** @param array<string, mixed> $receipt */
    private function refreshCoverageCompleteness(array $receipt): array
    {
        $receipt['line_coverage'] = $this->mergeLineCoverage($this->lineCoverageFromReceipt($receipt));
        if ($this->coverageCoversCompleteFile($receipt)) {
            $receipt['complete'] = true;
        }

        return $receipt;
    }

    /**
     * @param array<int, array{0:int, 1:int}> $coverage
     * @return array<int, array{0:int, 1:int}>
     */
    private function mergeLineCoverage(array $coverage): array
    {
        usort($coverage, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($coverage as [$start, $end]) {
            if ($start < 1 || $end < $start) {
                continue;
            }
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /** @param array<string, mixed> $receipt */
    private function coverageCoversCompleteFile(array $receipt): bool
    {
        $totalLines = $this->totalLinesFromReceipt($receipt);
        $coverage = $this->lineCoverageFromReceipt($receipt);

        return $totalLines !== null
            && $totalLines >= 1
            && count($coverage) === 1
            && $coverage[0][0] === 1
            && $coverage[0][1] >= $totalLines;
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array<int, array{0:int, 1:int}>
     */
    private function lineCoverageFromReceipt(array $receipt): array
    {
        $coverage = $receipt['line_coverage'] ?? null;
        if (! is_array($coverage)) {
            return [];
        }

        $valid = [];
        foreach ($coverage as $range) {
            if (is_array($range)
                && is_int($range[0] ?? null)
                && is_int($range[1] ?? null)
                && $range[0] >= 1
                && $range[1] >= $range[0]) {
                $valid[] = [$range[0], $range[1]];
            }
        }

        return $valid;
    }

    /** @param array<string, mixed> $receipt */
    private function totalLinesFromReceipt(array $receipt): ?int
    {
        $totalLines = $receipt['total_lines'] ?? null;

        return is_int($totalLines) && $totalLines >= 0 ? $totalLines : null;
    }

    private function countContentLines(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        $lineBreaks = preg_match_all('/\r\n|\n|\r/', $content);

        return (is_int($lineBreaks) ? $lineBreaks : 0)
            + (preg_match('/(?:\r\n|\n|\r)$/', $content) === 1 ? 0 : 1);
    }

    private function revisionKey(string $filePath): string
    {
        return CanonicalPathResolver::resolve($filePath, $this->workingDirectory);
    }

    private function existingRevisionKey(string $filePath): ?string
    {
        $virtualKey = $this->virtualRevisionKey($filePath);
        if (isset($this->readFileState[$virtualKey]) || isset($this->pendingReadFileState[$virtualKey])) {
            return $virtualKey;
        }

        $canonicalKey = $this->revisionKey($filePath);

        return isset($this->readFileState[$canonicalKey]) || isset($this->pendingReadFileState[$canonicalKey])
            ? $canonicalKey
            : null;
    }

    /**
     * Sandbox paths are guest POSIX paths. Normalize them lexically without
     * consulting a same-named path or symlink on the host filesystem.
     */
    private function virtualRevisionKey(string $filePath): string
    {
        $path = $filePath;
        if (! str_starts_with($path, '/')) {
            $path = rtrim($this->workingDirectory, '/').'/'.$path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
