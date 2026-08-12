<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

abstract class ProviderMatrixContract extends TestCase
{
    abstract protected function providerName(): string;

    abstract protected function createMockProvider(string $sseFixturePath): LlmProvider;

    protected function fixturesDir(): string
    {
        return dirname(__DIR__).'/fixtures/provider-matrix';
    }

    /** @return array{messages: array, tools: array|null, expected: array} */
    protected function loadFixture(string $name): array
    {
        return json_decode((string) file_get_contents($this->fixturesDir().'/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function ssePath(string $fixtureName): string
    {
        return $this->fixturesDir().'/sse/'.$this->providerName().'-'.$fixtureName.'.sse';
    }

    /** @return StreamEvent[] */
    protected function runFixture(string $fixtureName): array
    {
        $fixture = $this->loadFixture($fixtureName);
        $provider = $this->createMockProvider($this->ssePath($fixtureName));

        return iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: $fixture['messages'],
            tools: $fixture['tools'] ?? [],
        ), false);
    }

    /** @param StreamEvent[] $events */
    protected function extractText(array $events): string
    {
        $text = '';
        foreach ($events as $event) {
            if ($event->type === 'content_block_delta' && ($event->data['delta']['type'] ?? '') === 'text_delta') {
                $text .= $event->data['delta']['text'] ?? '';
            }
        }

        return $text;
    }

    /** @param StreamEvent[] $events */
    protected function extractThinking(array $events): string
    {
        $thinking = '';
        foreach ($events as $event) {
            if ($event->type === 'content_block_delta'
                && ($event->data['delta']['type'] ?? '') === 'thinking_delta') {
                $thinking .= $event->data['delta']['thinking'] ?? '';
            }
        }

        return $thinking;
    }

    /**
     * @param  StreamEvent[]  $events
     * @return array{stop_reason: string, input_tokens: int, output_tokens: int}
     */
    protected function extractUsage(array $events): array
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'stop_reason' => ''];
        foreach ($events as $event) {
            if ($event->type === 'message_start') {
                $usage['input_tokens'] = (int) ($event->data['message']['usage']['input_tokens'] ?? 0);
            }
            if ($event->type === 'message_delta') {
                $usage['stop_reason'] = $event->data['delta']['stop_reason'] ?? '';
                $u = $event->data['usage'] ?? [];
                $usage['output_tokens'] = (int) ($u['output_tokens'] ?? 0);
                if (isset($u['input_tokens'])) {
                    $usage['input_tokens'] = (int) $u['input_tokens'];
                }
            }
        }

        return $usage;
    }

    /**
     * @param  StreamEvent[]  $events
     * @return array<int, array{name: string, id: string}>
     */
    protected function extractToolUses(array $events): array
    {
        $tools = [];
        foreach ($events as $event) {
            if ($event->type === 'content_block_start' && ($event->data['content_block']['type'] ?? '') === 'tool_use') {
                $tools[] = ['name' => $event->data['content_block']['name'] ?? '', 'id' => $event->data['content_block']['id'] ?? ''];
            }
        }

        return $tools;
    }

    protected static function buildMockClient(string $sseFixturePath): MockHttpClient
    {
        return new MockHttpClient([new MockResponse([(string) file_get_contents($sseFixturePath)], ['http_code' => 200])]);
    }

    /** @param string[] $ssePaths */
    protected static function buildMockClientMulti(array $ssePaths): MockHttpClient
    {
        $responses = array_map(
            fn (string $path) => new MockResponse([(string) file_get_contents($path)], ['http_code' => 200]),
            $ssePaths,
        );

        return new MockHttpClient($responses);
    }

    abstract protected function createMockProviderMulti(MockHttpClient $client): LlmProvider;

    public function test_multi_turn_tool_cycle(): void
    {
        $fixture = $this->loadFixture('multi-turn-tool');
        $name = $this->providerName();
        $dir = $this->fixturesDir().'/sse';

        $client = self::buildMockClientMulti([
            "{$dir}/{$name}-multi-turn-tool-turn1.sse",
            "{$dir}/{$name}-multi-turn-tool-turn2.sse",
        ]);
        $provider = $this->createMockProviderMulti($client);

        // Turn 1: user asks, model calls tool
        $turn1Events = iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: $fixture['messages'],
            tools: $fixture['tools'],
        ), false);

        $tools = $this->extractToolUses($turn1Events);
        $this->assertCount((int) $fixture['expected']['turn1']['tool_use_count'], $tools);
        $this->assertSame($fixture['expected']['turn1']['tool_name'], $tools[0]['name']);

        // Turn 2: append tool result and get final answer
        $turn2Messages = array_merge($fixture['messages'], $fixture['turn2_messages_append']);
        $turn2Events = iterator_to_array($provider->streamMessages(
            systemPrompt: [],
            messages: $turn2Messages,
            tools: $fixture['tools'],
        ), false);

        $text = $this->extractText($turn2Events);
        $this->assertStringContainsString($fixture['expected']['turn2']['text_contains'], $text);

        $usage2 = $this->extractUsage($turn2Events);
        $this->assertSame($fixture['expected']['turn2']['stop_reason'], $usage2['stop_reason']);
    }

    public function test_stream_text_only(): void
    {
        $fixture = $this->loadFixture('text-only');
        $events = $this->runFixture('text-only');
        $text = $this->extractText($events);

        $this->assertStringContainsString($fixture['expected']['text_contains'], $text);

        $usage = $this->extractUsage($events);
        foreach ($fixture['expected']['usage_shape'] as $key) {
            $this->assertArrayHasKey($key, $usage);
        }
    }

    public function test_stream_tool_call(): void
    {
        $fixture = $this->loadFixture('tool-call');
        $events = $this->runFixture('tool-call');
        $tools = $this->extractToolUses($events);

        $this->assertCount((int) ($fixture['expected']['tool_use_count'] ?? 1), $tools);
        $this->assertSame($fixture['expected']['tool_name'], $tools[0]['name']);
    }

    public function test_stream_structured_output_text(): void
    {
        $fixture = $this->loadFixture('structured-output');
        $text = $this->extractText($this->runFixture('structured-output'));
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($fixture['expected']['json'], $decoded);
    }

    public function test_stream_thinking(): void
    {
        $fixture = $this->loadFixture('thinking');
        $events = $this->runFixture('thinking');

        $this->assertStringContainsString(
            $fixture['expected']['thinking_contains'],
            $this->extractThinking($events),
        );
        $this->assertStringContainsString(
            $fixture['expected']['text_contains'],
            $this->extractText($events),
        );
    }

    public function test_cost_usage_non_zero(): void
    {
        $usage = $this->extractUsage($this->runFixture('text-only'));

        $this->assertGreaterThan(0, $usage['input_tokens']);
        $this->assertGreaterThan(0, $usage['output_tokens']);
    }

    public function test_abort_interrupts(): void
    {
        $fixture = $this->loadFixture('text-only');
        $provider = $this->createMockProvider($this->ssePath('text-only'));
        $completeEventCount = count($this->runFixture('text-only'));
        $yielded = 0;

        foreach ($provider->streamMessages(
            systemPrompt: [],
            messages: $fixture['messages'],
            tools: [],
            shouldAbort: function () use (&$yielded): bool {
                return $yielded >= 1;
            },
        ) as $event) {
            $yielded++;
        }

        $this->assertGreaterThan(0, $yielded);
        $this->assertLessThan($completeEventCount, $yielded);
    }
}
