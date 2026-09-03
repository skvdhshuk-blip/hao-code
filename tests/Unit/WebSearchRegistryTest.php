<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Tools\WebSearch\Engine\EngineRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWebSearchEngine;

final class WebSearchRegistryTest extends TestCase
{
    public function test_default_catalog_is_a_positive_allowlist_without_google(): void
    {
        $registry = EngineRegistry::createDefault();

        $this->assertSame(
            ['bing', 'duckduckgo', 'sogou', '360', 'yahoo'],
            $registry->availableEngineIds(),
        );
        $this->assertSame(
            ['bing', 'duckduckgo', 'sogou', '360', 'yahoo'],
            $registry->defaultEngineIds(),
        );
        $this->assertNotContains('google', $registry->availableEngineIds());
        $this->assertSame(
            $registry->defaultEngineIds(),
            array_map(static fn ($engine): string => $engine->id(), $registry->resolve(null)),
        );
    }

    public function test_selection_preserves_requested_order(): void
    {
        $registry = EngineRegistry::createDefault();

        $this->assertSame(
            ['yahoo', 'bing'],
            array_map(static fn ($engine): string => $engine->id(), $registry->resolve(['yahoo', 'bing'])),
        );
    }

    /** @return iterable<string, array{FakeWebSearchEngine}> */
    public static function invalidEngines(): iterable
    {
        yield 'invalid id' => [new FakeWebSearchEngine('Bad_ID')];
        yield 'zero weight' => [new FakeWebSearchEngine('zero-weight', engineWeight: 0.0)];
        yield 'nan weight' => [new FakeWebSearchEngine('nan-weight', engineWeight: NAN)];
        yield 'infinite weight' => [new FakeWebSearchEngine('inf-weight', engineWeight: INF)];
        yield 'zero priority' => [new FakeWebSearchEngine('zero-priority', priority: 0)];
        yield 'zero timeout' => [new FakeWebSearchEngine('zero-timeout', engineTimeoutMs: 0)];
        yield 'large timeout' => [new FakeWebSearchEngine('large-timeout', engineTimeoutMs: 10001)];
        yield 'http warmup' => [new FakeWebSearchEngine('http-warmup', warmup: 'http://example.com/')];
    }

    #[DataProvider('invalidEngines')]
    public function test_registration_rejects_invalid_engine_contract(FakeWebSearchEngine $engine): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EngineRegistry)->register($engine);
    }

    public function test_registration_rejects_duplicate_id(): void
    {
        $registry = new EngineRegistry;
        $registry->register(new FakeWebSearchEngine('same'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate WebSearch engine ID: same');
        $registry->register(new FakeWebSearchEngine('same'));
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidSelections(): iterable
    {
        yield 'empty' => [[], 'non-empty list'];
        yield 'duplicate' => [['bing', 'bing'], 'Duplicate'];
        yield 'unknown' => [['google'], 'Unknown'];
        yield 'non string' => [[123], 'non-empty strings'];
    }

    #[DataProvider('invalidSelections')]
    public function test_resolve_rejects_invalid_selection(array $selection, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        EngineRegistry::createDefault()->resolve($selection);
    }
}
