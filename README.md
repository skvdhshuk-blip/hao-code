# hao-code

A framework-free PHP Agent SDK for Anthropic, OpenAI Responses, and OpenAI Chat Completions-compatible APIs.

`hao-code` lets PHP applications embed an AI coding agent with tools, skills,
streaming output, multi-turn sessions, structured JSON results, durable human
approval, agent teams, cost tracking, abort control, credential pools, and
isolated runtime storage.

For the complete SDK reference, see [docs/SDK.md](docs/SDK.md).

Looking for a ready-to-use desktop application powered by HaoCode? See
[Hao Work](https://github.com/skvdhshuk-blip/hao-work).

## Requirements

- PHP `^8.1`
- Composer; Symfony `^6.4`, `^7.0`, or `^8.0` components are installed as package dependencies
- Optional `pcntl` and `posix` extensions for background agents and agent teams
- macOS `/usr/bin/sandbox-exec` or Linux `bubblewrap` when using the native local sandbox
- Optional `zstd` and `tar` when installing the Tokimo guest kernel/rootfs
- Optional Linux `bubblewrap`, or KVM plus `cloud-hypervisor` and `virtiofsd`, for the Tokimo backend
- Optional Windows Virtual Machine Platform plus its one-time SYSTEM service for the Tokimo backend

## Install

```bash
composer require sk-wang/hao-code
```

This installs the PHP SDK only. It does not download Tokimo or any native
sandbox binaries.

### Optional Tokimo sandbox

Install the optional cross-platform sandbox now or at any later time:

```bash
# Runner only; use this when you already have a compatible baseRootfs:
vendor/bin/hao-code-sandbox install

# Recommended first-time setup; also downloads the guest kernel/rootfs:
vendor/bin/hao-code-sandbox install --with-runtime

# Show the currently installed runner:
vendor/bin/hao-code-sandbox status
```

Composer has no dependency-specific `require` flag for this, and dependency
package scripts are not executed automatically. For a one-line initial setup,
run:

```bash
composer require sk-wang/hao-code && vendor/bin/hao-code-sandbox install --with-runtime
```

You do not need to install Tokimo separately. The installer downloads the
verified runner and, with `--with-runtime`, the guest kernel/rootfs artifacts
for the current OS/CPU into the user cache. On macOS and Linux, full runtime
setup may ask for `sudo` while extracting the rootfs so its Linux uid/gid
ownership is preserved. From a hao-code source checkout, replace
`vendor/bin/hao-code-sandbox` with `bin/hao-code-sandbox`.

## Quick Start

Set the API key in the process environment:

```bash
export ANTHROPIC_API_KEY=your-api-key
```

Then create `example.php`:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$result = HaoCode::query('Reply with exactly: HaoCode works');

echo $result->text;
```

```bash
php example.php
```

The basic `query()` call does not expose file or shell tools and does not write
a session file. Hao Code reads real process environment variables; it does not load `.env` files by itself.
Applications that already load `.env` may pass the key explicitly. Constructing
`HaoCodeConfig` does not increase authority: its defaults are `allowedTools: []`,
`permissionMode: 'default'`, and `ephemeral: true`. Supplying only connection
settings therefore remains text-only and non-persistent:

```php
$config = new HaoCodeConfig(
    apiKey: getenv('ANTHROPIC_API_KEY') ?: '',
);
```

## Agent & Runner

HaoCode also exposes a reusable `Agent` and `Runner` API for applications that
want to define an agent once and execute it many times.

```php
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\RunOptions;
use HaoCode\Sdk\Runner;

$agent = new Agent(
    name: 'reviewer',
    model: 'claude-sonnet-4',
    allowedTools: ['Read', 'Grep'],
);

$result = Runner::run($agent, 'Review this file', RunOptions::make(cwd: __DIR__));
```

`contextPreset` is explicit. Its default, `'coding'`, preserves the existing
Git, project-instruction, and coding-convention context. Use `'generic'` for a
tool-using non-coding agent; tools, skills, memory, output style, permission,
and provider behavior are unchanged:

```php
$agent = new Agent(
    name: 'support-agent',
    contextPreset: 'generic',
    allowedTools: ['LookupOrder'],
    tools: [new LookupOrderTool()],
);
```

Agents can be composed: one agent can use another as a tool via `Agent::asTool()`.
`HaoCode::query()` and `HaoCode::stream()` remain unchanged and are implemented on
top of `Runner`.

For a less toy-like exercise,
[`examples/architecture-review-council.php`](examples/architecture-review-council.php)
defines three read-only architecture specialists and a lead reviewer. It runs
against this repository, records every delegated tool lifecycle, rejects empty
specialist reports, and prints aggregate usage plus a council verdict. Provider
settings come from the normal SDK Runtime; `ARCH_COUNCIL_MODEL` optionally
overrides the model, `ARCH_COUNCIL_BASE_URL` selects an Anthropic-compatible
endpoint, and `ARCH_COUNCIL_BUDGET_USD` enables a shared budget only when
trusted pricing exists for that model.

[`examples/agent-shapes-lab.php`](examples/agent-shapes-lab.php) exercises five
separate agent contracts against one provider: a custom-tool agent, structured
output, streaming, multi-turn conversation, and Agent-as-Tool delegation. Run
the full matrix or select one with `--shape=...`; each shape has its own output
acceptance gate, so an empty child response cannot be hidden by a successful
parent report.

## What It Provides

| Area | Capability |
| --- | --- |
| Agent execution | One-shot queries, streaming responses, multi-turn conversations, session resume, durable human approval |
| Providers | Anthropic, OpenAI Responses API, OpenAI Chat Completions-compatible gateways |
| Tools | Built-in file, search, patch, shell, web, MCP, task, team, memory, and planning tools, plus custom PHP tools |
| Sandbox | Local workspace, OS-native isolation, optional Tokimo runners, or Alibaba Cloud AgentRun for sandbox-scoped file and shell tools |
| Skills | Prompt-packaged domain guidance through `SdkSkill` |
| Structured output | JSON schema guided responses via `HaoCode::structured()` |
| Multimodal input | Image input via file path, URL, data URI, or pre-built blocks in `query()`, `stream()`, and `Conversation::send()` |
| Runtime control | Working directory, allowed tools, denied tools, permission mode, max turns, max tokens, thinking options |
| Operations | Cost budget, usage metadata, abort controller, callbacks for text/tool/turn events |
| State | Session IDs, conversation handles, memory summary levels, custom memory storage path |
| Reliability | Credential-pool failover on rate limits, provider abstraction, SDK-only runtime without framework dependencies |

The built-in `Read` tool returns text only. It does not return images or PDFs
without extractable text as base64 tool results; pass images through the SDK
image-input APIs instead. File mutation is also fail closed: a failed or partial
`Read` does not authorize `Write` or `Edit`, and an external revision change
requires another complete `Read` before mutation.

## Main APIs

| Need | API |
| --- | --- |
| One-shot query | `HaoCode::query()` |
| Streaming messages | `HaoCode::stream()` |
| Multi-turn conversation | `HaoCode::conversation()` |
| Resume a session | `HaoCode::resume()` |
| Continue latest session | `HaoCode::continueLatest()` |
| Resume human approval | `HaoCode::resumeInterrupt()` |
| Stream approval resume | `HaoCode::streamResumeInterrupt()` |
| Structured JSON result | `HaoCode::structured()` |

## Configuration

Pass `HaoCodeConfig` when you need explicit runtime configuration:

```php
use HaoCode\Sdk\HaoCodeConfig;

$config = new HaoCodeConfig(
    apiKey: getenv('OPENAI_API_KEY') ?: '',
    providerType: 'openai_chat',
    baseUrl: 'https://api.openai.com',
    model: 'gpt-4.1',
    maxTokens: 4096,
    cwd: __DIR__,
    maxTurns: 30,
    permissionMode: 'default',
    allowedTools: ['Read', 'Grep', 'Glob'],
    disallowedTools: ['Bash'],
);
```

If no explicit config is provided, the SDK reads environment and settings
values such as `ANTHROPIC_API_KEY`, `HAOCODE_MODEL`, `HAOCODE_API_BASE_URL`, and
`HAOCODE_MAX_TOKENS`. When the selected provider type is `openai` or
`openai_chat`, `OPENAI_API_KEY` is used if no explicit `apiKey` or matching
provider-entry key is set; it is not an Anthropic fallback.
For `openai` and `openai_chat`, a model must also be supplied explicitly or by
the selected provider entry; the Anthropic default is never sent to those
providers.

`providerType` accepts only `anthropic`, `openai`, and `openai_chat` (plus a
few documented aliases such as `openai_responses` / `chat_completions`). Any
other non-empty value throws at `HaoCodeConfig` construction — unknown types
never silently fall back to Anthropic, so an OpenAI key cannot be sent to an
Anthropic endpoint by typo.

Before it constructs a provider, sandbox, or MCP connection, the SDK preflights
the requested Agent, provider, model, endpoint, tools, sandbox, and permission
mode. After `AgentLoopFactory` assembles the run, the same run guard binds to the
actual `ToolRegistry`; implicit tools such as `AskUserQuestion` therefore cannot
fall outside the manifest. The guard runs again for every runtime setting
mutation and immediately before every provider request. Unsupported changes are
rolled back atomically. Unknown capabilities on new models or custom gateways
remain allowed for compatibility. `oauthBearer: true` is supported by the
Anthropic wire adapter only; selecting either OpenAI wire format with that
option fails before provider I/O.

The runtime `Config` tool treats `active_provider` as one connection identity:
provider type, key, model, endpoint, and token limits come from the newly
selected provider rather than leaking across from the old one. Run-level OAuth
mode and custom headers remain explicit; an incompatible switch is rejected and
rolled back instead of silently weakening authentication. Credential pools and
cost tracking also resolve the current provider on each request. Runtime
`api_base_url` changes may change only the path on the current HTTP(S) origin;
configure and select a named provider to change hosts.

For `deepseek-v4-flash`, enabling thinking sends DeepSeek's explicit thinking
contract. A thinking budget of `32000` or more selects maximum reasoning effort,
and HaoCode preserves `reasoning_content` across multi-turn tool calls.

For Anthropic models that use adaptive thinking (including Opus 4.8 and current
Claude 5 IDs), `thinkingBudget` maps to effort tiers (`low` / `medium` /
`high` / `max`) unless settings `effort_level` is set explicitly. Manual
extended-thinking models still use `thinkingBudget` as a token budget.

Tools, permission bypass, and durable storage are independent opt-ins. An
unattended full agent must state all three explicitly, for example
`allowedTools: ['*']`, `permissionMode: 'bypass_permissions'`, and
`ephemeral: false`. Do this only inside a trust boundary appropriate for the
tools being exposed.

For human-in-the-loop runs, `hitlMode` selects the approval mode. The default
is `'smart'` (resolved from `haocode.hitl_mode` / `HAOCODE_HITL_MODE` when the
constructor value is `null`): routine actions are fast-pathed by rules,
gray-area actions are reviewed by a model (`hitlReviewModel`, defaulting to the
run's model), and only dangerous actions interrupt for a human decision.
`'ask'` interrupts every configured action, and `'auto'` suppresses tool
interrupts entirely. Every automatic decision surfaces on the stream as an
`auto_decision` message. In `'smart'` mode, two fast paths settle `Bash`
actions without the guardian model: sandbox containment (a gray-area command
that will run inside an isolating sandbox is approved directly) and a
user-saved allow list pointed to by `hitlAllowlistPath` (exact- and prefix-match
v1+v2 rules; every segment of a compound command must match, so
`git commit && rm -rf /` never slips through). See
[Smart HITL modes](docs/SDK.md#smart-hitl-modes) in the SDK reference.

> **v1.8.0 behavior change:** `v1.7.0` constructed `HaoCodeConfig` with all
> tools, permission bypass, and durable storage by default. Existing trusted
> callers that need the old behavior must now opt in to those three settings
> explicitly.

## Sandbox Runtime

Use a sandbox when the agent needs file or shell tools but must not mutate the
PHP host project directory. Sandbox mode replaces `Read`, `Write`, `Glob`, and
`Grep` with sandbox-scoped tools. Set `mode: 'full'` to also replace `Bash` with
a sandbox-scoped shell. Sandbox configuration disables `Edit`, `apply_patch`,
`NotebookEdit`, `LSP`, worktree tools, `Agent`, and `SendMessage`. Other host-only
tools, including task/team tools, are not sandbox replacements; use an
explicit `allowedTools` list as shown below and omit them unless needed. Legacy
cron tool classes are not registered by the default runtime because no prompt
execution driver is wired.

The replacement boundary is fixed by mode:

| Tool family | `filesystem` | `full` | Boundary |
| --- | --- | --- | --- |
| `Read`, `Write`, `Glob`, `Grep` | Sandbox | Sandbox | All file reads/searches use the sandbox backend |
| `Bash` | Disabled | Sandbox | Only `full` runs shell commands inside the sandbox |
| `Edit`, `apply_patch`, `NotebookEdit`, `LSP`, worktree, `Agent`, `SendMessage` | Disabled | Disabled | Not sandbox replacements and unavailable while sandboxed |
| task/team, custom, and MCP tools | Host-side only | Host-side only | Never implicitly moved into the sandbox; allow explicitly only when the host wants them |

An explicit request for a disabled sandbox tool fails before the sandbox is
created. `allowedTools: ['*']` means all tools available in the selected
sandbox mode; it does not require unavailable host-only tools or `Bash` in
`filesystem` mode.

Overwriting an existing sandbox file also requires a complete current `Read`.
Local, native, and Tokimo backends compare the receipt while publishing the
replacement atomically. AgentRun rechecks the remote bytes immediately before
writing, but its file API has no conditional-write primitive, so that remote
check cannot eliminate a concurrent change between the check and write.

The read-before-write receipt is committed only when the current model tool
batch becomes visible to the model. A `Read` and a `Write`/`Edit` in the same
tool batch cannot authorize one another; issue the mutation in the next batch.

Host and sandbox text reads share the same bounds: offset up to 1,000,000,
limit up to 10,000 lines, and at most 1,000,000 bytes for one line or the
rendered result.

AgentRun `Write` results expose `metadata['writeSafety'] === 'recheck_only'`
and `metadata['conditionalWrite'] === false`. Local, Native, and Tokimo writes
use the stronger conditional revision-aware path.

Choose the backend by the isolation boundary you need:

| Backend | Hosts | Use it for |
| --- | --- | --- |
| `local()` | Any supported PHP host | File workspace isolation without untrusted `Bash`; shell commands still run as normal host processes |
| `native()` | macOS and Linux | Lightweight local process isolation through Seatbelt or bubblewrap |
| `tokimo()` | macOS arm64, Linux amd64/arm64, Windows amd64 | Recommended cross-platform boundary for agent-generated or untrusted shell commands; installed separately on demand |
| `agentRun()` | Any host with AgentRun access | Remote cloud isolation when commands and files must stay off the PHP host |

Sandbox `Bash` captures at most 100,000 bytes. Native, Tokimo, and AgentRun
apply that bound independently to stdout and stderr; Local uses one shared budget
across both streams. `outputLimited` uses exit code `1`, timeout uses `124`, and
abort uses `130`; normal sandbox Bash execution metadata includes all three
flags.

AgentRun command HTTP bodies and Tokimo runner JSONL responses also have a
2 MiB transport limit before JSON or base64 decoding. This protects PHP memory;
it does not raise the 100,000-byte command-output limit. An oversized transport
response returns the same `outputLimited: true`, exit-code `1` result. Tokimo
also discards the oversized runner process, then starts a clean one for the next
command while keeping the sandbox workspace.

For a portable full sandbox, prefer `tokimo()`. It is intentionally not part of
the default Composer install; run
`vendor/bin/hao-code-sandbox install --with-runtime` before first use or at any
later time. Use `local()` when only sandbox-scoped file tools are required.

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\Sandbox\SandboxConfig;

$result = HaoCode::query('Review this project and write notes to notes.md', new HaoCodeConfig(
    cwd: __DIR__,
    sandbox: SandboxConfig::local(
        mode: 'filesystem',  // Read/Write/Glob/Grep only; Bash disabled
        sync: 'upload-cwd',  // copy cwd snapshot into /workspace
    ),
    allowedTools: ['Read', 'Write', 'Grep', 'Glob'],
    permissionMode: 'bypass_permissions', // isolated workspace, unattended run
));
```

`local()` isolates the workspace path but executes `mode: 'full'` commands as a
normal host process. For agent-generated commands, use the native backend:

```php
$config = new HaoCodeConfig(
    cwd: __DIR__,
    sandbox: SandboxConfig::native(
        sync: 'upload-cwd',
        network: 'blocked', // opt in with "allow-all" when the task needs it
    ),
    allowedTools: ['Read', 'Write', 'Grep', 'Glob', 'Bash'],
    permissionMode: 'bypass_permissions', // native sandbox contains mutations
);
```

The native backend uses macOS Seatbelt or Linux bubblewrap, exposes only the
sandbox workspace for writes, removes inherited secrets from the command
environment, and blocks networking by default. It fails closed when the native
engine is unavailable. Linux hosts must install `bubblewrap`; macOS uses the
system `/usr/bin/sandbox-exec` executable.

### Tokimo cross-platform sandbox

`SandboxConfig::tokimo()` uses an optional native runner for macOS arm64, Linux
amd64/arm64, or Windows amd64. Neither the runner nor the larger VM images are
part of the Composer package. Install the runner only, or install the complete
runtime in one step:

```bash
vendor/bin/hao-code-sandbox install
vendor/bin/hao-code-sandbox install --with-runtime

# Check whether a runner is available:
vendor/bin/hao-code-sandbox status
```

Both install commands are safe to run later, after the original
`composer require`. Files are SHA-256 verified and stored in the user's cache.
Hao Code reports the same install command if `tokimo()` is used before the
runner is installed. From a source checkout, use `bin/hao-code-sandbox`.

The `--with-runtime` command prints the resulting `baseRootfs` directory. Pass
that path to the SDK:

```php
$baseRootfs = getenv('HAOCODE_SANDBOX_ROOTFS');
if (! is_string($baseRootfs) || $baseRootfs === '') {
    throw new RuntimeException('Set HAOCODE_SANDBOX_ROOTFS to the setup output.');
}

$config = new HaoCodeConfig(
    cwd: __DIR__,
    sandbox: SandboxConfig::tokimo(
        baseRootfs: $baseRootfs,
        network: 'blocked',
    ),
    allowedTools: ['Read', 'Write', 'Grep', 'Glob', 'Bash'],
    permissionMode: 'bypass_permissions', // the sandbox contains mutations
);
```

Hao Code keeps one sandbox session alive for the SDK run, so the VM and its
filesystem are shared across commands. On Linux, Tokimo uses its micro-VM backend
when KVM and its VM helpers are available and otherwise falls back to bubblewrap.
Install `cloud-hypervisor` and `virtiofsd` before running the setup command to
link the micro-VM helpers into the cache; install `bubblewrap` for the fallback.
On Windows, install the downloaded `haocode-sandbox-svc-windows-amd64.exe` once
with administrator rights before using the Hyper-V backend.

### Alibaba Cloud AgentRun

`SandboxConfig::agentRun()` uses Alibaba Cloud AgentRun as a remote temporary
filesystem and execution environment. Use it when the PHP server should not touch
local files or run untrusted commands locally.

```bash
export AGENTRUN_ACCOUNT_ID=your-account-id
export AGENTRUN_API_KEY=your-api-key
export AGENTRUN_TEMPLATE_NAME=your-template-name
export AGENTRUN_REGION=cn-hangzhou
php scripts/agentrun-verify.php
```

Use `AGENTRUN_TEMPLATE_NAME` to create a fresh temporary sandbox from a template.
Only set `AGENTRUN_SANDBOX_ID` when you already have a live sandbox instance ID;
a template ID is not a sandbox instance ID.

```php
$config = new HaoCodeConfig(
    sandbox: SandboxConfig::agentRun(
        accountId: getenv('AGENTRUN_ACCOUNT_ID')
            ?: throw new RuntimeException('Set AGENTRUN_ACCOUNT_ID.'),
        templateName: getenv('AGENTRUN_TEMPLATE_NAME')
            ?: throw new RuntimeException('Set AGENTRUN_TEMPLATE_NAME.'),
        apiKey: getenv('AGENTRUN_API_KEY') ?: null,
        mode: 'full',
        remoteCwd: '/tmp',
    ),
    allowedTools: ['Read', 'Write', 'Bash'],
    permissionMode: 'bypass_permissions', // remote sandbox contains mutations
);
```

For the current AgentRun code-interpreter template, write demo files under
`/tmp/workspace`; creating `/workspace` at the filesystem root can be denied by
the container. See `examples/agentrun-ml-clustering-agent.php` for a complete
agent-generated data + Python k-means demo.

AgentRun `Glob` and `Grep` prune default heavy directories, bound visited files
and returned results, and apply `glob` filters to relative paths. A bounded
remote search keeps the normal text result shape and adds metadata such as
`searchLimited`, `resultLimited`, `visitedLimited`, and `residualDifferences`.
Sandbox `Glob` keeps the host heading and emits an explicit bounded-result
notice; sandbox paths stay in the remote namespace so they can be passed to
sandbox `Read`. Search failures use `Glob search failed:` and `Grep search
failed:` prefixes.

## Streaming

Use `HaoCode::stream()` when the caller needs incremental output:

```php
foreach (HaoCode::stream('Explain PHP Fibers in three sentences') as $message) {
    if ($message->isError()) {
        throw new RuntimeException($message->error);
    }

    if ($message->text !== null) {
        echo $message->text;
    }
}
```

Terminal results expose `RunTerminationReason` through
`QueryResult::$terminationReason` and terminal
`Message::$terminationReason`. Values are `normal`, `cancelled`,
`budget_exhausted`, `turn_limit`, and `repeated_tool_failure`. Use this enum for
control flow; the v1 compatibility strings such as `(aborted)` remain unchanged
and are not status indicators.

Provider-controlled streaming input is bounded: provider SSE lines and
multiline events over 4 MiB fail with a `protocol_error`, and provider error
bodies are retained only as bounded prefixes. MCP stream/frame parsing applies
the same fail-closed principle. The normalized text and thinking accumulators
also reject more than 1,000,000 bytes per stream instead of retaining an
unbounded response in memory.

One-shot streams own their runtime resources. Once `Runner::stream()`,
`HaoCode::stream()`, or `HaoCode::streamResumeInterrupt()` yields a terminal
`result` or `error`, its sandbox, MCP connections, and abort subscription have
already been released; retaining that completed Generator does not keep the run
alive. A further durable `interrupt` still preserves its sandbox lease for the
next resume.

## Conversations

Use a conversation handle when later prompts should keep the same message history and session:

```php
$conversation = HaoCode::conversation(new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: ['Read', 'Glob', 'Grep'],
    ephemeral: false,
));

$conversation->send('Read the service layer and remember the architecture.');
$result = $conversation->send('Now review the newest changes.');

echo $result->text;
echo $conversation->getSessionId();
```

HaoCode keeps the system prompt byte-stable for the lifetime of a conversation
and grows message history append-only. Volatile Git status is attached to the
initial user turn instead of rewriting the system prefix, improving automatic
prefix-cache reuse on DeepSeek and other compatible providers. DeepSeek cache
hits are reported through `usage['cache_read_tokens']`. `usage['input_tokens']`
is cumulative for the run/conversation; use `usage['last_turn_input_tokens']`
for the current context-window occupancy.

`HaoCode::resume($sessionId)` restores tools against the session transcript's
canonical working directory, not the current PHP process cwd. If you pass a
config `cwd` that differs from that directory, resume fails unless you set
`allowCwdOverride: true`. `HaoCode::continueLatest($cwd)` injects the lookup
`$cwd` into the resume config automatically.

Durable sessions also append versioned, provider-neutral run events and
incremental checkpoints to the existing session JSONL. Legacy session records
remain readable and are still the transcript authority. For hosts that need
transactional tool recovery across workers, set `HAOCODE_RUN_STORE=sqlite` and
optionally `HAOCODE_RUN_DATABASE_PATH=/protected/path/run-state.sqlite` (PDO
SQLite is required). SQLite mode adds idempotency claims, leases and fencing;
an uncertain mutating tool becomes `unknown` and is never retried automatically.
It deliberately executes tools sequentially so a forked process cannot reuse a
database connection across the transaction boundary. This is at-least-once
recovery with fail-closed mutations, not an exactly-once guarantee.
The recovery protocol is tested across independent PHP processes and real
`SIGKILL` boundaries; a Worker daemon/Queue adapter remains host-owned. Internal
event export is always redacted, but Replay reads raw facts, so protect and
retain the run-state JSONL/SQLite files as sensitive application data.

## Human approval

Human-in-the-loop runs are durable and non-blocking: the SDK pauses, returns a
serializable interrupt, and the host resumes it in a later HTTP request or worker
job. Pass the original `HaoCodeConfig` back to `resumeInterrupt()` so the SDK can
restore the same tool and sandbox boundary.

If a later process first creates a handle with `HaoCode::resume($sessionId, $config)`,
then calls `$conversation->resumeInterrupt(...)`, the checkpoint sandbox is
reattached to that rebuilt handle too. A follow-up `send()` or
`streamResumeInterrupt()` therefore sees files created before the interrupt.
If that streaming resume pauses again, only that checkpoint lease is retained;
an unrelated fresh sandbox created for the loaded handle still follows its
configured cleanup policy when the handle closes.

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\HumanDecision;
use HaoCode\Sdk\HumanInterruptException;

$config = new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: ['Read', 'Write', 'Bash'],
    ephemeral: false,
    interruptOn: ['Bash' => true, 'Write' => true],
    enableAskUser: true,
);

try {
    $result = HaoCode::query('Create report.txt', $config);
} catch (HumanInterruptException $e) {
    // Persist or return $e->interrupt->toArray() to your UI.
    $result = HaoCode::resumeInterrupt(
        $e->interrupt->sessionId,
        $e->interrupt->id,
        [HumanDecision::approve($e->interrupt->actions[0]->id)],
        $config,
    );
}
```

`edit` changes only tool arguments, `reject` returns error feedback to the model,
and `respond` supplies a successful tool result. Hard permission denials cannot
be overridden. HITL cannot be combined with `ephemeral: true`.
Streaming hosts resume the same checkpoint with `HaoCode::streamResumeInterrupt()`;
it yields normal stream messages and exactly one final `result`, unless another
`interrupt` pauses the run again.
The facade closes its temporary restored Conversation before yielding a terminal
`result` or `error`; if it yields another `interrupt`, it detaches rather than
deletes the checkpoint's sandbox lease.
Resume preserves the effective inline Skill tool scope and cumulative
token/cost totals. A synchronous worktree Agent is finalized after its resumed
run; retained changes are reported in the final text and `usage` metadata.
The checkpoint also carries the effective cwd, project/worktree identity, model
and prompt settings, allowed tools and Skill scope, remaining turns, cumulative
usage/cost, budget-ledger identifiers, and sandbox lease identity. Credentials
are excluded; the current resume config still revalidates the security policy.

Once claimed, an interrupt moves to `resolving` and is never auto-retried (tool
side effects may already have run). If resume fails after the claim — provider
timeout, tool error, session write failure, or post-claim setup errors — the
session records a terminal `interrupt_failed` state with the error and a
side-effect hint instead of staying stuck in `resolving`. A long-lived
`Conversation` that resumes an interrupt rebuilds its loop and restores the
session / live-run working directory for later `send()` calls. Branching a
session that still has a pending/resolving interrupt is refused.

## Structured Output

Use `structured()` for machine-readable results:

```php
$result = HaoCode::structured('Classify: "payment failed"', [
    'type' => 'object',
    'properties' => [
        'category' => ['type' => 'string'],
        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
    ],
    'required' => ['category', 'priority'],
]);

echo $result->category;
```

Schema and JSON-parse retries always reuse one in-memory `Conversation` (even
when `ephemeral: true`) so tool side effects and transcript history stay in the
same agent run. `ephemeral` only controls durable session persistence, not retry
isolation. Correction turns ask for fixed JSON only and tell the model not to
repeat completed side effects. `StructuredResult::toJson()` preserves Unicode
and slashes and throws `JsonException` when the result cannot be JSON-encoded;
`__toString()` uses the same encoding policy with pretty printing.

## MCP Tools

Define MCP servers in `<cwd>/.haocode/settings.json` (or globally in
`~/.haocode/settings.json`). For example, Context7 uses Streamable HTTP:

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

Enable the discovered, normalized tool names explicitly for the SDK run:

```php
$result = HaoCode::query('Use Context7 to find the current Symfony Process API', new HaoCodeConfig(
    cwd: __DIR__,
    allowedTools: [
        'mcp__context7__resolve_library_id',
        'mcp__context7__query_docs',
    ],
));
```

Hao Code connects only when the run allows MCP tools, registers them for that
run, and disconnects when the run closes. Stdio servers inherit only basic
process-launch variables such as `PATH` and `HOME`; pass required credentials
through that server's explicit `env` map instead of relying on host inheritance.
When a sandbox is active, `allowedTools: ['*']` does not enable host-side MCP
servers; list the required `mcp__...` tools explicitly.

For direct MCP host integrations, call `McpConnectionManager::poll()` during
idle time (or make the next MCP request) so stdio notifications and reverse
requests are serviced; stdio has no background reader thread. The SDK run wires
this cooperative event pump automatically. `ensureConnected($name)` is the
reconnecting manager entry point. `McpClient::setRoots()` only updates the
host-side `roots/list` response and does not automatically notify an already
connected server, so set roots before connecting when initialization needs them.

The `http` transport implements MCP Streamable HTTP `2025-11-25`: JSON and
incremental SSE responses, server-initiated requests and notifications, the
optional GET event stream, `Last-Event-ID` resumption, session re-initialization
after HTTP 404, and best-effort DELETE on close. Static Bearer headers remain
supported. For headless OAuth client-credentials or refresh-token flows, keep
secrets in environment variables and reference only their names:

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
        "refresh_token_env": "PRIVATE_MCP_REFRESH_TOKEN",
        "scope": "mcp:tools"
      }
    }
  }
}
```

`access_token_env` can provide an existing access token. On HTTP 401 Hao Code
refreshes it once when the OAuth configuration contains enough credentials.
Interactive browser authorization and dynamic client registration are not
performed by the framework-free SDK; obtain those credentials in the host
application and expose them through the configured environment variables.

## Custom Tools

Define domain-specific PHP tools by extending `SdkTool`:

```php
use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\SdkTool;

$lookupOrder = new class extends SdkTool {
    public function name(): string { return 'LookupOrder'; }
    public function description(): string { return 'Look up an order by ID.'; }
    public function parameters(): array
    {
        return [
            'order_id' => ['type' => 'string', 'required' => true],
        ];
    }
    public function handle(array $input): string
    {
        return json_encode(['status' => 'paid']);
    }
    // Pure lookups opt into Plan-mode approval. Forking remains disabled.
    public function isReadOnly(array $input): bool
    {
        return true;
    }
};

$result = HaoCode::query('Check order A123', new HaoCodeConfig(
    allowedTools: ['LookupOrder'],
    tools: [$lookupOrder],
));
```

Custom tools and sandbox replacement tools are exposed only when their exact
names appear in `allowedTools` (or when `allowedTools: ['*']` is used);
`disallowedTools` always wins. By default `SdkTool` is neither read-only nor
concurrency-safe. Override `isReadOnly()` only for pure queries; this allows
Plan-mode approval but still executes in the caller process. Forking requires a
second explicit opt-in via `isConcurrencySafe()`, and is appropriate only when
the tool's complete dependency graph is safe after `pcntl_fork`. Framework
containers, database connections, and cURL clients generally are not. Any
stateful or non-concurrency-safe tool remains an execution barrier for later
calls in the same model response.

Each public run gets its own agent loop, message history, cost tracker, and
tool registry. Custom `SdkTool` objects do not have a cloneability contract,
however: cloneable instances are copied for isolated child registries, while
non-cloneable instances may remain shared. Keep custom tools stateless, create
one instance per concurrent run, or synchronize their mutable state when using
Swoole, RoadRunner, or another long-lived worker. The SDK runtime and its
in-memory rate-limit bookkeeping are process-local; use process isolation or an
application-owned shared store when worker-wide coordination is required.

`maxTurns` must be at least `1`. In addition to that hard turn budget, the loop
stops repeating a valid tool call after three consecutive identical tool-error
batches and makes one final no-tool request. Provider retries use bounded jitter
when no `Retry-After` header is supplied, while an explicit server delay is
honored as received.

When a `ToolInputSchema` uses only JSON Schema, a malformed self-contained
schema remains an intentional allow-through compatibility fallback so one bad
MCP schema does not disable every call to that tool. External `$ref` targets
are rejected without I/O. Structured response schemas use a stricter path and
are rejected before the model call when unusable.

## Custom Skills

Use `SdkSkill` to package reusable prompt guidance:

```php
use HaoCode\Sdk\SdkSkill;

$skill = new SdkSkill(
    name: 'security-review',
    description: 'Review code for common security risks.',
    prompt: 'Check $ARGUMENTS for injection, auth bypass, secrets, and unsafe IO.',
    // Full tool names, or Claude-style patterns such as Bash(cargo:*).
    // Patterns are enforced at call time — they are never stripped to a wider grant.
    allowedTools: ['Read', 'Grep', 'Bash(cargo:*)'],
    model: 'haiku', // Anthropic tier alias (or a full model id)
    context: 'inline', // use 'fork' for an isolated child agent
);

$result = HaoCode::query('Use security-review on app/Auth.php', new HaoCodeConfig(
    allowedTools: ['Skill', 'Read', 'Grep', 'Bash'],
    skills: [$skill],
));
```

To use an existing Claude Skill catalog without copying it, opt in explicitly:

```php
$result = HaoCode::query('Use the matching skill for this task', new HaoCodeConfig(
    allowedTools: ['Skill', 'Read', 'Grep'],
    skillDirectories: [getenv('HOME').'/.claude/skills'],
    recursiveSkillDiscovery: true,
));
```

Skill-specific tool restrictions and model overrides are enforced during the
active skill scope (inline for the rest of the turn; fork only inside the child
agent). Claude-style entries such as `Bash(cargo:*)` keep the command pattern —
`Bash(cargo:*)` does **not** silently become unrestricted `Bash`, and shell
chaining / expansion (`cargo test; rm -rf /`, pipes, `$()`, redirections) is
rejected. Nested inline skills intersect their capability lists. Model
overrides use the same provider-aware alias rules as Agent tools: `haiku` /
`sonnet` / `opus` expand on Anthropic; full model IDs pass through; Anthropic
aliases on non-Anthropic providers fail closed. Standalone skill shell
directives are forwarded to the normal `Bash` tool, so permission checks,
hooks, and skill scope still apply. Additional directories are never loaded
implicitly.

## Credentials And Budgets

Use credential pools when you have multiple API keys, and cost budgets when the caller needs a shared post-response spending guard:

```php
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;

$pool = new CredentialPool;
$pool->addMany('anthropic', [
    new Credential(apiKey: getenv('ANTHROPIC_API_KEY_1') ?: ''),
    new Credential(apiKey: getenv('ANTHROPIC_API_KEY_2') ?: ''),
]);

$config = new HaoCodeConfig(
    credentialPool: $pool,
    maxBudgetUsd: 1.00,
);
```

`exhaustedTtlSeconds` and an explicit `retryAfterSeconds` must be finite,
non-negative numbers. Each exhausted credential keeps the TTL used for that
exhaustion, so a provider-specific retry delay does not silently replace the
pool default for other credentials.

Credential-pool exhaustion and `RateLimitTracker` state are in-memory and
process-local. They coordinate calls handled by one PHP process only; a
multi-worker deployment needs an application-owned shared implementation if
workers must observe each other's rate limits. When a provider supplies no
`Retry-After`, SDK exponential retries add bounded jitter to avoid synchronized
retries across clients. Pool buckets are selected from the provider type at the
start of every request, including after a runtime `active_provider` change; a
credential from the previous provider is never reused for the new wire adapter.

Built-in USD budgets use exact pricing for the Claude models listed by this
release. Unknown or non-Anthropic models report `cost_available: false`;
requesting `maxBudgetUsd` for one of those models fails before a request is sent
instead of applying an unrelated fallback price. If a priced request returns an
unknown deployment alias, usage keeps the already validated request-model price
rather than becoming unpriced after the spend. The budget is one shared,
process-safe total across the root run, child/Team/background agents, forked
skills, structured retries, and durable HITL resumes. Enforcement is a
**shared post-response spending guard** (checked before the next request and
after each usage record), not a pre-reserved hard cap: a single in-flight call
can finish slightly over the limit, and parallel child agents may overshoot
further before their costs are merged. On HITL resume, the effective limit is
`min(snapshot limit, current config maxBudgetUsd)` so a tighter caller budget
is never ignored.

## Callbacks And Abort

`HaoCodeConfig` supports callbacks for text and thinking deltas, tool starts,
tool completions, and turn starts. Streaming APIs also yield a `turn` message at
the start of every agent turn. It supports `AbortController` for external cancellation:

```php
use HaoCode\Sdk\AbortController;

$abort = new AbortController();

$config = new HaoCodeConfig(
    abortController: $abort,
    onText: fn (string $delta) => print $delta,
    onThinking: fn (string $delta) => error_log("thinking: {$delta}"),
    onToolStart: fn (string $name, array $input) => error_log("tool: {$name}"),
);
```

Cancellation is checked again after each provider request. If it wins the race
with an otherwise completed response, including the no-tool final answer after
the tool-turn limit, the operation returns `(aborted)` without persisting that
assistant response.

## Storage And Memory

Runtime data is stored under `~/.haocode/storage` by default when installed
through Composer. Set `HAOCODE_STORAGE_PATH` for an application-specific
runtime directory. Background Agent, Team, and Task state is isolated under
`app/haocode/background-agents`, `app/haocode/teams`, and
`app/haocode/tasks` inside that runtime directory.

Background admission and mailboxes are bounded per owner run. Defaults are 8
active agents, 128 queued messages, 65,536 bytes per message, and 1 MiB per
mailbox. Override them with
`HAOCODE_BACKGROUND_AGENT_MAX_ACTIVE_PER_RUN`,
`HAOCODE_BACKGROUND_AGENT_MAILBOX_MAX_MESSAGES`,
`HAOCODE_BACKGROUND_AGENT_MESSAGE_MAX_BYTES`, and
`HAOCODE_BACKGROUND_AGENT_MAILBOX_MAX_BYTES`. Every value must be positive;
invalid configuration fails during runtime startup.

Long-term memory uses compact `l0` summaries in the system prompt by default;
`l1` provides a larger overview and `l2` injects original content. Supplying
`memoryStoragePath` explicitly also enables memory injection for text-only runs:

```php
$memoryPath = __DIR__.'/var/haocode-memory.json';

$config = new HaoCodeConfig(
    memorySummaryLevel: 'l1',
    memoryStoragePath: $memoryPath,
);
```

Use the public store API to seed or manage memory, and pass the same store to a
run when the agent needs on-demand detail:

```php
use HaoCode\Sdk\Memory\JsonMemoryStore;

$memory = new JsonMemoryStore($memoryPath);
$memory->write('response_style', 'The user prefers concise answers.', 'preference');

$config = new HaoCodeConfig(
    memoryStore: $memory, // takes precedence over memoryStoragePath
    allowedTools: ['MemoryRead'],
);
```

`MemoryWrite` and `MemoryDelete` are never exposed unless explicitly listed in
`allowedTools`. They are state-changing tools, so normal permission checks also
apply; their tool policy limits use to explicit remember/update/forget requests
and forbids storing secrets. `JsonMemoryStore` uses a file lock and atomic file
replacement. Implement `MemoryStoreInterface` to use a database or another
application-owned store.

## Examples

| Example | Purpose |
| --- | --- |
| `examples/code-review-agent.php` | Code review workflow |
| `examples/agentrun-ml-clustering-agent.php` | AgentRun sandbox ML clustering demo |
| `examples/support-ops-agent.php` | End-to-end support operations agent |
| `examples/weather-agent.php` | Custom tool example |
| `examples/sdk-suite/` | Focused examples for query, streaming, conversation, structured output, abort, credential pools, patching, MCP, and provider matrix |

## Built-in Web Search

`WebSearch` concurrently queries Bing, DuckDuckGo, Sogou, 360 Search, and
Yahoo by default, then deterministically deduplicates and ranks up to eight
results. Calls may select a non-empty subset with the optional `engines` tool
argument; supported IDs are `bing`, `duckduckgo`, `sogou`, `360`, and `yahoo`.
Google and other engines are not implicitly enabled.

The model still receives the existing Markdown result list. Hosts can use
`onToolComplete` and inspect `ToolResult::$data` for schema-v1 result cards,
engine provenance, scores, per-engine status, and partial-failure state without
parsing that Markdown. See [Complete SDK reference](docs/SDK.md#built-in-web-search)
for the input and data contracts.

## Documentation

- [Complete SDK reference](docs/SDK.md)
- [Product roadmap (中文)](docs/ROADMAP.md)
- [SDK backward compatibility policy](docs/sdk-bc-policy.md)
- [SDK example suite](examples/sdk-suite/README.md)

## Source Quality Gates

Every Git-tracked PHP file is limited to 500 physical lines. The rule has no
legacy allowlist and covers production code, tests, examples, and repository
scripts. Run the standalone check with:

```bash
composer run quality:file-size
```

`composer test` runs this check before test-discovery validation and the full
non-live PHPUnit suite. GitHub Actions enforces the same contract on Linux and
Windows. Large implementations keep their existing public class as the API and
split cohesive behavior into same-namespace concern traits.

## Version

Published versions are identified by Git tags and Packagist. This source line
is based on `v1.23.0`. Notable changes since `v1.10.0`:

- `v1.23.0` — Background agents and `run_in_background` Bash commands now report
  themselves when they finish, instead of waiting to be polled, and background
  Bash output is finally reachable through a `BashOutput` tool. Plan mode gains a
  plan file, a system-prompt section, and an `ExitPlanMode` that actually resolves
  the phase: human approval on a durable session, otherwise the plan is handed
  back with the new `RunTerminationReason::PlanReady`. New opt-in `goal`,
  `goalVerificationRounds`, `goalReminder`, and `planExitPolicy` settings keep
  long runs on task. `WebSearch` asks for a date on time-sensitive queries. All
  public API changes are additive; hosts with an exhaustive `match` over
  `RunTerminationReason` must add a `PlanReady` arm.

- `v1.22.0` — WebFetch gains opt-in main-content extraction (`extract`, with
  `keywords` to weight the blocks that matter), flags bot-challenge pages and
  client-rendered shells instead of returning them as content, reports the body
  of a 4xx/5xx response alongside its status code, and presents a browser
  `User-Agent` so CDN user-agent blacklists no longer read as dead links.
  Default WebFetch output is unchanged. Adds an `ext-dom` requirement.

- `v1.21.0` — Adds typed run termination reasons, fail-closed JSON Schema tool
  registration, one Fiber-backed streaming lifecycle, bounded background-agent
  admission/mailboxes, and immutable run bootstrap/config adaptation. Existing
  v1 response text and provider wire formats remain compatible.

- `v1.20.2` — Keeps RunEvent Store contract tests compatible with PHP 8.1 by
  consuming the declared `iterable` return type without assuming a
  `Traversable`; runtime behavior and the public SDK API are unchanged.

- `v1.20.1` — Adds the internal versioned RunEvent/Checkpoint contract and
  SQLite Claim/Lease/Fencing recovery path without changing the public SDK API.
  Real `SIGKILL` process tests verify fail-closed mutation recovery; exported
  events are redacted by default, and Agent/Model/Tool spans reuse canonical
  RunJournal identities for trace correlation.

- `v1.20.0` — Completes the existing architecture-convergence queue without
  adding a workflow DSL: coding and generic context presets are explicit;
  tool results and registry identities have one validated runtime contract;
  run limits, agent invocation, message visibility, prompt sensitivity, and
  provider cache adaptation each have a single internal authority. Existing
  defaults and provider request shapes remain compatible.

- `v1.19.10` — Adds a no-waiver 500-line limit for every tracked PHP file,
  enforced by Composer and Linux/Windows CI. Existing oversized production and
  test classes are split into responsibility-oriented concerns without changing
  the SDK public API snapshot or runtime behavior.

- `v1.19.9` — Host and sandbox `Read` tools now share one bounded text scanner
  for line windows, cancellation, hashing, and size failures; failed or aborted
  reads never create write-authorizing receipts. Coding prompt context is also
  isolated behind one internal preset while text-only calls keep their minimal
  no-tools prompt and existing public configuration contract.

- `v1.19.8` — Runtime provider switching now treats provider type, credentials,
  model, endpoint, token limits, capability checks, and cost context as one
  validated identity. The SDK preflights the actual tool and sandbox manifest,
  rolls rejected setting changes back atomically, and runs all three provider
  adapters through the same success and fault conformance matrix.

- `v1.19.7` — AgentRun and Tokimo bound command-response transport data before
  JSON decoding. Oversized remote/runner payloads now use the established
  `outputLimited` result contract instead of accumulating in PHP memory, and a
  Tokimo runner cleanly restarts after that boundary while still accepting
  ordinary chunked JSONL responses.

- `v1.19.6` — A `Conversation` loaded through `HaoCode::resume()` now cleans
  its unrelated fresh sandbox after a streaming resume pauses again, while the
  durable checkpoint sandbox remains available for the next decision.

- `v1.19.5` — A `Conversation` loaded with `HaoCode::resume()` now reclaims
  the durable interrupt checkpoint's sandbox lease before rebuilding after
  `resumeInterrupt()` or `streamResumeInterrupt()`. Follow-up tool calls keep
  seeing pre-interrupt files instead of a fresh sandbox.

- `v1.19.4` — Cancellation now has one consistent lifecycle boundary across
  ordinary, parent/child, event-pump, and tool-turn-limit finalization paths.
  A response that completes after cancellation is not persisted or reported as
  a completed agent run.

- `v1.19.3` — Tool scheduling now preserves stateful execution barriers: a
  later read-only call cannot run ahead of a preceding mutation, including in
  streaming early execution. Compatible provider streams also retain text,
  thinking, and complete tool arguments supplied in a content-block start
  event, while standard empty tool-input placeholders still accept later JSON
  deltas.

- `v1.19.2` — One-shot Runner and facade streams now release their owned
  runtime resources before exposing terminal results or errors, so retaining a
  completed Generator cannot keep a sandbox, MCP connection, or abort listener
  alive. Re-interrupting streaming resumes preserve the durable sandbox lease.

- `v1.19.1` — Completes compatible OpenAI Chat streams that explicitly end in
  `[DONE]` but omit `finish_reason`, while preserving partial-response recovery
  for bare EOF. Terminal conversation stream messages release their operation
  lease before they are yielded, so a retained completed Generator cannot block
  or later corrupt an immediate follow-up or interrupt resume. Durable
  `Conversation` resumes also transfer the reattached sandbox lease to their
  rebuilt handle, preserving pre-interrupt files for the next tool call.

- `v1.19.0` — Hardened existing agent lifecycle contracts: every SDK result
  surface reports current-turn context usage; durable and streaming interrupt
  resumes preserve cumulative usage, scoped Skill models, and canonical session
  lifecycle without replaying first-turn context/hooks; early tool completion
  events reflect worker completion; malformed gateway usage cannot decrease
  totals or cost.

- `v1.11.0` — Streamable HTTP MCP sessions (incremental SSE, reverse RPC,
  recovery, OAuth, cooperative event polling), and reduced repeated Git/memory/
  tool-schema work in long-running sessions.
- `v1.11.1` — Cache-stable system prompts, tool schemas, and conversation
  history with volatile Git context moved into the initial user turn; normalized
  DeepSeek and OpenAI-compatible cache usage accounting.
- `v1.12.0` — Native smart HITL modes (`HitlPolicy`, `HitlReviewer`,
  `SmartInterruptDecider`) with rule-based fast paths and a guardian review model.
- `v1.12.1` — Smart HITL aligned with codex semantics: recursive command
  grading, default mode `'smart'`, sandbox-containment fast path, and the
  `hitlAllowlistPath` "always allow" file.
- `v1.13.0` — Prefix-based always-allow rules (every segment of a compound
  command must match) and temp-dir redirect downgrade from red-line to gray
  review.
- `v1.13.1` — Session JSONL writes never kill or corrupt a run: invalid UTF-8
  and non-finite doubles are sanitized with partial-output fallback.
- `v1.18.6` — Durable HITL preserves inline Skill scopes, cumulative budgets,
  multimodal content, and managed worktree lifecycle; provider model selection,
  pricing, transcript writes, and runtime reset safety now fail closed.
- `v1.18.7` — Provider connections resolve credentials and model limits as one
  vendor-safe unit; adaptive thinking, shared run-tree budgets, consistent HITL
  resumes, worktree context, and durable session writes are hardened.
- `v1.18.8` — Unknown `providerType` fail-closed; skill `Bash(pattern)` capability
  rules enforced without silent widening; skill model aliases provider-aware;
  session resume restores transcript cwd; `interrupt_failed` terminal state;
  durable structured retries share one conversation; branch refuses unfinished
  HITL; budget documented as a shared post-response guard with stricter resume
  limits; adaptive effort mapped from `thinkingBudget` / `effort_level`.
- `v1.18.9` — Skill Bash patterns reject shell chaining/expansion (not just
  prefix match); `Conversation` reload after interrupt resume restores session /
  live-run cwd; post-claim HITL failures always record `interrupt_failed`.
- `v1.18.10` — Polish existing tools: drop misleading Bash
  `dangerouslyDisableSandbox`; harden background Bash bookkeeping (TTL,
  start-token, harvest-on-check); MCP `list*` follows `nextCursor`; Edit/Patch
  refuse binary/NUL payloads.
- `v1.18.11` — Contract fixes: background Bash no double-run + real exit codes;
  `SdkTool`/`AgentAsTool` default non-read-only; AgentAsTool inherits parent
  cwd/budget/abort and rethrows HITL; Runner inherits Agent ephemeral;
  invalid `hitlMode` throws; budget ledgers may only tighten; corrupt memory
  files fail closed; Edit tolerates missing fileinfo; structured retries on
  bad JSON; `turnsUsed` is per-operation; MCP list uses one total deadline.
- `v1.18.12` — Structured retries always share one Conversation (even ephemeral)
  with correction-only turns; HITL structured resume re-validates schema;
  AgentAsTool accepts any parent `LlmProvider` and parent ToolRegistry; durable
  HITL keeps sandbox roots across interrupt/resume; Bash/LocalSandbox use
  process-group kill + unified env denylist; nested agent tokens roll into
  parent usage; MCP connect/initialize share one absolute deadline.
- `v1.18.13` — Close cross-layer contracts: AgentRun/Tokimo durable leases
  without secrets; lease identity vs caller policy; interrupt JSONL corruption
  fail-closed; sandbox reserved tool names; shared usage in HITL snapshots;
  structured correction disables tools; scalar schema rejected early; MCP init
  full reset; Sandbox Bash honors abort during exec; background Bash watchdog.
- `v1.18.14` — Nested agent cost joins shared run accounting without losing
  per-child deltas; custom ExecPolicy `env_deny` applies consistently to
  foreground, background, and sandbox Bash; invalid HITL decisions preserve
  pending sandbox leases; structured streaming resume and MCP deadlines fail
  closed; release lint now covers examples.
- `v1.18.15` — SDK lifecycle and persistence contracts are hardened: durable
  HITL sandboxes survive non-streaming and repeated-interrupt paths; abandoned
  streams abort and release execution state; hooks time out, fail closed, and
  revalidate modified input; committed credential streams are never replayed;
  settings and session reads fail closed; parallel tool results retain metadata
  and outcomes.
- `v1.18.16` — Local file tools enforce text-only `Read` boundaries, complete
  and current revision receipts, and atomic conflict-safe mutation; context
  compaction preserves tool-call/result structure; `Conversation::loadSession()`
  atomically replaces existing in-memory history; CI discovers and runs every
  non-live test.
- `v1.18.17` — Cross-platform release gates now cover native Windows path and
  glob behavior, PHP 8.1–8.3 stream abandonment, clean-checkout sandbox tests,
  fail-closed Hook working directories, and Windows-form sensitive paths.
- `v1.18.18` — Same-response `Read` + `Write` batches no longer satisfy
  read-before-write authorization; internal Git diffs and worktree commands use
  isolated argv-based execution; worktree entry/exit preserves session cwd
  without trusting hooks, symlinks, or the PHP process cwd.
- `v1.18.19` — `ExitWorktree` is now classified as non-read-only because both
  keep and remove actions change session working-directory state.
- `v1.18.20` — Background Bash launches return promptly, supervise timeout from
  a detached PHP worker, terminate the command tree before writing exit 124, and
  keep captured background output under a physical byte cap.
- `v1.18.21` — MCP stdio requests now write stdin, read stdout, and drain stderr
  under one deadline, preventing stderr pipe pressure from deadlocking normal
  JSON-RPC responses and closing stdio process trees on shutdown.
- `v1.18.22` — Parallel tool execution avoids early/parallel forks when
  `PreToolUse` hooks may rewrite input, caps forked workers, and makes forked
  tool children process-group leaders so cleanup cancels descendant processes.
- `v1.18.23` — Grep streams ripgrep output and stops after the requested global
  result window, the PHP fallback scans files line-by-line, and Glob bounds
  brace expansion, visited files, ignored heavy directories, and retained top
  results.
- `v1.18.24` — Foreground Bash captures stdout/stderr through bounded endpoints,
  terminates runaway output at the byte cap, and still returns promptly when a
  command leaves its own background child running.
- `v1.18.25` — Text Read streams line windows while computing file revisions,
  segmented reads merge line coverage into complete receipts only after
  model-visible batch commit, and Edit/Write size checks now bound replacement
  payloads rather than rejecting large files outright.
- `v1.18.26` — WebFetch now requests and returns only explicit text-like media
  types, rejects binary or missing Content-Type responses before streaming body
  bytes, and normalizes fetched text to valid UTF-8 before tool results/cache.
- `v1.18.27` — File Read rejects extreme single-line buffers, runs PDF text
  extraction through argv-based bounded subprocesses with page validation,
  timeout, and output caps, and bounds notebook input/rendered output receipts.
- `v1.18.28` — Internal unified diffs are generated in PHP without relying on
  the host `diff` command, and Bash-backed process startup now reports a clear
  missing-`bash` diagnostic before attempting command execution.
- `v1.18.29` — The Windows release gate now runs the internal diff and local
  process startup contract tests in addition to path compatibility checks.
- `v1.18.30` — Hardened Git subprocesses preserve exit codes captured by
  `proc_get_status()` so PHP 8.1/8.2 CI does not drop successful internal diff
  output after `proc_close()` returns `-1`.
- `v1.18.31` — PDF text extraction now honors the active tool abort signal,
  terminates the extraction process tree, and reports interrupted reads without
  recording a file-read receipt.
- `v1.18.32` — Automatic Git diff generation after Edit/Write now carries the
  active tool abort signal into HardenedGitRunner so interrupted runs stop the
  internal Git process tree promptly.
- `v1.18.33` — Release metadata now points at the current audited source line
  so package consumers can distinguish this published patch tag from the prior
  hardening releases.
- `v1.18.34` — Git prompt context, managed agent worktrees, file-history diffs,
  and local executable discovery now avoid shell wrappers, carry bounded argv
  execution, and keep worktree cleanup tied to the owning repository.
- `v1.18.35` — Skill registry Git sync now reuses the shared hardened Git runner,
  and LSP server startup uses argv-based, bounded non-blocking stdio with
  process-tree cleanup.
- `v1.18.36` — Glob and Grep now prune default ignored directories before
  descending into them, and search paths honor abort signals across ripgrep and
  PHP fallback execution.
- `v1.18.37` — Local sandbox Glob and Grep now prune default ignored
  directories before descending, cap retained glob results, stream grep line
  reads, and preserve sandbox search abort and zero-limit semantics.
- `v1.18.38` — Local sandbox Read now streams text line windows while hashing
  the full file revision, avoids caching full file contents for partial reads,
  rejects extreme single-line buffers, and honors abort signals.
- `v1.18.39` — Local sandbox Bash now captures stdout and stderr through
  bounded pipes, terminates commands that exceed the capture cap, and reports
  output-limit metadata instead of allowing unbounded temporary output files.
- `v1.18.40` — Native, Tokimo, and AgentRun sandbox exec paths now share the
  `outputLimited` metadata contract; native sandbox commands are terminated when
  captured output exceeds the backend cap, while remote sandbox responses are
  bounded before reaching tool results.
- `v1.18.41` — AgentRun sandbox exec now returns the same non-zero exit status
  as other sandbox backends when SDK-side output bounding marks a command
  `outputLimited`.
- `v1.18.42` — Optional cron daemon job execution now uses the shared process
  supervisor, drains stdout and stderr under a single timeout, and terminates
  descendant processes before delayed side effects can run.
- `v1.18.43` — Cron daemon jobs now keep that supervised execution path without
  starting a login shell, so per-user shell profiles cannot bypass the cron
  environment allowlist or add hidden startup side effects.
- `v1.18.44` — Worktree creation now rejects `.claude/worktrees` symlinks,
  verifies the worktree base remains inside `.claude`, and updates `.gitignore`
  through the atomic writer instead of a direct append.
- `v1.18.45` — Durable HITL checkpoints now preserve pending Read receipts and
  promote them only after the resumed batch's tool results become model-visible,
  so approved same-batch writes cannot borrow unread content while the next turn
  can safely retry after seeing the Read output.
- `v1.18.46` — Parallel and streaming-early tool forks now cap serialized IPC
  payloads before writing or reading temp files, preventing oversized metadata
  from bypassing tool-result output caps while preserving aborted/error outcomes.
- `v1.18.47` — Release metadata refresh for the verified `v1.18.46` hardening
  source line, keeping downstream package indexing aligned with the current
  audited commit.
- `v1.18.48` — Host Grep PHP fallback and Glob now honor root `.gitignore`
  rules before returning or descending into ignored files, reducing duplicate
  worktree/vendor search cost while preserving bounded traversal semantics.
- `v1.18.49` — Fallback search and MCP process boundaries now reject
  pathological traversal, validate cross-platform roots, and close stale
  stdio descendants during reconnect and shutdown.
- `v1.18.50` — Large-file Read/Edit/Write paths, foreground/background Bash,
  parallel tool cancellation, Grep/Glob resource limits, and Windows path
  normalization now fail closed with bounded work and regression coverage.
- `v1.18.54` — Provider, MCP, and sandbox error bodies are now read with
  bounded prefixes; SSE lines and multiline events reject oversized input;
  stdio rejects newline-terminated oversized frames; and fallback search
  resource limits include literal Glob patterns and retained Grep context.
  `CredentialPool` rejects non-finite or negative exhaustion TTLs, and
  `StructuredResult::toJson()` now fails with `JsonException` instead of
    returning an invalid JSON value.
- `v1.18.57` — Native OpenAI Chat streams retain rate-limit headers for
  credential-pool backoff, recent-session selection uses mtime plus a bounded
  header scan, and MCP/AgentRun lifecycle and write-safety contracts are
  documented. Retry, snapshot, parallel IPC, Skill scope, JSONL, and
  background Bash bookkeeping were split into internal helpers without
  changing the public SDK surface.
- `v1.18.58` — Read-receipt batch promotion is isolated behind an internal
  lifecycle helper. Sandbox Glob keeps the host result heading and explicit
  bounded-result notice, while sandbox search failures use tool-specific
  prefixes.
- `v1.18.59` — Sandbox Glob's bounded-result wording no longer implies an
  unknown total count; it remains explicit without overstating the result.
- `v1.18.60` — AgentLoop rejects invalid zero or negative turn budgets and
  stops repeating identical valid tool-error batches with a final no-tool
  request; provider fallback retries add bounded jitter while honoring
  explicit `Retry-After` delays, with the lifecycle and deployment contracts
  documented and covered by regression tests.
- `v1.18.56` — AgentRun Glob/Grep now prune and bound remote searches, preserve
  path-level glob semantics, and expose residual-difference metadata; Tokimo
  and AgentRun abort waits mid-run, and all sandbox exec backends use the
  aligned 100,000-byte output-limit contract.
- `v1.18.55` — Stream text/thinking accumulation, Bash result metadata, and
  context-compaction thresholds now share tighter fail-closed contracts;
  sandbox and MCP documentation were refreshed for the current implementation.
- `v1.18.53` — Preserve quoted Bash command arguments on Windows, validate and
  normalize inputs before parallel/early execution, and bound unterminated MCP
  SSE metadata before buffering it.
- `v1.18.52` — Remaining tool resource boundaries now clean up forked process
  trees and IPC files on callback failures, host search honors ancestor and
  nested `.gitignore` rules, and parallel safety classification uses normalized
  inputs while failing closed around permission prompts.
- `v1.18.51` — Extensionless large text files no longer inherit a false binary
  classification from PHP's `application/octet-stream` MIME fallback.

## License

MIT
