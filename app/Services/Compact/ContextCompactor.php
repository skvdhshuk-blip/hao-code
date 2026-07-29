<?php

namespace HaoCode\Services\Compact;

use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Cache\FileStateCache;
use HaoCode\Services\Hooks\HookExecutor;

class ContextCompactor
{
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

    private ?FileStateCache $fileStateCache = null;

    /**
     * Resolved context window for this instance.
     *
     * All window-derived thresholds (effective window, auto-compact trigger,
     * warning tiers) scale proportionally from the 200k baseline constants,
     * so user-configured windows (e.g. 128k via SettingsManager) compact at
     * the equivalent point instead of far too late.
     */
    private readonly int $contextWindow;

    public function __construct(
        private readonly QueryEngine $queryEngine,
        private readonly ?HookExecutor $hookExecutor = null,
        ?int $contextWindow = null,
    ) {
        $this->contextWindow = ($contextWindow !== null && $contextWindow > 0)
            ? $contextWindow
            : self::CONTEXT_WINDOW;
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
        return $this->scaleFromDefaultWindow(self::EFFECTIVE_CONTEXT_WINDOW);
    }

    private function autoCompactThreshold(): int
    {
        return $this->scaleFromDefaultWindow(self::AUTO_COMPACT_THRESHOLD);
    }

    public function setFileStateCache(FileStateCache $cache): void
    {
        $this->fileStateCache = $cache;
    }

    /**
     * Compact message history using LLM-generated 9-section structured summary.
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

        $summary = $this->generateLlmSummary($oldMessages, $customInstructions ?? null);

        if ($summary === null) {
            $summary = $this->generateBasicSummary($oldMessages);
            $this->compactFailures++;
        } else {
            $this->compactFailures = 0;
            $summary = $this->stripAnalysisBlock($summary);
        }

        // PostCompact hook
        if ($this->hookExecutor) {
            $this->hookExecutor->execute('PostCompact', [
                'trigger' => 'auto',
                'compact_summary' => mb_substr($summary, 0, 500),
            ]);
        }

        $transcriptNote = "[Context Compaction Summary — {$removed} messages compacted]\n\n{$summary}\n\n[End of Summary. Continue the conversation from where it left off without asking further questions.]";
        $remaining = array_slice($messages, $removed);
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

        // Post-compact: re-inject recently-read files so model has context
        $recentFiles = $this->extractRecentFiles($oldMessages);
        $restoredFiles = $this->injectFileContext($history, $recentFiles);

        $suffix = $restoredFiles > 0 ? " Re-injected {$restoredFiles} recently-read files." : '';

        return "Compacted {$removed} messages into structured summary. Kept last {$keepLast}.{$suffix}";
    }

    public function shouldAutoCompact(int $totalInputTokens): bool
    {
        return $totalInputTokens > $this->autoCompactThreshold()
            && $this->compactFailures < self::MAX_COMPACT_FAILURES;
    }

    public function shouldMicroCompact(int $totalInputTokens): bool
    {
        return $totalInputTokens > self::MICRO_COMPACT_THRESHOLD
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

        $isBlocking = $tokensRemaining <= $this->scaleFromDefaultWindow(self::BLOCKING_BUFFER_TOKENS);
        $isError    = $tokensRemaining <= $this->scaleFromDefaultWindow(self::ERROR_BUFFER_TOKENS);
        $isWarning  = $tokensRemaining <= $this->scaleFromDefaultWindow(self::WARNING_BUFFER_TOKENS);

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

    /**
     * Get the structured 9-section compact system prompt.
     */
    private function getCompactSystemPrompt(): array
    {
        return [[
            'type' => 'text',
            'text' => <<<'PROMPT'
<important>No tools may be used during this compact operation. Do not call any tools.</important>

You are a conversation compaction assistant. You MUST produce your response in exactly two blocks:

1. An `<analysis>` block where you draft your understanding
2. A `<summary>` block with the final structured output

Your <summary> MUST contain these 9 sections in this exact format:

<summary>
# Conversation Summary

## 1. Primary Request and Intent
[What the user asked for — their exact words if possible, plus inferred intent]

## 2. Key Technical Concepts
[Important technical concepts, frameworks, patterns, algorithms discussed]

## 3. Files and Code Sections
[All files read, edited, or created. For each file, note what was done and any important code patterns. Use file:line format.]

## 4. Errors and Fixes
[Any errors encountered and how they were fixed]

## 5. Problem Solving
[Key decisions made, approaches tried, and why they were chosen]

## 6. All User Messages
[Bulleted list of every user message in order]

## 7. Pending Tasks
[Tasks mentioned but not yet completed]

## 8. Current Work
[What was being actively worked on when compaction was triggered]

## 9. Optional Next Step
[What the next logical step would be based on the current state]
</summary>

Be specific. Include file paths, function names, exact error messages. Preserve all context needed to continue the work seamlessly.
PROMPT,
        ]];
    }

    private function generateLlmSummary(array $oldMessages, ?string $customInstructions = null): ?string
    {
        try {
            $conversationText = $this->messagesToText($oldMessages);

            if (mb_strlen($conversationText) > 50000) {
                $conversationText = mb_substr($conversationText, 0, 50000) . "\n[...truncated for compaction...]";
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

    private function messagesToText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';

            if (is_string($content)) {
                $parts[] = "{$role}: {$content}";
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
                    $parts[] = "{$role}: {$text}";
                }
            }
        }

        return implode("\n\n", $parts);
    }

    private function generateBasicSummary(array $oldMessages): string
    {
        $parts = [];
        $userMessages = [];
        $files = [];
        $errors = [];
        $commands = [];

        foreach ($oldMessages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';

            if ($role === 'user' && is_string($content)) {
                $userMessages[] = mb_substr($content, 0, 200);
            }

            if (is_array($content)) {
                foreach ($content as $block) {
                    $type = $block['type'] ?? '';

                    if ($type === 'text') {
                        $text = $block['text'] ?? '';
                        if ($role === 'assistant' && !empty($text)) {
                            $parts[] = "assistant: " . mb_substr($text, 0, 200);
                        }
                    } elseif ($type === 'tool_use') {
                        $name = $block['name'] ?? '';
                        $input = $block['input'] ?? [];
                        $commands[] = $name;

                        // Track files
                        foreach (['file_path', 'path', 'command'] as $key) {
                            if (isset($input[$key])) {
                                $files[] = "{$name}: " . $input[$key];
                            }
                        }
                    } elseif ($type === 'tool_result') {
                        $resultContent = $block['content'] ?? '';
                        $isError = $block['is_error'] ?? false;
                        if ($isError && is_string($resultContent)) {
                            $errors[] = mb_substr($resultContent, 0, 200);
                        }
                    }
                }
            }
        }

        $summary = "# Conversation Summary\n\n";
        $summary .= "## User Messages\n";
        foreach (array_slice($userMessages, -10) as $um) {
            $summary .= "- {$um}\n";
        }
        $summary .= "\n## Files Touched\n";
        foreach (array_slice(array_unique($files), -20) as $f) {
            $summary .= "- {$f}\n";
        }
        if (!empty($errors)) {
            $summary .= "\n## Errors\n";
            foreach (array_slice($errors, -5) as $e) {
                $summary .= "- {$e}\n";
            }
        }
        $summary .= "\n## Tools Used\n";
        $toolCounts = array_count_values($commands);
        foreach ($toolCounts as $tool => $count) {
            $summary .= "- {$tool}: {$count}x\n";
        }

        return $summary;
    }

    /**
     * Extract file paths mentioned in messages (from Read/Edit/Write tool calls).
     *
     * @param array $messages
     * @return string[] file paths
     */
    public function extractRecentFiles(array $messages): array
    {
        $files = [];

        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $name = $block['name'] ?? '';
                if (!in_array($name, ['Read', 'Edit', 'Write'], true)) {
                    continue;
                }

                $path = $block['input']['file_path'] ?? null;
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    $files[$path] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * Re-inject recently-read files into history as context attachments.
     *
     * @param MessageHistory $history
     * @param string[] $filePaths
     * @return int number of files injected
     */
    public function injectFileContext(MessageHistory $history, array $filePaths): int
    {
        $injected = 0;
        $totalChars = 0;

        // Take last N files (most recently accessed)
        $filePaths = array_slice($filePaths, -self::POST_COMPACT_MAX_FILES);

        $parts = [];
        foreach ($filePaths as $path) {
            if (!file_exists($path) || !is_readable($path)) {
                continue;
            }

            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                continue;
            }

            // Per-file cap
            if (mb_strlen($content) > self::POST_COMPACT_PER_FILE_CAP_CHARS) {
                $content = mb_substr($content, 0, self::POST_COMPACT_PER_FILE_CAP_CHARS)
                    . "\n[... truncated for post-compact context]";
            }

            // Budget check
            if ($totalChars + mb_strlen($content) > self::POST_COMPACT_FILE_BUDGET_CHARS) {
                break;
            }

            $totalChars += mb_strlen($content);
            $parts[] = "<file_context path=\"{$path}\">\n{$content}\n</file_context>";
            $injected++;
        }

        if ($injected > 0) {
            $contextMsg = "[Post-compact: re-injecting {$injected} recently-accessed files for context]\n\n"
                . implode("\n\n", $parts);

            $history->addUserMessage($contextMsg);
            $history->addAssistantMessage([
                'role' => 'assistant',
                'content' => '[Acknowledged. I have the re-injected file context available.]',
            ]);
        }

        return $injected;
    }
}
