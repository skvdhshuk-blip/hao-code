<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Sdk\Sandbox\RevisionAwareSandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/**
 * Local sandbox backed by an operating-system isolation primitive.
 *
 * @internal
 */
final class NativeSandboxBackend implements SandboxBackendInterface, RevisionAwareSandboxBackendInterface
{
    private const MAX_OUTPUT_BYTES = 100_000;

    private readonly LocalSandboxBackend $filesystem;

    private readonly string $engine;

    public function __construct(private readonly SandboxConfig $config)
    {
        $this->engine = $this->selectEngine((string) ($config->options['engine'] ?? 'auto'));
        $this->filesystem = new LocalSandboxBackend($config);
    }

    public function stat(string $path): array
    {
        return $this->filesystem->stat($path);
    }

    public function readFile(string $path): string
    {
        return $this->filesystem->readFile($path);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->filesystem->writeFile($path, $content);
    }

    public function writeFileIfUnchanged(
        string $path,
        string $content,
        ?string $expectedSha256,
    ): void {
        $this->filesystem->writeFileIfUnchanged($path, $content, $expectedSha256);
    }

    public function delete(string $path): void
    {
        $this->filesystem->delete($path);
    }

    public function list(string $path): array
    {
        return $this->filesystem->list($path);
    }

    public function glob(string $pattern, ?string $path = null): array
    {
        return $this->filesystem->glob($pattern, $path);
    }

    public function grep(string $pattern, ?string $path = null, ?string $glob = null, bool $caseInsensitive = false, int $limit = 250): array
    {
        return $this->filesystem->grep($pattern, $path, $glob, $caseInsensitive, $limit);
    }

    public function exec(string $command, ?string $cwd = null, int $timeoutMs = 120000, ?callable $shouldAbort = null): array
    {
        $cwdRemote = $this->normalizeRemotePath($cwd ?? $this->config->remoteCwd);
        $cwdLocal = $this->localPath($cwdRemote);
        if (! is_dir($cwdLocal) && ! mkdir($cwdLocal, 0755, true) && ! is_dir($cwdLocal)) {
            throw new \RuntimeException("Failed to create native sandbox cwd: {$cwdRemote}");
        }

        $process = match ($this->engine) {
            'seatbelt' => $this->seatbeltCommand($command),
            'bubblewrap' => $this->bubblewrapCommand($command, $cwdRemote),
            default => throw new \LogicException("Unsupported native sandbox engine: {$this->engine}"),
        };

        return $this->run($process, $cwdLocal, $timeoutMs, $shouldAbort);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->filesystem->upload($localPath, $remotePath);
    }

    public function download(string $remotePath, string $localPath): void
    {
        $this->filesystem->download($remotePath, $localPath);
    }

    public function close(): void
    {
        $this->filesystem->close();
    }

    /** @internal */
    public function detach(): void
    {
        $this->filesystem->detach();
    }

    /**
     * @return array<string, mixed>
     * @internal
     */
    public function exportLease(): array
    {
        $lease = $this->filesystem->exportLease();
        $lease['provider'] = 'native';
        $lease['options'] = array_merge(
            is_array($lease['options'] ?? null) ? $lease['options'] : [],
            $this->config->options,
            ['engine' => $this->engine],
        );

        return $lease;
    }

    public function rootLabel(): string
    {
        return $this->engine.':'.$this->filesystem->rootLabel();
    }

    private function selectEngine(string $requested): string
    {
        $supported = match (PHP_OS_FAMILY) {
            'Darwin' => ['seatbelt' => '/usr/bin/sandbox-exec'],
            'Linux' => ['bubblewrap' => $this->findExecutable('bwrap')],
            default => [],
        };

        if ($requested !== 'auto') {
            if (! array_key_exists($requested, $supported) || $supported[$requested] === null || ! is_executable($supported[$requested])) {
                throw new \RuntimeException("Native sandbox engine '{$requested}' is unavailable on ".PHP_OS_FAMILY.'.');
            }

            return $requested;
        }

        foreach ($supported as $engine => $executable) {
            if ($executable !== null && is_executable($executable)) {
                return $engine;
            }
        }

        throw new \RuntimeException('No supported native sandbox engine is available. Install bubblewrap on Linux; macOS requires /usr/bin/sandbox-exec.');
    }

    /** @return string[] */
    private function seatbeltCommand(string $command): array
    {
        $root = $this->filesystem->rootLabel();
        $policyRoot = realpath($root) ?: $root;
        $profilePath = $root.'/.haocode-seatbelt.sb';
        $networkRule = $this->networkAllowed() ? '(allow network*)' : '(deny network*)';
        $profile = <<<SB
(version 1)
(deny default)
(allow process-exec process-fork)
(allow sysctl-read)
(allow file-read*)
(deny file-read*
    (require-all
        (subpath "/Users")
        (require-not (subpath "{$this->escapeSeatbeltString($policyRoot)}"))))
(deny file-read*
    (require-all
        (subpath "/private/tmp")
        (require-not (subpath "{$this->escapeSeatbeltString($policyRoot)}"))))
(deny file-read*
    (require-all
        (subpath "/private/var/folders")
        (require-not (subpath "{$this->escapeSeatbeltString($policyRoot)}"))))
(allow file-write*
    (subpath "{$this->escapeSeatbeltString($policyRoot)}")
    (literal "/dev/null")
    (literal "/dev/tty"))
{$networkRule}
SB;
        if (file_put_contents($profilePath, $profile) === false) {
            throw new \RuntimeException('Failed to create the Seatbelt sandbox profile.');
        }

        return ['/usr/bin/sandbox-exec', '-f', $profilePath, '/bin/sh', '-c', $command];
    }

    /** @return string[] */
    private function bubblewrapCommand(string $command, string $cwdRemote): array
    {
        $binary = $this->findExecutable('bwrap');
        if ($binary === null) {
            throw new \RuntimeException('bubblewrap is unavailable.');
        }

        $args = [
            $binary, '--die-with-parent', '--new-session', '--unshare-user',
            '--unshare-pid', '--unshare-ipc', '--unshare-uts', '--clearenv',
            '--setenv', 'PATH', '/usr/local/bin:/usr/bin:/bin',
            '--setenv', 'HOME', $this->config->remoteCwd,
            '--setenv', 'TMPDIR', '/tmp',
            '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
        ];

        if (! $this->networkAllowed()) {
            $args[] = '--unshare-net';
        }

        foreach (['/usr', '/bin', '/sbin', '/lib', '/lib64'] as $systemPath) {
            if (file_exists($systemPath)) {
                array_push($args, '--ro-bind', $systemPath, $systemPath);
            }
        }
        foreach (['/etc/ssl', '/etc/ca-certificates', '/etc/resolv.conf', '/etc/hosts'] as $systemPath) {
            if (file_exists($systemPath)) {
                array_push($args, '--ro-bind', $systemPath, $systemPath);
            }
        }

        array_push($args, '--bind', $this->filesystem->rootLabel(), '/sandbox');
        $workspaceLocal = $this->localPath($this->config->remoteCwd);
        array_push($args, '--bind', $workspaceLocal, $this->config->remoteCwd);
        array_push($args, '--chdir', $cwdRemote, '/bin/sh', '-c', $command);

        return $args;
    }

    /**
     * @param string[] $command
     * @param  callable(): bool|null  $shouldAbort
     * @return array{stdout: string, stderr: string, exitCode: int, timedOut: bool, aborted?: bool, outputLimited?: bool}
     */
    private function run(array $command, string $cwd, int $timeoutMs, ?callable $shouldAbort = null): array
    {
        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $this->localPath($this->config->remoteCwd),
            'TMPDIR' => $this->filesystem->rootLabel().'/tmp',
            'HAOCODE_SANDBOX_ROOT' => $this->filesystem->rootLabel(),
        ];
        if (! is_dir($environment['TMPDIR'])) {
            mkdir($environment['TMPDIR'], 0755, true);
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new \RuntimeException('Failed to start native sandbox command.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $deadline = microtime(true) + (max(1, $timeoutMs) / 1000);
        $timedOut = false;
        $aborted = false;
        $outputLimited = false;
        $exitCode = -1;
        $pid = (int) (proc_get_status($process)['pid'] ?? 0);

        do {
            if ($shouldAbort !== null && $shouldAbort()) {
                $aborted = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }
            if ($this->captureOutput($pipes[1], $stdout, $stdoutTruncated)
                || $this->captureOutput($pipes[2], $stderr, $stderrTruncated)) {
                $outputLimited = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
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
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }
            usleep(10000);
        } while (true);

        if (! $outputLimited && ($this->captureOutput($pipes[1], $stdout, $stdoutTruncated)
            || $this->captureOutput($pipes[2], $stderr, $stderrTruncated))) {
            $outputLimited = true;
            \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($outputLimited) {
            $exitCode = 1;
        } elseif ($exitCode < 0 && $closedExitCode >= 0) {
            $exitCode = $closedExitCode;
        }
        if ($stdoutTruncated) {
            $stdout .= "\n[stdout truncated at ".self::MAX_OUTPUT_BYTES.' bytes]';
        }
        if ($stderrTruncated) {
            $stderr .= "\n[stderr truncated at ".self::MAX_OUTPUT_BYTES.' bytes]';
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $aborted ? 130 : ($timedOut ? 124 : $exitCode),
            'timedOut' => $timedOut,
            'aborted' => $aborted,
            'outputLimited' => $outputLimited,
        ];
    }

    /** @param resource $stream */
    private function captureOutput($stream, string &$output, bool &$truncated): bool
    {
        $chunk = stream_get_contents($stream);
        if ($chunk === false || $chunk === '') {
            return false;
        }

        $remaining = self::MAX_OUTPUT_BYTES - strlen($output);
        if ($remaining > 0) {
            $output .= substr($chunk, 0, $remaining);
        }
        if (strlen($chunk) > max(0, $remaining)) {
            $truncated = true;

            return true;
        }

        return false;
    }

    private function networkAllowed(): bool
    {
        return ($this->config->options['network'] ?? 'blocked') === 'allow-all';
    }

    private function normalizeRemotePath(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            $path = rtrim($this->config->remoteCwd, '/').'/'.$path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    private function localPath(string $remotePath): string
    {
        return rtrim($this->filesystem->rootLabel(), '/').$this->normalizeRemotePath($remotePath);
    }

    private function escapeSeatbeltString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function findExecutable(string $name): ?string
    {
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        foreach ($paths as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        foreach (['/usr/bin/'.$name, '/usr/local/bin/'.$name, '/opt/homebrew/bin/'.$name] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
