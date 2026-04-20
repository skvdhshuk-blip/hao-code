<?php

namespace App\Services\Api;

use App\Services\Settings\SettingsManager;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal, non-streaming single-turn text generation helper.
 *
 * Used by background services (session title, away summary) that need a
 * one-shot completion and don't need the full streaming agent pipeline.
 * Routes to the active provider's REST endpoint (/v1/messages for
 * Anthropic, /v1/responses for OpenAI) and returns the plain assistant
 * text, or null on any failure.
 */
class SimpleCompletionClient
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly int $timeoutSeconds = 15,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {}

    /**
     * @param string $systemPrompt    Plain system instructions
     * @param string $userPrompt      Single user turn
     * @param int    $maxOutputTokens Cap on generated tokens
     * @param string $modelOverride   When non-empty, use this model instead of the provider default
     */
    public function complete(
        string $systemPrompt,
        string $userPrompt,
        int $maxOutputTokens,
        string $modelOverride = '',
    ): ?string {
        $type = $this->settings->getProviderType();
        $client = $this->httpClient ?? HttpClient::create(['timeout' => $this->timeoutSeconds]);
        $baseUrl = rtrim($this->settings->getBaseUrl(), '/');
        $apiKey = $this->settings->getApiKey();
        $model = $modelOverride !== '' ? $modelOverride : $this->settings->getModel();

        try {
            return match ($type) {
                'openai' => $this->completeOpenAi($client, $baseUrl, $apiKey, $model, $systemPrompt, $userPrompt, $maxOutputTokens),
                'openai_chat' => $this->completeOpenAiChat($client, $baseUrl, $apiKey, $model, $systemPrompt, $userPrompt, $maxOutputTokens),
                default => $this->completeAnthropic($client, $baseUrl, $apiKey, $model, $systemPrompt, $userPrompt, $maxOutputTokens),
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function completeAnthropic(
        HttpClientInterface $client,
        string $baseUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        int $maxTokens,
    ): ?string {
        $response = $client->request('POST', $baseUrl . '/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
        ]);

        $body = $response->toArray();

        foreach (($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text = trim($block['text']);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    private function completeOpenAiChat(
        HttpClientInterface $client,
        string $baseUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        int $maxTokens,
    ): ?string {
        $response = $client->request('POST', $baseUrl . '/v1/chat/completions', [
            'headers' => [
                'authorization' => 'Bearer ' . $apiKey,
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
        ]);

        $body = $response->toArray();
        $text = $body['choices'][0]['message']['content'] ?? null;

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    private function completeOpenAi(
        HttpClientInterface $client,
        string $baseUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        int $maxTokens,
    ): ?string {
        $response = $client->request('POST', $baseUrl . '/v1/responses', [
            'headers' => [
                'authorization' => 'Bearer ' . $apiKey,
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'instructions' => $systemPrompt,
                'input' => [[
                    'type' => 'message',
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $userPrompt]],
                ]],
                'max_output_tokens' => $maxTokens,
                'store' => false,
            ],
        ]);

        $body = $response->toArray();

        // Prefer the convenience field if the server provides it.
        if (is_string($body['output_text'] ?? null) && trim($body['output_text']) !== '') {
            return trim($body['output_text']);
        }

        foreach (($body['output'] ?? []) as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach (($item['content'] ?? []) as $part) {
                if (($part['type'] ?? '') === 'output_text' && is_string($part['text'] ?? null)) {
                    $text = trim($part['text']);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return null;
    }
}
