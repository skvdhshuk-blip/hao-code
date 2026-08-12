<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Internal\RunCapabilityResolver;
use HaoCode\Sdk\Internal\UnsupportedCapabilityException;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxToolPolicy;
use HaoCode\Sdk\SdkTool;
use HaoCode\Services\Api\Capability\CapabilityStatus;
use HaoCode\Services\Api\Capability\ProviderCapabilityRegistry;
use HaoCode\Services\Settings\ResolvedProviderConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunCapabilityResolverTest extends TestCase
{
    public function test_plain_run_manifest_is_hosted_text_only_with_default_permissions(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig);

        $this->assertTrue($manifest->agent[ProviderCapabilityRegistry::TEXT]);
        $this->assertFalse($manifest->agent[ProviderCapabilityRegistry::TOOLS]);
        $this->assertSame('none', $manifest->tools['selection']);
        $this->assertFalse($manifest->sandbox['active']);
        $this->assertSame('default', $manifest->permission['mode']);
        $this->assertSame([], $manifest->violations);
    }

    public function test_manifest_combines_agent_provider_tool_sandbox_and_permission_inputs(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            model: 'claude-sonnet-4-6',
            allowedTools: ['Read', 'Bash'],
            disallowedTools: ['Read'],
            thinkingEnabled: true,
            permissionMode: 'bypass_permissions',
            sandbox: SandboxConfig::local(mode: 'full'),
            images: ['data:image/png;base64,AA=='],
            headers: ['X-Test' => 'yes'],
            responseSchema: ['type' => 'object'],
        ));

        $this->assertTrue($manifest->agent[ProviderCapabilityRegistry::TOOLS]);
        $this->assertTrue($manifest->agent[ProviderCapabilityRegistry::STRUCTURED_OUTPUT]);
        $this->assertTrue($manifest->agent[ProviderCapabilityRegistry::THINKING]);
        $this->assertTrue($manifest->agent[ProviderCapabilityRegistry::IMAGES]);
        $this->assertSame(['Bash'], $manifest->tools['effective_requested']);
        $this->assertTrue($manifest->sandbox['bash_enabled']);
        $this->assertTrue($manifest->permission['approval_bypassed']);
        $this->assertSame([], $manifest->violations);
    }

    public function test_effective_manifest_uses_the_assembled_tool_registry(): void
    {
        $manifest = $this->resolve(
            new HaoCodeConfig(
                allowedTools: [],
                enableAskUser: true,
                ephemeral: false,
            ),
            effectiveToolNames: ['AskUserQuestion'],
        );

        $this->assertSame([], $manifest->tools['effective_requested']);
        $this->assertSame(['AskUserQuestion'], $manifest->tools['effective']);
        $this->assertTrue($manifest->tools['uses_tools']);
        $this->assertTrue($manifest->agent[ProviderCapabilityRegistry::TOOLS]);
    }

    #[DataProvider('openAiTypes')]
    public function test_openai_oauth_is_rejected_by_preflight(string $providerType): void
    {
        $manifest = $this->resolve(
            new HaoCodeConfig(
                providerType: $providerType,
                model: 'gpt-5.2',
                oauthBearer: true,
            ),
            $providerType,
            'gpt-5.2',
            'https://api.openai.com',
        );

        $this->assertNotSame([], $manifest->violations);
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('oauth_bearer');
        $this->expectExceptionMessage('before provider request');
        $manifest->assertSupported();
    }

    /** @return iterable<string, array{string}> */
    public static function openAiTypes(): iterable
    {
        yield 'responses' => ['openai'];
        yield 'chat' => ['openai_chat'];
    }

    public function test_unknown_custom_model_capability_is_visible_but_not_rejected(): void
    {
        $manifest = $this->resolve(
            new HaoCodeConfig(
                providerType: 'openai_chat',
                model: 'new-gateway-model',
                thinkingEnabled: true,
            ),
            'openai_chat',
            'new-gateway-model',
            'https://gateway.example.test',
        );

        $this->assertSame(
            CapabilityStatus::Unknown,
            $manifest->provider->status(ProviderCapabilityRegistry::THINKING),
        );
        $this->assertSame([], $manifest->violations);
        $manifest->assertSupported();
    }

    public function test_capability_violation_redacts_endpoint_credentials_and_path(): void
    {
        $manifest = $this->resolve(
            new HaoCodeConfig(
                providerType: 'openai',
                model: 'gpt-5.2',
                oauthBearer: true,
            ),
            'openai',
            'gpt-5.2',
            'https://url-user:url-password@api.openai.com/private/path?token=url-token',
        );

        try {
            $manifest->assertSupported();
            $this->fail('Expected unsupported OAuth bearer capability.');
        } catch (UnsupportedCapabilityException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('https://api.openai.com', $message);
            $this->assertStringNotContainsString('url-user', $message);
            $this->assertStringNotContainsString('url-password', $message);
            $this->assertStringNotContainsString('private/path', $message);
            $this->assertStringNotContainsString('url-token', $message);
        }

        $serialized = json_encode($manifest->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('https://api.openai.com', $serialized);
        $this->assertStringNotContainsString('url-user', $serialized);
        $this->assertStringNotContainsString('url-password', $serialized);
        $this->assertStringNotContainsString('private/path', $serialized);
        $this->assertStringNotContainsString('url-token', $serialized);
    }

    public function test_explicit_bash_requires_full_sandbox_mode(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            allowedTools: ['Bash'],
            sandbox: SandboxConfig::local(mode: 'filesystem'),
        ));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Bash requires sandbox mode');
        $manifest->assertSupported();
    }

    public function test_wildcard_means_all_available_tools_and_does_not_require_sandbox_bash(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            allowedTools: ['*'],
            sandbox: SandboxConfig::local(mode: 'filesystem'),
        ));

        $this->assertSame('wildcard', $manifest->tools['selection']);
        $this->assertFalse($manifest->sandbox['bash_enabled']);
        $this->assertSame([], $manifest->violations);
    }

    #[DataProvider('sandboxHostOnlyTools')]
    public function test_explicit_host_only_tools_are_rejected_in_sandbox(string $toolName): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            allowedTools: [$toolName],
            sandbox: SandboxConfig::local(),
        ));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage("Tool {$toolName} is host-only");
        $manifest->assertSupported();
    }

    /** @return iterable<string, array{string}> */
    public static function sandboxHostOnlyTools(): iterable
    {
        foreach (SandboxToolPolicy::hostOnlyToolNames() as $name) {
            yield $name => [$name];
        }
    }

    public function test_disallowed_tool_wins_before_sandbox_conflict_detection(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            allowedTools: ['Bash', 'Agent'],
            disallowedTools: ['Bash', 'Agent'],
            sandbox: SandboxConfig::local(),
        ));

        $this->assertSame([], $manifest->tools['effective_requested']);
        $this->assertSame([], $manifest->violations);
    }

    public function test_custom_tool_cannot_replace_a_reserved_sandbox_tool(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            allowedTools: ['Read'],
            tools: [$this->tool('Read')],
            sandbox: SandboxConfig::local(),
        ));

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('conflicts with a sandbox replacement');
        $manifest->assertSupported();
    }

    public function test_custom_tool_not_selected_by_allowed_tools_is_reported_as_disabled(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            tools: [$this->tool('LookupOrder')],
        ));

        $this->assertFalse($manifest->tools['custom']['LookupOrder']);
        $this->assertFalse($manifest->tools['uses_tools']);
    }

    public function test_invalid_sandbox_provider_and_mode_are_reported_together(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            sandbox: new SandboxConfig(provider: 'unknown', mode: 'unsafe'),
        ));

        $this->assertCount(2, $manifest->violations);
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('Sandbox provider');
        $this->expectExceptionMessage('Sandbox mode');
        $manifest->assertSupported();
    }

    public function test_plan_permission_removes_effective_write_permission(): void
    {
        $manifest = $this->resolve(new HaoCodeConfig(
            allowedTools: ['Read', 'Write'],
            permissionMode: 'plan',
        ));

        $this->assertFalse($manifest->permission['write_tools_enabled']);
        $this->assertFalse($manifest->permission['approval_bypassed']);
        $this->assertSame('plan', $manifest->permission['mode']);
    }

    private function resolve(
        HaoCodeConfig $config,
        string $providerType = 'anthropic',
        string $model = 'claude-sonnet-4-6',
        string $baseUrl = 'https://api.anthropic.com',
        ?array $effectiveToolNames = null,
    ): \HaoCode\Sdk\Internal\EffectiveCapabilityManifest {
        return RunCapabilityResolver::defaults()->resolve(
            $config,
            new ResolvedProviderConfig(
                providerType: $providerType,
                providerName: null,
                apiKey: 'test-key',
                model: $model,
                baseUrl: $baseUrl,
                maxTokens: 4096,
                contextWindow: 200000,
            ),
            $effectiveToolNames,
        );
    }

    private function tool(string $name): SdkTool
    {
        return new class($name) extends SdkTool {
            public function __construct(private readonly string $toolName) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'Test tool';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'ok';
            }
        };
    }
}
