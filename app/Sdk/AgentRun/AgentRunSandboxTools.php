<?php

namespace HaoCode\Sdk\AgentRun;

use HaoCode\Sdk\AgentRun\Tools\AgentRunBashTool;
use HaoCode\Sdk\AgentRun\Tools\AgentRunGlobTool;
use HaoCode\Sdk\AgentRun\Tools\AgentRunGrepTool;
use HaoCode\Sdk\AgentRun\Tools\AgentRunReadTool;
use HaoCode\Sdk\AgentRun\Tools\AgentRunWriteTool;

/** @api */
final class AgentRunSandboxTools
{
    /** @return array<int, \HaoCode\Contracts\ToolInterface> */
    public static function make(AgentRunSandboxConfig $config, ?AgentRunSandboxClient $client = null): array
    {
        $client ??= new AgentRunSandboxClient($config);

        return [
            new AgentRunReadTool($client),
            new AgentRunWriteTool($client),
            new AgentRunGlobTool($client),
            new AgentRunGrepTool($client),
            new AgentRunBashTool($client),
        ];
    }

    /** @return string[] */
    public static function localOnlyToolsToDisable(): array
    {
        return ['Edit', 'apply_patch', 'NotebookEdit', 'Lsp', 'EnterWorktree', 'ExitWorktree', 'Agent', 'SendMessage'];
    }
}
