<?php

namespace HaoCode\Services\Compact;

use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Hooks\HookExecutor;

class ContextCompactor
{
    use ContextCompactorConstructConcern;
    use ContextCompactorGenerateBasicSummaryConcern;

    /**
     * Default context window (200k tokens) and baseline for scaling.
     *
     * All constants below are the 200k-window baselines; per-instance values
     * are derived proportionally from the resolved $contextWindow.
     */
    private const CONTEXT_WINDOW = 200_000;

    /** Reserve for compaction summary output (~p99 of LLM summary) */
    private const MAX_OUTPUT_TOKENS_FOR_SUMMARY = 20_000;

    /** Effective usable context = CONTEXT_WINDOW - MAX_OUTPUT_TOKENS_FOR_SUMMARY */
    private const EFFECTIVE_CONTEXT_WINDOW = self::CONTEXT_WINDOW - self::MAX_OUTPUT_TOKENS_FOR_SUMMARY;

    /**
     * Trigger levels (tokens remaining before hitting EFFECTIVE_CONTEXT_WINDOW).
     * Ported from claude-code autoCompact.ts constants.
     */
    private const AUTOCOMPACT_BUFFER_TOKENS  = 13_000; // fire auto-compact
    private const WARNING_BUFFER_TOKENS      = 30_000; // yellow warning
    private const ERROR_BUFFER_TOKENS        = 20_000; // red warning (= effective window - this)
    private const BLOCKING_BUFFER_TOKENS     =  3_000; // hard block if compaction fails

    private const AUTO_COMPACT_THRESHOLD = self::EFFECTIVE_CONTEXT_WINDOW - self::AUTOCOMPACT_BUFFER_TOKENS;
    private const MICRO_COMPACT_THRESHOLD = 40000;

    private const COMPACTABLE_TOOLS = ['Read', 'Bash', 'Grep', 'Glob', 'WebSearch', 'WebFetch', 'Edit', 'Write'];

    /** Post-compact: max recently-read files to re-inject */
    private const POST_COMPACT_MAX_FILES = 5;
    /** Post-compact: max tokens budget for file re-injection (~50K tokens ≈ 200KB) */
    private const POST_COMPACT_FILE_BUDGET_CHARS = 200_000;
    /** Post-compact: per-file cap (~5K tokens ≈ 20KB) */
    private const POST_COMPACT_PER_FILE_CAP_CHARS = 20_000;

    private int $compactFailures = 0;
    private const MAX_COMPACT_FAILURES = 3;

    /**
     * Resolved context window for this instance.
     *
     * All window-derived thresholds (effective window, auto-compact trigger,
     * warning tiers) scale proportionally from the 200k baseline constants,
     * so user-configured windows (e.g. 128k via SettingsManager) compact at
     * the equivalent point instead of far too late.
     */
    private int $contextWindow;

    /**
     * Safe input budget used by AgentLoop after reserving model output and a
     * safety margin. When present, compaction must fire before this budget,
     * not only before the raw model context window.
     */
    private ?int $maxEstimatedInputTokens;
}
