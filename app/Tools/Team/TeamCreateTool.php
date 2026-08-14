<?php

namespace HaoCode\Tools\Team;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\TeamManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\Agent\AgentDefinition;
use HaoCode\Tools\Agent\AgentLoader;
use HaoCode\Tools\Agent\AgentModelResolver;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TeamCreateTool extends BaseTool
{
    use TeamCreateToolConstructConcern;
    use TeamCreateToolRunTurnConcern;

}
