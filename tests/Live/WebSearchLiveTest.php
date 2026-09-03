<?php

declare(strict_types=1);

namespace Tests\Live;

use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\WebSearch\Engine\EngineRegistry;
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

    public function test_all_default_engines_return_live_results(): void
    {
        $result = (new WebSearchTool(EngineRegistry::createDefault()))->call(
            ['query' => 'PHP programming language official documentation'],
            new ToolUseContext(sys_get_temp_dir(), 'web-search-live'),
        );

        $this->assertFalse($result->isError, $result->output);
        $this->assertSame(
            ['bing', 'duckduckgo', 'sogou', '360', 'yahoo'],
            $result->data['selected_engines'],
        );
        $this->assertNotEmpty($result->data['results']);
        $this->assertSame(
            array_fill(0, 5, 'success_with_results'),
            array_column($result->data['stats'], 'status'),
            json_encode($result->data['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        fwrite(STDOUT, "\nWebSearch live stats: ".json_encode(
            array_map(
                static fn (array $stat): array => [
                    'engine' => $stat['engine'],
                    'count' => $stat['count'],
                    'elapsed_ms' => $stat['elapsed_ms'],
                ],
                $result->data['stats'],
            ),
            JSON_UNESCAPED_SLASHES,
        )."\n");
    }
}
