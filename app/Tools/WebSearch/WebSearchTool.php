<?php

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use HaoCode\Tools\WebSearch\Engine\EngineRegistry;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebSearchTool extends BaseTool
{
    private ?HttpClientInterface $client = null;

    public function __construct(
        private readonly EngineRegistry $engines,
    ) {}

    public function setClient(HttpClientInterface $client): void
    {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'WebSearch';
    }

    public function description(): string
    {
        return <<<'DESC'
Search the web and return results. Use this to find up-to-date information, documentation, or answers to questions.

Returns search results with titles, URLs, and snippets.

Usage notes:
- Always include a "Sources:" section with markdown links at the end of responses using search results
- Use specific queries for better results
- Today's date is provided in the runtime context of the first user turn. When the user asks for current, latest, or recent information without naming a date or time period, append today's date to the query (for example "php 8.5 release notes 2026-09-02") so the results are not stale. Do not add a date when the user already specified one.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'description' => "The search query. If the request is time-sensitive and the user gave no explicit date or period, append today's date from the runtime context (YYYY-MM-DD).",
                ],
                'engines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => $this->engines->availableEngineIds(),
                    ],
                    'minItems' => 1,
                    'maxItems' => count($this->engines->availableEngineIds()),
                    'uniqueItems' => true,
                    'description' => 'Optional subset of search engines to use',
                ],
                'allowed_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Only include results from these domains',
                ],
                'blocked_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Exclude results from these domains',
                ],
            ],
            'required' => ['query'],
        ]);
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        try {
            $this->resolveEngines($input);
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $query = $input['query'] ?? null;
        if (! is_string($query) || trim($query) === '') {
            return ToolResult::error('WebSearch query must be a non-empty string.');
        }

        try {
            $engines = $this->resolveEngines($input);
        } catch (\InvalidArgumentException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $batch = (new WebSearchTransport($this->client()))->search($engines, $query, $context);
        if ($batch->aborted) {
            return ToolResult::aborted();
        }

        $results = (new WebSearchAggregator)->aggregate(
            $batch->outcomes,
            WebSearchDomainPolicy::fromInput(
                $input['allowed_domains'] ?? [],
                $input['blocked_domains'] ?? [],
            ),
        );
        $data = $this->structuredData($query, $engines, $batch, $results);

        if ($results !== []) {
            return ToolResult::success($this->formatResults($query, $results), null, $data);
        }

        $hasFailure = false;
        foreach ($batch->outcomes as $outcome) {
            $hasFailure = $hasFailure || $outcome->stat->failed();
        }
        if (! $hasFailure) {
            return ToolResult::success("No search results found for: {$query}", null, $data);
        }

        $statuses = [];
        foreach ($batch->outcomes as $outcome) {
            $statuses[] = $this->displayName($outcome->engine->id()).'='.$outcome->stat->status;
        }
        $output = 'Web search failed with no usable results. Backend statuses: '
            .implode(', ', $statuses).'.';

        return ToolResult::error($output, null, $data);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }

    private function client(): HttpClientInterface
    {
        return $this->client ??= HttpClient::create();
    }

    /** @return list<EngineInterface> */
    private function resolveEngines(array $input): array
    {
        if (! array_key_exists('engines', $input)) {
            return $this->engines->resolve(null);
        }
        if (! is_array($input['engines'])) {
            throw new \InvalidArgumentException('WebSearch engines must be a non-empty list.');
        }

        return $this->engines->resolve($input['engines']);
    }

    /** @param list<WebSearchResult> $results */
    private function formatResults(string $query, array $results): string
    {
        $output = "Search results for: \"{$query}\"\n\n";
        foreach ($results as $index => $result) {
            $output .= ($index + 1).". [{$result->title}]({$result->url})\n";
            if ($result->snippet !== '') {
                $output .= "   {$result->snippet}\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * @param list<EngineInterface> $engines
     * @param list<WebSearchResult> $results
     * @return array<string, mixed>
     */
    private function structuredData(
        string $query,
        array $engines,
        WebSearchBatch $batch,
        array $results,
    ): array {
        return [
            'type' => 'web_search',
            'schema_version' => 1,
            'query' => $query,
            'selected_engines' => array_map(
                static fn (EngineInterface $engine): string => $engine->id(),
                $engines,
            ),
            'partial' => array_reduce(
                $batch->outcomes,
                static fn (bool $partial, EngineOutcome $outcome): bool => $partial || $outcome->stat->failed(),
                false,
            ),
            'results' => array_map(
                static fn (WebSearchResult $result, int $index): array => $result->toArray($index + 1),
                $results,
                array_keys($results),
            ),
            'stats' => array_map(
                static fn (EngineOutcome $outcome): array => $outcome->stat->toArray(),
                $batch->outcomes,
            ),
        ];
    }

    private function displayName(string $id): string
    {
        return match ($id) {
            'bing' => 'Bing',
            'duckduckgo' => 'DuckDuckGo',
            'sogou' => 'Sogou',
            'yahoo' => 'Yahoo',
            default => $id,
        };
    }
}
