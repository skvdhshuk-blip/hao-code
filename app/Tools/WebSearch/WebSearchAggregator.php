<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use HaoCode\Tools\WebSearch\Engine\RawSearchResult;

/** @internal */
final class WebSearchAggregator
{
    /**
     * @param list<EngineOutcome> $outcomes
     * @return list<WebSearchResult>
     */
    public function aggregate(array $outcomes, WebSearchDomainPolicy $policy): array
    {
        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = [];
        foreach ($outcomes as $outcome) {
            $seen = [];
            foreach ($outcome->results as $index => $result) {
                if (! $result instanceof RawSearchResult || ! $policy->allows($result->url)) {
                    continue;
                }
                $key = self::dedupKey($result);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $position = $index + 1;

                if (! isset($buckets[$key])) {
                    $buckets[$key] = $this->newBucket($result, $outcome->engine, $position);
                    continue;
                }
                $this->merge($buckets[$key], $result, $outcome->engine, $position);
            }
        }

        $results = [];
        foreach ($buckets as $key => $bucket) {
            /** @var array<string, EngineInterface> $enginesById */
            $enginesById = $bucket['engines'];
            $engines = array_values($enginesById);
            usort($engines, static function (EngineInterface $left, EngineInterface $right): int {
                return $right->qualityPriority() <=> $left->qualityPriority()
                    ?: strcmp($left->id(), $right->id());
            });

            $combinedWeight = 1.0;
            foreach ($engines as $engine) {
                $combinedWeight *= $engine->weight();
            }
            $hitCount = count($engines);
            $score = 0.0;
            $positions = [];
            foreach ($engines as $engine) {
                $position = $bucket['positions'][$engine->id()];
                $positions[$engine->id()] = $position;
                $score += ($combinedWeight * $hitCount) / $position;
            }

            $results[] = new WebSearchResult(
                $bucket['title']['value'],
                $bucket['url']['value'],
                $bucket['snippet']['value'],
                array_map(static fn (EngineInterface $engine): string => $engine->id(), $engines),
                $positions,
                $score,
                $key,
                min($positions),
                max(array_map(static fn (EngineInterface $engine): int => $engine->qualityPriority(), $engines)),
            );
        }

        usort($results, static function (WebSearchResult $left, WebSearchResult $right): int {
            return $right->score <=> $left->score
                ?: count($right->engines) <=> count($left->engines)
                ?: $left->bestPosition <=> $right->bestPosition
                ?: $right->highestQualityPriority <=> $left->highestQualityPriority
                ?: strcmp($left->dedupKey, $right->dedupKey);
        });

        return array_slice($results, 0, 8);
    }

    /** @return array<string, mixed> */
    private function newBucket(
        RawSearchResult $result,
        EngineInterface $engine,
        int $position,
    ): array {
        $field = static fn (string $value): array => [
            'value' => $value,
            'quality' => $engine->qualityPriority(),
            'engine' => $engine->id(),
        ];

        return [
            'title' => $field($result->title),
            'snippet' => $field($result->snippet),
            'url' => $field($result->url),
            'engines' => [$engine->id() => $engine],
            'positions' => [$engine->id() => $position],
        ];
    }

    /** @param array<string, mixed> $bucket */
    private function merge(
        array &$bucket,
        RawSearchResult $result,
        EngineInterface $engine,
        int $position,
    ): void {
        if (isset($bucket['engines'][$engine->id()])) {
            return;
        }
        $bucket['engines'][$engine->id()] = $engine;
        $bucket['positions'][$engine->id()] = $position;

        if ($this->fieldWins($bucket['title'], $engine)) {
            $bucket['title'] = $this->field($result->title, $engine);
        }
        if ($result->snippet !== '' && ($bucket['snippet']['value'] === ''
            || $this->fieldWins($bucket['snippet'], $engine))) {
            $bucket['snippet'] = $this->field($result->snippet, $engine);
        }

        $candidateUrl = $this->field($result->url, $engine);
        if ($this->urlWins($candidateUrl, $bucket['url'])) {
            $bucket['url'] = $candidateUrl;
        }
    }

    /** @param array{value: string, quality: int, engine: string} $current */
    private function fieldWins(array $current, EngineInterface $candidate): bool
    {
        return $candidate->qualityPriority() > $current['quality']
            || ($candidate->qualityPriority() === $current['quality']
                && strcmp($candidate->id(), $current['engine']) < 0);
    }

    /** @return array{value: string, quality: int, engine: string} */
    private function field(string $value, EngineInterface $engine): array
    {
        return [
            'value' => $value,
            'quality' => $engine->qualityPriority(),
            'engine' => $engine->id(),
        ];
    }

    /**
     * @param array{value: string, quality: int, engine: string} $candidate
     * @param array{value: string, quality: int, engine: string} $current
     */
    private function urlWins(array $candidate, array $current): bool
    {
        $candidateHttps = str_starts_with(strtolower($candidate['value']), 'https://');
        $currentHttps = str_starts_with(strtolower($current['value']), 'https://');
        if ($candidateHttps !== $currentHttps) {
            return $candidateHttps;
        }

        return $candidate['quality'] > $current['quality']
            || ($candidate['quality'] === $current['quality']
                && strcmp($candidate['value'], $current['value']) < 0);
    }

    public static function dedupKey(RawSearchResult $result): string
    {
        $scheme = strtolower((string) parse_url($result->url, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) parse_url($result->url, PHP_URL_HOST), '.'));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $port = parse_url($result->url, PHP_URL_PORT);
        if (is_int($port) && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $host .= ':'.$port;
        }
        $path = parse_url($result->url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $query = parse_url($result->url, PHP_URL_QUERY);
        $fragment = parse_url($result->url, PHP_URL_FRAGMENT);

        return implode('|', [
            $result->template,
            $host,
            $path,
            is_string($query) ? $query : '',
            is_string($fragment) ? $fragment : '',
            $result->imgSrc ?? '',
        ]);
    }
}
