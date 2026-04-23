# MCP 客户端完成度审计（2026-04-23）

## 审计范围

| 文件 | 行数 |
|------|------|
| `app/Services/Mcp/McpClient.php` | 260 |
| `app/Services/Mcp/McpTransport.php` | 425 |
| `app/Services/Mcp/McpConnectionManager.php` | 256 |
| `app/Services/Mcp/McpServerConfigManager.php` | 271 |
| **合计** | **1212** |

审计依据：MCP 协议规范 2024-11-05，覆盖 9 个协议域。

---

## 总体完成度

**39%（3.5/9 协议域完整）**

完整实现：initialize/initialized、tools/list + tools/call、resources/list + resources/read。
部分实现：notifications（仅发送 initialized 单条）。
完全缺失：prompts、roots（反向请求）、logging、completion/complete、ping。

---

## 协议域逐项

### 1. initialize / initialized

- **完成度：95%**
- **代码证据：**
  - `app/Services/Mcp/McpClient.php:62-84` — 发送 `initialize` 请求，携带 `protocolVersion: 2024-11-05`、`capabilities.roots.listChanged: true`、`clientInfo`
  - `app/Services/Mcp/McpClient.php:82` — 发送 `notifications/initialized` 通知
  - `app/Services/Mcp/McpClient.php:77-79` — 保存 `capabilities`、`serverInfo`、`instructions`
- **缺口：**
  - P1：`protocolVersion` 协商写死 `2024-11-05`，服务端返回不同版本时无 fail-fast 处理（Spec 已决议本轮 fail-fast，不做降级，但缺少版本校验逻辑）
  - P1：`capabilities` 声明 `roots.listChanged: true` 但客户端从未处理服务端发来的 `roots/list` 请求（声明与实现不一致）

---

### 2. tools/list + tools/call

- **完成度：85%**
- **代码证据：**
  - `app/Services/Mcp/McpClient.php:109-138` — `listTools()`，支持缓存，解析 `name/description/inputSchema/annotations`
  - `app/Services/Mcp/McpClient.php:146-167` — `callTool()`，支持 `timeoutSeconds` 参数，返回 `content/isError/structuredContent`
  - `app/Services/Mcp/McpConnectionManager.php:125-152` — `discoverAllTools()`，聚合多 server 工具，生成 `mcp__<server>__<tool>` 命名
- **缺口：**
  - P1：`tools/list` 无 `cursor` 分页支持（服务端有 cursor 时只拿第一页）
  - P1：`tools/list` 无 `nextCursor` 迭代逻辑
  - P1：`notifications/tools/list_changed` 通知到达时缓存不会自动失效（仅有手动 `clearCache()`）

---

### 3. resources/list + resources/read + resources/subscribe

- **完成度：55%**
- **代码证据：**
  - `app/Services/Mcp/McpClient.php:175-209` — `listResources()`，支持缓存，解析 `uri/name/mimeType/description`
  - `app/Services/Mcp/McpClient.php:217-228` — `readResource()`，发送 `resources/read`，返回 `contents`
  - `app/Services/Mcp/McpConnectionManager.php:158-180` — `discoverAllResources()`，聚合多 server 资源
- **缺口：**
  - P1：`resources/list` 无分页（cursor）支持
  - P1：`notifications/resources/list_changed` 到达时缓存不失效
  - P2（下轮新工作项）：`resources/subscribe` 完全未实现——MCP 协议中客户端可订阅特定资源的变更通知，服务端发 `notifications/resources/updated`；当前无订阅注册、无通知监听、无取消订阅接口

---

### 4. prompts/list + prompts/get

- **完成度：0%**
- **代码证据：无**
- **缺口：**
  - P0：`prompts/list` 完全未实现（无方法、无缓存、无能力检查 `capabilities.prompts`）
  - P0：`prompts/get` 完全未实现（无参数构造、无响应解析）
  - P0：`McpConnectionManager` 缺少 `discoverAllPrompts()` 聚合方法
  - P1：`notifications/prompts/list_changed` 无处理

---

### 5. notifications/*（分发基础设施）

- **完成度：10%**
- **代码证据：**
  - `app/Services/Mcp/McpClient.php:82` — 仅发送 `notifications/initialized`（单向出站）
  - `app/Services/Mcp/McpTransport.php:99-111` — `notify()` 方法支持发送通知
  - `app/Services/Mcp/McpTransport.php:237-239` — `sendStdio` 读循环中跳过无 `id` 的消息（即悄悄丢弃所有入站通知）
- **缺口：**
  - P0：完全没有入站通知分发器——服务端发来的所有 `notifications/*` 消息（`tools/list_changed`、`resources/list_changed`、`resources/updated`、`prompts/list_changed`、`roots/list_changed` 等）均被丢弃
  - P0：无 `registerNotificationHandler(string $method, callable $handler)` 机制
  - P1：`list_changed` 类通知未触发缓存失效

---

### 6. roots/list + roots/list_changed（反向请求）

- **完成度：5%**
- **代码证据：**
  - `app/Services/Mcp/McpClient.php:65` — 声明 `capabilities.roots.listChanged: true`（仅声明，无实现）
- **缺口：**
  - P0：服务端向客户端发起的 `roots/list` 请求（反向 JSON-RPC）完全无处理——当前读循环（`McpTransport:237`）遇到带 `method` 但无 `id` 的消息跳过（实际 `roots/list` 有 `id`，会卡在等待响应却永远不响应，造成协议死锁）
  - P0：无客户端 workspace roots 注册接口（`setRoots(array $roots)`）
  - P1：`notifications/roots/list_changed` 事件无处理（服务端通知客户端根目录变更）

---

### 7. logging/setLevel + log message

- **完成度：0%**
- **代码证据：无**
- **缺口：**
  - P1：`logging/setLevel` 完全未实现——客户端应能调用此方法控制服务端日志级别
  - P1：服务端发来的 `notifications/message`（log message）无接收与转发逻辑
  - P1：无 OTEL 集成将 MCP server 日志纳入 span（Spec Acceptance Criteria 要求每个 RPC 有 `mcp.client.request.<method>` span）

---

### 8. completion/complete

- **完成度：0%**
- **代码证据：无**
- **缺口：**
  - P1：`completion/complete` 完全未实现——用于 prompt/resource 参数的自动补全
  - P1：无参数构造（`ref` 字段区分 `ref/prompt` / `ref/resource`），无响应解析

---

### 9. ping

- **完成度：0%**
- **代码证据：无**
- **缺口：**
  - P1：`ping` 方法完全未实现（双向，客户端和服务端均可发起）
  - P1：无心跳保活机制（长连接 stdio 进程静默后无检测）

---

## 错误处理 / 超时 / 重连完善度

### 错误处理

| 场景 | 现状 | 评估 |
|------|------|------|
| JSON-RPC `error` 字段 | 抛 `McpConnectionException`，含 code + message | 良好 |
| HTTP 4xx/5xx | 已分类处理 401/404/其他 | 良好 |
| curl 失败 | 抛异常，含 curl 错误信息 | 良好 |
| stdio 写失败 | 抛异常 | 良好 |
| 无效 initialize 响应 | 抛异常 | 良好 |
| 错误分类（protocol/transport/application） | 仅有单一 `McpConnectionException`，无分类 | 缺口 P1 |
| stderr 捕获 | `McpTransport:188` 直接关闭 stderr pipe（`@fclose($pipes[2])`），服务端错误日志丢失 | 缺口 P1 |

### 超时

| 场景 | 现状 | 评估 |
|------|------|------|
| `connect()` 超时 | 参数传递但仅用于 initialize RPC，stdio `proc_open` 无连接超时 | 部分 |
| `callTool()` 超时 | 支持 per-call `timeoutSeconds`，默认 60s | 良好 |
| `listTools()` 超时 | 无超时参数，使用 transport 默认 60s | 可改进 |
| `listResources()` / `readResource()` 超时 | 同上 | 可改进 |
| HTTP `CURLOPT_CONNECTTIMEOUT` | 固定 10s | 良好 |
| per-call timeout override | 仅 `callTool` 暴露，其余方法不可 override | 缺口 P1 |

### 重连

| 场景 | 现状 | 评估 |
|------|------|------|
| stdio 进程退出检测 | `isConnected()` 通过 `proc_get_status` 检测 | 有检测 |
| 自动重连 | 完全无 | 缺口 P0 |
| 指数退避 | 无 | 缺口 P0 |
| 最大重试次数 | 无 | 缺口 P0 |
| HTTP session 过期重连 | 404 时清 session ID 并抛异常，上层不会自动重试 | 缺口 P1 |
| 重连后缓存清除 | `clearCache()` 方法存在但无自动触发 | 缺口 P1 |

---

## 缺口汇总表

| 缺口 | 优先级 | 建议（PR B） |
|------|--------|-------------|
| `prompts/list` + `prompts/get` 实现 | P0 | `McpClient::listPrompts()` + `getPrompt()` |
| 入站通知分发器（`registerNotificationHandler`） | P0 | `McpTransport` 读循环分发 + `McpClient` 注册接口 |
| `roots/list` 反向请求响应 | P0 | 读循环检测有 `id`+`method` 的入站请求，回 JSON-RPC response |
| 断线重连（指数退避，最大 3 次） | P0 | `McpConnectionManager::reconnect()` |
| `ping` 心跳 | P1 | `McpClient::ping()` |
| `logging/setLevel` | P1 | `McpClient::setLogLevel()` |
| `completion/complete` | P1 | `McpClient::complete()` |
| `list_changed` 通知清缓存 | P1 | 通知 handler 自动调 `clearCache()` |
| per-call timeout 全方法暴露 | P1 | `listTools/listResources/readResource` 加 `$timeout` 参数 |
| stderr 捕获（纳入 OTEL） | P1 | 保留 stderr pipe，异步读取，记录 span event |
| 错误分类（protocol/transport/application） | P1 | 扩展 `McpConnectionException` 或新增子类 |
| HTTP session 过期自动重试 | P1 | `sendHttp` 捕获 404 后重新 initialize 并重发 |
| `protocolVersion` 版本校验 fail-fast | P1 | initialize 响应对比版本，不匹配抛异常 |
| `resources/subscribe` + `notifications/resources/updated` | P2（下轮新工作项） | 独立工作项：订阅 + 通知 + 取消订阅 |
| `sampling`（server 反向调 client LLM） | P2（下轮新工作项） | 独立工作项 |
| `progress notifications` | P2（下轮新工作项） | 独立工作项 |
| `cancellation` | P2（下轮新工作项） | 独立工作项 |
| HTTP / SSE transport 替代 stdio | P2（下轮新工作项） | 独立工作项 |

---

## 下一步（PR B 范围）

补齐 P0（4 项）+ P1（9 项），预估 ~380 行：

- `McpClient.php` 增量 ~180 行
  - `listPrompts()` / `getPrompt()`（+40 行）
  - `ping()`（+15 行）
  - `setLogLevel()`（+15 行）
  - `complete()`（+25 行）
  - `registerNotificationHandler()` + 反向请求响应接口（+30 行）
  - `setRoots()` + roots 响应逻辑（+20 行）
  - per-call timeout 参数补充（+10 行）
  - protocolVersion fail-fast 校验（+10 行）
  - HTTP session 过期自动重试（+15 行）

- `McpTransport.php` 增量 ~50 行
  - 读循环入站通知/请求分发（+30 行）
  - stderr 捕获（+20 行）

- `McpConnectionManager.php` 增量 ~60 行
  - `reconnect()` + 指数退避（+40 行）
  - `discoverAllPrompts()`（+20 行）

- `tests/Feature/Mcp/ClientTest.php` 新增 ~90 行
  - P0/P1 单元测试 + filesystem server 冒烟测试（`--group mcp-filesystem`）

**P2 五项全部推迟，各自独立建工作项。**
