<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Mcp\Server\ToolAdapter;
use HaoCode\Tools\FileRead\FileReadTool;
use PHPUnit\Framework\TestCase;

final class ToolAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/haocode-mcp-adapter-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/nested', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function test_relative_file_paths_are_executed_against_the_configured_root(): void
    {
        file_put_contents($this->root.'/nested/note.txt', "from configured root\n");

        $adapter = new ToolAdapter;
        $adapter->setRoot($this->root);
        $adapter->registerBuiltin(new FileReadTool);

        $result = $adapter->invoke('Read', ['file_path' => 'nested/note.txt']);

        $this->assertFalse($result['isError'] ?? false);
        $this->assertStringContainsString(
            'from configured root',
            $result['content'][0]['text'] ?? '',
        );
    }

    public function test_filesystem_root_remains_a_valid_configured_root(): void
    {
        file_put_contents($this->root.'/nested/root-note.txt', "from filesystem root\n");

        $adapter = new ToolAdapter;
        $adapter->setRoot(DIRECTORY_SEPARATOR);
        $adapter->registerBuiltin(new FileReadTool);

        $relativePath = ltrim($this->root, '/\\').'/nested/root-note.txt';
        $result = $adapter->invoke('Read', ['file_path' => $relativePath]);

        $this->assertFalse($result['isError'] ?? false);
        $this->assertStringContainsString(
            'from filesystem root',
            $result['content'][0]['text'] ?? '',
        );
    }

    public function test_sensitive_path_matching_normalizes_windows_separators(): void
    {
        $method = (new \ReflectionClass(ToolAdapter::class))->getMethod('isSensitivePath');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(new ToolAdapter, 'C:\\workspace\\.ssh\\id_rsa'));
        $this->assertTrue($method->invoke(new ToolAdapter, 'C:\\workspace\\.env.local'));
        $this->assertFalse($method->invoke(new ToolAdapter, 'C:\\workspace\\src\\App.php'));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $item) {
            $this->removeTree($item->getPathname());
        }
        @rmdir($path);
    }
}
