<?php

declare(strict_types=1);

namespace Tests\Sdk;

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Internal\UnsupportedCapabilityException;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\SdkRunFactory;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\StreamEvent;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Support\Runtime\SdkRuntime;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class CapabilityPreflightIntegrationTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDirectory = sys_get_temp_dir().'/haocode-capability-preflight-'.bin2hex(random_bytes(4));
        mkdir($this->tempDirectory.'/.haocode', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDirectory);
        parent::tearDown();
    }

    public function test_unsupported_provider_capability_fails_before_any_http_request(): void
    {
        file_put_contents($this->tempDirectory.'/.haocode/settings.json', json_encode([
            'active_provider' => 'test-openai',
            'provider' => [
                'test-openai' => [
                    'type' => 'openai',
                    'api_key' => 'test-key',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            $requestCount++;

            return new MockResponse('', ['http_code' => 500]);
        });
        $this->app->singleton(StreamingClient::class, function ($app) use ($httpClient): StreamingClient {
            return new StreamingClient(
                apiKey: 'test-key',
                model: 'gpt-5.2',
                baseUrl: 'https://api.openai.com',
                httpClient: $httpClient,
                settingsManager: $app->make(SettingsManager::class),
                providerType: 'openai',
            );
        });

        try {
            HaoCode::query('hello', new HaoCodeConfig(
                cwd: $this->tempDirectory,
                oauthBearer: true,
            ));
            $this->fail('Expected capability preflight to reject OAuth for the OpenAI wire adapter.');
        } catch (UnsupportedCapabilityException $exception) {
            $this->assertStringContainsString('oauth_bearer', $exception->getMessage());
        }

        $this->assertSame(0, $requestCount);
    }

    public function test_sandbox_conflict_fails_before_the_sandbox_workspace_is_created(): void
    {
        $sandboxRoot = $this->tempDirectory.'/sandbox-root';

        try {
            HaoCode::query('run bash', new HaoCodeConfig(
                apiKey: 'test-key',
                cwd: $this->tempDirectory,
                allowedTools: ['Bash'],
                sandbox: SandboxConfig::local(
                    mode: 'filesystem',
                    root: $sandboxRoot,
                ),
            ));
            $this->fail('Expected capability preflight to reject Bash in filesystem sandbox mode.');
        } catch (UnsupportedCapabilityException $exception) {
            $this->assertStringContainsString('Bash requires sandbox mode', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($sandboxRoot);
    }

    public function test_runtime_capability_mutation_is_rejected_and_rolled_back(): void
    {
        file_put_contents($this->tempDirectory.'/.haocode/settings.json', json_encode([
            'active_provider' => 'test-anthropic',
            'provider' => [
                'test-anthropic' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'test-openai' => [
                    'type' => 'openai',
                    'api_key' => 'openai-key',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $run = SdkRunFactory::create(
            new HaoCodeConfig(
                cwd: $this->tempDirectory,
            ),
            SdkRuntime::app(AgentLoopFactory::class),
        );

        try {
            $settings = $run->loop->getRunContext()?->settings;
            $this->assertNotNull($settings);

            $settings->set('active_provider', 'test-openai');
            $this->assertSame('openai', $settings->getProviderType());
            $this->assertFalse($settings->isOauthBearer());

            try {
                $settings->set('oauth_bearer', true);
                $this->fail('Expected the run guard to reject OpenAI OAuth bearer mode.');
            } catch (UnsupportedCapabilityException $exception) {
                $this->assertStringContainsString('oauth_bearer', $exception->getMessage());
            }

            $this->assertFalse($settings->isOauthBearer(), 'Rejected runtime changes must roll back atomically.');
        } finally {
            $run->close();
        }
    }

    public function test_incompatible_provider_switch_is_rejected_without_changing_identity(): void
    {
        file_put_contents($this->tempDirectory.'/.haocode/settings.json', json_encode([
            'active_provider' => 'test-anthropic',
            'provider' => [
                'test-anthropic' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'test-openai' => [
                    'type' => 'openai',
                    'api_key' => 'openai-key',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $run = SdkRunFactory::create(
            new HaoCodeConfig(
                cwd: $this->tempDirectory,
                oauthBearer: true,
            ),
            SdkRuntime::app(AgentLoopFactory::class),
        );

        try {
            $settings = $run->loop->getRunContext()?->settings;
            $this->assertNotNull($settings);

            try {
                $settings->set('active_provider', 'test-openai');
                $this->fail('Expected OpenAI selection to reject the active OAuth bearer policy.');
            } catch (UnsupportedCapabilityException $exception) {
                $this->assertStringContainsString('oauth_bearer', $exception->getMessage());
            }

            $resolved = $settings->resolveProviderConfig();
            $this->assertSame('anthropic', $resolved->providerType);
            $this->assertSame('test-anthropic', $resolved->providerName);
            $this->assertSame('anthropic-key', $resolved->apiKey);
            $this->assertTrue($settings->isOauthBearer());
        } finally {
            $run->close();
        }
    }

    public function test_provider_switch_without_target_credentials_is_rejected_atomically(): void
    {
        file_put_contents($this->tempDirectory.'/.haocode/settings.json', json_encode([
            'active_provider' => 'test-anthropic',
            'provider' => [
                'test-anthropic' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'test-openai' => [
                    'type' => 'openai',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $originalOpenAiKey = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');
        $run = null;

        try {
            $run = SdkRunFactory::create(
                new HaoCodeConfig(cwd: $this->tempDirectory),
                SdkRuntime::app(AgentLoopFactory::class),
            );
            $settings = $run->loop->getRunContext()?->settings;
            $this->assertNotNull($settings);

            try {
                $settings->set('active_provider', 'test-openai');
                $this->fail('Expected the provider switch to require a target credential.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('API key is required for provider type "openai"', $exception->getMessage());
            }

            $resolved = $settings->resolveProviderConfig();
            $this->assertSame('anthropic', $resolved->providerType);
            $this->assertSame('anthropic-key', $resolved->apiKey);
        } finally {
            $run?->close();
            $originalOpenAiKey === false
                ? putenv('OPENAI_API_KEY')
                : putenv('OPENAI_API_KEY='.$originalOpenAiKey);
        }
    }

    public function test_runtime_provider_change_is_rejected_for_a_fixed_injected_provider(): void
    {
        file_put_contents($this->tempDirectory.'/.haocode/settings.json', json_encode([
            'active_provider' => 'test-anthropic',
            'provider' => [
                'test-anthropic' => [
                    'type' => 'anthropic',
                    'api_key' => 'anthropic-key',
                    'api_base_url' => 'https://api.anthropic.com',
                    'model' => 'claude-sonnet-4-6',
                ],
                'test-openai' => [
                    'type' => 'openai',
                    'api_key' => 'openai-key',
                    'api_base_url' => 'https://api.openai.com',
                    'model' => 'gpt-5.2',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $fixedProvider = new class implements LlmProvider
        {
            public function streamMessages(
                array $systemPrompt,
                array $messages,
                array $tools,
                ?callable $onRawEvent = null,
                ?callable $shouldAbort = null,
            ): \Generator {
                if (false) {
                    yield new StreamEvent('message_stop', []);
                }
            }

            public function getLastRateLimitHeaders(): array
            {
                return [];
            }
        };
        $run = SdkRunFactory::create(
            new HaoCodeConfig(cwd: $this->tempDirectory),
            SdkRuntime::app(AgentLoopFactory::class),
            $fixedProvider,
        );

        try {
            $settings = $run->loop->getRunContext()?->settings;
            $this->assertNotNull($settings);

            try {
                $settings->set('active_provider', 'test-openai');
                $this->fail('Expected a fixed injected provider to reject runtime provider changes.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('SettingsAwareProvider', $exception->getMessage());
            }

            $resolved = $settings->resolveProviderConfig();
            $this->assertSame('anthropic', $resolved->providerType);
            $this->assertSame('test-anthropic', $resolved->providerName);
        } finally {
            $run->close();
        }
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
