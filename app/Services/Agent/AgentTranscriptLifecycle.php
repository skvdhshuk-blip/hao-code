<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Services\Session\SessionManager;
use HaoCode\Services\ToolResult\ToolResultStorage;

/** Owns durable transcript availability and write-failure state. @internal */
final class AgentTranscriptLifecycle
{
    private bool $persistenceFailed = false;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ToolOrchestrator $orchestrator,
    ) {}

    public function bindToolResultStorage(): void
    {
        if (! $this->sessions->isPersistenceEnabled()) {
            return;
        }
        $this->orchestrator->setToolResultStorage(new ToolResultStorage(
            $this->sessions->getSessionPath(),
            $this->sessions->getSessionId(),
        ));
    }

    public function assertUsable(): void
    {
        if ($this->persistenceFailed) {
            throw new \RuntimeException(
                'This durable conversation cannot continue because a previous transcript write failed. '
                .'Create or resume a fresh conversation from the last persisted state.',
            );
        }
    }

    /** @param string|array<int, mixed> $userInput @param string|array<int, mixed> $modelInput */
    public function recordUserInput(
        string|array $userInput,
        string|array $modelInput,
        bool $sessionStart,
        MessageHistory $history,
    ): void {
        $transcript = MessageEnvelope::user(
            $userInput,
            persistence: MessageEnvelope::PERSIST_TRANSCRIPT,
            audience: MessageEnvelope::AUDIENCE_UI,
        );
        $this->sessions->recordEntry([
            'type' => 'user_message',
            'content' => $transcript->message()['content'],
        ]);
        $history->addEnvelope(MessageEnvelope::user(
            $modelInput,
            persistence: MessageEnvelope::PERSIST_NONE,
            sensitivity: $sessionStart
                ? MessageEnvelope::SENSITIVITY_SENSITIVE
                : MessageEnvelope::SENSITIVITY_PUBLIC,
            audience: MessageEnvelope::AUDIENCE_MODEL,
            cacheStability: MessageEnvelope::CACHE_VOLATILE,
        ));
    }

    /**
     * Record loop-generated user-side text as a real conversation turn.
     *
     * Unlike a trailing block on a tool-result message, this becomes its own message,
     * so it must be persisted: resume replays the transcript, and a model-only message
     * would leave two assistant turns adjacent. CACHE_STABLE because the text never
     * changes after it is appended.
     */
    public function recordSyntheticUserMessage(string $text, MessageHistory $history): void
    {
        $this->sessions->recordEntry([
            'type' => 'user_message',
            'content' => $text,
        ]);
        $history->addEnvelope(MessageEnvelope::user(
            $text,
            persistence: MessageEnvelope::PERSIST_NONE,
            sensitivity: MessageEnvelope::SENSITIVITY_PUBLIC,
            audience: MessageEnvelope::AUDIENCE_MODEL,
            cacheStability: MessageEnvelope::CACHE_STABLE,
        ));
    }

    /** @param string|array<int, mixed>|null $userInput */
    public function persistInitialTitle(string|array|null $userInput): bool
    {
        if ($this->sessions->getTitle() !== null) {
            return false;
        }
        if (is_string($userInput)) {
            $rawTitle = $userInput;
        } elseif (is_array($userInput)) {
            $texts = array_filter(
                array_map(
                    static fn ($block) => is_string($block)
                        ? $block
                        : (is_array($block) ? ($block['text'] ?? null) : null),
                    $userInput,
                ),
                static fn ($text): bool => is_string($text) && $text !== '',
            );
            $rawTitle = implode(' ', $texts);
        } else {
            $rawTitle = '';
        }

        $title = preg_replace('/\s+/', ' ', trim(mb_substr($rawTitle, 0, 80)));
        if (is_string($title) && $title !== '') {
            $this->persistTitle($title);
        }

        return true;
    }

    public function persistTurn(array $assistantMessage, array $toolResults): void
    {
        try {
            $this->sessions->recordTurn($assistantMessage, $toolResults);
        } catch (\Throwable $error) {
            $this->persistenceFailed = true;
            throw new \RuntimeException(
                'Model or tool execution may have completed, but the durable transcript could not be written. '
                .'This conversation is no longer safe to continue.',
                0,
                $error,
            );
        }
    }

    public function persistTitle(string $title): void
    {
        try {
            $this->sessions->setTitle($title);
        } catch (\Throwable $error) {
            $this->persistenceFailed = true;
            throw new \RuntimeException(
                'Model or tool execution completed, but the durable session title could not be written. '
                .'This conversation is no longer safe to continue.',
                0,
                $error,
            );
        }
    }
}
