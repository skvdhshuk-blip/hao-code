<?php

namespace Tests\Feature;

use HaoCode\Sdk\Sandbox\SandboxToolPolicy;
use HaoCode\Tools\ToolRegistry;
use Tests\TestCase;

class ToolRegistryResolutionTest extends TestCase
{
    public function test_the_tool_registry_can_be_resolved_from_the_container(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertInstanceOf(ToolRegistry::class, $registry);
        $this->assertNotNull($registry->getTool('Agent'));
        $this->assertNotNull($registry->getTool('SendMessage'));
        $this->assertNull(
            $registry->getTool('CronCreate'),
            'Cron tools must not be advertised until a production execution driver is wired.',
        );
        $this->assertNull($registry->getTool('CronDelete'));
        $this->assertNull($registry->getTool('CronList'));
    }

    public function test_every_sandbox_host_only_tool_name_is_registered_exactly(): void
    {
        $registeredToolNames = array_keys(
            $this->app->make(ToolRegistry::class)->getAllTools(),
        );

        foreach (SandboxToolPolicy::hostOnlyToolNames() as $hostOnlyToolName) {
            $this->assertTrue(
                in_array($hostOnlyToolName, $registeredToolNames, true),
                "Sandbox host-only tool '{$hostOnlyToolName}' is not registered by the SDK runtime.",
            );
        }
    }
}
