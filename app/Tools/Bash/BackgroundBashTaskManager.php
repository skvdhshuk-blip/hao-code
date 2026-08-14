<?php

namespace HaoCode\Tools\Bash;

use HaoCode\Support\Runtime\ProcessSupervisor;
use HaoCode\Tools\ToolResult;

/**
 * Owns process-local background Bash task bookkeeping.
 *
 * The task IDs remain process-local; this class only moves registration,
 * polling, TTL cleanup and result harvesting out of BashTool.
 *
 * @internal
 */
final class BackgroundBashTaskManager
{
    use BackgroundBashTaskManagerStartConcern;
    use BackgroundBashTaskManagerProcessStartLineWithPsConcern;

    private const BACKGROUND_TASK_TTL_SECONDS = 6 * 3600;
    private const BACKGROUND_TASK_MAX = 64;
    private const MAX_CAPTURED_OUTPUT_BYTES = 100_000;

    /**
     * @var array<string, array{
     *   pid: int,
     *   process?: resource,
     *   outFile: string,
     *   statusFile: string,
     *   payloadFile?: string,
     *   startTime: float,
     *   deadline?: float,
     *   startToken: ?string,
     *   command: string
     * }>
     */
    private static array $backgroundTasks = [];

}
