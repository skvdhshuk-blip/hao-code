<?php

namespace Tests\Sdk;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\Tools\SandboxGlobTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxGrepTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxReadTool;
use HaoCode\Sdk\Sandbox\Tools\SandboxWriteTool;
use HaoCode\Sdk\SdkRun;
use HaoCode\Sdk\SdkRunFactory;
use HaoCode\Sdk\SdkTool;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Support\Runtime\SdkRuntime;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\WebFetch\WebFetchTool;
use Tests\TestCase;

class ToolCapabilityIntegrationTest extends TestCase
{
    public function test_empty_allowlist_registers_neither_sandbox_nor_custom_tools(): void
    {
        $custom = $this->customTool();
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: [],
            tools: [$custom],
            sandbox: $this->sandbox(),
        ));

        try {
            $this->assertSame([], array_keys($this->toolRegistry($run)->getAllTools()));
        } finally {
            $run->close();
        }
    }

    public function test_read_only_allowlist_registers_only_sandbox_read_replacement(): void
    {
        $custom = $this->customTool();
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['Read'],
            tools: [$custom],
            sandbox: $this->sandbox(),
        ));

        try {
            $registry = $this->toolRegistry($run);

            $this->assertSame(['Read'], array_keys($registry->getAllTools()));
            $this->assertInstanceOf(SandboxReadTool::class, $registry->getTool('Read'));
            $this->assertNull($registry->getTool('CustomProbe'));
        } finally {
            $run->close();
        }
    }

    public function test_custom_tool_requires_exact_allowlist_name(): void
    {
        $custom = $this->customTool();
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['CustomProbe'],
            tools: [$custom],
            sandbox: $this->sandbox(),
        ));

        try {
            $registry = $this->toolRegistry($run);

            $this->assertSame(['CustomProbe'], array_keys($registry->getAllTools()));
            $this->assertSame($custom, $registry->getTool('CustomProbe'));
        } finally {
            $run->close();
        }
    }

    public function test_sandbox_wildcard_registers_allowed_replacements_and_custom_tool(): void
    {
        $custom = $this->customTool();
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['*'],
            tools: [$custom],
            sandbox: $this->sandbox(),
        ));

        try {
            $registry = $this->toolRegistry($run);

            $this->assertInstanceOf(SandboxReadTool::class, $registry->getTool('Read'));
            $this->assertInstanceOf(SandboxWriteTool::class, $registry->getTool('Write'));
            $this->assertInstanceOf(SandboxGlobTool::class, $registry->getTool('Glob'));
            $this->assertInstanceOf(SandboxGrepTool::class, $registry->getTool('Grep'));
            $this->assertSame($custom, $registry->getTool('CustomProbe'));
            $this->assertNull($registry->getTool('Bash'));
            $this->assertNull($registry->getTool('Edit'));
        } finally {
            $run->close();
        }
    }

    public function test_disallowed_tools_win_for_sandbox_replacements_and_custom_tools(): void
    {
        $custom = $this->customTool();
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['*'],
            disallowedTools: ['Read', 'CustomProbe'],
            tools: [$custom],
            sandbox: $this->sandbox(),
        ));

        try {
            $registry = $this->toolRegistry($run);

            $this->assertNull($registry->getTool('Read'));
            $this->assertNull($registry->getTool('CustomProbe'));
            $this->assertInstanceOf(SandboxWriteTool::class, $registry->getTool('Write'));
        } finally {
            $run->close();
        }
    }

    public function test_sandbox_wildcard_registers_run_configured_webfetch_instance(): void
    {
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['*'],
            sandbox: $this->sandbox(),
            webfetchAllowPrivateNetworks: true,
            webfetchPrivateAllowList: ['10.0.0.0/8'],
            webfetchMaxBytes: 12_345,
        ));

        try {
            $webFetch = $this->toolRegistry($run)->getTool('WebFetch');

            $this->assertInstanceOf(WebFetchTool::class, $webFetch);
            $maxBytes = new \ReflectionProperty($webFetch, 'maxBytes');
            $this->assertSame(12_345, $maxBytes->getValue($webFetch));
            $allowPrivate = new \ReflectionProperty($webFetch, 'allowPrivateNetworks');
            $this->assertTrue($allowPrivate->getValue($webFetch));
            $allowList = new \ReflectionProperty($webFetch, 'ssrfAllowList');
            $this->assertSame(['10.0.0.0/8'], $allowList->getValue($webFetch));
        } finally {
            $run->close();
        }
    }

    public function test_disallowed_webfetch_does_not_leave_parent_default_instance(): void
    {
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['*'],
            disallowedTools: ['WebFetch'],
            sandbox: $this->sandbox(),
            webfetchMaxBytes: 12_345,
        ));

        try {
            $this->assertNull($this->toolRegistry($run)->getTool('WebFetch'));
        } finally {
            $run->close();
        }
    }

    public function test_child_loop_cannot_broaden_the_parent_sdk_registry(): void
    {
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['Agent'],
        ));

        try {
            $parentRegistry = $this->toolRegistry($run);
            $child = SdkRuntime::app(AgentLoopFactory::class)->createIsolated(
                toolFilter: static fn (string $name): bool => true,
                streamingClient: $this->createMock(\HaoCode\Services\Api\StreamingClient::class),
                runContext: $this->runContext($run)->fork(),
                parentToolRegistry: $parentRegistry,
            );
            $childRegistry = $this->loopToolRegistry($child);

            $this->assertSame(['Agent'], array_keys($childRegistry->getAllTools()));
            $this->assertNull($childRegistry->getTool('Bash'));
            $this->assertNull($childRegistry->getTool('Write'));
            $this->assertNull($childRegistry->getTool('WebFetch'));
        } finally {
            $run->close();
        }
    }

    public function test_child_loop_preserves_sandbox_tool_replacements(): void
    {
        $run = $this->createRun(new HaoCodeConfig(
            apiKey: 'test-key',
            allowedTools: ['*'],
            sandbox: $this->sandbox(),
        ));

        try {
            $child = SdkRuntime::app(AgentLoopFactory::class)->createIsolated(
                toolFilter: static fn (string $name): bool => in_array($name, ['Read', 'Write'], true),
                streamingClient: $this->createMock(\HaoCode\Services\Api\StreamingClient::class),
                runContext: $this->runContext($run)->fork(),
                parentToolRegistry: $this->toolRegistry($run),
            );
            $childRegistry = $this->loopToolRegistry($child);

            $this->assertInstanceOf(SandboxReadTool::class, $childRegistry->getTool('Read'));
            $this->assertInstanceOf(SandboxWriteTool::class, $childRegistry->getTool('Write'));
            $this->assertSame(['Read', 'Write'], array_keys($childRegistry->getAllTools()));
        } finally {
            $run->close();
        }
    }

    private function createRun(HaoCodeConfig $config): SdkRun
    {
        return SdkRunFactory::create(
            $config,
            SdkRuntime::app(AgentLoopFactory::class),
        );
    }

    private function toolRegistry(SdkRun $run): ToolRegistry
    {
        return $this->loopToolRegistry($run->loop);
    }

    private function loopToolRegistry(AgentLoop $loop): ToolRegistry
    {
        $property = new \ReflectionProperty($loop, 'toolRegistry');

        return $property->getValue($loop);
    }

    private function runContext(SdkRun $run): \HaoCode\Services\Agent\AgentRunContext
    {
        $property = new \ReflectionProperty($run->loop, 'runContext');

        return $property->getValue($run->loop);
    }

    private function sandbox(): SandboxConfig
    {
        return SandboxConfig::local(cleanup: 'always');
    }

    private function customTool(): SdkTool
    {
        return new class extends SdkTool
        {
            public function name(): string
            {
                return 'CustomProbe';
            }

            public function description(): string
            {
                return 'Probe custom tool capability registration.';
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
