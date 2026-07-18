# Hao Code PHP SDK

Use hao-code as a framework-free PHP library to embed an AI coding agent in your application.

This document describes the current `v1.10.0` source release.

```bash
composer require sk-wang/hao-code
```

Set your API key in the process environment:

```bash
export ANTHROPIC_API_KEY=your-api-key
```

Hao Code does not load `.env` files by itself. If your application already
loads `.env`, you may pass the resulting value through `HaoCodeConfig`.
`OPENAI_API_KEY` is not read automatically; pass it as `apiKey` or configure it
in the selected provider entry in `~/.haocode/settings.json`.

---

## Table of Contents

- [Quick Start](#quick-start)
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
- [Agent Teams](#agent-teams)
- [Multi-turn Conversations](#multi-turn-conversations)
- [Session Resume & Continue](#session-resume--continue)
- [Structured Output](#structured-output)
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

```text
HaoCode::resumeInterrupt(string $sessionId, string $interruptId, array $decisions, ?HaoCodeConfig $config = null): QueryResult|StructuredResult
HaoCode::streamResumeInterrupt(string $sessionId, string $interruptId, array $decisions, ?HaoCodeConfig $config = null): Generator<Message>
```

See [Durable human-in-the-loop](#durable-human-in-the-loop) for checkpoint and
decision semantics.

### structured()

Extract structured (JSON) data from the agent's response.

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

---

## HaoCodeConfig Reference

All parameters are optional. Pass as named arguments:

```php
$config = new HaoCodeConfig(
    apiKey: 'your-key',
    model: 'claude-sonnet-4-20250514',
    // ...
);
```

### API Connection

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `apiKey` | `?string` | `null` | API key for the selected provider. Falls back to provider/project/global settings, then `ANTHROPIC_API_KEY` |
| `model` | `?string` | `null` | Model ID. Falls back to config default |
| `baseUrl` | `?string` | `null` | API endpoint URL (for proxies, custom endpoints) |
| `maxTokens` | `?int` | `null` | Maximum output tokens per response |
| `providerType` | `?string` | `null` | `anthropic`, `openai`, or `openai_chat` wire format |

When any of these are set, the SDK creates a run-scoped provider. Explicit
values override active settings; unspecified connection values still come from
the selected provider/project/global settings.

Input budgeting uses the active provider's `context_window` setting. It falls
back to `HAOCODE_CONTEXT_WINDOW` (200000 by default) and reserves both the
configured output tokens and a safety margin before sending a request.

### Agent Behavior

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `cwd` | `?string` | `null` | Working directory for tool execution. Defaults to `getcwd()` |
| `maxTurns` | `int` | `50` | Maximum agent turns (tool-use round trips) |
| `maxBudgetUsd` | `?float` | `null` | Cost limit in USD. Agent stops when exceeded |
| `ephemeral` | `bool` | `true` | Disable session and tool-result persistence for this run |
| `permissionMode` | `string` | `'default'` | `'default'`, `'plan'`, `'accept_edits'`, `'bypass_permissions'` |
| `sandbox` | `?SandboxConfig` | `null` | Optional temporary filesystem/shell runtime for tools |
| `credentialPool` | `?CredentialPool` | `null` | Rotate provider credentials and retry rate-limited keys |
| `interruptOn` | `array` | `[]` | Exact tool names to pause before execution; values are `true`, `false`, or review configuration arrays |
| `enableAskUser` | `bool` | `false` | Register `AskUserQuestion` as a serializable host interrupt |
| `hitlMode` | `?string` | `null` | HITL approval mode: `'ask'`, `'smart'`, or `'auto'`; `null` resolves from config/env (default `'smart'`). See [Smart HITL modes](#smart-hitl-modes) |
| `hitlReviewModel` | `?string` | `null` | Model used to review gray-area actions in `'smart'` mode; `null` reuses the current run's model |
| `hitlAllowlistPath` | `?string` | `null` | JSON file with user-saved "always allow" Bash rules (exact/prefix, v1+v2); `null` disables the feature. See [Smart HITL modes](#smart-hitl-modes) |

### Prompts

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `systemPrompt` | `?string` | `null` | Replace the default system prompt entirely |
| `appendSystemPrompt` | `?string` | `null` | Append text to the default system prompt |
| `responseSchema` | `?array` | `null` | Override the schema used by `structured()` |

### Tools & Skills

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `allowedTools` | `string[]` | `[]` | Tools to allow. `['*']` explicitly enables all tools |
| `disallowedTools` | `string[]` | `[]` | Tools to deny (takes precedence over allowed) |
| `tools` | `SdkTool[]` | `[]` | Custom tools to register |
| `skills` | `SdkSkill[]` | `[]` | Custom skills to register |
| `skillDirectories` | `string[]` | `[]` | Additional explicit directories containing `<name>/SKILL.md` packages |
| `recursiveSkillDiscovery` | `bool` | `false` | Recursively discover nested Skill packages; shallow same-name packages win |

Tools, permission bypass, and durable storage are independent opt-ins. Merely
setting `apiKey`, `model`, or another connection option does not change them.

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

While sandbox mode is active, the SDK disables `Edit`, `apply_patch`,
`NotebookEdit`, `EnterWorktree`, `ExitWorktree`, `Agent`, and `SendMessage`.
Other host-only tools, including `LSP`, task/team tools, and cron tools, are not
sandbox replacements. Use an explicit `allowedTools` list and omit them unless
the host intentionally wants those capabilities alongside the sandbox.

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
export AGENTRUN_ACCOUNT_ID=1887527099427005
export AGENTRUN_API_KEY=ak_xxx
export AGENTRUN_TEMPLATE_NAME=sandbox-lagal
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
        accountId: getenv('AGENTRUN_ACCOUNT_ID'),
        sandboxId: getenv('AGENTRUN_SANDBOX_ID') ?: null,
        templateName: getenv('AGENTRUN_TEMPLATE_NAME') ?: 'sandbox-lagal',
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
| `thinkingBudget` | `int` | `10000` | Thinking token budget |
| `memorySummaryLevel` | `string` | `'l0'` | Memory injected into the system prompt: `l0`, `l1`, or `l2` |
| `memoryStoragePath` | `?string` | `null` | Isolated JSON memory file; defaults to `~/.haocode/memory.json` |
| `memoryStore` | `?MemoryStoreInterface` | `null` | Run-scoped custom store; takes precedence over `memoryStoragePath` |

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

// Helpers
$result->inputTokens();          // int
$result->outputTokens();         // int
```

`usage['input_tokens']` is the accumulated provider-reported input usage for
the run. When the provider reports prompt-cache telemetry,
`usage['cache_read_tokens']` contains the cached portion and
`usage['cache_creation_tokens']` contains explicit cache writes (Anthropic).

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

### Stateful Tools

By default, `SdkTool` is read-only (may be fork-executed in parallel). For stateful tools, override `isReadOnly`:

```php
class ShoppingCart extends SdkTool
{
    private array $items = [];

    public function handle(array $input): string
    {
        $this->items[] = $input['item'];
        return 'Cart: ' . implode(', ', $this->items);
    }

    // Required for state to persist across calls!
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
    allowedTools: ['Read', 'Grep'],  // optional: restrict tools during skill
    model: 'opus',                    // optional: model override
    context: 'inline',                // optional: inline or isolated fork
);

$result = HaoCode::query('Review auth.php for security', new HaoCodeConfig(
    allowedTools: ['Skill', 'Read', 'Grep'],
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
| Can restrict tools | Yes (`allowedTools`) | No |
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

`allowedTools` is enforced for the rest of the current user turn. Multiple
inline skills use the intersection of their tool sets. A skill model override
applies for the rest of that turn and is restored afterward. Forked skills
apply their tool and model settings only inside the child agent.
Standalone `!` shell directives in a skill are converted into normal `Bash`
tool requests; they do not bypass tool permissions, hooks, or skill tool scope.

---

## Streaming Messages

`HaoCode::stream()` yields `Message` objects with these types:

| Type | Fields | Description |
|------|--------|-------------|
| `turn` | `$msg->turnNumber` | A new agent turn started |
| `text` | `$msg->text` | Streaming text delta |
| `tool_start` | `$msg->toolName`, `$msg->toolInput` | Tool execution began |
| `tool_result` | `$msg->toolName`, `$msg->toolOutput`, `$msg->toolIsError` | Tool completed |
| `result` | `$msg->text`, `$msg->usage`, `$msg->cost`, `$msg->sessionId` | Final result |
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
already occurred.

Foreground child-agent interrupts bubble directly. Background agents and team
members enter `waiting_for_input`; `TeamAwait` and `TeamCollect` surface their
pending interrupt. Resolve the child interrupt using its own `sessionId`, then
collect the team again.

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
    allowedTools: ['Read', 'Write', 'Edit', 'Glob', 'Grep'],
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

// Later process — resume where we left off
$conv = HaoCode::resume($sessionId, $config);
$conv->send('What is the project codename?');

// Or just continue the latest session for the configured directory
$conv = HaoCode::continueLatest(__DIR__, $config);
$conv->send('What were we working on?');
```

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
);

echo $ticket->category;     // 'billing'
echo $ticket->priority;     // 'high'
echo $ticket['summary'];    // 'Customer reports duplicate charge' (ArrayAccess)
$ticket->toArray();          // full array
$ticket->toJson();           // JSON string

// Access underlying QueryResult for cost/usage
echo $ticket->queryResult->cost;
```

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
    maxBudgetUsd: 5.00,  // stop if cost exceeds $5
    allowedTools: ['Read', 'Edit', 'Glob', 'Grep'],
    permissionMode: 'bypass_permissions',
));
// Agent auto-stops at 80% ($4.00 warning) and 100% ($5.00 hard stop)
```

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
by provider errors and the pool's exhaustion TTL.

---

## Combining Tools + Skills

Tools and skills can be used together in a single query:

```php
$result = HaoCode::query('Run a full system health check', new HaoCodeConfig(
    allowedTools: ['Skill', 'Write'],
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

`composer test` runs the configured SDK compatibility suite. Use targeted paths
such as `./vendor/bin/phpunit tests/Feature/ContextBuilderTest.php` while working
on internal modules. See `tests/Feature/SdkE2ETest.php` for the current end-to-end
SDK examples.
