<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\AnthropicProvider;
use HaoCode\Services\Api\OpenAiChatProvider;
use HaoCode\Services\Api\OpenAiProvider;
use PHPUnit\Framework\TestCase;

/**
 * @group live
 */
class LiveMatrixTest extends TestCase
{
    private function assert_live_response(iterable $stream): void
    {
        $text = '';
        $inputTokens = 0;
        $outputTokens = 0;

        foreach ($stream as $event) {
            if ($event->type === 'content_block_delta') {
                $delta = $event->data['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta') {
                    $text .= $delta['text'] ?? '';
                }
            }
            if ($event->type === 'message_start') {
                $inputTokens = (int) ($event->data['message']['usage']['input_tokens'] ?? 0);
            }
            if ($event->type === 'message_delta') {
                $outputTokens = (int) ($event->data['usage']['output_tokens'] ?? 0);
            }
        }

        $this->assertNotEmpty($text, 'Live response must contain non-empty text');
        $this->assertGreaterThan(0, $inputTokens, 'input_tokens must be > 0');
        $this->assertGreaterThan(0, $outputTokens, 'output_tokens must be > 0');
    }

    public function test_anthropic_live(): void
    {
        $key = getenv('ANTHROPIC_API_KEY');
        if (! $key) {
            $this->markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        $provider = new AnthropicProvider(apiKey: $key, model: 'claude-haiku-4-5-20251001');
        $this->assert_live_response($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'Say hi in one word']],
            tools: [],
        ));
    }

    public function test_openai_responses_live(): void
    {
        $key = getenv('OPENAI_API_KEY');
        if (! $key) {
            $this->markTestSkipped('OPENAI_API_KEY not set');
        }

        $provider = new OpenAiProvider(apiKey: $key, model: 'gpt-4o-mini');
        $this->assert_live_response($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'Say hi in one word']],
            tools: [],
        ));
    }

    public function test_openai_chat_live(): void
    {
        $key = getenv('OPENAI_API_KEY') ?: getenv('ZAI_API_KEY');
        if (! $key) {
            $this->markTestSkipped('OPENAI_API_KEY / ZAI_API_KEY not set');
        }

        $provider = new OpenAiChatProvider(apiKey: $key, model: 'gpt-4o-mini');
        $this->assert_live_response($provider->streamMessages(
            systemPrompt: [],
            messages: [['role' => 'user', 'content' => 'Say hi in one word']],
            tools: [],
        ));
    }
}
