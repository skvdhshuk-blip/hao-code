<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function test_it_registers_and_resolves_all_provider_types(): void
    {
        $anthropic = $this->createMock(LlmProvider::class);
        $openai = $this->createMock(LlmProvider::class);
        $openaiChat = $this->createMock(LlmProvider::class);
        $registry = new ProviderRegistry([
            'anthropic' => $anthropic,
            'openai' => $openai,
            'openai_chat' => $openaiChat,
        ]);

        $this->assertSame(['anthropic', 'openai', 'openai_chat'], $registry->types());
        $this->assertSame($anthropic, $registry->get('anthropic'));
        $this->assertSame($openai, $registry->get('responses'));
        $this->assertSame($openaiChat, $registry->get('chat_completions'));
    }

    public function test_it_fails_when_a_known_type_has_no_registered_adapter(): void
    {
        $registry = new ProviderRegistry([
            'anthropic' => $this->createMock(LlmProvider::class),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('openai');

        $registry->get('openai');
    }
}
