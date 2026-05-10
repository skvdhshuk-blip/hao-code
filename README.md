# hao-code

A framework-free PHP Agent SDK for Anthropic, OpenAI Responses, and OpenAI Chat Completions-compatible APIs.

`hao-code` lets PHP applications embed an AI coding agent with tools, skills, streaming output, multi-turn sessions, structured JSON results, cost tracking, abort control, credential pools, and isolated runtime storage.

For the complete SDK reference, see [docs/SDK.md](docs/SDK.md).

## Install

```bash
composer require sk-wang/hao-code
```

## Quick Start

```php
<?php

require __DIR__.'/vendor/autoload.php';

use HaoCode\Sdk\HaoCode;
use HaoCode\Sdk\HaoCodeConfig;

$result = HaoCode::query('Explain this repository', new HaoCodeConfig(
    apiKey: getenv('ANTHROPIC_API_KEY') ?: '',
    cwd: __DIR__,
    allowedTools: ['Read', 'Grep', 'Glob'],
));

echo $result->text;
```

## What It Provides

| Area | Capability |
| --- | --- |
| Agent execution | One-shot queries, streaming responses, multi-turn conversations, session resume, continue latest session |
| Providers | Anthropic, OpenAI Responses API, OpenAI Chat Completions-compatible gateways |
| Tools | Built-in file, search, patch, shell, web, MCP, task, memory, and planning tools, plus custom PHP tools |
| Sandbox | Optional temporary filesystem for `Read`, `Write`, `Glob`, `Grep`, and sandboxed `Bash` |
| Skills | Prompt-packaged domain guidance through `SdkSkill` |
| Structured output | JSON schema guided responses via `HaoCode::structured()` |
| Runtime control | Working directory, allowed tools, denied tools, permission mode, max turns, max tokens, thinking options |
| Operations | Cost budget, usage metadata, abort controller, callbacks for text/tool/turn events |
| State | Session IDs, conversation handles, memory summary levels, custom memory storage path |
| Reliability | Credential pools, rate-limit tracking, provider abstraction, SDK-only runtime without Laravel dependency |

## Main APIs

| Need | API |
| --- | --- |
| One-shot query | `HaoCode::query()` |
| Streaming messages | `HaoCode::stream()` |
| Multi-turn conversation | `HaoCode::conversation()` |
| Resume a session | `HaoCode::resume()` |
| Continue latest session | `HaoCode::continueLatest()` |
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
    permissionMode: 'bypass_permissions',
    allowedTools: ['Read', 'Grep', 'Glob'],
    disallowedTools: ['Bash'],
);
```

If no explicit config is provided, the SDK reads environment and settings values such as `ANTHROPIC_API_KEY`, `HAOCODE_MODEL`, `HAOCODE_API_BASE_URL`, and `HAOCODE_MAX_TOKENS`.

## Local Sandbox

Use the local sandbox when the agent needs a temporary workspace but must not
write to the PHP host project directory. This copies a text snapshot of `cwd`
into an isolated temp directory, then replaces file/search tools with sandbox
tools.

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
));
```

Set `mode: 'full'` to also replace `Bash` with a sandbox-scoped shell. Host-only
editing tools such as `Edit`, `apply_patch`, `NotebookEdit`, `Lsp`, worktree
tools, and sub-agent messaging are disabled while sandbox mode is active.

An experimental AgentRun backend is available behind the same sandbox interface.
Verify credentials and sandbox/template IDs first with `php scripts/agentrun-verify.php`,
then use `SandboxConfig::agentRun(...)`.

## Streaming

Use `HaoCode::stream()` when the caller needs incremental output:

```php
foreach (HaoCode::stream('Summarize the current project') as $message) {
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
$conversation = HaoCode::conversation(new HaoCodeConfig(cwd: __DIR__));

$conversation->send('Read the service layer and remember the architecture.');
$result = $conversation->send('Now review the newest changes.');

echo $result->text;
echo $conversation->getSessionId();
```

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
    tools: [$lookupOrder],
));
```

By default `SdkTool` is treated as read-only. Override `isReadOnly()` and return `false` for stateful or mutating tools.

## Custom Skills

Use `SdkSkill` to package reusable prompt guidance:

```php
use HaoCode\Sdk\SdkSkill;

$skill = new SdkSkill(
    name: 'security-review',
    description: 'Review code for common security risks.',
    prompt: 'Check $ARGUMENTS for injection, auth bypass, secrets, and unsafe IO.',
    allowedTools: ['Read', 'Grep'],
);

$result = HaoCode::query('Use security-review on app/Auth.php', new HaoCodeConfig(
    skills: [$skill],
));
```

## Credentials And Budgets

Use credential pools when you have multiple API keys, and cost budgets when the caller needs a hard spending guard:

```php
use HaoCode\Sdk\Credential;
use HaoCode\Sdk\CredentialPool;

$pool = new CredentialPool([
    new Credential(apiKey: getenv('ANTHROPIC_API_KEY_1') ?: ''),
    new Credential(apiKey: getenv('ANTHROPIC_API_KEY_2') ?: ''),
]);

$config = new HaoCodeConfig(
    credentialPool: $pool,
    maxBudgetUsd: 1.00,
);
```

## Callbacks And Abort

`HaoCodeConfig` supports callbacks for text deltas, tool starts, tool completions, and turn starts. It also supports `AbortController` for external cancellation:

```php
use HaoCode\Sdk\AbortController;

$abort = new AbortController();

$config = new HaoCodeConfig(
    abortController: $abort,
    onText: fn (string $delta) => print $delta,
    onToolStart: fn (string $name, array $input) => error_log("tool: {$name}"),
);
```

## Storage And Memory

Runtime data is stored under `~/.haocode/storage` by default when installed through Composer. Set `HAOCODE_STORAGE_PATH` for an application-specific runtime directory.

Session memory can be customized with `memorySummaryLevel` and `memoryStoragePath`:

```php
$config = new HaoCodeConfig(
    memorySummaryLevel: 'l1',
    memoryStoragePath: __DIR__.'/var/haocode-memory.json',
);
```

## Examples

| Example | Purpose |
| --- | --- |
| `examples/code-review-agent.php` | Code review workflow |
| `examples/support-ops-agent.php` | End-to-end support operations agent |
| `examples/weather-agent.php` | Custom tool example |
| `examples/sdk-suite/` | Focused examples for query, streaming, conversation, structured output, abort, credential pools, patching, MCP, and provider matrix |

## Documentation

- [Complete SDK reference](docs/SDK.md)
- [SDK backward compatibility policy](docs/sdk-bc-policy.md)
- [SDK example suite](examples/sdk-suite/README.md)

## Version

`v1.0.0` is the first SDK-only release.

## License

MIT
