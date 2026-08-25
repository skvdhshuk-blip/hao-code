<?php

declare(strict_types=1);

namespace HaoCode\Scripts;

use RuntimeException;

final class PhpFileSizeCheck
{
    public const MAX_LINES = 500;

    /**
     * @return array{files: int, issues: list<string>}
     */
    public static function audit(string $projectRoot, int $maxLines = self::MAX_LINES): array
    {
        if ($maxLines < 1) {
            throw new RuntimeException('Maximum line count must be at least 1.');
        }

        $projectRoot = realpath($projectRoot) ?: throw new RuntimeException(
            "Project root does not exist: {$projectRoot}",
        );
        $files = self::trackedPhpFiles($projectRoot);
        $issues = [];

        foreach ($files as $relativePath) {
            $path = $projectRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (! is_file($path)) {
                continue;
            }

            $source = file_get_contents($path);
            if (! is_string($source)) {
                throw new RuntimeException("Unable to read tracked PHP file: {$relativePath}");
            }

            $lines = self::countPhysicalLines($source);
            if ($lines > $maxLines) {
                $issues[] = "{$relativePath} has {$lines} lines (max {$maxLines}).";
            }
        }

        sort($issues);

        return [
            'files' => count(array_filter(
                $files,
                static fn (string $path): bool => is_file(
                    $projectRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path),
                ),
            )),
            'issues' => $issues,
        ];
    }

    public static function countPhysicalLines(string $source): int
    {
        if ($source === '') {
            return 0;
        }

        return substr_count($source, "\n") + (str_ends_with($source, "\n") ? 0 : 1);
    }

    /** @return list<string> */
    private static function trackedPhpFiles(string $projectRoot): array
    {
        $command = ['git', '-C', $projectRoot, 'ls-files', '-z', '--', '*.php'];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start git while checking PHP file sizes.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! is_string($stdout)) {
            $detail = is_string($stderr) ? trim($stderr) : '';
            throw new RuntimeException(
                'Unable to list tracked PHP files'.($detail !== '' ? ": {$detail}" : '.'),
            );
        }

        $files = array_values(array_filter(
            explode("\0", $stdout),
            static fn (string $path): bool => $path !== '',
        ));
        sort($files);

        return $files;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        $result = PhpFileSizeCheck::audit(dirname(__DIR__));
        if ($result['issues'] !== []) {
            fwrite(STDERR, "PHP file size check failed:\n- ".implode("\n- ", $result['issues'])."\n");
            exit(1);
        }

        fwrite(
            STDOUT,
            "OK: {$result['files']} tracked PHP files are at most ".PhpFileSizeCheck::MAX_LINES." lines.\n",
        );
    } catch (\Throwable $exception) {
        fwrite(STDERR, "PHP file size check failed: {$exception->getMessage()}\n");
        exit(1);
    }
}
