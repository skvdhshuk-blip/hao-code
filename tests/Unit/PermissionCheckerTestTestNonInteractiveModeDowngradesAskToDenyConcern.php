<?php

namespace Tests\Unit;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Permissions\DenialTracker;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Permissions\PermissionMode;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

trait PermissionCheckerTestTestNonInteractiveModeDowngradesAskToDenyConcern
{

    public function test_non_interactive_mode_downgrades_ask_to_deny(): void
    {
        $checker = $this->makeChecker();
        $checker->nonInteractive(true);

        // rm -rf triggers ask() in normal mode
        $tool = $this->makeBashTool();
        $decision = $checker->check($tool, ['command' => 'rm -rf /tmp/foo'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertFalse($decision->needsPrompt, 'ask() must be downgraded to deny() in non-interactive mode');
        $this->assertStringContainsString('Non-interactive', $decision->reason ?? '');
    }

    public function test_non_interactive_false_preserves_ask(): void
    {
        $checker = $this->makeChecker();
        $checker->nonInteractive(false);

        $tool = $this->makeBashTool();
        $decision = $checker->check($tool, ['command' => 'rm -rf /tmp/foo'], $this->context);

        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->needsPrompt, 'ask() should be preserved when non-interactive is false');
    }

    public function test_is_non_interactive_reflects_flag(): void
    {
        $checker = $this->makeChecker();
        $this->assertFalse($checker->isNonInteractive());
        $checker->nonInteractive(true);
        $this->assertTrue($checker->isNonInteractive());
    }

    private function makeBashTool(): ToolInterface
    {
        return $this->makeTool('Bash', false);
    }
}
