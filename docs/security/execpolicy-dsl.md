# ExecPolicy DSL 使用指南

ExecPolicy 是 HaoCode SDK 的命令执行授权层。每次 `Bash` 工具调用都经过策略匹配。命令链等硬约束命中时，SDK 会拒绝执行（fail-closed）。`env_deny` 会从实际子进程环境中删除匹配变量，普通前台 Bash、后台 Bash 和沙箱 Bash 使用同一规则。未匹配任何规则的工具调用返回 `NotApplicable`（不是 `Deny`），外层 PermissionChecker 再检查显式 deny、危险模式、只读自动放行和默认 ask。这样，Bash policy 不会误伤 Read、Grep、Glob、MCP 等工具。cron 守护进程路径（JobStore）会把 `NotApplicable` 当作 `Deny`，确保无人值守任务默认拒绝。

## 1. DSL 字段说明

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `name` | string | 必填 | 规则唯一标识，出现在日志和审批提示中 |
| `tool` | string | 必填 | 工具名，当前固定为 `Bash` |
| `cmd` | string | 必填 | 匹配命令主体；`*` 通配所有命令 |
| `args_match` | string[] | `[]` | 参数匹配列表（AND 语义）；精确 / `*` 通配 / `/regex/` 三种模式 |
| `env_allow` | string[] | `[]` | 允许透传的环境变量（声明用，未来白名单模式） |
| `env_deny` | string[] | `[]` | 不得传给子进程的环境变量；非空时**必须包含全部 6 项硬黑名单**（见第 5 节） |
| `risk` | string | `normal` | 风险等级：`normal` 或 `high` |
| `allow_chain` | bool | `false` | 是否允许 `&&` `\|\|` `;` `$()` `` ` `` 等命令链操作符 |
| `approval_ttl` | int | `0` | **当前未实现**（保留字段）。原设计意图：`risk=high` 审批缓存秒数，`0` = 每次重新确认。Matcher 当前总是把 high-risk 视为"每次重新确认"，忽略此字段。 |
| `cwd_restriction` | string | `null` | 允许执行的工作目录前缀（绝对路径） |
| `allow_auto` | bool | `false` | `normal` 风险下命中时，PermissionChecker 直接返回 `PermissionDecision::allow()`，跳过 HITL/SmartHitl 审批流。`risk=high` 与 `allow_auto=true` 是禁止组合，Loader 会拒绝加载。 |
| `note` | string | `null` | 人类可读备注，不影响匹配逻辑 |

## 2. 示例 YAML

### 2.1 default.yml — 命令链禁用 / LD_PRELOAD 等硬黑名单

```yaml
rules:
  - name: bash-catch-all
    tool: Bash
    cmd: "*"
    risk: normal
    allow_chain: false
    allow_auto: false
    env_deny:
      - LD_PRELOAD
      - DYLD_INSERT_LIBRARIES
      - DYLD_LIBRARY_PATH
      - PYTHONPATH
      - NODE_OPTIONS
      - PERL5OPT
    note: 兜底规则；禁止命令链和环境变量注入

  - name: bash-git-read
    tool: Bash
    cmd: git
    args_match: ["status*"]
    risk: normal
    allow_auto: true
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]
```

### 2.2 laravel-dev.yml — composer / artisan / phpunit 放行

```yaml
rules:
  - name: laravel-artisan-safe
    tool: Bash
    cmd: php
    args_match: ["/artisan (list|route:list|migrate:status|config:show|env)/"]
    risk: normal
    allow_auto: true
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]
    note: 只读 artisan 命令，自动放行

  - name: laravel-composer-require
    tool: Bash
    cmd: composer
    args_match: ["require*"]
    risk: normal
    allow_auto: false   # 新增依赖需人工确认
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]

  - name: laravel-phpunit
    tool: Bash
    cmd: vendor/bin/phpunit
    risk: normal
    allow_auto: true
    cwd_restriction: /var/www
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]
```

### 2.3 高风险规则示例（risk=high + approval_ttl）

```yaml
rules:
  - name: git-force-push
    tool: Bash
    cmd: git
    args_match: ["/push.*--force/"]
    risk: high
    allow_auto: false   # risk=high + allow_auto=true 是禁止组合
    approval_ttl: 0     # 每次重新确认，写入 OTEL span
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]

  - name: artisan-migrate-prod
    tool: Bash
    cmd: php
    args_match: ["/artisan migrate$/"]
    risk: high
    allow_auto: false
    approval_ttl: 300   # 审批后 5 分钟内不重复弹窗
    cwd_restriction: /var/www/production
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]
```

### 2.4 企业场景：DB 访问脚本按 env_allow 放行

```yaml
rules:
  - name: db-query-readonly
    tool: Bash
    cmd: php
    args_match: ["/scripts\\/db-query\\.php/"]
    risk: normal
    allow_auto: false
    cwd_restriction: /opt/app/scripts
    env_allow: [DB_HOST, DB_PORT, DB_DATABASE]   # 声明允许透传的连接变量
    env_deny: [LD_PRELOAD, DYLD_INSERT_LIBRARIES, DYLD_LIBRARY_PATH, PYTHONPATH, NODE_OPTIONS, PERL5OPT]
```

## 3. 规则加载优先级

```
runtime override（代码注入）
    ↓
~/.haocode/policy.yml（用户级）
    ↓
project policies/*.yml（项目级，按文件名字典序合并）
    ↓
bundled policies/default.yml（内置兜底）
```

同优先级内，相同 `tool+cmd+args_match` 签名（args_match 排序后比较，空数组视为同一签名）在 `allow_chain`/`risk`/`allow_auto` 上不一致时启动校验报错。同一 `cmd` 但不同 `args_match` 的规则可以共存。这正是 `git status`（normal/auto）与 `git push --force`（high/no-auto）共用 `cmd: git` 的依据。

### 3.1 规则匹配顺序（specificity 优先）

PolicyMatcher 在构造时按 specificity 稳定排序，避免 `cmd: "*"` 兜底规则遮蔽后续具体规则。Specificity 二元组（大的优先）：

1. `cmd` 不是 `"*"` 通配符（精确命令名 > 通配符）
2. 规则带 `args_match`（有参数约束 > 无参数约束）

同 specificity 内保留 YAML 声明顺序。这意味着：

- `git status` 会命中 `bash-git-status`（cmd=git, args=status*），不会先被 `cmd: "*"` 吃掉
- `git push --force` 会命中 `bash-git-push-force`（risk=high），不会被前面的 normal 规则短路
- 只有未被任何具体规则覆盖的命令（如 `ls`）才落到 `cmd: "*"` 兜底

## 4. risk=high 三件套行为

命中 `risk=high` 时 PolicyMatcher 执行以下两步（`approval_ttl` 字段当前未实现，统一视为"每次重新确认"）：

1. **OTEL span 上报** — 写入 `permission.high_risk_decision` span，携带 `rule.name/tool/cmd/risk`（失败不阻断流程）。
2. **审批弹窗** — PolicyMatcher 返回 `ApprovalRequired`，PermissionChecker 转译成 `PermissionDecision::ask()`，进入 HITL 审批流。
3. **禁止自动通过** — `allow_auto=true` 与 `risk=high` 并存时 Loader 启动校验直接报错。

**OTEL span 示例：**
```json
{
  "name": "permission.high_risk_decision",
  "attributes": { "rule.name": "git-force-push", "rule.tool": "Bash", "rule.cmd": "git", "rule.risk": "high" }
}
```

## 5. PolicyLoader 5 项启动校验（fail-closed）

任一校验失败则整体拒绝加载，不做降级：

| # | 校验项 | 触发条件 |
|---|--------|----------|
| 1 | regex 长度 | `args_match` 中任一正则超过 1000 字符（ReDoS 防护） |
| 2 | 规则冲突 | 同 `tool+cmd+args_match 签名` 的规则在 `allow_chain`/`risk`/`allow_auto` 上不一致（同 cmd 不同 args 可共存） |
| 3 | 高风险自动批准 | `risk=high` 与 `allow_auto=true` 同时出现 |
| 4 | env_deny 完整性 | 非空 `env_deny` 缺少下列任一硬黑名单条目 |
| 5 | cwd 路径合法性 | `cwd_restriction` 非绝对路径或归一化后越出文件系统根 |

**硬黑名单**（非空 `env_deny` 必须全部包含）：
`LD_PRELOAD` `DYLD_INSERT_LIBRARIES` `DYLD_LIBRARY_PATH` `PYTHONPATH` `NODE_OPTIONS` `PERL5OPT`

SDK 始终从实际执行环境中删除这 6 项。规则还可追加其他变量。普通前台 Bash、后台 Bash、LocalSandbox 和 NativeSandbox 都会使用匹配规则的完整 `env_deny`。

## 6. 与 permissions.allow/deny glob 的关系

两者是**平行**控制层，互不覆盖：

- `permissions.allow/deny` — 基于工具名的粗粒度白/黑名单，由 Claude Code 权限系统管理。
- ExecPolicy — 基于 `tool+cmd+args` 的细粒度执行策略，由本 SDK 在运行时匹配。

命令必须同时通过两层才能执行；ExecPolicy 规则无法绕过 `permissions.deny` 中已拒绝的工具。

注意 Policy 决策的转译：`AllowAuto` 直接放行；普通 `Allow` 返回 null 让 PermissionChecker 继续 fallback 到 deny/allow/dangerous 判断（两层平行）；`Deny` 硬拒；`ApprovalRequired` 转 `ask()`。

## 7. policy:dump / policy:import 示例

### policy:dump（Artisan 命令）

> **注意**：`php artisan policy:dump` 命令当前**未实现**。下面的用法是设计意图，未来版本会补齐。临时可以通过 `PolicyImporter::importFile()` 读 JSON，再用 `json_encode` 手动 dump。

### policy:import（PHP API）

暂无独立 Artisan 命令，通过 `PolicyImporter` 类调用（自动执行 5 项校验）：

```php
use HaoCode\Services\Permissions\Policy\PolicyImporter;

$rules = (new PolicyImporter())->importFile('dist/merged-policy.json');
```
