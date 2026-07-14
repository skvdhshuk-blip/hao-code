<?php

namespace Tests\Sdk;

use HaoCode\Services\Agent\AgentLoopFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Memory\JsonMemoryStore;
use HaoCode\Sdk\Memory\MemoryStoreInterface;
use HaoCode\Sdk\SdkRunFactory;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Support\Runtime\SdkRuntime;
use HaoCode\Tools\ToolRegistry;
use HaoCode\Tools\ToolUseContext;
use Tests\TestCase;

class MemoryRunIntegrationTest extends TestCase
{
    private string $memoryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memoryPath = sys_get_temp_dir().'/haocode_memory_run_'.uniqid('', true).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->memoryPath);
        @unlink($this->memoryPath.'.lock');
        parent::tearDown();
    }

    public function test_run_memory_tools_use_the_configured_store_and_filter(): void
    {
        $store = new JsonMemoryStore($this->memoryPath);
        $store->write('project', 'run-scoped memory');
        $run = SdkRunFactory::create(
            new HaoCodeConfig(
                apiKey: 'test-key',
                allowedTools: ['MemoryRead'],
                memoryStore: $store,
            ),
            SdkRuntime::app(AgentLoopFactory::class),
        );

        try {
            $registry = $this->toolRegistry($run->loop);
            $read = $registry->getTool('MemoryRead');

            $this->assertNotNull($read);
            $this->assertNull($registry->getTool('MemoryWrite'));
            $this->assertNull($registry->getTool('MemoryDelete'));
            $result = $read->call(
                ['key' => 'project', 'level' => 'l2'],
                new ToolUseContext('/tmp', 'memory-run'),
            );
            $this->assertStringContainsString('run-scoped memory', $result->output);
        } finally {
            $run->close();
        }
    }

    public function test_runtime_memory_store_respects_the_configured_storage_path(): void
    {
        SdkRuntime::app(SettingsManager::class)->set('memory_storage_path', $this->memoryPath);

        $store = SdkRuntime::app(MemoryStoreInterface::class);
        $store->write('runtime', 'configured path');

        $this->assertFileExists($this->memoryPath);
        $this->assertSame('configured path', (new JsonMemoryStore($this->memoryPath))->read('runtime'));
    }

    private function toolRegistry(object $loop): ToolRegistry
    {
        $property = new \ReflectionProperty($loop, 'toolRegistry');

        return $property->getValue($loop);
    }
}
