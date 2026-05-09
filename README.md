# hao-code

A framework-free PHP Agent SDK for Anthropic, OpenAI Responses, and OpenAI Chat Completions-compatible APIs.

`hao-code` is now focused on the SDK surface: embed an agent in a PHP application, give it tools and skills, and receive typed results or streaming messages. The old interactive command-line application has been removed.

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
$config = new HaoCodeConfig(
    apiKey: getenv('OPENAI_API_KEY') ?: '',
    providerType: 'openai_chat',
    baseUrl: 'https://api.openai.com',
    model: 'gpt-4.1',
    maxTokens: 4096,
    cwd: __DIR__,
    permissionMode: 'bypass_permissions',
    allowedTools: ['Read', 'Grep'],
);
```

If no explicit config is provided, the SDK also reads environment variables such as `ANTHROPIC_API_KEY`, `HAOCODE_MODEL`, `HAOCODE_API_BASE_URL`, and `HAOCODE_MAX_TOKENS`.

## Custom Tools

```php
use HaoCode\Sdk\SdkTool;

$lookupOrder = new SdkTool(
    name: 'LookupOrder',
    description: 'Look up an order by ID.',
    schema: [
        'order_id' => ['type' => 'string', 'required' => true],
    ],
    handler: fn (array $input) => ['status' => 'paid'],
);

$result = HaoCode::query('Check order A123', new HaoCodeConfig(
    tools: [$lookupOrder],
));
```

## Storage

When used from a Composer-installed project, runtime data is stored under `~/.haocode/storage` by default. Set `HAOCODE_STORAGE_PATH` when you want an application-specific storage directory.

## Version

`v1.0.0` is the first SDK-only release.

## License

MIT
