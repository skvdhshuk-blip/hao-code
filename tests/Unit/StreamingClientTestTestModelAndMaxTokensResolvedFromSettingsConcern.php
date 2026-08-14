<?php

namespace Tests\Unit;

use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\OpenAiProvider;
use HaoCode\Services\Api\StreamEvent;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tests\Support\MockAnthropicSse;

trait StreamingClientTestTestModelAndMaxTokensResolvedFromSettingsConcern
{

    public function test_model_and_max_tokens_resolved_from_settings(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $settings = $this->createMock(\HaoCode\Services\Settings\SettingsManager::class);
        $settings->method('getModel')->willReturn('claude-opus-4-8');
        $settings->method('getMaxTokens')->willReturn(32768);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6', // default, should be overridden
            httpClient: $httpClient,
            settingsManager: $settings,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertSame('claude-opus-4-8', $decoded['model']);
        $this->assertSame(32768, $decoded['max_tokens']);
    }

    public function test_api_key_and_base_url_resolved_from_settings(): void
    {
        $capturedUrl = null;
        $capturedHeaders = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedHeaders) {
            $capturedUrl = $url;
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $settings = $this->createMock(\HaoCode\Services\Settings\SettingsManager::class);
        $settings->method('getModel')->willReturn('glm-5.1');
        $settings->method('getMaxTokens')->willReturn(16384);
        $settings->method('getApiKey')->willReturn('dynamic-key');
        $settings->method('getBaseUrl')->willReturn('https://api.z.ai/api/anthropic');

        $client = new StreamingClient(
            apiKey: 'fallback-key',
            model: 'claude-sonnet-4-6',
            baseUrl: 'https://api.anthropic.com',
            httpClient: $httpClient,
            settingsManager: $settings,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame('https://api.z.ai/api/anthropic/v1/messages', $capturedUrl);
        $this->assertContains('x-api-key: dynamic-key', $capturedHeaders);
    }

    /**
     * @param string[] $headers
     */
    private function hasHeader(array $headers, string $expected): bool
    {
        foreach ($headers as $header) {
            if (strcasecmp($header, $expected) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    public function test_oauth_bearer_mode_sends_authorization_header_and_oauth_beta_flag(): void
    {
        $capturedHeaders = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'sk-ant-oat-token',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
            oauthBearer: true,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertTrue($this->hasHeader($capturedHeaders, 'authorization: Bearer sk-ant-oat-token'));
        $this->assertFalse($this->hasHeader($capturedHeaders, 'x-api-key: sk-ant-oat-token'));
        $this->assertNull($this->headerValue($capturedHeaders, 'x-api-key'));

        $beta = $this->headerValue($capturedHeaders, 'anthropic-beta');
        $this->assertNotNull($beta);
        $this->assertStringContainsString('prompt-caching-2024-07-31', $beta);
        $this->assertStringContainsString('oauth-2025-04-20', $beta);
    }

    public function test_oauth_bearer_mode_via_settings_manager(): void
    {
        $capturedHeaders = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $settings = new SettingsManager(getcwd());
        $settings->set('api_key', 'sk-ant-oat-token');
        $settings->set('oauth_bearer', true);

        $client = (new StreamingClient(
            apiKey: 'fallback-key',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
        ))->withSettingsManager($settings);

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertTrue($this->hasHeader($capturedHeaders, 'authorization: Bearer sk-ant-oat-token'));
        $this->assertNull($this->headerValue($capturedHeaders, 'x-api-key'));
        $beta = $this->headerValue($capturedHeaders, 'anthropic-beta');
        $this->assertNotNull($beta);
        $this->assertStringContainsString('oauth-2025-04-20', $beta);
    }

    public function test_default_mode_keeps_x_api_key_header_without_oauth_beta_flag(): void
    {
        $capturedHeaders = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'plain-api-key',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertTrue($this->hasHeader($capturedHeaders, 'x-api-key: plain-api-key'));
        $this->assertNull($this->headerValue($capturedHeaders, 'authorization'));
        $this->assertSame('prompt-caching-2024-07-31', $this->headerValue($capturedHeaders, 'anthropic-beta'));
    }

    public function test_custom_headers_merge_into_anthropic_requests_and_auth_stays_protected(): void
    {
        $capturedHeaders = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'real-key',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
            headers: [
                'Editor-Version' => 'vscode/1.96.0',
                'Copilot-Integration-Id' => 'vscode-chat',
                'anthropic-beta' => 'custom-beta-flag', // same-name override wins
                'x-api-key' => 'stolen',                // auth header stays protected
            ],
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame('vscode/1.96.0', $this->headerValue($capturedHeaders, 'editor-version'));
        $this->assertSame('vscode-chat', $this->headerValue($capturedHeaders, 'copilot-integration-id'));
        $this->assertSame('custom-beta-flag', $this->headerValue($capturedHeaders, 'anthropic-beta'));
        $this->assertSame('real-key', $this->headerValue($capturedHeaders, 'x-api-key'));
    }

    public function test_custom_headers_via_settings_manager(): void
    {
        $capturedHeaders = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $settings = new SettingsManager(getcwd());
        $settings->set('api_key', 'real-key');
        $settings->set('headers', ['Editor-Version' => 'phpstorm/2024.3']);

        $client = (new StreamingClient(
            apiKey: 'real-key',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
        ))->withSettingsManager($settings);

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame('phpstorm/2024.3', $this->headerValue($capturedHeaders, 'editor-version'));
        $this->assertSame('real-key', $this->headerValue($capturedHeaders, 'x-api-key'));
    }

    public function test_cache_control_added_to_last_tool(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        $tools = [
            ['name' => 'Read', 'description' => 'read files'],
            ['name' => 'Bash', 'description' => 'run commands'],
        ];

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: $tools,
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('tools', $decoded);
        $this->assertCount(2, $decoded['tools']);
        // Only the last tool should have cache_control
        $this->assertArrayNotHasKey('cache_control', $decoded['tools'][0]);
        $this->assertArrayHasKey('cache_control', $decoded['tools'][1]);
        $this->assertSame('ephemeral', $decoded['tools'][1]['cache_control']['type']);
    }

    public function test_zai_endpoint_excludes_webfetch_tool_before_sending_tools(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '';
            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'glm-5.1',
            baseUrl: 'https://api.z.ai/api/anthropic',
            httpClient: $httpClient,
        );

        $tools = [
            ['name' => 'Read', 'description' => 'read files'],
            ['name' => 'WebFetch', 'description' => 'fetch webpages'],
            ['name' => 'Bash', 'description' => 'run commands'],
        ];

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: $tools,
        ));

        $decoded = json_decode($capturedBody, true);
        $this->assertArrayHasKey('tools', $decoded);
        $this->assertSame(['Read', 'Bash'], array_column($decoded['tools'], 'name'));
        $this->assertArrayNotHasKey('cache_control', $decoded['tools'][0]);
        $this->assertSame('ephemeral', $decoded['tools'][1]['cache_control']['type']);
    }

    public function test_http_error_with_plain_text_body_uses_body_as_message(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(
                'Service Unavailable',
                ['http_code' => 503],
            ),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame('http_error', $e->getErrorType());
            $this->assertStringContainsString('Service Unavailable', $e->getMessage());
        }
    }

    public function test_http_error_with_empty_body_includes_only_redacted_origin(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 502]),
        ]);

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            baseUrl: 'https://url-user:url-password@api.example.com/private/path?token=url-token',
            httpClient: $httpClient,
        );

        try {
            iterator_to_array($client->streamMessages(
                systemPrompt: [],
                messages: [['role' => 'user', 'content' => 'hello']],
                tools: [],
            ));
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
            $this->assertStringContainsString('https://api.example.com', $e->getMessage());
            $this->assertStringNotContainsString('url-user', $e->getMessage());
            $this->assertStringNotContainsString('url-password', $e->getMessage());
            $this->assertStringNotContainsString('private/path', $e->getMessage());
            $this->assertStringNotContainsString('url-token', $e->getMessage());
        }
    }

    public function test_it_forces_http_1_1_for_kimi_streaming_requests(): void
    {
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return new MockResponse([
                "event: message_stop\n",
                "data: {}\n\n",
            ], ['http_code' => 200]);
        });

        $client = new StreamingClient(
            apiKey: 'test-key',
            model: 'kimi-for-coding',
            baseUrl: 'https://api.kimi.com/coding',
            httpClient: $httpClient,
        );

        iterator_to_array($client->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [],
        ));

        $this->assertSame('1.1', $capturedOptions['http_version'] ?? null);
        $this->assertTrue($capturedOptions['verify_peer'] ?? false);
        $this->assertTrue($capturedOptions['verify_host'] ?? false);
    }
}
