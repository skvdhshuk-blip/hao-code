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

trait ToolOrchestratorExecuteSingleToolInnerConcern
{

    /**
     * Original executeSingleTool body, wrapped by {@see executeSingleTool()} so
     * the span lifecycle stays out of the permission / hook / validation logic.
     */
    private function executeSingleToolInner(
        array $block,
        ToolUseContext $context,
        ?callable $onStart,
        ?callable $onComplete,
    ): array {
        $toolUseId = $block['id'];
        $toolName = $block['name'];
        $input = $block['input'] ?? [];
        $isPrepared = ($block['_haocode_prepared'] ?? false) === true;

        if ($context->isAborted()) {
            return ToolResult::aborted()->toApiFormat($toolUseId);
        }

        $tool = $this->toolRegistry->getTool($toolName);

        if ($tool === null || !$tool->isEnabled()) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => "Unknown tool: {$toolName}",
                'is_error' => true,
            ];
        }

        if (! $isPrepared) {
            // Stages 1-2b: validate and normalize before hooks observe the input.
            $preparedInput = $this->validateAndNormalizeInput($tool, $input, $context);
            if ($preparedInput['error'] !== null) {
                return [
                    'tool_use_id' => $toolUseId,
                    'content' => $preparedInput['error'],
                    'is_error' => true,
                ];
            }
            $input = $preparedInput['input'];

            // Stage 3: PreToolUse hooks
            if ($context->isAborted()) {
                return ToolResult::aborted()->toApiFormat($toolUseId);
            }
            $hookResult = $this->hookExecutor->execute('PreToolUse', [
                'tool' => $toolName,
                'input' => $input,
            ], static fn (): bool => $context->isAborted());

            if ($context->isAborted()) {
                return ToolResult::aborted()->toApiFormat($toolUseId);
            }

            if (! $hookResult->allowed) {
                return [
                    'tool_use_id' => $toolUseId,
                    'content' => 'Blocked by hook: '.$hookResult->output,
                    'is_error' => true,
                ];
            }

            if ($hookResult->modifiedInput !== null) {
                $preparedInput = $this->validateAndNormalizeInput($tool, $hookResult->modifiedInput, $context);
                if ($preparedInput['error'] !== null) {
                    return [
                        'tool_use_id' => $toolUseId,
                        'content' => $preparedInput['error'],
                        'is_error' => true,
                    ];
                }
                $input = $preparedInput['input'];
            }

            // Stage 4: Permission check
            $decision = $this->permissionChecker->check($tool, $input, $context);

            if (! $decision->allowed) {
                // Only prompt the user for "ask" decisions (needsPrompt=true).
                // Hard "deny" decisions (deny rules, plan-mode writes) must never be
                // overridden by a permission prompt — they should always fail immediately.
                if ($decision->needsPrompt && $this->permissionPromptHandler) {
                    $userApproved = ($this->permissionPromptHandler)($toolName, $input);
                    if (! $userApproved) {
                        return [
                            'tool_use_id' => $toolUseId,
                            'content' => 'Permission denied by user',
                            'is_error' => true,
                        ];
                    }
                } else {
                    return [
                        'tool_use_id' => $toolUseId,
                        'content' => "Permission denied: ".($decision->reason ?? 'Not allowed'),
                        'is_error' => true,
                    ];
                }
            }
        }

        if (! $this->isAllowedByActiveSkillScope($toolName, is_array($input) ? $input : [])) {
            return [
                'tool_use_id' => $toolUseId,
                'content' => "Tool {$toolName} is not allowed by the active skill scope.",
                'is_error' => true,
            ];
        }

        if ($context->isAborted()) {
            return ToolResult::aborted()->toApiFormat($toolUseId);
        }

        if ($onStart) {
            $onStart($toolName, $input);
        }

        if ($context->isAborted()) {
            $result = ToolResult::aborted();
            if ($onComplete) {
                $onComplete($toolName, $result);
            }

            return $result->toApiFormat($toolUseId);
        }

        // Execute the tool
        try {
            $result = $tool->call($input, $context);
            if ($context->isAborted() || $result->outcome() === ToolOutcome::Aborted) {
                $result = $result->outcome() === ToolOutcome::Aborted
                    ? $result
                    : ToolResult::aborted();
            } else {
                $this->activateSkillScope($toolName, $result, $context);

                // PostToolUse hooks (success path)
                $postHookResult = $this->hookExecutor->execute('PostToolUse', [
                    'tool' => $toolName,
                    'input' => $input,
                    'output' => $result->output,
                    'isError' => $result->isError,
                ], static fn (): bool => $context->isAborted());

                if ($context->isAborted()) {
                    $result = ToolResult::aborted();
                } elseif ($postHookResult->output) {
                    $result = new ToolResult(
                        output: $result->output . "\n[Hook] " . $postHookResult->output,
                        isError: $result->isError,
                        metadata: $result->metadata,
                    );
                }
            }
        } catch (\HaoCode\Sdk\HumanInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($context->isAborted()) {
                $result = ToolResult::aborted();
            } else {
                $result = ToolResult::error("Tool execution error: " . $e->getMessage());

                // PostToolUseFailure hooks (error path)
                $failHookResult = $this->hookExecutor->execute('PostToolUseFailure', [
                    'tool' => $toolName,
                    'input' => $input,
                    'error' => $e->getMessage(),
                ], static fn (): bool => $context->isAborted());

                if ($context->isAborted()) {
                    $result = ToolResult::aborted();
                } elseif ($failHookResult->output) {
                    $result = new ToolResult(
                        output: $result->output . "\n[Hook] " . $failHookResult->output,
                        isError: true,
                        metadata: $result->metadata,
                    );
                }
            }
        }

        // Repeated-Read nudge: when an agent reads the same file many times
        // without editing it, it's almost always "I forgot what I just saw"
        // rather than a legitimate use case. Append a short hint into the
        // tool_result so the agent is reminded to reuse the content it already
        // has. A Write/Edit on the same path resets the counter because after
        // a mutation re-reading is expected.
        if ($result->outcome() !== ToolOutcome::Aborted) {
            $result = $this->annotateRepeatedReads($toolName, $input, $result);
        }

        // Persist large results to disk (or truncate as fallback)
        $toolMaxChars = $tool->maxResultSizeChars();
        $maxChars = min($toolMaxChars, ToolResultStorage::MAX_SINGLE_RESULT_CHARS);
        $resultWasCompacted = false;
        if ($result->outcome() !== ToolOutcome::Aborted
            && mb_strlen($result->output) > $maxChars
        ) {
            if ($toolMaxChars < PHP_INT_MAX && $this->toolResultStorage !== null) {
                $persisted = $this->toolResultStorage->persist($toolUseId, $result->output);
                if ($persisted !== null) {
                    $result = new ToolResult(
                        output: $persisted['message'],
                        isError: $result->isError,
                        metadata: $result->metadata,
                    );
                    $resultWasCompacted = true;
                }
            }
            // Fallback: inline truncation if persistence failed or unavailable
            if (mb_strlen($result->output) > $maxChars) {
                $storage = $this->toolResultStorage ?? new ToolResultStorage();
                $preview = $storage->generatePreview($result->output, ToolResultStorage::PREVIEW_SIZE_BYTES);
                $sizeLabel = round(mb_strlen($result->output) / 1024, 1) . 'K chars';
                $result = new ToolResult(
                    output: "<persisted-output>\nOutput too large ({$sizeLabel}). Showing first 2KB preview:\n\n{$preview}\n...(truncated)\n</persisted-output>",
                    isError: $result->isError,
                    metadata: $result->metadata,
                );
                $resultWasCompacted = true;
            }
        }
        if ($resultWasCompacted && $toolName === 'Read'
            && is_string($input['file_path'] ?? null)
        ) {
            $context->markFileReadIncomplete($input['file_path']);
        }

        if ($onComplete) {
            $onComplete($toolName, $result);
        }

        return $result->toApiFormat($toolUseId);
    }

    /** @return array{block?: array, result?: array, action?: HumanActionRequest} */
    private function prepareOneForHumanReview(array $block, ToolUseContext $context, bool $suppressConfiguredGate): array
    {
        $toolUseId = (string) ($block['id'] ?? '');
        $toolName = (string) ($block['name'] ?? '');
        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
        $tool = $this->toolRegistry->getTool($toolName);

        $error = static fn (string $message): array => ['result' => [
            'tool_use_id' => $toolUseId,
            'content' => $message,
            'is_error' => true,
        ]];

        if ($context->isAborted()) {
            return $error('Tool execution aborted');
        }

        if ($tool === null || ! $tool->isEnabled()) {
            return $error("Unknown tool: {$toolName}");
        }

        $preparedInput = $this->validateAndNormalizeInput($tool, $input, $context);
        if ($preparedInput['error'] !== null) {
            return $error($preparedInput['error']);
        }
        $input = $preparedInput['input'];

        $hookResult = $this->hookExecutor->execute(
            'PreToolUse',
            ['tool' => $toolName, 'input' => $input],
            static fn (): bool => $context->isAborted(),
        );
        if ($context->isAborted()) {
            return $error('Tool execution aborted');
        }
        if (! $hookResult->allowed) {
            return $error('Blocked by hook: '.$hookResult->output);
        }
        if ($hookResult->modifiedInput !== null) {
            $preparedInput = $this->validateAndNormalizeInput($tool, $hookResult->modifiedInput, $context);
            if ($preparedInput['error'] !== null) {
                return $error($preparedInput['error']);
            }
            $input = $preparedInput['input'];
        }

        if (! $this->isAllowedByActiveSkillScope($toolName, $input)) {
            return $error("Tool {$toolName} is not allowed by the active skill scope.");
        }

        $decision = $this->permissionChecker->check($tool, $input, $context);
        if (! $decision->allowed && ! $decision->needsPrompt) {
            return $error('Permission denied: '.($decision->reason ?? 'Not allowed'));
        }

        $configured = $this->interruptOn[$toolName] ?? false;
        $shouldInterrupt = ! $suppressConfiguredGate && (
            $decision->needsPrompt
            || $configured !== false
            || ($toolName === 'AskUserQuestion' && $this->enableAskUser)
        );

        $preparedBlock = $block;
        $preparedBlock['input'] = $input;

        if (! $shouldInterrupt) {
            return ['block' => $preparedBlock];
        }

        $allowed = ['approve', 'edit', 'reject', 'respond'];
        $description = $decision->reason ?? "Approve {$toolName}";
        if (is_array($configured)) {
            if (is_array($configured['allowedDecisions'] ?? null)) {
                $allowed = array_values(array_intersect($allowed, $configured['allowedDecisions']));
            }
            if (is_string($configured['description'] ?? null) && trim($configured['description']) !== '') {
                $description = trim($configured['description']);
            }
        }
        if ($toolName === 'AskUserQuestion') {
            $allowed = ['respond', 'reject'];
            $description = 'Answer the agent question';
        }
        if ($allowed === []) {
            return $error("No valid human decisions configured for {$toolName}.");
        }

        return [
            'block' => $preparedBlock,
            'action' => new HumanActionRequest(
                id: $toolUseId,
                toolName: $toolName,
                input: $input,
                description: $description,
                allowedDecisions: $allowed,
                agentId: $context->runContext?->agentId,
            ),
        ];
    }

    /**
     * Apply the complete validation and observable-input normalization pipeline.
     *
     * @return array{input: array, error: ?string}
     */
    private function validateAndNormalizeInput(
        ToolInterface $tool,
        array $input,
        ToolUseContext $context,
    ): array {
        try {
            $input = $tool->inputSchema()->validate($input);
        } catch (\InvalidArgumentException $e) {
            return [
                'input' => [],
                'error' => '<tool_use_error>InputValidationError: '.$e->getMessage().'</tool_use_error>',
            ];
        }

        $validationError = $tool->validateInput($input, $context);
        if ($validationError !== null) {
            return [
                'input' => [],
                'error' => '<tool_use_error>Validation: '.$validationError.'</tool_use_error>',
            ];
        }

        return [
            'input' => $tool->backfillObservableInput($input, $context),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isAllowedByActiveSkillScope(string $toolName, array $input = []): bool
    {
        return $this->skillScope()->allows($toolName, $input, $this->resumeAllowedTools);
    }

    private function activateSkillScope(string $toolName, ToolResult $result, ?ToolUseContext $toolContext = null): void
    {
        $this->skillScope()->activate($toolName, $result, $toolContext);
    }

    /**
     * Track per-file Read counts and, above the threshold, append a short
     * hint to the Read result. Write/Edit on the same path resets the count.
     */
    private function annotateRepeatedReads(string $toolName, array $input, ToolResult $result): ToolResult
    {
        if ($result->isError) {
            return $result;
        }

        $path = $input['file_path'] ?? null;
        if (! is_string($path) || $path === '') {
            return $result;
        }

        if ($toolName === 'Write' || $toolName === 'Edit' || $toolName === 'NotebookEdit') {
            unset($this->readCountsByFile[$path]);

            return $result;
        }

        if ($toolName !== 'Read') {
            return $result;
        }

        $count = ($this->readCountsByFile[$path] ?? 0) + 1;
        $this->readCountsByFile[$path] = $count;

        if ($count < self::REPEATED_READ_HINT_THRESHOLD) {
            return $result;
        }

        $hint = "\n\n[hint] You have now read {$path} {$count} times this session without modifying it. "
              . 'If you are paginating a large file that is fine, but otherwise prefer reusing the content '
              . 'you already have in memory rather than re-reading.';

        return new ToolResult(
            output: $result->output . $hint,
            isError: false,
            metadata: $result->metadata,
        );
    }
}
