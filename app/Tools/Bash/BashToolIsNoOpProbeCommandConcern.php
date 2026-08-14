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

trait BashToolIsNoOpProbeCommandConcern
{

    private function isNoOpProbeCommand(string $command): bool
    {
        return preg_match('/^(?::|true)(?:\s+(?:[12]?>{1,2}\s*\S+|[12]>&\d+))*$/i', trim($command)) === 1;
    }

    private function hasLeadingColonPrefix(string $command): bool
    {
        return str_starts_with(ltrim($command), ':');
    }
}
