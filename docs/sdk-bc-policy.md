# SDK Backward Compatibility Policy

## 标记语义

### `@api`

标记为 `@api` 的类、方法或属性是 **公开接口**（Public API）：

- 遵循语义化版本控制（SemVer）
- **破坏性改动**（签名修改、删除、行为变更）必须走 major 版本（例如 v1.0 → v2.0）
- 非破坏性添加（新方法、新可选参数）可以在 minor 版本引入

当前 `@api` 公开面板包括：

| 类 | 说明 |
|----|------|
| `HaoCode` | 主入口 Facade，6 个静态方法 |
| `HaoCodeConfig` | 查询配置对象 |
| `Conversation` | 多轮对话句柄 |
| `QueryResult` | 查询结果，携带 text/usage/cost/sessionId |
| `Message` | 流式事件消息信封 |
| `SdkTool` | 自定义工具基类（4 个抽象方法） |
| `SdkSkill` | 自定义 skill 定义 |
| `AbortController` | 取消控制器 |
| `StructuredResult` | 结构化输出封装 |

### `@internal`

标记为 `@internal` 的类、方法或属性是 **内部实现**：

- **不承诺向后兼容性**，可在任意版本修改或删除
- 不应在 SDK 外部直接使用
- 可能随重构消失或更名

当前 `@internal` 边界包括：

| 成员 | 原因 |
|------|------|
| `Conversation::__construct` | 由 `HaoCode::conversation()` / `resume()` 创建，外部不应直接实例化 |
| `Conversation::getLoop` | 暴露内部 AgentLoop，不属于稳定接口 |
| `SdkTool::inputSchema` | BaseTool 框架内部调用 |
| `SdkTool::call` | BaseTool 框架内部调用 |
| `SdkTool::isReadOnly` | BaseTool 框架内部调用 |
| `SdkSkill::toDefinition` | 转换为内部 SkillDefinition，不属于外部接口 |
| `HaoCodeSdkServiceProvider` | 旧框架集成兼容 shim，SDK 消费者不应直接操作 |

## BC 检查工具

### 生成快照

当首次设置或**故意修改公开接口**后，运行：

```bash
php scripts/sdk-bc-check.php --write
```

这会更新 `tests/Sdk/Fixtures/public-api.snapshot.json`，需要将该文件提交到版本控制。

### 验证当前 API 未变化

```bash
php scripts/sdk-bc-check.php --verify
```

退出码 0 = API 与快照一致；退出码 1 = 发生了未预期的变化，diff 会输出到 stderr。

### 在 PHPUnit 中自动验证

```bash
vendor/bin/phpunit tests/Sdk/PublicApiTest.php
```

`PublicApiTest` 会调用 `--verify` 并在签名变化时让测试失败。

## BC 风险处置决策

### `QueryResult` / `Message` readonly 属性

所有 `readonly` 属性视为 API 的一部分，写入快照并标注 `@api`。未来若需修改属性类型或删除属性，必须走 major 版本。

### `continueLatest` vs `resume`

两个方法保留独立签名，不做参数收敛，因为两者参数顺序不兼容，强行统一属于破坏性改动。

## 如何处理需要破坏性改动的情况

1. 创建独立工作项，明确标注 `breaking change`
2. 工作项进入 HEAVY 流程讨论，记录理由
3. 在新 major 版本分支实施
4. 更新快照：`php scripts/sdk-bc-check.php --write`
5. 提交快照变更作为 major 版本 PR 的一部分
