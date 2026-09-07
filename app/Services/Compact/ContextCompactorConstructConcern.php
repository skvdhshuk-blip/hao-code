<?php

namespace HaoCode\Services\Compact;

use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Hooks\HookExecutor;

trait ContextCompactorConstructConcern
{

    public function __construct(
        private readonly QueryEngine $queryEngine,
        private readonly ?HookExecutor $hookExecutor = null,
        ?int $contextWindow = null,
        ?int $maxEstimatedInputTokens = null,
    ) {
        $this->updateLimits($contextWindow, $maxEstimatedInputTokens);
    }

    /** @internal */
    public function updateLimits(?int $contextWindow, ?int $maxEstimatedInputTokens): void
    {
        $this->contextWindow = ($contextWindow !== null && $contextWindow > 0)
            ? $contextWindow
            : self::CONTEXT_WINDOW;
        $this->maxEstimatedInputTokens = $maxEstimatedInputTokens !== null
            ? max(1, $maxEstimatedInputTokens)
            : null;
    }

    /**
     * Scale a 200k-baseline threshold proportionally to the resolved window.
     * With the default window this returns the baseline value unchanged.
     */
    private function scaleFromDefaultWindow(int $baselineValue): int
    {
        return (int) round($baselineValue * ($this->contextWindow / self::CONTEXT_WINDOW));
    }

    private function effectiveContextWindow(): int
    {
        $scaledWindow = $this->scaleFromDefaultWindow(self::EFFECTIVE_CONTEXT_WINDOW);

        return $this->maxEstimatedInputTokens === null
            ? $scaledWindow
            : min($scaledWindow, $this->maxEstimatedInputTokens);
    }

    private function scaledBuffer(int $baselineValue): int
    {
        $scaledBuffer = $this->scaleFromDefaultWindow($baselineValue);

        return min($this->effectiveContextWindow(), $scaledBuffer);
    }

    private function autoCompactThreshold(): int
    {
        $scaledThreshold = $this->scaleFromDefaultWindow(self::AUTO_COMPACT_THRESHOLD);
        if ($this->maxEstimatedInputTokens === null) {
            return $scaledThreshold;
        }

        // AgentLoop rejects requests at maxEstimatedInputTokens. Keep the
        // existing window-derived threshold as the upper bound, but move it
        // earlier when a large max-output reservation makes the safe input
        // budget materially smaller than the raw context window. The limit
        // is calculated once by AgentLoopFactory and shared here so the two
        // guards cannot drift.
        return max(1, min($scaledThreshold, $this->maxEstimatedInputTokens));
    }

    private function microCompactThreshold(): int
    {
        return $this->scaleFromDefaultWindow(self::MICRO_COMPACT_THRESHOLD);
    }

    /**
     * Compact message history into an LLM-generated structured summary.
     */
    public function compact(MessageHistory $history, int $keepLast = 6, ?string $customInstructions = null): string
    {
        $messages = $history->getMessages();
        $count = count($messages);

        if ($count <= $keepLast) {
            return "No compaction needed ({$count} messages).";
        }

        $removed = $count - $keepLast;
        $oldMessages = array_slice($messages, 0, $removed);

        // PreCompact hook
        if ($this->hookExecutor) {
            $this->hookExecutor->execute('PreCompact', [
                'trigger' => 'auto',
                'removed_messages' => $removed,
            ]);
        }

        $response = $this->generateLlmSummary($oldMessages, $customInstructions ?? null);

        if ($response === null) {
            $summary = $this->generateBasicSummary($oldMessages);
            $bands = [];
            $this->compactFailures++;
        } else {
            $this->compactFailures = 0;
            // parseBands reads the raw response: stripAnalysisBlock keeps only
            // what is inside <summary>, which is where the keep blocks are not.
            $bands = GradedCompactionPrompt::parseBands($response, count($oldMessages));
            $summary = GradedCompactionPrompt::stripKeepBlocks($this->stripAnalysisBlock($response));
        }

        // PostCompact hook
        if ($this->hookExecutor) {
            $this->hookExecutor->execute('PostCompact', [
                'trigger' => 'auto',
                'compact_summary' => mb_substr($summary, 0, 500),
            ]);
        }

        $remaining = array_slice($messages, $removed);
        [$preserved, $bandsKept] = $this->renderPreservedBands($bands, $oldMessages, $remaining);

        $transcriptNote = "[Context Compaction Summary — {$removed} messages compacted]\n\n{$summary}\n\n";
        if ($preserved !== '') {
            $transcriptNote .= $preserved."\n\n";
        }
        $transcriptNote .= '[End of Summary. Continue the conversation from where it left off without asking further questions.]';

        $rebuilt = [[
            'role' => 'user',
            'content' => $transcriptNote,
        ]];

        // If the first remaining message is a user message, insert a bridge assistant
        // acknowledgement so the history never has two consecutive user messages
        // (which the Anthropic API rejects). This can happen when the history ends
        // with an un-replied user turn (e.g., the current input in AgentLoop).
        if (!empty($remaining) && ($remaining[0]['role'] ?? '') === 'user') {
            $rebuilt[] = [
                'role' => 'assistant',
                'content' => '[Acknowledged. Continuing from where we left off.]',
            ];
        }

        $history->replaceMessages(array_merge($rebuilt, $remaining));

        // Post-compact context restoration. When the model picked the originals
        // worth keeping, those are already in the transcript note and are a
        // better signal than "whatever the last few Read calls touched", so the
        // path-based re-injection only runs as the fallback.
        if ($preserved !== '') {
            $suffix = " Preserved {$bandsKept} priority band(s) of original messages verbatim.";
        } else {
            $recentFiles = $this->extractRecentFiles(array_slice($messages, 0, $removed));
            $restoredFiles = $this->injectFileContext($history, $recentFiles);
            $suffix = $restoredFiles > 0 ? " Re-injected {$restoredFiles} recently-read files." : '';
        }

        return "Compacted {$removed} messages into structured summary. Kept last {$keepLast}.{$suffix}";
    }

    public function shouldAutoCompact(int $totalInputTokens): bool
    {
        return $totalInputTokens > $this->autoCompactThreshold()
            && $this->compactFailures < self::MAX_COMPACT_FAILURES;
    }

    public function shouldMicroCompact(int $totalInputTokens): bool
    {
        return $totalInputTokens > $this->microCompactThreshold()
            && $totalInputTokens <= $this->autoCompactThreshold();
    }

    /**
     * Return a tiered warning state based on current token usage.
     *
     * Mirrors claude-code's autoCompact.ts warning threshold logic.
     *
     * @return array{
     *   percentUsed: float,
     *   isWarning: bool,
     *   isError: bool,
     *   isBlocking: bool,
     *   message: string|null
     * }
     */
    public function getWarningState(int $totalInputTokens): array
    {
        $effectiveWindow = $this->effectiveContextWindow();
        $percentUsed = round(($totalInputTokens / $effectiveWindow) * 100, 1);

        $tokensRemaining = $effectiveWindow - $totalInputTokens;

        $isBlocking = $tokensRemaining <= $this->scaledBuffer(self::BLOCKING_BUFFER_TOKENS);
        $isError    = $tokensRemaining <= $this->scaledBuffer(self::ERROR_BUFFER_TOKENS);
        $isWarning  = $tokensRemaining <= $this->scaledBuffer(self::WARNING_BUFFER_TOKENS);

        $message = null;
        if ($isBlocking) {
            $message = "Context window critically full ({$percentUsed}%). Use /compact immediately.";
        } elseif ($isError) {
            $message = "Context window nearly full ({$percentUsed}%). Consider using /compact.";
        } elseif ($isWarning) {
            $message = "Context window at {$percentUsed}%. Auto-compact will trigger soon.";
        }

        return [
            'percentUsed' => $percentUsed,
            'isWarning'   => $isWarning,
            'isError'     => $isError,
            'isBlocking'  => $isBlocking,
            'message'     => $message,
        ];
    }

    /**
     * Micro-compact: clear old tool result content without LLM call.
     */
    public function microCompact(MessageHistory $history, int $keepLastToolResults = 4): string
    {
        $messages = $history->getMessages();
        $modified = 0;
        $charsSaved = 0;

        $toolResultPositions = [];
        foreach ($messages as $idx => $msg) {
            $content = $msg['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $blockIdx => $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $toolResultPositions[] = "{$idx}:{$blockIdx}";
                    }
                }
            }
        }

        if (count($toolResultPositions) <= $keepLastToolResults) {
            return "No micro-compact needed (only " . count($toolResultPositions) . " tool results).";
        }

        $resultsToKeep = array_fill_keys(
            array_slice($toolResultPositions, -$keepLastToolResults),
            true,
        );
        $newMessages = [];

        foreach ($messages as $idx => $msg) {
            $content = $msg['content'] ?? '';
            $role = $msg['role'] ?? '';

            if (is_array($content) && $role === 'user') {
                $newContent = [];
                foreach ($content as $blockIdx => $block) {
                    $position = "{$idx}:{$blockIdx}";
                    if (($block['type'] ?? '') === 'tool_result'
                        && ! isset($resultsToKeep[$position])
                        && $this->isCompactableToolResult($block)) {
                        $oldLen = mb_strlen($block['content'] ?? '');
                        $block['content'] = '[Old tool result content cleared to save context]';
                        $newContent[] = $block;
                        $charsSaved += $oldLen;
                        $modified++;
                        continue;
                    }
                    $newContent[] = $block;
                }
                $msg['content'] = $newContent;
            }

            $newMessages[] = $msg;
        }

        if ($modified === 0) {
            return "No compactable tool results found to clear.";
        }

        $history->replaceMessages($newMessages);

        return "Micro-compacted: cleared {$modified} old tool results, saved ~" . number_format($charsSaved) . " chars.";
    }

    /**
     * Last-resort compaction used before rejecting an over-budget request.
     * Preserves tool-result IDs and short text previews while dropping large
     * image/base64 payloads from every turn.
     */
    public function emergencyCompact(MessageHistory $history, int $previewChars = 2000): string
    {
        $messages = $history->getMessages();
        $trimmed = 0;
        $charsSaved = 0;

        foreach ($messages as &$message) {
            if (($message['role'] ?? '') !== 'user' || ! is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as &$block) {
                if (($block['type'] ?? '') !== 'tool_result' || ! is_string($block['content'] ?? null)) {
                    continue;
                }

                $content = $block['content'];
                if (mb_strlen($content) <= $previewChars) {
                    continue;
                }

                $oldLength = mb_strlen($content);
                $isImagePayload = str_contains($content, '[Image:') || str_contains($content, 'data:image/');
                $block['content'] = $isImagePayload
                    ? '[Large image tool result omitted during emergency context compaction]'
                    : mb_substr($content, 0, $previewChars)."\n[Tool result truncated during emergency context compaction]";
                $charsSaved += $oldLength - mb_strlen($block['content']);
                $trimmed++;
            }
            unset($block);
        }
        unset($message);

        if ($trimmed === 0) {
            return 'No oversized tool results found for emergency compaction.';
        }

        $history->replaceMessages($messages);

        return "Emergency-compacted {$trimmed} tool results, saved ~".number_format($charsSaved).' chars.';
    }

    private function isCompactableToolResult(array $block): bool
    {
        $content = $block['content'] ?? '';
        return is_string($content) && mb_strlen($content) >= 1000;
    }

    private function generateLlmSummary(array $oldMessages, ?string $customInstructions = null): ?string
    {
        try {
            $conversationText = $this->messagesToText($oldMessages, withIndices: true);

            if (mb_strlen($conversationText) > self::SUMMARY_INPUT_MAX_CHARS) {
                // Drop the middle, not the tail. The head carries the original
                // request; the tail carries the work in flight. The keep-list
                // can only name messages the model actually saw, so truncating
                // from the end would make the most recent — and usually most
                // load-bearing — originals impossible to preserve.
                $tailChars = self::SUMMARY_INPUT_MAX_CHARS - self::SUMMARY_INPUT_HEAD_CHARS;
                $conversationText = mb_substr($conversationText, 0, self::SUMMARY_INPUT_HEAD_CHARS)
                    ."\n[...middle of transcript truncated for compaction...]\n"
                    .mb_substr($conversationText, -$tailChars);
            }

            $prompt = "Please summarize the following conversation using the structured 9-section format:\n\n{$conversationText}";
            if ($customInstructions !== null) {
                $prompt .= "\n\nAdditional instructions for this compaction:\n{$customInstructions}";
            }

            $summaryMessages = [[
                'role' => 'user',
                'content' => $prompt,
            ]];

            $processor = $this->queryEngine->query(
                systemPrompt: $this->getCompactSystemPrompt(),
                messages: $summaryMessages,
                // The compact prompt forbids tool use, so advertising the
                // registry only burns input tokens on schemas the model is
                // told not to touch — for a tool-heavy agent, on every
                // compaction. QueryEngine falls back to the full registry
                // unless the override is passed explicitly.
                toolsOverride: [],
            );

            $text = $processor->getAccumulatedText();

            return !empty($text) ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Strip <analysis>...</analysis> blocks from compact output, keep <summary>.
     */
    private function stripAnalysisBlock(string $text): string
    {
        // Extract content between <summary> tags if present
        if (preg_match('/<summary>(.*?)<\/summary>/s', $text, $m)) {
            return trim($m[1]);
        }

        // Remove <analysis> block if present but no <summary> tag
        $text = preg_replace('/<analysis>.*?<\/analysis>\s*/s', '', $text);

        return trim($text);
    }

    /**
     * Render messages for the summary request.
     *
     * With $withIndices the transcript is labelled `[#n]` by position in
     * $messages, which is how the model addresses a message in its keep-list.
     * The labels must stay in step with the array indices that
     * GradedCompactionPrompt::renderBand() later looks up.
     */
    private function messagesToText(array $messages, bool $withIndices = false): string
    {
        $parts = [];
        foreach ($messages as $index => $msg) {
            $role = $msg['role'] ?? 'unknown';
            $label = $withIndices ? "[#{$index}] {$role}" : (string) $role;
            $content = $msg['content'] ?? '';

            if (is_string($content)) {
                $parts[] = "{$label}: {$content}";
            } elseif (is_array($content)) {
                $text = '';
                foreach ($content as $block) {
                    $type = $block['type'] ?? '';
                    if ($type === 'text') {
                        $text .= $block['text'] . "\n";
                    } elseif ($type === 'tool_use') {
                        $name = $block['name'] ?? 'unknown';
                        $input = json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE);
                        if (mb_strlen($input) > 300) {
                            $input = mb_substr($input, 0, 300) . '...';
                        }
                        $text .= "[Tool call: {$name}({$input})]\n";
                    } elseif ($type === 'tool_result') {
                        $result = $block['content'] ?? '';
                        if (is_string($result) && mb_strlen($result) > 800) {
                            $result = mb_substr($result, 0, 800) . '...';
                        }
                        $text .= "[Tool result: {$result}]\n";
                    }
                }
                if (!empty($text)) {
                    $parts[] = "{$label}: {$text}";
                }
            }
        }

        return implode("\n\n", $parts);
    }
}
