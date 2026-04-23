<?php

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that HaoCode\Sdk\* public API has not changed from the committed snapshot.
 *
 * To intentionally update the snapshot after a deliberate public-API change:
 *   php scripts/sdk-bc-check.php --write
 *   git add tests/Sdk/Fixtures/public-api.snapshot.json
 */
class PublicApiTest extends TestCase
{
    public function test_public_api_matches_snapshot(): void
    {
        $scriptPath = dirname(__DIR__, 2).'/scripts/sdk-bc-check.php';
        $this->assertFileExists($scriptPath, 'sdk-bc-check.php not found');

        $snapshotPath = dirname(__DIR__).'/Sdk/Fixtures/public-api.snapshot.json';
        $this->assertFileExists($snapshotPath, 'public-api.snapshot.json not found — run: php scripts/sdk-bc-check.php --write');

        $output = [];
        $exitCode = 0;
        exec('php '.escapeshellarg($scriptPath).' --verify 2>&1', $output, $exitCode);

        $this->assertSame(
            0,
            $exitCode,
            "Public API has changed from snapshot:\n".implode("\n", $output)
        );
    }
}
