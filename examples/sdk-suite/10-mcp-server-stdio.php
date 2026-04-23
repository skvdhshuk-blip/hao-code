#!/usr/bin/env php
<?php
// Env: ANTHROPIC_API_KEY
if (!getenv('ANTHROPIC_API_KEY')) { fwrite(STDERR, "[skip] 需要 ANTHROPIC_API_KEY\n"); exit(0); }

require __DIR__.'/_bootstrap.php';
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Mcp\McpConnectionManager;

/** @var McpServerConfigManager $cfg */
$cfg = app(McpServerConfigManager::class);
$cfg->addServer('self-stdio', [
    'transport' => 'stdio', 'enabled' => true,
    'command' => 'php', 'args' => [$packageRoot.'/bin/hao-code', 'mcp-serve', '--root='.sys_get_temp_dir(), '--preset=strict'],
    'env' => ['ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY')],
], 'project');

/** @var McpConnectionManager $mgr */
$mgr = app(McpConnectionManager::class);
try {
    $mgr->connectAll(fn ($n, $s) => print("  MCP {$n}: {$s}\n"));
    $tools = $mgr->discoverAllTools();
    echo "MCP tools (".count($tools)."):\n";
    foreach (array_slice($tools, 0, 5) as $t) echo "  - {$t['name']}\n";
} finally {
    $mgr->disconnectAll();
    $cfg->removeServer('self-stdio', 'project');
}
