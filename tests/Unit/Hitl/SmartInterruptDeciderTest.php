<?php

declare(strict_types=1);

namespace Tests\Unit\Hitl;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanActionRequest;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterrupt;
use HaoCode\Sdk\Message;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Services\Hitl\HitlReviewer;
use HaoCode\Services\Hitl\SmartInterruptDecider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two-phase batch decision semantics ported from the bridge worker:
 * rule fast-path approvals, red-line/ask batch escalation with collateral
 * events, guardian review allow/deny/unsure paths, both circuit breakers,
 * auto mode, and fail-closed behaviour.
 */
class SmartInterruptDeciderTest extends TestCase
{
    private const PROVIDER_CONFIG = [
        'apiKey' => 'sk-test-key',
        'model' => 'test-model',
        'baseUrl' => null,
        'providerType' => 'anthropic',
    ];

    private string $cwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwd = sys_get_temp_dir();
    }

    /** @param array<string, mixed>|\Throwable $result */
    private function reviewerWith(array|\Throwable $result): HitlReviewer
    {
        $runner = static function () use ($result): StructuredResult {
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return new StructuredResult($result);
        };

        return new HitlReviewer(self::PROVIDER_CONFIG, $this->cwd, $runner);
    }

    private static function verdict(string $outcome, string $risk = 'low', string $rationale = 'Routine and reversible.'): array
    {
        return [
            'risk_level' => $risk,
            'user_authorization' => 'high',
            'outcome' => $outcome,
            'rationale' => $rationale,
        ];
    }

    /** @param HumanActionRequest[] $actions */
    private function interrupt(array $actions): HumanInterrupt
    {
        return new HumanInterrupt(
            id: 'int_test_1',
            sessionId: 'session-test',
            actions: $actions,
            createdAt: date('c'),
        );
    }

    private static function approvable(string $id, string $toolName, array $input): HumanActionRequest
    {
        return new HumanActionRequest($id, $toolName, $input, 'Review this action');
    }

    private function smartDecider(?HitlReviewer $reviewer): SmartInterruptDecider
    {
        return new SmartInterruptDecider('smart', $reviewer, $this->cwd, 'session-test');
    }

    // ─── smart mode: rule fast path ─────────────────────────────────────

    public function test_all_auto_allow_batch_is_approved_without_a_reviewer(): void
    {
        $decider = $this->smartDecider(null);
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => 'pwd']),
            self::approvable('call-2', 'Read', ['file_path' => '/tmp/x']),
        ]), 'list the directory');

        $this->assertSame('auto', $batch['status']);
        $this->assertCount(2, $batch['decisions']);
        $this->assertContainsOnlyInstancesOf(HumanDecision::class, $batch['decisions']);
        foreach ($batch['decisions'] as $decision) {
            $this->assertSame('approve', $decision->type);
        }
        $this->assertCount(2, $batch['events']);
        foreach ($batch['events'] as $event) {
            $this->assertTrue($event->isAutoDecision());
            $this->assertSame('approve', $event->decision);
            $this->assertSame('rule', $event->source);
            $this->assertSame('low', $event->riskLevel);
            $this->assertSame('session-test', $event->sessionId);
            $this->assertSame('int_test_1', $event->interruptId);
        }
        $this->assertSame('call-1', $batch['events'][0]->actionId);
        $this->assertSame('call-2', $batch['events'][1]->actionId);
    }

    public function test_red_line_action_escalates_the_whole_batch_with_collateral(): void
    {
        $decider = $this->smartDecider($this->reviewerWith(self::verdict('allow')));
        $batch = $decider->decide($this->interrupt([
            self::approvable('safe-1', 'Bash', ['command' => 'pwd']),
            self::approvable('red-1', 'Bash', ['command' => 'sudo ls']),
        ]), 'inspect things');

        $this->assertSame('human', $batch['status']);
        $this->assertCount(2, $batch['events']);

        [$collateral, $redLine] = $batch['events'];
        $this->assertSame('escalate', $collateral->decision);
        $this->assertSame('batch', $collateral->source);
        $this->assertSame('low', $collateral->riskLevel);
        $this->assertStringStartsWith('batch:escalated', (string) $collateral->reason);

        $this->assertSame('escalate', $redLine->decision);
        $this->assertSame('rule', $redLine->source);
        $this->assertSame('high', $redLine->riskLevel);
        $this->assertStringStartsWith('rule:red_line:', (string) $redLine->reason);
    }

    public function test_non_approvable_action_escalates_as_rule_ask(): void
    {
        $decider = $this->smartDecider(null);
        $batch = $decider->decide($this->interrupt([
            new HumanActionRequest('ask-1', 'AskUserQuestion', ['questions' => []], 'Pick one', ['respond', 'reject']),
        ]), 'choose');

        $this->assertSame('human', $batch['status']);
        $this->assertCount(1, $batch['events']);
        $event = $batch['events'][0];
        $this->assertSame('escalate', $event->decision);
        $this->assertSame('rule', $event->source);
        $this->assertSame('medium', $event->riskLevel);
        $this->assertStringStartsWith('rule:ask:', (string) $event->reason);
    }

    public function test_empty_or_malformed_interrupt_escalates_without_events(): void
    {
        $decider = $this->smartDecider(null);

        $batch = $decider->decide($this->interrupt([]), 'nothing');
        $this->assertSame('human', $batch['status']);
        $this->assertSame([], $batch['events']);

        $blank = new HumanInterrupt('', '', [self::approvable('a', 'Read', ['file_path' => '/tmp/x'])], date('c'));
        $batch = $decider->decide($blank, 'nothing');
        $this->assertSame('human', $batch['status']);
        $this->assertSame([], $batch['events']);
    }

    // ─── smart mode: guardian review paths ──────────────────────────────

    public function test_gray_action_review_allow_is_approved_with_review_source(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('allow', 'medium', 'Bounded and reversible.'));
        $batch = $this->smartDecider($reviewer)->decide($this->interrupt([
            self::approvable('gray-1', 'Bash', ['command' => 'gem install rake']),
        ]), 'install the build tool');

        $this->assertSame('auto', $batch['status']);
        $this->assertSame('approve', $batch['decisions'][0]->type);
        $event = $batch['events'][0];
        $this->assertSame('approve', $event->decision);
        $this->assertSame('review', $event->source);
        $this->assertSame('medium', $event->riskLevel);
        $this->assertSame('Bounded and reversible.', $event->reason);
    }

    public function test_gray_action_review_deny_is_rejected_with_guardian_message(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('deny', 'high', 'Destroys user data.'));
        $batch = $this->smartDecider($reviewer)->decide($this->interrupt([
            self::approvable('gray-1', 'Bash', ['command' => 'gem install rake']),
        ]), 'install the build tool');

        $this->assertSame('auto', $batch['status']);
        $decision = $batch['decisions'][0];
        $this->assertSame('reject', $decision->type);
        $this->assertStringContainsString('Automatically rejected by the smart HITL guardian.', (string) $decision->message);
        $this->assertStringContainsString('Do not bypass this rejection', (string) $decision->message);
        $this->assertStringContainsString('Rationale: Destroys user data.', (string) $decision->message);

        $event = $batch['events'][0];
        $this->assertSame('reject', $event->decision);
        $this->assertSame('review', $event->source);
        $this->assertSame('high', $event->riskLevel);
    }

    public function test_gray_action_review_unsure_escalates_the_batch(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('unsure', 'medium', 'Cannot confirm authorization.'));
        $batch = $this->smartDecider($reviewer)->decide($this->interrupt([
            self::approvable('safe-1', 'Bash', ['command' => 'pwd']),
            self::approvable('gray-1', 'Bash', ['command' => 'gem install rake']),
        ]), 'install the build tool');

        $this->assertSame('human', $batch['status']);
        [$collateral, $unsure] = $batch['events'];
        $this->assertSame('escalate', $collateral->decision);
        $this->assertStringStartsWith('batch:escalated', (string) $collateral->reason);
        $this->assertSame('escalate', $unsure->decision);
        $this->assertSame('review', $unsure->source);
        $this->assertStringStartsWith('review:unsure', (string) $unsure->reason);
        $this->assertStringContainsString('Cannot confirm authorization.', (string) $unsure->reason);
    }

    public function test_gray_action_review_failure_escalates_as_unavailable(): void
    {
        $reviewer = $this->reviewerWith(new \RuntimeException('network down'));
        $batch = $this->smartDecider($reviewer)->decide($this->interrupt([
            self::approvable('gray-1', 'Bash', ['command' => 'gem install rake']),
        ]), 'install the build tool');

        $this->assertSame('human', $batch['status']);
        $event = $batch['events'][0];
        $this->assertSame('escalate', $event->decision);
        $this->assertSame('review', $event->source);
        $this->assertStringStartsWith('review:unavailable:', (string) $event->reason);
        $this->assertStringContainsString('Model review failed or timed out', (string) $event->reason);
    }

    public function test_gray_circuit_breaker_escalates_without_calling_the_model(): void
    {
        $reviewer = $this->reviewerWith(new \RuntimeException('boom'));
        $decider = $this->smartDecider($reviewer);

        // Two failed reviews open the gray circuit breaker.
        $decider->decide($this->interrupt([self::approvable('g1', 'Bash', ['command' => 'gem install a'])]), 't');
        $decider->decide($this->interrupt([self::approvable('g2', 'Bash', ['command' => 'gem install b'])]), 't');
        $this->assertTrue($reviewer->shouldEscalateGrayToAsk());

        $batch = $decider->decide($this->interrupt([self::approvable('g3', 'Bash', ['command' => 'gem install c'])]), 't');
        $this->assertSame('human', $batch['status']);
        $this->assertStringStartsWith('review:unavailable: Review circuit breaker open', (string) $batch['events'][0]->reason);
    }

    public function test_batch_circuit_breaker_escalates_everything(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('deny', 'high'));
        $decider = $this->smartDecider($reviewer);

        // Three consecutive denies trip the batch circuit breaker.
        foreach (['a', 'b', 'c'] as $n) {
            $batch = $decider->decide($this->interrupt([self::approvable('d-'.$n, 'Bash', ['command' => 'gem install '.$n])]), 't');
            $this->assertSame('auto', $batch['status']);
            $this->assertSame('reject', $batch['decisions'][0]->type);
        }
        $this->assertTrue($reviewer->shouldEscalateBatchToHuman());

        $batch = $decider->decide($this->interrupt([
            self::approvable('safe-1', 'Bash', ['command' => 'pwd']),
        ]), 't');
        $this->assertSame('human', $batch['status']);
        $this->assertCount(1, $batch['events']);
        $this->assertSame('batch', $batch['events'][0]->source);
        $this->assertSame('high', $batch['events'][0]->riskLevel);
        $this->assertStringStartsWith('batch:circuit_breaker', (string) $batch['events'][0]->reason);
    }

    // ─── auto mode ──────────────────────────────────────────────────────

    public function test_auto_mode_approves_tool_actions_without_rules_or_review(): void
    {
        $decider = new SmartInterruptDecider('auto', null, $this->cwd, 'session-test');
        $batch = $decider->decide($this->interrupt([
            // Even a rule red line is auto-approved in auto mode; the policy is not consulted.
            self::approvable('call-1', 'Bash', ['command' => 'sudo ls']),
        ]), 'run it');

        $this->assertSame('auto', $batch['status']);
        $this->assertSame('approve', $batch['decisions'][0]->type);
        $event = $batch['events'][0];
        $this->assertSame('approve', $event->decision);
        $this->assertSame('rule', $event->source);
        $this->assertSame('low', $event->riskLevel);
        $this->assertStringContainsString('auto', (string) $event->reason);
    }

    public function test_auto_mode_still_escalates_non_approvable_actions(): void
    {
        $decider = new SmartInterruptDecider('auto', null, $this->cwd, 'session-test');
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => 'pwd']),
            new HumanActionRequest('ask-1', 'AskUserQuestion', ['questions' => []], 'Pick one', ['respond', 'reject']),
        ]), 'run and ask');

        $this->assertSame('human', $batch['status']);
        $this->assertCount(2, $batch['events']);
        $this->assertStringStartsWith('batch:escalated', (string) $batch['events'][0]->reason);
        $this->assertStringStartsWith('rule:ask:', (string) $batch['events'][1]->reason);
    }

    // ─── fail-closed ────────────────────────────────────────────────────

    public function test_decider_failure_escalates_fail_closed(): void
    {
        // An interrupt whose actions trigger a decider-internal error must
        // never fail open. Simulate by passing an action object that blows up
        // on property access is not possible with readonly classes, so instead
        // assert the contract on a null-reviewer smart batch with a gray item:
        // missing reviewer must escalate, never approve.
        $decider = new SmartInterruptDecider('smart', null, $this->cwd, 'session-test');
        $batch = $decider->decide($this->interrupt([
            self::approvable('gray-1', 'Bash', ['command' => 'gem install rake']),
        ]), 'install the build tool');

        $this->assertSame('human', $batch['status']);
        $this->assertStringStartsWith('review:unavailable:', (string) $batch['events'][0]->reason);
    }

    public function test_events_carry_message_contract(): void
    {
        $decider = $this->smartDecider(null);
        $batch = $decider->decide($this->interrupt([
            self::approvable('call-1', 'Bash', ['command' => 'pwd']),
        ]), 'list');

        $event = $batch['events'][0];
        $this->assertInstanceOf(Message::class, $event);
        $this->assertSame('auto_decision', $event->type);
        $this->assertSame('Bash', $event->toolName);
        $this->assertSame(['command' => 'pwd'], $event->toolInput);
    }
}
