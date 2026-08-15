<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

/**
 * One attributed fragment of a model prompt before provider adaptation.
 *
 * @internal
 */
final class PromptFragment
{
    public const STABILITY_RUN = 'run';
    public const STABILITY_TURN = 'turn';

    public const SENSITIVITY_PUBLIC = 'public';
    public const SENSITIVITY_INTERNAL = 'internal';
    public const SENSITIVITY_SENSITIVE = 'sensitive';

    public function __construct(
        public readonly string $source,
        public readonly string $content,
        public readonly string $stability = self::STABILITY_RUN,
        public readonly string $sensitivity = self::SENSITIVITY_INTERNAL,
    ) {
        if (trim($this->source) === '') {
            throw new \InvalidArgumentException('PromptFragment source must not be empty.');
        }
        if (! in_array($this->stability, [self::STABILITY_RUN, self::STABILITY_TURN], true)) {
            throw new \InvalidArgumentException("Unknown prompt stability: {$this->stability}");
        }
        if (! in_array($this->sensitivity, [
            self::SENSITIVITY_PUBLIC,
            self::SENSITIVITY_INTERNAL,
            self::SENSITIVITY_SENSITIVE,
        ], true)) {
            throw new \InvalidArgumentException("Unknown prompt sensitivity: {$this->sensitivity}");
        }
    }

    public function telemetryContent(): string
    {
        return $this->sensitivity === self::SENSITIVITY_SENSITIVE
            ? '[redacted]'
            : $this->content;
    }
}
