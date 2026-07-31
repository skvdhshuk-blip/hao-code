<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Sdk\Sandbox\AgentRun\AgentRunClient;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/** @api */
final class AgentRunSandboxBackend implements SandboxBackendInterface
{
    private const MAX_EXEC_OUTPUT_BYTES = 100_000;

    private AgentRunClient $client;

    public function __construct(private readonly SandboxConfig $config, ?AgentRunClient $client = null)
    {
        $this->client = $client ?? AgentRunClient::fromOptions($config->options);
    }

    public function stat(string $path): array
    {
        try {
            $stat = $this->client->stat($path);
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            // Only map explicit not-found signals; auth/rate-limit/5xx stay exceptions.
            if (str_contains($message, '404')
                || str_contains($message, 'not found')
                || str_contains($message, 'does not exist')
                || str_contains($message, 'no such file')) {
                return ['exists' => false];
            }
            throw $e;
        }

        $type = $stat['type'] ?? null;
        $isFile = array_key_exists('isFile', $stat)
            ? (bool) $stat['isFile']
            : (is_string($type) && strcasecmp($type, 'file') === 0);
        $isDir = array_key_exists('isDir', $stat)
            ? (bool) $stat['isDir']
            : (is_string($type) && (strcasecmp($type, 'dir') === 0 || strcasecmp($type, 'directory') === 0));

        return [
            'exists' => true,
            'isFile' => $isFile,
            'isDir' => $isDir,
            'size' => (int) ($stat['size'] ?? 0),
            'mtime' => (int) ($stat['mtime'] ?? $stat['modifiedTime'] ?? 0),
        ];
    }

    public function readFile(string $path): string
    {
        return $this->client->readFile($path);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->client->writeFile($path, $content);
    }

    public function delete(string $path): void
    {
        $this->client->remove($path);
    }

    public function list(string $path): array
    {
        $result = $this->client->list($path);
        $items = $result['items'] ?? $result['files'] ?? $result['data']['items'] ?? $result['data'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $paths = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $paths[] = $item;
            } elseif (is_array($item) && is_string($item['path'] ?? null)) {
                $paths[] = $item['path'];
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    public function glob(string $pattern, ?string $path = null): array
    {
        $cwd = $path ?? $this->config->remoteCwd;
        $cmd = 'find '.escapeshellarg($cwd).' -type f -path '.escapeshellarg($this->findPattern($cwd, $pattern)).' | head -1000';
        $result = $this->exec($cmd, $cwd, 30000);
        if ($result['exitCode'] !== 0) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode("\n", $result['stdout']))));
    }

    public function grep(string $pattern, ?string $path = null, ?string $glob = null, bool $caseInsensitive = false, int $limit = 250): array
    {
        $cwd = $path ?? $this->config->remoteCwd;
        $cmd = 'grep -RIn'.($caseInsensitive ? 'i' : '').' -- '.escapeshellarg($pattern).' '.escapeshellarg($cwd).' | head -'.max(1, $limit);
        $result = $this->exec($cmd, $cwd, 30000);
        if ($result['exitCode'] !== 0 && trim($result['stdout']) === '') {
            return [];
        }
        $matches = [];
        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }
            [$file, $lineNo, $text] = array_pad(explode(':', $line, 3), 3, '');
            if ($glob !== null && $glob !== '' && ! fnmatch($glob, basename($file))) {
                continue;
            }
            $matches[] = ['file' => $file, 'line' => (int) $lineNo, 'text' => $text];
        }
        return $matches;
    }

    public function exec(string $command, ?string $cwd = null, int $timeoutMs = 120000, ?callable $shouldAbort = null): array
    {
        if ($shouldAbort !== null && $shouldAbort()) {
            return ['stdout' => '', 'stderr' => '', 'exitCode' => 130, 'timedOut' => false, 'aborted' => true, 'outputLimited' => false];
        }

        $result = $this->client->cmd($command, $cwd ?? $this->config->remoteCwd, max(1, (int) ceil($timeoutMs / 1000)));
        $exitCode = $result['exitCode']
            ?? $result['code']
            ?? $result['data']['exitCode']
            ?? $result['result']['exitCode']
            ?? null;
        if (! is_numeric($exitCode)) {
            throw new \RuntimeException('AgentRun process response is missing a numeric exitCode.');
        }

        $stdout = (string) ($result['stdout'] ?? $result['data']['stdout'] ?? $result['result']['stdout'] ?? '');
        $stderr = (string) ($result['stderr'] ?? $result['data']['stderr'] ?? $result['result']['stderr'] ?? '');
        $outputLimited = (bool) ($result['outputLimited'] ?? $result['output_limited'] ?? $result['data']['outputLimited'] ?? $result['result']['outputLimited'] ?? false);
        $stdoutLimited = $this->capExecOutput($stdout, 'stdout');
        $stderrLimited = $this->capExecOutput($stderr, 'stderr');
        $outputLimited = $outputLimited || $stdoutLimited || $stderrLimited;

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $outputLimited ? 1 : (int) $exitCode,
            'timedOut' => (bool) ($result['timedOut'] ?? $result['data']['timedOut'] ?? false),
            'aborted' => false,
            'outputLimited' => $outputLimited,
        ];
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read local file: {$localPath}");
        }
        $this->writeFile($remotePath, $content);
    }

    public function download(string $remotePath, string $localPath): void
    {
        $content = $this->readFile($remotePath);
        $dir = dirname($localPath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create local directory: {$dir}");
        }
        if (file_put_contents($localPath, $content) === false) {
            throw new \RuntimeException("Failed to write local file: {$localPath}");
        }
    }

    public function close(): void {}

    /** @internal */
    public function detach(): void
    {
        // Remote sandbox identity is retained server-side; nothing local to delete.
    }

    /**
     * Durable lease identity only — never credentials.
     *
     * @return array<string, mixed>
     * @internal
     */
    public function exportLease(): array
    {
        $resolvedId = $this->client->sandboxId();

        return [
            'version' => 1,
            'provider' => 'agentrun',
            'identity' => [
                'sandbox_id' => $resolvedId,
                'remote_cwd' => $this->config->remoteCwd,
            ],
            // Policy is re-applied from the caller config on resume.
            'mode' => $this->config->mode,
            'remote_cwd' => $this->config->remoteCwd,
            'sync' => $this->config->sync,
            'cleanup' => $this->config->cleanup,
            'root' => null,
            'owns_root' => false,
            'exclude' => $this->config->exclude,
            // Non-secret options only (auth re-read from env/caller on resume).
            'options' => array_filter([
                'sandboxId' => $resolvedId,
                'region' => $this->config->options['region'] ?? null,
                'endpoint' => $this->config->options['endpoint'] ?? null,
                'timeoutSeconds' => $this->config->options['timeoutSeconds'] ?? null,
                'accountId' => $this->config->options['accountId'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        ];
    }

    public function rootLabel(): string
    {
        return 'agentrun:'.$this->client->sandboxId();
    }

    private function findPattern(string $cwd, string $pattern): string
    {
        $pattern = ltrim($pattern, './');
        return rtrim($cwd, '/').'/'.$pattern;
    }

    private function capExecOutput(string &$output, string $streamName): bool
    {
        if (strlen($output) <= self::MAX_EXEC_OUTPUT_BYTES) {
            return false;
        }

        $output = substr($output, 0, self::MAX_EXEC_OUTPUT_BYTES)
            ."\n\n[{$streamName} truncated at ".self::MAX_EXEC_OUTPUT_BYTES.' bytes]';

        return true;
    }
}
