<?php

namespace HaoCode\Sdk\Sandbox\AgentRun;

use HaoCode\Support\Http\BoundedResponseBodyReader;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** @internal */
final class AgentRunClient
{
    private const MAX_ERROR_BODY_BYTES = 64 * 1024;
    // Two 100 KB captured streams can expand substantially when JSON-escaped.
    // Keep the transport buffer bounded without rejecting a valid capped result.
    private const MAX_COMMAND_RESPONSE_BYTES = 2 * 1024 * 1024;

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

    public function cmd(string $command, string $cwd, int $timeoutSeconds, ?callable $shouldAbort = null): array
    {
        return $this->request('POST', '/processes/cmd', [
            'command' => $command,
            'cwd' => $cwd,
            'timeout' => $timeoutSeconds,
        ], shouldAbort: $shouldAbort, maxResponseBytes: self::MAX_COMMAND_RESPONSE_BYTES);
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

    public function request(
        string $method,
        string $path,
        ?array $data = null,
        array $query = [],
        bool $sandboxScoped = true,
        ?callable $shouldAbort = null,
        ?int $maxResponseBytes = null,
    ): array
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
        if ($maxResponseBytes !== null) {
            // Symfony otherwise buffers the entire successful command response
            // before the backend can enforce its SDK capture limit.
            $options['buffer'] = false;
        }

        $response = $this->http->request($method, $url, $options);
        $body = null;
        $bodyLimited = false;
        if ($shouldAbort !== null || $maxResponseBytes !== null) {
            $received = $this->readStreamedResponse($response, $shouldAbort, $maxResponseBytes);
            if ($received['aborted']) {
                return ['__haocode_aborted' => true];
            }
            $status = $received['status'];
            $body = $received['body'];
            $bodyLimited = $received['limited'];
        } else {
            $status = $response->getStatusCode();
        }

        if ($status < 200 || $status >= 300) {
            $body ??= BoundedResponseBodyReader::read(
                $this->http,
                $response,
                self::MAX_ERROR_BODY_BYTES,
            );
            $decoded = [];
            if ($body !== '') {
                $json = json_decode($body, true);
                if (is_array($json)) {
                    $decoded = $json;
                }
            }

            $message = $decoded['error']['message'] ?? $decoded['message'] ?? $body ?: 'AgentRun request failed.';
            throw new \RuntimeException("AgentRun HTTP {$status}: {$message}");
        }

        if ($bodyLimited) {
            return ['__haocode_response_limited' => true];
        }
        $body ??= $response->getContent(false);

        $decoded = [];
        if ($body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        }

        return $decoded;
    }

    /**
     * @return array{status: int, body: string, aborted: bool, limited: bool}
     */
    private function readStreamedResponse(
        ResponseInterface $response,
        ?callable $shouldAbort,
        ?int $maxResponseBytes,
    ): array
    {
        $body = '';
        $status = null;
        foreach ($this->http->stream($response, 0.25) as $chunk) {
            if ($shouldAbort !== null && $shouldAbort()) {
                $response->cancel();

                return ['status' => 0, 'body' => '', 'aborted' => true, 'limited' => false];
            }

            if ($chunk->isTimeout()) {
                continue;
            }

            if ($chunk->getError() !== null) {
                throw new \RuntimeException('AgentRun response stream failed: '.$chunk->getError());
            }

            if ($chunk->isFirst()) {
                // Headers have arrived, so this is non-blocking. In contrast,
                // calling getStatusCode() before streaming can wait through a
                // remote command and miss an AbortController cancellation.
                $status = $response->getStatusCode();
                continue;
            }
            if ($chunk->isLast()) {
                break;
            }

            $content = $chunk->getContent();
            $limit = $status !== null && ($status < 200 || $status >= 300)
                ? self::MAX_ERROR_BODY_BYTES
                : $maxResponseBytes;
            if ($limit !== null) {
                $remaining = $limit - strlen($body);
                if ($remaining <= 0 || strlen($content) > $remaining) {
                    if ($remaining > 0) {
                        $body .= substr($content, 0, $remaining);
                    }
                    $response->cancel();

                    return [
                        'status' => $status ?? $response->getStatusCode(),
                        'body' => $body,
                        'aborted' => false,
                        'limited' => true,
                    ];
                }
            }

            $body .= $content;
        }

        return [
            'status' => $status ?? $response->getStatusCode(),
            'body' => $body,
            'aborted' => false,
            'limited' => false,
        ];
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
