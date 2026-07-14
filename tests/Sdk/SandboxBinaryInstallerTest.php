<?php

namespace Tests\Sdk;

use HaoCode\Sdk\Sandbox\SandboxBinaryInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SandboxBinaryInstallerTest extends TestCase
{
    public function test_installs_and_verifies_only_current_platform_assets(): void
    {
        $cache = sys_get_temp_dir().'/haocode-sandbox-installer-'.bin2hex(random_bytes(4));
        $previousCache = getenv('HAOCODE_SANDBOX_CACHE');
        putenv('HAOCODE_SANDBOX_CACHE='.$cache);

        try {
            try {
                $runner = SandboxBinaryInstaller::platformBinaryName();
            } catch (\RuntimeException $exception) {
                $this->markTestSkipped($exception->getMessage());
            }

            $assets = [$runner => "runner:{$runner}\n"];
            if (PHP_OS_FAMILY === 'Windows') {
                $assets['haocode-sandbox-svc-windows-amd64.exe'] = "windows service\n";
            }
            $requests = [];
            $client = new MockHttpClient(function (string $method, string $url) use (&$requests, $assets): MockResponse {
                $this->assertSame('GET', $method);
                $requests[] = $url;
                $name = basename((string) parse_url($url, PHP_URL_PATH));
                if (str_ends_with($name, '.sha256')) {
                    $assetName = substr($name, 0, -strlen('.sha256'));
                    return new MockResponse(hash('sha256', $assets[$assetName])."  {$assetName}\n");
                }

                return new MockResponse($assets[$name]);
            });

            $installed = SandboxBinaryInstaller::install(
                releaseTag: '9.9.9',
                releaseBase: 'https://example.test/releases/download',
                client: $client,
            );

            $this->assertCount(count($assets), $installed);
            $this->assertCount(count($assets) * 2, $requests);
            $this->assertSame($installed[0], SandboxBinaryInstaller::cachedBinary('v9.9.9'));
            foreach ($installed as $path) {
                $this->assertFileExists($path);
                $this->assertFileExists($path.'.sha256');
                $this->assertStringStartsWith($cache, $path);
            }

            $reinstalled = SandboxBinaryInstaller::install(
                releaseTag: 'v9.9.9',
                releaseBase: 'https://example.test/releases/download',
                force: true,
                client: $client,
            );
            $this->assertSame($installed, $reinstalled);
            $this->assertCount(count($assets) * 4, $requests);
        } finally {
            $previousCache === false
                ? putenv('HAOCODE_SANDBOX_CACHE')
                : putenv('HAOCODE_SANDBOX_CACHE='.$previousCache);
            $this->removeDirectory($cache);
        }
    }

    public function test_rejects_release_tag_path_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SandboxBinaryInstaller::cacheDirectory('../../outside');
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($directory);
    }
}
