<?php

namespace HaoCode\Services\Api;

use JsonException;
use HaoCode\Support\Http\BoundedResponseBodyReader;
use HaoCode\Support\Streaming\BoundedSseLineBuffer;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OpenAI Chat Completions API (/v1/chat/completions) streaming provider.
 *
 * Covers the same surface as {@see OpenAiProvider} but targets the older,
 * more widely-supported Chat Completions interface. Required for proxies
 * that haven't adopted Responses yet (aihubmix, DeepSeek, vLLM, many
 * OpenAI-compatible gateways).
 *
 * Wire differences vs. Responses:
 *   - Payload uses a flat `messages` array with roles (system/user/assistant/tool)
 *     instead of nested "input" items.
 *   - Tools are nested under `{type:'function', function:{...}}` rather than flat.
 *   - SSE frames have no `event:` line — only `data:` JSON deltas.
 *   - Tool-call streaming uses `choices[0].delta.tool_calls[]` with its own
 *     `index` namespace, which we remap onto synthesized Anthropic content
 *     block indices.
 *   - Reasoning appears as `delta.reasoning_content` (DeepSeek / some proxies).
 *   - Usage arrives on the final delta only when `stream_options.include_usage`
 *     is set; we always request it.
 */
class OpenAiChatProvider implements ApiKeyAwareProvider, SettingsAwareProvider
{
    use OpenAiChatProviderConstructConcern;
    use OpenAiChatProviderProcessSseLineConcern;
    use OpenAiChatProviderTranslateToolsConcern;

    private const MAX_SSE_LINE_BYTES = 4 * 1024 * 1024;
    private const MAX_ERROR_BODY_BYTES = 64 * 1024;

    private HttpClientInterface $httpClient;
    private bool $useNativeStream;
    private int $maxRetries = 3;
    private array $lastRateLimitHeaders = [];
    /** @var array<string, string> */
    private array $headers;
    /** @var callable(): float */
    private $timeProvider;
}

/**
 * Mutable per-turn translator state for the Chat Completions stream.
 *
 * Stream events don't carry explicit content-block indices, so we allocate
 * our own Anthropic-style indices as text / reasoning / tool_use fragments
 * appear, and remember enough state to emit matching content_block_stop
 * events at the right time.
 */
class OpenAiChatTranslatorState
{
    public bool $messageStartEmitted = false;
    public int $nextBlockIndex = 0;
    public ?int $textBlockIndex = null;
    public bool $textBlockStopped = false;
    public ?int $thinkingBlockIndex = null;
    public bool $thinkingBlockStopped = false;
    /** @var array<int, int> stream tool_call index → synthesized content_block index */
    public array $toolCallBlockIndexByStreamIndex = [];
    /** @var array<int, true> */
    public array $toolCallBlocksClosed = [];
    public bool $hasToolCall = false;
    public ?string $pendingFinishReason = null;
    public bool $pendingFinishReasonEmitted = false;
    /** Whether the provider explicitly ended its SSE stream with data: [DONE]. */
    public bool $sawDone = false;
    /** @var array<string, mixed> */
    public array $pendingUsage = [];
}
