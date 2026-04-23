# hao-code 作为 MCP server

hao-code 可作为 stdio JSON-RPC MCP server 给 Claude Code / Cursor / Codex 等 host 调用。

## 3 分钟快速上手

```bash
composer require sk-wang/hao-code
bin/hao-code mcp-serve --preset laravel --root . --tool App\\Tools\\LookupOrderTool
```

### Claude Code 配置

`.mcp.json`：

```json
{
  "mcpServers": {
    "hao-code": {
      "command": "bin/hao-code",
      "args": ["mcp-serve", "--preset", "laravel", "--root", ".", "--tool", "App\\\\Tools\\\\LookupOrderTool"]
    }
  }
}
```

### Cursor 配置

`.cursor/mcp.json`：

```json
{
  "mcpServers": {
    "hao-code": {
      "command": "bin/hao-code",
      "args": ["mcp-serve", "--preset", "laravel", "--root", ".", "--tool", "App\\\\Tools\\\\LookupOrderTool"]
    }
  }
}
```

重启 Host，hao-code MCP server 即可被调用。

## Preset

- `strict`：只允许 read / grep / glob 在 --root 下
- `laravel`：strict + read/grep 全覆盖 `./app/**`
- `laravel-dev`：laravel + 允许 `bash:./composer ./artisan` + `write:./storage/**`

## 安全说明

- **默认 ALL-DENY**：Bash / Write / Edit 必须显式 `--allow`，无 `--preset` 且无 `--allow` 时全拒
- **敏感路径黑名单**：`.env*` / `*.key` / `id_rsa*` / `*.pem` / `.git/config` / `~/.ssh/` / `~/.aws/` 即使 `--allow read:./**` 也拒
- **SdkTool 副作用由用户代码负责**：自定义 tool 的 `execute()` 里若访问外部资源，由你自己控制
- **Skills prompts**：暴露给外部 host 的 skill 必须在 skill markdown frontmatter 标 `public: true`，否则 server 不列出
- **bypass_permissions fail-fast**：MCP server 进程启动时检测到 `bypass_permissions=true` 立即退出非 0

## 集成测试

在 hao-code 仓库里：

```bash
vendor/bin/phpunit tests/Feature/Mcp/ServerIntegrationTest.php --testdox
```

覆盖 6 条路径（initialize / tools list / tools call / blacklist / symlink / bypass）。
