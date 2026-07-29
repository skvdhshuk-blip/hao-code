<?php

namespace HaoCode\Support\JsonSchema;

use Swaggest\JsonSchema\RemoteRefProvider;

/**
 * Prevent JSON Schema validation from resolving caller-controlled resources.
 *
 * @internal
 */
final class DenyRemoteRefProvider implements RemoteRefProvider
{
    public function getSchemaData($url)
    {
        throw new ExternalReferenceException;
    }
}
