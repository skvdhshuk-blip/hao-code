<?php

namespace HaoCode\Sdk\Sandbox;

/**
 * Optional backend capability for compare-and-swap file writes.
 *
 * A null expected hash means that the target must not exist. A non-null hash
 * means that the target must still contain exactly the bytes previously read.
 *
 * @internal
 */
interface RevisionAwareSandboxBackendInterface
{
    public function writeFileIfUnchanged(
        string $path,
        string $content,
        ?string $expectedSha256,
    ): void;
}
