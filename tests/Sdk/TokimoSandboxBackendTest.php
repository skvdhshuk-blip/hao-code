<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Sandbox\SandboxBinaryResolver;
use HaoCode\Sdk\Sandbox\SandboxBinaryInstaller;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TokimoSandboxBackendTest extends TestCase
{
    public function test_tokimo_backend_uses_runner_protocol_and_shared_workspace(): void
    {
        $cwd = $this->tmpDir('haocode-tokimo-cwd-');
        $rootfs = $this->tmpDir('haocode-tokimo-rootfs-');
        file_put_contents($cwd.'/input.txt', 'from host');

        $runtime = SandboxManager::create(SandboxConfig::tokimo(
            baseRootfs: $rootfs,
            binary: dirname(__DIR__).'/fixtures/fake-tokimo-runner.php',
            sync: 'upload-cwd',
        ), $cwd);

        $this->assertSame('from host', $runtime->backend->readFile('/workspace/input.txt'));
        $result = $runtime->backend->exec('printf hello', '/workspace', 1000);

        $this->assertSame(0, $result['exitCode']);
        $this->assertFalse($result['timedOut']);
        $this->assertSame('printf hello|/workspace', $result['stdout']);
        $this->assertStringStartsWith('tokimo:', $runtime->backend->rootLabel());

        $runtime->close();
        $this->removeDir($cwd);
        $this->removeDir($rootfs);
    }

    public function test_tokimo_config_rejects_invalid_resource_and_network_settings(): void
    {
        try {
            SandboxConfig::tokimo('/tmp', network: 'sometimes');
            $this->fail('Unknown network policies must fail.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('network policy', $exception->getMessage());
        }

        try {
            SandboxConfig::tokimo('/tmp', memoryMb: 128);
            $this->fail('Memory limits below the Tokimo minimum must fail.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('memoryMb', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        SandboxConfig::tokimo('/tmp', cpuCount: 65);
    }

    #[DataProvider('filesystemRootProvider')]
    public function test_tokimo_config_rejects_cross_platform_filesystem_roots(string $root): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filesystem root');

        SandboxConfig::tokimo('/tmp', root: $root);
    }

    public static function filesystemRootProvider(): array
    {
        return [
            'unix root' => ['/'],
            'windows drive root' => ['C:\\'],
            'windows drive root with slash' => ['D:/'],
            'UNC share root' => ['\\\\server\\share\\'],
        ];
    }

    public function test_tokimo_backend_rechecks_root_when_public_constructor_bypasses_factory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filesystem root');

        SandboxManager::create(new SandboxConfig(
            provider: 'tokimo',
            mode: 'full',
            root: '/',
            options: [
                'baseRootfs' => '/tmp',
                'binary' => dirname(__DIR__).'/fixtures/fake-tokimo-runner.php',
            ],
        ));
    }

    public function test_development_runner_resolves_from_project_bin(): void
    {
        $expected = dirname(__DIR__, 2).'/bin/'.SandboxBinaryInstaller::platformBinaryName();
        if (! is_file($expected)) {
            $this->markTestSkipped(
                'Platform runner is an optional release-staging artifact and is absent from this checkout.',
            );
        }

        $runner = SandboxBinaryResolver::resolve();

        $this->assertSame(realpath(dirname(__DIR__, 2).'/bin'), dirname($runner));
        $this->assertStringStartsWith('haocode-sandbox-', basename($runner));
    }

    private function tmpDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
