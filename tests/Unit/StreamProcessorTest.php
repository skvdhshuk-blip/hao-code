<?php

namespace Tests\Unit;

use HaoCode\Services\Agent\StreamProcessor;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;

class StreamProcessorTest extends TestCase
{
    use StreamProcessorTestEventConcern;
    use StreamProcessorTestTestEventWithNullDataIsIgnoredConcern;

    // ─── helpers ──────────────────────────────────────────────────────────

    // ─── message_start ────────────────────────────────────────────────────

    // ─── text accumulation ────────────────────────────────────────────────

    // ─── thinking blocks ──────────────────────────────────────────────────

    // ─── getStopReason ────────────────────────────────────────────────────

    // ─── message_delta merges usage ────────────────────────────────────────

    // ─── toAssistantMessage ───────────────────────────────────────────────

    // ─── error event throws ───────────────────────────────────────────────

    // ─── ping / message_stop ignored ──────────────────────────────────────

    // ─── reset ────────────────────────────────────────────────────────────

    // ─── getIndexedToolUseBlocks ──────────────────────────────────────────

    // ─── null data event ignored ──────────────────────────────────────────

    // ─── existing tests below ─────────────────────────────────────────────

    // ─── thinking block signature (extended thinking) ─────────────────────
}
