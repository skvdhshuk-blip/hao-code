# ExecPolicy DSL 使用指南

ExecPolicy 是 HaoCode SDK 的命令执行授权层。每次 `Bash` 工具调用都经过策略匹配；无规则命中时默认拒绝（fail-closed）。

## 1. DSL 字段说明

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `name` | string | 必填 | 规则唯一标识，出现在日志和审批提示中 |
| `tool` | string | 必填 | 工具名，当前固定为 `Bash` |
| `cmd` | string | 必填 | 匹配命令主体；`*` 通配所有命令 |
| `args_match` | string[] | `[]` | 参数匹配列表（AND 语义）；精确 / `*` 通配 / `/regex/` 三种模式 |
| `env_allow` | string[] | `[]` | 允许透传的环境变量（声明用，未来白名单模式） |
| `env_deny` | string[] | `[]` | 拒绝的环境变量；非空时**必须包含全部 6 项硬黑名单**（见第 5 节） |
| `risk` | string | `normal` | 风险等级：`normal` 或 `high` |
| `allow_chain` | bool | `false` | 是否允许 `&&` `\|\|` `;` `$()` `` ` `` 等命令链操作符 |
| `approval_ttl` | int | `0` | `risk=high` 审批缓存秒数；`0` = 每次重新确认 |
| `cwd_restriction` | string | `null` | 允许执行的工作目录前缀（绝对路径） |
| `allow_auto` | bool | `false` | `normal` 风险下跳过人工确认弹窗 |
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

同优先级内，相同 `tool+cmd` 在 `allow_chain`/`risk`/`allow_auto` 上不一致时启动校验报错。

## 4. risk=high 三件套行为

命中 `risk=high` 时 PolicyMatcher 执行以下三步：

1. **OTEL span 上报** — 写入 `permission.high_risk_decision` span，携带 `rule.name/tool/cmd/risk`（失败不阻断流程）。
2. **审批弹窗** — 向用户展示规则名和原因；`approval_ttl=0` 时每次执行均弹窗。
3. **禁止自动通过** — `allow_auto=true` 与 `risk=high` 并存时启动校验直接报错。

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
| 2 | 规则冲突 | 同 `tool+cmd` 的规则在 `allow_chain`/`risk`/`allow_auto` 上不一致 |
| 3 | 高风险自动批准 | `risk=high` 与 `allow_auto=true` 同时出现 |
| 4 | env_deny 完整性 | 非空 `env_deny` 缺少下列任一硬黑名单条目 |
| 5 | cwd 路径合法性 | `cwd_restriction` 非绝对路径或归一化后越出文件系统根 |

**硬黑名单**（非空 `env_deny` 必须全部包含）：
`LD_PRELOAD` `DYLD_INSERT_LIBRARIES` `DYLD_LIBRARY_PATH` `PYTHONPATH` `NODE_OPTIONS` `PERL5OPT`

## 6. 与 permissions.allow/deny glob 的关系

两者是**平行**控制层，互不覆盖：

- `permissions.allow/deny` — 基于工具名的粗粒度白/黑名单，由 Claude Code 权限系统管理。
- ExecPolicy — 基于 `tool+cmd+args` 的细粒度执行策略，由本 SDK 在运行时匹配。

命令必须同时通过两层才能执行；ExecPolicy 规则无法绕过 `permissions.deny` 中已拒绝的工具。

## 7. policy:dump / policy:import 示例

### policy:dump（Artisan 命令）

```bash
# 导出到 stdout
php artisan policy:dump policies/default.yml

# 合并多个文件并美化输出
php artisan policy:dump policies/default.yml policies/laravel-dev.yml \
  --output=dist/merged-policy.json --pretty
```

输出格式为 `{ "rules": [ { "name": ..., "tool": ..., "cmd": ..., ... } ] }`。

### policy:import（PHP API）

暂无独立 Artisan 命令，通过 `PolicyImporter` 类调用（自动执行 5 项校验）：

```php
use HaoCode\Services\Permissions\Policy\PolicyImporter;

$rules = (new PolicyImporter())->importFile('dist/merged-policy.json');
```
