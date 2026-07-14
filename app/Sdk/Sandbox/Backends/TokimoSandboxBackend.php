<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxBinaryResolver;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/** @internal */
final class TokimoSandboxBackend implements SandboxBackendInterface
{
    private readonly LocalSandboxBackend $filesystem;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $responseBuffer = '';

    private string $bridgeStderr = '';

    private bool $started = false;

    private readonly string $binary;

    private readonly string $baseRootfs;

    private readonly string $vmDir;

    private readonly bool $ownsVmDir;

    public function __construct(private readonly SandboxConfig $config)
    {
        if (! str_starts_with($config->remoteCwd, '/') || $config->remoteCwd === '/') {
            throw new \InvalidArgumentException('Tokimo sandbox remoteCwd must be an absolute directory below the guest filesystem root.');
        }
        if ($config->root !== null && $this->isFilesystemRoot($config->root)) {
            throw new \InvalidArgumentException('The Tokimo sandbox workspace root cannot be the filesystem root.');
        }

        $this->binary = SandboxBinaryResolver::resolve($this->stringOption('binary'));
        $this->baseRootfs = $this->resolveDirectory($this->requiredStringOption('baseRootfs'), 'baseRootfs');

        $configuredVmDir = $this->stringOption('vmDir');
        $this->ownsVmDir = $configuredVmDir === null;
        $vmDir = $configuredVmDir ?? sys_get_temp_dir().'/haocode-tokimo-vm-'.bin2hex(random_bytes(8));
        if (! is_dir($vmDir) && ! mkdir($vmDir, 0755, true) && ! is_dir($vmDir)) {
            throw new \RuntimeException("Failed to create Tokimo VM directory: {$vmDir}");
        }
        $resolvedVmDir = realpath($vmDir);
        if ($resolvedVmDir === false) {
            throw new \RuntimeException("Failed to resolve Tokimo VM directory: {$vmDir}");
        }
        $this->vmDir = $resolvedVmDir;

        try {
            $this->filesystem = new LocalSandboxBackend($config);
        } catch (\Throwable $exception) {
            if ($this->ownsVmDir) {
                $this->removeDirectory($this->vmDir);
            }
            throw $exception;
        }
    }

    public function stat(string $path): array { return $this->filesystem->stat($path); }

    public function readFile(string $path): string { return $this->filesystem->readFile($path); }

    public function writeFile(string $path, string $content): void { $this->filesystem->writeFile($path, $content); }

    public function delete(string $path): void { $this->filesystem->delete($path); }

    public function list(string $path): array { return $this->filesystem->list($path); }

    public function glob(string $pattern, ?string $path = null): array { return $this->filesystem->glob($pattern, $path); }

    public function grep(string $pattern, ?string $path = null, ?string $glob = null, bool $caseInsensitive = false, int $limit = 250): array
    {
        return $this->filesystem->grep($pattern, $path, $glob, $caseInsensitive, $limit);
    }

    public function exec(string $command, ?string $cwd = null, int $timeoutMs = 120000): array
    {
        $this->start();
        $this->writeRequest([
            'op' => 'exec',
            'command' => $command,
            'cwd' => $cwd ?? $this->config->remoteCwd,
            'timeout_ms' => max(1, $timeoutMs),
        ]);
        $response = $this->readResponse(max(1000, $timeoutMs + 5000));
        $this->assertSuccessfulResponse($response, 'Tokimo command failed');

        $stdout = base64_decode((string) ($response['stdout_base64'] ?? ''), true);
        $stderr = base64_decode((string) ($response['stderr_base64'] ?? ''), true);
        if ($stdout === false || $stderr === false) {
            throw new \RuntimeException('Tokimo runner returned invalid base64 command output.');
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => (int) ($response['exit_code'] ?? -1),
            'timedOut' => (bool) ($response['timed_out'] ?? false),
        ];
    }

    public function upload(string $localPath, string $remotePath): void { $this->filesystem->upload($localPath, $remotePath); }

    public function download(string $remotePath, string $localPath): void { $this->filesystem->download($remotePath, $localPath); }

    public function close(): void
    {
        $this->closeRunnerProcess($this->started);

        $this->filesystem->close();
        if ($this->ownsVmDir && $this->config->cleanup === 'always') {
            $this->removeDirectory($this->vmDir);
        }
    }

    public function rootLabel(): string
    {
        return 'tokimo:'.$this->filesystem->rootLabel();
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }

        $command = str_ends_with(strtolower($this->binary), '.php')
            ? [PHP_BINARY, $this->binary]
            : [$this->binary];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname($this->baseRootfs), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new \RuntimeException("Failed to start Tokimo sandbox binary: {$this->binary}");
        }

        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        try {
            $this->writeRequest([
                'op' => 'start',
                'protocol_version' => 1,
                'config' => [
                    'user_data_name' => 'haocode',
                    'session_id' => 'haocode-'.bin2hex(random_bytes(8)),
                    'base_rootfs' => $this->baseRootfs,
                    'vm_dir' => $this->vmDir,
                    'workspace_host_path' => $this->workspaceHostPath(),
                    'remote_cwd' => $this->config->remoteCwd,
                    'memory_mb' => (int) ($this->config->options['memoryMb'] ?? 4096),
                    'cpu_count' => (int) ($this->config->options['cpuCount'] ?? 4),
                    'network' => (string) ($this->config->options['network'] ?? 'blocked'),
                ],
            ]);

            $timeout = max(1, (int) ($this->config->options['startupTimeoutSeconds'] ?? 30)) * 1000;
            $response = $this->readResponse($timeout);
            $this->assertSuccessfulResponse($response, 'Tokimo sandbox failed to start');
            $this->started = true;
        } catch (\Throwable $exception) {
            $this->closeRunnerProcess(false);
            throw $exception;
        }
    }

    private function closeRunnerProcess(bool $graceful): void
    {
        if (! is_resource($this->process)) {
            return;
        }
        if ($graceful) {
            try {
                $this->writeRequest(['op' => 'shutdown']);
                $this->readResponse(5000);
            } catch (\Throwable) {
                // Best-effort graceful shutdown; force termination follows.
            }
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $status = proc_get_status($this->process);
        if ($status['running'] ?? false) {
            proc_terminate($this->process, 15);
        }
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
        $this->started = false;
    }

    private function workspaceHostPath(): string
    {
        $parts = [];
        foreach (explode('/', $this->config->remoteCwd) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return rtrim($this->filesystem->rootLabel(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function writeRequest(array $request): void
    {
        if (! isset($this->pipes[0]) || ! is_resource($this->pipes[0])) {
            throw new \RuntimeException('Tokimo runner stdin is unavailable.');
        }
        $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $offset = 0;
        $length = strlen($json);
        while ($offset < $length) {
            $written = fwrite($this->pipes[0], substr($json, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed to write a complete request to the Tokimo runner.');
            }
            $offset += $written;
        }
        fflush($this->pipes[0]);
    }

    private function readResponse(int $timeoutMs): array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            $newline = strpos($this->responseBuffer, "\n");
            if ($newline !== false) {
                $line = substr($this->responseBuffer, 0, $newline);
                $this->responseBuffer = substr($this->responseBuffer, $newline + 1);
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new \RuntimeException('Tokimo runner returned a non-object response.');
                }
                return $decoded;
            }

            $read = [];
            if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
                $read[] = $this->pipes[1];
            }
            if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
                $read[] = $this->pipes[2];
            }
            if ($read === []) {
                break;
            }

            $remaining = max(0.001, $deadline - microtime(true));
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $write = null;
            $except = null;
            $selected = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new \RuntimeException('Failed while waiting for a Tokimo runner response.');
            }
            if ($selected === 0) {
                continue;
            }
            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $this->pipes[1]) {
                    $this->responseBuffer .= $chunk;
                } else {
                    $this->bridgeStderr = substr($this->bridgeStderr.$chunk, -65536);
                }
            }

            if (is_resource($this->process)) {
                $status = proc_get_status($this->process);
                if (! ($status['running'] ?? false) && $this->responseBuffer === '') {
                    throw new \RuntimeException('Tokimo runner exited before responding. '.$this->bridgeStderr);
                }
            }
        }

        throw new \RuntimeException('Timed out waiting for the Tokimo runner. '.$this->bridgeStderr);
    }

    private function assertSuccessfulResponse(array $response, string $prefix): void
    {
        if (($response['ok'] ?? false) === true) {
            return;
        }
        $message = (string) ($response['error'] ?? 'unknown runner error');
        throw new \RuntimeException($prefix.': '.$message);
    }

    private function requiredStringOption(string $name): string
    {
        $value = $this->stringOption($name);
        if ($value === null) {
            throw new \InvalidArgumentException("Tokimo sandbox requires option {$name}.");
        }
        return $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->config->options[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveDirectory(string $path, string $label): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new \InvalidArgumentException("Tokimo sandbox {$label} directory does not exist: {$path}");
        }
        return $resolved;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && ($file->isLink() || $file->isFile())) {
                @unlink($file->getPathname());
            } elseif ($file instanceof \SplFileInfo && $file->isDir()) {
                @rmdir($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = rtrim(str_replace('\\', '/', trim($path)), '/');

        return $normalized === ''
            || preg_match('/^[A-Za-z]:$/', $normalized) === 1
            || preg_match('#^//[^/]+/[^/]+$#', $normalized) === 1;
    }
}
