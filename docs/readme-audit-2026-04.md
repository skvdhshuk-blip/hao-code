# README 承诺对齐审计（2026-04）

## 审计范围

- `README.md`
- `docs/SDK.md`
- `app/Sdk/` — HaoCode、HaoCodeConfig、Conversation、QueryResult、SdkTool、SdkSkill、StructuredResult、AbortController
- `app/Services/` — Agent、Mcp、Hooks、Task、Memory、Api
- `app/Tools/` — Cron、Team、Skill、Task 等工具层

## 审计结论

| 维度 | 数量 |
|------|------|
| 承诺总条数 | 25 |
| ✅ 完整实现 | 19 |
| ⚠️ 部分实现（有壳、有差距） | 5 |
| ❌ 未实现 | 1 |

---

## 能力完成度总表（5 维度）

| 能力 | 存在性 | 完成度 | 测试覆盖 | docs 示例 | 差距（vs Claude Code/Codex/Hermes） |
|------|--------|--------|----------|-----------|--------------------------------------|
| `HaoCode::query()` | ✅ | 100% | ✅ SdkE2ETest | ✅ README+SDK.md | 无明显差距 |
| `HaoCode::stream()` | ✅ | 100% | ✅ SdkE2ETest | ✅ README+SDK.md | 无明显差距 |
| `HaoCode::conversation()` | ✅ | 100% | ✅ SdkE2ETest | ✅ SDK.md | 无明显差距 |
| `HaoCode::resume()` / `continueLatest()` | ✅ | 100% | ✅ SdkE2ETest | ✅ SDK.md | 无明显差距 |
| `HaoCode::structured()` | ✅ | 100% | ✅ SdkE2ETest | ✅ SDK.md | 无明显差距 |
| 自定义工具 `SdkTool` | ✅ | 100% | ✅ SdkE2ETest | ✅ README+SDK.md | 无明显差距 |
| 自定义技能 `SdkSkill` | ✅ | 100% | ✅ SdkE2ETest | ✅ SDK.md | 无明显差距 |
| Abort 控制 `AbortController` | ✅ | 100% | ✅ SdkE2ETest | ✅ SDK.md | 无明显差距 |
| 成本追踪 `cost/usage/maxBudgetUsd` | ✅ | 100% | ✅ CostTrackerTest | ✅ SDK.md | 无明显差距 |
| 流式回调 `onText/onToolStart/…` | ✅ | 100% | ✅ SdkE2ETest | ✅ SDK.md | 无明显差距 |
| 多 Provider（anthropic/openai/openai_chat） | ✅ | 100% | ✅ 各 ProviderTest | ✅ README | 无明显差距 |
| MCP 客户端 | ✅ | 80% | ✅ McpClientTest | ⚠️ 仅 `/mcp` 命令提及 | MCP server 配置文档缺失；README 完全未提 MCP |
| Teams（TeamManager + 工具） | ✅ | 90% | ✅ TeamToolsE2ETest | ✅ README | Teams 成员间通信依赖 `SendMessage`，但 README 描述略简 |
| Hooks（HookExecutor） | ✅ | 100% | ✅ HookExecutorTest | ✅ README | 无明显差距 |
| Skills（SkillLoader + SkillTool） | ✅ | 100% | ✅ SkillLoaderTest | ✅ README | 无明显差距 |
| Agent / 子 agent（AgentTool + BackgroundAgentManager） | ✅ | 85% | ✅ AgentToolTest | ⚠️ README 仅列工具名 | 并发子 agent 管理、stop/mailbox 机制未在文档中说明 |
| Cron（CronScheduler + 工具） | ✅ | 70% | ✅ CronToolsTest | ⚠️ README 仅列工具名 | 见「Cron + TaskManager 专节」 |
| TaskManager | ✅ | 70% | ✅ TaskManagerTest | ⚠️ README 仅列工具名 | 见「Cron + TaskManager 专节」 |
| AutoDream / Memory | ✅ | 80% | ✅ AutoDreamServiceTest | ❌ README 未提及 | 自动记忆整合机制对用户完全不可见 |
| Session HUD | ✅ | 90% | ✅ PromptHudStateTest | ⚠️ 仅 badge 提及 | 配置方式、可自定义项未文档化 |
| REPL 交互模式 | ✅ | 100% | N/A（CLI功能） | ✅ README | 无明显差距 |
| 观测性 Arize Phoenix | ✅ | 100% | ✅ PhoenixTracerTest | ✅ README | 无明显差距 |
| 权限模式 | ✅ | 100% | ✅ PermissionDecisionTest | ✅ README | 无明显差距 |
| LSP 工具 | ✅ | 90% | ✅ LspToolTest | ⚠️ README 仅列工具名 | 支持语言列表、服务器配置方式未文档化 |
| Worktree 工具 | ✅ | 100% | ✅ WorktreeToolsTest | ⚠️ README 仅列工具名 | 无明显差距 |

---

## 承诺逐条核对

### 1. `HaoCode::query()` — One-shot query

- **承诺位置**：README.md（L27–L29），docs/SDK.md（#query）
- **实现位置**：`app/Sdk/HaoCode.php::query()`
- **一致性**：✅
- **细节**：实现与文档完全一致。`echo $result`（Stringable）、`$result->cost`、`$result->usage`、`$result->sessionId` 均已实现。

### 2. `HaoCode::stream()` — 流式输出

- **承诺位置**：README.md（L31–L34），docs/SDK.md（#stream）
- **实现位置**：`app/Sdk/HaoCode.php::stream()`，使用 PHP Fiber 实现真实流式
- **一致性**：✅
- **细节**：`$msg->type === 'text'`、`'tool_start'`、`'result'` 等类型均已实现，与文档描述一致。

### 3. `HaoCode::conversation()` — 多轮对话

- **承诺位置**：README.md（L36–L39），docs/SDK.md（#conversation）
- **实现位置**：`app/Sdk/HaoCode.php::conversation()`，`app/Sdk/Conversation.php`
- **一致性**：✅
- **细节**：`$conv->send()`、`$conv->close()`、`getTurnCount()`、`getCost()`、`getSessionId()` 均已实现。

### 4. `HaoCode::resume()` / `continueLatest()` — 会话恢复

- **承诺位置**：README.md（L133–L135），docs/SDK.md（#resume--continuelatest）
- **实现位置**：`app/Sdk/HaoCode.php::resume()` 和 `continueLatest()`
- **一致性**：✅
- **细节**：两个方法均已实现，`HaoCodeConfig::sessionId` 和 `continueSession` 参数也已支持。

### 5. `HaoCode::structured()` — 结构化 JSON 输出

- **承诺位置**：README.md（L43–L45），docs/SDK.md（#structured）
- **实现位置**：`app/Sdk/HaoCode.php::structured()`，`app/Sdk/StructuredResult.php`
- **一致性**：✅
- **细节**：`$result->category`、`$result['priority']`（ArrayAccess）、`toArray()`、`toJson()` 均已实现。

### 6. 自定义工具 `SdkTool`

- **承诺位置**：README.md（L47–L49、L76–L99），docs/SDK.md（#custom-tools-sdktool）
- **实现位置**：`app/Sdk/SdkTool.php`
- **一致性**：✅
- **细节**：4 个方法（`name()`、`description()`、`parameters()`、`handle()`）均需实现，`isReadOnly()` 可选重写，与文档一致。

### 7. 自定义技能 `SdkSkill`

- **承诺位置**：README.md（feature table），docs/SDK.md（#custom-skills-sdkskill）
- **实现位置**：`app/Sdk/SdkSkill.php`，`app/Tools/Skill/SkillLoader.php::registerSkillDefinition()`
- **一致性**：✅
- **细节**：`$ARGUMENTS` 替换、`allowedTools`、`model` 覆盖均已实现。

### 8. Abort 控制

- **承诺位置**：README.md feature table，docs/SDK.md（#abort-controller）
- **实现位置**：`app/Sdk/AbortController.php`，`HaoCode::createLoop()` 中 `onAbort` 回调
- **一致性**：✅

### 9. 成本追踪

- **承诺位置**：README.md（L29 `$result->cost`），docs/SDK.md（#cost-tracking）
- **实现位置**：`app/Services/Cost/CostTracker.php`，`QueryResult::$cost`，`HaoCodeConfig::$maxBudgetUsd`
- **一致性**：✅
- **细节**：80%/100% 阈值预警在代码中已实现（`HaoCode::createLoop()`），文档描述准确。

### 10. 流式回调 `onText/onToolStart/onToolComplete/onTurnStart`

- **承诺位置**：docs/SDK.md（#haocodeconfig-reference Callbacks 表）
- **实现位置**：`app/Sdk/HaoCodeConfig.php`，在 `HaoCode::query()` 和 `stream()` 中均已传递
- **一致性**：✅

### 11. Multi-provider（anthropic/openai/openai_chat）

- **承诺位置**：README.md（L69、L181–L237）
- **实现位置**：`app/Services/Api/AnthropicProvider.php`、`OpenAiProvider.php`、`OpenAiChatProvider.php`
- **一致性**：✅
- **细节**：三种 provider type 均已实现，README 中的配置 JSON 示例与代码实现吻合。

### 12. MCP 客户端

- **承诺位置**：README.md（仅在 Slash Commands 中列了 `/mcp`，L342），无正面承诺
- **实现位置**：`app/Services/Mcp/McpClient.php`（260 行），`McpConnectionManager.php`、`McpTransport.php`、`McpDynamicTool.php`
- **一致性**：⚠️
- **细节**：MCP 客户端实现完整（初始化握手、工具发现、资源读取），但 README 完全没有正面介绍 MCP 能力，只在 slash commands 列表中隐约提了 `/mcp`。`docs/SDK.md` 也没有 MCP 相关内容。这是文档欠债，不是代码问题。

### 13. Teams

- **承诺位置**：README.md（L389–L414），feature table（L9 badge）
- **实现位置**：`app/Services/Agent/TeamManager.php`、`app/Tools/Team/TeamCreateTool.php` 等
- **一致性**：✅
- **细节**：`TeamCreate`、`TeamList`、`TeamDelete`、`SendMessage(to: "team:<name>")` 均已实现，`TeamManager.memberAgentId()` 生成确定性 agent ID 符合文档描述。

### 14. Hooks

- **承诺位置**：README.md（L354–L367 Permissions and Hooks 节）
- **实现位置**：`app/Services/Hooks/HookExecutor.php`
- **一致性**：✅
- **细节**：8 个 hook 事件（`SessionStart`、`Stop`、`PreToolUse`、`PostToolUse`、`PostToolUseFailure`、`PreCompact`、`PostCompact`、`Notification`）在 README 中列出，HookExecutor 从 settings.json 动态加载，JSON 输出可修改 input，与文档一致。

### 15. Skills

- **承诺位置**：README.md（L373–L381 Skills 节），docs/SDK.md
- **实现位置**：`app/Tools/Skill/SkillLoader.php`、`app/Tools/Skill/SkillTool.php`
- **一致性**：✅
- **细节**：从 `~/.haocode/skills/` 和 `.haocode/skills/` 两个路径加载，YAML frontmatter 解析，`$ARGUMENTS` 替换，均与文档一致。

### 16. Agent / 子 agent

- **承诺位置**：README.md（L162 feature table 列了 `Agent`、`SendMessage`）
- **实现位置**：`app/Tools/Agent/AgentTool.php`、`app/Services/Agent/BackgroundAgentManager.php`
- **一致性**：⚠️
- **细节**：子 agent 执行（并发、mailbox 通信、stop 请求）在代码中有完整实现，但 README 仅用一行 feature table 带过。并发子 agent 管理、mailbox 机制、`AgentDefinition` 等高级特性对用户完全不可见。

### 17. Session HUD

- **承诺位置**：README.md（L9 badge 列出 `Session HUD`）
- **实现位置**：`app/Services/...`（PromptHudState 相关）
- **一致性**：⚠️
- **细节**：功能存在，但 README 仅在 badge 行列出，无任何说明或配置文档。

### 18. REPL 交互模式

- **承诺位置**：README.md（L130 `hao-code`）
- **实现位置**：`app/Console/Commands/HaoCodeCommand.php`
- **一致性**：✅

### 19. 观测性（Arize Phoenix / OTEL）

- **承诺位置**：README.md（L249–L298 Observability 节）
- **实现位置**：`app/Services/Telemetry/PhoenixTracer.php`、`SafeSpanProcessor.php`
- **一致性**：✅

### 20. AutoDream / Memory（自动记忆整合）

- **承诺位置**：README.md 未提及（只有 `/memory` slash command）
- **实现位置**：`app/Services/Memory/AutoDreamService.php`、`DreamConsolidator.php`、`SessionMemory.php`
- **一致性**：⚠️（有实现但无文档）
- **细节**：24 小时 + 5 次会话触发的自动记忆整合逻辑完整实现。README 对此功能完全沉默，用户无从知晓有此特性。

---

## Cron + TaskManager 专节（面向 M4 Spec 输入）

### 当前实现状态

**CronScheduler**（`app/Tools/Cron/CronScheduler.php`）：

- ✅ 内存中 job 存储 + 可选磁盘持久化（`~/.haocode/scheduled_tasks.json`）
- ✅ 5 字段 cron 表达式解析（支持 `*`、`*/N`、范围、列表）
- ✅ `checkDue()` 轮询机制（防重复触发：60 秒内不再次执行）
- ✅ 单次/循环 job（`recurring` 标志）
- ✅ 7 天自动过期
- ❌ **无调度驱动**：`checkDue()` 仅在被主循环调用时触发，没有独立的定时器进程或 Laravel Scheduler 集成
- ❌ **无跨进程持久调度**：进程退出后，内存 jobs 丢失，只有标记为 `durable` 的 job 才会持久化

**TaskManager**（`app/Services/Task/TaskManager.php`）：

- ✅ 任务 CRUD（create/get/list/update/stop/remove）
- ✅ 状态机（pending → in_progress → completed/failed）
- ✅ 磁盘持久化（`/tmp/haocode_tasks/tasks.json`）
- ✅ 24 小时自动清理过期任务
- ❌ **无后台执行引擎**：TaskManager 是纯状态管理，不负责实际执行任务
- ❌ **无任务依赖/优先级**：任务列表是平铺的，无 DAG、优先级队列等

### 与 Claude Code / Hermes 的差距

| 特性 | hao-code 现状 | Claude Code | Hermes |
|------|--------------|-------------|--------|
| Cron 调度持久化 | 需 `durable` 标志，临时进程丢失 | 进程内，无 cron | 独立 daemon，持久化 |
| 远程触发 | ❌ 无 | ❌ 无 | ✅ webhook |
| 跨进程唤醒 | ❌ 无 | ❌ 无 | ✅ |
| 任务优先级 | ❌ 无 | ✅ 任务树 | ✅ 队列优先级 |
| 任务依赖 DAG | ❌ 无 | ❌ 无 | ✅ |
| Docker/SSH 执行 | ❌ 无（M4 目标） | ❌ 无 | ✅ |

### M4 Spec 候选差距列表

1. **Cron 调度持久 daemon**：无进程依赖的定时调度（`cron.d` 或 Laravel Scheduler 集成），支持机器重启后恢复
2. **TaskManager 执行引擎**：任务从"状态数据"升级为"可执行单元"，后台跑 AgentLoop
3. **远程运行**：通过 SSH/Docker 在远端执行 agent，本地轮询结果
4. **任务优先级 + 依赖**：支持 `depends_on`、`priority` 字段，任务树执行
5. **跨进程唤醒机制**：进程退出后 cron/task 仍能在下次启动时续行

---

## 问题清单（对齐失败 / 文档欠债）

### 1. MCP 客户端在 README 中完全缺失（严重度：中）

- **承诺**：README badge 行列出了功能集，但无 MCP 相关承诺
- **实际**：完整的 MCP 客户端（初始化握手、工具/资源发现、动态工具代理）已实现
- **影响**：用户不知道 hao-code 支持 MCP server，无法配置使用
- **建议**：在 README 新增「MCP 集成」小节，说明配置方式（`~/.haocode/settings.json` 中的 `mcp` 块）

### 2. AutoDream / Memory 自动整合对用户不可见（严重度：低）

- **承诺**：README 仅列出 `/memory` slash command，无自动整合的任何说明
- **实际**：`AutoDreamService` 实现了 24h + 5 次会话触发的自动整合
- **影响**：用户不知道有此特性，也不知道如何配置触发条件
- **建议**：在 README「Configuration」节补充 `memory` 配置项说明

### 3. Agent / 子 agent 并发特性文档不足（严重度：低）

- **承诺**：README feature table 仅 `Agent, SendMessage`
- **实际**：`BackgroundAgentManager` 支持多 agent 并发、mailbox 通信、进程状态追踪
- **影响**：高级用户无法了解完整能力
- **建议**：README「Built-in Tools」下补充子 agent 使用示例

### 4. Session HUD 无配置文档（严重度：低）

- **承诺**：badge 行提到 `Session HUD`
- **实际**：功能存在但未文档化
- **建议**：在 README 补充 HUD 的启用/配置说明

### 5. Cron + TaskManager 能力被低估（严重度：中）

- **承诺**：README feature table 仅列工具名（`CronCreate/Delete/List`、`TaskCreate/Get/List/Update/Stop`）
- **实际**：有完整的 cron 解析引擎和 task 状态机，但文档完全沉默
- **影响**：用户不知道如何用 cron 表达式调度任务
- **建议**：在 README 新增「Scheduled Tasks & Automation」小节（最短示例 + 说明限制）

---

## 建议改动分类

### 建议在本 PR 修改 README（文档欠债补录）

1. **新增 MCP 集成节**（「MCP Servers」，20–30 行）：配置示例 + 链接到 `/mcp` slash command
2. **新增 Scheduled Tasks 节**（「Scheduled Tasks」，15–20 行）：CronCreate 基本示例 + 限制说明
3. **在 Configuration 节补充 memory 配置项**（5 行）

### 建议作为独立工作项处理（代码层改进）

| 工作项标题 | 优先级 | 说明 |
|-----------|--------|------|
| Cron 持久 daemon（M4 Spec） | 高 | 进程无关的 cron 调度，重启可恢复 |
| TaskManager 执行引擎（M4 Spec） | 高 | 任务从状态数据升级为可执行单元 |
| MCP 配置文档 + SDK.md 补充 | 中 | `/mcp` 命令文档、`settings.json` MCP 配置格式 |
| AutoDream 配置暴露 | 低 | 允许用户配置 `minHours`/`minSessions` 触发条件 |
| Sub-agent 高级用法文档 | 低 | BackgroundAgentManager 完整示例 |
