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

    /** @param callable(): bool|null $shouldAbort */
    public function diffForFile(string $filePath, ?callable $shouldAbort = null): string
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            return '';
        }

        $root = $this->gitRoot($dir, $shouldAbort);
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

        // A repository-local filter.clean/process command is executable code,
        // just like diff.external and textconv.  Git's --no-ext-diff and
        // --no-textconv flags do not disable filters used while converting the
        // working tree.  Refuse the supplemental diff rather than allowing an
        // untrusted repository to execute a helper; the file mutation itself
        // remains successful and the caller can still show its own summary.
        if ($this->hasExternalFilter($root, $relative, $shouldAbort)) {
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
        ], shouldAbort: $shouldAbort);

        if ($result['timedOut'] || $result['truncated'] || $result['aborted']) {
            return '';
        }

        // git diff returns 0 whether or not differences are present.
        return $result['exitCode'] === 0 ? trim($result['stdout']) : '';
    }

    /** @param callable(): bool|null $shouldAbort */
    private function gitRoot(string $directory, ?callable $shouldAbort = null): ?string
    {
        $result = $this->runGit($directory, [
            '--no-pager',
            'rev-parse',
            '--show-toplevel',
        ], shouldAbort: $shouldAbort);
        if ($result['exitCode'] !== 0 || $result['timedOut'] || $result['truncated'] || $result['aborted']) {
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
     * @param callable(): bool|null $shouldAbort
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, aborted: bool, truncated: bool}
     */
    public function runGit(
        string $cwd,
        array $argv,
        ?float $timeoutSeconds = null,
        ?callable $shouldAbort = null,
    ): array
    {
        foreach ($argv as $arg) {
            if (! is_string($arg) || str_contains($arg, "\0")) {
                return ['exitCode' => -1, 'stdout' => '', 'stderr' => 'Invalid git argument.', 'timedOut' => false, 'aborted' => false, 'truncated' => false];
            }
        }

        // These config overrides apply to every internal Git query, including
        // status/rev-parse calls used by worktree management.  Without them a
        // repository-local fsmonitor hook can run arbitrary code even when no
        // diff is being requested.
        $safeConfig = [
            '-c', 'core.fsmonitor=false',
            '-c', 'core.hooksPath='.(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'),
        ];

        return $this->run(
            array_merge(['git'], $safeConfig, $argv),
            $cwd,
            $timeoutSeconds ?? self::TIMEOUT_SECONDS,
            $shouldAbort,
        );
    }

    /** @param callable(): bool|null $shouldAbort */
    private function hasExternalFilter(string $root, string $relative, ?callable $shouldAbort): bool
    {
        $result = $this->runGit($root, [
            '--no-pager',
            'check-attr',
            '--all',
            '--',
            $relative,
        ], timeoutSeconds: 1.0, shouldAbort: $shouldAbort);

        if ($result['aborted'] || $result['timedOut'] || $result['truncated']) {
            return true;
        }
        if ($result['exitCode'] !== 0) {
            // A failed attribute query must not be followed by a potentially
            // executable diff. Treat the supplemental diff as unavailable.
            return true;
        }

        foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
            if (preg_match('/:\s*filter:\s*(\S.*)$/i', $line, $matches) !== 1) {
                continue;
            }

            $value = trim($matches[1]);
            if ($value !== '' && strcasecmp($value, 'unspecified') !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $argv
     * @param callable(): bool|null $shouldAbort
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, aborted: bool, truncated: bool}
     */
    private function run(array $argv, string $cwd, float $timeoutSeconds, ?callable $shouldAbort): array
    {
        $env = $this->cleanEnvironment();
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if ($shouldAbort !== null && $shouldAbort()) {
            return ['exitCode' => 130, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'aborted' => true, 'truncated' => false];
        }
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);
        if (! is_resource($process)) {
            return ['exitCode' => -1, 'stdout' => '', 'stderr' => '', 'timedOut' => false, 'aborted' => false, 'truncated' => false];
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
        $exitCode = -1;
        $timedOut = false;
        $aborted = false;
        $truncated = false;

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                $aborted = true;
                ProcessSupervisor::terminateTree($pid);
                break;
            }

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
                $exitCode = ($status['signaled'] ?? false)
                    ? 128 + (int) ($status['termsig'] ?? 0)
                    : (int) ($status['exitcode'] ?? -1);
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

        $closed = proc_close($process);
        if ($exitCode < 0 && ! $timedOut && ! $truncated) {
            $exitCode = $closed;
        }

        return [
            'exitCode' => $aborted ? 130 : ($timedOut || $truncated ? -1 : $exitCode),
            'stdout' => substr($stdout, 0, self::MAX_OUTPUT_BYTES),
            'stderr' => substr($stderr, 0, self::MAX_OUTPUT_BYTES),
            'timedOut' => $timedOut,
            'aborted' => $aborted,
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
