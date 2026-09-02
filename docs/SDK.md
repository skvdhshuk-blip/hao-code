# Hao Code PHP SDK

Use hao-code as a framework-free PHP library to embed an AI coding agent in your application.

This document describes the `v1.21.0` source line. Published package versions
are identified by Git tags and Packagist.

```bash
composer require sk-wang/hao-code
```

Set your API key in the process environment:

```bash
export ANTHROPIC_API_KEY=your-api-key
```

Hao Code does not load `.env` files by itself. If your application already
loads `.env`, you may pass the resulting value through `HaoCodeConfig`.
For `openai` and `openai_chat`, `OPENAI_API_KEY` is used when no explicit
`apiKey` or matching provider-entry key is set; it is not an Anthropic fallback.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Agent & Runner](#agent--runner)
- [Requirements](#requirements)
- [HaoCode API Reference](#haocode-api-reference)
  - [query()](#query)
  - [stream()](#stream)
  - [conversation()](#conversation)
  - [resume() / continueLatest()](#resume--continuelatest)
  - [resumeInterrupt() / streamResumeInterrupt()](#resumeinterrupt--streamresumeinterrupt)
  - [structured()](#structured)
- [HaoCodeConfig Reference](#haocodeconfig-reference)
- [Long-Term Memory](#long-term-memory)
- [Sandbox Runtime](#sandbox-runtime)
- [QueryResult](#queryresult)
- [MCP Tools](#mcp-tools)
- [Custom Tools (SdkTool)](#custom-tools-sdktool)
- [Custom Skills (SdkSkill)](#custom-skills-sdkskill)
- [Streaming Messages](#streaming-messages)
- [Plan Mode](#plan-mode)
- [Agent Teams](#agent-teams)
- [Multi-turn Conversations](#multi-turn-conversations)
- [Session Resume & Continue](#session-resume--continue)
- [Structured Output](#structured-output)
- [Multimodal Input](#multimodal-input)
- [Abort Controller](#abort-controller)
- [Cost Tracking](#cost-tracking)
- [Credential Pools](#credential-pools)
- [Combining Tools + Skills](#combining-tools--skills)
- [Testing](#testing)

---

## Quick Start

```php
<?php

require __DIR__.'/vendor/autoload.php';

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$result = HaoCode::query('Reply with exactly: HaoCode works');

echo $result->text;
```

`query()` is text-only and non-persistent by default. The same safe defaults
apply when a config object is supplied: `allowedTools: []`,
`permissionMode: 'default'`, and `ephemeral: true`. Connection settings such as
an API key do not implicitly enable tools, bypass permissions, or create a
session:

```php
$result = HaoCode::query('Reply with exactly: HaoCode works', new HaoCodeConfig(
    apiKey: getenv('ANTHROPIC_API_KEY') ?: '',
));
```

Runnable examples:

- `examples/code-review-agent.php` — compact review-focused demo
- `examples/agentrun-ml-clustering-agent.php` — AgentRun sandbox demo where an
  agent writes data, creates a pure-Python k-means script, and runs it remotely
- `examples/support-ops-agent.php` — end-to-end support-operations agent using
  query, stream, conversation, resume, continue, structured output, custom
  tools, skills, callbacks, and abort wiring

---

## Agent & Runner

`Agent` is a reusable agent definition: everything that stays the same across
runs (model, system prompt, tools, skills, permissions, sandbox, credentials,
request headers). `Runner` executes an `Agent` once with a prompt and optional
`RunOptions` (the things that change per execution: callbacks, images,
session persistence, cwd, budget, abort controller).

```php
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Runner;
use HaoCode\Sdk\RunOptions;

$agent = new Agent(
    name: 'code-reviewer',
    model: 'claude-sonnet-4',
    allowedTools: ['Read', 'Grep', 'Glob'],
    maxTurns: 50,
);

$result = Runner::run($agent, 'Review the latest commit');

foreach (Runner::stream($agent, 'Stream the review', new RunOptions(
    onText: fn (string $delta) => print($delta),
)) as $message) {
    // Message::text / toolStart / toolResult / turn / result
}
```

The default `contextPreset: 'coding'` preserves the existing coding-agent
prompt context. A tool-using domain agent can select `contextPreset: 'generic'`
to omit Git status, project instruction files, and Hao Code coding conventions
while retaining its configured tools, skills, memory, output style, provider,
and permission policy. The selection is stored in durable run snapshots so an
interrupt resume cannot silently switch presets.

`Agent` is immutable: `withTool()`, `withTools()`, `withModel()`,
`withSystemPrompt()`, `withMaxTurns()`, and `withPermissionMode()` each return
a new instance. `Agent::fromConfig()` / `Agent::toConfig()` convert between an
`Agent` and a legacy `HaoCodeConfig`, and `Agent::asTool()` exposes the agent
as a tool for another agent (see [Agent Teams](#agent-teams)).
The legacy `sessionId`, `continueSession`, and `structuredMaxRetries` Agent
properties remain accepted for source compatibility, but are facade-level
options and are not applied by `Runner`; configure them on `HaoCodeConfig` when
using `HaoCode::query()`/`structured()`.

The `HaoCode` facade delegates to these primitives: `HaoCode::query()` and
`HaoCode::stream()` build an `Agent` + `RunOptions` from the given
`HaoCodeConfig` and call `Runner`, while `Conversation` keeps its own internal
`Agent` definition and reuses the same single run-assembly path
(`SdkRunFactory::createFromAgent()`, internal). Interrupt resume
(`resumeInterrupt` / `streamResumeInterrupt`) remains a `Conversation`
capability, because resuming requires the persisted session and checkpoint
state that only a durable conversation holds.

---

## Requirements

- PHP `^8.1`
- Symfony components `^6.4`, `^7.0`, or `^8.0` installed by Composer
- Optional `pcntl` and `posix` extensions for background agents and teams
- macOS `/usr/bin/sandbox-exec` or Linux `bubblewrap` for `SandboxConfig::native()`
- `zstd` and `tar` when installing the optional Tokimo kernel/rootfs
- Linux `bubblewrap`, or KVM plus `cloud-hypervisor` and `virtiofsd`, for `SandboxConfig::tokimo()`
- Windows Virtual Machine Platform plus the downloaded SYSTEM service for `SandboxConfig::tokimo()`
- SQLite/PDO SQLite plus `pcntl` and `posix` only when embedding the optional cron daemon services

The basic SDK, local filesystem sandbox, and remote AgentRun backend do not
require the optional process or SQLite extensions.

### Optional Tokimo sandbox installation

`composer require sk-wang/hao-code` installs only the PHP SDK. It does not
download Tokimo, native runners, or guest images. Install the optional sandbox
now or later with the Composer bin command:

```bash
# Install only the native runner for the current OS/CPU:
vendor/bin/hao-code-sandbox install

# Recommended first-time setup: runner plus guest kernel/rootfs:
vendor/bin/hao-code-sandbox install --with-runtime

# Check which runner is currently available:
vendor/bin/hao-code-sandbox status
```

No separate Tokimo installation is required. On macOS and Linux,
`--with-runtime` may ask for `sudo` while extracting the rootfs to preserve its
Linux uid/gid ownership. From a hao-code source checkout, use
`bin/hao-code-sandbox` instead of `vendor/bin/hao-code-sandbox`.

Composer does not execute dependency package scripts or provide a
dependency-specific install flag. To install the SDK and complete sandbox in
one shell command, use:

```bash
composer require sk-wang/hao-code && vendor/bin/hao-code-sandbox install --with-runtime
```

---

## HaoCode API Reference

### query()

Execute a one-shot query. Returns a [`QueryResult`](#queryresult) (implements `Stringable`).

```php
HaoCode::query(string $prompt, ?HaoCodeConfig $config = null): QueryResult
```

```php
$result = HaoCode::query('Explain the auth system', new HaoCodeConfig(
    allowedTools: [],
    ephemeral: false,
));

echo $result;            // response text (Stringable)
echo $result->text;      // same as above, explicit
echo $result->cost;      // estimated cost in USD
echo $result->usage;     // ['input_tokens' => ..., 'output_tokens' => ...]
echo $result->sessionId; // durable session ID for later resume
```

Set `ephemeral: false` only when the result must include a durable session ID.
Omitting it keeps `$result->sessionId` as `null`.

### stream()

Execute a query and yield typed [`Message`](#streaming-messages) objects as they arrive.

`stream()` matches `query()`: it is text-only, ephemeral, and does not expose
tools unless those capabilities are enabled explicitly.

```php
HaoCode::stream(string $prompt, ?HaoCodeConfig $config = null): Generator<Message>
```

```php
foreach (HaoCode::stream('Explain dependency injection briefly') as $msg) {
    match ($msg->type) {
        'text'        => print($msg->text),
        'tool_start'  => print("⚙ {$msg->toolName}\n"),
        'tool_result' => print("  ✓ done\n"),
        'result'      => print("\nCost: \${$msg->cost}\n"),
        'interrupt'   => print("Approval required: {$msg->interrupt->id}\n"),
        'error'       => print("Error: {$msg->error}\n"),
        default       => null,
    };
}
```

If a caller stops consuming a stream *before* a terminal `result`, `interrupt`,
or `error` message, it must also release the `Generator` (`unset($stream)` or
let it leave scope). PHP does not notify a Generator merely because `break` was
used while another reference is retained; HaoCode rejects overlapping operations
until that release triggers the stream's abort and cleanup. Abandoning a stream
never resumes queued model or tool work. In contrast, after a one-shot
`Runner::stream()` or `HaoCode::stream()` has reached a terminal `result`,
`interrupt`, or `error`, it releases its owned run resources before that message
is yielded. Retaining the completed Generator therefore does not retain its
sandbox, MCP connections, or abort subscription. A durable `interrupt` still
detaches its sandbox lease for the next resume. A long-lived `Conversation`
releases its operation lease before yielding a terminal message, so its next
`send()` or `streamResumeInterrupt()` may begin immediately even when the caller
retains the completed Generator.

Provider-controlled streaming input is bounded: provider SSE lines and
multiline events over 4 MiB fail with an `ApiErrorException` of type
`protocol_error`, while provider error bodies are retained only as bounded
prefixes. MCP stream/frame parsing applies the same fail-closed principle. The
normalized text and thinking accumulators reject more than 1,000,000 bytes per
stream with a `LengthException` rather than retaining an unbounded response.

### conversation()

Create a multi-turn conversation with persistent context.

```php
HaoCode::conversation(?HaoCodeConfig $config = null): Conversation
```

```php
$conv = HaoCode::conversation(new HaoCodeConfig(
    allowedTools: [],
    ephemeral: false,
));

$r1 = $conv->send('Create a User model');
echo $r1->text;

$r2 = $conv->send('Add email validation');  // remembers User model
echo $r2->text;

$conv->close();
```

The handle keeps message history in memory for its lifetime. Set
`ephemeral: false` when that history must also be written to durable session
storage. Tools remain disabled unless listed in `allowedTools`.
`Conversation::loadSession()` reconstructs the requested session first, then
atomically replaces any existing in-memory history and switches the handle to
the loaded session. It is treated as an existing session, so initial workspace
context and `SessionStart` hooks are not replayed; durable tool-result storage
is rebound to the canonical session id. Prefer `HaoCode::resume()` when
creating a new handle for a durable session.

### resume() / continueLatest()

Resume a previous session by ID, or continue the most recent one.

```php
HaoCode::resume(string $sessionId, ?HaoCodeConfig $config = null): Conversation
HaoCode::continueLatest(?string $cwd = null, ?HaoCodeConfig $config = null): Conversation
```

```php
// Resume by ID
$config = new HaoCodeConfig(allowedTools: [], ephemeral: false);
$conv = HaoCode::resume('20260407_143022_a1b2c3d4', $config);
$conv->send('Where were we?');

// Continue the latest session in current directory
$conv = HaoCode::continueLatest(config: $config);
$conv->send('Continue the refactoring');
```

**Working directory on resume.** Session transcripts store a canonical `cwd` per
entry. `resume()` uses that directory for tools (`Read` / `Write` / `Bash`, …)
so history and file operations stay aligned:

| Config `cwd` | Behavior |
|---|---|
| `null` / empty | Restored from the session transcript |
| Same as session cwd | Resume normally |
| Different from session cwd | Throws unless `allowCwdOverride: true` |

`continueLatest($cwd)` always injects the lookup `$cwd` into the resume config
when config `cwd` is empty, then follows the same rules.

Also works inline via config:

```php
// Resume via config
$result = HaoCode::query('Continue', new HaoCodeConfig(
    sessionId: 'abc123',
    allowedTools: [],
    ephemeral: false,
));

// Auto-continue latest
$result = HaoCode::query('What were we doing?', new HaoCodeConfig(
    continueSession: true,
    allowedTools: [],
    ephemeral: false,
));
```

### resumeInterrupt() / streamResumeInterrupt()

Resume a durable human-in-the-loop checkpoint. The non-streaming form returns a
normal query result (or a structured result when the interrupted operation came
from `structured()`); the streaming form yields the same `Message` types as
`stream()`.

Both methods require the original `HaoCodeConfig` at runtime even though the
parameter remains nullable for signature compatibility. This prevents a resumed
approval from silently switching tool implementations or escaping its sandbox.
The checkpoint also restores inline Skill tool/model scope and cumulative usage
and cost totals. When a synchronous worktree Agent retains changes after resume,
the final result includes `worktree_path`, `worktree_branch`, and
`worktree_retained` in its `usage` metadata.

The run snapshot carries the effective `cwd`, project/worktree identity, model
and prompt settings, allowed tools and active Skill scope, remaining turns,
cumulative token/cost totals, budget-ledger identifiers and the sandbox lease
identity. Credentials are excluded. On resume, the caller's current security
policy is revalidated and may only tighten the checkpointed boundary.

Interrupt lifecycle (latest entry wins):

| State | Meaning |
|---|---|
| `interrupt_pending` | Waiting for host decisions |
| `interrupt_resolving` | Claimed; tool side effects may be in progress — not auto-retried |
| `interrupt_resolved` | Tools applied; model loop continued |
| `interrupt_cancelled` | Explicitly cancelled by the host |
| `interrupt_failed` | Claimed resume failed (provider/tool/session error); terminal, not auto-retried |

A claim-then-crash path records `interrupt_failed` with `error`,
`side_effect_status` (`none` / `partial` / `unknown`), and optional
`partial_results` instead of leaving the interrupt stuck in `resolving`.
Post-claim setup/tool failures are always written as `interrupt_failed` so the
interrupt never stays permanently in `resolving`.

When you resume via a long-lived `Conversation` handle — including one created
by `HaoCode::resume()` in a later process — the pending checkpoint's sandbox
lease is authoritative. The conversation rebuilds its loop after the interrupt
and restores the working directory from (in order) the live run context, the
session transcript canonical cwd, then `RunOptions` cwd — so a later `send()`
does not fall back to the process `getcwd()` or a fresh sandbox. The rebuild
carries the final resumed usage before delivering a terminal streaming `result`
and releases the conversation operation before that yield, so the next `send()`
continues immediately with the same cumulative accounting and transcript even
if the caller retains the completed Generator.
If a later-process `HaoCode::resume()` handle has its own unrelated fresh
sandbox, another streaming interrupt retains only the checkpoint lease; closing
the handle still applies its configured cleanup policy to that fresh sandbox.

The static `HaoCode::streamResumeInterrupt()` facade creates a temporary
restored Conversation for one resume operation. It closes that temporary run
before yielding a terminal `result` or `error`, while a further `interrupt`
detaches its sandbox lease so a later resume sees the same filesystem.

```text
HaoCode::resumeInterrupt(string $sessionId, string $interruptId, array $decisions, ?HaoCodeConfig $config = null): QueryResult|StructuredResult
HaoCode::streamResumeInterrupt(string $sessionId, string $interruptId, array $decisions, ?HaoCodeConfig $config = null): Generator<Message>
```

See [Durable human-in-the-loop](#durable-human-in-the-loop) for checkpoint and
decision semantics.

### structured()

Extract structured (JSON) data from the agent's response. The schema is
validated with a real JSON Schema validator
([`swaggest/json-schema`](https://github.com/swaggest/json-schema)). JSON
syntax errors and schema violations enter the same retry path. Configure the
retry budget with `HaoCodeConfig::$structuredMaxRetries` (default `1`; set to
`0` to fail fast). Root `type: array` prompts ask for a JSON array; object
schemas ask for a JSON object. Schemas must be self-contained: external or
filesystem `$ref` targets are never fetched and are rejected before model
spend; local fragment references such as `#/definitions/item` remain supported.

```php
HaoCode::structured(string $prompt, array $jsonSchema, ?HaoCodeConfig $config = null): StructuredResult
```

```php
$result = HaoCode::structured('Classify this ticket: "My order is late"', [
    'type' => 'object',
    'properties' => [
        'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical']],
        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
        'summary'  => ['type' => 'string'],
    ],
    'required' => ['category', 'priority', 'summary'],
]);

echo $result->category;   // 'shipping'
echo $result['priority'];  // 'high' (ArrayAccess)
$result->toArray();        // ['category' => 'shipping', ...]
$result->toJson();         // '{"category":"shipping",...}'
```

`StructuredResult::toJson()` always keeps Unicode and slashes unescaped and
throws `JsonException` when the data cannot be encoded. `__toString()` uses the
same encoding policy with `JSON_PRETTY_PRINT`.

**Retry conversation reuse.** Parse and schema retries always share one
in-memory `Conversation` (even when `ephemeral: true`) so prior tool results
remain visible and side effects are not re-issued on a blank agent. `ephemeral`
only controls durable session persistence. Correction turns ask for fixed JSON
only and remind the model not to repeat completed side effects. The same
parse/validate/retry state machine is used after durable HITL resume when the
checkpoint recorded `operation: structured` (restoring `response_schema` from
the checkpoint).

When validation fails after exhausting retries, `structured()` throws
`HaoCode\Sdk\StructuredResultValidationException`, which exposes the raw model
response (`$e->rawResponse`) and the validator's error list
(`$e->validationErrors`) so callers can log, fall back, or surface the failure.

---

## HaoCodeConfig Reference

All parameters are optional. Pass as named arguments:

```php
$config = new HaoCodeConfig(
    apiKey: 'your-key',
    model: 'claude-sonnet-4-6',
    // ...
);
```

### API Connection

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `apiKey` | `?string` | `null` | API key for the selected provider. Anthropic may use legacy project/global settings and `ANTHROPIC_API_KEY`; OpenAI wire formats resolve from explicit `apiKey`, the matching provider entry, or `OPENAI_API_KEY` |
| `model` | `?string` | `null` | Model ID. Anthropic falls back to the configured Claude default; `openai` and `openai_chat` require an explicit model or one in the selected provider entry |
| `baseUrl` | `?string` | `null` | API endpoint URL (for proxies, custom endpoints) |
| `maxTokens` | `?int` | `null` | Maximum output tokens per response |
| `providerType` | `?string` | `null` | `anthropic`, `openai`, or `openai_chat` wire format (aliases: `openai_responses` / `responses` → `openai`; `openai_chat_completions` / `chat_completions` → `openai_chat`). **Unknown non-empty values throw at construction** — they never silently map to Anthropic |
| `oauthBearer` | `?bool` | `null` | When `true`, treat `apiKey` as an Anthropic OAuth access token: it is sent as `Authorization: Bearer <token>` with the `oauth-2025-04-20` beta flag (merged with the prompt-caching flag) instead of the `x-api-key` header. `null`/`false` keeps the default `x-api-key` behaviour. Anthropic provider only |
| `headers` | `array<string, string>` | `[]` | Extra HTTP request headers merged into every provider request (e.g. GitHub Copilot's `Editor-Version` / `Copilot-Integration-Id`). A custom value overrides the provider's hardcoded header of the same name (case-insensitive), except `Authorization` / `x-api-key`, which always stay under the SDK's authentication logic. Invalid entries (non-string keys/values, invalid header names, CR/LF) are filtered out |

When any of these are set, the SDK creates a run-scoped provider. Explicit
values override active settings; unspecified connection values come only from
the selected provider and the matching vendor environment. Credentials and
models are resolved as one connection, so switching wire formats cannot reuse
another vendor's active key or model.
Selecting `openai` or `openai_chat` without a model fails before request
creation. This prevents a Claude default model ID from crossing provider
boundaries.

Settings files use the same fail-closed type rules: an explicit provider entry
`type` (or runtime `provider_type` override) that is not a known alias throws
before any HTTP request is built. When `type` is omitted from a named provider
entry, only a name that is itself a known alias is treated as a type; otherwise
the historical Anthropic default still applies.

Before constructing a provider, sandbox, or MCP connection, the SDK preflights
the requested Agent, provider wire format, model, endpoint, tools, sandbox, and
permission mode. Once `AgentLoopFactory` has assembled the run, the run guard
binds the manifest to the actual `ToolRegistry`, including implicit tools such
as `AskUserQuestion`. The same guard revalidates every runtime setting mutation
and every provider request; a rejected mutation restores all previous settings.
An unmatched model or custom endpoint remains `unknown` and is allowed, so a
newly released model is not rejected by a stale catalog. `oauthBearer: true`
with `openai` or `openai_chat` is explicitly unsupported and fails before
provider I/O.

The runtime `Config` tool switches `active_provider` as one connection identity.
Provider type, API key, model, base URL, and token limits are resolved from the
selected provider entry instead of being carried over from the previous
provider. Run-level OAuth mode and custom headers remain explicit; if they are
incompatible with the selected wire format, the whole switch is rejected and
rolled back. The credential pool and cost tracker resolve the current provider
at each request boundary. `api_base_url` may be changed at runtime only to
another path on the current HTTP(S) origin; URLs containing credentials, query
strings, or fragments are rejected. Configure a named provider and select it
with `active_provider` when the host must change.

Input budgeting uses the active provider's `context_window` setting. It falls
back to `HAOCODE_CONTEXT_WINDOW` (200000 by default) and reserves both the
configured output tokens and a safety margin before sending a request.

### Agent Behavior

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `cwd` | `?string` | `null` | Working directory for tool execution. Defaults to `getcwd()`. On `resume()`, when empty, the session transcript cwd is restored instead |
| `maxTurns` | `int` | `50` | Maximum agent turns (tool-use round trips); must be at least `1`. After three consecutive identical valid tool-error batches, the loop stops repeating the action and performs one no-tool finalization request |
| `maxBudgetUsd` | `?float` | `null` | Shared post-response spending guard (USD) for the root run, child/Team/background agents, forked skills, structured retries, and HITL resumes. Checked before each model request and after usage is recorded — **not** a pre-reserved hard cap. In-flight or parallel work can finish slightly over the limit. On HITL resume the effective limit is `min(snapshot, config)` |
| `allowCwdOverride` | `bool` | `false` | When `false`, `resume()` refuses a config `cwd` that differs from the session transcript cwd. Set `true` only when tools should intentionally run under a different project root |
| `ephemeral` | `bool` | `true` | Disable session and tool-result persistence for this run |
| `permissionMode` | `string` | `'default'` | `'default'`, `'plan'`, `'accept_edits'`, `'bypass_permissions'` |
| `sandbox` | `?SandboxConfig` | `null` | Optional temporary filesystem/shell runtime for tools |
| `credentialPool` | `?CredentialPool` | `null` | Rotate provider credentials and retry rate-limited keys |
| `interruptOn` | `array` | `[]` | Exact tool names to pause before execution; values are `true`, `false`, or review configuration arrays |
| `enableAskUser` | `bool` | `false` | Register `AskUserQuestion` as a serializable host interrupt |
| `hitlMode` | `?string` | `null` | HITL approval mode: `'ask'`, `'smart'`, or `'auto'`; `null` resolves from config/env (default `'smart'`). See [Smart HITL modes](#smart-hitl-modes) |
| `hitlReviewModel` | `?string` | `null` | Model used to review gray-area actions in `'smart'` mode; `null` reuses the current run's model |
| `hitlAllowlistPath` | `?string` | `null` | JSON file with user-saved "always allow" Bash rules (exact/prefix, v1+v2); `null` disables the feature. See [Smart HITL modes](#smart-hitl-modes) |
| `goal` | `?string` | `null` | What the run must achieve, in a sentence or two. When set, the model is asked once to check its final answer against it before the run ends. Blank values normalize to `null` |
| `goalVerificationRounds` | `int` | `1` | How many goal checks a run may spend (0-5). `0` disables the check while keeping `goal` available to the reminder |
| `goalReminder` | `?array` | `null` | Periodically restate the task during long runs. `null` disables it; `[]` enables the defaults. Keys: `recapEvery` (default 5), `fullEvery` (default 10); `0` disables that half and at least one must be positive |
| `planExitPolicy` | `string` | `'approval'` | How `ExitPlanMode` leaves plan mode: `'approval'` requires a human decision, `'auto'` switches the permission mode without asking. See [Plan Mode](#plan-mode) |

Security modes are validated exactly. Unknown values (including accidental
whitespace such as `'plan '`) throw instead of falling back to the broader
`default` mode.

### Staying on task in long runs

Task context is injected once, on the first user turn. Thirty turns and a
compaction later it can be the least prominent thing in the window, and the model
drifts or stops early. Two opt-in settings address that.

`goalReminder` restates the task at a turn boundary: a one-line recap every
`recapEvery` turns and the full original request every `fullEvery` turns, with the
full restatement winning when both fall on the same turn. The recap uses `goal`
when set, otherwise the opening of the original prompt. Reminders are not
persisted, so a resumed session does not replay them.

`goal` also enables a verification round. When the model stops calling tools, it is
shown the goal alongside its own final answer and asked whether the goal is really
met; if not it keeps working. The round is bounded by `goalVerificationRounds`, not
by `maxTurns`, so a run already at the turn limit still gets its check. This costs
one extra model request per run, which is why it needs an explicit `goal`.

Both apply to the run the caller asked for. Nested agents have their own task and
do not inherit either setting.

### Prompts

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `systemPrompt` | `?string` | `null` | Replace the default system prompt entirely |
| `appendSystemPrompt` | `?string` | `null` | Append text to the default system prompt |
| `contextPreset` | `string` | `'coding'` | `'coding'` adds Git/project/coding context; `'generic'` omits those coding-only fragments while keeping tools, skills, memory, and output style |
| `responseSchema` | `?array` | `null` | Override the schema used by `structured()` |
| `structuredMaxRetries` | `int` | `1` | Number of times `structured()` retries after JSON parse or schema validation failures. Retries always reuse one in-memory conversation (even when `ephemeral: true`) so prior tool results remain visible; correction turns ask for fixed JSON only. `0` fails fast with `StructuredResultValidationException`. |
| `webfetchAllowPrivateNetworks` | `bool` | `false` | Allow WebFetch to reach private-like RFC1918, loopback, link-local, and IPv6 ULA ranges. Special-use, multicast, documentation, benchmark, and reserved ranges remain blocked; use an explicit CIDR allowlist for a deliberate exception. |
| `webfetchPrivateAllowList` | `list<string>` | `[]` | Explicit CIDRs that bypass the WebFetch SSRF guard (for example `['127.0.0.1/32', '192.168.0.0/16']`). The default is empty, so loopback is not implicitly reachable. |
| `webfetchMaxBytes` | `int` | `5_242_880` | Hard cap on decompressed response bytes per WebFetch request. Responses over the cap are cancelled and surfaced as an error (previously the entire body was buffered, risking OOM). |

Tool-enabled runs use the explicitly selected context preset. `'coding'` is the
backward-compatible default and includes the default coding prompt, environment,
project instruction files, Git turn context, and Hao Code path conventions.
`'generic'` omits those coding-only sections. Runs with no tools use the minimal
text-only prompt in either preset. `systemPrompt` and `appendSystemPrompt` retain
their documented override behavior in every mode.

### Tools & Skills

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `allowedTools` | `string[]` | `[]` | Tools to allow. `['*']` explicitly enables all tools |
| `disallowedTools` | `string[]` | `[]` | Tools to deny (takes precedence over allowed) |
| `tools` | `SdkTool[]` | `[]` | Custom tools to register |
| `skills` | `SdkSkill[]` | `[]` | Custom skills to register |
| `skillDirectories` | `string[]` | `[]` | Additional explicit directories containing `<name>/SKILL.md` packages |
| `recursiveSkillDiscovery` | `bool` | `false` | Recursively discover nested Skill packages; shallow same-name packages win |
| `images` | `array` | `[]` | Image attachments for multimodal input (one-shot queries and streams). Each item can be a local file path, URL, pre-built content block, or data URI. For conversations, pass images per-send via `Conversation::send()` |

Tools, permission bypass, and durable storage are independent opt-ins. Merely
setting `apiKey`, `model`, or another connection option does not change them.
Custom tools and sandbox replacement tools use the same exact-name
`allowedTools`/`disallowedTools` filters as built-in tools; list each name
explicitly or use `allowedTools: ['*']`.

Tool names are stable capability identities. Registration now fails on a
duplicate name instead of silently letting a later custom or MCP tool replace
the assembled implementation. SDK consumers should give custom tools unique
names; sandbox and run-policy replacements are selected internally through an
explicit replacement path. Dynamic input schemas remain supported and are
revalidated before provider use.

The built-in `Read` tool produces text tool results. Images and PDFs without
extractable text fail explicitly rather than being returned as base64; provide
images through the SDK image-input APIs described in [Multimodal Input](#multimodal-input).
Host and sandbox text reads use the same bounded line-window contract: offsets
must be at most 1,000,000, limits at most 10,000 lines, and a single line or
rendered result cannot exceed 1,000,000 bytes.
The built-in `WebFetch` tool returns the whole converted page by default. Set
`extract: true` to return only the main article instead, and pass `keywords`
with the terms the answer should contain — blocks mentioning them are weighted
up during scoring, so a short but relevant block (a data table, a price row) is
not outranked by long boilerplate. When no block scores above the surrounding
noise, or the winning block retains too little of the page, the full page is
returned instead and a `[WebFetch]` note explains why. Extraction runs before
the output-size cap, so an article is never truncated to make room for
navigation. `WebFetch` also prepends a `[WebFetch]` warning when the response
is a bot-challenge interstitial, an access-denied wall, or a client-rendered
shell with no static text; no headless browser is bundled to fetch such pages,
so the note tells the model to find another source. A 4xx/5xx response is
reported with the first part of its body alongside the status code.

The built-in `WebSearch` tool asks the model to append today's date to
time-sensitive queries when the user named no date or period, so "latest
release notes" does not return last year's page. The date itself is not baked
into the tool definition — that would change the cached prompt prefix daily —
it comes from the runtime context injected on the first user turn.

The read-before-write guard records only complete, successful reads. Failed or
partial reads do not authorize `Write` or `Edit`, and if the file's external
revision changes, the caller must complete another full `Read` before mutation.
Receipts from a `Read` remain pending until that tool-result batch is visible to
the model, so a `Read` and a `Write`/`Edit` in the same model batch cannot
authorize one another; issue the mutation in the next batch.

This differs from `v1.7.0`, where an explicitly constructed config defaulted to
all tools, permission bypass, and durable storage. Existing trusted callers must
set `allowedTools: ['*']`, `permissionMode: 'bypass_permissions'`, and
`ephemeral: false` explicitly when migrating to `v1.8.0`.

## Long-Term Memory

Long-term memory is separate from conversation history and project instruction
files such as `AGENTS.md`, `HAOCODE.md`, and `CLAUDE.md`. A memory store contains
learned reference data from previous runs. The SDK labels it as potentially
stale data and gives the current user request and verified evidence precedence.

The default `JsonMemoryStore` stores entries in `~/.haocode/memory.json`. Each
entry has three retrieval levels:

| Level | Purpose |
|-------|---------|
| `l0` | Compact one-line index; default system-prompt level |
| `l1` | Structured overview for additional context |
| `l2` | Original stored value |

Use the public store API to seed memory before a run:

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Memory\JsonMemoryStore;

$memory = new JsonMemoryStore(__DIR__.'/var/agent-memory.json');
$memory->write(
    'response_style',
    'The user prefers concise answers with runnable examples.',
    'preference',
);

$result = HaoCode::query('How should I structure this command?', new HaoCodeConfig(
    memoryStore: $memory,
    memorySummaryLevel: 'l0',
    allowedTools: ['MemoryRead'],
));
```

The same run-scoped store is used both for prompt injection and by all Memory
tools. `memoryStore` takes precedence over `memoryStoragePath`. The path option
is a shortcut when the default JSON implementation is sufficient:

```php
$config = new HaoCodeConfig(
    memoryStoragePath: __DIR__.'/var/agent-memory.json',
    memorySummaryLevel: 'l1',
);
```

An explicitly supplied `memoryStore`, `memoryStoragePath`, or non-default
`memorySummaryLevel` enables long-term-memory injection even when
`allowedTools: []` keeps the run text-only. Text-only runs receive the configured
summary but cannot fetch additional detail.

### Reading and updating memory

Memory tools remain opt-in:

| Tool | Behavior | Permission classification |
|------|----------|---------------------------|
| `MemoryRead` | List keys or fetch `l1`/`l2` detail | Read-only |
| `MemoryWrite` | Store or replace one entry | State-changing |
| `MemoryDelete` | Delete one entry | State-changing |

```php
$config = new HaoCodeConfig(
    memoryStore: $memory,
    allowedTools: ['MemoryRead', 'MemoryWrite', 'MemoryDelete'],
    ephemeral: false,
    interruptOn: [
        'MemoryWrite' => true,
        'MemoryDelete' => true,
    ],
);
```

`MemoryWrite` and `MemoryDelete` are not silently enabled. In addition to being
listed in `allowedTools`, they pass through the normal SDK permission system.
Their model-facing policy permits them only for explicit user requests to
remember, update, forget, or remove durable information, and forbids storing
credentials or other secrets. A trusted non-interactive host may instead use
`permissionMode: 'bypass_permissions'`, accepting responsibility for those
mutations.

`JsonMemoryStore` refreshes reads across instances and uses a lock plus
same-directory atomic replacement for writes. Applications can implement
`MemoryStoreInterface` to provide database-backed, per-user, or other
application-specific isolation.

## Sandbox Runtime

Use `SandboxConfig` when the agent needs file or shell tools but must not mutate
the PHP host project directory. Sandbox mode replaces `Read`, `Write`, `Glob`,
and `Grep` with sandbox-scoped tools. Set `mode: 'full'` to also replace `Bash`
with a sandbox-scoped shell.

Sandbox `Write` requires a complete current `Read` before overwriting an
existing file. Local, native, and Tokimo backends perform the comparison inside
their atomic publish path. AgentRun rechecks the remote bytes immediately before
its write request, but its file API does not expose a conditional-write
primitive; a concurrent remote writer can therefore still race that final
request.

AgentRun `Write` returns `metadata['writeSafety'] === 'recheck_only'` and
`metadata['conditionalWrite'] === false`. Local, Native, and Tokimo backends
report the stronger conditional revision-aware path.

Choose the backend by the isolation boundary you need:

| Backend | Hosts | Use it for |
|---------|-------|------------|
| `local()` | Any supported PHP host | File workspace isolation without untrusted `Bash`; shell commands still run as normal host processes |
| `native()` | macOS and Linux | Lightweight local process isolation through Seatbelt or bubblewrap |
| `tokimo()` | macOS arm64, Linux amd64/arm64, Windows amd64 | Recommended cross-platform boundary for agent-generated or untrusted shell commands; installed separately on demand |
| `agentRun()` | Any host with AgentRun access | Remote cloud isolation when commands and files must stay off the PHP host |

For a portable full sandbox, prefer `tokimo()`. Its runner and guest runtime are
intentionally excluded from the default Composer install; run
`vendor/bin/hao-code-sandbox install --with-runtime` before first use or at any
later time. Use `local()` when only sandbox-scoped file tools are required.

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;

$result = HaoCode::query('Inspect the project and write a report.md file', new HaoCodeConfig(
    cwd: __DIR__,
    sandbox: SandboxConfig::local(
        mode: 'filesystem',
        sync: 'upload-cwd',
        remoteCwd: '/workspace',
    ),
    allowedTools: ['Read', 'Write', 'Grep', 'Glob'],
    permissionMode: 'bypass_permissions', // isolated workspace, unattended run
));
```

Sandbox modes:

| Mode | Replaced tools | Notes |
|------|----------------|-------|
| `filesystem` | `Read`, `Write`, `Glob`, `Grep` | Default; `Bash` is disabled |
| `full` | `Read`, `Write`, `Glob`, `Grep`, `Bash` | Shell commands run inside the sandbox backend |

The replacement boundary is otherwise fixed: `Edit`, `apply_patch`,
`NotebookEdit`, `Lsp`, worktree tools, `Agent`, and `SendMessage` are disabled
while a sandbox is active. Task/team, custom, and MCP tools remain host-side and
are never implicitly moved into the sandbox; list them explicitly only when the
host intends to keep that capability. Explicitly requesting a disabled tool
fails before the sandbox is created. `allowedTools: ['*']` selects every tool
available in the chosen mode; it does not require unavailable host-only tools
or `Bash` in `filesystem` mode.

All sandbox backends bound SDK-captured command output to 100,000 bytes. Native,
Tokimo, and AgentRun apply that bound independently to stdout and stderr; Local
uses one shared 100,000-byte budget across both streams. When a command is
terminated for that limit, the result sets `outputLimited: true` and uses exit
code `1`; timeout and user abort use `124` and `130` respectively. `timedOut`,
`aborted`, and `outputLimited` are included in normal sandbox Bash execution
metadata.

AgentRun command HTTP bodies and Tokimo runner JSONL response lines have a
separate 2 MiB transport limit, enforced before JSON or base64 decoding. This
bound protects the PHP process from oversized protocol envelopes; decoded
stdout and stderr remain subject to the 100,000-byte SDK capture contract. A
transport overflow returns `outputLimited: true` with exit code `1`. Tokimo
closes the oversized runner process and starts a clean one for the next command
without discarding the sandbox workspace.

Legacy `CronCreate`/`CronList`/`CronDelete` classes are not registered by the
default runtime because no prompt execution driver is wired.

When a durable HITL interrupt is raised, the active sandbox root (or remote
identity) is **detached** instead of cleaned up. The interrupt checkpoint stores
a lease **identity** (root path, resolved AgentRun sandbox ID, Tokimo vmDir);
credentials (API keys, tokens) are never written to session JSONL. On
`resumeInterrupt()`, identity is reattached while security **policy** (mode,
network, cleanup) comes from the caller's current config and may only tighten.
For a long-lived `Conversation`, including a handle loaded by
`HaoCode::resume()`, the temporary resume run hands that same lease to the
rebuilt handle before cleanup, so later tool calls retain files created before
the interrupt. Sandbox tool names (`Read`/`Write`/`Glob`/`Grep`/`Bash`)
are reserved while sandbox mode is active and cannot be overridden by custom or
MCP tools. Final completion still honors the configured `cleanup` policy.

### Local backend

The local backend creates an isolated temp directory. With `sync: 'upload-cwd'`,
it copies text files from `cwd` into `remoteCwd`, skipping `.git`,
`node_modules`, `vendor`, caches, binaries, and files over 1MB.

`SandboxConfig::local(mode: 'full')` provides workspace isolation, not operating
system process isolation: `Bash` still runs as a normal host process with the
sandbox directory as its working directory. Do not use it for untrusted commands.

| Sync | Behavior |
|------|----------|
| `none` | Start with an empty sandbox at `remoteCwd` |
| `upload-cwd` | Copy a safe text snapshot of `cwd` into `remoteCwd` |

### Native local backend

Use `SandboxConfig::native()` when an agent may generate shell commands. It uses
the host operating system's local isolation primitive and refuses to run if that
engine is missing:

- macOS: Seatbelt via `/usr/bin/sandbox-exec`
- Linux: bubblewrap (`bwrap` must be installed)

```php
$config = new HaoCodeConfig(
    cwd: __DIR__,
    sandbox: SandboxConfig::native(
        sync: 'upload-cwd',
        remoteCwd: '/workspace',
        network: 'blocked',
        cleanup: 'always',
    ),
    allowedTools: ['Read', 'Write', 'Glob', 'Grep', 'Bash'],
    permissionMode: 'bypass_permissions', // native sandbox contains mutations
);
```

The backend allows writes only under its temporary root, starts commands with a
sanitized environment, and blocks network access by default. Set
`network: 'allow-all'` only for tasks that require network access. `engine` may
be set to `seatbelt` or `bubblewrap` to require a specific engine; the default
`auto` selects the native engine for the current platform. This backend does not
yet provide Tokimo's Linux micro-VM boundary, PTY transport, or packaged rootfs;
use the Tokimo or AgentRun backend when a stronger boundary is required.

Native, Tokimo, and AgentRun all apply the same 100,000-byte SDK capture
contract. Native and Tokimo can terminate their local runner/process directly;
AgentRun cancels the in-flight HTTP response and reports an interrupted result
when the caller's abort signal fires while a remote command is still running.

### Tokimo cross-platform backend

`SandboxConfig::tokimo()` selects an optional native host runner. Supported
targets are macOS arm64, Linux amd64 and arm64, and Windows amd64. The runner is
not bundled with the Composer package; this keeps the default SDK install small.
The same runner process owns a persistent sandbox for the entire SDK run, while
PHP continues to expose the standard sandbox file and shell tool interfaces.

Install only the runner, or the complete runtime, at any time after Composer
installation:

```bash
# Install only the runner when baseRootfs is already available:
vendor/bin/hao-code-sandbox install

# Install the runner and the guest kernel/rootfs:
vendor/bin/hao-code-sandbox install --with-runtime

# Report whether the runner is installed:
vendor/bin/hao-code-sandbox status

# From a hao-code source checkout:
bin/hao-code-sandbox install --with-runtime
```

The command downloads only the current OS/CPU release assets, verifies their
SHA-256 checksums, and stores them in the user's cache. If the backend is used
before installation, it fails with the command required to install it. The
legacy `php scripts/sandbox-setup.php` entry point remains available and now
installs the runner first when necessary.

You do not need a separate Tokimo installation. On macOS and Linux, complete
runtime setup may request `sudo` to preserve the guest rootfs ownership during
extraction.

Use the `baseRootfs` path printed by the `--with-runtime` command:

```php
use HaoCode\Sdk\Sandbox\SandboxConfig;

$baseRootfs = getenv('HAOCODE_SANDBOX_ROOTFS');
if (! is_string($baseRootfs) || $baseRootfs === '') {
    throw new RuntimeException('Set HAOCODE_SANDBOX_ROOTFS to the setup output.');
}

$config = new HaoCodeConfig(
    cwd: __DIR__,
    sandbox: SandboxConfig::tokimo(
        baseRootfs: $baseRootfs,
        sync: 'upload-cwd',
        remoteCwd: '/workspace',
        memoryMb: 4096,
        cpuCount: 4,
        network: 'blocked',
    ),
    allowedTools: ['Read', 'Write', 'Glob', 'Grep', 'Bash'],
    permissionMode: 'bypass_permissions',
);
```

`network` accepts `blocked` (the default) or `allow-all`. `memoryMb: 0` and
`cpuCount: 0` ask Tokimo to use its platform defaults. `binary` can override the
cached runner, and `vmDir` can preserve VM runtime state in a caller-managed
directory. On Linux, the backend uses a micro-VM when KVM and the VM helpers are
available and otherwise falls back to bubblewrap. Install `cloud-hypervisor` and
`virtiofsd` before running `sandbox-setup.php`; the script links them into the
versioned runtime cache layout expected by Tokimo. Install `bubblewrap` when the
fallback is required. On Windows, Tokimo's Hyper-V backend also requires the
downloaded service to be installed once as
administrator by running
`haocode-sandbox-svc-windows-amd64.exe --install` from an elevated terminal.

### Alibaba Cloud AgentRun backend

`SandboxConfig::agentRun()` uses Alibaba Cloud AgentRun as a remote temporary
filesystem and script execution environment. It is useful when the PHP server
must not write project files or execute agent-generated shell commands locally.

Verify credentials and template/instance IDs first:

```bash
export AGENTRUN_ACCOUNT_ID=your-account-id
export AGENTRUN_API_KEY=your-api-key
export AGENTRUN_TEMPLATE_NAME=your-template-name
export AGENTRUN_REGION=cn-hangzhou
php scripts/agentrun-verify.php
```

`AGENTRUN_TEMPLATE_NAME` asks AgentRun to create a fresh temporary sandbox from a
template. `AGENTRUN_SANDBOX_ID` is only for an already-created live sandbox
instance. Do not put a template ID into `AGENTRUN_SANDBOX_ID`; AgentRun will
return `sandbox not found`.

```php
use HaoCode\Sdk\Sandbox\SandboxConfig;

$config = new HaoCodeConfig(
    sandbox: SandboxConfig::agentRun(
        accountId: getenv('AGENTRUN_ACCOUNT_ID')
            ?: throw new RuntimeException('Set AGENTRUN_ACCOUNT_ID.'),
        sandboxId: getenv('AGENTRUN_SANDBOX_ID') ?: null,
        templateName: getenv('AGENTRUN_TEMPLATE_NAME') ?: null,
        apiKey: getenv('AGENTRUN_API_KEY') ?: null,
        region: getenv('AGENTRUN_REGION') ?: 'cn-hangzhou',
        mode: 'full',
        remoteCwd: '/tmp',
    ),
    allowedTools: ['Read', 'Write', 'Bash'],
    permissionMode: 'bypass_permissions', // remote sandbox contains mutations
);
```

For the current AgentRun code-interpreter template, use a writable directory
under `/tmp`, such as `/tmp/workspace`, for generated files. Creating `/workspace`
at the filesystem root can fail with permission denied. The complete demo in
`examples/agentrun-ml-clustering-agent.php` lets an agent generate data, write a
pure-Python k-means script, run it in AgentRun, and read back the JSON summary.

AgentRun `Glob` and `Grep` prune default heavy directories (`.git`, `vendor`,
`node_modules`, and similar paths), bound visited files and returned results, and
match `glob` filters against the relative path rather than only the basename.
When a remote search reaches one of those bounds, the tool result keeps the
normal text shape and adds machine-visible metadata such as `searchLimited`,
`resultLimited`, `resultLimit`, `visitedLimited`, and `residualDifferences`.
`residualDifferences` also records that AgentRun Glob uses remote traversal order
and AgentRun Grep uses the remote grep regex/text-file semantics.

Sandbox `Glob` and `Grep` keep the host tools' no-match and result-heading
wording; sandbox paths remain in the remote namespace so a returned path can be
passed directly to sandbox `Read`. A bounded Glob result includes an explicit
notice in its text and retains the machine-visible search metadata. Search
failures begin with `Glob search failed:` or `Grep search failed:`.

### Callbacks

| Parameter | Type | Description |
|-----------|------|-------------|
| `onText` | `?callable` | `fn(string $delta): void` — streaming text chunk |
| `onThinking` | `?callable` | `fn(string $delta): void` — reasoning/thinking chunk |
| `onToolStart` | `?callable` | `fn(string $toolName, array $input): void` — tool began |
| `onToolComplete` | `?callable` | `fn(string $toolName, ToolResult $result): void` — tool finished |
| `onTurnStart` | `?callable` | `fn(int $turnNumber): void` — new agent turn |

### Advanced

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `abortController` | `?AbortController` | `null` | Cancellation handle |
| `sessionId` | `?string` | `null` | Resume a previous session in `query()`/`stream()` |
| `continueSession` | `bool` | `false` | Auto-continue latest session in `query()`/`stream()` |
| `thinkingEnabled` | `bool` | `false` | Enable extended thinking |
| `thinkingBudget` | `int` | `10000` | Manual extended-thinking token budget, or (for adaptive-thinking models) the budget used to derive effort when `effort_level` is not set |
| `memorySummaryLevel` | `string` | `'l0'` | Memory injected into the system prompt: `l0`, `l1`, or `l2` |
| `memoryStoragePath` | `?string` | `null` | Isolated JSON memory file; defaults to `~/.haocode/memory.json` |
| `memoryStore` | `?MemoryStoreInterface` | `null` | Run-scoped custom store; takes precedence over `memoryStoragePath` |

Models that require adaptive thinking (including Opus 4.8 and current Claude 5
models) send `thinking.type: adaptive` with an effort tier:

| Source | Effort |
|---|---|
| Settings `effort_level` = `low` / `medium` / `high` / `max` | That value |
| Otherwise `thinkingBudget` below 8000 | `low` |
| `thinkingBudget` 8000–15999 | `medium` |
| `thinkingBudget` 16000–31999 | `high` |
| `thinkingBudget` 32000 or more | `max` |

Manual extended-thinking models still send `thinking.type: enabled` with
`budget_tokens` from `thinkingBudget`.

### Factory Method

```php
// Connection shorthand; safe HaoCodeConfig defaults still apply.
$config = HaoCodeConfig::make(apiKey: 'key', model: 'claude-haiku');
```

`make()` is text-only, uses normal permission checks, and does not persist a
session unless those capabilities are enabled with the full constructor.

---

## QueryResult

Returned by `HaoCode::query()` and `Conversation::send()`. Implements `Stringable`.

```php
$result = HaoCode::query('Hello');

// As string (Stringable)
echo $result;                    // prints response text
echo "Answer: " . $result;      // string concatenation works

// Properties
$result->text;                   // string — response text
$result->usage;                  // array — ['input_tokens' => int, 'output_tokens' => int, ...]
$result->cost;                   // float — estimated cost in USD
$result->sessionId;              // ?string — session ID for resume
$result->turnsUsed;              // int — agent turns consumed
$result->terminationReason;      // RunTerminationReason — typed terminal reason

// Helpers
$result->inputTokens();          // int
$result->outputTokens();         // int
```

`terminationReason` is one of `normal`, `cancelled`, `budget_exhausted`,
`turn_limit`, or `repeated_tool_failure` from
`HaoCode\Contracts\RunTerminationReason`. Branch on the enum rather than the
response text. For v1 compatibility, cancellation and budget-limit text such
as `(aborted)` remains unchanged even though the reason is now unambiguous.

`usage['input_tokens']` is the accumulated provider-reported input usage for
the conversation/run lifetime (not a single HTTP delta), including nested
`AgentAsTool` / `Agent` child runs that share the parent usage ledger. After a
durable HITL snapshot resume rebuilds the agent loop, both token counters and
cost remain cumulative so they stay in the same statistical scope. When the
provider reports prompt-cache telemetry, `usage['cache_read_tokens']` contains
the cached portion and `usage['cache_creation_tokens']` contains explicit cache
writes (Anthropic). `usage['last_turn_input_tokens']` is the full prompt input
of the most recent model request and is the appropriate value for current
context-window occupancy.

---

## MCP Tools

MCP server definitions are loaded from `~/.haocode/settings.json` and from the
`.haocode/settings.json` under `HaoCodeConfig::cwd`. A project definition with
the same name overrides its global definition.

Context7 example:

```json
{
  "mcp_servers": {
    "context7": {
      "transport": "http",
      "url": "https://mcp.context7.com/mcp",
      "enabled": true
    }
  }
}
```

Server and tool punctuation is normalized to underscores in the Agent-facing
name. Enable the tools needed by the run:

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$result = HaoCode::query('Look up the current Symfony Process API with Context7', new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: [
        'mcp__context7__resolve_library_id',
        'mcp__context7__query_docs',
    ],
));
```

MCP connections belong to the isolated SDK run and are closed with it. No MCP
server is started or contacted when `allowedTools` does not enable an MCP tool.
With a sandbox active, the wildcard `allowedTools: ['*']` does not implicitly
enable host-side MCP servers; list each required `mcp__...` tool explicitly.
For stdio servers, Hao Code passes only basic launch variables (`PATH`, `HOME`,
temporary-directory and locale variables) plus the server's explicit `env` map.
Configure required credentials explicitly:

```json
{
  "mcp_servers": {
    "private-server": {
      "transport": "stdio",
      "command": "node",
      "args": ["server.js"],
      "env": {"PRIVATE_SERVER_TOKEN": "replace-at-deploy-time"}
    }
  }
}
```

Avoid committing real credentials in project settings; prefer a protected
global settings file or generate the configuration during deployment.

### MCP host lifecycle: polling and roots

The SDK run installs `McpConnectionManager::poll()` as its cooperative event
pump. A host that uses `McpClient` / `McpConnectionManager` directly must call
`poll()` during idle time (or issue another MCP request) so server-initiated
notifications and reverse requests are read. This is especially important for
`stdio`: there is no independent reader thread that drains an idle server.
`McpConnectionManager::ensureConnected($name)` is the reconnecting entry point
when a direct host integration needs a live client.

```php
$manager->connectAll();
$manager->poll();                 // service pending idle messages
$client = $manager->ensureConnected('private-server');
$client->poll(0.01);              // optional bounded wait for direct hosts
```

`McpClient::setRoots()` changes the roots returned by the host's `roots/list`
handler. It does not automatically send `notifications/roots/list_changed`
after a connection is established, so set roots before `connect()` when the
server needs them during initialization. A later `setRoots()` takes effect for
future `roots/list` requests; it is not a server notification by itself.

### Streamable HTTP and OAuth

The `http` client transport implements MCP Streamable HTTP `2025-11-25`. It
parses SSE incrementally, handles JSON responses, responds to server-initiated
requests, listens on the optional GET event stream, honors SSE `retry` and
`Last-Event-ID`, re-initializes expired sessions before retrying a request, and
sends a best-effort DELETE when the run closes. Servers that answer GET with
HTTP 405 continue to work through request-scoped POST responses.

Remote servers may use a static `Authorization` header or a headless OAuth
client-credentials/refresh-token configuration. Secret values must stay in
environment variables; settings contain only the variable names:

```json
{
  "mcp_servers": {
    "private-api": {
      "transport": "http",
      "url": "https://mcp.example.com/mcp",
      "oauth": {
        "token_endpoint": "https://auth.example.com/oauth/token",
        "client_id": "hao-code",
        "client_secret_env": "PRIVATE_MCP_CLIENT_SECRET",
        "access_token_env": "PRIVATE_MCP_ACCESS_TOKEN",
        "refresh_token_env": "PRIVATE_MCP_REFRESH_TOKEN",
        "scope": "mcp:tools",
        "token_endpoint_auth_method": "client_secret_basic"
      }
    }
  }
}
```

`token_endpoint_auth_method` accepts `client_secret_basic` (default) or
`client_secret_post`. HTTP token endpoints are rejected except on loopback.
OAuth tokens refreshed during a run are kept in memory. Interactive browser
authorization and dynamic client registration remain the responsibility of the
host application.

## Custom Tools (SdkTool)

Define domain-specific tools the agent can call. Implement 4 methods:

```php
use HaoCode\Sdk\SdkTool;

class LookupOrderTool extends SdkTool
{
    public function name(): string
    {
        return 'LookupOrder';
    }

    public function description(): string
    {
        return 'Look up an order by ID from the database.';
    }

    public function parameters(): array
    {
        return [
            'order_id' => [
                'type' => 'string',
                'description' => 'The order ID to look up',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $order = Order::findOrFail($input['order_id']);
        return $order->toJson();
    }

    // Pure lookups opt into Plan-mode approval. This does not enable forking.
    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
```

Use it:

```php
$result = HaoCode::query('Find order #12345 and check its status', new HaoCodeConfig(
    allowedTools: ['LookupOrder'],
    tools: [new LookupOrderTool()],
));
```

### Parameter Format

Each parameter is `name => options`:

```php
public function parameters(): array
{
    return [
        'city' => [
            'type' => 'string',           // string|integer|number|boolean|array|object
            'description' => 'City name', // shown to the model
            'required' => true,           // default: false
            'enum' => ['NYC', 'LA'],      // optional: restrict values
        ],
    ];
}
```

### Error Handling

Exceptions in `handle()` are caught and sent back to the model as error messages:

```php
public function handle(array $input): string
{
    throw new \RuntimeException('Database connection refused');
    // → model receives: "Database connection refused" as tool error
    // → model can retry or explain the failure to the user
}
```

### Read-only vs stateful tools

By default, `SdkTool` is **not** read-only and **not** concurrency-safe: Plan
mode denies it without an explicit allow path, and the tool orchestrator will
not use `pcntl_fork`. Those are separate capabilities. This is intentional —
`handle()` may write databases, files, or call external APIs even when the
class name looks like a lookup.

- Mutating / stateful tools: keep the default (`isReadOnly() === false`).
- Pure query tools: override `isReadOnly()` and return `true` so Plan mode may
  auto-approve them. They still execute sequentially in the caller process.
- Fork-safe pure query tools: also override `isConcurrencySafe()` and return
  `true`. Do this only when the tool and every dependency are safe after
  `pcntl_fork`; framework containers, database connections, and cURL clients
  generally are not.

A stateful or non-concurrency-safe tool is an execution barrier, so later calls
wait for its effects in the same model response.

Every public run gets a fresh loop, message history, cost tracker, and tool
registry. `SdkTool` does not require a clone implementation, so cloneable
instances are copied for isolated child registries but non-cloneable instances
may remain shared. Keep custom tools stateless, construct one instance per
concurrent run, or synchronize mutable state when running under Swoole,
RoadRunner, or another long-lived worker. The SDK runtime and its in-memory
rate-limit bookkeeping are process-local; worker-wide coordination requires
process isolation or an application-owned shared store.

The loop also has a narrow repetition guard: after three consecutive identical
valid tool-error batches it stops retrying that action and performs one final
no-tool request. This complements, rather than replaces, the configured
`maxTurns` budget.

For `ToolInputSchema` backed only by a JSON Schema, a malformed self-contained
schema is deliberately treated as an allow-through compatibility fallback so a
single broken MCP schema does not disable every call to that tool. External
references are still rejected without I/O. `structured()` response schemas use
the stricter validation path and reject unusable schemas before the model call.

```php
class ShoppingCart extends SdkTool
{
    private array $items = [];

    public function handle(array $input): string
    {
        $this->items[] = $input['item'];
        return 'Cart: ' . implode(', ', $this->items);
    }

    // Default is already false (non-read-only). Explicit override is optional
    // but documents intent: state must stay in the parent process.
    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
```

## Custom Skills (SdkSkill)

Skills are named prompt templates the agent can invoke. Unlike tools (which execute PHP code), skills inject instructions that guide the agent's behavior.

```php
use HaoCode\Sdk\SdkSkill;

$skill = new SdkSkill(
    name: 'security-review',
    description: 'Review code for OWASP vulnerabilities',
    prompt: 'Review $ARGUMENTS for injection, XSS, auth bypass, and other OWASP Top 10 issues.',
    // Full tool names and/or Claude-style patterns (enforced at call time).
    allowedTools: ['Read', 'Grep', 'Bash(cargo:*)'],
    model: 'haiku',                   // optional: Anthropic alias or full model id
    context: 'inline',                // optional: inline or isolated fork
);

$result = HaoCode::query('Review auth.php for security', new HaoCodeConfig(
    allowedTools: ['Skill', 'Read', 'Grep', 'Bash'],
    skills: [$skill],
));
```

### Skill vs Tool

| | SdkSkill | SdkTool |
|---|---|---|
| What it is | Named prompt template | Executable PHP code |
| How agent uses it | Invokes via `SkillTool`, gets expanded prompt | Calls `handle()` directly |
| `$ARGUMENTS` support | Yes | No |
| Appears in system prompt | Yes (Available skills list) | Yes (API tools list) |
| Can restrict tools | Yes (`allowedTools`, including input patterns) | No |
| Isolated execution | Yes (`context: 'fork'`) | No |
| Returns | Expanded prompt text | `handle()` return string |

File-based skills are loaded from `~/.haocode/skills` and
`<project>/.haocode/skills`. Additional catalogs must be opted in explicitly:

```php
$config = new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: ['Skill', 'Read', 'Grep'],
    skillDirectories: [getenv('HOME').'/.claude/skills'],
    recursiveSkillDiscovery: true,
);
```

The system prompt keeps an exact-name index for large catalogs while budgeting
descriptions. The `Skill` tool supports paginated `list` and filtered `search`
actions. Once a skill is invoked, its resolved absolute directory is included
in the tool result so relative references are read from the correct package.

#### Skill tool scope and model overrides

`allowedTools` is enforced for the rest of the current user turn (inline) or for
the entire forked child run (`context: 'fork'`). Entries may be:

| Spec | Meaning |
|---|---|
| `Read` | Full access to that tool for the skill scope |
| `Bash(cargo:*)` | `Bash` only for a **single simple** command whose text is `cargo` or starts with `cargo ` |
| `Read(src/**)` | Path-constrained tools match against `file_path` / `path` |

Patterns are **never** stripped to a wider grant. `Bash(cargo:*)` does not
become unrestricted `Bash`. Bash patterns also **fail closed** on shell
chaining and expansion: `;`, `&&`, `||`, `|`, backticks, `$()`, redirections
(`>`, `<`), newlines, comments (`#`), and `ENV=value cmd` prefixes are
rejected even when the string begins with the allowed prefix (so
`cargo test; rm -rf /` is denied). Multiple inline skills intersect their
capability lists (the more restrictive combination wins). File-based
frontmatter uses the same format (`allowed-tools: Bash(cargo:*) Read`).

`model` overrides use a provider-aware resolver (same alias rules as Agent
tools):

| Value | Anthropic | `openai` / `openai_chat` |
|---|---|---|
| `haiku` / `sonnet` / `opus` | Expand to the catalog model id | Reject (fail closed) |
| Full model id (e.g. `claude-…`, `gpt-…`) | Pass through | Pass through |
| `inherit` / empty | Keep parent model | Keep parent model |

The override applies for the rest of that turn (inline) or only inside the
child agent (fork), then the parent model is restored. Standalone `!` shell
directives in a skill become normal `Bash` tool requests; they still pass
through tool permissions, hooks, and skill capability scope.

---

## Streaming Messages

`HaoCode::stream()` yields `Message` objects with these types:

| Type | Fields | Description |
|------|--------|-------------|
| `turn` | `$msg->turnNumber` | A new agent turn started |
| `text` | `$msg->text` | Streaming text delta |
| `tool_start` | `$msg->toolName`, `$msg->toolInput` | Tool execution began |
| `tool_result` | `$msg->toolName`, `$msg->toolOutput`, `$msg->toolIsError` | Tool completed |
| `result` | `$msg->text`, `$msg->usage`, `$msg->cost`, `$msg->sessionId`, `$msg->terminationReason` | Final result with a typed `RunTerminationReason` |
| `error` | `$msg->error` | An error occurred |
| `interrupt` | `$msg->interrupt`, `$msg->sessionId` | Generation paused for human input; no `result` follows in that stream |
| `auto_decision` | `$msg->sessionId`, `$msg->interruptId`, `$msg->actionId`, `$msg->toolName`, `$msg->toolInput`, `$msg->decision`, `$msg->source`, `$msg->riskLevel`, `$msg->reason` | An action was decided automatically by the smart HITL policy |

```php
foreach (HaoCode::stream('Refactor the auth module', new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: ['Read', 'Edit', 'Grep', 'Glob'],
    permissionMode: 'bypass_permissions',
)) as $msg) {
    if ($msg->type === 'text') {
        echo $msg->text;  // stream to browser, worker output, or logs
    }

    if ($msg->type === 'tool_start') {
        Log::info("Agent using tool: {$msg->toolName}");
    }

    if ($msg->isResult()) {
        DB::table('usage')->insert([
            'tokens' => $msg->usage['input_tokens'] + $msg->usage['output_tokens'],
            'cost'   => $msg->cost,
        ]);
    }

    if ($msg->isError()) {
        Log::error("Agent error: {$msg->error}");
    }
}
```

### Durable human-in-the-loop

HITL never reads stdin and does not call a blocking PHP approval callback. A
non-streaming call throws `HumanInterruptException`; a streaming call yields one
`interrupt` message and ends. The host stores the interrupt ID and resumes it later:

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterruptException;

$config = new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: ['Read', 'Write', 'Bash', 'AskUserQuestion'],
    ephemeral: false,
    interruptOn: [
        'Bash' => [
            'allowedDecisions' => ['approve', 'edit', 'reject'],
            'description' => 'Review shell command',
        ],
        'Write' => true,
    ],
    enableAskUser: true,
);

try {
    HaoCode::query('Inspect the app and write report.md', $config);
} catch (HumanInterruptException $e) {
    $interrupt = $e->interrupt; // safe to serialize with toArray()

    $result = HaoCode::resumeInterrupt(
        sessionId: $interrupt->sessionId,
        interruptId: $interrupt->id,
        decisions: array_map(
            fn ($action) => HumanDecision::approve($action->id),
            $interrupt->actions,
        ),
        config: $config,
    );
}
```

Available decisions are:

- `HumanDecision::approve($actionId)` executes the hook-normalized checkpoint input.
- `HumanDecision::edit($actionId, $input)` revalidates new arguments without changing the tool name.
- `HumanDecision::reject($actionId, $feedback)` skips execution and returns error feedback to the model.
- `HumanDecision::respond($actionId, $value)` skips execution and returns the host value as a successful tool result.

`AskUserQuestion` actions allow only `respond` and `reject`. A response is
`['status' => 'answered', 'answers' => [...]]` or
`['status' => 'cancelled', 'answers' => []]`. One answer entry is required per
question; optional questions may use `null`. Hard deny rules and hooks remain
authoritative. Sessions are claimed under a file lock; a `resolving` checkpoint
is not automatically retried after a process crash because side effects may have
already occurred. If resume fails after the claim, the session appends an
`interrupt_failed` entry (error message, `side_effect_status`, optional partial
results) so the interrupt does not remain stuck in `resolving`.

Foreground child-agent interrupts bubble directly. Background agents and team
members enter `waiting_for_input`; `TeamAwait` and `TeamCollect` surface their
pending interrupt. Resolve the child interrupt using its own `sessionId`, then
collect the team again. When the child interrupt has no `sourceAgentId`, the
run snapshot's `background_owner_agent_id` is still used to mark the outer
background owner completed after the full parent chain finishes.

Branching a session (`SessionManager::branchSession` / conversation branch
helpers) is refused while any interrupt is still `pending` or `resolving`, so
HITL checkpoints are never duplicated across branches.

For a streaming host, resume without buffering the continued run:

```php
foreach (HaoCode::streamResumeInterrupt(
    $interrupt->sessionId,
    $interrupt->id,
    [HumanDecision::approve($interrupt->actions[0]->id)],
    $config,
) as $message) {
    if ($message->type === 'text') {
        echo $message->text;
    }
    if ($message->isInterrupt()) {
        // Persist the new checkpoint; this run paused again.
    }
}
```

#### Smart HITL modes

`hitlMode` controls how much of the approval flow is automated. The defaults
are also available as `hitl_mode` / `hitl_review_model` in `config/haocode.php`
(`HAOCODE_HITL_MODE` / `HAOCODE_HITL_REVIEW_MODEL`).

| Mode | Behavior |
|------|----------|
| `'ask'` | Every configured action interrupts for a human decision — the behavior described above. |
| `'smart'` (default) | Rules fast-path routine actions, gray-area actions are reviewed by a model, and only dangerous actions interrupt for a human decision. `hitlReviewModel` selects the review model; `null` reuses the current run's model. |
| `'auto'` | Tool interrupts are suppressed entirely; `AskUserQuestion` still interrupts for a human response. |

`hitlMode` is nullable: `null` (the constructor default) means "not chosen
explicitly" and resolves from the `haocode.hitl_mode` config value /
`HAOCODE_HITL_MODE` environment variable, whose own default is `'smart'`. An
explicit `'ask'` is always honored as `'ask'`.

In `'smart'` and `'auto'` mode, every automatic decision is reported on the
stream as an `auto_decision` message built by `Message::autoDecision()` and
detected with `$msg->isAutoDecision()`. The message contract is frozen at nine
fields:

| Field | Type | Domain |
|-------|------|--------|
| `sessionId` | `string` | Owning session |
| `interruptId` | `string` | Interrupt checkpoint the action belongs to |
| `actionId` | `string` | Action that was decided |
| `toolName` | `string` | Tool that was about to run |
| `toolInput` | `array` | Normalized tool input that was reviewed |
| `decision` | `string` | `approve`, `reject`, or `escalate` (unknown values normalize to `escalate`) |
| `source` | `string` | `rule`, `review`, `sandbox`, or `batch` (unknown values normalize to `rule`) |
| `riskLevel` | `string` | `low`, `medium`, `high`, or `critical` (unknown values normalize to `medium`) |
| `reason` | `string` | Human-readable rationale |

`escalate` means the policy declined to decide and the action falls back to the
normal human-interrupt flow. Escalation reasons carry a prefix family matching
their source: `rule:`, `review:`, or `batch:`.

In `'smart'` mode, two fast paths settle actions without the guardian model:

- **Sandbox containment** (codex `OnRequest` parity): a gray-area `Bash`
  action that will genuinely execute inside the configured sandbox is
  approved directly, with `source: 'sandbox'`, `riskLevel: 'low'`, and reason
  `sandbox:contained: ...`. Containment requires sandbox mode `'full'` on an
  isolating provider (`native`, `tokimo`, or `agentrun`); the `local`
  provider is only a working-directory jail and does not qualify. Red-line
  and ask-level actions are never sandbox-exempted.
- **User-saved allow rules** (codex "always allow" parity): when
  `hitlAllowlistPath` points to a JSON rule file, a `Bash` action matching a
  saved rule is approved before the rule classifier runs — with
  `source: 'rule'` and reason `allowlist:user_rule: User-saved allow rule.`
  (prefix hits append `(prefix: <tokens>)` before the final period) — even
  when the classifier would red-line it (user sovereignty). Matching first
  tries whole-command equality against exact/legacy rules; otherwise the
  command is split into segments on `&&` / `||` / `;` / `|` / newlines
  (quote-aware), leading `VAR=value` assignments are stripped per segment,
  and every segment must hit a v2 rule — an exact rule by trimmed equality or
  a prefix rule by leading-token equality — so `git commit && rm -rf /` never
  slips through a `git commit` prefix. A missing, corrupt, or unknown-version
  file loads as an empty allowlist and never throws. The file format is
  frozen (version 1 keeps exact-match-only entries; version 2 adds typed
  `exact` / `prefix` rules):

  ```json
  {
    "version": 2,
    "rules": [
      {"type": "prefix", "tokens": ["git", "commit"], "addedAt": "2025-01-01T00:00:00+00:00", "source": "user"},
      {"type": "exact", "command": "node scripts/foo.js --flag", "addedAt": "2025-01-01T00:00:00+00:00", "source": "user"},
      {"command": "make deploy", "addedAt": "2025-01-01T00:00:00+00:00", "source": "user"}
    ]
  }
  ```

```php
foreach (HaoCode::stream('Deploy the release', $config) as $msg) {
    if ($msg->isAutoDecision()) {
        Log::info("{$msg->decision} ({$msg->source}/{$msg->riskLevel}): {$msg->reason}");
    }
}
```

---

## Plan Mode

`permissionMode: 'plan'` starts a read-only phase: the model explores and designs
an approach, and every tool that modifies files or state is denied. The one
exception is the session's plan file, `<session path>/plans/<session id>.md`,
which `Write` and `Edit` may target so the model has somewhere to draft. The path
is matched canonically and exactly; nothing else becomes writable.

The model ends the phase by calling `ExitPlanMode`, and what happens next depends
on whether a human can be asked.

| Session | `planExitPolicy` | What `ExitPlanMode` does |
|---|---|---|
| durable (`ephemeral: false`) | `'approval'` (default) | Raises a human interrupt for `ExitPlanMode`. On `approve` the permission mode switches to `default` and the plan is injected back as an approved plan; `reject` and `respond` leave the run in plan mode |
| ephemeral | `'approval'` | Nothing can approve, so the plan is returned and the run ends with `RunTerminationReason::PlanReady`. `QueryResult->text` is the plan |
| any | `'auto'` | Switches to `default` and injects the plan, with no approval step |

The durable path reuses the ordinary interrupt mechanism, so
`interruptOn['ExitPlanMode']` is armed for you when the run starts in plan mode.
Set it to `false` to opt out, which falls back to handing the plan back.

```php
try {
    $result = $haoCode->query('Design the billing migration', new HaoCodeConfig(
        permissionMode: 'plan',
        ephemeral: false,
    ));
} catch (HumanInterruptException $e) {
    // One action, named ExitPlanMode, carrying the plan for review.
    $result = $haoCode->resumeInterrupt(
        $e->interrupt->id,
        [HumanDecision::approve($e->interrupt->actions[0]->id)],
    );
}
```

Two things to know. The mode switch is in-memory and is not written to the run
snapshot, so a later `resume()` of the same session should pass
`permissionMode: 'default'` itself. And a nested agent never inherits an
escalating policy: it hands its plan back to its caller instead of changing any
permission mode.

`RunTerminationReason::PlanReady` is a new case on an `@api` enum. A host with an
exhaustive `match` over termination reasons must add an arm for it.

---

## Agent Teams

Enable the built-in team tools when one query needs several focused agents:

```php
$result = HaoCode::query(
    'Create a research team, wait for every member, then summarize the evidence.',
    new HaoCodeConfig(
        cwd: __DIR__,
        allowedTools: [
            'TeamCreate', 'TeamAwait', 'TeamCollect', 'TeamList', 'TeamDelete',
            'Read', 'Glob', 'Grep',
        ],
        permissionMode: 'bypass_permissions',
    ),
);
```

`TeamCreate` starts the members in the configured `cwd` with the parent run's
model and provider settings. Member prompts are optional; descriptive role
names provide compact defaults for larger teams, and `default_agent_type` can
select one agent type for all members without repeating it. Background agents
and teams require `pcntl_fork`; `posix` enables process liveness checks and stop
signals. `TeamAwait` blocks until every member has returned a result or failed
and emits one structured JSON aggregate. `TeamCollect`
returns the same aggregate immediately, which is useful for progress checks.
Set `read_only: true` in `TeamCreate` when members must be prevented from
mutating files, including through Bash commands; this is enforced by the
permission layer rather than relying on prompts.
Use `SendMessage` only while a member is `running` or `idle`, and call
`TeamDelete` when the team is no longer needed.

Agent IDs and team names are restricted to filesystem-safe identifiers and
cannot overwrite an existing background run. Their manifests, mailboxes, and
task records are stored below the configured runtime storage path in
`app/haocode/background-agents`, `app/haocode/teams`, and `app/haocode/tasks`.

### Completion delivery

A background agent or a `run_in_background` Bash command reports itself when it
finishes: at the next turn boundary the root run injects a
`# Background task updates` block naming each finished task and carrying its
result. The model does not have to poll `TaskGet` to notice that work is done.

Delivery happens exactly once. A completion is claimed under the state lock as
it is announced, and reading a terminal result through `TaskGet` claims it the
same way, so a result is never reported twice. Only a root run announces
completions, and only for tasks owned by its own session, so a nested agent
never sees a sibling's results.

Background Bash output up to 8 KB is inlined in the notice, which also reaps the
task. A larger output is announced by size only; fetch it with the `BashOutput`
tool, which takes the `task_id` from the start message. `BashOutput` must be in
`allowedTools` alongside `Bash` for the model to be able to call it.

Background capacity is admitted atomically per owner run. The states `pending`,
`running`, `idle`, and `waiting_for_input` all occupy a slot. Existing state
without `owner_run_id` remains readable and counts against a legacy global
pool; no file migration is required. Mailbox rejection is atomic and returns
the stable `background_busy` error without changing the queue or pending count.

| Config key | Environment variable | Default |
|---|---|---:|
| `background_agent_max_active_per_run` | `HAOCODE_BACKGROUND_AGENT_MAX_ACTIVE_PER_RUN` | 8 |
| `background_agent_mailbox_max_messages` | `HAOCODE_BACKGROUND_AGENT_MAILBOX_MAX_MESSAGES` | 128 |
| `background_agent_message_max_bytes` | `HAOCODE_BACKGROUND_AGENT_MESSAGE_MAX_BYTES` | 65,536 |
| `background_agent_mailbox_max_bytes` | `HAOCODE_BACKGROUND_AGENT_MAILBOX_MAX_BYTES` | 1,048,576 |

All four values must be positive. Invalid values fail when the SDK runtime is
assembled rather than being silently clamped.

---

## Multi-turn Conversations

`Conversation` maintains persistent context across multiple `send()` calls:

The system prompt is frozen when the conversation first runs. Volatile Git
status is appended to the initial user turn, and later messages only extend the
history. This preserves the byte-stable prefix required by DeepSeek's automatic
prompt cache; memory, instruction, output-style, and system-prompt changes take
effect in a new conversation rather than rewriting an active prefix.

```php
$conv = HaoCode::conversation(new HaoCodeConfig(
    allowedTools: ['Read', 'Write', 'Edit', 'Glob', 'Grep', 'MyDatabaseTool'],
    permissionMode: 'bypass_permissions',
    ephemeral: false,
    tools: [new MyDatabaseTool()],
    maxTurns: 20,
));

// Turn 1 — agent creates a file
$r1 = $conv->send('Create a User model with name, email, and password');
echo $r1->text;

// Turn 2 — agent remembers the User model from turn 1
$r2 = $conv->send('Add email validation and password hashing');
echo $r2->text;

// Turn 3 — agent knows about both previous changes
$r3 = $conv->send('Write a PHPUnit test for the User model');
echo $r3->text;

// Metadata
echo $conv->getTurnCount();   // 3
echo $conv->getCost();        // cumulative cost
echo $conv->getSessionId();   // session ID

// Streaming within conversation
foreach ($conv->stream('Add a factory for the User model') as $msg) {
    if ($msg->type === 'text') echo $msg->text;
}

$conv->close();  // no more sends allowed
```

---

## Session Resume & Continue

Sessions are persisted as JSONL files. Resume from any process:

```php
// First process — create a durable text-only session
$config = new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: [],
    ephemeral: false,
);
$result = HaoCode::query('Remember that the project codename is ORBIT.', $config);
$sessionId = $result->sessionId;  // save this

// Later process — resume where we left off (tools use the session transcript cwd)
$conv = HaoCode::resume($sessionId, $config);
$conv->send('What is the project codename?');

// Or just continue the latest session for the configured directory
$conv = HaoCode::continueLatest(__DIR__, $config);
$conv->send('What were we working on?');
```

If the PHP process cwd differs from the session's recorded project root,
`resume()` still targets the transcript cwd (not the process cwd). Passing a
different config `cwd` throws unless `allowCwdOverride: true`. See
[resume() / continueLatest()](#resume--continuelatest).

### Internal run state storage

Non-ephemeral runs record schema-versioned `run_event` and incremental
`run_checkpoint` entries alongside the existing JSONL transcript. These
provider-neutral records power read-only export/replay internally; they do not
replace `SessionManager` as the transcript authority, and existing JSONL
sessions remain readable. Branching copies settled conversation history but
does not copy execution events or checkpoints into the new run.

The internal export view is always redacted: message bodies, model text, tool
results, raw errors, and unknown future content fields are masked while
operational ids, states, hashes, counters, and token usage remain available.
Replay still reads the raw internal facts. Run-state JSONL/SQLite files can
therefore contain sensitive application data and belong in a host-protected
directory with an appropriate retention policy.

The default is `HAOCODE_RUN_STORE=jsonl`. Set
`HAOCODE_RUN_STORE=sqlite` to enable the transactional P2 recovery protocol,
and optionally set `HAOCODE_RUN_DATABASE_PATH` (the default is
`storage/app/haocode/run-state.sqlite`). SQLite mode requires `ext-pdo_sqlite`
and records tool idempotency keys, claims, leases and fencing tokens. Claims
are committed before lifecycle hooks/tool code; terminal results and the
post-result checkpoint are committed together. A mutating call that was
started but whose result cannot be confirmed becomes `unknown` and is not
automatically retried. Read-only calls may be reclaimed after lease expiry.
The protocol is regression-tested with independent PHP processes receiving a
real `SIGKILL` before execution, after a separately persisted external effect,
and after result commit. This validates the Store contract; it does not provide
a Worker daemon or Queue adapter.

This protocol is at-least-once, not exactly-once. SQLite mode disables parallel
tool forks to preserve one process/connection transaction ownership; JSONL mode
keeps the existing parallel execution behavior. Worker daemon and queue adapter
productization are outside this SDK boundary.

---

## Structured Output

Extract typed data from AI responses:

```php
// Classify a support ticket
$ticket = HaoCode::structured(
    'Classify: "I was charged twice for my subscription"',
    [
        'type' => 'object',
        'properties' => [
            'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical', 'account']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
            'summary'  => ['type' => 'string'],
            'action'   => ['type' => 'string'],
        ],
        'required' => ['category', 'priority', 'summary'],
    ],
    new HaoCodeConfig(
        ephemeral: false,          // durable: schema retries share one session
        structuredMaxRetries: 2,
        allowedTools: [],
    ),
);

echo $ticket->category;     // 'billing'
echo $ticket->priority;     // 'high'
echo $ticket['summary'];    // 'Customer reports duplicate charge' (ArrayAccess)
$ticket->toArray();          // full array
$ticket->toJson();           // JSON string

// Access underlying QueryResult for cost/usage
echo $ticket->queryResult->cost;
```

With durable config (`ephemeral: false`) or an explicit budget/session, every
schema retry reuses the same conversation so tool side effects and message
history are not split across multiple session files.

---

## Multimodal Input

Pass images to `query()`, `stream()`, or `Conversation::send()` alongside text prompts. The SDK translates them into provider-native image blocks automatically.

### Via HaoCode::query() / stream()

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

// Local file path (auto-detected MIME + base64)
$result = HaoCode::query(
    'Describe this photo',
    new HaoCodeConfig(images: ['/path/to/photo.jpg'])
);

// Multiple images
$result = HaoCode::query(
    'What do these two diagrams have in common?',
    new HaoCodeConfig(images: ['/path/to/diagram1.png', '/path/to/diagram2.png'])
);

// Streaming also works
foreach (HaoCode::stream('Transcribe this screenshot', new HaoCodeConfig(
    images: ['/path/to/screenshot.png']
)) as $event) {
    echo $event->text;
}
```

### Via Conversation

```php
$conversation = HaoCode::conversation();

$conversation->send('Describe this image', ['/path/to/photo.jpg']);

// Streaming conversation with images
foreach ($conversation->stream('Any logos here?', ['/path/to/brochure.pdf.png']) as $event) {
    echo $event->text;
}
```

### Image source formats

The `images` array accepts four formats. They can be mixed in the same call:

| Format | Example |
|--------|---------|
| Local file path | `'/path/to/photo.jpg'` |
| Remote URL | `'https://example.com/image.png'` |
| Data URI | `'data:image/png;base64,iVBORw0KGgo...'` |
| Pre-built block | `['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => '...']]` |

String-based local files and data URIs are limited to 5 MiB after decoding and
must be JPEG, PNG, GIF, or WebP. Local files are checked with `fileinfo` rather
than trusted by extension; data URIs require strict base64. Relative local paths
resolve from the run's configured `cwd` (or the process cwd when no run cwd is
configured). Pre-built blocks are an advanced escape hatch and are passed
through unchanged, so callers constructing them are responsible for equivalent
validation.

File-tool `Read` is not an image-ingestion path: it emits text only and rejects
images or PDFs without extractable text instead of embedding base64 in a tool
result. Use the image arguments above for multimodal model input.

### Manual block assembly

For full control, use `ImageContentBlock` directly:

```php
use HaoCode\Sdk\ImageContentBlock;

// From a local file (returns an Anthropic-shaped image block)
$block = ImageContentBlock::from('/path/to/photo.jpg');

// Resolve a relative path from an explicit project directory
$block = ImageContentBlock::from('assets/photo.jpg', __DIR__);

// From a URL
$block = ImageContentBlock::from('https://example.com/image.png');

// From a data URI
$block = ImageContentBlock::from('data:image/png;base64,iVBORw0KGgo...');

// Build a complete user message with text + images
$content = ImageContentBlock::buildUserContent('Analyze this', [$block]);
```

`buildUserContent()` returns an array of content blocks that the Provider layer sends as a native multimodal message. You do not need to base64-encode files manually; `ImageContentBlock::from()` detects the MIME type and reads the file automatically.

With `ephemeral: false`, the normalized text and image blocks are persisted so
session resume and durable HITL see the original multimodal message. Durable
entries are limited to 32 MiB each and a session transcript to 128 MiB; an
oversized or failed write is reported to the caller.

---

## Abort Controller

Cancel long-running operations from external code:

```php
use HaoCode\Sdk\AbortController;

$abort = new AbortController();

// In a queued job:
$result = HaoCode::query('Refactor the entire codebase', new HaoCodeConfig(
    abortController: $abort,
    allowedTools: ['Read', 'Edit', 'Glob', 'Grep'],
    permissionMode: 'bypass_permissions',
));

// From a signal handler, timeout, or another thread:
$abort->abort();

// Register cleanup callbacks
$abort->onAbort(function () {
    Log::info('Agent operation was cancelled');
});
```

Works with conversations too:

```php
$conv = HaoCode::conversation(new HaoCodeConfig(
    abortController: $abort,
    allowedTools: [],
));
$conv->send('Long running task...');
// $abort->abort() will stop the agent mid-execution
```

Cancellation is checked again after every provider request. If it races with an
otherwise completed response, including the no-tool final answer used after the
tool-turn limit, the operation returns `(aborted)` and does not add that
assistant response to the conversation history.

---

## Cost Tracking

### Per-query cost

```php
$result = HaoCode::query('Analyze this codebase');
echo "Cost: \${$result->cost}";
echo "Input tokens: {$result->inputTokens()}";
echo "Output tokens: {$result->outputTokens()}";
```

### Budget limits

```php
$result = HaoCode::query('Do a big refactoring', new HaoCodeConfig(
    maxBudgetUsd: 5.00,  // shared post-response spending guard
    allowedTools: ['Read', 'Edit', 'Glob', 'Grep'],
    permissionMode: 'bypass_permissions',
));
// Warns at ~80%. Stops before the *next* model call once the shared total
// reaches 100%. The request that crossed the limit may still complete.
```

The built-in estimator has exact pricing for the Claude model IDs in
`ModelCatalog`. Unknown and non-Anthropic models expose
`$result->usage['cost_available'] === false`; `maxBudgetUsd` fails before the
first request when trusted pricing is unavailable. If the Provider returns an
unknown deployment alias for a request whose model was already priced, usage
continues with that validated request-model price. Durable HITL checkpoints
restore cumulative token and cost totals before continuing (including after
snapshot rebuilds of the conversation loop). One process-safe ledger is shared
by the root run, synchronous and background descendants, Team members, forked
skills, and structured-output correction retries.

This is a **shared post-response spending guard**, not a pre-reserved hard
cap: cost is recorded after each model response, so one in-flight call (or
parallel children that all passed the pre-check) can finish slightly over the
limit. On HITL resume the effective limit is
`min(snapshot budget_limit_usd, current config maxBudgetUsd)`. Ledger files
inactive for 90 days are collected lazily; a later durable resume reconstructs
the minimum accumulated spend from its checkpoint.

### Conversation cumulative cost

```php
$conv = HaoCode::conversation(new HaoCodeConfig(allowedTools: []));
$conv->send('Step 1');
$conv->send('Step 2');
echo "Total cost: \${$conv->getCost()}";
```

---

## Credential Pools

`CredentialPool` rotates API keys inside a canonical provider bucket
(`anthropic`, `openai`, or `openai_chat`). Higher-priority credentials are tried
first; equal-priority credentials use round-robin selection. A 429,
`rate_limit_error`, or `overloaded_error` temporarily exhausts the selected key
and retries with another healthy key.

```php
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;

$pool = new CredentialPool(exhaustedTtlSeconds: 60);
$pool->addMany('anthropic', [
    Credential::make(getenv('ANTHROPIC_API_KEY_1') ?: '', priority: 10),
    Credential::make(getenv('ANTHROPIC_API_KEY_2') ?: '', priority: 10),
]);

$result = HaoCode::query('Summarize this incident.', new HaoCodeConfig(
    credentialPool: $pool,
    allowedTools: [],
    ephemeral: true,
));
```

If the selected provider bucket has credentials, a separate `apiKey` is not
required. `RateLimitTracker` exists as an optional in-memory primitive, but
`HaoCodeConfig` does not currently attach one; normal SDK pool failover is driven
by provider errors and the pool's exhaustion TTL. The pool constructor and
`markExhausted()` require finite, non-negative TTL values. A supplied
`retryAfterSeconds` is stored for that credential's current exhaustion only; it
does not change the pool default or other credentials, and invalid values throw
`InvalidArgumentException`.

Credential-pool exhaustion and `RateLimitTracker` state are process-local. In a
multi-worker deployment, workers do not automatically observe one another's
rate-limit state; provide an application-owned shared backend when that
coordination is required. Provider exponential retry delays add bounded jitter
when no `Retry-After` header is supplied, while an explicit server delay is
honored as received.

---

## Combining Tools + Skills

Tools and skills can be used together in a single query:

```php
$result = HaoCode::query('Run a full system health check', new HaoCodeConfig(
    allowedTools: ['Skill', 'Write', 'DatabaseHealthTool', 'CacheHealthTool'],
    permissionMode: 'bypass_permissions',
    // Custom tool — executes PHP code
    tools: [
        new DatabaseHealthTool(),
        new CacheHealthTool(),
    ],
    // Custom skill — injects a prompt template
    skills: [
        new SdkSkill(
            name: 'health-report',
            description: 'Generate a health report',
            prompt: 'Check all systems using the available health tools, then write a report to health-report.md.',
        ),
    ],
));
```

---

## Testing

The repository test infrastructure uses `MockAnthropicSse` to exercise the
Anthropic-shaped SDK path without real API calls:

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Services\Api\StreamingClient;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Support\Runtime\SdkRuntime;
use Tests\Support\MockAnthropicSse;

// In your test:
$requests = [];
SdkRuntime::reset();
$app = SdkRuntime::boot();
$app->singleton(StreamingClient::class, function ($app) use (&$requests) {
    return new StreamingClient(
        apiKey: 'test-key',
        model: 'claude-test',
        baseUrl: 'https://mock.test',
        maxTokens: 4096,
        httpClient: MockAnthropicSse::client([
            MockAnthropicSse::textResponse('Mocked response.'),
        ], $requests),
        settingsManager: $app->make(SettingsManager::class),
    );
});

$result = HaoCode::query('Test prompt');
$this->assertStringContainsString('Mocked response', $result->text);
```

`MockAnthropicSse` is a repository test helper, not part of the installed package.
OpenAI Chat Completions streaming uses PHP's native stream wrapper, so a
`MockHttpClient` does not intercept that SSE transport; use a local HTTP fixture
server or an explicitly gated live test for that provider.

`composer test` first enforces the repository's 500-physical-line limit for
every Git-tracked PHP file, then verifies that every ordinary `*Test.php` file
is covered by `phpunit.xml` and runs the complete non-live suite. The file-size
contract has no legacy allowlist and can be run independently with
`composer run quality:file-size`; Linux and Windows CI execute the same check.
Use `composer test:live` for explicitly gated provider tests, or targeted paths such as
`./vendor/bin/phpunit tests/Feature/ContextBuilderTest.php` while working on
internal modules. See `tests/Feature/SdkE2ETest.php` for the current end-to-end
SDK examples.

Provider conformance tests live under `tests/Provider`. The same matrix runs
against Anthropic, OpenAI Responses, and OpenAI Chat for text, tool calls,
multi-turn tools, structured text, thinking, usage, and abort. The fault
catalog under `tests/fixtures/provider-conformance/faults` covers HTTP failures,
provider stream errors, transport interruptions, malformed JSON, oversized SSE
lines, non-object JSON payloads, and idle timeout:

```bash
./vendor/bin/phpunit tests/Provider
```
