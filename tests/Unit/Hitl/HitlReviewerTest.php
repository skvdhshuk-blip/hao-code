<?php

declare(strict_types=1);

namespace Tests\Unit\Hitl;

use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\StructuredResult;
use HaoCode\Services\Api\ApiErrorException;
use HaoCode\Services\Hitl\HitlReviewer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the guardian review paths with an injected fake structured runner:
 * allow / deny / unsure outcomes, failure and timeout handling, transient
 * retry (max 3 attempts inside one 90s budget), schema-failure retry,
 * rationale truncation, and both circuit breakers (gray→ask, batch→human).
 */
class HitlReviewerTest extends TestCase
{
    private const PROVIDER_CONFIG = [
        'apiKey' => 'sk-test-key',
        'model' => 'test-model',
        'baseUrl' => null,
        'providerType' => 'anthropic',
    ];

    /**
     * @param array<string, mixed>|\Throwable $result
     * @return HitlReviewer reviewer with a fake runner returning $result (or throwing it)
     */
    private function reviewerWith(array|\Throwable $result, ?callable &$spy = null): HitlReviewer
    {
        $runner = static function (string $prompt, array $schema, HaoCodeConfig $config) use ($result): StructuredResult {
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return new StructuredResult($result);
        };
        $spy = $runner;

        return new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);
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

    // ─── outcomes ───────────────────────────────────────────────────────

    public function test_allow_outcome_passes_through(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('allow', 'low', 'Looks safe.'));

        $result = $reviewer->review('please list files', 'Bash', ['command' => 'python3 x.py']);

        $this->assertSame('allow', $result['outcome']);
        $this->assertSame('low', $result['riskLevel']);
        $this->assertSame('Looks safe.', $result['rationale']);
        $this->assertFalse($reviewer->shouldEscalateGrayToAsk());
        $this->assertFalse($reviewer->shouldEscalateBatchToHuman());
    }

    public function test_deny_outcome_passes_through_and_counts_reject(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('deny', 'critical', 'Exfiltrates secrets.'));

        $result = $reviewer->review('do the thing', 'Bash', ['command' => 'gem install x']);

        $this->assertSame('deny', $result['outcome']);
        $this->assertSame('critical', $result['riskLevel']);
        $this->assertSame('Exfiltrates secrets.', $result['rationale']);
        $this->assertFalse($reviewer->shouldEscalateBatchToHuman());
    }

    public function test_unsure_becomes_ask_with_reviewer_rationale(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('unsure', 'high', 'Cannot tell if authorized.'));

        $result = $reviewer->review('vague task', 'Bash', ['command' => 'node x.js']);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('high', $result['riskLevel']);
        $this->assertSame('Cannot tell if authorized.', $result['rationale']);
    }

    public function test_unsure_with_empty_rationale_uses_default_text(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('unsure', 'medium', ''));

        $result = $reviewer->review('task', 'Bash', ['command' => 'node x.js']);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('medium', $result['riskLevel']);
        $this->assertSame('Reviewer was unsure.', $result['rationale']);
    }

    // ─── failure / timeout handling ─────────────────────────────────────

    public function test_runner_exception_escalates_to_human(): void
    {
        $reviewer = $this->reviewerWith(new \RuntimeException('network down'));

        $result = $reviewer->review('task', 'Bash', ['command' => 'node x.js']);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('medium', $result['riskLevel']);
        $this->assertSame('Model review failed or timed out; escalating to a human.', $result['rationale']);
        $this->assertFalse($reviewer->shouldEscalateGrayToAsk());
    }

    public function test_timeout_exception_from_alarm_escalates_to_human(): void
    {
        $reviewer = $this->reviewerWith(new \RuntimeException('HITL guardian review exceeded its time budget.'));

        $result = $reviewer->review('task', 'Bash', ['command' => 'node x.js']);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('Model review failed or timed out; escalating to a human.', $result['rationale']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function malformedResultProvider(): iterable
    {
        yield 'unknown risk level' => [['risk_level' => 'extreme', 'user_authorization' => 'high', 'outcome' => 'allow', 'rationale' => 'x']];
        yield 'unknown authorization' => [['risk_level' => 'low', 'user_authorization' => 'total', 'outcome' => 'allow', 'rationale' => 'x']];
        yield 'unknown outcome' => [['risk_level' => 'low', 'user_authorization' => 'high', 'outcome' => 'yolo', 'rationale' => 'x']];
        yield 'non-string rationale' => [['risk_level' => 'low', 'user_authorization' => 'high', 'outcome' => 'allow', 'rationale' => 42]];
        yield 'missing fields' => [['risk_level' => 'low']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedResultProvider')]
    public function test_malformed_model_result_fails_closed(array $result): void
    {
        $reviewer = $this->reviewerWith($result);

        $review = $reviewer->review('task', 'Bash', ['command' => 'node x.js']);

        $this->assertSame('ask', $review['outcome']);
        $this->assertSame('Model review failed or timed out; escalating to a human.', $review['rationale']);
    }

    public function test_rationale_is_truncated_to_500_chars(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('allow', 'low', str_repeat('r', 600)));

        $result = $reviewer->review('task', 'Bash', ['command' => 'node x.js']);

        $this->assertSame('allow', $result['outcome']);
        $this->assertStringEndsWith('…<truncated/>', $result['rationale']);
        $this->assertSame(str_repeat('r', 500).'…<truncated/>', $result['rationale']);
    }

    // ─── transient retry (codex guardian alignment) ───────────────────

    public function test_time_budget_is_90_seconds(): void
    {
        $constant = (new \ReflectionClass(HitlReviewer::class))->getConstant('TIMEOUT_SECONDS');

        $this->assertSame(90, $constant, 'Guardian review budget must match the codex 90s deadline.');
    }

    public function test_first_attempt_success_does_not_retry(): void
    {
        $calls = 0;
        $runner = static function () use (&$calls): StructuredResult {
            $calls++;

            return new StructuredResult(HitlReviewerTest::verdictPublic('allow'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $result = $reviewer->review('task', 'Bash', ['command' => 'ls']);

        $this->assertSame('allow', $result['outcome']);
        $this->assertSame(1, $calls);
    }

    public function test_transient_exception_twice_then_success(): void
    {
        $calls = 0;
        $runner = static function () use (&$calls): StructuredResult {
            $calls++;
            if ($calls <= 2) {
                throw new ApiErrorException('Service overloaded.', 'overloaded_error');
            }

            return new StructuredResult(HitlReviewerTest::verdictPublic('allow', 'low', 'Recovered.'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $result = $reviewer->review('task', 'Bash', ['command' => 'ls']);

        $this->assertSame('allow', $result['outcome']);
        $this->assertSame('Recovered.', $result['rationale']);
        $this->assertSame(3, $calls);
        $this->assertFalse($reviewer->shouldEscalateGrayToAsk());
    }

    /**
     * @return iterable<string, array{0: \Throwable}>
     */
    public static function transientExceptionProvider(): iterable
    {
        yield 'overloaded' => [new ApiErrorException('Overloaded.', 'overloaded_error')];
        yield 'rate limit' => [new ApiErrorException('Slow down.', 'rate_limit_error')];
        yield 'api error' => [new ApiErrorException('Internal API error.', 'api_error')];
        yield 'stream timeout' => [new ApiErrorException('Stream timed out.', 'stream_timeout')];
        yield 'transport error' => [new ApiErrorException('Network transport error.', 'transport_error')];
        yield 'http 500' => [new ApiErrorException('HTTP 500', 'http_error', 500)];
        yield 'http 503' => [new ApiErrorException('HTTP 503', 'http_error', 503)];
        yield 'http 429' => [new ApiErrorException('HTTP 429', 'http_error', 429)];
        yield 'sdk json parse failure' => [new \RuntimeException("Failed to parse structured response as JSON.\nRaw response: nope")];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transientExceptionProvider')]
    public function test_each_transient_exception_kind_is_retried(\Throwable $error): void
    {
        $calls = 0;
        $runner = static function () use (&$calls, $error): StructuredResult {
            $calls++;
            if ($calls === 1) {
                throw $error;
            }

            return new StructuredResult(HitlReviewerTest::verdictPublic('allow'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $result = $reviewer->review('task', 'Bash', ['command' => 'ls']);

        $this->assertSame('allow', $result['outcome']);
        $this->assertSame(2, $calls);
    }

    public function test_three_transient_failures_ask_and_count_as_one_failure(): void
    {
        $calls = 0;
        $runner = static function () use (&$calls): StructuredResult {
            $calls++;
            throw new ApiErrorException('Service overloaded.', 'overloaded_error');
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $result = $reviewer->review('task', 'Bash', ['command' => 'a']);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('medium', $result['riskLevel']);
        $this->assertSame('Model review failed or timed out; escalating to a human.', $result['rationale']);
        $this->assertSame(3, $calls, 'All three attempts must be consumed before failing closed.');
        $this->assertFalse(
            $reviewer->shouldEscalateGrayToAsk(),
            'Three failed attempts inside one review count as ONE consecutive failure, not three.',
        );

        // Second review also fails 3x → now the breaker opens.
        $reviewer->review('task', 'Bash', ['command' => 'b']);
        $this->assertSame(6, $calls);
        $this->assertTrue($reviewer->shouldEscalateGrayToAsk());
    }

    public function test_schema_validation_failure_then_success(): void
    {
        $calls = 0;
        $runner = static function () use (&$calls): StructuredResult {
            $calls++;
            if ($calls === 1) {
                return new StructuredResult(['risk_level' => 'extreme']); // fails schema validation
            }

            return new StructuredResult(HitlReviewerTest::verdictPublic('allow', 'low', 'Second try parsed.'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $result = $reviewer->review('task', 'Bash', ['command' => 'ls']);

        $this->assertSame('allow', $result['outcome']);
        $this->assertSame('Second try parsed.', $result['rationale']);
        $this->assertSame(2, $calls);
    }

    /**
     * @return iterable<string, array{0: \Throwable}>
     */
    public static function nonTransientExceptionProvider(): iterable
    {
        yield 'authentication error' => [new ApiErrorException('Invalid API key.', 'authentication_error', 401)];
        yield 'permission error' => [new ApiErrorException('Forbidden.', 'permission_error', 403)];
        yield 'bad request 400' => [new ApiErrorException('HTTP 400', 'http_error', 400)];
        yield 'budget alarm exception' => [new \RuntimeException('HITL guardian review exceeded its time budget.')];
        yield 'generic runtime error' => [new \RuntimeException('network down')];
        yield 'config logic error' => [new \LogicException('Misconfigured provider.')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonTransientExceptionProvider')]
    public function test_non_transient_exception_fails_immediately_without_retry(\Throwable $error): void
    {
        $calls = 0;
        $runner = static function () use (&$calls, $error): StructuredResult {
            $calls++;
            throw $error;
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $result = $reviewer->review('task', 'Bash', ['command' => 'a']);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('Model review failed or timed out; escalating to a human.', $result['rationale']);
        $this->assertSame(1, $calls, 'Non-transient errors must not consume retries.');
    }

    public function test_malformed_results_exhaust_all_attempts_before_asking(): void
    {
        $calls = 0;
        $runner = static function () use (&$calls): StructuredResult {
            $calls++;

            return new StructuredResult(['risk_level' => 'extreme']); // always invalid
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $result = $reviewer->review('task', 'Bash', ['command' => 'a']);

        $this->assertSame('ask', $result['outcome']);
        $this->assertSame('Model review failed or timed out; escalating to a human.', $result['rationale']);
        $this->assertSame(3, $calls);
    }

    // ─── circuit breakers ───────────────────────────────────────────────

    public function test_two_consecutive_failures_open_gray_circuit_breaker(): void
    {
        $reviewer = $this->reviewerWith(new \RuntimeException('boom'));

        $reviewer->review('task', 'Bash', ['command' => 'a']);
        $this->assertFalse($reviewer->shouldEscalateGrayToAsk());
        $reviewer->review('task', 'Bash', ['command' => 'b']);
        $this->assertTrue($reviewer->shouldEscalateGrayToAsk());

        $open = $reviewer->review('task', 'Bash', ['command' => 'c']);
        $this->assertSame('ask', $open['outcome']);
        $this->assertSame('medium', $open['riskLevel']);
        $this->assertSame('Review circuit breaker open after repeated review failures.', $open['rationale']);
    }

    public function test_open_circuit_breaker_does_not_call_the_model(): void
    {
        $calls = 0;
        $runner = static function () use (&$calls): StructuredResult {
            $calls++;
            throw new \RuntimeException('boom');
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $reviewer->review('task', 'Bash', ['command' => 'a']);
        $reviewer->review('task', 'Bash', ['command' => 'b']);
        $this->assertSame(2, $calls);

        $reviewer->review('task', 'Bash', ['command' => 'c']);
        $reviewer->review('task', 'Bash', ['command' => 'd']);
        $this->assertSame(2, $calls, 'Model must not be called while the circuit breaker is open.');
    }

    public function test_successful_review_resets_failure_streak(): void
    {
        $fail = true;
        $runner = static function () use (&$fail): StructuredResult {
            if ($fail) {
                throw new \RuntimeException('boom');
            }

            return new StructuredResult(HitlReviewerTest::verdictPublic('allow'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $reviewer->review('task', 'Bash', ['command' => 'a']); // failure 1
        $fail = false;
        $reviewer->review('task', 'Bash', ['command' => 'b']); // success resets
        $fail = true;
        $reviewer->review('task', 'Bash', ['command' => 'c']); // failure 1 again
        $this->assertFalse($reviewer->shouldEscalateGrayToAsk());
        $reviewer->review('task', 'Bash', ['command' => 'd']); // failure 2
        $this->assertTrue($reviewer->shouldEscalateGrayToAsk());
    }

    public function test_three_consecutive_denies_escalate_batch_to_human(): void
    {
        $reviewer = $this->reviewerWith(self::verdict('deny', 'high'));

        $reviewer->review('task', 'Bash', ['command' => 'a']);
        $reviewer->review('task', 'Bash', ['command' => 'b']);
        $this->assertFalse($reviewer->shouldEscalateBatchToHuman());
        $reviewer->review('task', 'Bash', ['command' => 'c']);
        $this->assertTrue($reviewer->shouldEscalateBatchToHuman());
    }

    public function test_allow_resets_reject_streak(): void
    {
        $outcome = 'deny';
        $runner = static function () use (&$outcome): StructuredResult {
            return new StructuredResult(HitlReviewerTest::verdictPublic($outcome, 'high'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $reviewer->review('task', 'Bash', ['command' => 'a']); // deny 1
        $reviewer->review('task', 'Bash', ['command' => 'b']); // deny 2
        $outcome = 'allow';
        $reviewer->review('task', 'Bash', ['command' => 'c']); // resets
        $outcome = 'deny';
        $reviewer->review('task', 'Bash', ['command' => 'd']); // deny 1 again
        $reviewer->review('task', 'Bash', ['command' => 'e']); // deny 2
        $this->assertFalse($reviewer->shouldEscalateBatchToHuman());
        $reviewer->review('task', 'Bash', ['command' => 'f']); // deny 3
        $this->assertTrue($reviewer->shouldEscalateBatchToHuman());
    }

    public function test_unsure_neither_increments_nor_resets_reject_streak(): void
    {
        $outcome = 'deny';
        $runner = static function () use (&$outcome): StructuredResult {
            return new StructuredResult(HitlReviewerTest::verdictPublic($outcome, 'high'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $reviewer->review('task', 'Bash', ['command' => 'a']); // deny 1
        $reviewer->review('task', 'Bash', ['command' => 'b']); // deny 2
        $outcome = 'unsure';
        $reviewer->review('task', 'Bash', ['command' => 'c']); // streak untouched
        $this->assertFalse($reviewer->shouldEscalateBatchToHuman());
        $outcome = 'deny';
        $reviewer->review('task', 'Bash', ['command' => 'd']); // deny 3 -> trips
        $this->assertTrue($reviewer->shouldEscalateBatchToHuman());
    }

    // ─── prompt construction (via injected runner) ──────────────────────

    public function test_runner_receives_guardian_prompt_with_task_and_action(): void
    {
        $captured = null;
        $runner = static function (string $prompt, array $schema, HaoCodeConfig $config) use (&$captured): StructuredResult {
            $captured = [$prompt, $schema, $config];

            return new StructuredResult(HitlReviewerTest::verdictPublic('allow'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $reviewer->review('clean the build dir', 'Bash', ['command' => 'rm -rf build']);

        $this->assertNotNull($captured);
        [$prompt, $schema, $config] = $captured;
        $this->assertStringContainsString('clean the build dir', $prompt);
        $this->assertStringContainsString('Tool: Bash', $prompt);
        $this->assertStringContainsString('rm -rf build', $prompt);
        $this->assertSame(['risk_level', 'user_authorization', 'outcome', 'rationale'], $schema['required']);
        $this->assertSame('sk-test-key', $config->apiKey);
        $this->assertSame('test-model', $config->model);
        $this->assertSame(2048, $config->maxTokens);
        $this->assertSame(3, $config->maxTurns);
        $this->assertSame([], $config->allowedTools);
        $this->assertTrue($config->ephemeral);
    }

    public function test_oversized_action_fields_are_truncated_in_prompt(): void
    {
        $captured = null;
        $runner = static function (string $prompt) use (&$captured): StructuredResult {
            $captured = $prompt;

            return new StructuredResult(HitlReviewerTest::verdictPublic('allow'));
        };
        $reviewer = new HitlReviewer(self::PROVIDER_CONFIG, sys_get_temp_dir(), $runner);

        $reviewer->review('task', 'Bash', ['command' => str_repeat('c', 5000)]);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('…<truncated/>', $captured);
        $this->assertStringNotContainsString(str_repeat('c', 5000), $captured);
    }

    /** Public helper so static closures can build verdict arrays. */
    public static function verdictPublic(string $outcome, string $risk = 'low', string $rationale = 'Routine and reversible.'): array
    {
        return self::verdict($outcome, $risk, $rationale);
    }
}
