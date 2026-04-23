<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Examples;

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\SdkTool;
use HaoCode\Sdk\StructuredResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geocode a free-form city name into latitude/longitude via Open-Meteo.
 *
 * Open-Meteo's geocoding API is free and needs no key, which makes it a clean
 * choice for an SDK demo — the interesting part is the agent loop, not the
 * credentials management.
 */
final class GeocodeCityTool extends SdkTool
{
    public function __construct(private readonly ?HttpClientInterface $http = null) {}

    public function name(): string
    {
        return 'GeocodeCity';
    }

    public function description(): string
    {
        return 'Resolve a free-form city name to latitude, longitude, country, and timezone.';
    }

    public function parameters(): array
    {
        return [
            'city' => [
                'type' => 'string',
                'description' => 'City name to geocode, e.g. "Tokyo" or "Reykjavik"',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $city = trim((string) ($input['city'] ?? ''));
        if ($city === '') {
            throw new \InvalidArgumentException('city is required');
        }

        $http = $this->http ?? HttpClient::create(['timeout' => 10]);
        $response = $http->request('GET', 'https://geocoding-api.open-meteo.com/v1/search', [
            'query' => ['name' => $city, 'count' => 1, 'language' => 'en', 'format' => 'json'],
        ]);
        $body = $response->toArray(false);

        $hit = $body['results'][0] ?? null;
        if (! is_array($hit)) {
            throw new \RuntimeException("No geocoding hit for \"{$city}\".");
        }

        return json_encode([
            'name' => $hit['name'] ?? $city,
            'country' => $hit['country'] ?? null,
            'latitude' => $hit['latitude'] ?? null,
            'longitude' => $hit['longitude'] ?? null,
            'timezone' => $hit['timezone'] ?? 'auto',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

/**
 * Fetch current-conditions weather for a lat/lng pair via Open-Meteo.
 */
final class GetCurrentWeatherTool extends SdkTool
{
    public function __construct(private readonly ?HttpClientInterface $http = null) {}

    public function name(): string
    {
        return 'GetCurrentWeather';
    }

    public function description(): string
    {
        return 'Fetch current temperature, humidity, wind speed, and weather code for a latitude/longitude.';
    }

    public function parameters(): array
    {
        return [
            'latitude' => [
                'type' => 'number',
                'description' => 'Latitude in decimal degrees',
                'required' => true,
            ],
            'longitude' => [
                'type' => 'number',
                'description' => 'Longitude in decimal degrees',
                'required' => true,
            ],
            'timezone' => [
                'type' => 'string',
                'description' => 'IANA timezone (e.g. "Asia/Tokyo") or "auto"',
            ],
        ];
    }

    public function handle(array $input): string
    {
        $latitude = (float) ($input['latitude'] ?? 0);
        $longitude = (float) ($input['longitude'] ?? 0);
        $timezone = (string) ($input['timezone'] ?? 'auto');

        $http = $this->http ?? HttpClient::create(['timeout' => 10]);
        $response = $http->request('GET', 'https://api.open-meteo.com/v1/forecast', [
            'query' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'timezone' => $timezone !== '' ? $timezone : 'auto',
                'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m',
            ],
        ]);
        $body = $response->toArray(false);

        $current = $body['current'] ?? [];
        $units = $body['current_units'] ?? [];

        return json_encode([
            'observed_at' => $current['time'] ?? null,
            'temperature' => [
                'value' => $current['temperature_2m'] ?? null,
                'unit' => $units['temperature_2m'] ?? '°C',
            ],
            'feels_like' => [
                'value' => $current['apparent_temperature'] ?? null,
                'unit' => $units['apparent_temperature'] ?? '°C',
            ],
            'humidity' => [
                'value' => $current['relative_humidity_2m'] ?? null,
                'unit' => $units['relative_humidity_2m'] ?? '%',
            ],
            'wind_speed' => [
                'value' => $current['wind_speed_10m'] ?? null,
                'unit' => $units['wind_speed_10m'] ?? 'km/h',
            ],
            'weather_code' => $current['weather_code'] ?? null,
            'weather_description' => self::describeWeatherCode($current['weather_code'] ?? null),
            'timezone' => $body['timezone'] ?? $timezone,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * WMO weather interpretation codes (a small subset good enough for a demo).
     */
    private static function describeWeatherCode(mixed $code): string
    {
        $codes = [
            0 => 'Clear sky',
            1 => 'Mainly clear',
            2 => 'Partly cloudy',
            3 => 'Overcast',
            45 => 'Fog',
            48 => 'Depositing rime fog',
            51 => 'Light drizzle',
            53 => 'Moderate drizzle',
            55 => 'Dense drizzle',
            61 => 'Light rain',
            63 => 'Moderate rain',
            65 => 'Heavy rain',
            71 => 'Light snow',
            73 => 'Moderate snow',
            75 => 'Heavy snow',
            80 => 'Rain showers',
            81 => 'Heavy rain showers',
            82 => 'Violent rain showers',
            95 => 'Thunderstorm',
            96 => 'Thunderstorm with slight hail',
            99 => 'Thunderstorm with heavy hail',
        ];

        if (! is_int($code) && ! (is_numeric($code))) {
            return 'Unknown';
        }

        return $codes[(int) $code] ?? 'Unknown';
    }
}

/**
 * Weather agent SDK demo.
 *
 * Exercises three HaoCode SDK entry points against whatever provider the
 * caller configures (Anthropic, OpenAI Responses, or OpenAI Chat Completions
 * via aihubmix / DeepSeek / any OpenAI-compatible gateway):
 *
 *   1. {@see HaoCode::query()}   — one-shot, returns full text + cost
 *   2. {@see HaoCode::stream()}  — streamed narration with callbacks
 *   3. {@see HaoCode::structured()} — JSON-schema constrained output
 *
 * All tool calls hit Open-Meteo (free, no API key) so the demo is reproducible
 * without any third-party weather credentials.
 */
final class WeatherAgent
{
    /** @var callable(string): void */
    private $writer;

    /** @var list<SdkTool> */
    private readonly array $tools;

    public function __construct(
        private readonly string $workspaceDir,
        private readonly HaoCodeConfig $baseConfig,
        ?callable $writer = null,
    ) {
        $this->writer = $writer ?? static fn (string $chunk): int => print $chunk;
        $this->tools = [
            new GeocodeCityTool,
            new GetCurrentWeatherTool,
        ];

        if (! is_dir($this->workspaceDir)) {
            mkdir($this->workspaceDir, 0755, true);
        }
    }

    /**
     * @return array{query: QueryResult, stream_events: list<string>, forecast: StructuredResult}
     */
    public function run(): array
    {
        $this->line('=== Weather Agent ===');
        $this->line("Workspace: {$this->workspaceDir}");
        $this->line('Provider: '.($this->baseConfig->providerType ?? 'anthropic').' · model: '.($this->baseConfig->model ?? 'settings.json default'));

        $query = $this->runOneShotQuery();
        $streamEvents = $this->runStreamingAdvisory();
        $forecast = $this->runStructuredForecast();

        $this->line('');
        $this->line('--- Summary ---');
        $this->line('One-shot cost:        $'.number_format($query->cost, 5));
        $this->line('Streaming events:     '.count($streamEvents).' (types: '.implode(', ', array_unique($streamEvents)).')');
        $this->line('Structured forecast:  '.$forecast->toJson());

        return [
            'query' => $query,
            'stream_events' => $streamEvents,
            'forecast' => $forecast,
        ];
    }

    private function runOneShotQuery(): QueryResult
    {
        $this->section('1. One-shot query');

        $result = HaoCode::query(
            'What is the weather in Tokyo right now? Use GeocodeCity then GetCurrentWeather, and answer in one concise sentence with the temperature and conditions.',
            $this->makeConfig(),
        );

        $this->line('Answer: '.trim($result->text));
        $this->line(sprintf(
            'Usage:  in=%d out=%d, cost=$%.5f',
            $result->inputTokens(),
            $result->outputTokens(),
            $result->cost,
        ));

        return $result;
    }

    /**
     * @return list<string>
     */
    private function runStreamingAdvisory(): array
    {
        $this->section('2. Streaming advisory');

        $events = [];
        foreach (HaoCode::stream(
            'Use GeocodeCity and GetCurrentWeather for Reykjavik, then stream a short, friendly travel-advisory paragraph (two or three sentences) covering temperature, wind, and what to wear.',
            $this->makeConfig(),
        ) as $message) {
            $events[] = $message->type;

            match ($message->type) {
                'text' => $this->write($message->text),
                'tool_start' => $this->line("\n[tool] → {$message->toolName}(".json_encode($message->toolInput, JSON_UNESCAPED_SLASHES).')'),
                'tool_result' => $this->line("[tool] ← {$message->toolName}"),
                'result' => $this->line("\n[done] cost=\$".number_format((float) $message->cost, 5)),
                'error' => $this->line("[error] {$message->error}"),
                default => null,
            };
        }
        $this->write("\n");

        return $events;
    }

    private function runStructuredForecast(): StructuredResult
    {
        $this->section('3. Structured forecast (JSON schema)');

        $schema = [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
                'country' => ['type' => 'string'],
                'temperature_celsius' => ['type' => 'number'],
                'conditions' => ['type' => 'string'],
                'wind_kmh' => ['type' => 'number'],
                'advice' => ['type' => 'string', 'description' => 'One short sentence for a traveller arriving today.'],
            ],
            'required' => ['city', 'country', 'temperature_celsius', 'conditions', 'advice'],
        ];

        $result = HaoCode::structured(
            'Fetch the current weather for Singapore using GeocodeCity and GetCurrentWeather, then produce a compact forecast card.',
            $schema,
            $this->makeConfig(),
        );

        $this->line('City:   '.$result->city);
        $this->line('Cond:   '.$result->conditions);
        $this->line('Temp:   '.$result->temperature_celsius.'°C');
        $this->line('Advice: '.$result->advice);

        return $result;
    }

    private function makeConfig(): HaoCodeConfig
    {
        return new HaoCodeConfig(
            apiKey: $this->baseConfig->apiKey,
            model: $this->baseConfig->model,
            baseUrl: $this->baseConfig->baseUrl,
            providerType: $this->baseConfig->providerType,
            maxTokens: $this->baseConfig->maxTokens,
            cwd: $this->workspaceDir,
            maxTurns: 6,
            maxBudgetUsd: 0.50,
            permissionMode: 'bypass_permissions',
            allowedTools: [
                'GeocodeCity',
                'GetCurrentWeather',
            ],
            disallowedTools: ['Bash', 'Write', 'Edit'],
            tools: $this->tools,
        );
    }

    private function section(string $title): void
    {
        $this->line("\n--- {$title} ---");
    }

    private function line(string $text): void
    {
        $this->write($text."\n");
    }

    private function write(string $text): void
    {
        ($this->writer)($text);
    }
}
