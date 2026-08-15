<?php

namespace HaoCode\Services\Agent;

trait AgentLoopMessageEnvelopeConcern
{
    /** @param string|array<int, mixed> $userInput @param string|array<int, mixed> $modelInput */
    private function recordUserInputEnvelope(string|array $userInput, string|array $modelInput, bool $sessionStart): void
    {
        $transcript = MessageEnvelope::user(
            $userInput,
            persistence: MessageEnvelope::PERSIST_TRANSCRIPT,
            audience: MessageEnvelope::AUDIENCE_UI,
        );
        $this->sessionManager->recordEntry([
            'type' => 'user_message',
            'content' => $transcript->message()['content'],
        ]);
        $this->messageHistory->addEnvelope(MessageEnvelope::user(
            $modelInput,
            persistence: MessageEnvelope::PERSIST_NONE,
            sensitivity: $sessionStart
                ? MessageEnvelope::SENSITIVITY_SENSITIVE
                : MessageEnvelope::SENSITIVITY_PUBLIC,
            audience: MessageEnvelope::AUDIENCE_MODEL,
            cacheStability: MessageEnvelope::CACHE_VOLATILE,
        ));
    }
}
