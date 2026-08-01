<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Mcp\Server\ToolAdapter;
use HaoCode\Tools\FileRead\FileReadTool;
use HaoCode\Tools\Grep\GrepTool;
use HaoCode\Tools\Skill\SkillDefinition;
use HaoCode\Tools\Skill\SkillLoader;
use PHPUnit\Framework\TestCase;

final class ToolAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/haocode-mcp-adapter-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/nested', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function test_relative_file_paths_are_executed_against_the_configured_root(): void
    {
        file_put_contents($this->root.'/nested/note.txt', "from configured root\n");

        $adapter = new ToolAdapter;
        $adapter->setRoot($this->root);
        $adapter->registerBuiltin(new FileReadTool);

        $result = $adapter->invoke('Read', ['file_path' => 'nested/note.txt']);

        $this->assertFalse($result['isError'] ?? false);
        $this->assertStringContainsString(
            'from configured root',
            $result['content'][0]['text'] ?? '',
        );
    }

    public function test_grep_pattern_is_not_mistaken_for_a_filesystem_path(): void
    {
        file_put_contents($this->root.'/nested/note.txt', "/needle\n");

        $adapter = new ToolAdapter;
        $adapter->setRoot($this->root);
        $adapter->registerBuiltin(new GrepTool);

        $result = $adapter->invoke('Grep', ['pattern' => '/needle']);

        $this->assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');
        $this->assertStringContainsString('nested/note.txt', $result['content'][0]['text'] ?? '');
    }

    public function test_filesystem_root_remains_a_valid_configured_root(): void
    {
        file_put_contents($this->root.'/nested/root-note.txt', "from filesystem root\n");

        $adapter = new ToolAdapter;
        $adapter->setRoot(DIRECTORY_SEPARATOR);
        $adapter->registerBuiltin(new FileReadTool);

        $relativePath = ltrim($this->root, '/\\').'/nested/root-note.txt';
        $result = $adapter->invoke('Read', ['file_path' => $relativePath]);

        $this->assertFalse($result['isError'] ?? false);
        $this->assertStringContainsString(
            'from filesystem root',
            $result['content'][0]['text'] ?? '',
        );
    }

    public function test_sensitive_path_matching_normalizes_windows_separators(): void
    {
        $method = (new \ReflectionClass(ToolAdapter::class))->getMethod('isSensitivePath');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(new ToolAdapter, 'C:\\workspace\\.ssh\\id_rsa'));
        $this->assertTrue($method->invoke(new ToolAdapter, 'C:\\workspace\\.env.local'));
        $this->assertFalse($method->invoke(new ToolAdapter, 'C:\\workspace\\src\\App.php'));
    }

    public function test_public_prompts_use_configured_root_and_enforce_directory_boundary(): void
    {
        $skillsDir = $this->root.'/.haocode/skills';
        mkdir($skillsDir.'/public-skill', 0700, true);
        file_put_contents(
            $skillsDir.'/public-skill/SKILL.md',
            "---\npublic: true\n---\nPublic prompt body\n",
        );

        $loader = new SkillLoader($this->root);
        $outside = $this->root.'/.haocode/skills-evil/evil-skill';
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/SKILL.md', "---\npublic: true\n---\nEvil prompt body\n");
        $loader->registerSkillDefinition(new SkillDefinition(
            name: 'evil-skill',
            description: 'evil',
            whenToUse: null,
            prompt: 'Evil prompt body',
            skillDir: $outside,
        ));

        $adapter = new ToolAdapter($loader);
        $adapter->setRoot($this->root);

        $prompts = $adapter->listPrompts();

        $this->assertSame(['public-skill'], array_column($prompts, 'name'));
        $this->assertSame('Public prompt body', $adapter->getPrompt('public-skill')['messages'][0]['content']['text']);
        $this->assertTrue($adapter->getPrompt('evil-skill')['isError'] ?? false);
    }

    public function test_disabled_tools_are_not_exposed_or_invokable_over_mcp(): void
    {
        $disabled = new class extends \HaoCode\Tools\BaseTool {
            public function name(): string { return 'DisabledMcpTool'; }
            public function description(): string { return 'disabled'; }
            public function inputSchema(): \HaoCode\Tools\ToolInputSchema
            {
                return \HaoCode\Tools\ToolInputSchema::make(['type' => 'object']);
            }
            public function isEnabled(): bool { return false; }
            public function call(array $input, \HaoCode\Tools\ToolUseContext $context): \HaoCode\Tools\ToolResult
            {
                return \HaoCode\Tools\ToolResult::success('must not run');
            }
        };

        $adapter = new ToolAdapter;
        $adapter->registerBuiltin($disabled);

        $this->assertSame([], $adapter->listTools());
        $this->assertTrue($adapter->invoke('DisabledMcpTool', [])['isError'] ?? false);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $item) {
            $this->removeTree($item->getPathname());
        }
        @rmdir($path);
    }
}
