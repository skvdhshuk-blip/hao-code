# AGENTS.md — hao-code

## 1. 项目定位

`hao-code` 是 framework-free PHP Agent SDK，Composer 包名是 `sk-wang/hao-code`。它给 PHP 应用嵌入 AI coding agent 能力，覆盖 Anthropic、OpenAI Responses API、OpenAI Chat Completions 兼容网关，并提供 tools、skills、streaming、多轮会话、结构化输出、成本统计、Abort、credential pool、沙箱运行时和 SDK-only storage。

运行栈以 `composer.json` 为准：PHP `^8.1`，Symfony 组件 `^6.4 || ^7.0`，测试用 PHPUnit `^10.5 || ^11.5`。不要把扫描脚本从 `policies/laravel-dev.yml` 或旧文档示例里识别出的关键词当成主栈证据；主仓定位看 `composer.json`、`README.md`、`docs/SDK.md` 和 `app/Sdk/`。

## 2. 命令

- 安装依赖：`composer install`
- 全量测试：`composer test`
- 定向 PHPUnit：`./vendor/bin/phpunit tests/Feature/ContextBuilderTest.php`
- PHP 语法检查：`composer run lint`
- Composer 元数据校验：`composer validate --strict`
- 安全审计：`composer audit --format=plain`
- SDK 公共 API 快照校验：`php scripts/sdk-bc-check.php --verify`
- 故意改变公开 API 后更新快照：`php scripts/sdk-bc-check.php --write`
- AgentRun 沙箱验证：配置 `AGENTRUN_ACCOUNT_ID`、`AGENTRUN_API_KEY`、`AGENTRUN_TEMPLATE_NAME` 后运行 `php scripts/agentrun-verify.php`

发布前至少跑：`composer validate --strict`、`composer test`、`composer audit --format=plain`。

## 3. 目录地图 / 架构

- `app/Sdk/`：对 Composer 用户暴露的 SDK API。`HaoCode` 是主入口；`HaoCodeConfig`、`Conversation`、`QueryResult`、`Message`、`SdkTool`、`SdkSkill`、`SandboxConfig` 都属于重点兼容面。
- `app/Support/Runtime/SdkRuntime.php`：SDK-only 容器启动、配置加载、核心服务和内置工具注册入口。新增内置工具或核心单例先看这里。
- `app/Services/Agent/`：agent loop、context builder、query engine、tool orchestrator、message history、background agent 等主链路。
- `app/Services/Api/`：模型 provider 适配层。`StreamingClient` 根据 provider type 分发到 Anthropic、OpenAI Responses、OpenAI Chat Completions，并把不同 wire format 翻译回统一 stream event。
- `app/Tools/`：内置工具，包含文件、grep/glob、bash、patch、web、MCP、skill、task、team、memory、plan mode、worktree 等。
- `app/Sdk/Sandbox/`：local / AgentRun 沙箱配置和 backend。sandbox 会替换 `Read`/`Write`/`Glob`/`Grep`；只有 `mode: full` 才允许 sandboxed `Bash`。
- `config/haocode.php`：默认模型、API key/base URL、provider、permission mode、streaming、thinking、cost threshold 等运行配置。
- `docs/SDK.md`、`README.md`、`examples/sdk-suite/`：对外文档和示例；公开行为变化要同步这里。
- `tests/Sdk/Fixtures/public-api.snapshot.json`：公开 API 快照，由 `scripts/sdk-bc-check.php` 生成，不手写。

当前工作区可能存在 `.claude/`、`.codex/`、`flask-api/`、`flask_api/`、`flask_jwt_api/`、`results/` 等本地实验目录。它们不是发布结构的一部分，除非用户明确要求，不要纳入提交、tag 或 Packagist 发布。

## 4. 代码风格 / 接口契约

- 公开 API 以 `docs/sdk-bc-policy.md` 为准：`@api` 成员遵守 SemVer；删除、改签名、改 readonly 属性类型属于 breaking change。
- 修改 `app/Sdk/*` 构造函数、公开方法或 `readonly` 属性后，必须跑 `php scripts/sdk-bc-check.php --verify`。故意新增非破坏性 API 时，运行 `php scripts/sdk-bc-check.php --write` 并提交快照。
- Provider 层保持 `StreamingClient::streamMessages()` 的 Anthropic-shaped 输入/输出契约；不要把 OpenAI 或兼容网关的 wire format 泄漏到 `AgentLoop`、`QueryEngine` 或 SDK public API。
- `OpenAiChatProvider` 当前用 PHP native stream wrapper 读 SSE，是为绕开部分 SSE/chunked 网关下 Symfony HttpClient/Curl 提前 close stream 的问题。改回 HttpClient 前必须有回归测试覆盖。
- `HaoCodeConfig::providerType` 支持 `anthropic`、`openai`、`openai_chat`；显式传入 `apiKey`、`baseUrl`、`model`、`maxTokens` 等配置时才覆盖默认 settings。
- Skill 采用渐进披露：系统提示只列 description；实际使用 skill 时通过 `SkillTool` 完整读取 `SKILL.md`，相对路径按 `${HAOCODE_SKILL_DIR}` 解析。
- 自定义工具继承 `SdkTool`；默认 **非** read-only（Plan 模式不会自动放行，也不会默认并行 fork）。纯查询工具必须显式 `isReadOnly(): true`。

## 5. 工作流硬约束

- 精准改动：SDK public API、Provider、Tool、Sandbox、Runtime 是不同边界；改哪个测哪个，不借题重构其他主链路。
- 依赖升级只做必要最小范围。升级 `composer.lock` 后跑 Composer 校验、测试和 audit。
- 密钥和账号信息只走环境变量：`ANTHROPIC_API_KEY`、`OPENAI_API_KEY`、`HAOCODE_*`、`AGENTRUN_*` 等不要写进代码、文档真实值或提交记录。
- `composer.json` 不要添加 `version` 字段。Packagist 依据 Git tag 推断版本，硬写 version 会导致 tag 被忽略。
- 源仓库地址变更时，同步更新 `composer.json` 的 `homepage`、`support.issues`、`support.source`，并刷新 `composer.lock` content hash。
- 发布流程：提交 release prep → 创建 annotated SemVer tag → 推送 branch 和 tag 到 Packagist 实际抓取的 source remote → 检查 Packagist 是否索引到新版本。

## 6. 已知陷阱 / 不要做

- Packagist 提示 `Some tags were ignored because of a version mismatch in composer.json` 时，不要重打旧 tag。先修 `composer.json`，提交后发新的 patch tag。
- 发 tag 前检查精确提交：`git show <tag>:composer.json` 或先 `git rev-parse HEAD`，确认没有 `version` 字段且 repository/support URL 正确。
- 推 tag 后用 `git ls-remote <remote> refs/tags/<tag> refs/tags/<tag>^{}` 验证 annotated tag 和 peeled commit；Packagist API 没出现新版本时，去 Packagist 页面点 `Update` 或等待 webhook。
- Composer 包名和 GitHub owner 是两件事：包名来自 `composer.json` 的 `name`，当前是 `sk-wang/hao-code`；仓库迁移不会自动改变 `composer require` 名称。
- 不要把 README 的 `v1.0.0 is the first SDK-only release` 当当前版本号；实际发布版本看 Git tag 和 Packagist。
- AgentRun demo 文件写入位置遵循 README：当前 code-interpreter template 下用 `/tmp/workspace` 更稳，不要想当然套 local sandbox 默认 `/workspace`。
- sandbox active 时不要默认启用 host-only 工具；`Edit`、`apply_patch`、`NotebookEdit`、`Lsp`、worktree、sub-agent messaging 需要明确设计后再放开。
- Provider 变更不要只测 happy path。至少覆盖 text-only、tool-call、multi-turn tool、reasoning/thinking 相关 fixture。
