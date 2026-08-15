<?php

namespace HaoCode\Services\Agent;

use HaoCode\Services\Api\ProviderMessageAdapter;

class MessageHistory
{
    /** @var list<MessageEnvelope> */
    private array $messages = [];

    /**
     * Add a user message. Accepts plain text string or an array of content blocks
     * (for mixed text+image messages).
     *
     * @param string|array $content Plain text, or array of content blocks:
     *   [['type' => 'text', 'text' => '...'], ['type' => 'image', 'source' => [...]]]
     */
    public function addUserMessage(string|array $content): void
    {
        $this->messages[] = MessageEnvelope::user($content);
    }

    public function addAssistantMessage(array $message): void
    {
        $this->messages[] = MessageEnvelope::fromMessage($message);
    }

    /** @internal */
    public function addEnvelope(MessageEnvelope $envelope): void
    {
        $this->messages[] = $envelope;
    }

    /**
     * @param array<int, array{tool_use_id: string, content: string, is_error: bool}> $toolResults
     */
    public function addToolResultMessage(array $toolResults, ?string $text = null): void
    {
        $content = array_map(function (array $result) {
            return [
                'type' => 'tool_result',
                'tool_use_id' => $result['tool_use_id'],
                'content' => $result['content'],
                'is_error' => $result['is_error'] ?? false,
            ];
        }, $toolResults);

        $text = trim((string) $text);
        if ($text !== '') {
            $content[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        $this->messages[] = MessageEnvelope::user($content);
    }

    /**
     * Get messages formatted for the Anthropic API.
     * Adds cache_control breakpoints for prompt caching on the penultimate message.
     */
    public function getMessagesForApi(): array
    {
        $envelopes = array_values(array_filter(
            $this->messages,
            static fn (MessageEnvelope $message): bool => $message->isModelVisible(),
        ));
        $messages = $this->normalizeToolCallPairs(array_map(
            static fn (MessageEnvelope $message): array => $message->message(),
            $envelopes,
        ));
        $penultimate = count($envelopes) >= 3 ? $envelopes[count($envelopes) - 2] : null;

        return (new ProviderMessageAdapter)->adapt(
            $messages,
            $penultimate?->cacheStability === MessageEnvelope::CACHE_STABLE,
        );
    }

    /**
     * 在发送模型前修复工具调用与结果的配对，但不修改持久化的原始历史。
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function normalizeToolCallPairs(array $messages): array
    {
        $hasToolCalls = false;
        foreach ($messages as $message) {
            if ($this->toolUseIds($message) !== []) {
                $hasToolCalls = true;
                break;
            }
        }
        if (! $hasToolCalls) {
            return $messages;
        }

        $normalized = [];
        $pendingToolUseIds = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? '';

            if ($role === 'assistant') {
                if ($pendingToolUseIds !== []) {
                    $normalized[] = $this->syntheticToolResultMessage($pendingToolUseIds);
                }

                $normalized[] = $message;
                $pendingToolUseIds = $this->toolUseIds($message);

                continue;
            }

            if ($role === 'user') {
                $message = $this->normalizeUserToolResults($message, $pendingToolUseIds);
                $pendingToolUseIds = [];

                if ($message !== null) {
                    $normalized[] = $message;
                }

                continue;
            }

            if ($pendingToolUseIds !== []) {
                $normalized[] = $this->syntheticToolResultMessage($pendingToolUseIds);
                $pendingToolUseIds = [];
            }
            $normalized[] = $message;
        }

        if ($pendingToolUseIds !== []) {
            $normalized[] = $this->syntheticToolResultMessage($pendingToolUseIds);
        }

        return $normalized;
    }

    /**
     * 提取一条 assistant 消息中按顺序出现的工具调用 ID。
     *
     * @return string[]
     */
    private function toolUseIds(array $message): array
    {
        if (! is_array($message['content'] ?? null)) {
            return [];
        }

        $ids = [];
        foreach ($message['content'] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_string($block['id'] ?? null)) {
                $ids[] = $block['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 只保留当前待处理调用的结果，并为缺失结果生成 aborted 占位块。
     */
    private function normalizeUserToolResults(array $message, array $pendingToolUseIds): ?array
    {
        $content = $message['content'] ?? '';
        if (is_string($content)) {
            if ($pendingToolUseIds === []) {
                return $message;
            }

            $content = $content === '' ? [] : [['type' => 'text', 'text' => $content]];
        }

        if (! is_array($content)) {
            return $pendingToolUseIds === [] ? $message : $this->syntheticToolResultMessage($pendingToolUseIds);
        }

        $allowedIds = array_fill_keys($pendingToolUseIds, true);
        $resultsById = [];
        $otherBlocks = [];
        foreach ($content as $block) {
            if (($block['type'] ?? null) !== 'tool_result') {
                $otherBlocks[] = $block;

                continue;
            }

            $toolUseId = $block['tool_use_id'] ?? null;
            if (! is_string($toolUseId) || ! isset($allowedIds[$toolUseId]) || isset($resultsById[$toolUseId])) {
                continue;
            }

            $resultsById[$toolUseId] = $block;
        }

        $orderedResults = [];
        foreach ($pendingToolUseIds as $toolUseId) {
            $orderedResults[] = $resultsById[$toolUseId] ?? $this->syntheticToolResultBlock($toolUseId);
        }
        $filtered = array_merge($orderedResults, $otherBlocks);

        if ($filtered === []) {
            return null;
        }

        $message['content'] = $filtered;

        return $message;
    }

    /**
     * 为未完成的一组工具调用构造模型可接受的用户结果消息。
     */
    private function syntheticToolResultMessage(array $toolUseIds): array
    {
        return [
            'role' => 'user',
            'content' => array_map(
                fn (string $toolUseId): array => $this->syntheticToolResultBlock($toolUseId),
                $toolUseIds,
            ),
        ];
    }

    /**
     * 为单个未完成工具调用构造稳定的 aborted 结果块。
     */
    private function syntheticToolResultBlock(string $toolUseId): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => 'aborted',
            'is_error' => true,
        ];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    /**
     * Replace the internal transcript without applying API-only normalization.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @internal
     */
    public function replaceMessages(array $messages): void
    {
        $this->messages = array_map(
            static fn (array $message): MessageEnvelope => MessageEnvelope::fromMessage($message),
            array_values($messages),
        );
    }

    public function getMessages(): array
    {
        return array_map(
            static fn (MessageEnvelope $message): array => $message->message(),
            $this->messages,
        );
    }

    /** @return list<MessageEnvelope> */
    public function getEnvelopes(): array
    {
        return $this->messages;
    }

    /** @return array<int, array<string, mixed>> */
    public function getPersistableMessages(): array
    {
        return array_values(array_map(
            static fn (MessageEnvelope $message): array => $message->message(),
            array_filter(
                $this->messages,
                static fn (MessageEnvelope $message): bool => $message->shouldPersist(),
            ),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function getTelemetryMessages(): array
    {
        return array_map(
            static fn (MessageEnvelope $message): array => $message->telemetryMessage(),
            $this->messages,
        );
    }

    /**
     * Model-visible, provider-shaped messages with sensitive content removed.
     *
     * @return array<int, array<string, mixed>>
     * @internal
     */
    public function getTelemetryMessagesForApi(): array
    {
        $messages = array_values(array_map(
            static fn (MessageEnvelope $message): array => $message->telemetryMessage(),
            array_filter(
                $this->messages,
                static fn (MessageEnvelope $message): bool => $message->isModelVisible(),
            ),
        ));

        return (new ProviderMessageAdapter)->adapt(
            $this->normalizeToolCallPairs($messages),
            cachePenultimate: false,
        );
    }

    public function getLastAssistantText(): ?string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $msg = $this->messages[$i]->message();
            if ($msg['role'] === 'assistant') {
                // Handle string content (simple text messages)
                if (is_string($msg['content'])) {
                    return $msg['content'] !== '' ? $msg['content'] : null;
                }
                // Handle array content blocks
                if (is_array($msg['content'])) {
                    foreach ($msg['content'] as $block) {
                        if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
                            return $block['text'];
                        }
                    }
                }
            }
        }
        return null;
    }
}
