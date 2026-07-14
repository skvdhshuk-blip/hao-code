<?php

namespace Tests\Unit;

use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Tools\Memory\MemoryDeleteTool;
use HaoCode\Tools\Memory\MemoryReadTool;
use HaoCode\Tools\Memory\MemoryWriteTool;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class MemoryToolsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/haocode_memory_tools_'.uniqid('', true).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path.'.lock');
    }

    public function test_read_write_and_delete_share_the_same_store(): void
    {
        $store = new JsonMemoryStore($this->path);
        $context = new ToolUseContext('/tmp', 'memory-tools');
        $write = new MemoryWriteTool($store);
        $read = new MemoryReadTool($store);
        $delete = new MemoryDeleteTool($store);

        $writeResult = $write->call([
            'key' => 'language',
            'value' => 'The user prefers PHP examples.',
            'type' => 'preference',
        ], $context);
        $readResult = $read->call(['key' => 'language', 'level' => 'l2'], $context);
        $deleteResult = $delete->call(['key' => 'language'], $context);

        $this->assertFalse($writeResult->isError);
        $this->assertStringContainsString('The user prefers PHP examples.', $readResult->output);
        $this->assertFalse($deleteResult->isError);
        $this->assertNull($store->read('language'));
        $this->assertFalse($write->isReadOnly([]));
        $this->assertFalse($delete->isReadOnly([]));
    }

    public function test_json_store_reads_updates_from_another_instance(): void
    {
        $first = new JsonMemoryStore($this->path);
        $second = new JsonMemoryStore($this->path);

        $first->write('shared', 'first');
        $this->assertSame('first', $second->read('shared'));

        $second->write('second_key', 'preserved');
        $this->assertSame(['shared', 'second_key'], array_keys($first->all()));

        $second->write('shared', 'second');
        $this->assertSame('second', $first->read('shared'));
    }
}
