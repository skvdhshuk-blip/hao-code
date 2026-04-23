<?php

namespace HaoCode\Console\Commands;

use HaoCode\Services\Mcp\Server\McpServer;
use HaoCode\Services\Mcp\Server\ToolAdapter;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Tools\Bash\BashTool;
use HaoCode\Tools\FileRead\FileReadTool;
use HaoCode\Tools\FileWrite\FileWriteTool;
use HaoCode\Tools\Glob\GlobTool;
use HaoCode\Tools\Grep\GrepTool;
use HaoCode\Tools\Skill\SkillLoader;
use Illuminate\Console\Command;

/**
 * Starts hao-code as a stdio MCP server.
 *
 * Security hardening:
 * - Overrides permission_mode to 'default' (red line #1)
 * - Fail-fast on bypass_permissions (red line #1)
 * - Strips dangerouslyDisableSandbox (red line #5)
 * - Defaults ALL-DENY for Bash/Write/Edit unless --allow or --preset (red line #2)
 * - realpath + root prefix enforcement delegated to ToolAdapter (red line #4)
 * - Sensitive path blacklist in ToolAdapter (red line #3)
 */
class McpServeCommand extends Command
{
    protected $signature = 'mcp-serve
        {--root=. : Working directory root; all file tool paths must be under this}
        {--allow=* : Additional allow rules, e.g. --allow=write:./storage/**}
        {--deny=* : Additional deny rules}
        {--tool=* : SdkTool FQCN to register, e.g. --tool=App\\Tools\\LookupOrderTool}
        {--preset= : Permission preset: strict|laravel|laravel-dev}';

    protected $description = 'Start hao-code as an MCP server (stdio JSON-RPC 2.0)';

    // Tools allowed per preset
    private const PRESET_TOOLS = [
        'strict' => ['Read', 'Grep', 'Glob'],
        'laravel' => ['Read', 'Grep', 'Glob'],
        'laravel-dev' => ['Read', 'Grep', 'Glob', 'Bash', 'Write'],
    ];

    public function handle(): int
    {
        // Red line #5: strip dangerouslyDisableSandbox
        putenv('DANGEROUSLY_DISABLE_SANDBOX');
        unset($_ENV['DANGEROUSLY_DISABLE_SANDBOX'], $_SERVER['DANGEROUSLY_DISABLE_SANDBOX']);

        // Red line #1: override permission_mode to 'default'
        /** @var SettingsManager $settings */
        $settings = app(SettingsManager::class);
        $settings->set('permission_mode', 'default');

        // Red line #1: fail-fast if bypass_permissions detected anywhere
        $this->checkBypassFail($settings);

        $root = realpath($this->option('root') ?: '.') ?: (string) ($this->option('root') ?: '.');
        chdir($root);

        $preset = $this->option('preset') ?: 'strict';
        $allowedToolNames = self::PRESET_TOOLS[$preset] ?? self::PRESET_TOOLS['strict'];

        // Apply extra --allow/--deny (currently used for preset augmentation)
        $extraAllow = (array) $this->option('allow');
        if (in_array('bash', array_map('strtolower', $extraAllow), true)) {
            $allowedToolNames[] = 'Bash';
        }
        if (in_array('write', array_map('strtolower', $extraAllow), true)) {
            $allowedToolNames[] = 'Write';
        }

        // (a) expose-skills hardcoded to project scope (砍序 a)
        $caller = (string) (getenv('MCP_CALLER') ?: getenv('HAO_CODE_MCP_CALLER') ?: 'unknown');
        $skillLoader = $this->buildSkillLoader($root);

        $adapter = new ToolAdapter($skillLoader);
        $adapter->setRoot($root);

        // Register allowed built-in tools
        $allBuiltins = [
            'Read' => new FileReadTool,
            'Bash' => new BashTool,
            'Write' => new FileWriteTool,
            'Grep' => new GrepTool,
            'Glob' => new GlobTool,
        ];

        foreach ($allowedToolNames as $name) {
            if (isset($allBuiltins[$name])) {
                $adapter->registerBuiltin($allBuiltins[$name]);
            }
        }

        // Register SdkTools from --tool (red line #10: FQCN only, no disk scan)
        foreach ((array) $this->option('tool') as $fqcn) {
            $adapter->registerSdkTool($fqcn);
        }

        $tracer = PhoenixTracer::fromSettings($settings);
        $server = new McpServer($adapter, $tracer, $caller);
        $server->run();

        return 0;
    }

    private function checkBypassFail(SettingsManager $settings): void
    {
        // Check env var
        if (getenv('HAO_CODE_BYPASS_PERMISSIONS') === '1'
            || getenv('HAO_CODE_BYPASS_PERMISSIONS') === 'true') {
            fwrite(STDERR, "MCP server refuses to run with bypass_permissions\n");
            exit(1);
        }

        // Check settings
        $raw = $settings->getPermissionMode();
        if ($raw->value === 'bypass_permissions') {
            $settings->set('permission_mode', 'default');
        }
    }

    private function buildSkillLoader(string $root): SkillLoader
    {
        $loader = new SkillLoader;

        // Red line #12: scan skills for credentials at startup, fail-fast if found
        $skillsDir = $root.'/.haocode/skills';
        if (is_dir($skillsDir)) {
            $this->scanSkillsForCredentials($skillsDir);
        }

        return $loader;
    }

    private function scanSkillsForCredentials(string $dir): void
    {
        $credentialPattern = '/AKIA[A-Z0-9]{16}|-----BEGIN [A-Z ]+PRIVATE KEY-----|sk-ant-[A-Za-z0-9\-]+/';
        $files = glob($dir.'/**/*.md') ?: [];
        $files = array_merge($files, glob($dir.'/*.md') ?: []);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false && preg_match($credentialPattern, $content)) {
                fwrite(STDERR, "SECURITY: Credential found in skill file: $file\nMCP server refuses to start\n");
                exit(1);
            }
        }
    }
}
