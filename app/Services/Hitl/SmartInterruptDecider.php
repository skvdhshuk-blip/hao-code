<?php

declare(strict_types=1);

namespace HaoCode\Services\Hitl;

use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/**
 * Two-phase batch decider for the smart/auto HITL modes.
 *
 * Ports the batch semantics of the hao-work bridge worker
 * (decideSmartInterrupt) into the SDK agent loop:
 *
 * - Phase 1 classifies every action up front (deterministic rules in smart
 *   mode, blanket approval in auto mode; malformed or non-approvable actions
 *   are always ASK). The classifier is a pure local rule engine, so this is
 *   safe to run even on escalation paths. Two fast paths run before/around
 *   the classifier in smart mode:
 *   - User-saved allow rules (HitlAllowlist) approve an exactly matching
 *     Bash command BEFORE the classifier — the codex "always allow" concept,
 *     which intentionally overrides red lines (user sovereignty).
 *   - A gray Bash action that will genuinely execute inside the configured
 *     sandbox (mode 'full' on an isolating provider) is approved without a
 *     model review — the codex OnRequest concept of running unmatched
 *     commands inside the restricted sandbox instead of asking.
 * - One red-line/ask action escalates the WHOLE batch: the SDK requires all
 *   decisions atomically, so a single human-bound action sends the batch to a
 *   human. Auto-decidable siblings become collateral and gray actions are not
 *   reviewed (saves review budget).
 * - Phase 2 auto-allows rule-approved actions, settles sandbox-contained
 *   gray actions, and sends the remaining gray actions through the guardian
 *   model review (smart mode only). Review allow/deny settle the
 *   action; review unsure/failure/circuit-breaker escalates the batch.
 * - Every settled or escalated action is described by a Message::autoDecision
 *   event. Escalation reasons carry the rule:/review:/batch: prefix family;
 *   sandbox containment approvals carry the sandbox:contained reason.
 *
 * Everything is fail-closed: any unexpected error escalates the batch to a
 * human, never to silent approval.
 *
 * @internal
 */
final class SmartInterruptDecider
{
    /**
     * @param string $mode 'smart' or 'auto' ('ask' never reaches this class).
     * @param HitlReviewer|null $reviewer Guardian reviewer for gray actions;
     *        required in smart mode, unused in auto mode.
     * @param string $cwd Workspace root used by the rule classifier.
     * @param string $fallbackSessionId Session id used when the interrupt
     *        itself does not carry one.
     * @param SandboxConfig|null $sandbox Active sandbox runtime config; gray
     *        Bash actions are auto-approved only when the sandbox genuinely
     *        contains shell execution (mode 'full' on an isolating provider).
     * @param HitlAllowlist|null $allowlist User-saved always-allow rules;
     *        null disables the feature.
     */
    public function __construct(
        private readonly string $mode,
        private readonly ?HitlReviewer $reviewer,
        private readonly string $cwd,
        private readonly string $fallbackSessionId,
        private readonly ?SandboxConfig $sandbox = null,
        private readonly ?HitlAllowlist $allowlist = null,
    ) {}

    /**
     * Decide one interrupt batch.
     *
     * @return array{status: 'auto', decisions: HumanDecision[], events: Message[]}|array{status: 'human', events: Message[]}
     *         - 'auto': the whole batch was settled; decisions cover every
     *           action and must be applied through the normal resume path.
     *         - 'human': the batch escalates; events explain, per action, why
     *           a human is needed (they may be empty when the interrupt itself
     *           is malformed).
     */
    public function decide(HumanInterrupt $interrupt, string $userPrompt): array
    {
        try {
            return $this->decideInternal($interrupt, $userPrompt);
        } catch (\Throwable) {
            // Fail closed: any error escalates the batch to a human.
            return ['status' => 'human', 'events' => []];
        }
    }

    /** @return array{status: 'auto', decisions: HumanDecision[], events: Message[]}|array{status: 'human', events: Message[]} */
    private function decideInternal(HumanInterrupt $interrupt, string $userPrompt): array
    {
        $interruptId = trim($interrupt->id);
        $sessionId = trim($interrupt->sessionId) !== '' ? trim($interrupt->sessionId) : trim($this->fallbackSessionId);
        $actions = array_values($interrupt->actions);
        if ($interruptId === '' || $sessionId === '' || $actions === []) {
            return ['status' => 'human', 'events' => []];
        }

        $autoDecision = static fn (
            string $actionId,
            string $toolName,
            array $input,
            string $decision,
            string $source,
            string $riskLevel,
            string $reason,
        ): Message => Message::autoDecision(
            sessionId: $sessionId,
            interruptId: $interruptId,
            actionId: $actionId,
            toolName: $toolName,
            toolInput: $input,
            decision: $decision,
            source: $source,
            riskLevel: $riskLevel,
            reason: $reason,
        );
        $escalationEvent = static fn (array $item, string $source, string $riskLevel, string $reason): Message => $autoDecision(
            $item['actionId'],
            $item['toolName'],
            $item['input'],
            'escalate',
            $source,
            $riskLevel,
            $reason,
        );
        $collateralEvent = static fn (array $item): Message => $escalationEvent(
            $item,
            'batch',
            $item['level'] === HitlPolicy::AUTO_ALLOW ? 'low' : 'medium',
            'batch:escalated: Escalated because another action in the same batch needs a human.',
        );

        // Phase 1: normalize and classify every action up front.
        $items = [];
        foreach ($actions as $action) {
            $actionId = trim($action->id);
            $toolName = trim($action->toolName);
            $input = $action->input;
            $allowed = $action->allowedDecisions;
            if ($actionId === '' || $toolName === '' || ! in_array('approve', $allowed, true)) {
                // AskUserQuestion / child-agent waits / malformed actions.
                $items[] = [
                    'actionId' => $actionId !== '' ? $actionId : 'unknown',
                    'toolName' => $toolName !== '' ? $toolName : 'unknown',
                    'input' => $input,
                    'allowed' => $allowed,
                    'level' => HitlPolicy::ASK,
                    'reason' => 'Action is malformed or does not allow an approve decision.',
                    'sandboxContained' => false,
                ];
                continue;
            }
            if ($this->mode === 'auto') {
                $items[] = [
                    'actionId' => $actionId,
                    'toolName' => $toolName,
                    'input' => $input,
                    'allowed' => $allowed,
                    'level' => HitlPolicy::AUTO_ALLOW,
                    'reason' => "Auto-approved without rules or model review because hitlMode is 'auto'.",
                    'sandboxContained' => false,
                ];
                continue;
            }
            // User-saved allow rules run BEFORE the rule classifier: an exact
            // match approves the command outright, including commands the
            // classifier would red-line (codex always-allow; user sovereignty).
            if ($this->allowlist !== null && $toolName === 'Bash') {
                $command = is_array($input) ? ($input['command'] ?? null) : null;
                if (is_string($command) && $this->allowlist->matches($command)) {
                    $items[] = [
                        'actionId' => $actionId,
                        'toolName' => $toolName,
                        'input' => $input,
                        'allowed' => $allowed,
                        'level' => HitlPolicy::AUTO_ALLOW,
                        'reason' => 'allowlist:user_rule: User-saved allow rule.',
                        'sandboxContained' => false,
                    ];
                    continue;
                }
            }
            $verdict = HitlPolicy::classifyAction($toolName, $input, $this->cwd);
            $level = is_string($verdict['level'] ?? null) ? $verdict['level'] : HitlPolicy::ASK;
            $items[] = [
                'actionId' => $actionId,
                'toolName' => $toolName,
                'input' => $input,
                'allowed' => $allowed,
                'level' => $level,
                'reason' => is_string($verdict['reason'] ?? null) ? $verdict['reason'] : '',
                // Gray actions only: red lines and ask-level actions are never
                // sandbox-exempted and keep their human escalation.
                'sandboxContained' => $level === HitlPolicy::GRAY && $this->isSandboxContained($toolName),
            ];
        }

        // Batch circuit breaker: repeated auto-rejects escalate everything.
        if ($this->reviewer?->shouldEscalateBatchToHuman()) {
            return [
                'status' => 'human',
                'events' => array_map(
                    static fn (array $item): Message => $escalationEvent(
                        $item,
                        'batch',
                        'high',
                        'batch:circuit_breaker: Too many consecutive auto-rejects; the batch needs a human.',
                    ),
                    $items,
                ),
            ];
        }

        // Rule-level escalation: one red-line/ask action escalates the whole
        // batch; the remaining actions become collateral and gray ones are not
        // reviewed (saves review budget).
        $hasRuleEscalation = false;
        foreach ($items as $item) {
            if ($item['level'] === HitlPolicy::RED_LINE || $item['level'] === HitlPolicy::ASK) {
                $hasRuleEscalation = true;
                break;
            }
        }
        if ($hasRuleEscalation) {
            $events = [];
            foreach ($items as $item) {
                if ($item['level'] === HitlPolicy::RED_LINE) {
                    $events[] = $escalationEvent($item, 'rule', 'high', 'rule:red_line: '.$item['reason']);
                } elseif ($item['level'] === HitlPolicy::ASK) {
                    $events[] = $escalationEvent($item, 'rule', 'medium', 'rule:ask: '.$item['reason']);
                } else {
                    $events[] = $collateralEvent($item);
                }
            }

            return ['status' => 'human', 'events' => $events];
        }

        // Phase 2: auto-allow rules pass; gray actions go through model review.
        $decisions = [];
        $outcomes = [];
        $escalated = false;
        foreach ($items as $index => $item) {
            if ($escalated) {
                $outcomes[$index] = ['kind' => 'pending'];
                continue;
            }
            if ($item['level'] === HitlPolicy::AUTO_ALLOW) {
                $decisions[] = HumanDecision::approve($item['actionId']);
                $outcomes[$index] = [
                    'kind' => 'auto',
                    'event' => $autoDecision($item['actionId'], $item['toolName'], $item['input'], 'approve', 'rule', 'low', $item['reason']),
                ];
                continue;
            }

            // Gray action contained by the sandbox: approve without spending
            // a model review (codex OnRequest parity — unmatched commands run
            // inside the restricted sandbox instead of asking the user).
            if ($item['sandboxContained']) {
                $decisions[] = HumanDecision::approve($item['actionId']);
                $outcomes[$index] = [
                    'kind' => 'auto',
                    'event' => $autoDecision(
                        $item['actionId'],
                        $item['toolName'],
                        $item['input'],
                        'approve',
                        'sandbox',
                        'low',
                        'sandbox:contained: Gray-zone action auto-approved to run inside the configured sandbox.',
                    ),
                ];
                continue;
            }

            // Gray action: eligible for model review.
            if ($this->reviewer === null || $this->reviewer->shouldEscalateGrayToAsk()) {
                $reason = $this->reviewer === null
                    ? 'review:unavailable: No guardian reviewer is configured for smart HITL mode.'
                    : 'review:unavailable: Review circuit breaker open after repeated review failures.';
                $outcomes[$index] = [
                    'kind' => 'escalate',
                    'event' => $escalationEvent($item, 'review', 'medium', $reason),
                ];
                $escalated = true;
                continue;
            }

            $review = $this->reviewer->review($userPrompt, $item['toolName'], $item['input']);
            $outcome = is_string($review['outcome'] ?? null) ? $review['outcome'] : 'ask';
            $riskLevel = is_string($review['riskLevel'] ?? null) ? $review['riskLevel'] : 'medium';
            $rationale = is_string($review['rationale'] ?? null) ? $review['rationale'] : '';
            if ($outcome === 'allow') {
                $decisions[] = HumanDecision::approve($item['actionId']);
                $outcomes[$index] = [
                    'kind' => 'auto',
                    'event' => $autoDecision($item['actionId'], $item['toolName'], $item['input'], 'approve', 'review', $riskLevel, $rationale),
                ];
                continue;
            }
            if ($outcome === 'deny' && in_array('reject', $item['allowed'], true)) {
                $message = 'Automatically rejected by the smart HITL guardian. '
                    .'Do not bypass this rejection or try to reach the same result by other means; '
                    .'only propose a clearly safer alternative or ask the user for explicit approval.'
                    .($rationale !== '' ? ' Rationale: '.$rationale : '');
                $decisions[] = HumanDecision::reject($item['actionId'], $message);
                $outcomes[$index] = [
                    'kind' => 'auto',
                    'event' => $autoDecision($item['actionId'], $item['toolName'], $item['input'], 'reject', 'review', $riskLevel, $rationale),
                ];
                continue;
            }

            // Escalate this action; the rest of the batch becomes collateral.
            if ($outcome === 'deny') {
                $reason = 'review:unsure: Review requested rejection but the action does not allow a reject decision.'
                    .($rationale !== '' ? ' '.$rationale : '');
            } elseif (($failure = self::reviewFailureReason($rationale)) !== null) {
                $reason = 'review:unavailable: '.$failure;
            } else {
                $reason = 'review:unsure: '.($rationale !== '' ? $rationale : 'Reviewer was unsure.');
            }
            $outcomes[$index] = [
                'kind' => 'escalate',
                'event' => $escalationEvent($item, 'review', $riskLevel, $reason),
            ];
            $escalated = true;
        }

        if ($escalated || count($decisions) !== count($items)) {
            $events = [];
            foreach ($items as $index => $item) {
                $outcome = $outcomes[$index] ?? ['kind' => 'pending'];
                $events[] = $outcome['kind'] === 'escalate' ? $outcome['event'] : $collateralEvent($item);
            }

            return ['status' => 'human', 'events' => $events];
        }

        return [
            'status' => 'auto',
            'decisions' => $decisions,
            'events' => array_map(static fn (array $outcome): Message => $outcome['event'], $outcomes),
        ];
    }

    /**
     * Whether the tool genuinely executes inside the configured sandbox.
     *
     * Only Bash with sandbox mode 'full' runs shell commands through the
     * sandbox backend (SandboxRuntime exposes no Bash tool otherwise). The
     * sandboxed file/search tools never produce gray actions, and host-only
     * tools (Edit, apply_patch, …) are disabled while a sandbox is active.
     * The 'local' provider is a working-directory jail without operating
     * system isolation (docs/SDK.md: "Do not use it for untrusted commands"),
     * so it does NOT qualify as containment; native (Seatbelt/bubblewrap),
     * Tokimo (VM), and AgentRun (remote) do.
     */
    private function isSandboxContained(string $toolName): bool
    {
        return $toolName === 'Bash'
            && $this->sandbox !== null
            && $this->sandbox->enablesBash()
            && $this->sandbox->provider !== 'local';
    }

    /**
     * Tell a reviewer infrastructure failure apart from a model "unsure" verdict.
     * Mirrors the fixed fallback rationales in HitlReviewer; returns the failure
     * rationale when one is recognized, null otherwise.
     */
    private static function reviewFailureReason(string $rationale): ?string
    {
        foreach (['Review circuit breaker open', 'Model review failed or timed out'] as $marker) {
            if (str_starts_with($rationale, $marker)) {
                return $rationale;
            }
        }

        return null;
    }
}
