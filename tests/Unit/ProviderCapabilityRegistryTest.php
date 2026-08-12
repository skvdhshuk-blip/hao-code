<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Api\Capability\CapabilityStatus;
use HaoCode\Services\Api\Capability\ProviderCapabilityRegistry;
use HaoCode\Services\Settings\ResolvedProviderConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilityRegistryTest extends TestCase
{
    public function test_default_matrix_contains_exactly_the_three_runtime_providers(): void
    {
        $this->assertSame(
            ['anthropic', 'openai', 'openai_chat'],
            ProviderCapabilityRegistry::defaults()->types(),
        );
    }

    #[DataProvider('providerCases')]
    public function test_common_runtime_capabilities_share_one_matrix(
        string $providerType,
        string $model,
        string $baseUrl,
    ): void {
        $capabilities = ProviderCapabilityRegistry::defaults()->resolve(
            $this->provider($providerType, $model, $baseUrl),
        );

        foreach ([
            ProviderCapabilityRegistry::TEXT,
            ProviderCapabilityRegistry::STREAMING,
            ProviderCapabilityRegistry::TOOLS,
            ProviderCapabilityRegistry::MULTI_TURN_TOOLS,
            ProviderCapabilityRegistry::STRUCTURED_OUTPUT,
            ProviderCapabilityRegistry::IMAGES,
            ProviderCapabilityRegistry::ABORT,
            ProviderCapabilityRegistry::CUSTOM_HEADERS,
        ] as $capability) {
            $this->assertSame(CapabilityStatus::Supported, $capabilities->status($capability));
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function providerCases(): iterable
    {
        yield 'anthropic' => ['anthropic', 'claude-sonnet-4-6', 'https://api.anthropic.com'];
        yield 'openai responses' => ['openai', 'gpt-5.2', 'https://api.openai.com'];
        yield 'openai chat' => ['openai_chat', 'deepseek-reasoner', 'https://api.deepseek.com'];
    }

    #[DataProvider('thinkingModelCases')]
    public function test_model_rules_describe_known_thinking_models(
        string $providerType,
        string $model,
        string $baseUrl,
    ): void {
        $capabilities = ProviderCapabilityRegistry::defaults()->resolve(
            $this->provider($providerType, $model, $baseUrl),
        );

        $decision = $capabilities->decision(ProviderCapabilityRegistry::THINKING);
        $this->assertSame(CapabilityStatus::Supported, $decision['status']);
        $this->assertStringStartsWith('model:', $decision['source']);
        $this->assertNotSame([], $capabilities->modelLevel);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function thinkingModelCases(): iterable
    {
        yield 'claude' => ['anthropic', 'claude-sonnet-4-6', 'https://api.anthropic.com'];
        yield 'responses' => ['openai', 'gpt-5.2', 'https://api.openai.com'];
        yield 'chat' => ['openai_chat', 'deepseek-reasoner', 'https://api.deepseek.com'];
        yield 'deepseek v4 flash' => ['openai_chat', 'deepseek-v4-flash', 'https://api.deepseek.com'];
    }

    public function test_unmatched_custom_model_remains_unknown_instead_of_being_rejected(): void
    {
        $capabilities = ProviderCapabilityRegistry::defaults()->resolve(
            $this->provider('openai_chat', 'vendor-new-model', 'https://gateway.example.test'),
        );

        $this->assertSame(
            CapabilityStatus::Unknown,
            $capabilities->status(ProviderCapabilityRegistry::THINKING),
        );
        $this->assertSame([], $capabilities->modelLevel);
        $this->assertSame([], $capabilities->endpointLevel);
    }

    public function test_official_anthropic_endpoint_adds_endpoint_level_oauth_support(): void
    {
        $capabilities = ProviderCapabilityRegistry::defaults()->resolve(
            $this->provider('anthropic', 'claude-sonnet-4-6', 'https://api.anthropic.com'),
        );

        $decision = $capabilities->decision(ProviderCapabilityRegistry::OAUTH_BEARER);
        $this->assertSame(CapabilityStatus::Supported, $decision['status']);
        $this->assertSame('endpoint:api.anthropic.com', $decision['source']);
        $this->assertNotSame([], $capabilities->endpointLevel);
    }

    #[DataProvider('openAiTypes')]
    public function test_openai_wire_adapters_explicitly_reject_anthropic_oauth_mode(string $providerType): void
    {
        $capabilities = ProviderCapabilityRegistry::defaults()->resolve(
            $this->provider($providerType, 'gpt-5.2', 'https://api.openai.com'),
        );

        $decision = $capabilities->decision(ProviderCapabilityRegistry::OAUTH_BEARER);
        $this->assertSame(CapabilityStatus::Unsupported, $decision['status']);
        $this->assertStringContainsString('Anthropic wire adapter', (string) $decision['reason']);
    }

    /** @return iterable<string, array{string}> */
    public static function openAiTypes(): iterable
    {
        yield 'responses' => ['openai'];
        yield 'chat' => ['openai_chat'];
    }

    public function test_serialized_manifest_retains_all_three_resolution_levels(): void
    {
        $manifest = ProviderCapabilityRegistry::defaults()->resolve(
            $this->provider('anthropic', 'claude-sonnet-4-6', 'https://api.anthropic.com'),
        )->toArray();

        $this->assertArrayHasKey('provider', $manifest);
        $this->assertArrayHasKey('model_level', $manifest);
        $this->assertArrayHasKey('endpoint_level', $manifest);
        $this->assertArrayHasKey('effective', $manifest);
        $this->assertSame('supported', $manifest['effective']['thinking']['status']);
    }

    private function provider(string $type, string $model, string $baseUrl): ResolvedProviderConfig
    {
        return new ResolvedProviderConfig(
            providerType: $type,
            providerName: null,
            apiKey: 'test-key',
            model: $model,
            baseUrl: $baseUrl,
            maxTokens: 4096,
            contextWindow: 200000,
        );
    }
}
