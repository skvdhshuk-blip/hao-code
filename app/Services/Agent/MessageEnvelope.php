<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * Internal message ownership contract, independent of provider wire format.
 *
 * @internal
 */
final class MessageEnvelope
{
    public const AUDIENCE_MODEL = 'model';
    public const AUDIENCE_UI = 'ui';
    public const AUDIENCE_BOTH = 'both';

    public const PERSIST_TRANSCRIPT = 'transcript';
    public const PERSIST_NONE = 'none';

    public const SENSITIVITY_PUBLIC = 'public';
    public const SENSITIVITY_INTERNAL = 'internal';
    public const SENSITIVITY_SENSITIVE = 'sensitive';

    public const CACHE_STABLE = 'stable';
    public const CACHE_VOLATILE = 'volatile';

    /** @param array<string, mixed> $message */
    public function __construct(
        private readonly array $message,
        public readonly string $audience = self::AUDIENCE_BOTH,
        public readonly string $persistence = self::PERSIST_TRANSCRIPT,
        public readonly string $sensitivity = self::SENSITIVITY_PUBLIC,
        public readonly string $cacheStability = self::CACHE_STABLE,
    ) {
        if (! is_string($this->message['role'] ?? null)
            || ! array_key_exists('content', $this->message)) {
            throw new \InvalidArgumentException('MessageEnvelope requires role and content.');
        }
        self::assertOneOf($this->audience, [self::AUDIENCE_MODEL, self::AUDIENCE_UI, self::AUDIENCE_BOTH], 'audience');
        self::assertOneOf($this->persistence, [self::PERSIST_TRANSCRIPT, self::PERSIST_NONE], 'persistence');
        self::assertOneOf($this->sensitivity, [
            self::SENSITIVITY_PUBLIC,
            self::SENSITIVITY_INTERNAL,
            self::SENSITIVITY_SENSITIVE,
        ], 'sensitivity');
        self::assertOneOf($this->cacheStability, [self::CACHE_STABLE, self::CACHE_VOLATILE], 'cacheStability');
    }

    /** @param string|array<int, mixed> $content */
    public static function user(
        string|array $content,
        string $persistence = self::PERSIST_TRANSCRIPT,
        string $sensitivity = self::SENSITIVITY_PUBLIC,
        string $audience = self::AUDIENCE_BOTH,
        string $cacheStability = self::CACHE_STABLE,
    ): self {
        return new self(
            ['role' => 'user', 'content' => $content],
            $audience,
            $persistence,
            $sensitivity,
            $cacheStability,
        );
    }

    /** @param array<string, mixed> $message */
    public static function fromMessage(
        array $message,
        string $persistence = self::PERSIST_TRANSCRIPT,
        string $sensitivity = self::SENSITIVITY_PUBLIC,
        string $audience = self::AUDIENCE_BOTH,
        string $cacheStability = self::CACHE_STABLE,
    ): self {
        return new self($message, $audience, $persistence, $sensitivity, $cacheStability);
    }

    /** @return array<string, mixed> */
    public function message(): array
    {
        return $this->message;
    }

    public function isModelVisible(): bool
    {
        return $this->audience !== self::AUDIENCE_UI;
    }

    public function shouldPersist(): bool
    {
        return $this->persistence === self::PERSIST_TRANSCRIPT;
    }

    /** @return array<string, mixed> */
    public function telemetryMessage(): array
    {
        if ($this->sensitivity !== self::SENSITIVITY_SENSITIVE) {
            return $this->message;
        }

        return array_replace($this->message, ['content' => '[redacted]']);
    }

    /** @param list<string> $allowed */
    private static function assertOneOf(string $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown MessageEnvelope {$field}: {$value}");
        }
    }
}
