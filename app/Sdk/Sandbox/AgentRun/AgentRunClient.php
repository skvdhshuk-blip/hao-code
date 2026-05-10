<?php

namespace HaoCode\Sdk\Sandbox\AgentRun;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @internal */
final class AgentRunClient
{
    private HttpClientInterface $http;
    private ?string $resolvedSandboxId = null;

    public function __construct(
        private readonly string $accountId,
        private readonly ?string $sandboxId = null,
        private readonly ?string $templateName = null,
        private readonly ?string $apiKey = null,
        private readonly string $region = 'cn-hangzhou',
        private readonly ?string $endpoint = null,
        private readonly int $timeoutSeconds = 30,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->http = $httpClient ?? HttpClient::create([
            'timeout' => $timeoutSeconds,
            'max_duration' => max($timeoutSeconds + 5, 35),
        ]);
    }

    public static function fromOptions(array $options, ?HttpClientInterface $httpClient = null): self
    {
        $accountId = (string) ($options['accountId'] ?? getenv('AGENTRUN_ACCOUNT_ID') ?: getenv('ALIBABA_CLOUD_ACCOUNT_ID') ?: '');
        if ($accountId === '') {
            throw new \InvalidArgumentException('AgentRun sandbox requires accountId or AGENTRUN_ACCOUNT_ID.');
        }

        return new self(
            accountId: $accountId,
            sandboxId: isset($options['sandboxId']) ? (string) $options['sandboxId'] : (getenv('AGENTRUN_SANDBOX_ID') ?: null),
            templateName: isset($options['templateName']) ? (string) $options['templateName'] : (getenv('AGENTRUN_TEMPLATE_NAME') ?: null),
            apiKey: isset($options['apiKey']) ? (string) $options['apiKey'] : (getenv('AGENTRUN_API_KEY') ?: null),
            region: (string) ($options['region'] ?? getenv('AGENTRUN_REGION') ?: getenv('ALIBABA_CLOUD_REGION_ID') ?: 'cn-hangzhou'),
            endpoint: isset($options['endpoint']) ? (string) $options['endpoint'] : (getenv('AGENTRUN_DATA_ENDPOINT') ?: null),
            timeoutSeconds: (int) ($options['timeoutSeconds'] ?? getenv('AGENTRUN_TIMEOUT_SECONDS') ?: 30),
            httpClient: $httpClient,
        );
    }

    public function sandboxId(): string
    {
        if ($this->resolvedSandboxId !== null) {
            return $this->resolvedSandboxId;
        }
        if ($this->sandboxId !== null && $this->sandboxId !== '') {
            return $this->resolvedSandboxId = $this->sandboxId;
        }
        if ($this->templateName === null || $this->templateName === '') {
            throw new \RuntimeException('AgentRun sandboxId is empty. Provide sandboxId or templateName.');
        }

        $created = $this->request('POST', '/sandboxes', ['templateName' => $this->templateName], sandboxScoped: false);
        $id = $created['sandboxId'] ?? $created['data']['sandboxId'] ?? $created['result']['sandboxId'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('AgentRun sandbox creation did not return sandboxId.');
        }

        return $this->resolvedSandboxId = $id;
    }

    public function readFile(string $path): string
    {
        return $this->extractContent($this->request('GET', '/files', query: ['path' => $path]));
    }

    public function writeFile(string $path, string $content): void
    {
        $this->request('POST', '/files', [
            'path' => $path,
            'content' => $content,
            'mode' => '644',
            'encoding' => 'utf-8',
            'createDir' => true,
        ]);
    }

    public function remove(string $path): void
    {
        $this->request('POST', '/filesystem/remove', ['path' => $path]);
    }

    public function stat(string $path): array
    {
        return $this->request('GET', '/filesystem/stat', query: ['path' => $path]);
    }

    public function list(string $path, int $depth = 1): array
    {
        return $this->request('GET', '/filesystem', query: ['path' => $path, 'depth' => $depth]);
    }

    public function cmd(string $command, string $cwd, int $timeoutSeconds): array
    {
        return $this->request('POST', '/processes/cmd', [
            'command' => $command,
            'cwd' => $cwd,
            'timeout' => $timeoutSeconds,
        ]);
    }

    public function executeCode(string $code, int $timeoutSeconds = 30, string $language = 'python'): array
    {
        return $this->request('POST', '/contexts/execute', [
            'code' => $code,
            'language' => $language,
            'timeout' => $timeoutSeconds,
        ]);
    }

    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    public function request(string $method, string $path, ?array $data = null, array $query = [], bool $sandboxScoped = true): array
    {
        $url = $this->url($path, $query, $sandboxScoped);
        $headers = ['User-Agent' => 'HaoCode-AgentRun-Spike/1.0'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }
        if ($this->accountId !== '') {
            $headers['X-Acs-Parent-Id'] = $this->accountId;
        }
        if ($data !== null) {
            $headers['Content-Type'] = 'application/json';
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

    private function url(string $path, array $query, bool $sandboxScoped): string
    {
        $base = $this->endpoint !== null && trim($this->endpoint) !== ''
            ? rtrim($this->endpoint, '/')
            : "https://{$this->accountId}.agentrun-data.{$this->region}.aliyuncs.com";
        $prefix = $sandboxScoped ? '/sandboxes/'.$this->sandboxId() : '';
        $url = $base.$prefix.'/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    private function extractContent(array $result): string
    {
        foreach (['content', 'text', 'data'] as $key) {
            if (isset($result[$key]) && is_scalar($result[$key])) {
                return (string) $result[$key];
            }
        }
        foreach (['data', 'result'] as $container) {
            if (isset($result[$container]) && is_array($result[$container])) {
                foreach (['content', 'text'] as $key) {
                    if (isset($result[$container][$key]) && is_scalar($result[$container][$key])) {
                        return (string) $result[$container][$key];
                    }
                }
            }
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
