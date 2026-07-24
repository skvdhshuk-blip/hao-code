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

You do not need to install Tokimo separately. The installer downloads only the
runner for the current OS/CPU and stores verified artifacts in the user cache.
On macOS and Linux, full runtime setup may ask for `sudo` while extracting the
rootfs so its Linux uid/gid ownership is preserved. From a hao-code source
checkout, replace `vendor/bin/hao-code-sandbox` with `bin/hao-code-sandbox`.

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

Agents can be composed: one agent can use another as a tool via `Agent::asTool()`.
`HaoCode::query()` and `HaoCode::stream()` remain unchanged and are implemented on
top of `Runner`.

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
`HAOCODE_MAX_TOKENS`. `OPENAI_API_KEY` is not an automatic fallback; pass it as
`apiKey` or put it in the selected provider entry in
`~/.haocode/settings.json`.

For `deepseek-v4-flash`, enabling thinking sends DeepSeek's explicit thinking
contract. A thinking budget of `32000` or more selects maximum reasoning effort,
and HaoCode preserves `reasoning_content` across multi-turn tool calls.

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
`NotebookEdit`, worktree tools, `Agent`, and `SendMessage`. Other host-only tools,
including `LSP`, task/team tools, and cron tools, are not sandbox replacements;
use an explicit `allowedTools` list as shown below and omit them unless needed.

Choose the backend by the isolation boundary you need:

| Backend | Hosts | Use it for |
| --- | --- | --- |
| `local()` | Any supported PHP host | File workspace isolation without untrusted `Bash`; shell commands still run as normal host processes |
| `native()` | macOS and Linux | Lightweight local process isolation through Seatbelt or bubblewrap |
| `tokimo()` | macOS arm64, Linux amd64/arm64, Windows amd64 | Recommended cross-platform boundary for agent-generated or untrusted shell commands; installed separately on demand |
| `agentRun()` | Any host with AgentRun access | Remote cloud isolation when commands and files must stay off the PHP host |

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
export AGENTRUN_ACCOUNT_ID=1887527099427005
export AGENTRUN_API_KEY=ak_xxx
export AGENTRUN_TEMPLATE_NAME=sandbox-lagal
export AGENTRUN_REGION=cn-hangzhou
php scripts/agentrun-verify.php
```

Use `AGENTRUN_TEMPLATE_NAME` to create a fresh temporary sandbox from a template.
Only set `AGENTRUN_SANDBOX_ID` when you already have a live sandbox instance ID;
a template ID is not a sandbox instance ID.

```php
$config = new HaoCodeConfig(
    sandbox: SandboxConfig::agentRun(
        accountId: getenv('AGENTRUN_ACCOUNT_ID'),
        templateName: getenv('AGENTRUN_TEMPLATE_NAME') ?: 'sandbox-lagal',
        apiKey: getenv('AGENTRUN_API_KEY'),
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
hits are reported through `usage['cache_read_tokens']`.

## Human approval

Human-in-the-loop runs are durable and non-blocking: the SDK pauses, returns a
serializable interrupt, and the host resumes it in a later HTTP request or worker
job. Pass the original `HaoCodeConfig` back to `resumeInterrupt()` so the SDK can
restore the same tool and sandbox boundary.

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
};

$result = HaoCode::query('Check order A123', new HaoCodeConfig(
    allowedTools: ['LookupOrder'],
    tools: [$lookupOrder],
));
```

Custom tools and sandbox replacement tools are exposed only when their exact
names appear in `allowedTools` (or when `allowedTools: ['*']` is used);
`disallowedTools` always wins. By default `SdkTool` is treated as read-only.
Override `isReadOnly()` and return `false` for stateful or mutating tools.

## Custom Skills

Use `SdkSkill` to package reusable prompt guidance:

```php
use HaoCode\Sdk\SdkSkill;

$skill = new SdkSkill(
    name: 'security-review',
    description: 'Review code for common security risks.',
    prompt: 'Check $ARGUMENTS for injection, auth bypass, secrets, and unsafe IO.',
    allowedTools: ['Read', 'Grep'],
    context: 'inline', // use 'fork' for an isolated child agent
);

$result = HaoCode::query('Use security-review on app/Auth.php', new HaoCodeConfig(
    allowedTools: ['Skill', 'Read', 'Grep'],
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
active skill scope. Standalone skill shell directives are forwarded to the
normal `Bash` tool, so the configured permission checks and hooks still apply.
Additional directories are never loaded implicitly.

## Credentials And Budgets

Use credential pools when you have multiple API keys, and cost budgets when the caller needs a hard spending guard:

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

## Storage And Memory

Runtime data is stored under `~/.haocode/storage` by default when installed
through Composer. Set `HAOCODE_STORAGE_PATH` for an application-specific
runtime directory. Background Agent, Team, and Task state is isolated under
`app/haocode/background-agents`, `app/haocode/teams`, and
`app/haocode/tasks` inside that runtime directory.

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

## Documentation

- [Complete SDK reference](docs/SDK.md)
- [SDK backward compatibility policy](docs/sdk-bc-policy.md)
- [SDK example suite](examples/sdk-suite/README.md)

## Version

Published versions are identified by Git tags and Packagist. This source line
is based on `v1.18.5`. Notable changes since `v1.10.0`:

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

## License

MIT
