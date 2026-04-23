<?php

namespace HaoCode\Services\Api;

/**
 * Thrown by CredentialPool::pickNext() when all credentials for a provider
 * are exhausted and no fallback is possible.
 */
class NoAvailableCredentialException extends \RuntimeException {}
