<?php

namespace HaoCode\Support\JsonSchema;

/**
 * Raised when a schema attempts to resolve anything outside its own document.
 *
 * @internal
 */
final class ExternalReferenceException extends \RuntimeException
{
}
