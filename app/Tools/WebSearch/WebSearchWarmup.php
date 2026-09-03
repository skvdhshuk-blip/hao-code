<?php

declare(strict_types=1);

namespace HaoCode\Tools\WebSearch;

use HaoCode\Tools\ToolUseContext;
use HaoCode\Tools\WebSearch\Engine\EngineInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** @internal */
final class WebSearchWarmup
{
    /** @var \WeakMap<HttpClientInterface, array{attempted: array<string, true>, cookies: array<string, array{origin: string, value: string}>}>|null */
    private static ?\WeakMap $clientStates = null;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly int $timeoutMs,
    ) {}

    /**
     * @param list<EngineInterface> $engines
     * @param \Closure(array<string, string>, float): array<string, mixed> $options
     */
    public function run(
        array $engines,
        ToolUseContext $context,
        float $overallDeadline,
        \Closure $options,
    ): bool {
        $state = $this->state();
        $pending = [];
        foreach ($engines as $engine) {
            $url = $engine->warmupUrl();
            if ($url === null || isset($state['attempted'][$engine->id()])) {
                continue;
            }
            $state['attempted'][$engine->id()] = true;
            if ($context->isAborted()) {
                $this->save($state);

                return false;
            }
            try {
                $remaining = max(0.001, min(
                    $engine->timeoutMs() / 1000,
                    $this->timeoutMs / 1000,
                    $overallDeadline - self::now(),
                ));
                $response = $this->client->request('GET', $url, $options([], $remaining));
                $pending[spl_object_id($response)] = [$response, $engine, $url];
            } catch (\Throwable) {
                // Warmup is best effort and never suppresses the real search.
            }
        }
        $this->save($state);
        if ($pending === []) {
            return true;
        }

        $deadline = min($overallDeadline, self::now() + ($this->timeoutMs / 1000));
        try {
            while ($pending !== [] && self::now() < $deadline) {
                $responses = array_map(static fn (array $item): ResponseInterface => $item[0], $pending);
                $timeout = max(0.001, min(0.05, $deadline - self::now()));
                foreach ($this->client->stream($responses, $timeout) as $response => $chunk) {
                    $id = spl_object_id($response);
                    $failed = false;
                    try {
                        $isTimeout = $chunk->isTimeout();
                    } catch (\Throwable) {
                        $failed = true;
                        $isTimeout = false;
                    }
                    if ($context->isAborted()) {
                        $this->cancel($pending);

                        return false;
                    }
                    if (self::now() >= $deadline) {
                        break 2;
                    }
                    if ($failed) {
                        $response->cancel();
                        unset($pending[$id]);

                        continue;
                    }
                    if (! isset($pending[$id]) || $isTimeout) {
                        continue;
                    }
                    if ($chunk->isFirst()) {
                        [, $engine, $url] = $pending[$id];
                        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                            $this->rememberCookie($engine, $url, $response);
                        }
                        $response->cancel();
                        unset($pending[$id]);
                    }
                }
            }
        } catch (\Throwable) {
            // Warmup is best effort.
        }
        $this->cancel($pending);

        return ! $context->isAborted();
    }

    public function cookieFor(EngineInterface $engine, string $url): ?string
    {
        $cookie = $this->state()['cookies'][$engine->id()] ?? null;

        return $cookie !== null && $cookie['origin'] === self::origin($url) ? $cookie['value'] : null;
    }

    private function rememberCookie(
        EngineInterface $engine,
        string $warmupUrl,
        ResponseInterface $response,
    ): void {
        $effectiveUrl = $response->getInfo('url');
        if (! is_string($effectiveUrl) || self::origin($effectiveUrl) !== self::origin($warmupUrl)) {
            return;
        }

        $cookies = [];
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $header) {
            $pair = trim(explode(';', $header, 2)[0]);
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+=([^\x00-\x1F\x7F]*)$/', $pair) === 1) {
                $cookies[] = $pair;
            }
        }
        if ($cookies === []) {
            return;
        }

        $state = $this->state();
        $state['cookies'][$engine->id()] = [
            'origin' => self::origin($warmupUrl),
            'value' => implode('; ', $cookies),
        ];
        $this->save($state);
    }

    /** @return array{attempted: array<string, true>, cookies: array<string, array{origin: string, value: string}>} */
    private function state(): array
    {
        self::$clientStates ??= new \WeakMap;

        return self::$clientStates[$this->client] ?? ['attempted' => [], 'cookies' => []];
    }

    /** @param array{attempted: array<string, true>, cookies: array<string, array{origin: string, value: string}>} $state */
    private function save(array $state): void
    {
        self::$clientStates ??= new \WeakMap;
        self::$clientStates[$this->client] = $state;
    }

    /** @param array<int, array{ResponseInterface, EngineInterface, string}> $pending */
    private function cancel(array $pending): void
    {
        foreach ($pending as [$response]) {
            $response->cancel();
        }
    }

    private static function origin(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
