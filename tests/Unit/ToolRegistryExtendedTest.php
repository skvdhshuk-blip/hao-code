<?php

namespace Tests\Unit;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Sdk\SdkTool;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\Mcp\McpDynamicTool;
use HaoCode\Tools\Sleep\SleepTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class ToolRegistryExtendedTest extends TestCase
{
    private function makeTool(string $name, bool $enabled = true): BaseTool
    {
        return new class($name, $enabled) extends BaseTool
        {
            public function __construct(private string $toolName, private bool $toolEnabled)
            {
            }

            public function name(): string { return $this->toolName; }
            public function description(): string { return "Tool {$this->toolName}"; }
            public function isEnabled(): bool { return $this->toolEnabled; }

            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };
    }

    public function test_get_tool_returns_registered_tool(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('Bash'));

        $this->assertNotNull($registry->getTool('Bash'));
    }

    public function test_get_tool_returns_null_for_unknown_name(): void
    {
        $registry = new ToolRegistry;

        $this->assertNull($registry->getTool('DoesNotExist'));
    }

    public function test_get_all_tools_excludes_disabled_tools(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('EnabledTool', true));
        $registry->register($this->makeTool('DisabledTool', false));

        $all = $registry->getAllTools();

        $this->assertArrayHasKey('EnabledTool', $all);
        $this->assertArrayNotHasKey('DisabledTool', $all);
    }

    public function test_to_api_tools_returns_only_enabled_tools(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('VisibleTool', true));
        $registry->register($this->makeTool('HiddenTool', false));

        $apiTools = $registry->toApiTools();

        $names = array_column($apiTools, 'name');
        $this->assertContains('VisibleTool', $names);
        $this->assertNotContains('HiddenTool', $names);
    }

    public function test_to_api_tools_produces_correct_structure(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('ReadFile', true));

        $apiTools = $registry->toApiTools();

        $this->assertCount(1, $apiTools);
        $this->assertArrayHasKey('name', $apiTools[0]);
        $this->assertArrayHasKey('description', $apiTools[0]);
        $this->assertArrayHasKey('input_schema', $apiTools[0]);
        $this->assertSame('ReadFile', $apiTools[0]['name']);
    }

    public function test_manifest_is_validated_and_uses_stable_registration_identity(): void
    {
        $registry = new ToolRegistry;
        $tool = $this->makeTool('LookupOrder');
        $registry->register($tool);

        $manifest = $registry->manifest();

        $this->assertSame('LookupOrder', $manifest['LookupOrder']['name']);
        $this->assertSame('runtime', $manifest['LookupOrder']['effect']);
        $this->assertStringStartsWith('anonymous:', $manifest['LookupOrder']['implementation']);
        $this->assertStringNotContainsString(__FILE__, $manifest['LookupOrder']['implementation']);
        $this->assertSame('object', $manifest['LookupOrder']['input_schema']['type']);
    }

    public function test_invalid_schema_is_rejected_during_registration(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn('BrokenTool');
        $tool->method('inputSchema')->willReturn(ToolInputSchema::make(['type' => 'string']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type=object');
        (new ToolRegistry)->register($tool);
    }

    public function test_schema_required_entries_must_reference_declared_properties(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn('InvalidRequired');
        $tool->method('inputSchema')->willReturn(ToolInputSchema::make([
            'type' => 'object',
            'properties' => [],
            'required' => ['missing'],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required entries must name declared properties');
        (new ToolRegistry)->register($tool);
    }

    public function test_duplicate_registration_requires_explicit_replacement(): void
    {
        $registry = new ToolRegistry;
        $registry->register($this->makeTool('MyTool'));

        $second = $this->makeTool('MyTool');
        try {
            $registry->register($second);
            $this->fail('Duplicate registration must fail closed.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('use replace()', $e->getMessage());
        }

        $registry->replace($second);

        $this->assertSame($second, $registry->getTool('MyTool'));
    }

    public function test_to_api_tools_returns_empty_array_when_no_tools(): void
    {
        $registry = new ToolRegistry;

        $this->assertSame([], $registry->toApiTools());
    }

    public function test_api_tool_cache_is_reused_and_invalidated_by_registry_changes(): void
    {
        $tool = new SleepTool;

        $registry = new ToolRegistry;
        $registry->register($tool);

        $registry->toApiTools();
        $registry->toApiTools();

        $reflection = new \ReflectionProperty($registry, 'apiSchemas');
        $this->assertArrayHasKey('Sleep', $reflection->getValue($registry));

        $registry->register($this->makeTool('SecondTool'));
        $this->assertSame(['Sleep', 'SecondTool'], array_column($registry->toApiTools(), 'name'));

        $registry->unregister('Sleep');
        $this->assertSame(['SecondTool'], array_column($registry->toApiTools(), 'name'));
        $this->assertArrayNotHasKey('Sleep', $reflection->getValue($registry));
    }

    public function test_dynamic_enabled_state_is_evaluated_on_each_conversion(): void
    {
        $tool = new class extends BaseTool
        {
            public bool $enabled = true;
            public int $schemaCalls = 0;

            public function name(): string { return 'DynamicTool'; }
            public function description(): string { return 'Dynamic tool'; }
            public function isEnabled(): bool { return $this->enabled; }

            public function inputSchema(): ToolInputSchema
            {
                $this->schemaCalls++;

                return ToolInputSchema::make(['type' => 'object', 'properties' => []]);
            }

            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };

        $registry = new ToolRegistry;
        $registry->register($tool);

        $this->assertSame(['DynamicTool'], array_column($registry->toApiTools(), 'name'));
        $tool->enabled = false;
        $this->assertSame([], $registry->toApiTools());
        $tool->enabled = true;
        $this->assertSame(['DynamicTool'], array_column($registry->toApiTools(), 'name'));
        $this->assertSame(3, $tool->schemaCalls);
    }

    public function test_schema_cache_does_not_freeze_sdk_tool_parameters(): void
    {
        $tool = new class extends SdkTool
        {
            public bool $includeFormat = false;

            public function name(): string { return 'DynamicSdkTool'; }
            public function description(): string { return 'Dynamic SDK tool'; }

            public function parameters(): array
            {
                $parameters = ['query' => ['type' => 'string', 'required' => true]];
                if ($this->includeFormat) {
                    $parameters['format'] = ['type' => 'string'];
                }

                return $parameters;
            }

            public function handle(array $input): string
            {
                return 'ok';
            }
        };

        $registry = new ToolRegistry;
        $registry->register($tool);

        $firstProperties = $registry->toApiTools()[0]['input_schema']['properties'];
        $tool->includeFormat = true;
        $secondProperties = $registry->toApiTools()[0]['input_schema']['properties'];

        $this->assertSame(['query'], array_keys($firstProperties));
        $this->assertSame(['query', 'format'], array_keys($secondProperties));
    }

    public function test_registering_same_name_invalidates_the_cached_schema(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new SleepTool);

        $firstSchema = $registry->toApiTools()[0]['input_schema'];

        $replacement = $this->makeSchemaTool('Sleep', 'integer');
        $registry->replace($replacement);
        $secondSchema = $registry->toApiTools()[0]['input_schema'];

        $this->assertSame($replacement, $registry->getTool('Sleep'));
        $this->assertSame('number', $firstSchema['properties']['seconds']['type']);
        $this->assertSame('integer', $secondSchema['properties']['value']['type']);
    }

    public function test_unregister_then_reregister_does_not_reuse_the_removed_schema(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new SleepTool);
        $registry->toApiTools();

        $registry->unregister('Sleep');
        $registry->register($this->makeSchemaTool('Sleep', 'boolean'));

        $schema = $registry->toApiTools()[0]['input_schema'];
        $this->assertSame('boolean', $schema['properties']['value']['type']);
    }

    public function test_sdk_tool_dynamic_name_description_and_parameters_remain_live(): void
    {
        $tool = new class extends SdkTool
        {
            public string $currentName = 'OriginalSdkTool';
            public string $currentDescription = 'Original description';
            public string $parameterType = 'string';

            public function name(): string { return $this->currentName; }
            public function description(): string { return $this->currentDescription; }
            public function parameters(): array
            {
                return ['value' => ['type' => $this->parameterType]];
            }
            public function handle(array $input): string { return 'ok'; }
        };

        $registry = new ToolRegistry;
        $registry->register($tool);
        $registry->toApiTools();

        $tool->currentDescription = 'Updated description';
        $tool->parameterType = 'integer';
        $apiTool = $registry->toApiTools()[0];

        $this->assertSame('OriginalSdkTool', $apiTool['name']);
        $this->assertSame('Updated description', $apiTool['description']);
        $this->assertSame('integer', $apiTool['input_schema']['properties']['value']['type']);
        $this->assertSame($tool, $registry->getTool('OriginalSdkTool'));
    }

    public function test_registered_tool_identity_cannot_drift(): void
    {
        $tool = new class extends SdkTool
        {
            public string $currentName = 'StableTool';
            public function name(): string { return $this->currentName; }
            public function description(): string { return 'Stable tool'; }
            public function parameters(): array { return []; }
            public function handle(array $input): string { return 'ok'; }
        };

        $registry = new ToolRegistry;
        $registry->register($tool);
        $tool->currentName = 'DriftedTool';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Registered tool identity 'StableTool' changed");
        $registry->toApiTools();
    }

    public function test_external_base_tool_dynamic_schema_is_not_cached(): void
    {
        $tool = new class extends BaseTool
        {
            public string $parameterType = 'string';

            public function name(): string { return 'ExternalBaseTool'; }
            public function description(): string { return 'External base tool'; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => ['value' => ['type' => $this->parameterType]],
                ]);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };

        $registry = new ToolRegistry;
        $registry->register($tool);
        $registry->toApiTools();

        $tool->parameterType = 'integer';
        $schema = $registry->toApiTools()[0]['input_schema'];

        $this->assertSame('integer', $schema['properties']['value']['type']);
    }

    public function test_direct_tool_interface_dynamic_schema_is_not_cached(): void
    {
        $parameterType = 'string';
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('name')->willReturn('DirectInterfaceTool');
        $tool->method('description')->willReturn('Direct interface tool');
        $tool->method('isEnabled')->willReturn(true);
        $tool->method('inputSchema')->willReturnCallback(
            static function () use (&$parameterType): ToolInputSchema {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => ['value' => ['type' => $parameterType]],
                ]);
            },
        );

        $registry = new ToolRegistry;
        $registry->register($tool);
        $registry->toApiTools();

        $parameterType = 'number';
        $schema = $registry->toApiTools()[0]['input_schema'];

        $this->assertSame('number', $schema['properties']['value']['type']);
    }

    public function test_cloned_registry_has_independent_cache_invalidation(): void
    {
        $registry = new ToolRegistry;
        $original = new SleepTool;
        $registry->register($original);
        $registry->toApiTools();

        $clone = clone $registry;
        $replacement = $this->makeSchemaTool('Sleep', 'integer');
        $clone->replace($replacement);

        $this->assertSame('number', $registry->toApiTools()[0]['input_schema']['properties']['seconds']['type']);
        $this->assertSame('integer', $clone->toApiTools()[0]['input_schema']['properties']['value']['type']);
        $this->assertSame($original, $registry->getTool('Sleep'));
        $this->assertSame($replacement, $clone->getTool('Sleep'));
    }

    public function test_mcp_tool_schema_survives_registry_clone_and_reregistration(): void
    {
        $manager = new McpConnectionManager(new McpServerConfigManager(sys_get_temp_dir()));
        $tool = new McpDynamicTool(
            qualifiedName: 'mcp__context7__lookup',
            serverName: 'context7',
            toolName: 'lookup',
            toolDescription: 'Look up documentation',
            inputJsonSchema: [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
            ],
            annotations: ['readOnlyHint' => true],
            connectionManager: $manager,
        );

        $registry = new ToolRegistry;
        $registry->register($tool);
        $registry->toApiTools();
        $clone = clone $registry;
        $clone->unregister('mcp__context7__lookup');
        $clone->register($tool);

        $this->assertSame($registry->toApiTools(), $clone->toApiTools());
        $this->assertSame('string', $clone->toApiTools()[0]['input_schema']['properties']['query']['type']);
        $reflection = new \ReflectionProperty($clone, 'apiSchemas');
        $this->assertArrayHasKey('mcp__context7__lookup', $reflection->getValue($clone));
    }

    private function makeSchemaTool(string $name, string $parameterType): BaseTool
    {
        return new class($name, $parameterType) extends BaseTool
        {
            public function __construct(
                private readonly string $toolName,
                private readonly string $parameterType,
            ) {
            }

            public function name(): string { return $this->toolName; }
            public function description(): string { return "Schema tool {$this->toolName}"; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    'properties' => ['value' => ['type' => $this->parameterType]],
                ]);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };
    }
}
