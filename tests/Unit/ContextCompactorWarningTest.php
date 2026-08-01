<?php

namespace Tests\Unit;

use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Agent\QueryEngine;
use PHPUnit\Framework\TestCase;

class ContextCompactorWarningTest extends TestCase
{
    /**
     * Constants mirrored from ContextCompactor (private).
     *
     * EFFECTIVE_CONTEXT_WINDOW = 200_000 - 20_000 = 180_000
     * WARNING_BUFFER_TOKENS    = 30_000  → isWarning  when tokens >= 150_000
     * ERROR_BUFFER_TOKENS      = 20_000  → isError    when tokens >= 160_000
     * BLOCKING_BUFFER_TOKENS   =  3_000  → isBlocking when tokens >= 177_000
     */
    private const EFFECTIVE = 180_000;
    private const WARNING_THRESHOLD = self::EFFECTIVE - 30_000; // 150_000
    private const ERROR_THRESHOLD   = self::EFFECTIVE - 20_000; // 160_000
    private const BLOCKING_THRESHOLD = self::EFFECTIVE - 3_000; // 177_000

    private function makeCompactor(?int $contextWindow = null): ContextCompactor
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        return new ContextCompactor($queryEngine, null, $contextWindow);
    }

    // ─── percentUsed ──────────────────────────────────────────────────────

    public function test_percent_used_at_zero_tokens(): void
    {
        $state = $this->makeCompactor()->getWarningState(0);
        $this->assertSame(0.0, $state['percentUsed']);
    }

    public function test_percent_used_at_full_window(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::EFFECTIVE);
        $this->assertSame(100.0, $state['percentUsed']);
    }

    public function test_percent_used_at_half_window(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::EFFECTIVE / 2);
        $this->assertSame(50.0, $state['percentUsed']);
    }

    // ─── no warnings below threshold ──────────────────────────────────────

    public function test_no_warning_when_well_below_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(100_000);

        $this->assertFalse($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertFalse($state['isBlocking']);
        $this->assertNull($state['message']);
    }

    // ─── warning tier (isWarning) ─────────────────────────────────────────

    public function test_is_warning_just_at_warning_threshold(): void
    {
        // exactly at threshold: remaining = 30_000 → isWarning
        $state = $this->makeCompactor()->getWarningState(self::WARNING_THRESHOLD);

        $this->assertTrue($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertFalse($state['isBlocking']);
        $this->assertNotNull($state['message']);
        $this->assertStringContainsString('%', $state['message']);
    }

    public function test_is_warning_just_below_warning_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::WARNING_THRESHOLD - 1);

        $this->assertFalse($state['isWarning']);
    }

    public function test_warning_message_mentions_auto_compact(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::WARNING_THRESHOLD);
        $this->assertStringContainsString('Auto-compact', $state['message']);
    }

    // ─── error tier (isError) ────────────────────────────────────────────

    public function test_is_error_just_at_error_threshold(): void
    {
        // remaining = 20_000 → isError
        $state = $this->makeCompactor()->getWarningState(self::ERROR_THRESHOLD);

        $this->assertTrue($state['isWarning']);
        $this->assertTrue($state['isError']);
        $this->assertFalse($state['isBlocking']);
    }

    public function test_error_message_suggests_compact(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::ERROR_THRESHOLD);
        $this->assertStringContainsString('/compact', $state['message']);
        $this->assertStringContainsString('nearly full', $state['message']);
    }

    public function test_is_error_false_just_below_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::ERROR_THRESHOLD - 1);
        $this->assertFalse($state['isError']);
    }

    // ─── blocking tier (isBlocking) ───────────────────────────────────────

    public function test_is_blocking_at_blocking_threshold(): void
    {
        // remaining = 3_000 → isBlocking
        $state = $this->makeCompactor()->getWarningState(self::BLOCKING_THRESHOLD);

        $this->assertTrue($state['isWarning']);
        $this->assertTrue($state['isError']);
        $this->assertTrue($state['isBlocking']);
    }

    public function test_blocking_message_says_critically_full(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::BLOCKING_THRESHOLD);
        $this->assertStringContainsString('critically full', $state['message']);
        $this->assertStringContainsString('/compact', $state['message']);
    }

    public function test_is_blocking_false_just_below_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::BLOCKING_THRESHOLD - 1);
        $this->assertFalse($state['isBlocking']);
    }

    // ─── return shape ─────────────────────────────────────────────────────

    public function test_return_array_has_all_required_keys(): void
    {
        $state = $this->makeCompactor()->getWarningState(50_000);

        $this->assertArrayHasKey('percentUsed', $state);
        $this->assertArrayHasKey('isWarning', $state);
        $this->assertArrayHasKey('isError', $state);
        $this->assertArrayHasKey('isBlocking', $state);
        $this->assertArrayHasKey('message', $state);
    }

    public function test_percent_used_is_float(): void
    {
        $state = $this->makeCompactor()->getWarningState(90_000);
        $this->assertIsFloat($state['percentUsed']);
    }

    // ─── shouldAutoCompact ────────────────────────────────────────────────

    public function test_should_auto_compact_returns_false_below_threshold(): void
    {
        $compactor = $this->makeCompactor();
        // AUTO_COMPACT_THRESHOLD = 180_000 - 13_000 = 167_000
        $this->assertFalse($compactor->shouldAutoCompact(160_000));
    }

    public function test_should_auto_compact_returns_true_at_threshold(): void
    {
        $compactor = $this->makeCompactor();
        // Just above AUTO_COMPACT_THRESHOLD
        $this->assertTrue($compactor->shouldAutoCompact(168_000));
    }

    // ─── default window boundary assertions (200k baseline) ──────────────

    public function test_default_window_auto_compact_boundary_is_167k(): void
    {
        $compactor = $this->makeCompactor();
        // AUTO_COMPACT_THRESHOLD = 167_000 for the default 200k window
        $this->assertFalse($compactor->shouldAutoCompact(166_999));
        $this->assertFalse($compactor->shouldAutoCompact(167_000));
        $this->assertTrue($compactor->shouldAutoCompact(167_001));
    }

    public function test_null_window_behaves_like_default(): void
    {
        $compactor = $this->makeCompactor(null);
        $this->assertFalse($compactor->shouldAutoCompact(167_000));
        $this->assertTrue($compactor->shouldAutoCompact(167_001));
    }

    public function test_non_positive_window_falls_back_to_default(): void
    {
        foreach ([0, -1, -100_000] as $invalidWindow) {
            $compactor = $this->makeCompactor($invalidWindow);
            $this->assertFalse($compactor->shouldAutoCompact(160_000));
            $this->assertTrue($compactor->shouldAutoCompact(168_000));
        }
    }

    // ─── custom 128k window ───────────────────────────────────────────────

    /**
     * For a 128k window every threshold scales by 128/200 = 0.64:
     *   effective window        = 180_000 * 0.64 = 115_200
     *   auto-compact threshold  = 167_000 * 0.64 = 106_880
     *   warning when remaining <= 19_200 (tokens >= 96_000)
     *   error   when remaining <= 12_800 (tokens >= 102_400)
     *   blocking when remaining <= 1_920 (tokens >= 113_280)
     */
    private const CUSTOM_WINDOW = 128_000;
    private const CUSTOM_EFFECTIVE = 115_200;
    private const CUSTOM_AUTO_THRESHOLD = 106_880;

    public function test_custom_128k_window_auto_compact_fires_near_106k(): void
    {
        $compactor = $this->makeCompactor(self::CUSTOM_WINDOW);

        $this->assertFalse($compactor->shouldAutoCompact(100_000));
        $this->assertFalse($compactor->shouldAutoCompact(self::CUSTOM_AUTO_THRESHOLD));
        $this->assertTrue($compactor->shouldAutoCompact(self::CUSTOM_AUTO_THRESHOLD + 1));
        $this->assertTrue($compactor->shouldAutoCompact(110_000));
    }

    public function test_custom_128k_window_micro_compact_range(): void
    {
        $compactor = $this->makeCompactor(self::CUSTOM_WINDOW);

        // MICRO_COMPACT_THRESHOLD = 40_000 * 128/200 = 25_600.
        $this->assertFalse($compactor->shouldMicroCompact(25_600));
        $this->assertTrue($compactor->shouldMicroCompact(25_601));
        // Above the scaled auto threshold → auto-compact takes over
        $this->assertFalse($compactor->shouldMicroCompact(self::CUSTOM_AUTO_THRESHOLD + 1));
    }

    public function test_custom_128k_window_percent_used(): void
    {
        $compactor = $this->makeCompactor(self::CUSTOM_WINDOW);

        $this->assertSame(50.0, $compactor->getWarningState(self::CUSTOM_EFFECTIVE / 2)['percentUsed']);
        $this->assertSame(100.0, $compactor->getWarningState(self::CUSTOM_EFFECTIVE)['percentUsed']);
    }

    public function test_custom_128k_window_warning_tiers(): void
    {
        $compactor = $this->makeCompactor(self::CUSTOM_WINDOW);

        // Well below warning: remaining = 115_200 - 90_000 = 25_200 > 19_200
        $state = $compactor->getWarningState(90_000);
        $this->assertFalse($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertFalse($state['isBlocking']);

        // Warning tier: remaining = 19_200
        $state = $compactor->getWarningState(96_000);
        $this->assertTrue($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertFalse($state['isBlocking']);

        // Error tier: remaining = 12_800
        $state = $compactor->getWarningState(102_400);
        $this->assertTrue($state['isWarning']);
        $this->assertTrue($state['isError']);
        $this->assertFalse($state['isBlocking']);

        // Blocking tier: remaining = 1_920
        $state = $compactor->getWarningState(113_280);
        $this->assertTrue($state['isWarning']);
        $this->assertTrue($state['isError']);
        $this->assertTrue($state['isBlocking']);
    }
}
