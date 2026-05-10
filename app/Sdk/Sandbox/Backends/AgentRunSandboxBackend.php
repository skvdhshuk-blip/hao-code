<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Sdk\Sandbox\AgentRun\AgentRunClient;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/** @api */
final class AgentRunSandboxBackend implements SandboxBackendInterface
{
    private AgentRunClient $client;

    public function __construct(private readonly SandboxConfig $config, ?AgentRunClient $client = null)
    {
        $this->client = $client ?? AgentRunClient::fromOptions($config->options);
    }

    public function stat(string $path): array
    {
        try {
            $stat = $this->client->stat($path);
        } catch (\Throwable) {
            return ['exists' => false];
        }
        return [
            'exists' => true,
            'isFile' => (bool) ($stat['isFile'] ?? $stat['type'] ?? false),
            'isDir' => (bool) ($stat['isDir'] ?? false),
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

    public function exec(string $command, ?string $cwd = null, int $timeoutMs = 120000): array
    {
        $result = $this->client->cmd($command, $cwd ?? $this->config->remoteCwd, max(1, (int) ceil($timeoutMs / 1000)));
        return [
            'stdout' => (string) ($result['stdout'] ?? $result['data']['stdout'] ?? $result['result']['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? $result['data']['stderr'] ?? $result['result']['stderr'] ?? ''),
            'exitCode' => (int) ($result['exitCode'] ?? $result['code'] ?? $result['data']['exitCode'] ?? $result['result']['exitCode'] ?? 0),
            'timedOut' => (bool) ($result['timedOut'] ?? false),
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

    public function rootLabel(): string
    {
        return 'agentrun:'.$this->client->sandboxId();
    }

    private function findPattern(string $cwd, string $pattern): string
    {
        $pattern = ltrim($pattern, './');
        return rtrim($cwd, '/').'/'.$pattern;
    }
}
