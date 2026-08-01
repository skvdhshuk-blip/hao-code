<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

final class WindowsToolCompatibilityTest extends TestCase
{
    public function test_windows_foreground_capture_uses_bounded_pipes(): void
    {
        $this->requireWindows();

        $method = new \ReflectionMethod(BashTool::class, 'allocateForegroundCaptureFiles');
        $capture = $method->invoke(new BashTool);

        $this->assertIsArray($capture);
        $this->assertTrue($capture['usePipes'] ?? false);
        $this->assertNull($capture['stdoutFile'] ?? null);
        $this->assertNull($capture['stderrFile'] ?? null);
    }

    public function test_windows_foreground_output_limit_is_enforced_before_result_return(): void
    {
        $this->requireWindows();

        $result = (new BashTool)->call([
            'command' => "php -r 'echo str_repeat(\"x\", 200000);'",
            'timeout' => 5000,
        ], new ToolUseContext(sys_get_temp_dir(), 'windows-bash-smoke'));

        $this->assertTrue($result->isError, $result->output);
        $this->assertTrue($result->metadata['outputLimited'] ?? false, $result->output);
        $this->assertLessThanOrEqual(101_000, strlen($result->output));
    }

    private function requireWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Real Windows tool integration coverage.');
        }
    }
}
