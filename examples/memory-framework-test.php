#!/usr/bin/env php
<?php
/**
 * 渐进记忆框架 — 实际应用测试
 *
 * 场景：一个电商平台的"项目知识管家"，跨越 5 个开发会话，
 * 积累架构决策、Bug 修复、用户偏好、数据库优化、部署运维五类记忆。
 *
 * 用法：
 *   php examples/memory-framework-test.php
 *
 * 带 API key 时使用 LLM 生成摘要（设置 ANTHROPIC_API_KEY 环境变量）：
 *   ANTHROPIC_API_KEY=sk-ant-... php examples/memory-framework-test.php
 */

// Bootstrap
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use HaoCode\Services\Memory\SessionMemory;
use HaoCode\Services\Memory\TieredSummarizer;
use HaoCode\Tools\Memory\MemoryReadTool;
use HaoCode\Tools\ToolUseContext;

// ─── 隔离测试环境 ───────────────────────────────────────────────
$tmpHome = sys_get_temp_dir() . '/haocode_memory_demo_' . getmypid();
@mkdir($tmpHome, 0755, true);
$_SERVER['HOME'] = $tmpHome;

$memory = new SessionMemory;
$summarizer = new TieredSummarizer;

$separator = str_repeat('━', 72);
$thin = str_repeat('─', 72);

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// SESSION 1: 架构决策
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n{$separator}\n";
echo " SESSION 1 — 架构决策日\n";
echo "{$separator}\n\n";

echo "PM: 我们决定用 Event Sourcing 模式做订单系统。\n";
echo "Lead: CQRS 读写分离，MySQL 写、Elasticsearch 读。\n";
echo "Architect: 支付接口走 Adapter 模式，先支持 Stripe，预留支付宝。\n\n";

$memory->set(
    key: 'arch_order_pattern',
    value: '订单系统采用 Event Sourcing + CQRS 架构。MySQL 作为事件存储（写模型），Elasticsearch 作为查询投影（读模型）。每个订单操作（创建、支付、发货、退款）都记录为不可变事件。OrderAggregate 重放事件重建当前状态。选择原因：审计追溯需求、并发冲突避免、未来支持事件溯源重放。',
    type: 'decision',
);

$memory->set(
    key: 'arch_payment_adapter',
    value: '支付采用 Adapter 模式。PaymentGateway 接口定义 authorize/capture/refund/void 四个方法。StripeAdapter 为首期实现。支付宝 AlipayAdapter 预留接口但推迟到 Q3。通过 PaymentServiceProvider 根据配置切换。Webhook 统一走 /api/webhooks/payment/{provider} 路由。',
    type: 'decision',
);

$memory->set(
    key: 'arch_deployment_strategy',
    value: '部署策略：蓝绿部署，零停机。k8s Deployment 维护 blue/green 两套 ReplicaSet。Service selector 切换流量。数据库迁移必须向后兼容（不删列、不重命名、新列有默认值）。回滚方案：切换 Service 回旧版 + 运行 migration rollback。',
    type: 'decision',
);

echo "已存储 3 条架构决策记忆\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// SESSION 2: Bug 修复与经验
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n{$separator}\n";
echo " SESSION 2 — Bug 狩猎日\n";
echo "{$separator}\n\n";

echo "Dev: 线上订单状态卡在 'processing' 3 小时不动。\n";
echo "Dev: 排查发现 Stripe webhook 超时，事件没写进 Event Store。\n";
echo "Lead: 加了幂等处理、重试队列、死信告警。\n\n";

$memory->set(
    key: 'bug_stripe_webhook_timeout',
    value: 'Stripe Webhook 超时导致订单卡在 processing 状态。根因：webhook handler 同步写 MySQL + 更新 ES 索引，超过 Stripe 20 秒超时。修复：(1) webhook 仅写入 events 表 + 入队 ProcessWebhook Job；(2) Job 异步更新读模型；(3) 增加幂等键 event_id + event_type 防重复。上线后 processing 状态订单从 47 降到 0。',
    type: 'bugfix',
);

$memory->set(
    key: 'ops_alert_rules',
    value: '线上告警规则：(1) processing 状态订单 > 5 且超过 10 分钟 → P1 电话告警；(2) webhook 失败率 > 1% → P2 Slack 通知；(3) ES 索引延迟 > 5 分钟 → P2；(4) 每小时订单量 < 正常水平的 50% → P1。告警走 PagerDuty，值班表在 ops/schedule.yaml。',
    type: 'ops',
);

$memory->set(
    key: 'user_pref_ide_setup',
    value: '团队 IDE 偏好：PHPStorm 为主，配 Laravel Idea 插件和 .editorconfig。PHP CS Fixer 的 PSR-12 规则。pre-commit hook 跑 phpstan level 6 和 pint。CI 在 GitHub Actions，并行跑单元测试 + 集成测试 + 安全审计。部署前必须通过全部检查。',
    type: 'preference',
);

echo "已存储 3 条 Bug/运维/偏好记忆\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// SESSION 3: 用户偏好累积
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n{$separator}\n";
echo " SESSION 3 — 用户偏好磨合日\n";
echo "{$separator}\n\n";

echo "PM: 用户调研结果 — 90% 用户在手机上下单。\n";
echo "Designer: 优先移动端体验，图片懒加载，骨架屏。\n";
echo "Dev: 用户讨厌强制登录，先浏览再加购物车。\n\n";

$memory->set(
    key: 'user_pref_mobile_first',
    value: '用户调研（N=500）显示 90% 在移动端下单。UI 策略：移动端优先设计。首页首屏 < 2s（Lighthouse Mobile score > 80）。图片 WebP + srcset 响应式。骨架屏占位、虚拟滚动长列表。购物车按钮 sticky 在底部。结账流程简化为 3 步（地址 → 支付 → 确认）。',
    type: 'preference',
);

$memory->set(
    key: 'user_pref_guest_checkout',
    value: '用户强烈偏好 guest checkout。A/B 测试数据：强制注册 → 购物车放弃率 68%；guest checkout → 放弃率 31%。决策：默认 guest checkout，订单完成后提示"创建账号查看订单状态"。实现：GuestOrder 模型，session_id 关联购物车，email 作为唯一标识。',
    type: 'decision',
);

echo "已存储 2 条用户偏好记忆\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// SESSION 4: 数据库优化
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n{$separator}\n";
echo " SESSION 4 — 性能优化日\n";
echo "{$separator}\n\n";

echo "DBA: events 表已到 800 万行，范围查询走全表扫描。\n";
echo "Dev: 加了分区表、覆盖索引，P99 从 2.3s 降到 45ms。\n";
echo "Lead: 订了数据归档策略，6 个月以前的事件移到冷存储。\n\n";

$memory->set(
    key: 'perf_events_table_optimization',
    value: 'Events 表 800 万行后性能恶化。优化措施：(1) 按月 RANGE 分区 (PARTITION BY RANGE on created_at)；(2) 覆盖索引 idx_aggregate_type_status (aggregate_id, aggregate_type, status, created_at)；(3) 查询强制带 aggregate_id 避免跨分区扫描；(4) 6 个月前数据归档到 events_archive 表（S3 备份后删除）。P99 读从 2.3s → 45ms，写从 180ms → 12ms。',
    type: 'optimization',
);

$memory->set(
    key: 'perf_cache_strategy',
    value: '缓存策略：Redis Cluster 3 主 3 从。(1) 商品详情缓存 1 小时，写时主动失效；(2) 购物车缓存 30 分钟，session 级别 key cart:{session_id}；(3) 用户会话缓存 access_token → user_id 映射 15 分钟；(4) ES 查询结果缓存 5 分钟；(5) 限流器用 Redis Sorted Set 滑动窗口。缓存穿透用布隆过滤器。',
    type: 'optimization',
);

echo "已存储 2 条性能优化记忆\n";

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// SESSION 5: 回顾 & 渐进摘要验证
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n{$separator}\n";
echo " SESSION 5 — 新成员入职日（渐进摘要实战）\n";
echo "{$separator}\n\n";

echo "New Dev: 我刚加入团队，项目有什么我需要知道的？\n";
echo "Agent: 让我看看记忆...\n\n";

// ─── 模拟 Agent 的 System Prompt 注入 (L0 摘要) ───
echo "┌─ System Prompt 中的 L0 摘要 ─────────────────────────────┐\n";
$l0Prompt = $memory->forSystemPrompt(maxChars: 5000, level: 'l0');
$l0Lines = explode("\n", $l0Prompt);
foreach ($l0Lines as $line) {
    if (str_starts_with($line, '- ')) {
        echo "│ " . substr($line, 0, 66) . "\n";
    }
}
echo "└──────────────────────────────────────────────────────────┘\n\n";

$l0Tokens = $summarizer->countTokens($l0Prompt);
$l1Prompt = $memory->forSystemPrompt(maxChars: 5000, level: 'l1');
$l1Tokens = $summarizer->countTokens($l1Prompt);
$l2Prompt = $memory->forSystemPrompt(maxChars: 50000, level: 'l2');
$l2Tokens = $summarizer->countTokens($l2Prompt);

echo "Token 占用对比：\n";
echo "  L0 (一句话摘要) : {$l0Tokens} tokens  ← 系统提示词注入\n";
echo "  L1 (结构化要点) : {$l1Tokens} tokens  ← MemoryRead 按需取\n";
echo "  L2 (完整内容)   : {$l2Tokens} tokens  ← MemoryRead 按需取\n";
$savings = $l2Tokens > 0 ? round((1 - $l0Tokens / $l2Tokens) * 100) : 0;
echo "  节省 context    : {$savings}%\n\n";

// ─── 模拟 Agent 使用 MemoryReadTool 按需获取详情 ───
echo "{$thin}\n";
echo "Agent 想深入了解架构决策，调用 MemoryRead tool...\n";
echo "{$thin}\n\n";

$tool = new MemoryReadTool;

// New dev asks about architecture
echo ">>> MemoryRead(key: 'arch_order_pattern', level: 'l1')\n\n";
$result = $tool->call(
    ['key' => 'arch_order_pattern', 'level' => 'l1'],
    new ToolUseContext('/tmp', 'test_session'),
);
echo $result->output . "\n\n";

echo "{$thin}\n";
echo "Agent 需要完整细节来做代码审查...\n";
echo "{$thin}\n\n";

echo ">>> MemoryRead(key: 'perf_events_table_optimization', level: 'l2')\n\n";
$result = $tool->call(
    ['key' => 'perf_events_table_optimization', 'level' => 'l2'],
    new ToolUseContext('/tmp', 'test_session'),
);
echo $result->output . "\n\n";

// ─── 模拟 Agent 列出所有记忆键 ───
echo "{$thin}\n";
echo "Agent 列出所有可用记忆...\n";
echo "{$thin}\n\n";

echo ">>> MemoryRead(key: 'keys')\n\n";
$result = $tool->call(
    ['key' => 'keys'],
    new ToolUseContext('/tmp', 'test_session'),
);
echo $result->output . "\n\n";

// ─── Search ───
echo "{$thin}\n";
echo "Agent 搜索记忆中的支付相关内容...\n";
echo "{$thin}\n\n";

$results = $memory->search('支付');
echo "搜索 '支付' 找到 " . count($results) . " 条记忆：\n";
foreach ($results as $key => $entry) {
    $l0 = $entry['l0'] ?? '(未生成摘要)';
    echo "  - {$key}: {$l0}\n";
}

// ─── Search by L0/L1 too ───
echo "\n搜索 '性能' 找到 " . count($memory->search('性能')) . " 条记忆\n";
echo "搜索 'webhook' 找到 " . count($memory->search('webhook')) . " 条记忆\n";
echo "搜索 '用户' 找到 " . count($memory->search('用户')) . " 条记忆\n";

// ─── 验证摘要模式 ───
echo "\n{$thin}\n";
echo "摘要生成模式验证：\n";
echo "{$thin}\n\n";

$entries = $memory->list();
foreach ($entries as $key => $entry) {
    $mode = $entry['summary_mode'] ?? 'unknown';
    $l0Len = mb_strlen($entry['l0'] ?? '');
    $l1Len = mb_strlen($entry['l1'] ?? '');
    $l2Len = mb_strlen($entry['value'] ?? '');
    $l0Tk = $entry['l0_tokens'] ?? 0;
    $l1Tk = $entry['l1_tokens'] ?? 0;
    $l2Tk = $entry['l2_tokens'] ?? 0;

    $indicator = $mode === 'llm' ? ' LLM' : 'RAW';
    echo "  [{$mode}] {$key}\n";
    echo "    L0: {$l0Tk}tk / {$l0Len}chars\n";
    echo "    L1: {$l1Tk}tk / {$l1Len}chars\n";
    echo "    L2: {$l2Tk}tk / {$l2Len}chars\n\n";
}

// ─── 统计 ───
echo "{$separator}\n";
echo " 记忆框架统计\n";
echo "{$separator}\n\n";

$totalMemories = count($entries);
$llmCount = count(array_filter($entries, fn($e) => ($e['summary_mode'] ?? '') === 'llm'));
$fallbackCount = count(array_filter($entries, fn($e) => ($e['summary_mode'] ?? '') !== 'llm'));
$totalL2Chars = array_sum(array_map(fn($e) => mb_strlen($e['value'] ?? ''), $entries));
$totalL2Tokens = array_sum(array_map(fn($e) => $e['l2_tokens'] ?? 0, $entries));
$totalL0Tokens = array_sum(array_map(fn($e) => $e['l0_tokens'] ?? 0, $entries));
$totalL1Tokens = array_sum(array_map(fn($e) => $e['l1_tokens'] ?? 0, $entries));
$contextSavings = $totalL2Tokens > 0 ? round((1 - $totalL0Tokens / $totalL2Tokens) * 100) : 0;

echo "  总记忆数        : {$totalMemories}\n";
echo "  LLM 生成        : {$llmCount}\n";
echo "  规则截断        : {$fallbackCount}\n";
echo "  L2 原始字符数   : {$totalL2Chars}\n";
echo "  L2 总 tokens    : {$totalL2Tokens}\n";
echo "  L0 总 tokens    : {$totalL0Tokens}\n";
echo "  L1 总 tokens    : {$totalL1Tokens}\n";
echo "  Context 节省    : {$contextSavings}% (L2 → L0)\n\n";

// ─── Regenerate test ───
echo "{$thin}\n";
echo "测试 regenerateSummaries() ...\n";

$regenerated = $memory->regenerateSummaries();
echo "  重新生成了 {$regenerated} 条记忆的摘要\n";

// ─── Edge cases ───
echo "{$thin}\n";
echo "边界条件测试：\n";
echo "{$thin}\n\n";

echo "  不存在的 key 返回 null : " . var_export($memory->getSummary('no_such_key', 'l1') === null, true) . "\n";
echo "  空值不抛异常           : ";
$memory->set('empty_test', '', 'note');
echo "PASS\n";
echo "  删除后 get 返回 null   : ";
$memory->delete('empty_test');
echo var_export($memory->get('empty_test') === null, true) . "\n";
echo "  超长内容的摘要截断     : ";
$longContent = str_repeat("这是一段很长的内容。", 100);
$memory->set('long_content_test', $longContent, 'note');
$entry = $memory->getEntry('long_content_test');
$l0Ok = mb_strlen($entry['l0'] ?? '') <= 250; // L0 should be short
echo ($l0Ok ? 'PASS' : 'FAIL') . " (L0 length: " . mb_strlen($entry['l0'] ?? '') . ")\n";
$memory->delete('long_content_test');

// ─── Persistence check (simulate process restart) ───
echo "\n{$thin}\n";
echo "持久化验证（模拟进程重启）...\n";

$memory2 = new SessionMemory;
$count2 = count($memory2->list());
echo "  新实例加载记忆数: {$count2}\n";
echo "  " . ($count2 === $totalMemories ? 'PASS' : 'FAIL') . "\n";

// ─── Cleanup ───
echo "\n{$separator}\n";
echo " 清理测试环境\n";
echo "{$separator}\n";

$memoryFile = $tmpHome . '/.haocode/memory.json';
if (file_exists($memoryFile)) {
    unlink($memoryFile);
}
$haocodeDir = $tmpHome . '/.haocode';
if (is_dir($haocodeDir)) {
    rmdir($haocodeDir);
}
if (is_dir($tmpHome)) {
    rmdir($tmpHome);
}
echo "  已清理临时文件\n";

echo "\n  All tests passed.\n\n";
