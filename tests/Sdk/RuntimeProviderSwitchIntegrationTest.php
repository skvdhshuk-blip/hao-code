<?php

declare(strict_types=1);

namespace Tests\Sdk;

use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\SdkRunFactory;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\ContextBudget;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Support\Runtime\SdkRuntime;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class RuntimeProviderSwitchIntegrationTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDirectory = sys_get_temp_dir().'/haocode-provider-switch-'.bin2hex(random_bytes(4));
        mkdir($this->tempDirectory.'/.haocode', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDirectory);
        parent::tearDown();
    }

    public function test_runtime_switch_uses_only_the_new_provider_identity_and_pool_bucket(): void
    {
        file_put_contents($this->tempDirectory.'/.haocode/settings.json', json_encode([
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'configured-anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'openai-main' => [
                    'type' => 'openai',
                    'api_key' => 'configured-openai-key',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                    'max_tokens' => 4096,
                    'context_window' => 64000,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $pool = new CredentialPool;
        $pool->add('anthropic', new Credential(apiKey: 'anthropic-pool-sentinel'));
        $pool->add('openai', new Credential(apiKey: 'openai-pool-key'));
        $request = [];
        $fixture = (string) file_get_contents(
            dirname(__DIR__).'/fixtures/provider-matrix/sse/openai-responses-text-only.sse',
        );
        $httpClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$request, $fixture): MockResponse {
                $request = [
                    'method' => $method,
                    'url' => $url,
                    'headers' => $options['headers'] ?? [],
                    'body' => $options['body'] ?? '',
                ];

                return new MockResponse($fixture, ['http_code' => 200]);
            },
        );
        $client = new StreamingClient(
            apiKey: 'fallback-key',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
        );
        $run = SdkRunFactory::create(
            new HaoCodeConfig(
                cwd: $this->tempDirectory,
                credentialPool: $pool,
            ),
            SdkRuntime::app(AgentLoopFactory::class),
            $client,
        );

        try {
            $settings = $run->loop->getRunContext()?->settings;
            $this->assertNotNull($settings);
            $settings->set('active_provider', 'openai-main');

            $this->assertSame('hi', $run->loop->run('hello'));
            $this->assertSame('POST', $request['method']);
            $this->assertSame('https://api.openai.com/v1/responses', $request['url']);
            $this->assertSame('Bearer openai-pool-key', $this->headerValue($request['headers'], 'authorization'));
            $this->assertStringContainsString('"model":"gpt-5.2"', (string) $request['body']);

            $serializedRequest = json_encode($request, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('anthropic-pool-sentinel', $serializedRequest);
            $this->assertStringNotContainsString('configured-anthropic-key', $serializedRequest);
            $this->assertSame(
                ContextBudget::safeInputLimit(64000, 4096),
                $run->loop->getMaxEstimatedInputTokens(),
            );
        } finally {
            $run->close();
        }
    }

    public function test_runtime_switch_without_target_credentials_fails_before_http_without_old_key_fallback(): void
    {
        file_put_contents($this->tempDirectory.'/.haocode/settings.json', json_encode([
            'active_provider' => 'anthropic-main',
            'provider' => [
                'anthropic-main' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-secret-must-not-cross',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'openai-without-key' => [
                    'type' => 'openai',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $requestCount = 0;
        $httpClient = new MockHttpClient(
            function () use (&$requestCount): MockResponse {
                $requestCount++;

                return new MockResponse('', ['http_code' => 500]);
            },
        );
        $client = new StreamingClient(
            apiKey: 'anthropic-secret-must-not-cross',
            model: 'claude-sonnet-4-6',
            httpClient: $httpClient,
            providerType: 'anthropic',
        );
        $originalOpenAiKey = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');
        $run = null;

        try {
            $run = SdkRunFactory::create(
                new HaoCodeConfig(cwd: $this->tempDirectory),
                SdkRuntime::app(AgentLoopFactory::class),
                $client,
            );
            $settings = $run->loop->getRunContext()?->settings;
            $this->assertNotNull($settings);
            try {
                $settings->set('active_provider', 'openai-without-key');
                $this->fail('Expected the target provider switch to require its own API key.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('provider type "openai"', $exception->getMessage());
                $this->assertStringNotContainsString('anthropic-secret-must-not-cross', $exception->getMessage());
            }

            $resolved = $settings->resolveProviderConfig();
            $this->assertSame('anthropic', $resolved->providerType);
            $this->assertSame('anthropic-secret-must-not-cross', $resolved->apiKey);
            $this->assertSame(0, $requestCount);
        } finally {
            $run?->close();
            $originalOpenAiKey === false
                ? putenv('OPENAI_API_KEY')
                : putenv('OPENAI_API_KEY='.$originalOpenAiKey);
        }
    }

    /** @param list<string> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name.':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
