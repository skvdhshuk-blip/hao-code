<?php

namespace HaoCode\Sdk\AgentRun;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * REST client for Alibaba Cloud AgentRun Code Interpreter data API.
 *
 * @api
 */
final class AgentRunSandboxClient
{
    private HttpClientInterface $http;
    private AgentRunRamSigner $signer;
    private ?string $resolvedSandboxId = null;

    public function __construct(
        private readonly AgentRunSandboxConfig $config,
        ?HttpClientInterface $httpClient = null,
        ?AgentRunRamSigner $signer = null,
    ) {
        $this->http = $httpClient ?? HttpClient::create([
            'timeout' => $config->timeoutSeconds,
            'max_duration' => max($config->timeoutSeconds + 5, 35),
        ]);
        $this->signer = $signer ?? new AgentRunRamSigner();
    }

    public function config(): AgentRunSandboxConfig
    {
        return $this->config;
    }

    public function sandboxId(): string
    {
        if ($this->resolvedSandboxId !== null) {
            return $this->resolvedSandboxId;
        }

        if ($this->config->sandboxId !== '') {
            return $this->resolvedSandboxId = $this->config->sandboxId;
        }

        if ($this->config->templateName === null || $this->config->templateName === '') {
            throw new \RuntimeException('AgentRun sandboxId is empty. Provide sandboxId or templateName to create one.');
        }

        $created = $this->request('POST', '/sandboxes', [
            'templateName' => $this->config->templateName,
        ], sandboxScoped: false);

        $id = $created['sandboxId'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('AgentRun sandbox creation did not return sandboxId.');
        }

        return $this->resolvedSandboxId = $id;
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /** @return array<string, mixed> */
    public function readFile(string $path): array
    {
        return $this->request('GET', '/files', query: ['path' => $path]);
    }

    /** @return array<string, mixed> */
    public function writeFile(string $path, string $content): array
    {
        return $this->request('POST', '/files', [
            'path' => $path,
            'content' => $content,
            'mode' => '644',
            'encoding' => 'utf-8',
            'createDir' => true,
        ]);
    }

    /** @return array<string, mixed> */
    public function stat(string $path): array
    {
        return $this->request('GET', '/filesystem/stat', query: ['path' => $path]);
    }

    /** @return array<string, mixed> */
    public function listDirectory(?string $path = null, ?int $depth = null): array
    {
        $query = [];
        if ($path !== null) {
            $query['path'] = $path;
        }
        if ($depth !== null) {
            $query['depth'] = $depth;
        }

        return $this->request('GET', '/filesystem', query: $query);
    }

    /** @return array<string, mixed> */
    public function mkdir(string $path): array
    {
        return $this->request('POST', '/filesystem/mkdir', [
            'path' => $path,
            'parents' => true,
            'mode' => '0755',
        ]);
    }

    /** @return array<string, mixed> */
    public function remove(string $path): array
    {
        return $this->request('POST', '/filesystem/remove', ['path' => $path]);
    }

    /** @return array<string, mixed> */
    public function cmd(string $command, ?string $cwd = null, ?int $timeout = 30): array
    {
        $data = [
            'command' => $command,
            'cwd' => $cwd ?? $this->config->remoteCwd,
        ];
        if ($timeout !== null) {
            $data['timeout'] = $timeout;
        }

        return $this->request('POST', '/processes/cmd', $data);
    }

    /** @return array<string, mixed> */
    public function executeCode(string $code, ?string $contextId = null, string $language = 'python', ?int $timeout = 30): array
    {
        $data = ['code' => $code, 'language' => $language];
        if ($contextId !== null) {
            $data['contextId'] = $contextId;
        }
        if ($timeout !== null) {
            $data['timeout'] = $timeout;
        }

        return $this->request('POST', '/contexts/execute', $data);
    }

    /**
     * Copy a local directory snapshot into the sandbox using the REST file API.
     * This reads local files but never writes to the local filesystem.
     *
     * @api
     *
     * @param string[] $exclude Directory or file-name patterns to skip.
     * @return array{files: int, skipped: int}
     */
    public function syncDirectory(string $localDir, ?string $remoteDir = null, array $exclude = []): array
    {
        $root = realpath($localDir);
        if ($root === false || ! is_dir($root)) {
            throw new \InvalidArgumentException("Local directory does not exist: {$localDir}");
        }

        $remoteRoot = rtrim($remoteDir ?? $this->config->remoteCwd, '/');
        $exclude = array_merge([
            '.git', '.svn', '.hg', 'node_modules', 'vendor', '.idea', '.vscode',
            '.DS_Store', '__pycache__', 'storage', 'var/cache',
        ], $exclude);

        $files = 0;
        $skipped = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $localPath = $file->getPathname();
            $relative = ltrim(str_replace($root, '', $localPath), DIRECTORY_SEPARATOR);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($this->isExcluded($relative, $exclude) || $file->getSize() > 1024 * 1024) {
                $skipped++;
                continue;
            }

            $content = file_get_contents($localPath);
            if ($content === false || str_contains($content, "\0")) {
                $skipped++;
                continue;
            }

            $this->writeFile($remoteRoot.'/'.$relative, $content);
            $files++;
        }

        return ['files' => $files, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $data = null, array $query = [], bool $sandboxScoped = true): array
    {
        $url = $this->url($path, $query, $sandboxScoped);
        $contentType = $data !== null ? 'application/json' : null;
        $headers = $this->signer->sign($url, $method, $this->config, $contentType);
        $headers['User-Agent'] = 'HaoCode-AgentRun/1.0';
        if ($this->config->parentId !== null && $this->config->parentId !== '') {
            $headers['X-Acs-Parent-Id'] = $this->config->parentId;
        }
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        $options = ['headers' => $headers];
        if ($data !== null) {
            $options['json'] = $data;
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        $decoded = [];
        if ($body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? $body ?: 'AgentRun request failed.';
            throw new \RuntimeException("AgentRun HTTP {$status}: {$message}");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $query */
    private function url(string $path, array $query, bool $sandboxScoped): string
    {
        $base = $this->config->dataEndpoint();
        $prefix = $sandboxScoped ? '/sandboxes/'.$this->sandboxId() : '';
        $url = $base.$prefix.'/'.ltrim($path, '/');

        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /** @param string[] $patterns */
    private function isExcluded(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($relativePath === $pattern || str_starts_with($relativePath, rtrim($pattern, '/').'/')) {
                return true;
            }
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($relativePath))) {
                return true;
            }
        }

        return false;
    }
}
