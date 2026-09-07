<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Compact\ContextCompactor;
use HaoCode\Services\Hooks\HookExecutor;
use HaoCode\Services\Hooks\HookResult;
use PHPUnit\Framework\TestCase;

class ContextCompactorTest extends TestCase
{
    use ContextCompactorTestMakeCompactorConcern;
    use ContextCompactorTestTestCompactPreservesRecentMixedContentBlocksExactlyConcern;
    use ContextCompactorTestPreservedBandsConcern;


    // ─── compact — few messages ────────────────────────────────────────────

    // ─── stripAnalysisBlock ────────────────────────────────────────────────

    // ─── messagesToText ────────────────────────────────────────────────────

    // ─── generateBasicSummary ──────────────────────────────────────────────

    // ─── microCompact ─────────────────────────────────────────────────────

    // ─── shouldAutoCompact ────────────────────────────────────────────────

    // ─── getWarningState ──────────────────────────────────────────────────

    // ─── consecutive user messages after compact ──────────────────────────

    // ─── isCompactableToolResult ──────────────────────────────────────────
}
