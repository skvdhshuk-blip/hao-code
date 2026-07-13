<?php

namespace HaoCode\Tools\Team;

use HaoCode\Services\Agent\TeamResultCollector;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

class TeamAwaitTool extends BaseTool
{
    public function __construct(private readonly TeamResultCollector $collector) {}

    public function name(): string { return 'TeamAwait'; }

    public function description(): string
    {
        return 'Wait for all team members to produce a result or fail, then return the complete structured aggregate.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Team name'],
                'timeout_seconds' => ['type' => 'integer', 'description' => 'Maximum wait time (1-600 seconds)'],
                'poll_interval_ms' => ['type' => 'integer', 'description' => 'Polling interval (100-2000 ms)'],
            ],
            'required' => ['name'],
        ], [
            'name' => 'required|string',
            'timeout_seconds' => 'nullable|integer|min:1|max:600',
            'poll_interval_ms' => 'nullable|integer|min:100|max:2000',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $timeout = (int) ($input['timeout_seconds'] ?? 120);
        $pollMicros = (int) ($input['poll_interval_ms'] ?? 250) * 1000;
        $deadline = microtime(true) + $timeout;

        do {
            $result = $this->collector->collect($input['name']);
            if ($result === null) {
                return ToolResult::error("Team not found: {$input['name']}");
            }
            foreach ($result['members'] as $member) {
                if (is_array($member['pending_interrupt'] ?? null)) {
                    throw new \HaoCode\Sdk\HumanInterruptException(
                        \HaoCode\Sdk\HumanInterrupt::fromArray($member['pending_interrupt']),
                    );
                }
            }
            if (($result['summary']['pending'] ?? 0) === 0 || $context->isAborted()) {
                break;
            }
            usleep($pollMicros);
        } while (microtime(true) < $deadline);

        $timedOut = ($result['summary']['pending'] ?? 0) > 0;
        $result['timed_out'] = $timedOut;

        return ToolResult::success(
            (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $result['summary'] + ['timedOut' => $timedOut],
        );
    }

    public function isReadOnly(array $input): bool { return true; }
}
