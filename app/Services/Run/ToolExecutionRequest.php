<?php

declare(strict_types=1);

namespace HaoCode\Services\Run;

/** @internal */
final class ToolExecutionRequest
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $runId,
        public readonly string $invocationId,
        public readonly string $toolUseId,
        public readonly string $toolName,
        public readonly string $inputHash,
        public readonly bool $readOnly,
        public readonly bool $resumeInterrupted = false,
    ) {
        foreach ([$idempotencyKey, $runId, $invocationId, $toolUseId, $toolName, $inputHash] as $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException('Tool execution identity must not be empty.');
            }
        }
    }
}
