<?php

declare(strict_types=1);

namespace HaoCode\Services\Git;

use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Support\Runtime\ProcessSupervisor;

/**
 * Minimal, argv-based Git runner for internal read-only operations.
 *
 * @internal
 */
final class HardenedGitRunner
{
    private const TIMEOUT_SECONDS = 2.0;
    private const MAX_OUTPUT_BYTES = 60_000;

    public function diffForFile(string $filePath): string
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            return '';
        }

        $root = $this->gitRoot($dir);
        try {
            $resolvedFile = CanonicalPathResolver::resolve($filePath, $dir);
        } catch (\Throwable) {
            return '';
        }
        if ($root === null || ! CanonicalPathResolver::isWithin($resolvedFile, $root)) {
            return '';
        }

        $relative = ltrim(substr($resolvedFile, strlen(rtrim($root, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
        if ($relative === '' || str_contains($relative, "\0")) {
            return '';
        }

        $result = $this->runGit($root, [
            '--no-pager',
            'diff',
            '--no-ext-diff',
            '--no-textconv',
            '--no-renames',
            '--',
            $relative,
        ]);

        if ($result['timedOut'] || $result['truncated']) {
            return '';
        }

        // git diff returns 0 whether or not differences are present.
        return $result['exitCode'] === 0 ? trim($result['stdout']) : '';
    }

    private function gitRoot(string $directory): ?string
    {
        $result = $this->runGit($directory, [
            '--no-pager',
            'rev-parse',
            '--show-toplevel',
        ]);
        if ($result['exitCode'] !== 0 || $result['timedOut'] || $result['truncated']) {
            return null;
        }

        $root = trim($result['stdout']);
        if ($root === '') {
            return null;
        }

        try {
            return CanonicalPathResolver::resolve($root, $directory);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $argv
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
     */
    public function runGit(string $cwd, array $argv, ?float $timeoutSeconds = null): array
    {
        foreach ($argv as $arg) {
            if (! is_string($arg) || str_contains($arg, "\0")) {
                return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'Invalid git argument.', 'timedOut' => false, 'truncated' => false];
            }
        }

        return $this->run(array_merge(['git'], $argv), $cwd, $timeoutSeconds ?? self::TIMEOUT_SECONDS);
    }

    /**
     * @param list<string> $argv
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, truncated: bool}
     */
    private function run(array $argv, string $cwd, float $timeoutSeconds): array
    {
        $env = $this->cleanEnvironment();
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            return ['exitCode' => -1, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'truncated' => false];
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        $deadline = microtime(true) + max(0.001, $timeoutSeconds);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $truncated = false;

        while (true) {
            foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $name) {
                if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                    continue;
                }
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($name === 'stdout') {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
                if (strlen($stdout) + strlen($stderr) > self::MAX_OUTPUT_BYTES) {
                    $truncated = true;
                    ProcessSupervisor::terminateTree($pid);
                    break 2;
                }
            }

            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                ProcessSupervisor::terminateTree($pid);
                break;
            }

            usleep(20_000);
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                $chunk = stream_get_contents($pipes[$index]);
                if (is_string($chunk) && $chunk !== '') {
                    if ($index === 1) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
                fclose($pipes[$index]);
            }
        }

        $exitCode = proc_close($process);

        return [
            'exitCode' => $timedOut || $truncated ? -1 : $exitCode,
            'stdout' => substr($stdout, 0, self::MAX_OUTPUT_BYTES),
            'stderr' => substr($stderr, 0, self::MAX_OUTPUT_BYTES),
            'timedOut' => $timedOut,
            'truncated' => $truncated,
        ];
    }

    /** @return array<string, string> */
    private function cleanEnvironment(): array
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'LANG' => getenv('LANG') ?: 'C',
            'LC_ALL' => 'C',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null',
            'GIT_ATTR_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_OPTIONAL_LOCKS' => '0',
        ];

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $env['HOME'] = $home;
        }

        return $env;
    }
}
