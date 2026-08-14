<?php

namespace HaoCode\Services\Compact;

use HaoCode\Services\Agent\MessageHistory;
use HaoCode\Services\Agent\QueryEngine;
use HaoCode\Services\Hooks\HookExecutor;

trait ContextCompactorGenerateBasicSummaryConcern
{

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
