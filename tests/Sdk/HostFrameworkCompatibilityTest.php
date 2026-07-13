<?php

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;

class HostFrameworkCompatibilityTest extends TestCase
{
    public function test_sdk_does_not_resolve_services_through_host_framework_helpers(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'haocode-host-helpers-');
        $this->assertNotFalse($script);
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        $source = <<<'PHP'
<?php
function app(mixed $abstract = null): mixed { throw new RuntimeException('host app() was called'); }
function config(mixed $key = null, mixed $default = null): mixed { throw new RuntimeException('host config() was called'); }
function env(string $key, mixed $default = null): mixed { throw new RuntimeException('host env() was called'); }
function storage_path(string $path = ''): string { throw new RuntimeException('host storage_path() was called'); }
function resource_path(string $path = ''): string { throw new RuntimeException('host resource_path() was called'); }
require %s;
$conversation = HaoCode\Sdk\HaoCode::conversation(new HaoCode\Sdk\HaoCodeConfig(apiKey: 'test-key', model: 'test-model'));
$conversation->close();
echo "host-compatible\n";
PHP;
        file_put_contents($script, sprintf($source, var_export($autoload, true)));

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $exitCode);
        @unlink($script);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertSame(['host-compatible'], $output);
    }
}
