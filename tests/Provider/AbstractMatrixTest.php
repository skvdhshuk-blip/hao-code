<?php

declare(strict_types=1);

namespace Tests\Provider;

use HaoCode\Services\Api\LlmProvider;
use HaoCode\Services\Api\StreamEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

abstract class AbstractMatrixTest extends TestCase
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
        $yielded = 0;
        $aborted = false;

        try {
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
            $aborted = true;
        } catch (\Throwable $e) {
            $aborted = true;
        }

        $this->assertTrue($aborted);
        $this->assertLessThanOrEqual(2, $yielded);
    }
}
