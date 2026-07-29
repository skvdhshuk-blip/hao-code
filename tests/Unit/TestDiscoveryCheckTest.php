<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Scripts\TestDiscoveryCheck;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/scripts/test-discovery-check.php';

final class TestDiscoveryCheckTest extends TestCase
{
    public function test_repository_phpunit_config_covers_every_test_file(): void
    {
        $result = TestDiscoveryCheck::audit(dirname(__DIR__, 2));

        $this->assertGreaterThan(0, $result['testFiles']);
        $this->assertSame([], $result['issues']);
    }

    public function test_audit_reports_a_test_outside_configured_paths(): void
    {
        $root = sys_get_temp_dir().'/haocode-test-discovery-'.bin2hex(random_bytes(6));
        mkdir($root.'/tests/Unit', 0755, true);
        mkdir($root.'/tests/Feature', 0755, true);
        file_put_contents($root.'/tests/Unit/CoveredTest.php', "<?php\nclass CoveredTest {}\n");
        file_put_contents($root.'/tests/Feature/MissingTest.php', "<?php\nclass MissingTest {}\n");
        file_put_contents(
            $root.'/phpunit.xml',
            <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML,
        );

        try {
            $result = TestDiscoveryCheck::audit($root);

            $this->assertSame(
                ['Test file is not covered by phpunit.xml: tests/Feature/MissingTest.php'],
                $result['issues'],
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_audit_accepts_windows_style_configured_paths(): void
    {
        $root = sys_get_temp_dir().'/haocode-test-discovery-'.bin2hex(random_bytes(6));
        mkdir($root.'/tests/Unit', 0755, true);
        file_put_contents($root.'/tests/Unit/CoveredTest.php', "<?php\nclass CoveredTest {}\n");
        file_put_contents(
            $root.'/phpunit.xml',
            <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests\Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML,
        );

        try {
            $result = TestDiscoveryCheck::audit($root);

            $this->assertSame(1, $result['testFiles']);
            $this->assertSame([], $result['issues']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
