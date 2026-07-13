<?php

namespace Tests\Unit;

use HaoCode\Sdk\AgentRunContextFactory;
use HaoCode\Sdk\HaoCodeConfig;
use HaoCode\Sdk\SdkSkill;
use Tests\TestCase;

class AgentRunContextFactoryTest extends TestCase
{
    /** @var string[] */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_it_isolates_working_directory_settings_and_skills_per_run(): void
    {
        $firstDirectory = $this->makeProjectDirectory('first-project');
        $secondDirectory = $this->makeProjectDirectory('second-project');

        file_put_contents($firstDirectory.'/.haocode/settings.json', json_encode([
            'system_prompt' => 'first project prompt',
        ]));
        file_put_contents($secondDirectory.'/.haocode/settings.json', json_encode([
            'system_prompt' => 'second project prompt',
        ]));

        $first = AgentRunContextFactory::make(new HaoCodeConfig(
            cwd: $firstDirectory,
            permissionMode: 'plan',
            skills: [new SdkSkill('first-only', 'First skill', 'First prompt')],
        ));
        $second = AgentRunContextFactory::make(new HaoCodeConfig(
            cwd: $secondDirectory,
            permissionMode: 'bypass_permissions',
        ));

        $this->assertSame($firstDirectory, $first->workingDirectory);
        $this->assertSame($firstDirectory, $first->projectDirectory);
        $this->assertSame('first project prompt', $first->settings->getSystemPrompt());
        $this->assertSame('second project prompt', $second->settings->getSystemPrompt());
        $this->assertSame('plan', $first->settings->getPermissionMode()->value);
        $this->assertSame('bypass_permissions', $second->settings->getPermissionMode()->value);
        $this->assertNotNull($first->skillLoader->findSkill('first-only'));
        $this->assertNull($second->skillLoader->findSkill('first-only'));
    }

    public function test_sandbox_working_directory_does_not_replace_host_project_directory(): void
    {
        $projectDirectory = $this->makeProjectDirectory('sandbox-project');
        $context = AgentRunContextFactory::make(new HaoCodeConfig(
            cwd: $projectDirectory,
            sandbox: \HaoCode\Sdk\Sandbox\SandboxConfig::local(remoteCwd: '/workspace'),
        ));

        $this->assertSame('/workspace', $context->workingDirectory);
        $this->assertSame($projectDirectory, $context->projectDirectory);
    }

    public function test_explicit_api_key_is_preserved_in_forked_run_context(): void
    {
        $projectDirectory = $this->makeProjectDirectory('explicit-key-project');
        $context = AgentRunContextFactory::make(new HaoCodeConfig(
            apiKey: 'parent-explicit-key',
            cwd: $projectDirectory,
        ));

        $child = $context->fork();

        $this->assertSame('parent-explicit-key', $context->settings->getApiKey());
        $this->assertSame('parent-explicit-key', $child->settings->getApiKey());
    }

    public function test_additional_recursive_skill_directories_are_propagated_to_run_context(): void
    {
        $projectDirectory = $this->makeProjectDirectory('skill-directory-project');
        $skillDirectory = $projectDirectory.'/claude-skills/group/imported';
        mkdir($skillDirectory, 0755, true);
        file_put_contents($skillDirectory.'/SKILL.md', "---\ndescription: Imported skill\n---\nPrompt");

        $context = AgentRunContextFactory::make(new HaoCodeConfig(
            cwd: $projectDirectory,
            skillDirectories: [$projectDirectory.'/claude-skills'],
            recursiveSkillDiscovery: true,
        ));

        $this->assertSame('Imported skill', $context->skillLoader->findSkill('imported')?->description);
    }

    public function test_sdk_skill_rejects_an_unknown_execution_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Skill context must be "inline" or "fork".');

        new \HaoCode\Sdk\SdkSkill(
            name: 'invalid-context',
            description: 'Invalid context fixture',
            prompt: 'Fixture prompt',
            context: 'background',
        );
    }

    private function makeProjectDirectory(string $name): string
    {
        $directory = sys_get_temp_dir().'/haocode_run_context_'.uniqid($name.'_', true);
        mkdir($directory.'/.haocode', 0755, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
