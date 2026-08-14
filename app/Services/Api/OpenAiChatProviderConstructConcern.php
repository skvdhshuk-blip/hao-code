<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

trait OpenAiChatProviderConstructConcern
{

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.openai.com',
        private int $maxTokens = 16384,
        private bool $thinkingEnabled = false,
        private int $thinkingBudget = 10000,
        ?HttpClientInterface $httpClient = null,
        private ?\HaoCode\Services\Settings\SettingsManager $settingsManager = null,
        private readonly int $idleTimeoutSeconds = 60,
        private readonly float $streamPollTimeoutSeconds = 1.0,
        ?callable $timeProvider = null,
        array $headers = [],
    ) {
        $this->useNativeStream = $httpClient === null;
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => 300,
            'max_duration' => 600,
        ]);
        $this->timeProvider = $timeProvider ?? static fn (): float => microtime(true);
        $this->headers = RequestHeaders::sanitize($headers);
    }

    public function streamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent = null,
        ?callable $shouldAbort = null,
    ): \Generator {
        $attempt = 0;

        while (true) {
            if ($shouldAbort && $shouldAbort()) {
                return;
            }

            $hasCommittedResponseState = false;

            try {
                foreach ($this->doStreamMessages($systemPrompt, $messages, $tools, $onRawEvent, $shouldAbort) as $event) {
                    $hasCommittedResponseState = $hasCommittedResponseState || $event->commitsResponseState();
                    yield $event;
                }
                return;
            } catch (\Throwable $e) {
                if ($shouldAbort && $shouldAbort()) {
                    return;
                }

                if ($hasCommittedResponseState) {
                    throw $this->normalizeTransportException($e);
                }

                $attempt++;

                if (! $this->shouldRetry($e, $attempt)) {
                    throw $this->normalizeTransportException($e);
                }

                $delay = $this->getRetryDelay($attempt, $e);
                usleep((int) ($delay * 1000000));
            }
        }
    }

    public function getLastRateLimitHeaders(): array
    {
        return $this->lastRateLimitHeaders;
    }

    /**
     * Clone this provider while retaining its configured transport.
     */
    public function withSettingsManager(\HaoCode\Services\Settings\SettingsManager $settingsManager): self
    {
        $provider = clone $this;
        $provider->settingsManager = $settingsManager;

        return $provider;
    }

    public function withApiKey(string $apiKey): self
    {
        $provider = clone $this;
        $provider->apiKey = $apiKey;
        $provider->model = $this->resolveModel();
        $provider->baseUrl = $this->resolveBaseUrl();
        $provider->maxTokens = $this->resolveMaxTokens();
        $provider->thinkingEnabled = $this->resolveThinkingEnabled();
        $provider->thinkingBudget = $this->resolveThinkingBudget();
        $provider->settingsManager = null;

        return $provider;
    }

    /**
     * Public for testing — build the Chat Completions request body from the
     * caller-facing Anthropic-shaped inputs.
     */
    public function buildPayload(array $systemPrompt, array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->resolveModel(),
            'messages' => $this->translateMessages($systemPrompt, $messages),
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'max_tokens' => $this->resolveMaxTokens(),
        ];

        if ($tools !== []) {
            $payload['tools'] = $this->translateTools($tools);
        }

        $thinkingEnabled = $this->resolveThinkingEnabled();
        if ($this->isDeepSeekV4Flash()) {
            $payload['thinking'] = ['type' => $thinkingEnabled ? 'enabled' : 'disabled'];
        }

        // DeepSeek and other reasoning-capable models honour this hint;
        // models that don't understand it simply ignore the field.
        if ($thinkingEnabled) {
            $budget = $this->resolveThinkingBudget();
            if ($this->isDeepSeekV4Flash()) {
                $payload['reasoning_effort'] = $budget >= 32000 ? 'max' : 'high';
            } else {
                $payload['reasoning_effort'] = match (true) {
                    $budget >= 16000 => 'high',
                    $budget >= 4000 => 'medium',
                    default => 'low',
                };
            }
        }

        return $payload;
    }

    /**
     * Custom request headers for this run (run-scoped settings win over the
     * constructor map).
     *
     * @return array<string, string>
     */
    private function resolveCustomHeaders(): array
    {
        return $this->settingsManager?->getHeaders() ?: $this->headers;
    }

    /**
     * Public for testing — the merged Chat Completions request headers as an
     * associative map, shared by both transports (native stream wrapper and
     * Symfony HttpClient). Custom values win same-name (case-insensitive)
     * except `Authorization`, which always stays under the auth logic.
     *
     * @return array<string, string>
     */
    public function buildRequestHeaders(): array
    {
        return RequestHeaders::mergeCustom([
            'Authorization' => 'Bearer ' . $this->resolveApiKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
        ], $this->resolveCustomHeaders());
    }

    /**
     * Public for testing — request header lines ("Name: value") for the
     * native PHP stream-wrapper transport. Adds `Connection: close` unless
     * the caller already supplied a Connection header.
     *
     * @return string[]
     */
    public function buildNativeHeaderLines(): array
    {
        $headers = $this->buildRequestHeaders();

        $hasConnection = false;
        foreach ($headers as $name => $_) {
            if (strtolower((string) $name) === 'connection') {
                $hasConnection = true;
                break;
            }
        }
        if (! $hasConnection) {
            $headers['Connection'] = 'close';
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    private function doStreamMessages(
        array $systemPrompt,
        array $messages,
        array $tools,
        ?callable $onRawEvent,
        ?callable $shouldAbort,
    ): \Generator {
        // Headers belong to this request attempt. Do not let a previous
        // response's Retry-After influence a later request that has no such
        // header.
        $this->lastRateLimitHeaders = [];
        $baseUrl = $this->resolveBaseUrl();
        $payload = $this->buildPayload($systemPrompt, $messages, $tools);

        if (! $this->useNativeStream) {
            yield from $this->doHttpClientStreamMessages(
                $baseUrl,
                $payload,
                $onRawEvent,
                $shouldAbort,
            );

            return;
        }

        $body = $this->encodePayload($payload);
        $url = rtrim($baseUrl, '/') . '/v1/chat/completions';
        $debug = getenv('HAOCODE_STREAM_DEBUG') === '1';

        // 用 PHP 原生 stream wrappers 实现 SSE 流式读取，绕开 Symfony HttpClient + Curl
        // 在某些 SSE/chunked-transfer 网关下被 16KB write-buffer 提前 close stream 的问题。
        // PHP 的 http:// wrapper 自己管 chunked decoding，对大量 SSE event 友好。
        $headers = $this->buildNativeHeaderLines();
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'protocol_version' => 1.1,
                'timeout' => $this->idleTimeoutSeconds,
                'ignore_errors' => true, // 让我们自己处理 4xx/5xx
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        if ($shouldAbort && $shouldAbort()) {
            return;
        }

        $fp = @fopen($url, 'r', false, $ctx);
        if ($fp === false) {
            throw new ApiErrorException(
                'Failed to open stream to '.EndpointRedactor::origin($url).'.',
                'transport_error',
            );
        }

        // 解析响应头拿状态码
        $meta = stream_get_meta_data($fp);
        $wrapperData = $meta['wrapper_data'] ?? [];
        $this->extractRateLimitHeadersFromWrapperData($wrapperData);
        $statusCode = $this->extractStatusCodeFromWrapperData($wrapperData);
        if ($debug) fwrite(STDERR, "[stream] opened, status={$statusCode}\n");

        if ($statusCode >= 400) {
            // Only retain a bounded prefix of provider-controlled error data.
            $errBody = stream_get_contents($fp, self::MAX_ERROR_BODY_BYTES + 1) ?: '';
            if (strlen($errBody) > self::MAX_ERROR_BODY_BYTES) {
                $errBody = substr($errBody, 0, self::MAX_ERROR_BODY_BYTES);
            }
            fclose($fp);
            $msg = $errBody !== '' ? $errBody : "HTTP {$statusCode}";
            $errorType = 'http_error';
            $decoded = json_decode($errBody, true);
            if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
                $errorType = (string) ($decoded['error']['type'] ?? $errorType);
                $msg = (string) ($decoded['error']['message'] ?? $msg);
            }
            throw new ApiErrorException($msg, $errorType, $statusCode);
        }

        $state = new OpenAiChatTranslatorState();
        $lineReader = new BoundedSseLineBuffer(self::MAX_SSE_LINE_BYTES);
        $loopStart = ($this->timeProvider)();
        $lastActivityAt = $loopStart;
        $chunkCount = 0;
        $totalBytes = 0;
        stream_set_timeout($fp, max(1, (int) $this->streamPollTimeoutSeconds));

        try {
            while (!feof($fp)) {
                if ($shouldAbort && $shouldAbort()) {
                    fclose($fp);

                    return;
                }

                $data = fread($fp, 65536);
                if ($data === false || $data === '') {
                    // 看是 EOF 还是 timeout
                    $meta = stream_get_meta_data($fp);
                    if ($meta['timed_out'] ?? false) {
                        if (($this->timeProvider)() - $lastActivityAt >= $this->idleTimeoutSeconds) {
                            fclose($fp);

                            throw new ApiErrorException(
                                "Streaming response stalled for more than {$this->idleTimeoutSeconds}s without new data. Retry the turn.",
                                'stream_timeout',
                            );
                        }
                        continue;
                    }
                    if (feof($fp)) break;
                    continue;
                }

                $chunkCount++;
                $totalBytes += strlen($data);
                $lastActivityAt = ($this->timeProvider)();
                if ($debug && ($chunkCount <= 5 || $chunkCount % 50 === 0)) {
                    $elapsed = round($lastActivityAt - $loopStart, 2);
                    fwrite(STDERR, "[stream] chunk#{$chunkCount} +" . strlen($data) . "B total={$totalBytes} t={$elapsed}s\n");
                }

                foreach ($lineReader->push($data) as $line) {
                    foreach ($this->processSseLine($line, $state, $onRawEvent) as $emitted) {
                        if ($shouldAbort && $shouldAbort()) {
                            fclose($fp);

                            return;
                        }

                        yield $emitted;
                    }
                }
            }
            if ($debug) {
                $elapsed = round(($this->timeProvider)() - $loopStart, 2);
                fwrite(STDERR, "[stream] EOF chunks={$chunkCount} bytes={$totalBytes} t={$elapsed}s\n");
            }
        } catch (\LengthException $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }

            throw new ApiErrorException(
                'Streaming SSE line exceeded the configured size limit.',
                'protocol_error',
                previous: $e,
            );
        } catch (\Throwable $e) {
            if ($debug) fwrite(STDERR, "[stream] EXCEPTION: " . get_class($e) . ": " . $e->getMessage() . "\n");
            if (is_resource($fp)) fclose($fp);
            if ($shouldAbort && $shouldAbort()) {
                return;
            }
            throw $e;
        }

        if (is_resource($fp)) fclose($fp);

        foreach ($lineReader->push('', true) as $line) {
            foreach ($this->processSseLine($line, $state, $onRawEvent) as $emitted) {
                yield $emitted;
            }
        }

        // Emit deferred message_delta/stop if the server omitted a final
        // usage-only frame (some proxies stop after [DONE]).
        foreach ($this->finalizeIfNeeded($state) as $emitted) {
            yield $emitted;
        }
    }

    /**
     * Use the injected transport for tests and host applications that provide
     * their own HttpClient. The default production path remains the native PHP
     * stream wrapper used for chunked SSE compatibility.
     */
    private function doHttpClientStreamMessages(
        string $baseUrl,
        array $payload,
        ?callable $onRawEvent,
        ?callable $shouldAbort,
    ): \Generator {
        $response = $this->httpClient->request('POST', rtrim($baseUrl, '/').'/v1/chat/completions', [
            'headers' => $this->buildRequestHeaders(),
            'body' => $this->encodePayload($payload),
            'buffer' => false,
            'http_version' => '1.1',
            'verify_peer' => true,
            'verify_host' => true,
        ]);

        if ($shouldAbort && $shouldAbort()) {
            $response->cancel();

            return;
        }

        $this->extractRateLimitHeaders($response);
        // Capture headers before decoding the error body: PooledProvider uses
        // them when a 429 is converted into a credential exhaustion decision.
        $this->throwForHttpError($response);

        $state = new OpenAiChatTranslatorState();
        $lineReader = new BoundedSseLineBuffer(self::MAX_SSE_LINE_BYTES);
        $lastActivityAt = ($this->timeProvider)();

        try {
            foreach ($this->httpClient->stream($response, $this->streamPollTimeoutSeconds) as $chunk) {
                if ($shouldAbort && $shouldAbort()) {
                    $response->cancel();

                    return;
                }

                if ($chunk->isTimeout()) {
                    if (($this->timeProvider)() - $lastActivityAt >= $this->idleTimeoutSeconds) {
                        $response->cancel();

                        throw new ApiErrorException(
                            "Streaming response stalled for more than {$this->idleTimeoutSeconds}s without new data. Retry the turn.",
                            'stream_timeout',
                        );
                    }

                    continue;
                }

                $content = $chunk->getContent();
                $lastActivityAt = ($this->timeProvider)();

                foreach ($lineReader->push($content) as $line) {
                    foreach ($this->processSseLine($line, $state, $onRawEvent) as $emitted) {
                        if ($shouldAbort && $shouldAbort()) {
                            $response->cancel();

                            return;
                        }

                        yield $emitted;
                    }
                }
            }
        } catch (\LengthException $e) {
            $response->cancel();

            throw new ApiErrorException(
                'Streaming SSE line exceeded the configured size limit.',
                'protocol_error',
                previous: $e,
            );
        } catch (\Throwable $e) {
            if ($shouldAbort && $shouldAbort()) {
                $response->cancel();

                return;
            }

            throw $e;
        }

        foreach ($lineReader->push('', true) as $line) {
            foreach ($this->processSseLine($line, $state, $onRawEvent) as $emitted) {
                yield $emitted;
            }
        }

        foreach ($this->finalizeIfNeeded($state) as $emitted) {
            yield $emitted;
        }
    }
}
