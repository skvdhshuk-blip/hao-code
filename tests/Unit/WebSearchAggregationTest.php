<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\WebSearch\Engine\EngineParseResult;
use HaoCode\Tools\WebSearch\Engine\RawSearchResult;
use HaoCode\Tools\WebSearch\EngineOutcome;
use HaoCode\Tools\WebSearch\EngineStat;
use HaoCode\Tools\WebSearch\WebSearchAggregator;
use HaoCode\Tools\WebSearch\WebSearchDomainPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWebSearchEngine;

final class WebSearchAggregationTest extends TestCase
{
    public function test_cross_engine_hits_merge_and_receive_the_contract_score(): void
    {
        $bing = new FakeWebSearchEngine('bing', 400);
        $ddg = new FakeWebSearchEngine('duckduckgo', 500);
        $outcomes = [
            $this->outcome($bing, [
                $this->rawResult('other', 'https://other.example/'),
                $this->rawResult('Bing title', 'http://www.example.com/page', 'Bing snippet'),
            ]),
            $this->outcome($ddg, [
                $this->rawResult('DDG title', 'https://example.com/page', 'DDG snippet'),
            ]),
        ];

        $results = (new WebSearchAggregator)->aggregate($outcomes, $this->policy());

        $this->assertSame('DDG title', $results[0]->title);
        $this->assertSame('DDG snippet', $results[0]->snippet);
        $this->assertSame('https://example.com/page', $results[0]->url);
        $this->assertSame(['duckduckgo', 'bing'], $results[0]->engines);
        $this->assertSame(['duckduckgo' => 1, 'bing' => 2], $results[0]->positions);
        $this->assertSame(3.0, $results[0]->score);
    }

    public function test_quality_priority_prevents_yahoo_breadcrumb_and_date_noise_winning(): void
    {
        $ddg = new FakeWebSearchEngine('duckduckgo', 500);
        $yahoo = new FakeWebSearchEngine('yahoo', 100);

        $results = (new WebSearchAggregator)->aggregate([
            $this->outcome($yahoo, [
                $this->rawResult(
                    'PHP https://www.php.net › releases › 8.5 Very Long Breadcrumb',
                    'https://www.php.net/releases/8.5/en.php',
                    'Aug 5, 2010 unrelated and much longer noisy summary',
                ),
            ]),
            $this->outcome($ddg, [
                $this->rawResult(
                    'PHP 8.5 Release Announcement',
                    'https://php.net/releases/8.5/en.php',
                    'Official release notes.',
                ),
            ]),
        ], $this->policy());

        $this->assertSame('PHP 8.5 Release Announcement', $results[0]->title);
        $this->assertSame('Official release notes.', $results[0]->snippet);
    }

    public function test_lower_priority_non_empty_snippet_fills_an_empty_higher_priority_field(): void
    {
        $results = (new WebSearchAggregator)->aggregate([
            $this->outcome(new FakeWebSearchEngine('duckduckgo', 500), [
                $this->rawResult('Clean title', 'https://example.com/page', ''),
            ]),
            $this->outcome(new FakeWebSearchEngine('yahoo', 100), [
                $this->rawResult('Noisy title', 'https://example.com/page', 'Available snippet'),
            ]),
        ], $this->policy());

        $this->assertSame('Clean title', $results[0]->title);
        $this->assertSame('Available snippet', $results[0]->snippet);
    }

    public function test_same_engine_duplicates_keep_only_the_earliest_position_and_fields(): void
    {
        $engine = new FakeWebSearchEngine('bing', 400);
        $results = (new WebSearchAggregator)->aggregate([
            $this->outcome($engine, [
                $this->rawResult('First', 'https://example.com/page', 'first'),
                $this->rawResult('Second longer title', 'https://example.com/page', 'second'),
            ]),
        ], $this->policy());

        $this->assertSame('First', $results[0]->title);
        $this->assertSame(['bing' => 1], $results[0]->positions);
        $this->assertSame(1.0, $results[0]->score);
    }

    public function test_query_and_fragment_remain_part_of_the_identity(): void
    {
        $engine = new FakeWebSearchEngine('bing');
        $results = (new WebSearchAggregator)->aggregate([
            $this->outcome($engine, [
                $this->rawResult('A', 'https://example.com/page?a=1#one'),
                $this->rawResult('B', 'https://example.com/page?a=2#one'),
                $this->rawResult('C', 'https://example.com/page?a=1#two'),
            ]),
        ], $this->policy());

        $this->assertCount(3, $results);
    }

    public function test_non_default_port_stays_in_identity_while_default_ports_merge(): void
    {
        $engine = new FakeWebSearchEngine('bing');
        $results = (new WebSearchAggregator)->aggregate([
            $this->outcome($engine, [
                $this->rawResult('A', 'http://www.example.com:80/path'),
                $this->rawResult('B', 'https://example.com:443/path'),
                $this->rawResult('C', 'https://example.com:8443/path'),
            ]),
        ], $this->policy());

        $this->assertCount(2, $results);
    }

    public function test_ties_are_deterministic_regardless_of_outcome_completion_order(): void
    {
        $left = $this->outcome(new FakeWebSearchEngine('left', 100), [
            $this->rawResult('Z', 'https://z.example/path'),
        ]);
        $right = $this->outcome(new FakeWebSearchEngine('right', 100), [
            $this->rawResult('A', 'https://a.example/path'),
        ]);
        $aggregator = new WebSearchAggregator;

        $forward = $aggregator->aggregate([$left, $right], $this->policy());
        $reverse = $aggregator->aggregate([$right, $left], $this->policy());

        $this->assertSame(array_column($forward, 'url'), array_column($reverse, 'url'));
        $this->assertSame(['https://a.example/path', 'https://z.example/path'], array_column($forward, 'url'));
    }

    public function test_domain_policy_uses_true_boundaries_and_blocked_wins(): void
    {
        $policy = WebSearchDomainPolicy::fromInput(
            ['*.example.com', 'https://foo.org/path'],
            ['private.example.com'],
        );

        $this->assertTrue($policy->allows('https://example.com/a'));
        $this->assertTrue($policy->allows('https://docs.example.com/a'));
        $this->assertTrue($policy->allows('https://foo.org/a'));
        $this->assertFalse($policy->allows('https://notexample.com/a'));
        $this->assertFalse($policy->allows('https://private.example.com/a'));
        $this->assertSame(
            ['example.com', 'foo.org'],
            WebSearchDomainPolicy::normalize(['*.Example.com', 'https://Foo.org/x', 12, '']),
        );
    }

    public function test_final_results_are_limited_to_eight(): void
    {
        $raw = [];
        for ($index = 1; $index <= 10; $index++) {
            $raw[] = $this->rawResult('Title '.$index, 'https://example.com/'.$index);
        }

        $results = (new WebSearchAggregator)->aggregate([
            $this->outcome(new FakeWebSearchEngine('bing'), $raw),
        ], $this->policy());

        $this->assertCount(8, $results);
    }

    /** @param list<RawSearchResult> $results */
    private function outcome(FakeWebSearchEngine $engine, array $results): EngineOutcome
    {
        return new EngineOutcome($engine, $results, new EngineStat(
            $engine->id(),
            EngineParseResult::SUCCESS_WITH_RESULTS,
            count($results),
            1,
            200,
            null,
        ));
    }

    private function rawResult(string $title, string $url, string $snippet = ''): RawSearchResult
    {
        return RawSearchResult::from($title, $url, $snippet)
            ?? throw new \RuntimeException('Invalid test result.');
    }

    private function policy(): WebSearchDomainPolicy
    {
        return WebSearchDomainPolicy::fromInput([], []);
    }
}
