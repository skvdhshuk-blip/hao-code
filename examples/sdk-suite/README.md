# SDK Demo Suite

11 standalone demos covering the main HaoCode SDK capabilities.

## Prerequisites

```bash
composer install          # from repo root
```

## Environment Variables

| Demo | Required Env | Optional Env |
|------|-------------|--------------|
| 01–08, 15 | `ANTHROPIC_API_KEY` | — |
| 07 | `ANTHROPIC_API_KEY` | `ANTHROPIC_API_KEY_2` (second key for pool) |
| 09 | — (npx + @modelcontextprotocol/server-filesystem) | — |
| 13 | — | — |
| 15 | `ANTHROPIC_API_KEY` | `OPENAI_API_KEY` |

All demos print `[skip]` to stderr and exit 0 when required env is absent.

## Demo Index

| # | File | Feature | Needs Key |
|---|------|---------|-----------|
| 01 | `01-one-shot.php` | `HaoCode::query()` single call + metadata | ANTHROPIC |
| 02 | `02-streaming.php` | `HaoCode::stream()` + text callbacks | ANTHROPIC |
| 03 | `03-conversation.php` | Multi-turn conversation + `HaoCode::resume()` | ANTHROPIC |
| 04 | `04-structured.php` | `HaoCode::structured()` JSON schema output | ANTHROPIC |
| 05 | `05-custom-tool.php` | Custom `SdkTool` subclass | ANTHROPIC |
| 06 | `06-abort.php` | `AbortController` mid-stream cancellation | ANTHROPIC |
| 07 | `07-credential-pool.php` | `CredentialPool` multi-key round-robin | ANTHROPIC |
| 08 | `08-apply-patch.php` | `apply_patch` envelope format | ANTHROPIC |
| 09 | `09-mcp-client.php` | MCP client connecting to filesystem server | npx |
| 13 | `13-execpolicy.php` | YAML policy + `PolicyMatcher` deny example | — |
| 15 | `15-provider-matrix.php` | Cross-provider alignment (anthropic/openai/openai_chat) | ANTHROPIC |

## Running

```bash
# Single demo
php examples/sdk-suite/01-one-shot.php

# All demos
for f in examples/sdk-suite/*.php; do
  echo "--- $f ---"
  php "$f"
done

# With key
ANTHROPIC_API_KEY=sk-ant-... php examples/sdk-suite/01-one-shot.php

# With multiple providers (demo 15)
ANTHROPIC_API_KEY=sk-ant-... OPENAI_API_KEY=sk-... php examples/sdk-suite/15-provider-matrix.php
```
