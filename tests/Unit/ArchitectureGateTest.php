<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Scripts\PhpAggregateSizeCheck;
use HaoCode\Scripts\RuntimeDependencyCheck;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/php-aggregate-size-check.php';
require_once dirname(__DIR__, 2).'/scripts/runtime-dependency-check.php';

final class ArchitectureGateTest extends TestCase
{
    public function test_production_aggregates_stay_within_trait_and_concern_limits(): void
    {
        $result = PhpAggregateSizeCheck::audit(dirname(__DIR__, 2));

        $this->assertGreaterThan(0, $result['classes']);
        $this->assertSame([], $result['issues']);
    }

    public function test_lower_layers_do_not_reach_into_the_sdk_runtime(): void
    {
        $this->assertSame([], RuntimeDependencyCheck::audit(dirname(__DIR__, 2)));
    }
}
