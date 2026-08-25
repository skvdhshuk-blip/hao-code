<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Cost\CostTracker;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Session\SessionManager;

/** Produces the final no-tools answer after a loop terminal limit. @internal */
final class AgentFinalResponseCoordinator
{
    public function finalize(
        array $systemPrompt,
        ?callable $onTextDelta,
        ?callable $onThinkingDelta,
        ?string $reason,
        int $maxTurns,
        int $maxInputTokens,
        int $lastRunTurns,
        ContextCompactor $compactor,
        MessageHistory $history,
        QueryEngine $queryEngine,
        ContextBuilder $contextBuilder,
        CostTracker $costTracker,
        AgentTranscriptLifecycle $transcript,
        ?HookExecutor $hooks,
        SessionManager $sessions,
        callable $isCancelled,
        callable $normalizeUsage,
        callable $recordUsage,
    ): AgentRunOutcome {
        $compactor->microCompact($history);
        $messages = $history->getMessagesForApi();
        $messages[] = [
            'role' => 'user',
            'content' => $reason === 'repeated identical tool failure'
                ? 'The same tool failure has repeated several times. Do not call tools. Return the best final answer now using the evidence already collected, and state any remaining uncertainty.'
                : 'The tool-turn limit has been reached. Do not call tools. Return the best final answer now using the evidence already collected, and state any remaining uncertainty.',
        ];
        $estimated = ContextBudget::estimateTokens($systemPrompt, $messages, []);
        if ($estimated > $maxInputTokens) {
            $compactor->compact($history);
            $messages = $history->getMessagesForApi();
            $messages[] = ['role' => 'user', 'content' =>
                'Return the final answer now without tools, using the retained evidence.'];
            if (ContextBudget::estimateTokens($systemPrompt, $messages, []) > $maxInputTokens) {
                $compactor->emergencyCompact($history);
                $messages = $history->getMessagesForApi();
                $messages[] = ['role' => 'user', 'content' =>
                    'Return a concise final answer now without tools, using the retained evidence previews.'];
            }
            $estimated = ContextBudget::estimateTokens($systemPrompt, $messages, []);
            if ($estimated > $maxInputTokens) {
                throw new \RuntimeException(
                    'Estimated model input exceeds the safe context budget after emergency compaction '
                    .sprintf('(estimated %d tokens; safe limit %d). ', $estimated, $maxInputTokens)
                    .'The estimate includes system instructions, conversation history, and advertised tools. '
                    .'Reduce the user input, project instructions, or advertised tools.',
                );
            }
        }
        $processor = $queryEngine->query(
            systemPrompt: $systemPrompt,
            messages: $messages,
            onTextDelta: $onTextDelta,
            onThinkingDelta: $onThinkingDelta,
            shouldAbort: $isCancelled,
            toolsOverride: [],
            telemetrySystemPrompt: $contextBuilder->getTelemetrySystemPrompt(),
            telemetryMessages: $history->getTelemetryMessagesForApi(),
        );
        if ($isCancelled()) {
            return AgentRunOutcome::cancelled();
        }
        $usage = $normalizeUsage($processor->getUsage());
        $recordUsage($usage);
        if ($processor->getModel() !== null) {
            $costTracker->setResponseModel($processor->getModel());
        }
        $costTracker->addUsage(
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $usage['cache_creation_input_tokens'] ?? 0,
            $usage['cache_read_input_tokens'] ?? 0,
        );
        $assistant = $processor->toAssistantMessage();
        $history->addAssistantMessage($assistant);
        $transcript->persistTurn($assistant, []);
        $hooks?->execute('Stop', [
            'session_id' => $sessions->getSessionId(),
            'turn' => $lastRunTurns,
        ]);
        $answer = trim($processor->getAccumulatedText());
        $text = $answer !== ''
            ? $answer
            : ($reason === 'repeated identical tool failure'
                ? 'Stopped after repeated identical tool failures without a final answer.'
                : "Reached maximum turn limit ({$maxTurns}) without a final answer.");

        return AgentRunOutcome::turnLimit(
            $text,
            $reason === 'repeated identical tool failure',
        );
    }
}
