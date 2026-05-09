#!/usr/bin/env php
<?php
// Env: none required — uses npx @modelcontextprotocol/server-filesystem if available
$packageRoot = dirname(__DIR__, 2);
require_once $packageRoot.'/vendor/autoload.php';
use HaoCode\Services\Mcp\McpServerConfigManager;
use HaoCode\Services\Mcp\McpConnectionManager;

\HaoCode\Support\Runtime\SdkRuntime::boot(basePath: $packageRoot);

/** @var McpServerConfigManager $cfg */
$cfg = app(McpServerConfigManager::class);
$cfg->addServer('demo-fs', [
    'transport' => 'stdio', 'enabled' => true,
    'command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-filesystem', sys_get_temp_dir()],
], 'project');

/** @var McpConnectionManager $mgr */
$mgr = app(McpConnectionManager::class);
$mgr->connectAll(fn ($n, $s) => print("  MCP {$n}: {$s}\n"));
$tools = $mgr->discoverAllTools();
echo "MCP tools (".count($tools)."):\n";
foreach (array_slice($tools, 0, 5) as $t) echo "  - {$t['name']}\n";

$mgr->disconnectAll();
$cfg->removeServer('demo-fs', 'project');
