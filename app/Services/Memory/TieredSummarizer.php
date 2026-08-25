<?php

namespace HaoCode\Services\Memory;

use HaoCode\Services\Settings\SettingsManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generate L0/L1/L2 tiered summaries for memory entries.
 *
 * Auto-fallback mode: LLM-generated summaries when API is available,
 * rule-based truncation otherwise. Supports both Anthropic Messages API
 * and OpenAI Chat Completions wire formats.
 */
class TieredSummarizer
{
    private const L0_MAX_TOKENS = 50;
    private const L1_MAX_TOKENS = 500;

    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?SettingsManager $settings = null,
    ) {}

    /**
     * Generate L0/L1 summaries for content.
     *
     * @return array{l0: string, l1: string, l0_tokens: int, l1_tokens: int, mode: string}
     */
    public function summarize(string $content): array
    {
        if ($content === '') {
            return [
                'l0' => '', 'l1' => '',
                'l0_tokens' => 0, 'l1_tokens' => 0,
                'mode' => 'empty',
            ];
        }

        $llmResult = $this->tryLlmSummarize($content);

        if ($llmResult !== null) {
            return $llmResult;
        }

        return $this->fallbackSummarize($content);
    }

    /**
     * Count tokens using character-based approximation (4 chars ≈ 1 token).
     */
    public function countTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    private function tryLlmSummarize(string $content): ?array
    {
        $resolved = $this->resolveProviderConfig();
        if ($resolved === null) {
            return null;
        }

        ['apiKey' => $apiKey, 'baseUrl' => $baseUrl, 'model' => $model, 'isAnthropic' => $isAnthropic] = $resolved;

        if ($apiKey === '' || $apiKey === null) {
            return null;
        }

        $l0 = $this->callLlm(
            $apiKey, $baseUrl, $model, $isAnthropic,
            'Summarize the following into exactly ONE sentence (max 50 tokens). Be concise and factual.',
            $content,
            80,
        );

        if ($l0 === null) {
            return null;
        }

        $l1 = $this->callLlm(
            $apiKey, $baseUrl, $model, $isAnthropic,
            'Summarize the following into a structured overview with key points (max 500 tokens). Use bullet points where appropriate.',
            $content,
            600,
        );

        if ($l1 === null) {
            $l1 = $this->truncateToTokens($content, self::L1_MAX_TOKENS);
        }

        return [
            'l0' => $l0,
            'l1' => $l1,
            'l0_tokens' => $this->countTokens($l0),
            'l1_tokens' => $this->countTokens($l1),
            'mode' => 'llm',
        ];
    }

    private function fallbackSummarize(string $content): array
    {
        $l0 = $this->extractFirstSentence($content);
        $l0 = $this->truncateToTokens($l0, self::L0_MAX_TOKENS);

        $l1 = $this->truncateToTokens($content, self::L1_MAX_TOKENS);

        return [
            'l0' => $l0,
            'l1' => $l1,
            'l0_tokens' => $this->countTokens($l0),
            'l1_tokens' => $this->countTokens($l1),
            'mode' => 'fallback',
        ];
    }

    private function extractFirstSentence(string $text): string
    {
        $text = str_replace("\n", ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (preg_match('/^(.+?[.!?])\s/s', $text, $matches)) {
            return trim($matches[1]);
        }

        return mb_substr($text, 0, 200);
    }

    private function truncateToTokens(string $text, int $maxTokens): string
    {
        $maxChars = $maxTokens * 4;
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . '...';
    }

    /**
     * Call LLM API for summarization. Supports Anthropic Messages and OpenAI Chat Completions.
     */
    private function callLlm(
        string $apiKey,
        string $baseUrl,
        string $model,
        bool $isAnthropic,
        string $systemPrompt,
        string $userContent,
        int $maxTokens,
    ): ?string {
        try {
            $client = $this->httpClient ?? \Symfony\Component\HttpClient\HttpClient::create();

            if ($isAnthropic) {
                return $this->callAnthropic($client, $apiKey, $baseUrl, $model, $systemPrompt, $userContent, $maxTokens);
            }

            return $this->callOpenAiChat($client, $apiKey, $baseUrl, $model, $systemPrompt, $userContent, $maxTokens);
        } catch (\Throwable) {
            return null;
        }
    }

    private function callAnthropic(
        $client, string $apiKey, string $baseUrl, string $model,
        string $systemPrompt, string $userContent, int $maxTokens,
    ): ?string {
        $response = $client->request('POST', rtrim($baseUrl, '/') . '/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userContent],
                ],
            ],
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return $data['content'][0]['text'] ?? null;
    }

    private function callOpenAiChat(
        $client, string $apiKey, string $baseUrl, string $model,
        string $systemPrompt, string $userContent, int $maxTokens,
    ): ?string {
        $response = $client->request('POST', rtrim($baseUrl, '/') . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => 0.3,
            ],
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return $data['choices'][0]['message']['content'] ?? null;
    }

    /**
     * Resolve provider config from SettingsManager (honours active_provider),
     * falling back to env vars and SDK config.
     *
     * @return array{apiKey: string, baseUrl: string, model: string, isAnthropic: bool}|null
     */
    private function resolveProviderConfig(): ?array
    {
        if ($this->settings !== null) {
            try {
                $apiKey = $this->settings->getApiKey();
                $baseUrl = $this->settings->getBaseUrl();
                $model = $this->settings->getModel();
                $providerType = $this->settings->getProviderType();
                $isAnthropic = $providerType === 'anthropic';

                if ($apiKey !== '') {
                    // For summarization, prefer a faster/cheaper model. If the
                    // configured model is already a small one, keep it; otherwise
                    // we still use the configured model since alternative models
                    // may not be available on the user's provider.
                    return [
                        'apiKey' => $apiKey,
                        'baseUrl' => $baseUrl,
                        'model' => $model,
                        'isAnthropic' => $isAnthropic,
                    ];
                }
            } catch (\Throwable) {}
        }

        // Standalone fallback: environment only. SDK configuration must be
        // supplied through the injected immutable SettingsManager snapshot.
        $key = $_ENV['ANTHROPIC_API_KEY'] ?? $_SERVER['ANTHROPIC_API_KEY'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $baseUrl = 'https://api.anthropic.com';
        $model = 'claude-haiku-4-5-20251001';

        $isAnthropic = str_contains($baseUrl, 'anthropic');

        return [
            'apiKey' => $key,
            'baseUrl' => $baseUrl,
            'model' => $model,
            'isAnthropic' => $isAnthropic,
        ];
    }
}
