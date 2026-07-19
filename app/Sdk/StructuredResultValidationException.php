<?php

namespace HaoCode\Sdk;

/**
 * Thrown when {@see HaoCode::structured()} cannot produce output that satisfies
 * the supplied JSON Schema after exhausting {@see HaoCodeConfig::$structuredMaxRetries}
 * validation retries.
 *
 * Carries the raw model response and the validator's error list so callers can
 * log, fall back, or surface a meaningful message to end users.
 *
 * @api
 */
final class StructuredResultValidationException extends \RuntimeException
{
    /** @api */
    public readonly string $rawResponse;

    /** @var list<string> */
    public readonly array $validationErrors;

    /**
     * @param list<string> $validationErrors  Human-readable validator error paths
     * @api
     */
    public function __construct(string $message, string $rawResponse, array $validationErrors)
    {
        parent::__construct($message);

        $this->rawResponse = $rawResponse;
        $this->validationErrors = $validationErrors;
    }
}
