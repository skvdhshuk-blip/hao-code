<?php

namespace HaoCode\Services\Agent;

use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\HumanInterruptException;
use HaoCode\Services\Session\SessionManager;
use HaoCode\Tools\ToolUseContext;

/** @internal */
final class HumanInterruptCoordinator
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ToolOrchestrator $tools,
    ) {}

    /**
     * @param array<int, HumanDecision|array<string, mixed>> $decisions
     * @return array{interrupt: HumanInterrupt, checkpoint: array, results: array}
     */
    public function resolve(
        string $interruptId,
        array $decisions,
        ToolUseContext $context,
        ?callable $onToolStart = null,
        ?callable $onToolComplete = null,
    ): array {
        $serialized = self::serializeDecisions($decisions);
        $pending = $this->sessions->getInterruptState($this->sessions->getSessionId(), $interruptId);
        if (($pending['type'] ?? null) !== 'interrupt_pending') {
            $state = str_replace('interrupt_', '', (string) ($pending['type'] ?? 'unknown'));
            throw new \RuntimeException("Interrupt {$interruptId} is already {$state}; automatic retry is disabled.");
        }

        $pendingInterrupt = HumanInterrupt::fromArray($pending['interrupt'] ?? []);
        $decisionMap = self::validateSerializedDecisions($pendingInterrupt, $serialized);

        // Claim first, then run everything else under failInterrupt so a
        // post-claim crash cannot leave the interrupt permanently "resolving".
        $claim = $this->sessions->claimInterrupt(
            $this->sessions->getSessionId(),
            $interruptId,
            $serialized,
        );
        $sideEffectStatus = 'none';
        $results = [];
        try {
            $interrupt = HumanInterrupt::fromArray($claim['interrupt'] ?? []);
            $checkpoint = is_array($claim['checkpoint'] ?? null) ? $claim['checkpoint'] : [];
            $this->restoreCheckpointPolicy($checkpoint);

            $blocks = is_array($checkpoint['blocks'] ?? null) ? $checkpoint['blocks'] : [];
            $results = is_array($checkpoint['results'] ?? null) ? $checkpoint['results'] : [];
            $actionMap = [];
            foreach ($interrupt->actions as $action) {
                $actionMap[$action->id] = $action;
            }

            foreach ($blocks as $index => $block) {
                $id = (string) ($block['id'] ?? '');
                if (! isset($actionMap[$id])) {
                    continue;
                }
                $action = $actionMap[$id];
                $decision = $decisionMap[$id];

                if ($decision->type === 'reject') {
                    $results[$index] = $action->toolName === 'AskUserQuestion'
                        ? [
                            'tool_use_id' => $id,
                            'content' => json_encode(['status' => 'cancelled', 'answers' => []], JSON_UNESCAPED_SLASHES),
                            'is_error' => false,
                        ]
                        : [
                            'tool_use_id' => $id,
                            'content' => 'Rejected by human'.($decision->message !== null ? ': '.$decision->message : ''),
                            'is_error' => true,
                        ];
                    continue;
                }
                if ($decision->type === 'respond') {
                    $content = is_string($decision->response)
                        ? $decision->response
                        : (json_encode($decision->response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null');
                    $results[$index] = ['tool_use_id' => $id, 'content' => $content, 'is_error' => false];
                    continue;
                }
                if ($decision->type === 'edit') {
                    $block['input'] = $decision->editedInput;
                    $review = $this->tools->prepareHumanReview([$block], $context, true);
                    if (isset($review['results'][0])) {
                        $results[$index] = $review['results'][0];
                        continue;
                    }
                    $block = $review['prepared'][0];
                }
                try {
                    $results[$index] = $this->tools->executePreparedToolBlock(
                        $block,
                        $context,
                        $onToolStart,
                        $onToolComplete,
                    );
                    $sideEffectStatus = 'partial';
                } catch (HumanInterruptException $childInterrupt) {
                    foreach ($checkpoint['blocks'] ?? [] as $siblingIndex => $sibling) {
                        if ($siblingIndex === $index || isset($results[$siblingIndex])) {
                            continue;
                        }
                        $results[$siblingIndex] = [
                            'tool_use_id' => $sibling['id'],
                            'content' => 'Deferred because a child agent requires human input; retry after the child resumes.',
                            'is_error' => true,
                        ];
                    }
                    $waitAction = new HumanActionRequest(
                        id: $id,
                        toolName: (string) $block['name'],
                        input: $block['input'] ?? [],
                        description: 'Continue with the resumed child agent result',
                        allowedDecisions: ['respond', 'reject'],
                        agentId: $interrupt->sourceAgentId,
                    );
                    $waitingInterrupt = new HumanInterrupt(
                        id: $interrupt->id,
                        sessionId: $interrupt->sessionId,
                        actions: [$waitAction],
                        createdAt: date('c'),
                        sourceAgentId: $interrupt->sourceAgentId,
                        sourceTeam: $interrupt->sourceTeam,
                    );
                    $checkpoint['blocks'] = [$index => $block];
                    $checkpoint['results'] = $results;
                    $this->sessions->recordChildWaitInterrupt($waitingInterrupt->toArray(), $checkpoint);
                    $this->sessions->recordInterruptParentLink(
                        $childInterrupt->interrupt->sessionId,
                        $childInterrupt->interrupt->id,
                        $waitingInterrupt->sessionId,
                        $waitingInterrupt->id,
                        $waitAction->id,
                    );
                    throw $childInterrupt;
                }
            }

            ksort($results);
            $results = array_values($results);
            $this->sessions->resolveInterrupt($interruptId, $results);

            return compact('interrupt', 'checkpoint', 'results');
        } catch (HumanInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->sessions->failInterrupt(
                $this->sessions->getSessionId(),
                $interruptId,
                $e->getMessage(),
                $sideEffectStatus,
                $results === [] ? null : $results,
            );
            throw $e;
        }
    }

    /**
     * Validate before a durable sandbox is reattached. The coordinator repeats
     * validation under the session claim, but this early pass prevents an
     * invalid decision from opening and then cleaning a still-pending lease.
     *
     * @param  array<int, HumanDecision|array<string, mixed>>  $decisions
     * @internal
     */
    public static function assertValidDecisions(HumanInterrupt $interrupt, array $decisions): void
    {
        self::validateSerializedDecisions($interrupt, self::serializeDecisions($decisions));
    }

    /**
     * @param  array<int, HumanDecision|array<string, mixed>>  $decisions
     * @return list<array<string, mixed>>
     */
    private static function serializeDecisions(array $decisions): array
    {
        return array_map(
            static fn (HumanDecision|array $decision): array => $decision instanceof HumanDecision
                ? $decision->toArray()
                : $decision,
            $decisions,
        );
    }

    /** @return array<string, HumanDecision> */
    private static function validateSerializedDecisions(HumanInterrupt $interrupt, array $serialized): array
    {
        $actions = [];
        foreach ($interrupt->actions as $action) {
            $actions[$action->id] = $action;
        }
        $decisions = [];
        foreach ($serialized as $value) {
            $decision = HumanDecision::fromArray($value);
            if ($decision->actionId === '' || isset($decisions[$decision->actionId])) {
                throw new \InvalidArgumentException('Each human decision must have a unique non-empty action ID.');
            }
            $action = $actions[$decision->actionId] ?? null;
            if ($action === null) {
                throw new \InvalidArgumentException("Unknown action ID: {$decision->actionId}.");
            }
            if (! in_array($decision->type, $action->allowedDecisions, true)) {
                throw new \InvalidArgumentException("Decision {$decision->type} is not allowed for action {$decision->actionId}.");
            }
            if ($decision->type === 'respond' && $action->toolName === 'AskUserQuestion') {
                $error = self::validateAskUserResponse($action->input, $decision->response);
                if ($error !== null) {
                    throw new \InvalidArgumentException($error);
                }
            }
            $decisions[$decision->actionId] = $decision;
        }
        if (count($decisions) !== count($actions)) {
            throw new \InvalidArgumentException('A decision is required for every interrupted action.');
        }

        return $decisions;
    }

    private function restoreCheckpointPolicy(array $checkpoint): void
    {
        if (is_array($checkpoint['interrupt_on'] ?? null)) {
            $this->tools->configureHumanInterrupts(
                $checkpoint['interrupt_on'],
                (bool) ($checkpoint['enable_ask_user'] ?? false),
            );
            $this->tools->enablePermissionInterrupts((bool) ($checkpoint['permission_interrupts'] ?? false));
        }
        $this->tools->setResumeAllowedTools(
            is_array($checkpoint['allowed_tools'] ?? null) ? $checkpoint['allowed_tools'] : null,
        );
    }

    private static function validateAskUserResponse(array $input, mixed $response): ?string
    {
        if (! is_array($response)) {
            return 'AskUserQuestion response must be an array with status and answers.';
        }
        if (! in_array($response['status'] ?? null, ['answered', 'cancelled'], true)) {
            return 'AskUserQuestion response status must be answered or cancelled.';
        }
        if ($response['status'] === 'cancelled') {
            return null;
        }
        $answers = $response['answers'] ?? null;
        $questions = $input['questions'] ?? [];
        if (! is_array($answers) || count($answers) !== count($questions)) {
            return 'AskUserQuestion response must contain exactly one answer entry per question.';
        }
        foreach ($questions as $index => $question) {
            $answer = $answers[$index] ?? null;
            if (($question['required'] ?? true) !== false && ($answer === null || $answer === '' || $answer === [])) {
                return "Question {$index} requires an answer.";
            }
            if (($question['type'] ?? null) === 'text') {
                if ($answer !== null && ! is_string($answer)) {
                    return "Question {$index} text answer must be a string or null.";
                }
                continue;
            }
            if ($answer === null || $answer === '') {
                continue;
            }
            $values = ($question['multiple'] ?? false) ? $answer : [$answer];
            if (! is_array($values)) {
                return "Question {$index} answer has an invalid shape.";
            }
            foreach ($values as $value) {
                if (! in_array($value, $question['options'] ?? [], true)) {
                    return "Question {$index} answer is not one of the allowed options.";
                }
            }
        }

        return null;
    }
}
