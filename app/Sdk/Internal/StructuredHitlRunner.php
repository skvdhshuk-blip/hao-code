<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Internal;

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\StructuredResult;

/** SDK-edge adapter that keeps the HaoCode facade out of the service layer. */
final class StructuredHitlRunner
{
    public function __invoke(string $prompt, array $schema, HaoCodeConfig $config): StructuredResult
    {
        return HaoCode::structured($prompt, $schema, $config);
    }
}
