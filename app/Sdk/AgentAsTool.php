<?php

namespace HaoCode\Sdk;

/**
 * A tool that delegates to another Agent.
 *
 * @internal
 */
final class AgentAsTool extends SdkTool
{
    public function __construct(
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly Agent $agent,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function parameters(): array
    {
        return [
            'task' => [
                'type' => 'string',
                'description' => 'The task or question to hand off to this agent.',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $task = $input['task'] ?? '';
        if (! is_string($task) || trim($task) === '') {
            return 'Error: task must be a non-empty string.';
        }

        $result = Runner::run($this->agent, $task);

        return (string) $result;
    }
}
