<?php

namespace HaoCode\Services\Mcp;

class McpConnectionException extends \RuntimeException
{
    public const TYPE_PROTOCOL = 'protocol';

    public const TYPE_TRANSPORT = 'transport';

    public const TYPE_APPLICATION = 'application';

    public function __construct(
        string $message,
        int $code = 0,
        private readonly string $errorType = self::TYPE_APPLICATION,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public static function protocol(string $message, int $code = 0): self
    {
        return new self($message, $code, self::TYPE_PROTOCOL);
    }

    public static function transport(string $message, int $code = 0): self
    {
        return new self($message, $code, self::TYPE_TRANSPORT);
    }

    public static function application(string $message, int $code = 0): self
    {
        return new self($message, $code, self::TYPE_APPLICATION);
    }
}
