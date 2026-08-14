<?php

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Services\Agent\AgentLoop;
use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\BackgroundAgentManager;
use HaoCode\Services\Task\TaskManager;
use HaoCode\Tools\Agent\BuiltInAgents;
use HaoCode\Tools\Agent\AgentTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class AgentToolTest extends TestCase
{
    use AgentToolTestTestSchemaAcceptsExplicitInheritModelConcern;
    use AgentToolTestTestCommittedWorktreeChangesAreRetainedConcern;


    // ─── success path ─────────────────────────────────────────────────────

    // ─── error handling ───────────────────────────────────────────────────

    // ─── metadata ─────────────────────────────────────────────────────────

    // ─── default agent type ───────────────────────────────────────────────

    // ─── tool metadata ────────────────────────────────────────────────────

    // ─── existing test ────────────────────────────────────────────────────
}
