<?php

namespace HaoCode\Services\Agent;

use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;

/**
 * Pure retry and malformed-tool formatting rules used by AgentLoop.
 *
 * Keeping these rules outside the run state machine makes changes to provider
 * recovery text less likely to affect durable session or tool lifecycle code.
 *
 * @internal
 */
final class AgentResponseRetryPolicy
{
    public function __construct(
        private readonly ?ToolRegistry $toolRegistry = null,
    ) {
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @return array<int, array{id: string, name: string, error: string}>
     */
    public function findMalformedToolUseFailures(array $toolCalls, ToolUseContext $context): array
    {
        $failures = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->toolRegistry?->getTool($toolCall->name);
            if ($tool === null) {
                continue;
            }

            $inputJsonError = $toolCall->inputError;
            if (is_string($inputJsonError) && $inputJsonError !== '') {
                $rawInputSnippet = $this->summarizeMalformedToolInput($toolCall->rawInput);
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $inputJsonError.($rawInputSnippet !== null ? ' Raw input: '.$rawInputSnippet : ''),
                ];

                continue;
            }

            $rawInput = $toolCall->input;
            if (! is_array($rawInput)) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => 'Tool input must decode to an object.',
                ];

                continue;
            }

            try {
                $validatedInput = $tool->inputSchema()->validate($rawInput);
            } catch (\InvalidArgumentException $e) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $e->getMessage(),
                ];

                continue;
            } catch (\TypeError $e) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $semanticError = $tool->validateInput($validatedInput, $context);
            if ($semanticError !== null) {
                $failures[] = [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'error' => $semanticError,
                ];
            }
        }

        return $failures;
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    public function sanitizeMalformedToolAssistantMessage(array $assistantMessage, array $failures): array
    {
        $failedToolIds = [];
        foreach ($failures as $failure) {
            $failedToolIds[$failure['id']] = true;
        }

        $sanitizedContent = [];
        foreach ($assistantMessage['content'] ?? [] as $block) {
            if (($block['type'] ?? null) !== 'tool_use') {
                continue;
            }

            $toolUseId = $block['id'] ?? null;
            if (! is_string($toolUseId) || ! isset($failedToolIds[$toolUseId])) {
                continue;
            }

            if (! is_array($block['input'] ?? null)) {
                $block['input'] = [];
            }

            $sanitizedContent[] = $block;
        }

        $assistantMessage['content'] = array_values($sanitizedContent);

        return $assistantMessage;
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     * @return array<int, array{tool_use_id: string, content: string, is_error: bool}>
     */
    public function buildMalformedToolRetryResults(array $failures): array
    {
        return array_map(function (array $failure): array {
            return [
                'tool_use_id' => $failure['id'],
                'content' => $this->buildMalformedToolRetryMessage($failure['name'], $failure['error']),
                'is_error' => true,
            ];
        }, $failures);
    }

    public function buildMalformedToolRetryMessage(string $toolName, string $error): string
    {
        $lines = [
            'Tool input validation failed. This tool call was not executed.',
            $error,
            'Retry with corrected input.',
        ];

        if ($error === 'Tool input must decode to an object.') {
            $lines[] = 'Tool inputs must be JSON objects that match the tool schema.';
        }

        if ($toolName === 'Write') {
            $lines[] = 'For Write: include an absolute file_path, send the complete file contents in content, and do not prefix JSON or file contents with stray ":" placeholder text.';
            if ($this->isToolInputJsonFailure($error)) {
                $lines[] = 'If the file content is large or multiline, stop resending the same broken JSON blob. Split the file into smaller writes or create it in smaller Bash heredoc chunks.';
                $lines[] = 'For large source files, create a tiny scaffold first, then use Edit in small chunks no larger than about 8 lines or 400 characters.';
            }
        }

        if ($toolName === 'TodoWrite') {
            $lines[] = 'For TodoWrite: send a todos array with real tasks, or skip TodoWrite entirely if there is nothing useful to track.';
        }

        if ($toolName === 'Bash') {
            $lines[] = 'For Bash: do not send shell no-ops or probes such as ": > /dev/null 2>&1" or "true". If you need context first, run a real command like "pwd" or "ls".';
            if ($this->isToolInputJsonFailure($error)) {
                $lines[] = 'If the command is large or multiline, split it into smaller concrete commands instead of resending one giant heredoc payload.';
                $lines[] = 'Do not send large heredocs, inline python/node scripts, base64 blobs, or long printf command lists in one Bash call.';
            }
        }

        if ($toolName === 'TeamCreate') {
            $lines[] = 'For TeamCreate: include name, task, and a non-empty members array; every member needs a role.';
            $lines[] = 'Keep the payload compact. For large teams, omit member prompts and use descriptive role names.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{id: string, name: string, error: string}>  $failures
     */
    public function buildMalformedToolRetryInstruction(array $failures, int $retryCount): string
    {
        $lines = [
            'Retry with corrected tool input only. Do not repeat the same malformed call.',
            'If you are unsure, inspect the repo or directory first instead of sending an empty or placeholder tool input.',
        ];

        if ($retryCount >= 2) {
            $lines[] = 'You have already repeated this invalid tool pattern. Stop and correct it now.';
        }

        $hasJsonParseFailure = false;
        foreach ($failures as $failure) {
            if ($this->isToolInputJsonFailure($failure['error'])) {
                $hasJsonParseFailure = true;
                break;
            }
        }
        if ($hasJsonParseFailure) {
            $lines[] = 'If a large multiline payload keeps breaking tool JSON, stop resending the same blob. Split it into smaller commands or smaller file writes.';
            $lines[] = 'Do not narrate a new strategy and then resend another large blob. The next turn must begin with a small concrete tool call.';
            $lines[] = 'Do not use Agent or Skill as a fallback for ordinary file creation or editing. Stay in this thread and use the local tools directly.';
        }

        $toolNames = array_values(array_unique(array_map(
            static fn (array $failure): string => $failure['name'],
            $failures,
        )));

        foreach ($toolNames as $toolName) {
            if ($toolName === 'Write') {
                $lines[] = 'For Write: send a valid JSON object with both absolute file_path and full content strings.';
                $lines[] = 'Prefer a tiny initial Write followed by Edit chunks for long files.';
            } elseif ($toolName === 'TodoWrite') {
                $lines[] = 'For TodoWrite: send {"todos":[...]} with real tasks, or omit TodoWrite if there is nothing useful to track.';
            } elseif ($toolName === 'Bash') {
                $lines[] = 'For Bash: send a real shell command in command. Never send ":" placeholders or no-op probes like ": > /dev/null 2>&1" or "true"; use "pwd" or "ls" if you need to inspect the directory first.';
                $lines[] = 'Keep Bash commands short and concrete; avoid giant multiline file-generation commands.';
            } elseif ($toolName === 'TeamCreate') {
                $lines[] = 'For TeamCreate: send one compact JSON object with name, task, and members; each member must contain role.';
                $lines[] = 'For large teams, omit member prompts and use descriptive role names. Do not include literal newlines, control characters, or omit members.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Track retries by failure category so a corrected, parseable call with a
     * schema error does not consume the remaining JSON-repair budget.
     *
     * @param array<int, array{id: string, name: string, error: string}> $failures
     */
    public function malformedFailureSignature(array $failures): string
    {
        $parts = array_map(function (array $failure): string {
            $category = $this->isToolInputJsonFailure($failure['error'])
                ? 'json'
                : (str_contains($failure['error'], 'validation failed') ? 'schema' : 'other');

            return $failure['name'].':'.$category;
        }, $failures);
        sort($parts);

        return implode('|', $parts);
    }

    public function isToolInputJsonFailure(string $error): bool
    {
        return str_contains($error, 'Tool input JSON could not be parsed')
            || str_contains($error, 'Tool input JSON was incomplete');
    }

    public function summarizeMalformedToolInput(string $rawInput): ?string
    {
        $rawInput = trim($rawInput);
        if ($rawInput === '') {
            return null;
        }

        $snippet = preg_replace('/\s+/', ' ', $rawInput);
        if ($snippet === null || $snippet === '') {
            return null;
        }

        if (mb_strlen($snippet) > 120) {
            $snippet = mb_substr($snippet, 0, 120).'...';
        }

        return $snippet;
    }

    public function shouldRetryIncompleteAssistantResponse(
        StreamProcessor $processor,
        array $assistantMessage,
        ?string $stopReason,
        int $retryCount,
        int $maxRetries,
    ): bool {
        if ($retryCount >= $maxRetries) {
            return false;
        }

        if ($stopReason === 'max_tokens') {
            return true;
        }

        if ($this->isNarrationOnlyAssistantMessage($assistantMessage)) {
            return true;
        }

        if (! $this->assistantMessageHasTextContent($assistantMessage)) {
            return true;
        }

        if ($processor->hasFinalMessageEvent()) {
            return false;
        }

        return $this->assistantMessageHasVisibleContent($assistantMessage);
    }

    public function assistantMessageHasVisibleContent(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content)) {
            return false;
        }

        foreach ($content as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                return true;
            }

            if ($type === 'thinking' && trim((string) ($block['thinking'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A terminal SDK result is user-facing text. Thinking blocks remain useful
     * provider history, but they cannot make an otherwise empty result a
     * successful answer.
     */
    public function assistantMessageHasTextContent(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content)) {
            return false;
        }

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text'
                && trim((string) ($block['text'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function buildIncompleteResponseRetryInstruction(
        ?string $stopReason,
        int $retryCount,
        bool $skipHistory = false,
    ): string {
        $lines = [];

        if ($stopReason === 'max_tokens') {
            $lines[] = 'Your previous response hit the output limit before you finished.';
        } else {
            $lines[] = 'Your previous response ended without a usable final answer.';
        }

        $lines[] = 'Continue exactly from where you left off.';
        $lines[] = 'Do not restart from scratch.';
        $lines[] = 'If the task requires file changes, commands, or verification, keep using tools until the requested work is actually complete.';

        if ($skipHistory) {
            $lines[] = 'Do not narrate progress or announce the next step. Take the next concrete action immediately.';
        }

        if ($retryCount >= 2) {
            $lines[] = 'You have already been cut off once. Finish the task now instead of narrating the next step.';
        }

        return implode("\n", $lines);
    }

    public function shouldSkipIncompleteAssistantHistory(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content)) {
            return false;
        }

        if (! $this->assistantMessageHasTextContent($assistantMessage)
            && $this->assistantMessageHasThinkingContent($assistantMessage)) {
            return true;
        }

        if (count($content) !== 1) {
            return false;
        }

        $block = $content[0];
        if (($block['type'] ?? null) !== 'text') {
            return false;
        }

        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '' || str_contains($text, "\n") || mb_strlen($text) > 160) {
            return false;
        }

        if ($this->isLowValueNarrationText($text)) {
            return true;
        }

        if (preg_match('/[：:]$/u', $text) === 1) {
            return true;
        }

        return preg_match(
            "~^(现在|接下来|继续|然后|下一步|接着|Now\\b|Next\\b|I(?:'ll| will)\\b|Let's\\b)~iu",
            $text,
        ) === 1;
    }

    private function assistantMessageHasThinkingContent(array $assistantMessage): bool
    {
        foreach ($assistantMessage['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'thinking'
                && trim((string) ($block['thinking'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function isNarrationOnlyAssistantMessage(array $assistantMessage): bool
    {
        $content = $assistantMessage['content'] ?? null;
        if (! is_array($content) || count($content) !== 1) {
            return false;
        }

        $block = $content[0];
        if (($block['type'] ?? null) !== 'text') {
            return false;
        }

        $text = trim((string) ($block['text'] ?? ''));
        if ($text === '' || str_contains($text, "\n") || mb_strlen($text) > 220) {
            return false;
        }

        return $this->isLowValueNarrationText($text);
    }

    public function isLowValueNarrationText(string $text): bool
    {
        return preg_match(
            "~^(现在|接下来|继续|然后|下一步|接着|让我|我(?:先|会|将|正在|要|需要|尝试|打算|使用)|I(?:'ll| will| need to| am going to)|Let me|Using\\b|Now\\b|Next\\b)~iu",
            trim($text),
        ) === 1;
    }
}
