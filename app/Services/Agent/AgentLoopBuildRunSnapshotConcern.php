<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hitl\HitlAllowlist;
use HaoCode\Services\Hitl\HitlReviewer;
use HaoCode\Services\Hitl\SmartInterruptDecider;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;

trait AgentLoopBuildRunSnapshotConcern
{

    /**
     * Capture the scoped child-run identity needed for a process-safe resume.
     *
     * @return array<string, mixed>
     */
    private function buildRunSnapshot(int $turnCount): array
    {
        return AgentRunSnapshotBuilder::build(
            turnCount: $turnCount,
            maxTurns: $this->maxTurns,
            cwd: $this->getCurrentWorkingDirectory(),
            runContext: $this->runContext,
            allowedTools: $this->effectiveAllowedTools(),
            activeSkillAllowedTools: $this->toolOrchestrator->getActiveSkillAllowedTools(),
            activeSkillModelOverride: $this->toolOrchestrator->getActiveSkillModelOverride(),
            activeSkillContext: $this->toolOrchestrator->getActiveSkillContext(),
            baseModel: $this->runBaseModel,
            estimatedCost: $this->getEstimatedCost(),
            totalInputTokens: $this->getTotalInputTokens(),
            totalOutputTokens: $this->getTotalOutputTokens(),
            totalCacheCreationTokens: $this->getCacheCreationTokens(),
            totalCacheReadTokens: $this->getCacheReadTokens(),
            lastTurnInputTokens: $this->lastTurnInputTokens,
            sandboxRuntime: $this->sandboxRuntime,
        );
    }

    /** @param array<string, mixed> $checkpoint */
    private function queueCheckpointReadReceiptsForVisibleResults(array $checkpoint, ToolUseContext $context): void
    {
        $snapshot = $checkpoint['pending_read_file_state'] ?? null;
        if (is_array($snapshot) && $snapshot !== []) {
            $context->queueReadReceiptSnapshotForCurrentBatch($snapshot);
        }
    }

    /** @return string[] */
    private function effectiveAllowedTools(): array
    {
        $allowed = $this->toolOrchestrator->getAdvertisedAllowedTools()
            ?? array_keys($this->toolRegistry->getAllTools());
        if (isset($this->toolRegistry->getAllTools()['Skill'])
            && ! in_array('Skill', $allowed, true)) {
            $allowed[] = 'Skill';
        }

        return $allowed;
    }

    /** @return array<int, array<string, mixed>> */
    private function getActiveSkillApiTools(): array
    {
        $tools = $this->toolRegistry->toApiTools();
        $allowedTools = $this->toolOrchestrator->getAdvertisedAllowedTools();
        if ($allowedTools === null) {
            return $tools;
        }

        $allowedTools[] = 'Skill';

        return array_values(array_filter(
            $tools,
            static fn (array $tool): bool => in_array((string) ($tool['name'] ?? ''), $allowedTools, true),
        ));
    }

    private function withInitialTurnContext(string|array $userInput): string|array
    {
        $turnContext = $this->contextBuilder->buildTurnContext();
        if ($turnContext === '') {
            return $userInput;
        }

        $contextBlock = [
            'type' => 'text',
            'text' => "# Initial workspace context\n\n{$turnContext}",
        ];

        if (is_array($userInput)) {
            return array_merge([$contextBlock], $userInput);
        }

        return [
            $contextBlock,
            ['type' => 'text', 'text' => $userInput],
        ];
    }

    private function finalizeAfterTurnLimit(
        array $systemPrompt,
        ?callable $onTextDelta,
        ?callable $onThinkingDelta,
        ?string $reason = null,
    ): string {
        $this->synchronizeRuntimeContextBudget();
        $this->contextCompactor->microCompact($this->messageHistory);
        $messages = $this->messageHistory->getMessagesForApi();
        $messages[] = [
            'role' => 'user',
            'content' => $reason === 'repeated identical tool failure'
                ? 'The same tool failure has repeated several times. Do not call tools. Return the best final answer now using the evidence already collected, and state any remaining uncertainty.'
                : 'The tool-turn limit has been reached. Do not call tools. Return the best final answer now using the evidence already collected, and state any remaining uncertainty.',
        ];

        $estimatedTokens = ContextBudget::estimateTokens($systemPrompt, $messages, []);
        if ($estimatedTokens > $this->maxEstimatedInputTokens) {
            $this->contextCompactor->compact($this->messageHistory);
            $messages = $this->messageHistory->getMessagesForApi();
            $messages[] = [
                'role' => 'user',
                'content' => 'Return the final answer now without tools, using the retained evidence.',
            ];
            if (ContextBudget::estimateTokens($systemPrompt, $messages, []) > $this->maxEstimatedInputTokens) {
                $this->contextCompactor->emergencyCompact($this->messageHistory);
                $messages = $this->messageHistory->getMessagesForApi();
                $messages[] = [
                    'role' => 'user',
                    'content' => 'Return a concise final answer now without tools, using the retained evidence previews.',
                ];
            }

            $estimatedTokens = ContextBudget::estimateTokens($systemPrompt, $messages, []);
            if ($estimatedTokens > $this->maxEstimatedInputTokens) {
                $this->throwContextBudgetExceeded($estimatedTokens);
            }
        }

        $processor = $this->queryEngine->query(
            systemPrompt: $systemPrompt,
            messages: $messages,
            onTextDelta: $onTextDelta,
            onThinkingDelta: $onThinkingDelta,
            shouldAbort: fn (): bool => $this->cancellationToken->isCancelled(),
            toolsOverride: [],
        );

        if ($this->isCancellationRequested()) {
            return '(aborted)';
        }

        $usage = $this->normalizeUsage($processor->getUsage());
        $this->recordUsage($usage);
        if ($processor->getModel() !== null) {
            $this->costTracker->setResponseModel($processor->getModel());
        }
        $this->costTracker->addUsage(
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $usage['cache_creation_input_tokens'] ?? 0,
            $usage['cache_read_input_tokens'] ?? 0,
        );

        $assistantMessage = $processor->toAssistantMessage();
        $this->messageHistory->addAssistantMessage($assistantMessage);
        $this->persistAssistantTurn($assistantMessage, []);
        $this->hookExecutor?->execute('Stop', [
            'session_id' => $this->sessionManager->getSessionId(),
            'turn' => $this->lastRunTurns,
        ]);

        $answer = trim($processor->getAccumulatedText());

        return $answer !== ''
            ? $answer
            : ($reason === 'repeated identical tool failure'
                ? 'Stopped after repeated identical tool failures without a final answer.'
                : "Reached maximum turn limit ({$this->maxTurns}) without a final answer.");
    }

    private function throwContextBudgetExceeded(int $estimatedTokens): never
    {
        throw new \RuntimeException(
            'Estimated model input exceeds the safe context budget after emergency compaction '.
            sprintf('(estimated %d tokens; safe limit %d). ', $estimatedTokens, $this->maxEstimatedInputTokens).
            'The estimate includes system instructions, conversation history, and advertised tools. '.
            'Reduce the user input, project instructions, or advertised tools.',
        );
    }

    private function synchronizeRuntimeContextBudget(): void
    {
        $settings = $this->runContext?->settings;
        if ($settings === null) {
            return;
        }

        $this->maxEstimatedInputTokens = ContextBudget::safeInputLimit(
            $settings->getContextWindow(),
            $settings->getMaxTokens(),
        );
        $this->contextCompactor->updateLimits(
            $settings->getContextWindow(),
            $this->maxEstimatedInputTokens,
        );
    }

    /** @internal */
    public function getMaxEstimatedInputTokens(): int
    {
        return $this->maxEstimatedInputTokens;
    }

    public function getTotalInputTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getInputTokens() ?? $this->totalInputTokens;
    }

    /** @internal */
    public function getLocalInputTokens(): int
    {
        return $this->totalInputTokens;
    }

    public function getLastTurnInputTokens(): int
    {
        return $this->lastTurnInputTokens;
    }

    public function getLastRunTurns(): int
    {
        return $this->lastRunTurns;
    }

    public function getTotalOutputTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getOutputTokens() ?? $this->totalOutputTokens;
    }

    /** @internal */
    public function getLocalOutputTokens(): int
    {
        return $this->totalOutputTokens;
    }

    public function getEstimatedCost(): float
    {
        return $this->costTracker->getTotalCost();
    }

    /** @internal */
    public function getLocalEstimatedCost(): float
    {
        return $this->costTracker->getLocalTotalCost();
    }

    public function isCostEstimateAvailable(): bool
    {
        return $this->costTracker->isPricingAvailable();
    }

    public function getCostTracker(): CostTracker
    {
        return $this->costTracker;
    }

    /** @internal */
    public function getBudgetLedger(): ?\HaoCode\Services\Cost\BudgetLedger
    {
        return $this->runContext?->budgetLedger;
    }

    /** @internal */
    public function getRunContext(): ?AgentRunContext
    {
        return $this->runContext;
    }

    /** @return list<string> @internal */
    public function getRegisteredToolNames(): array
    {
        $names = array_keys($this->toolRegistry->getAllTools());
        sort($names);

        return $names;
    }

    public function getCacheCreationTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getCacheCreationTokens()
            ?? $this->totalCacheCreationTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->runContext?->usageAccumulator?->getCacheReadTokens()
            ?? $this->totalCacheReadTokens;
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function recordUsage(array $usage): void
    {
        $this->lastTurnInputTokens = max(0, (int) ($usage['context_input_tokens'] ?? $usage['input_tokens'] ?? 0));
        $input = $this->lastTurnInputTokens;
        $output = max(0, (int) ($usage['output_tokens'] ?? 0));
        $cacheCreation = max(0, (int) ($usage['cache_creation_input_tokens'] ?? 0));
        $cacheRead = max(0, (int) ($usage['cache_read_input_tokens'] ?? 0));

        $this->totalInputTokens += $input;
        $this->totalOutputTokens += $output;
        $this->totalCacheCreationTokens += $cacheCreation;
        $this->totalCacheReadTokens += $cacheRead;

        $this->runContext?->usageAccumulator?->add($input, $output, $cacheCreation, $cacheRead);
    }

    /**
     * Normalize provider telemetry before it reaches usage totals or budget
     * accounting. Compatible gateways are external input: malformed usage
     * must never make totals/costs decrease or invalidate a shared ledger.
     *
     * @param array<string, mixed> $usage
     * @return array{input_tokens: int, context_input_tokens: int, output_tokens: int, cache_creation_input_tokens: int, cache_read_input_tokens: int}
     */
    private function normalizeUsage(array $usage): array
    {
        $input = self::nonNegativeUsageCount($usage['input_tokens'] ?? null) ?? 0;
        $contextInput = self::nonNegativeUsageCount($usage['context_input_tokens'] ?? null) ?? $input;

        return [
            'input_tokens' => $input,
            'context_input_tokens' => $contextInput,
            'output_tokens' => self::nonNegativeUsageCount($usage['output_tokens'] ?? null) ?? 0,
            'cache_creation_input_tokens' => self::nonNegativeUsageCount(
                $usage['cache_creation_input_tokens'] ?? null,
            ) ?? 0,
            'cache_read_input_tokens' => self::nonNegativeUsageCount(
                $usage['cache_read_input_tokens'] ?? null,
            ) ?? 0,
        ];
    }

    private static function nonNegativeUsageCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0 ? (int) $value : null;
        }
        if (is_string($value) && is_numeric($value)) {
            $number = (float) $value;

            return is_finite($number) && $number >= 0 ? (int) $number : null;
        }

        return null;
    }

    public function getMessageHistory(): MessageHistory
    {
        return $this->messageHistory;
    }

    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }

    /**
     * Install a run-lifetime skill capability envelope (used by forked skills).
     *
     * @param  list<string>|null  $allowedTools
     * @internal
     */
    public function setBaseSkillScope(?array $allowedTools): void
    {
        $this->toolOrchestrator->setBaseSkillScope($allowedTools);
    }

    /** @internal */
    public function restoreRunSnapshot(array $snapshot): void
    {
        if (is_array($snapshot['allowed_tools'] ?? null)) {
            $this->toolOrchestrator->setResumeAllowedTools($snapshot['allowed_tools']);
        }
        $this->toolOrchestrator->restoreSkillScope(
            is_array($snapshot['active_skill_allowed_tools'] ?? null)
                ? $snapshot['active_skill_allowed_tools']
                : null,
            is_string($snapshot['active_skill_model_override'] ?? null)
                ? $snapshot['active_skill_model_override']
                : null,
            is_string($snapshot['active_skill_context'] ?? null)
                ? $snapshot['active_skill_context']
                : null,
        );

        $this->totalInputTokens = max(0, (int) ($snapshot['total_input_tokens'] ?? 0));
        $this->totalOutputTokens = max(0, (int) ($snapshot['total_output_tokens'] ?? 0));
        $this->totalCacheCreationTokens = max(0, (int) ($snapshot['total_cache_creation_tokens'] ?? 0));
        $this->totalCacheReadTokens = max(0, (int) ($snapshot['total_cache_read_tokens'] ?? 0));
        $this->lastTurnInputTokens = max(0, (int) ($snapshot['last_turn_input_tokens'] ?? 0));
        if (is_numeric($snapshot['estimated_cost_usd'] ?? null)) {
            $this->costTracker->setTotalCost(max(0.0, (float) $snapshot['estimated_cost_usd']));
        }

        // Seed a fresh shared accumulator so resume QueryResult usage matches the
        // checkpoint (AgentAsTool children add on top of this base).
        $acc = $this->runContext?->usageAccumulator;
        if ($acc !== null
            && $acc->getInputTokens() === 0
            && $acc->getOutputTokens() === 0
            && $acc->getCacheCreationTokens() === 0
            && $acc->getCacheReadTokens() === 0
            && ($this->totalInputTokens > 0
                || $this->totalOutputTokens > 0
                || $this->totalCacheCreationTokens > 0
                || $this->totalCacheReadTokens > 0)) {
            $acc->add(
                $this->totalInputTokens,
                $this->totalOutputTokens,
                $this->totalCacheCreationTokens,
                $this->totalCacheReadTokens,
            );
        }
    }

    public function resetSessionMetrics(): void
    {
        $this->aborted = false;
        $this->sessionStarted = false;
        $this->runContext?->usageAccumulator?->reset();
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;
        $this->totalCacheCreationTokens = 0;
        $this->totalCacheReadTokens = 0;
        $this->lastTurnInputTokens = 0;
        $this->lastRunTurns = 0;
        $this->costTracker->reset();
    }
}
