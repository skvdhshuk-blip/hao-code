<?php

namespace HaoCode\Services\Mcp\Server;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Sdk\SdkTool;
use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Tools\Skill\SkillDefinition;
use HaoCode\Tools\Skill\SkillLoader;
use HaoCode\Tools\ToolUseContext;
use Throwable;

/**
 * Bridges Tool / SdkTool instances to MCP tool meta and invocation.
 * Handles exception redaction (red line #11) and SdkTool registration gate.
 */
class ToolAdapter
{
    /** Sensitive path blacklist patterns (red line #3) */
    private const SENSITIVE_PATTERNS = [
        '/^\.env/', '/\.key$/', '/^id_rsa/', '/^id_ed25519/',
        '/\.git\/config$/', '/\/\.ssh\//', '/\/\.aws\//',
        '/vendor\/.*\.env/', '/vendor\/.*\.key$/',
    ];

    /** Pattern to redact tokens, keys from exception messages */
    private const REDACT_PATTERN = '/([A-Za-z0-9+\/=]{20,}|Bearer [^\s]+|sk-[A-Za-z0-9]+|AKIA[A-Z0-9]{16})/';

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** @var array<string, string> FQCN → user.name map */
    private array $sdkToolMap = [];

    private string $root;

    public function __construct(
        private readonly ?SkillLoader $skillLoader = null,
    ) {
        $this->root = getcwd() ?: '/';
    }

    public function setRoot(string $root): void
    {
        $trimmed = rtrim($root, '/\\');
        // Keep filesystem roots intact: rtrim('/') would otherwise turn the
        // configured root into an empty working directory.
        if ($trimmed === '' || preg_match('/^[A-Za-z]:$/', $trimmed) === 1) {
            $trimmed = $root;
        }
        $this->root = $trimmed;
    }

    /** Register a built-in tool (Read/Bash/Write/Grep/Glob). */
    public function registerBuiltin(ToolInterface $tool): void
    {
        $name = $tool->name();
        $this->tools[$name] = $tool;
    }

    /**
     * Register an SdkTool from FQCN (red line #10).
     * Fails fast on naming collision with built-in.
     */
    public function registerSdkTool(string $fqcn): void
    {
        if (! class_exists($fqcn)) {
            throw new \RuntimeException("SdkTool class not found: $fqcn");
        }

        $instance = new $fqcn;
        if (! ($instance instanceof SdkTool)) {
            throw new \RuntimeException("$fqcn is not an SdkTool");
        }

        $shortName = (new \ReflectionClass($fqcn))->getShortName();
        $mcpName = 'user.'.$shortName;

        if (isset($this->tools[$instance->name()])) {
            throw new \RuntimeException("SdkTool naming collision: '{$instance->name()}' already registered as built-in");
        }

        $this->tools[$mcpName] = $instance;
        $this->sdkToolMap[$fqcn] = $mcpName;

        fwrite(STDERR, "[allowed] {$mcpName} from {$fqcn}\n");
    }

    /** Returns MCP tools/list format. */
    public function listTools(): array
    {
        $list = [];
        foreach ($this->tools as $name => $tool) {
            if (! $tool->isEnabled()) {
                continue;
            }
            $schema = $tool->inputSchema()->toJsonSchema();
            $list[] = [
                'name' => $name,
                'description' => $tool->description(),
                'inputSchema' => $schema,
            ];
        }

        return $list;
    }

    /**
     * Invoke a tool by MCP name.
     * Applies: root prefix check, sensitive path blacklist, permission check, exception redaction.
     */
    public function invoke(string $name, array $args): array
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null || ! $tool->isEnabled()) {
            return $this->denied('access denied');
        }

        // Keep the MCP server-side path and validation contract in step with
        // ToolOrchestrator.  ToolAdapter invokes tools directly, so without
        // this normalization a relative Read/Write/Grep/Glob path is checked
        // against the configured root but then executed relative to the PHP
        // process cwd.
        $ctx = new ToolUseContext(workingDirectory: $this->root, sessionId: 'mcp');
        try {
            $args = $tool->inputSchema()->validate($args);
        } catch (\InvalidArgumentException) {
            return $this->denied('invalid tool input');
        }

        $validationError = $tool->validateInput($args, $ctx);
        if ($validationError !== null) {
            return $this->denied('invalid tool input');
        }

        $args = $tool->backfillObservableInput($args, $ctx);

        // Path validation for built-ins that accept file paths (red lines #3 & #4)
        $pathArg = $args['file_path'] ?? $args['path'] ?? null;
        if ($pathArg !== null && is_string($pathArg)) {
            // Normalize the path for checking; resolve relative to root on
            // both Unix and Windows (including drive-letter and UNC paths).
            $normalizedRoot = realpath($this->root)
                ?: CanonicalPathResolver::resolve($this->root, getcwd() ?: '/');
            $absPath = CanonicalPathResolver::resolve($pathArg, $normalizedRoot);

            // Sensitive filename check (before realpath to catch .env etc.)
            if ($this->isSensitivePath(basename($pathArg)) || $this->isSensitivePath($pathArg)) {
                return $this->denied('access denied');
            }

            // For existing paths, use realpath (resolves symlinks - red line #4)
            $candidate = realpath($absPath) ?: $absPath;
            if (! CanonicalPathResolver::isWithin($candidate, $normalizedRoot)) {
                return $this->denied('access denied');
            }
        }

        // Check permissions via tool's own checkPermissions
        $decision = $tool->checkPermissions($args, $ctx);

        if (! $decision->allowed) {
            return $this->denied('access denied');
        }

        try {
            $result = $tool->call($args, $ctx);
            $isSdk = isset($this->sdkToolMap[get_class($tool)]) || str_starts_with($name, 'user.');
            $origin = $isSdk ? 'sdk' : 'builtin';

            if ($result->isError) {
                $msg = $isSdk ? $this->redactMessage($result->output) : 'access denied';

                return [
                    'isError' => true,
                    'content' => [['type' => 'text', 'text' => $msg]],
                    'tool.origin' => $origin,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => $result->output]],
                'tool.origin' => $origin,
            ];
        } catch (Throwable $e) {
            $isSdk = str_starts_with($name, 'user.');
            $msg = $isSdk ? $this->redactMessage($e->getMessage()) : 'access denied';

            return [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $msg]],
            ];
        }
    }

    /** Returns MCP prompts/list format (only public:true skills from project dir). */
    public function listPrompts(): array
    {
        if ($this->skillLoader === null) {
            return [];
        }
        $prompts = [];
        foreach ($this->skillLoader->loadSkills() as $name => $skill) {
            if (! $this->isPublicProjectSkill($skill)) {
                continue;
            }
            $prompts[] = [
                'name' => $name,
                'description' => $skill->description,
            ];
        }

        return $prompts;
    }

    /** Returns MCP prompts/get format with credential masking. */
    public function getPrompt(string $name): array
    {
        if ($this->skillLoader === null) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'access denied']]];
        }

        $skill = $this->skillLoader->findSkill($name);
        if ($skill === null || ! $this->isPublicProjectSkill($skill)) {
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'access denied']]];
        }

        // Outbound scan + mask credentials (red line #12)
        $body = $this->maskCredentials($skill->getPrompt());

        return [
            'description' => $skill->description,
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $body]],
            ],
        ];
    }

    private function isPublicProjectSkill(SkillDefinition $skill): bool
    {
        // Must have public: true in frontmatter (we check skillDir is under project dir)
        $projectRoot = CanonicalPathResolver::resolve($this->root, getcwd() ?: '/');
        $projectSkillsDir = CanonicalPathResolver::resolve(
            rtrim($projectRoot, '/\\').'/.haocode/skills',
            $projectRoot,
        );
        $skillRealDir = realpath($skill->skillDir)
            ?: CanonicalPathResolver::resolve($skill->skillDir, $projectRoot);

        // Only project skills (not global ~/.haocode/skills)
        if (! CanonicalPathResolver::isWithin($skillRealDir, $projectSkillsDir)) {
            return false;
        }

        // We need the skill to have 'public: true' — we check via context property
        // SkillDefinition doesn't carry this directly; we'll re-read the file
        $skillFile = rtrim($skillRealDir, '/\\').'/SKILL.md';
        if (! file_exists($skillFile)) {
            $skillFile = $skillRealDir.'.md';
        }
        if (! file_exists($skillFile)) {
            return false;
        }

        $content = file_get_contents($skillFile);
        if ($content === false) {
            return false;
        }

        if (preg_match('/^---\s*\n(.*?)\n---/s', $content, $m)) {
            return (bool) preg_match('/^public:\s*true\s*$/m', $m[1]);
        }

        return false;
    }

    private function isSensitivePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        $base = basename($normalized);
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $base) || preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function redactMessage(string $msg): string
    {
        // Check for SQL/PDO/stacktrace patterns
        $dangerous = ['/SQLSTATE/', '/PDO/', '/Stack trace/', '/vendor\//', '/HaoCode\\\\/'];
        foreach ($dangerous as $pattern) {
            if (preg_match($pattern, $msg)) {
                return 'tool execution failed';
            }
        }
        $msg = preg_replace(self::REDACT_PATTERN, '***', $msg) ?? $msg;

        return mb_substr($msg, 0, 200);
    }

    private function maskCredentials(string $text): string
    {
        // Mask credential patterns in skill body (red line #12)
        $text = preg_replace('/AKIA[A-Z0-9]{16}/', '***MASKED***', $text) ?? $text;
        $text = preg_replace('/-----BEGIN [A-Z ]+PRIVATE KEY-----.*?-----END [A-Z ]+PRIVATE KEY-----/s', '***MASKED***', $text) ?? $text;
        $text = preg_replace('/sk-ant-[A-Za-z0-9\-]+/', '***MASKED***', $text) ?? $text;
        $text = preg_replace('/token:\s*[A-Za-z0-9\-_\.]+/', 'token: ***MASKED***', $text) ?? $text;
        // Strip template expressions that would leak config/env
        $text = preg_replace('/\{\{\s*(env|config)\s*\(.*?\)\s*\}\}/', '{{ /* masked */ }}', $text) ?? $text;

        return $text;
    }

    private function denied(string $msg): array
    {
        return [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => $msg]],
        ];
    }
}
