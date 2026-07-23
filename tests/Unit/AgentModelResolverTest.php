<?php

namespace Tests\Unit;

use HaoCode\Tools\Agent\AgentModelResolver;
use PHPUnit\Framework\TestCase;

class AgentModelResolverTest extends TestCase
{
    public function test_call_model_takes_precedence_over_definition_model(): void
    {
        $this->assertSame(
            'claude-opus-4-20250514',
            AgentModelResolver::resolve('opus', 'haiku'),
        );
    }

    public function test_inherit_and_null_keep_the_parent_model(): void
    {
        $this->assertNull(AgentModelResolver::resolve(null, 'inherit'));
        $this->assertNull(AgentModelResolver::resolve(null, null));
    }

    public function test_invalid_definition_model_is_rejected_explicitly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported agent model');

        AgentModelResolver::resolve(null, 'fastest');
    }
}
