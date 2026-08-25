<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

/** @internal */
final class RuntimeDefaults
{
    public function __construct(
        public readonly int $apiStreamIdleTimeoutSeconds = 60,
        public readonly float $apiStreamPollTimeoutSeconds = 1.0,
        public readonly string $sessionPath = '',
        public readonly string $budgetDirectory = '',
    ) {
        if ($this->apiStreamIdleTimeoutSeconds <= 0 || $this->apiStreamPollTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('API stream timeouts must be greater than zero.');
        }
        if (trim($this->sessionPath) === '' || trim($this->budgetDirectory) === '') {
            throw new \InvalidArgumentException('Runtime storage paths must be non-empty.');
        }
    }

    public static function capture(): self
    {
        return new self(
            (int) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_stream_idle_timeout', 60),
            (float) \HaoCode\Support\Runtime\SdkRuntime::config('haocode.api_stream_poll_timeout', 1.0),
            (string) \HaoCode\Support\Runtime\SdkRuntime::config(
                'haocode.session_path',
                \HaoCode\Support\Runtime\SdkRuntime::storagePath('app/haocode/sessions'),
            ),
            \HaoCode\Support\Runtime\SdkRuntime::storagePath('app/haocode/budgets'),
        );
    }
}
