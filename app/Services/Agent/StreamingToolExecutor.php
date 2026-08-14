<?php

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolResult;

/**
 * Executes tools as they stream in from the API, not after the full response completes.
 *
 * When a tool_use content_block_stop event arrives during streaming:
 * - Safe tools (read-only + concurrency-safe) are forked immediately via pcntl_fork
 *   until a stateful tool creates an execution barrier
 * - Unsafe tools, and safe tools after a stateful predecessor, are queued for
 *   sequential execution after the stream ends
 *
 * After the stream completes, collectResults() waits for forked children
 * and executes queued unsafe tools, returning all results in block order.
 *
 * Sibling abort: When a Bash tool errors, all other running tool processes
 * are killed and pending tools receive synthetic error messages. This matches
 * claude-code's siblingAbortController behavior.
 */
class StreamingToolExecutor
{
    use StreamingToolExecutorConstructConcern;
    use StreamingToolExecutorAbortedResultConcern;

    private const MAX_EARLY_EXECUTIONS = 8;
    private const MAX_IPC_PAYLOAD_BYTES = 1_000_000;
    private const MAX_IPC_TOOL_ID_BYTES = 4_096;
    private const DEFAULT_EARLY_TOOL_TIMEOUT_SECONDS = 120.0;

    /** @var array<int, array{pid: int, temp_file: string, block: array}> */
    private array $earlyPids = [];

    /** @var array<int, array> block index => tool_use block */
    private array $queuedBlocks = [];

    /** Whether a stateful tool has made later early execution unsafe. */
    private bool $hasSequentialBarrier = false;

    private bool $contextSet = false;
    private ToolUseContext $context;
    /** @var callable|null */
    private $onToolStart = null;
    /** @var callable|null */
    private $onToolComplete = null;

    /** Whether a Bash tool has errored, triggering sibling abort. */
    private bool $siblingAborted = false;
    /** Description of the tool that triggered the sibling abort. */
    private ?string $abortedByTool = null;
}
