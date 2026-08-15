<?php

namespace HaoCode\Sdk;

use HaoCode\Services\Agent\ContextPreset;
use HaoCode\Services\Permissions\PermissionMode;

trait HaoCodeConfigConstructConcern
{

    public function __construct(
        /**
         * Anthropic API key. Falls back to config('haocode.api_key').
         *
         * @api
         */
        public readonly ?string $apiKey = null,
        /**
         * Model identifier (e.g., 'claude-sonnet-4-6').
         *
         * @api
         */
        public readonly ?string $model = null,
        /**
         * API base URL (for custom endpoints / proxies).
         *
         * @api
         */
        public readonly ?string $baseUrl = null,
        /**
         * Provider wire format: 'anthropic' (default), 'openai' (Responses
         * API), or 'openai_chat' (Chat Completions — use for aihubmix,
         * DeepSeek, vLLM, and other OpenAI-compatible gateways). OpenAI
         * providers require an explicit model or one configured on the
         * selected provider entry. Unknown non-empty values throw at
         * construction time (fail closed — never silently map to Anthropic).
         *
         * @api
         */
        ?string $providerType = null,
        /**
         * Maximum output tokens per response.
         *
         * @api
         */
        public readonly ?int $maxTokens = null,
        /**
         * Working directory for tool execution. Defaults to getcwd().
         *
         * @api
         */
        public readonly ?string $cwd = null,
        /**
         * Maximum agent turns (tool-use round trips). Default: 50.
         *
         * @api
         */
        public readonly int $maxTurns = 50,
        /**
         * Shared post-response spending guard in USD. Checked before each
         * model request and again after usage is recorded. Supported only
         * when the selected provider/model has trusted built-in pricing.
         * null = no limit. Concurrent or in-flight requests may still push
         * the total slightly past the limit before the next pre-request check.
         *
         * @api
         */
        public readonly ?float $maxBudgetUsd = null,
        /**
         * Permission mode: 'default', 'plan', 'accept_edits', 'bypass_permissions'.
         * Default: 'default'. Use 'bypass_permissions' only when the caller
         * explicitly accepts responsibility for tool execution.
         *
         * @api
         */
        public readonly string $permissionMode = 'default',
        /**
         * Tools to allow. No tools are exposed by default. Use tool names like
         * ['Bash', 'Read', 'Write'], or ['*'] to explicitly allow all tools.
         *
         * @api
         *
         * @var string[]
         */
        public readonly array $allowedTools = [],
        /**
         * Tools to deny. Takes precedence over allowedTools.
         *
         * @api
         *
         * @var string[]
         */
        public readonly array $disallowedTools = [],
        /**
         * Custom system prompt. null = use default.
         *
         * @api
         */
        public readonly ?string $systemPrompt = null,
        /**
         * Text appended to the default system prompt.
         *
         * @api
         */
        public readonly ?string $appendSystemPrompt = null,
        /**
         * Enable extended thinking.
         *
         * @api
         */
        public readonly bool $thinkingEnabled = false,
        /**
         * Thinking token budget when thinking is enabled.
         *
         * @api
         */
        public readonly int $thinkingBudget = 10000,
        /**
         * Streaming text callback: fn(string $delta): void
         * Called for each text chunk as it arrives from the model.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onText = null,
        /**
         * Streaming thinking callback: fn(string $delta): void
         * Called for each reasoning/thinking chunk (Anthropic extended-thinking,
         * DeepSeek-R1 `reasoning_content`, qwen reasoning, etc.). For most
         * reasoning models the final answer arrives via `onText`, but some
         * proxies/models emit the answer inside `reasoning_content` and never
         * produce a visible `content` block — consumers that need to capture
         * everything should hook this in addition to {@see $onText}.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onThinking = null,
        /**
         * Tool start callback: fn(string $toolName, array $input): void
         * Called when a tool begins execution.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onToolStart = null,
        /**
         * Tool complete callback: fn(string $toolName, ToolResult $result): void
         * Called when a tool finishes execution.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onToolComplete = null,
        /**
         * Turn start callback: fn(int $turnNumber): void
         * Called at the start of each agent turn.
         *
         * @api
         *
         * @var callable|null
         */
        public readonly mixed $onTurnStart = null,
        /**
         * Disable session and tool-result persistence for this run. Enabled by
         * default; durable conversations and human-in-the-loop flows must set
         * this to false explicitly.
         *
         * @api
         */
        public readonly bool $ephemeral = true,
        /**
         * Custom tools to register (instances of SdkTool).
         *
         * @api
         *
         * @var SdkTool[]
         */
        public readonly array $tools = [],
        /**
         * Custom skills to register (instances of SdkSkill).
         * Skills are named prompt templates the agent can invoke.
         *
         * @api
         *
         * @var SdkSkill[]
         */
        public readonly array $skills = [],
        /**
         * AbortController for cancellation from external code.
         *
         * @api
         */
        public readonly ?AbortController $abortController = null,
        /**
         * Session ID to resume a previous conversation.
         *
         * @api
         */
        public readonly ?string $sessionId = null,
        /**
         * Continue the most recent session in the working directory.
         *
         * @api
         */
        public readonly bool $continueSession = false,
        /**
         * JSON schema for structured output (used with HaoCode::structured()).
         *
         * @api
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $responseSchema = null,
        /**
         * Optional credential pool for multi-key rotation. When set, the SDK
         * wraps the resolved LlmProvider with a PooledProvider decorator.
         *
         * Single-key users can leave this null — all existing behaviour is
         * unchanged (BC preserved).
         *
         * @api
         */
        public readonly ?\HaoCode\Sdk\CredentialPool $credentialPool = null,
        /**
         * Optional sandbox runtime. When set, file/search tools operate inside
         * the sandbox instead of directly against the PHP host cwd.
         *
         * @api
         */
        public readonly ?\HaoCode\Sdk\Sandbox\SandboxConfig $sandbox = null,
        /**
         * Memory summary level for system prompt injection.
         *
         * - 'l0': Compact one-liners (~50 tokens each) — default.
         * - 'l1': Structured overviews (~500 tokens each).
         * - 'l2': Full memory content.
         *
         * Add MemoryRead to allowedTools when the agent should fetch more detail.
         *
         * @api
         */
        public readonly string $memorySummaryLevel = 'l0',
        /**
         * Custom storage path for JsonMemoryStore. When set, memory is persisted
         * to this file instead of the default ~/.haocode/memory.json.
         *
         * Useful for SDK consumers that want isolated memory stores (e.g.,
         * per-project or per-use-case memory files).
         *
         * @api
         */
        public readonly ?string $memoryStoragePath = null,
        /**
         * Additional directories containing <name>/SKILL.md packages.
         * Directories are loaded only when explicitly configured.
         *
         * @api
         *
         * @var string[]
         */
        public readonly array $skillDirectories = [],
        /**
         * Recursively discover nested SKILL.md packages in all skill sources.
         * Disabled by default; shallow packages win same-name collisions.
         *
         * @api
         */
        public readonly bool $recursiveSkillDiscovery = false,
        /**
         * Tools that pause before execution for a human decision.
         * Values may be true, false, or an array with allowedDecisions and description.
         *
         * @api
         */
        public readonly array $interruptOn = [],
        /**
         * Register AskUserQuestion as an SDK interrupt tool.
         *
         * @api
         */
        public readonly bool $enableAskUser = false,
        /**
         * Custom long-term memory store. Takes precedence over memoryStoragePath.
         *
         * @api
         */
        public readonly ?\HaoCode\Sdk\Memory\MemoryStoreInterface $memoryStore = null,
        /**
         * Human-in-the-loop approval mode: 'ask', 'smart', or 'auto'.
         * null inherits global defaults. Non-null unknown values throw.
         *
         * @api
         */
        ?string $hitlMode = null,
        /**
         * Model used to review gray-area actions in 'smart' HITL mode.
         * null reuses the current run's model.
         *
         * @api
         */
        ?string $hitlReviewModel = null,
        /**
         * Path to a JSON file with user-saved "always allow" Bash rules.
         * Empty values normalize to null.
         *
         * @api
         */
        ?string $hitlAllowlistPath = null,
        /**
         * Treat {@see $apiKey} as an Anthropic OAuth access token instead of
         * an API key: the SDK then sends `Authorization: Bearer <token>` plus
         * the `oauth-2025-04-20` anthropic-beta flag instead of the
         * `x-api-key` header. null/false keeps the default x-api-key
         * behaviour. Only meaningful for the 'anthropic' provider type.
         *
         * @api
         */
        public readonly ?bool $oauthBearer = null,
        /**
         * Image attachments for multimodal input (one-shot queries and streams).
         *
         * Each item can be:
         * - A local file path (e.g. '/path/to/photo.jpg')
         * - A URL string (e.g. 'https://example.com/photo.jpg')
         * - A pre-built content block array (e.g. ['type' => 'image', 'source' => [...]])
         * - A data URI (e.g. 'data:image/png;base64,iVBORw0KGgo...')
         *
         * For multi-turn conversations, pass images per-send via
         * {@see Conversation::send()} instead.
         *
         * @api
         *
         * @var string[]|array<string, mixed>[]
         */
        public readonly array $images = [],
        /**
         * Extra HTTP request headers merged into every provider request
         * (string => string map, e.g. ['Editor-Version' => 'vscode/1.96.0']).
         * Invalid entries are filtered out; see {@see $headers}.
         *
         * @api
         *
         * @var array<string, string>
         */
        array $headers = [],
        /**
         * Maximum number of times {@see HaoCode::structured()} retries the
         * model when its JSON output fails schema validation. Each retry
         * appends the validator's JSON-pointer error paths to the prompt so
         * the model can correct itself. Defaults to 1 (one retry).
         *
         * Set to 0 to fail fast and surface a StructuredResultValidationException
         * on the first violation.
         *
         * @api
         */
        public readonly int $structuredMaxRetries = 1,
        /**
         * Allow WebFetch to reach private-like networks (RFC1918, loopback,
         * link-local, and IPv6 ULA). Special-use, multicast, documentation,
         * benchmark, and reserved ranges remain blocked; use the explicit
         * CIDR allowlist for deliberate exceptions. Disabled by default.
         *
         * Enable only when you trust the agent and the URLs it will hit.
         *
         * @api
         */
        public readonly bool $webfetchAllowPrivateNetworks = false,
        /**
         * CIDR allowlist that bypasses the WebFetch SSRF guard (e.g.
         * ['127.0.0.1/32', '192.168.0.0/16']). The default is empty; entries
         * are explicit exceptions and are not augmented implicitly. Prefer
         * precise entries instead of enabling webfetchAllowPrivateNetworks
         * globally.
         *
         * @api
         *
         * @var list<string>
         */
        public readonly array $webfetchPrivateAllowList = [],
        /**
         * Hard cap on decompressed response bytes pulled into memory per
         * WebFetch request. Responses exceeding the cap are cancelled and
         * surface as an error to the agent (previously the entire body was
         * buffered, risking OOM). Defaults to 5 MiB.
         *
         * @api
         */
        public readonly int $webfetchMaxBytes = 5_242_880,
        /**
         * When true, {@see HaoCode::resume()} may run a session under a cwd
         * that differs from the session's stored working directory. Default
         * false refuses the mismatch so tools cannot silently operate on a
         * different project than the transcript history.
         *
         * @api
         */
        public readonly bool $allowCwdOverride = false,
        /**
         * Context injected around the system prompt. 'coding' preserves the
         * existing Git/project/coding context; 'generic' omits coding-only data
         * while retaining configured tools, skills, memory, and output style.
         *
         * @api
         */
        public readonly string $contextPreset = ContextPreset::CODING,
    ) {
        if (PermissionMode::tryFrom($this->permissionMode) === null) {
            throw new \InvalidArgumentException(
                "permissionMode must be 'default', 'plan', 'accept_edits', or 'bypass_permissions'; got '{$this->permissionMode}'.",
            );
        }
        ContextPreset::assertValid($this->contextPreset);
        if ($hitlMode === null) {
            $this->hitlMode = null;
        } elseif (is_string($hitlMode) && in_array($hitlMode, ['ask', 'smart', 'auto'], true)) {
            $this->hitlMode = $hitlMode;
        } else {
            throw new \InvalidArgumentException(
                "hitlMode must be 'ask', 'smart', 'auto', or null; got "
                . (is_string($hitlMode) ? "'{$hitlMode}'" : get_debug_type($hitlMode)).'.',
            );
        }
        $this->hitlReviewModel = is_string($hitlReviewModel) && trim($hitlReviewModel) !== ''
            ? $hitlReviewModel
            : null;
        $this->hitlAllowlistPath = is_string($hitlAllowlistPath) && trim($hitlAllowlistPath) !== ''
            ? $hitlAllowlistPath
            : null;
        $this->headers = \HaoCode\Services\Api\RequestHeaders::sanitize($headers);
        // Normalize + fail closed on unknown provider types before any HTTP
        // client can be constructed with mixed credentials.
        $this->providerType = \HaoCode\Services\Settings\ProviderType::normalizeExplicit($providerType);

        if (! in_array($this->memorySummaryLevel, ['l0', 'l1', 'l2'], true)) {
            throw new \InvalidArgumentException('memorySummaryLevel must be l0, l1, or l2.');
        }
        if ($this->ephemeral && ($this->interruptOn !== [] || $this->enableAskUser)) {
            throw new \InvalidArgumentException('Human-in-the-loop requires a durable session; ephemeral must be false.');
        }
        $decisionTypes = ['approve', 'edit', 'reject', 'respond'];
        foreach ($this->interruptOn as $toolName => $review) {
            if (! is_string($toolName) || trim($toolName) === '') {
                throw new \InvalidArgumentException('interruptOn keys must be non-empty exact tool names.');
            }
            if (! is_bool($review) && ! is_array($review)) {
                throw new \InvalidArgumentException("interruptOn.{$toolName} must be true, false, or a review configuration array.");
            }
            if (! is_array($review)) {
                continue;
            }
            if (isset($review['description']) && ! is_string($review['description'])) {
                throw new \InvalidArgumentException("interruptOn.{$toolName}.description must be a string.");
            }
            if (isset($review['allowedDecisions'])) {
                if (! is_array($review['allowedDecisions'])
                    || $review['allowedDecisions'] === []
                    || array_diff($review['allowedDecisions'], $decisionTypes) !== []) {
                    throw new \InvalidArgumentException(
                        "interruptOn.{$toolName}.allowedDecisions must contain approve, edit, reject, or respond.",
                    );
                }
            }
        }
    }

    /**
     * Create a minimal config for quick queries.
     *
     * @api
     */
    public static function make(?string $apiKey = null, ?string $model = null): self
    {
        return new self(apiKey: $apiKey, model: $model);
    }
}
