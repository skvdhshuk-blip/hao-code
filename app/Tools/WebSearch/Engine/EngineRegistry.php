<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

/** @internal */
final class EngineRegistry
{
    /** @var array<string, EngineInterface> */
    private array $engines = [];

    /** @param list<string> $defaultEngineIds */
    public function __construct(
        private readonly array $defaultEngineIds = [],
    ) {}

    public static function createDefault(): self
    {
        $registry = new self(['bing', 'duckduckgo', 'sogou', '360', 'yahoo']);
        $registry->register(new BingEngine);
        $registry->register(new DuckDuckGoEngine);
        $registry->register(new SogouEngine);
        $registry->register(new So360Engine);
        $registry->register(new YahooEngine);

        return $registry;
    }

    /** @return list<string> */
    public function availableEngineIds(): array
    {
        return array_map(
            static fn (EngineInterface $engine): string => $engine->id(),
            array_values($this->engines),
        );
    }

    /** @return list<string> */
    public function defaultEngineIds(): array
    {
        return $this->defaultEngineIds;
    }

    public function register(EngineInterface $engine): void
    {
        $id = $engine->id();
        $warmupUrl = $engine->warmupUrl();
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) !== 1) {
            throw new \InvalidArgumentException("Invalid WebSearch engine ID: {$id}");
        }
        if (isset($this->engines[$id])) {
            throw new \InvalidArgumentException("Duplicate WebSearch engine ID: {$id}");
        }
        if (! is_finite($engine->weight()) || $engine->weight() <= 0) {
            throw new \InvalidArgumentException("Invalid weight for WebSearch engine: {$id}");
        }
        if ($engine->qualityPriority() <= 0) {
            throw new \InvalidArgumentException("Invalid quality priority for WebSearch engine: {$id}");
        }
        if ($engine->timeoutMs() < 1 || $engine->timeoutMs() > 10000) {
            throw new \InvalidArgumentException("Invalid timeout for WebSearch engine: {$id}");
        }
        if ($warmupUrl !== null && strtolower((string) parse_url($warmupUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new \InvalidArgumentException("Invalid warmup URL for WebSearch engine: {$id}");
        }

        $this->engines[$id] = $engine;
    }

    /** @return list<EngineInterface> */
    public function resolve(?array $engineIds): array
    {
        $ids = $engineIds ?? $this->defaultEngineIds;
        if ($ids === []) {
            throw new \InvalidArgumentException('WebSearch engines must be a non-empty list.');
        }

        $seen = [];
        $resolved = [];
        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                throw new \InvalidArgumentException('WebSearch engine IDs must be non-empty strings.');
            }
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException("Duplicate WebSearch engine selection: {$id}");
            }
            if (! isset($this->engines[$id])) {
                throw new \InvalidArgumentException("Unknown WebSearch engine: {$id}");
            }

            $seen[$id] = true;
            $resolved[] = $this->engines[$id];
        }

        return $resolved;
    }
}
