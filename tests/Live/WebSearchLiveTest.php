<?php

declare(strict_types=1);

namespace Tests\Live;

use HaoCode\Tools\WebSearch\WebSearchTool;
use PHPUnit\Framework\TestCase;

/**
 * @group live
 */
class WebSearchLiveTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('HAOCODE_LIVE_WEBSEARCH') !== '1') {
            $this->markTestSkipped('Set HAOCODE_LIVE_WEBSEARCH=1 to run live web-search smoke tests.');
        }
    }

    public function test_duckduckgo_returns_live_results(): void
    {
        $this->assertEngineReturnsResults('searchDuckDuckGo');
    }

    public function test_bing_returns_live_results(): void
    {
        $this->assertEngineReturnsResults('searchBing');
    }

    private function assertEngineReturnsResults(string $method): void
    {
        $tool = new WebSearchTool;
        $reflection = new \ReflectionMethod($tool, $method);
        $response = $reflection->invoke($tool, 'PHP programming language official documentation');

        $this->assertSame('success_with_results', $response['status']);
        $this->assertNotEmpty($response['results']);
        $this->assertNotSame('', $response['results'][0]['title']);
        $this->assertMatchesRegularExpression('~^https?://~', $response['results'][0]['url']);
    }
}
