<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

final class ToolSchemaRegistrationTest extends TestCase
{
    public function test_unresolvable_local_reference_never_enters_registry_or_execution(): void
    {
        $calls = 0;
        $tool = new class($calls) extends BaseTool {
            public function __construct(private int &$calls) {}
            public function name(): string { return 'BrokenReference'; }
            public function description(): string { return 'Must never run'; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make([
                    'type' => 'object',
                    '$ref' => '#/$defs/missing',
                ]);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                $this->calls++;

                return ToolResult::success('unreachable');
            }
        };
        $registry = new ToolRegistry;

        try {
            $registry->register($tool);
            self::fail('Malformed schemas must fail during registration.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('BrokenReference', $exception->getMessage());
        }

        self::assertFalse($registry->has('BrokenReference'));
        self::assertSame([], $registry->toApiTools());
        self::assertSame(0, $calls);
    }

    public function test_dynamic_schema_change_fails_before_model_advertisement(): void
    {
        $tool = new class extends BaseTool {
            public bool $broken = false;
            public function name(): string { return 'DynamicSchema'; }
            public function description(): string { return 'Dynamic'; }
            public function inputSchema(): ToolInputSchema
            {
                return ToolInputSchema::make($this->broken
                    ? ['type' => 'object', '$ref' => '#/missing']
                    : ['type' => 'object', 'properties' => []]);
            }
            public function call(array $input, ToolUseContext $context): ToolResult
            {
                return ToolResult::success('ok');
            }
        };
        $registry = new ToolRegistry;
        $registry->register($tool);
        $tool->broken = true;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DynamicSchema');
        $registry->toApiTools();
    }
}
