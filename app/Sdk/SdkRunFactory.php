<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Agent\AgentRunContext;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\PooledProvider;
use HaoCode\Services\Api\SettingsAwareProvider;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Cost\BudgetLedger;
use HaoCode\Services\Cost\UsageAccumulator;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Sdk\Internal\RunCapabilityGuard;
use HaoCode\Sdk\Internal\RunCapabilityResolver;
use HaoCode\Sdk\Sandbox\SandboxManager;
use HaoCode\Tools\Mcp\ListMcpResourcesTool;
use HaoCode\Tools\Mcp\McpDynamicTool;
use HaoCode\Tools\Mcp\ReadMcpResourceTool;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\WebFetch\WebFetchTool;

/** @internal */
final class SdkRunFactory
{
    use SdkRunFactoryStageResumeSnapshotConcern;
    use SdkRunFactoryLoadMcpToolsConcern;

}
