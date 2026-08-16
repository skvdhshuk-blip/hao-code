<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class RunReplayResult
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, array<string, mixed>> $toolResults
     * @param array<string, int> $usage
     */
    public function __construct(
        public readonly string $runId,
        public readonly RunStatus $status,
        public readonly array $messages,
        public readonly array $toolResults,
        public readonly array $usage,
        public readonly ?string $text,
        public readonly int $lastSequence,
    ) {}
}
