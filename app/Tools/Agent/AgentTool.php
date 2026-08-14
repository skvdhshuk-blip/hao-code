<?php

namespace HaoCode\Tools\Agent;

use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Git\HardenedGitRunner;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class AgentTool extends BaseTool
{
    use AgentToolConstructConcern;
    use AgentToolExecuteBackgroundAgentConcern;


    // ─── Worktree support ───────────────────────────────────────────
}
