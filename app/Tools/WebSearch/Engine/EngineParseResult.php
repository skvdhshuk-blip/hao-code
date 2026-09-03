<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch\Engine;

/** @internal */
final class EngineParseResult
{
    public const SUCCESS_WITH_RESULTS = 'success_with_results';
    public const SUCCESS_EMPTY = 'success_empty';
    public const PARSE_ERROR = 'parse_error';

    /** @param list<RawSearchResult> $results */
    private function __construct(
        public readonly string $status,
        public readonly array $results,
        public readonly ?string $error,
    ) {}

    /** @param list<RawSearchResult> $results */
    public static function success(array $results): self
    {
        return new self(self::SUCCESS_WITH_RESULTS, array_slice($results, 0, 10), null);
    }

    public static function empty(): self
    {
        return new self(self::SUCCESS_EMPTY, [], null);
    }

    public static function error(string $code = 'unexpected_markup'): self
    {
        return new self(self::PARSE_ERROR, [], $code);
    }
}
