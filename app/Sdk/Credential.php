<?php

namespace HaoCode\Sdk;

/**
 * Immutable credential DTO for a single API key entry in a credential pool.
 *
 * @api
 */
final class Credential
{
    /**
     * @param  array<string, mixed>  $meta  Arbitrary metadata (e.g. label, tier, rpm_limit).
     */
    public function __construct(
        /**
         * The API key value.
         *
         * @api
         */
        public readonly string $apiKey,

        /**
         * Stable identifier for this credential (defaults to a hash of apiKey).
         *
         * @api
         */
        public readonly string $id = '',

        /**
         * Routing priority — higher wins when multiple credentials are available.
         * Default 0 means equal weight.
         *
         * @api
         */
        public readonly int $priority = 0,

        /**
         * Arbitrary metadata (e.g. 'label', 'tier', 'rpm_limit').
         *
         * @api
         *
         * @var array<string, mixed>
         */
        public readonly array $meta = [],
    ) {}

    /**
     * Return a safe, non-reversible identifier suitable for log/span attributes.
     *
     * @api
     */
    public function idHash(): string
    {
        $id = $this->id !== '' ? $this->id : $this->apiKey;

        return substr(hash('sha256', $id), 0, 12);
    }

    /**
     * Create a Credential with an auto-generated ID.
     *
     * @api
     *
     * @param  array<string, mixed>  $meta
     */
    public static function make(string $apiKey, int $priority = 0, array $meta = []): self
    {
        $id = 'cred_'.substr(hash('sha256', $apiKey), 0, 8);

        return new self(apiKey: $apiKey, id: $id, priority: $priority, meta: $meta);
    }
}
