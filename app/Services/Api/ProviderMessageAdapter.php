<?php

declare(strict_types=1);

namespace HaoCode\Services\Api;

/** @internal */
final class ProviderMessageAdapter
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function adapt(array $messages, bool $cachePenultimate = true): array
    {
        $count = count($messages);
        if ($cachePenultimate && $count >= 3) {
            $index = $count - 2;
            $messages[$index] = $this->addCacheControl($messages[$index]);
        }

        return array_map($this->normalizeMessage(...), $messages);
    }

    /** @param array<string, mixed> $message */
    private function addCacheControl(array $message): array
    {
        if (is_string($message['content'] ?? null)) {
            $message['content'] = [[
                'type' => 'text',
                'text' => $message['content'],
                'cache_control' => ['type' => 'ephemeral'],
            ]];

            return $message;
        }
        if (is_array($message['content'] ?? null) && $message['content'] !== []) {
            $index = count($message['content']) - 1;
            $message['content'][$index]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $message;
    }

    /** @param array<string, mixed> $message */
    private function normalizeMessage(array $message): array
    {
        if (! is_array($message['content'] ?? null)) {
            return $message;
        }
        foreach ($message['content'] as $index => $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? null) === []) {
                $message['content'][$index]['input'] = (object) [];
            }
        }

        return $message;
    }
}
