<?php

namespace Tests\Sdk;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Message;
use PHPUnit\Framework\TestCase;

final class SmartHitlSurfaceTest extends TestCase
{
    public function test_hitl_mode_defaults_to_ask(): void
    {
        $config = new HaoCodeConfig;
        $this->assertSame('ask', $config->hitlMode);
        $this->assertNull($config->hitlReviewModel);
    }

    public function test_hitl_mode_accepts_the_three_supported_modes(): void
    {
        foreach (['ask', 'smart', 'auto'] as $mode) {
            $this->assertSame($mode, (new HaoCodeConfig(hitlMode: $mode))->hitlMode);
        }
    }

    public function test_hitl_mode_normalizes_unknown_values_to_ask(): void
    {
        $this->assertSame('ask', (new HaoCodeConfig(hitlMode: 'yolo'))->hitlMode);
        $this->assertSame('ask', (new HaoCodeConfig(hitlMode: ''))->hitlMode);
        $this->assertSame('ask', (new HaoCodeConfig(hitlMode: 'SMART'))->hitlMode);
    }

    public function test_hitl_review_model_normalization(): void
    {
        $this->assertNull((new HaoCodeConfig(hitlReviewModel: ''))->hitlReviewModel);
        $this->assertNull((new HaoCodeConfig(hitlReviewModel: '   '))->hitlReviewModel);
        $this->assertSame(
            'claude-haiku-4-5-20251001',
            (new HaoCodeConfig(hitlReviewModel: 'claude-haiku-4-5-20251001'))->hitlReviewModel,
        );
    }

    public function test_with_response_schema_preserves_hitl_options(): void
    {
        $config = new HaoCodeConfig(hitlMode: 'smart', hitlReviewModel: 'review-model');
        $derived = $config->withResponseSchema(['type' => 'object']);

        $this->assertSame('smart', $derived->hitlMode);
        $this->assertSame('review-model', $derived->hitlReviewModel);
        $this->assertSame(['type' => 'object'], $derived->responseSchema);
    }

    public function test_auto_decision_message_carries_the_full_contract(): void
    {
        $message = Message::autoDecision(
            sessionId: 'session-1',
            interruptId: 'int-1',
            actionId: 'call-1',
            toolName: 'Bash',
            toolInput: ['command' => 'ls'],
            decision: 'approve',
            source: 'rule',
            riskLevel: 'low',
            reason: 'rule: read-only command allow-listed',
        );

        $this->assertSame('auto_decision', $message->type);
        $this->assertSame('session-1', $message->sessionId);
        $this->assertSame('int-1', $message->interruptId);
        $this->assertSame('call-1', $message->actionId);
        $this->assertSame('Bash', $message->toolName);
        $this->assertSame(['command' => 'ls'], $message->toolInput);
        $this->assertSame('approve', $message->decision);
        $this->assertSame('rule', $message->source);
        $this->assertSame('low', $message->riskLevel);
        $this->assertSame('rule: read-only command allow-listed', $message->reason);

        $this->assertTrue($message->isAutoDecision());
        $this->assertFalse($message->isInterrupt());
        $this->assertFalse($message->isResult());
        $this->assertFalse($message->isError());
    }

    public function test_auto_decision_normalizes_out_of_domain_values(): void
    {
        $message = Message::autoDecision(
            sessionId: 'session-1',
            interruptId: 'int-1',
            actionId: 'call-1',
            toolName: 'Write',
            toolInput: [],
            decision: 'maybe',
            source: 'coin-flip',
            riskLevel: 'extreme',
            reason: 'review: undecided',
        );

        $this->assertSame('escalate', $message->decision);
        $this->assertSame('rule', $message->source);
        $this->assertSame('medium', $message->riskLevel);
    }

    public function test_default_config_file_exposes_hitl_defaults(): void
    {
        $config = require dirname(__DIR__, 2).'/config/haocode.php';

        $this->assertSame('ask', $config['hitl_mode']);
        $this->assertNull($config['hitl_review_model']);
    }
}
