<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Services\Api\LlmProvider;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * A tool that delegates to another Agent, inheriting the parent run context.
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

    /**
     * Not used: {@see call()} owns execution so the parent ToolUseContext is not dropped.
     */
    public function handle(array $input): string
    {
        throw new \LogicException('AgentAsTool::handle() must not be called; use call().');
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $task = $input['task'] ?? '';
        if (! is_string($task) || trim($task) === '') {
            return ToolResult::error('task must be a non-empty string.');
        }

        /** @var AgentLoopFactory $factory */
        $factory = \HaoCode\Support\Runtime\SdkRuntime::app(AgentLoopFactory::class);

        $budgetLedger = $context->runContext?->budgetLedger;
        $options = new RunOptions(
            cwd: $context->workingDirectory,
            // Explicit agent preference; null would also inherit after RunOptions fix.
            ephemeral: $this->agent->ephemeral,
            maxBudgetUsd: $budgetLedger?->getLimit(),
        );

        $run = null;
        try {
            $parentProvider = $context->provider instanceof LlmProvider
                ? $context->provider
                : null;
            $run = SdkRunFactory::createFromAgent(
                $this->agent,
                $options,
                $factory,
                streamingClient: $parentProvider,
                budgetLedger: $budgetLedger,
                // Inherit parent tool implementations (including sandbox replacements)
                // so the child cannot rebuild host tools from the process registry.
                parentToolRegistry: $context->toolRegistry,
                usageAccumulator: $context->runContext?->usageAccumulator,
            );
            $loop = $run->loop;

            // Parent tool cwd wins over process getcwd() / agent construction cwd.
            $loop->setWorkingDirectory($context->workingDirectory);

            // Compose with any existing pump (e.g. MCP poll from SdkRunFactory).
            // setEventPump would clobber streamable HTTP polling.
            if ($context->shouldAbort !== null) {
                $parentAbort = $context->shouldAbort;
                $loop->appendEventPump(static function () use ($loop, $parentAbort): void {
                    if ($parentAbort()) {
                        $loop->abort();
                    }
                });
            }

            $text = $loop->run(userInput: $task);

            return ToolResult::success($text, [
                'inputTokens' => $loop->getLocalInputTokens(),
                'outputTokens' => $loop->getLocalOutputTokens(),
                'cost' => $loop->getLocalEstimatedCost(),
                'sessionId' => $this->agent->ephemeral ? null : $loop->getSessionManager()->getSessionId(),
            ]);
        } catch (HumanInterruptException $e) {
            $run?->preserveSandboxOnClose();
            throw $e;
        } catch (\Throwable $e) {
            return ToolResult::error('Agent tool error: '.$e->getMessage());
        } finally {
            $run?->close();
        }
    }

    public function isReadOnly(array $input): bool
    {
        return false;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return false;
    }
}
