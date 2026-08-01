# MCP 客户端现状审计（截至 2026-08-01）

> 文件名保留历史日期，避免破坏已有链接；以下内容以当前工作区代码为准，基线为
> `v1.18.54`。这不是协议能力路线图，只记录已经存在的 MCP 功能、边界和剩余的
> 现有行为问题。

## 结论

旧版“39% 完成度”审计已经失效。当前客户端已经覆盖初始化协商、tools/resources/
prompts、分页、通知分发、反向 `roots/list`、ping、日志级别、completion、OAuth、
Streamable HTTP 会话恢复和显式重连等能力。

当前 MCP 代码的主要质量特征是：

- `McpClient` 把协议能力翻译成稳定的 PHP 方法，不把 JSON-RPC 细节泄漏到 Agent
  工具层。
- `McpTransport` 同时承担 stdio、Streamable HTTP 和 SSE 响应解析，并负责入站通知
  与反向请求的分发。
- 列表分页、stdio 帧、SSE、HTTP 响应、stderr 和 MCP 工具输出都有边界。
- 失败连接不会阻断其它 MCP server；连接管理器保留每个 server 的失败状态。

## 当前能力矩阵

| 能力 | 当前行为 | 代码落点 |
|------|----------|----------|
| initialize / initialized | 客户端发送 `2025-11-25`，接受已实现的历史版本；服务端返回未知版本时 fail-closed | `McpClient::connect()` |
| tools/list + tools/call | 支持缓存、`nextCursor` 分页、annotations、统一超时和 HTTP session 恢复 | `McpClient::listTools()` / `callTool()` |
| resources/list + resources/read | 支持缓存、分页、超时、session 恢复和多 server 聚合 | `McpClient` / `McpConnectionManager` |
| prompts/list + prompts/get | 支持能力检查、缓存、分页、参数和多 server 聚合 | `McpClient` / `McpConnectionManager` |
| notifications | 支持按 method 注册多个 handler；`tools/resources/prompts/list_changed` 会清缓存 | `McpTransport::onNotification()` |
| reverse RPC | 支持服务端发起 `roots/list`，未知 method 返回 JSON-RPC `-32601` | `McpTransport::onRequest()` |
| ping / logging / completion | 已有 `ping()`、`setLogLevel()`、`complete()` 方法和 OTEL span | `McpClient` |
| stdio | argv 形式启动子进程，限制环境继承，非阻塞读写，捕获并脱敏 stderr | `McpTransport` |
| Streamable HTTP / SSE | 支持 JSON、增量 SSE、session id、GET event stream、`Last-Event-ID` 和 `retry` | `McpTransport` |
| OAuth | 支持 client credentials / refresh token；secret 只从环境变量读取 | `McpOAuthTokenProvider` |
| 生命周期 | `close()` 清理 server tree、pipe、HTTP session 和 event stream；manager 支持显式重连 | `McpTransport` / `McpConnectionManager` |

## 关键实现与边界

### 初始化与协议版本

`McpClient` 当前声明的最新协议版本是 `2025-11-25`，同时接受：

- `2025-11-25`
- `2025-06-18`
- `2025-03-26`
- `2024-11-05`
- `2024-10-07`

服务端返回缺失或未知版本时，初始化抛出 protocol 类型的
`McpConnectionException`，不会继续使用未协商的协议。连接失败会清理 client 状态、
缓存和 transport。

客户端声明支持 `roots.listChanged`，并通过 `setRoots()` 提供服务端请求
`roots/list` 时返回的 workspace roots。

### 列表分页与资源边界

`tools/list`、`resources/list` 和 `prompts/list` 都走同一个分页循环：

- 单次操作最多 100 页；
- 聚合最多 10,000 个条目；
- 聚合 JSON 体最多 16 MiB；
- cursor 最多 16 KiB；
- 整个分页操作共享一个绝对 deadline，而不是每页重新获得完整超时。

缓存只保留已经成功完成的列表。服务端发送对应的 `list_changed` 通知后，内置
handler 会清除对应缓存；调用方也可以使用 `clearCache()` 主动清理。

### 入站通知与反向请求

`McpTransport` 不再丢弃没有 `id` 的入站通知：它会按 method 调用已注册的 handler。
带有 `id` 和 `method` 的入站 JSON-RPC 请求会进入 request handler，并返回成功结果或
标准错误响应。

目前内置处理的是：

- `notifications/tools/list_changed`
- `notifications/resources/list_changed`
- `notifications/prompts/list_changed`
- `roots/list`

其它通知（例如 `notifications/message`、资源更新通知）可以通过
`McpClient::registerNotificationHandler()` 接入宿主应用。handler 异常会被隔离，避免
破坏 transport 读循环；如果宿主需要记录这些异常，应在自定义 handler 内完成。

### stdio 进程与 HTTP 流

stdio 只继承安全的启动环境和配置中显式提供的 `env`，不会隐式把宿主全部环境变量
传给 MCP server。stdout/stderr 使用非阻塞 pipe，stderr 保留在有界缓冲区中，并在
错误预览和 `drainStderr()` 中做 token、key 和绝对路径脱敏。

边界如下：

- stdio 未终止 JSONL 帧或读缓冲超过 4 MiB 时拒绝继续解析；
- HTTP JSON/error body 不超过 4 MiB；
- SSE decoder 使用有界缓冲；
- stderr 保留最多 32 KiB；
- HTTP event stream 采用协作式 `poll()`，不会在 PHP 进程中偷偷创建后台线程。

Streamable HTTP 支持请求级 POST 响应，也支持独立 GET event stream。GET 返回 405 时，
仍可继续使用请求级响应。连接关闭时会尽力发送 DELETE，并清理 session 和子进程资源。

### Session 恢复与显式重连

HTTP session 过期时，当前请求在绝对 deadline 内重新 initialize、清理列表缓存并重发
一次。连接管理器的 `reconnect()` 最多执行 3 次连接尝试，失败之间使用 500ms、1000ms
退避；`ensureConnected()` 会在 client 不再连接时触发它。

这不是后台自动重连：`McpConnectionManager::poll()` 只记录连接失败，不会无条件重启
server。宿主需要在合适的生命周期点调用 `ensureConnected()` 或 `reconnect()`，这样
不会在一个长时间运行的 PHP worker 中产生不可控的重启循环。

## 仍需注意的现有行为

以下不是“缺少新协议功能”，而是当前已有行为的边界，调用方应明确处理：

1. stdio 没有独立的后台读循环。通知和反向请求会在下一次请求的读写过程中被处理；
   如果 server 在长时间空闲期间只发送通知，宿主需要主动触发合适的 I/O 生命周期。
2. `setRoots()` 当前更新本地 roots，供后续 `roots/list` 使用；它不会自动向 server
   发送 roots 变更通知。若宿主在连接建立后改变 roots，应在自己的协议生命周期中处理
   这一点。
3. `setLogLevel()`、`complete()` 等低层方法由 server 的能力和错误响应决定；客户端
   不会为了调用方静默伪造服务端能力。
4. MCP 动态工具在进入 Agent 消息前仍有独立的结果输出上限；原始 server 响应不会
   无界地进入上下文。

## 旧审计结论的复核

| 旧审计结论 | 当前状态 |
|------------|----------|
| prompts 完全未实现 | 已有 `listPrompts()`、`getPrompt()`、能力检查、分页和 manager 聚合 |
| 入站通知全部丢弃 | 已有按 method 的通知 handler 和缓存失效 |
| `roots/list` 无响应 | 已有 roots 注册与反向请求响应 |
| tools/resources 无分页 | 三类 list 均使用 `nextCursor` 循环和总量边界 |
| 没有 ping / logging / completion | `ping()`、`setLogLevel()`、`complete()` 已存在 |
| protocol version 写死且不校验 | 发送最新版本，并校验服务端版本是否在支持集合内 |
| stderr 直接关闭 | stdio stderr 已捕获、限长并脱敏 |
| HTTP session 过期不会恢复 | `McpSessionExpiredException` 触发一次 deadline 内重初始化和重发 |
| manager 没有重连 | 已有显式 `reconnect()`、退避和 `ensureConnected()` |

旧报告中关于 `resources/subscribe`、sampling、progress 等未纳入当前工具面的协议域，
属于其它协议能力，不应在“只打磨已有功能”的工作中伪装成回归项或顺手扩展范围。

## 维护约束

- 修改 MCP transport 或 client 时，至少覆盖：stdio、HTTP/SSE、分页、session 过期、
  反向请求、通知 handler 和资源上限。
- 任何新增的读取路径都必须保留 deadline、帧/响应上限和敏感信息脱敏。
- 连接失败应保持 server 级隔离；不要因为单个 MCP server 失败而让其它 server 的
  工具发现和调用失效。
- 文档中的协议版本、能力矩阵和边界以 `app/Services/Mcp/` 当前实现为准；不要继续
  使用旧的“39% 完成度”数字。
