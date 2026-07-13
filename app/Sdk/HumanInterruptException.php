<?php

namespace HaoCode\Sdk;

/**
 * Expected control-flow exception raised when a non-streaming run pauses.
 *
 * @api
 */
final class HumanInterruptException extends \RuntimeException
{
    /** @api */
    public function __construct(
        /** @api */
        public readonly HumanInterrupt $interrupt,
    ) {
        parent::__construct('Agent execution requires human input: '.$interrupt->id);
    }
}
