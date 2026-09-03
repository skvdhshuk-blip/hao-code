<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\WebSearch\Engine\BingEngine;
use HaoCode\Tools\WebSearch\Engine\DuckDuckGoEngine;
use HaoCode\Tools\WebSearch\Engine\EngineHttpResponse;
use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use HaoCode\Tools\WebSearch\Engine\EngineParseResult;
use HaoCode\Tools\WebSearch\Engine\So360Engine;
use HaoCode\Tools\WebSearch\Engine\SogouEngine;
use HaoCode\Tools\WebSearch\Engine\YahooEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebSearchEngineTest extends TestCase
{
    /** @return iterable<string, array{EngineInterface, string, string, string, string}> */
    public static function normalPages(): iterable
    {
        yield 'duckduckgo redirect' => [
            new DuckDuckGoEngine,
            '<div class="result web-result"><h2 class="result__title">'
            .'<a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fddg">DDG title</a>'
            .'</h2><a class="result__snippet">DDG snippet</a></div>',
            'DDG title',
            'https://example.com/ddg',
            'DDG snippet',
        ];

        $encoded = rtrim(strtr(base64_encode('https://example.com/bing'), '+/', '-_'), '=');
        yield 'bing redirect' => [
            new BingEngine,
            '<ol id="b_results"><li class="b_algo"><h2>'
            .'<a href="https://www.bing.com/ck/a?x=1&amp;u=a1'.$encoded.'">Bing title</a>'
            .'</h2><p>Bing snippet</p></li></ol>',
            'Bing title',
            'https://example.com/bing',
            'Bing snippet',
        ];

        yield 'sogou data url' => [
            new SogouEngine,
            '<div class="rb"><h3 class="pt"><a href="/link?url=abc">Sogou title</a></h3>'
            .'<i data-url="https://example.com/sogou"></i><div class="ft">Sogou snippet</div></div>',
            'Sogou title',
            'https://example.com/sogou',
            'Sogou snippet',
        ];

        yield '360 data-mdurl' => [
            new So360Engine,
            '<ul><li class="res-list"><h3 class="res-title">'
            .'<a href="/link" data-mdurl="https://example.com/360">360 title</a></h3>'
            .'<p class="res-desc">360 snippet</p></li></ul>',
            '360 title',
            'https://example.com/360',
            '360 snippet',
        ];

        yield 'yahoo redirect' => [
            new YahooEngine,
            '<div class="algo-sr"><div class="compTitle"><h3>'
            .'<a aria-label="Yahoo title" href="https://r.search.yahoo.com/RU=https%3A%2F%2Fexample.com%2Fyahoo/RK=2/RS=x">noise</a>'
            .'</h3></div><div class="compText">Yahoo snippet</div></div>',
            'Yahoo title',
            'https://example.com/yahoo',
            'Yahoo snippet',
        ];
    }

    #[DataProvider('normalPages')]
    public function test_parses_normal_engine_page(
        EngineInterface $engine,
        string $html,
        string $title,
        string $url,
        string $snippet,
    ): void {
        $parsed = $engine->parse($this->response($engine, $html));

        $this->assertSame(EngineParseResult::SUCCESS_WITH_RESULTS, $parsed->status);
        $this->assertCount(1, $parsed->results);
        $this->assertSame($title, $parsed->results[0]->title);
        $this->assertSame($url, $parsed->results[0]->url);
        $this->assertSame($snippet, $parsed->results[0]->snippet);
    }

    /** @return iterable<string, array{EngineInterface, string}> */
    public static function emptyPages(): iterable
    {
        yield 'bing' => [new BingEngine, '<li class="b_no">There are no results found.</li>'];
        yield 'duckduckgo' => [new DuckDuckGoEngine, '<div class="no-results">No results.</div>'];
        yield 'sogou' => [new SogouEngine, '<main>抱歉，没有找到相关结果</main>'];
        yield '360' => [new So360Engine, '<main>未找到相关结果</main>'];
        yield 'yahoo' => [new YahooEngine, '<main>We did not find results for this query.</main>'];
    }

    #[DataProvider('emptyPages')]
    public function test_requires_explicit_evidence_for_success_empty(EngineInterface $engine, string $html): void
    {
        $this->assertSame(
            EngineParseResult::SUCCESS_EMPTY,
            $engine->parse($this->response($engine, $html))->status,
        );
        $this->assertSame(
            EngineParseResult::PARSE_ERROR,
            $engine->parse($this->response($engine, '<main>unknown layout</main>'))->status,
        );
        $this->assertSame(
            EngineParseResult::PARSE_ERROR,
            $engine->parse($this->response($engine, ''))->status,
        );
    }

    /** @return iterable<string, array{EngineInterface}> */
    public static function engines(): iterable
    {
        yield 'bing' => [new BingEngine];
        yield 'duckduckgo' => [new DuckDuckGoEngine];
        yield 'sogou' => [new SogouEngine];
        yield '360' => [new So360Engine];
        yield 'yahoo' => [new YahooEngine];
    }

    #[DataProvider('engines')]
    public function test_challenge_pages_are_parse_errors_with_stable_code(EngineInterface $engine): void
    {
        $parsed = $engine->parse($this->response(
            $engine,
            '<form id="captcha"><div>challenge</div></form>',
        ));

        $this->assertSame(EngineParseResult::PARSE_ERROR, $parsed->status);
        $this->assertSame('challenge_page', $parsed->error);
    }

    public function test_sogou_antispider_redirect_is_a_challenge_even_with_result_markup(): void
    {
        $engine = new SogouEngine;
        $parsed = $engine->parse(new EngineHttpResponse(
            200,
            'https://www.sogou.com/antispider/?from=%2Fweb',
            [],
            '<div class="rb"><h3 class="pt"><a href="https://example.com">Result</a></h3></div>',
        ));

        $this->assertSame(EngineParseResult::PARSE_ERROR, $parsed->status);
        $this->assertSame('challenge_page', $parsed->error);
    }

    public function test_invalid_result_urls_do_not_turn_unknown_markup_into_empty_success(): void
    {
        $engine = new DuckDuckGoEngine;
        $parsed = $engine->parse($this->response(
            $engine,
            '<a class="result__a" href="javascript:alert(1)">Bad URL</a>',
        ));

        $this->assertSame(EngineParseResult::PARSE_ERROR, $parsed->status);
        $this->assertSame([], $parsed->results);
    }

    public function test_parser_caps_valid_results_at_ten_in_original_order(): void
    {
        $html = '';
        for ($index = 1; $index <= 12; $index++) {
            $html .= '<a class="result__a" href="https://example.com/'.$index.'">Title '.$index.'</a>';
        }

        $parsed = (new DuckDuckGoEngine)->parse($this->response(new DuckDuckGoEngine, $html));

        $this->assertCount(10, $parsed->results);
        $this->assertSame('https://example.com/1', $parsed->results[0]->url);
        $this->assertSame('https://example.com/10', $parsed->results[9]->url);
    }

    private function response(EngineInterface $engine, string $html): EngineHttpResponse
    {
        return new EngineHttpResponse(
            200,
            $engine->createRequest('test')->url,
            [],
            $html,
        );
    }
}
