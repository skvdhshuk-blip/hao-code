<?php

declare(strict_types=1);

namespace HaoCode\Services\Mcp;

use HaoCode\Support\Http\BoundedResponseBodyReader;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Headless OAuth token provider for remote MCP servers.
 *
 * Secrets are read from explicitly named environment variables. Tokens refreshed
 * during a run remain in memory and are never written to the MCP settings file.
 */
final class McpOAuthTokenProvider
{
    private const MAX_RESPONSE_BODY_BYTES = 64 * 1024;

    private ?string $accessToken = null;

    private ?string $refreshToken = null;

    private float $expiresAt = 0.0;

    /** @param array<string, string> $config */
    public function __construct(
        private readonly array $config,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function authorizationHeader(): ?string
    {
        if ($this->accessToken === null) {
            $this->accessToken = $this->environmentValue('access_token_env');
        }

        if ($this->accessToken !== null && ($this->expiresAt === 0.0 || microtime(true) < $this->expiresAt - 30.0)) {
            return 'Bearer '.$this->accessToken;
        }

        if (! $this->canRefresh()) {
            return $this->accessToken !== null ? 'Bearer '.$this->accessToken : null;
        }

        $this->refresh();

        return $this->accessToken !== null ? 'Bearer '.$this->accessToken : null;
    }

    public function refreshAfterUnauthorized(): bool
    {
        if (! $this->canRefresh()) {
            return false;
        }

        $this->accessToken = null;
        $this->expiresAt = 0.0;
        $this->refresh();

        return true;
    }

    private function canRefresh(): bool
    {
        return isset($this->config['token_endpoint'])
            && $this->clientId() !== null
            && ($this->refreshToken() !== null || $this->environmentValue('client_secret_env') !== null);
    }

    private function refresh(): void
    {
        $endpoint = $this->config['token_endpoint'] ?? '';
        $this->assertSecureTokenEndpoint($endpoint);

        $clientId = $this->clientId();
        if ($clientId === null) {
            throw McpConnectionException::application('MCP OAuth client ID is not configured', 401);
        }

        $refreshToken = $this->refreshToken();
        $body = $refreshToken !== null
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]
            : ['grant_type' => 'client_credentials'];
        $body['client_id'] = $clientId;

        if (isset($this->config['scope']) && $this->config['scope'] !== '') {
            $body['scope'] = $this->config['scope'];
        }

        $headers = ['Accept' => 'application/json'];
        $clientSecret = $this->environmentValue('client_secret_env');
        $authMethod = $this->config['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if ($clientSecret !== null && $authMethod === 'client_secret_post') {
            $body['client_secret'] = $clientSecret;
        } elseif ($clientSecret !== null) {
            $headers['Authorization'] = 'Basic '.base64_encode($clientId.':'.$clientSecret);
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            $status = $response->getStatusCode();
            $payload = json_decode(BoundedResponseBodyReader::read(
                $this->httpClient,
                $response,
                self::MAX_RESPONSE_BODY_BYTES,
            ), true);
        } catch (\Throwable $exception) {
            throw McpConnectionException::transport(
                'MCP OAuth token request failed: '.$exception->getMessage()
            );
        }

        if ($status < 200 || $status >= 300 || ! is_array($payload) || ! is_string($payload['access_token'] ?? null)) {
            throw McpConnectionException::application('MCP OAuth token endpoint rejected the request', $status);
        }

        $this->accessToken = $payload['access_token'];
        if (is_string($payload['refresh_token'] ?? null) && $payload['refresh_token'] !== '') {
            $this->refreshToken = $payload['refresh_token'];
        }
        $expiresIn = is_numeric($payload['expires_in'] ?? null) ? max(0, (int) $payload['expires_in']) : 0;
        $this->expiresAt = $expiresIn > 0 ? microtime(true) + $expiresIn : 0.0;
    }

    private function clientId(): ?string
    {
        $configured = $this->config['client_id'] ?? null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : $this->environmentValue('client_id_env');
    }

    private function refreshToken(): ?string
    {
        $this->refreshToken ??= $this->environmentValue('refresh_token_env');

        return $this->refreshToken;
    }

    private function environmentValue(string $configKey): ?string
    {
        $name = $this->config[$configKey] ?? null;
        if (! is_string($name) || $name === '') {
            return null;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function assertSecureTokenEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLoopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $isLoopback)) {
            throw McpConnectionException::application(
                'MCP OAuth token endpoint must use HTTPS unless it is loopback'
            );
        }
    }
}
