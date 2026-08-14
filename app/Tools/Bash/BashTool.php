<?php

namespace HaoCode\Tools\Bash;

use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Services\Permissions\SensitivePathGuard;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Support\Runtime\SpawnEnvironment;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class BashTool extends BaseTool
{
    use BashToolNameConcern;
    use BashToolCloseForegroundCaptureFilesConcern;
    use BashToolIsNoOpProbeCommandConcern;

    private const MAX_CAPTURED_OUTPUT_BYTES = 100_000;
    /** @var array<string, string> */
    private static array $sessionWorkingDirectories = [];
}
