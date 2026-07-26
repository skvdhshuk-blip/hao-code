<?php

namespace Tests\Unit;

use HaoCode\Sdk\SdkTool;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class SdkToolDefaultsTest extends TestCase
{
    public function test_sdk_tool_defaults_to_non_read_only(): void
    {
        $tool = new class extends SdkTool {
            public function name(): string
            {
                return 'WriteSomething';
            }

            public function description(): string
            {
                return 'writes';
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

        $this->assertFalse($tool->isReadOnly([]));
        $this->assertFalse($tool->isConcurrencySafe([]));
    }

    public function test_plan_mode_rejects_default_sdk_tool(): void
    {
        $tool = new class extends SdkTool {
            public function name(): string
            {
                return 'MutatingTool';
            }

            public function description(): string
            {
                return 'mutates';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'done';
            }
        };

        $checker = $this->makePlanChecker();
        $context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'sdk-tool-plan',
        );

        $decision = $checker->check($tool, [], $context);
        $this->assertFalse($decision->allowed, 'Plan mode must deny non-read-only SdkTool defaults');
    }

    public function test_explicit_read_only_sdk_tool_is_allowed_in_plan_mode(): void
    {
        $tool = new class extends SdkTool {
            public function name(): string
            {
                return 'LookupTool';
            }

            public function description(): string
            {
                return 'reads';
            }

            public function parameters(): array
            {
                return [];
            }

            public function handle(array $input): string
            {
                return 'data';
            }

            public function isReadOnly(array $input): bool
            {
                return true;
            }
        };

        $checker = $this->makePlanChecker();
        $context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'sdk-tool-plan-ro',
        );

        $decision = $checker->check($tool, [], $context);
        $this->assertTrue($decision->allowed);
    }

    private function makePlanChecker(): PermissionChecker
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getPermissionMode')->willReturn(PermissionMode::Plan);
        $settings->method('getAllowRules')->willReturn([]);
        $settings->method('getDenyRules')->willReturn([]);

        return new PermissionChecker($settings, new DenialTracker);
    }
}
