<?php

namespace Tests\Unit;

use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Services\Memory\SessionMemory;
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

    public function test_json_stores_observe_rapid_same_size_writes(): void
    {
        $first = new JsonMemoryStore($this->path);
        $second = new JsonMemoryStore($this->path);

        $first->write('shared', 'AAAA');
        $this->assertSame('AAAA', $second->read('shared'));

        $second->write('shared', 'BBBB');
        $this->assertSame('BBBB', $first->read('shared'));

        $first->write('shared', 'CCCC');
        $this->assertSame('CCCC', $second->read('shared'));
    }

    public function test_store_detects_same_size_in_place_rewrite_when_metadata_collides(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            @unlink($this->path);
            $store = new JsonMemoryStore($this->path);
            $store->write('shared', 'AAAA');
            $this->assertSame('AAAA', $store->read('shared'));

            $original = file_get_contents($this->path);
            $this->assertIsString($original);
            $replacement = str_replace('AAAA', 'BBBB', $original);
            $this->assertSame(strlen($original), strlen($replacement));

            $before = $this->metadataSignature($this->path);
            file_put_contents($this->path, $replacement);
            $after = $this->metadataSignature($this->path);

            if ($before === $after) {
                $this->assertSame('BBBB', $store->read('shared'));

                return;
            }
        }

        $this->fail('Unable to reproduce a same-second metadata collision.');
    }

    public function test_store_observes_file_deletion_and_recreation(): void
    {
        $first = new JsonMemoryStore($this->path);
        $second = new JsonMemoryStore($this->path);
        $first->write('shared', 'before');
        $this->assertSame('before', $second->read('shared'));

        unlink($this->path);
        $this->assertNull($first->read('shared'));
        $this->assertNull($second->read('shared'));

        $second->write('shared', 'after');
        $this->assertSame('after', $first->read('shared'));
    }

    public function test_store_recovers_after_invalid_json_is_replaced(): void
    {
        $store = new JsonMemoryStore($this->path);
        $store->write('shared', 'valid');
        $validJson = file_get_contents($this->path);
        $this->assertIsString($validJson);

        $this->atomicReplace($this->path, '{"shared":');
        $this->assertNull($store->read('shared'));

        $this->atomicReplace($this->path, $validJson);
        $this->assertSame('valid', $store->read('shared'));
    }

    public function test_concurrent_process_writes_preserve_every_entry(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for lock contention coverage.');
        }

        $store = new JsonMemoryStore($this->path);
        $store->write('seed', '');

        $children = [];
        $releaseSockets = [];
        for ($index = 0; $index < 6; $index++) {
            $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($barrier === false) {
                $this->markTestSkipped('A local socket pair is required for lock contention coverage.');
            }

            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                fclose($barrier[0]);
                $released = fread($barrier[1], 1);
                fclose($barrier[1]);

                try {
                    if ($released !== 'x') {
                        exit(1);
                    }
                    (new JsonMemoryStore($this->path))->write("child_{$index}", '');
                    exit(0);
                } catch (\Throwable $exception) {
                    fwrite(STDERR, $exception->getMessage()."\n");
                    exit(1);
                }
            }

            fclose($barrier[1]);
            $children[] = $pid;
            $releaseSockets[] = $barrier[0];
        }

        foreach ($releaseSockets as $releaseSocket) {
            $this->assertSame(1, fwrite($releaseSocket, 'x'));
            fclose($releaseSocket);
        }

        foreach ($children as $pid) {
            $status = 0;
            $this->assertSame($pid, pcntl_waitpid($pid, $status));
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $entries = (new SessionMemory($this->path))->list();
        $this->assertCount(7, $entries);
        for ($index = 0; $index < 6; $index++) {
            $this->assertArrayHasKey("child_{$index}", $entries);
            $this->assertSame('', $entries["child_{$index}"]['value'] ?? null);
        }
    }

    private function metadataSignature(string $path): array
    {
        clearstatcache(true, $path);
        $stat = stat($path);
        $this->assertIsArray($stat);

        return [
            $stat['dev'] ?? null,
            $stat['ino'] ?? null,
            $stat['size'] ?? null,
            $stat['mtime'] ?? null,
            $stat['ctime'] ?? null,
        ];
    }

    private function atomicReplace(string $path, string $contents): void
    {
        $temporaryPath = tempnam(dirname($path), '.memory-test-');
        $this->assertIsString($temporaryPath);
        file_put_contents($temporaryPath, $contents);
        rename($temporaryPath, $path);
    }
}
