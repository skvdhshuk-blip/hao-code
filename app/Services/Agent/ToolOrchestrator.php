<?php

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Run\DurableToolExecutionCoordinator;
use HaoCode\Services\Run\RunJournal;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolResult;

class ToolOrchestrator
{
    use ToolOrchestratorConstructConcern;
    use ToolOrchestratorExecuteSingleToolInnerConcern;
    use ToolOrchestratorRunStateConcern;

    /** Appended to a successful Read's output once the same file has been read
     *  this many times without an intervening Write/Edit on the same path. */
    private const REPEATED_READ_HINT_THRESHOLD = 4;

    private const DEFAULT_PARALLEL_TOOL_TIMEOUT_SECONDS = 120.0;

    private $permissionPromptHandler = null;
    private ?ToolResultStorage $toolResultStorage = null;
    /** @var array<string, int> raw file_path → successful Read count (this session) */
    private array $readCountsByFile = [];
    private ?SkillScopeState $skillScope = null;

    /** @var array<string, bool|array<string, mixed>> */
    private array $interruptOn = [];

    private bool $enableAskUser = false;

    private bool $enablePermissionInterrupts = false;

    /** @var string[]|null */
    private ?array $resumeAllowedTools = null;
}
