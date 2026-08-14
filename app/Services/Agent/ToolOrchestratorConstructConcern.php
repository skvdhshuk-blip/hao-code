<?php

namespace HaoCode\Services\Agent;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Permissions\PermissionChecker;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Services\ToolResult\ToolResultStorage;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolResult;

trait ToolOrchestratorConstructConcern
{

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly PermissionChecker $permissionChecker,
        private readonly HookExecutor $hookExecutor,
        private readonly ?PhoenixTracer $tracer = null,
        private readonly float $parallelToolTimeoutSeconds = self::DEFAULT_PARALLEL_TOOL_TIMEOUT_SECONDS,
    ) {
        $this->skillScope = new SkillScopeState();
        if (! is_finite($this->parallelToolTimeoutSeconds) || $this->parallelToolTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Parallel tool timeout must be greater than zero.');
        }
    }

    private function skillScope(): SkillScopeState
    {
        return $this->skillScope ??= new SkillScopeState();
    }

    public function setToolResultStorage(ToolResultStorage $storage): void
    {
        $this->toolResultStorage = $storage;
    }

    public function getToolResultStorage(): ?ToolResultStorage
    {
        return $this->toolResultStorage;
    }

    public function setPermissionPromptHandler(callable $handler): void
    {
        $this->permissionPromptHandler = $handler;
    }

    /** @internal */
    public function configureHumanInterrupts(array $interruptOn, bool $enableAskUser): void
    {
        $this->interruptOn = $interruptOn;
        $this->enableAskUser = $enableAskUser;
    }

    /** @internal */
    public function getInterruptOn(): array
    {
        return $this->interruptOn;
    }

    /** @internal */
    public function isAskUserEnabled(): bool
    {
        return $this->enableAskUser;
    }

    /** @internal */
    public function hasHumanInterruptsConfigured(): bool
    {
        return $this->interruptOn !== [] || $this->enableAskUser || $this->enablePermissionInterrupts;
    }

    /** @internal */
    public function enablePermissionInterrupts(bool $enabled): void
    {
        $this->enablePermissionInterrupts = $enabled;
    }

    /** @internal */
    public function arePermissionInterruptsEnabled(): bool
    {
        return $this->enablePermissionInterrupts;
    }

    /** @internal */
    public function setResumeAllowedTools(?array $toolNames): void
    {
        $this->resumeAllowedTools = $toolNames === null ? null : array_values(array_unique($toolNames));
    }

    /**
     * Validate, normalize, hook and permission-check a complete assistant tool batch
     * before any gated action is executed.
     *
     * @return array{prepared: array<int, array>, results: array<int, array>, actions: array<int, HumanActionRequest>}
     * @internal
     */
    public function prepareHumanReview(array $blocks, ToolUseContext $context, bool $suppressConfiguredGate = false): array
    {
        $prepared = [];
        $results = [];
        $actions = [];

        foreach ($blocks as $index => $block) {
            $outcome = $this->prepareOneForHumanReview($block, $context, $suppressConfiguredGate);
            if (isset($outcome['result'])) {
                $results[$index] = $outcome['result'];
            } else {
                $prepared[$index] = $outcome['block'];
                if (isset($outcome['action'])) {
                    $actions[$index] = $outcome['action'];
                }
            }
        }

        return compact('prepared', 'results', 'actions');
    }

    /** @internal */
    public function executePreparedToolBlock(
        array $block,
        ToolUseContext $context,
        ?callable $onStart = null,
        ?callable $onComplete = null,
    ): array {
        $block['_haocode_prepared'] = true;

        return $this->executeSingleTool($block, $context, $onStart, $onComplete);
    }

    public function resetSkillScope(): void
    {
        $this->skillScope()->reset();
    }

    /**
     * Install a run-lifetime capability envelope (forked skills). Call before
     * {@see AgentLoop::run()} so resetSkillScope restores this envelope.
     *
     * @param  list<string>|null  $allowedTools
     * @internal
     */
    public function setBaseSkillScope(?array $allowedTools): void
    {
        $this->skillScope()->setBase($allowedTools);
    }

    /** @internal */
    public function restoreSkillScope(
        ?array $allowedTools,
        ?string $modelOverride,
        ?string $context,
    ): void
    {
        $this->skillScope()->restore($allowedTools, $modelOverride, $context);
    }

    /**
     * Active skill capability specs (may include patterns like Bash(cargo:*)).
     *
     * @return list<string>|null
     */
    public function getActiveSkillAllowedTools(): ?array
    {
        return $this->skillScope()->getAllowed();
    }

    /**
     * Tool names advertised to the model under the active scope (patterns stripped).
     *
     * @return string[]|null @internal
     */
    public function getAdvertisedAllowedTools(): ?array
    {
        return $this->skillScope()->getAdvertised($this->resumeAllowedTools);
    }

    public function getActiveSkillModelOverride(): ?string
    {
        return $this->skillScope()->getModelOverride();
    }

    public function getActiveSkillContext(): string
    {
        return $this->skillScope()->getContext();
    }

    /** @internal */
    public function mayRunPreToolUseHook(string $toolName): bool
    {
        return $this->hookExecutor->hasHooksFor('PreToolUse', $toolName);
    }

    /**
     * Parallel workers must not run any tool lifecycle hook.  Post hooks can
     * still mutate files or external state even when the tool itself is
     * read-only, and failure hooks can have the same side effects.
     *
     * @internal
     */
    public function mayRunToolHooks(string $toolName): bool
    {
        foreach (['PreToolUse', 'PostToolUse', 'PostToolUseFailure'] as $event) {
            if ($this->hookExecutor->hasHooksFor($event, $toolName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parallel workers must not execute a tool while an approval callback may
     * need to run.  The callback is owned by the parent conversation and is
     * not safe to invoke from a forked child; a child cannot suspend the
     * parent's model turn waiting for a human decision.
     *
     * @internal
     */
    public function mayRunPermissionPrompts(): bool
    {
        return $this->permissionPromptHandler !== null || $this->enablePermissionInterrupts;
    }

    /**
     * Execute a single tool block (public entry point for streaming executor).
     */
    public function executeToolBlock(
        array $block,
        ToolUseContext $context,
        ?callable $onStart = null,
        ?callable $onComplete = null,
    ): array {
        return $this->executeSingleTool($block, $context, $onStart, $onComplete);
    }

    /**
     * Execute a set of tool_use blocks from the API response.
     * Parallelizes contiguous runs of concurrency-safe (read-only) tools.
     *
     * @param array $toolUseBlocks Array of {id, name, input} from API
     * @return array Array of API-format tool_result blocks
     */
    public function executeTools(
        array $toolUseBlocks,
        ToolUseContext $context,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
    ): array {
        return ReadReceiptBatch::execute(
            $context,
            fn (): array => $this->executeToolsInBatch(
                $toolUseBlocks,
                $context,
                $onToolStart,
                $onToolComplete,
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $toolUseBlocks
     * @return array<int, array<string, mixed>>
     */
    private function executeToolsInBatch(
        array $toolUseBlocks,
        ToolUseContext $context,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
    ): array {
        if ($context->isAborted()) {
            $results = [];
            foreach ($toolUseBlocks as $block) {
                $result = ToolResult::aborted();
                if ($onToolComplete) {
                    $onToolComplete((string) ($block['name'] ?? ''), $result);
                }
                $results[] = $result->toApiFormat((string) ($block['id'] ?? ''));
            }

            return $results;
        }

        if (count($toolUseBlocks) <= 1) {
            // Single tool: no need for parallelism
            $results = [];
            foreach ($toolUseBlocks as $block) {
                $results[] = $this->executeSingleTool($block, $context, $onToolStart, $onToolComplete);
            }
            return $results;
        }

        // A stateful tool is an execution barrier. Only run a contiguous safe
        // segment in parallel so a later read-only tool observes any preceding
        // mutation instead of being scheduled ahead of it.
        $results = [];
        $safeRun = [];
        $flushSafeRun = function () use (
            &$safeRun,
            &$results,
            $context,
            $onToolStart,
            $onToolComplete,
        ): void {
            if ($safeRun === []) {
                return;
            }

            foreach ($this->executeInParallel($safeRun, $context, $onToolStart, $onToolComplete) as $origIdx => $result) {
                $results[$origIdx] = $result;
            }
            $safeRun = [];
        };

        foreach ($toolUseBlocks as $origIdx => $block) {
            $tool = $this->toolRegistry->getTool($block['name']);
            $isSafe = false;
            $preparedBlock = $block;
            $rawInput = $block['input'] ?? null;
            if ($tool !== null && is_array($rawInput)) {
                try {
                    // Run the same validation and normalization pipeline that
                    // sequential execution uses before deciding whether a
                    // worker may be forked.  Otherwise an invalid invocation can
                    // emit onStart from the parent before the child rejects it,
                    // and context-derived fields are missing from that callback.
                    $preparedInput = $this->validateAndNormalizeInput($tool, $rawInput, $context);
                    if ($preparedInput['error'] === null) {
                        $classificationInput = $preparedInput['input'];
                        $isSafe = $tool->isConcurrencySafe($classificationInput)
                            && $tool->isReadOnly($classificationInput)
                            && ! $this->mayRunToolHooks($tool->name())
                            && ! $this->mayRunPermissionPrompts();
                        if ($isSafe) {
                            // Keep the parent callback and the child invocation
                            // on the same observable input.  The child still
                            // re-runs the normal execution pipeline.
                            $preparedBlock['input'] = $classificationInput;
                        }
                    }
                } catch (\Throwable) {
                    // A validation/normalization/classification failure must
                    // fail closed and use the sequential error path.
                    $isSafe = false;
                }
            }
            if ($isSafe) {
                $safeRun[$origIdx] = $preparedBlock;
                continue;
            }

            $flushSafeRun();
            $results[$origIdx] = $this->executeSingleTool($block, $context, $onToolStart, $onToolComplete);
        }
        $flushSafeRun();

        // Re-sort by original call order and strip keys
        ksort($results);
        return array_values($results);
    }

    /**
     * Execute safe tools in isolated child processes while preserving call order.
     */
    private function executeInParallel(
        array $blocks,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $executor = new ParallelToolExecutor(
            executeTool: function (
                array $block,
                ToolUseContext $toolContext,
                ?callable $start,
                ?callable $complete,
            ): array {
                return $this->executeSingleTool($block, $toolContext, $start, $complete);
            },
            timeoutSeconds: $this->parallelToolTimeoutSeconds,
        );

        return $executor->execute($blocks, $context, $onStart, $onComplete);
    }

    private function executeSingleTool(
        array $block,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $toolUseId = $block['id'];
        $toolName = $block['name'];
        $input = $block['input'] ?? [];

        $toolSpan = $this->tracer?->startSpan(
            name: "tool.{$toolName}",
            openInferenceKind: PhoenixTracer::KIND_TOOL,
            attributes: [
                'tool.name' => $toolName,
                'tool.call_id' => (string) $toolUseId,
                'input.value' => json_encode($input, JSON_UNESCAPED_UNICODE) ?: '',
                'input.mime_type' => 'application/json',
            ],
        );
        $toolScope = $toolSpan?->activate();

        try {
            $apiResult = $this->executeSingleToolInner($block, $context, $onStart, $onComplete);

            if ($toolSpan !== null) {
                // Route through PhoenixTracer::setAttribute so tool output is
                // masked when redact_messages is on. A direct setAttribute
                // here used to leak file contents / Bash output / MCP payloads
                // regardless of the redaction flag.
                $this->tracer?->setAttribute($toolSpan, 'output.value', (string) ($apiResult['content'] ?? ''));
                $this->tracer?->setAttribute($toolSpan, 'tool.is_error', (bool) ($apiResult['is_error'] ?? false));
            }

            return $apiResult;
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->tracer?->recordException($toolSpan, $e);
            throw $e;
        } finally {
            $toolScope?->detach();
            $toolSpan?->end();
        }
    }
}
