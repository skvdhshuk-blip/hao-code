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

trait AgentLoopMarkSessionResumedConcern
{

    /**
     * Mark that an existing durable transcript has been loaded into this loop.
     *
     * A resumed session needs its tool-result storage rebound to the canonical
     * session id, but it must not replay first-turn workspace context or the
     * SessionStart hook.
     *
     * @internal
     */
    public function markSessionResumed(): void
    {
        $this->sessionStarted = true;
        $this->initializeDurableToolResultStorage();
    }

    /**
     * Revoke write authorization when aggregate-budget compaction means the
     * model received only a persisted preview of an otherwise complete Read.
     *
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, array{tool_use_id: string, content: string, is_error: bool}>  $before
     * @param  array<int, array{tool_use_id: string, content: string, is_error: bool}>  $after
     */
    private function invalidateCompactedReadReceipts(
        array $toolCalls,
        array $before,
        array $after,
        ToolUseContext $context,
    ): void {
        $readPaths = [];
        foreach ($toolCalls as $toolCall) {
            $path = $toolCall->input['file_path'] ?? null;
            if ($toolCall->name === 'Read' && is_string($path) && $path !== '') {
                $readPaths[$toolCall->id] = $path;
            }
        }
        if ($readPaths === []) {
            return;
        }

        $visibleContent = [];
        foreach ($after as $result) {
            $id = $result['tool_use_id'] ?? null;
            $content = $result['content'] ?? null;
            if (is_string($id) && is_string($content)) {
                $visibleContent[$id] = $content;
            }
        }

        foreach ($before as $result) {
            $id = $result['tool_use_id'] ?? null;
            $content = $result['content'] ?? null;
            if (! is_string($id) || ! is_string($content)
                || ! isset($readPaths[$id], $visibleContent[$id])
                || hash_equals($content, $visibleContent[$id])
            ) {
                continue;
            }

            $context->markFileReadIncomplete($readPaths[$id]);
        }
    }

    /**
     * Detect a model repeating the same valid tool-error batch without changing
     * its approach. Tool-use IDs are deliberately excluded because providers
     * generate a new ID for every retry.
     *
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, array<string, mixed>>  $toolResults
     */
    private function detectRepeatedToolErrorBatch(
        array $toolCalls,
        array $toolResults,
        ?string &$lastFingerprint,
        int &$repeatCount,
    ): bool {
        $resultsById = [];
        foreach ($toolResults as $result) {
            $id = $result['tool_use_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $resultsById[$id] = $result;
            }
        }

        $entries = [];
        $hasError = false;
        foreach ($toolCalls as $toolCall) {
            $result = $resultsById[$toolCall->id] ?? null;
            $isError = is_array($result) && ($result['is_error'] ?? false) === true;
            $entry = [
                'name' => $toolCall->name,
                'input' => $this->canonicalizeFingerprintValue($toolCall->input),
                'is_error' => $isError,
                'error' => null,
            ];

            if ($isError) {
                $hasError = true;
                $content = $result['content'] ?? '';
                if (! is_string($content)) {
                    $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $content = is_string($encoded) ? $encoded : get_debug_type($content);
                }
                $normalized = preg_replace('/\s+/u', ' ', trim($content));
                $entry['error'] = mb_substr($normalized === null ? $content : $normalized, 0, 512);
            }

            $entries[] = $entry;
        }

        if (! $hasError) {
            $lastFingerprint = null;
            $repeatCount = 0;

            return false;
        }

        $encoded = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            $lastFingerprint = null;
            $repeatCount = 0;

            return false;
        }

        $fingerprint = hash('sha256', $encoded);
        if ($fingerprint === $lastFingerprint) {
            $repeatCount++;
        } else {
            $lastFingerprint = $fingerprint;
            $repeatCount = 1;
        }

        return $repeatCount >= self::MAX_IDENTICAL_TOOL_ERROR_BATCHES;
    }

    private function canonicalizeFingerprintValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            $normalized[$key] = $this->canonicalizeFingerprintValue($child);
        }
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @return array<int, array{id: string, name: string, error: string}>
     */
    private function findMalformedToolUseFailures(array $toolCalls, ToolUseContext $context): array
    {
        return $this->responseRetryPolicy()->findMalformedToolUseFailures($toolCalls, $context);
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    private function sanitizeMalformedToolAssistantMessage(array $assistantMessage, array $failures): array
    {
        return $this->responseRetryPolicy()->sanitizeMalformedToolAssistantMessage($assistantMessage, $failures);
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     * @return array<int, array{tool_use_id: string, content: string, is_error: bool}>
     */
    private function buildMalformedToolRetryResults(array $failures): array
    {
        return $this->responseRetryPolicy()->buildMalformedToolRetryResults($failures);
    }

    private function buildMalformedToolRetryMessage(string $toolName, string $error): string
    {
        return $this->responseRetryPolicy()->buildMalformedToolRetryMessage($toolName, $error);
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    private function buildMalformedToolRetryInstruction(array $failures, int $retryCount): string
    {
        return $this->responseRetryPolicy()->buildMalformedToolRetryInstruction($failures, $retryCount);
    }

    /**
     * @param array<int, array{id: string, name: string, error: string}> $failures
     */
    private function malformedFailureSignature(array $failures): string
    {
        return $this->responseRetryPolicy()->malformedFailureSignature($failures);
    }

    private function isToolInputJsonFailure(string $error): bool
    {
        return $this->responseRetryPolicy()->isToolInputJsonFailure($error);
    }

    private function summarizeMalformedToolInput(string $rawInput): ?string
    {
        return $this->responseRetryPolicy()->summarizeMalformedToolInput($rawInput);
    }

    private function shouldRetryIncompleteAssistantResponse(
        StreamProcessor $processor,
        array $assistantMessage,
        ?string $stopReason,
        int $retryCount,
    ): bool {
        return $this->responseRetryPolicy()->shouldRetryIncompleteAssistantResponse(
            $processor,
            $assistantMessage,
            $stopReason,
            $retryCount,
            $this->maxIncompleteResponseRetries,
        );
    }

    private function assistantMessageHasVisibleContent(array $assistantMessage): bool
    {
        return $this->responseRetryPolicy()->assistantMessageHasVisibleContent($assistantMessage);
    }

    private function recordIncompleteAssistantResponse(array $assistantMessage, bool $skipHistory = false): void
    {
        if ($skipHistory || ! $this->assistantMessageHasVisibleContent($assistantMessage)) {
            return;
        }

        $this->messageHistory->addAssistantMessage($assistantMessage);
        $this->persistAssistantTurn($assistantMessage, []);
    }

    private function initializeDurableToolResultStorage(): void
    {
        if (! $this->sessionManager->isPersistenceEnabled()) {
            return;
        }

        $this->toolOrchestrator->setToolResultStorage(
            new ToolResultStorage($this->sessionManager->getSessionId()),
        );
    }

    private function assertDurableConversationUsable(): void
    {
        if ($this->durablePersistenceFailed) {
            throw new \RuntimeException(
                'This durable conversation cannot continue because a previous transcript write failed. '
                .'Create or resume a fresh conversation from the last persisted state.',
            );
        }
    }

    private function persistAssistantTurn(array $assistantMessage, array $toolResults): void
    {
        try {
            $this->sessionManager->recordTurn($assistantMessage, $toolResults);
        } catch (\Throwable $e) {
            $this->durablePersistenceFailed = true;

            throw new \RuntimeException(
                'Model or tool execution may have completed, but the durable transcript could not be written. '
                .'This conversation is no longer safe to continue.',
                0,
                $e,
            );
        }
    }

    private function persistSessionTitle(string $title): void
    {
        try {
            $this->sessionManager->setTitle($title);
        } catch (\Throwable $e) {
            $this->durablePersistenceFailed = true;

            throw new \RuntimeException(
                'Model or tool execution completed, but the durable session title could not be written. '
                .'This conversation is no longer safe to continue.',
                0,
                $e,
            );
        }
    }

    private function buildIncompleteResponseRetryInstruction(
        ?string $stopReason,
        int $retryCount,
        bool $skipHistory = false,
    ): string {
        return $this->responseRetryPolicy()->buildIncompleteResponseRetryInstruction(
            $stopReason,
            $retryCount,
            $skipHistory,
        );
    }

    private function shouldSkipIncompleteAssistantHistory(array $assistantMessage): bool
    {
        return $this->responseRetryPolicy()->shouldSkipIncompleteAssistantHistory($assistantMessage);
    }

    private function isNarrationOnlyAssistantMessage(array $assistantMessage): bool
    {
        return $this->responseRetryPolicy()->isNarrationOnlyAssistantMessage($assistantMessage);
    }

    private function isLowValueNarrationText(string $text): bool
    {
        return $this->responseRetryPolicy()->isLowValueNarrationText($text);
    }
}
