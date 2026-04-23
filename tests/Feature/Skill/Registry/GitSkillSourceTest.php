<?php

namespace Tests\Feature\Skill\Registry;

use HaoCode\Skill\Registry\GitRunner;
use HaoCode\Skill\Registry\GitSkillSource;
use HaoCode\Tools\Skill\SkillDefinition;
use PHPUnit\Framework\TestCase;

class GitSkillSourceTest extends TestCase
{
    private string $tmpDir;

    private string $cacheBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/hao-git-test-'.uniqid();
        $this->cacheBase = $this->tmpDir.'/cache';
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    // --- helpers ---

    private function makeRunner(int $exitCode = 0, string $stdout = '', string $stderr = ''): GitRunner
    {
        $runner = $this->createMock(GitRunner::class);
        $runner->method('run')->willReturn([$exitCode, $stdout, $stderr]);

        return $runner;
    }

    private function makeSource(GitRunner $runner, string $url = 'https://example.com/skills.git', string $alias = 'test'): GitSkillSource
    {
        return new GitSkillSource($url, $alias, $runner, null, $this->cacheBase);
    }

    private function seedCacheWithSkill(string $url, string $skillName, string $body): string
    {
        $cacheDir = $this->cacheBase.'/'.sha1($url);
        mkdir($cacheDir, 0755, true);
        $skillDir = $cacheDir.'/'.$skillName;
        mkdir($skillDir, 0755, true);
        file_put_contents($skillDir.'/SKILL.md', "---\ndescription: Test skill\n---\n{$body}");

        return $cacheDir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // --- tests ---

    public function test_alias_returns_configured_value(): void
    {
        $source = $this->makeSource($this->makeRunner(), 'https://example.com/s.git', 'mypkg');
        $this->assertSame('mypkg', $source->alias());
    }

    public function test_is_syncable(): void
    {
        $source = $this->makeSource($this->makeRunner());
        $this->assertTrue($source->isSyncable());
    }

    public function test_load_returns_empty_when_cache_missing_and_clone_fails(): void
    {
        $runner = $this->makeRunner(exitCode: 1, stderr: 'Repository not found');
        $source = $this->makeSource($runner);

        $skills = $source->load();
        $this->assertSame([], $skills);
    }

    public function test_load_clones_and_returns_skills_when_cache_absent(): void
    {
        $url = 'https://example.com/skills.git';
        $runner = $this->createMock(GitRunner::class);

        // On clone call, seed the cache directory
        $runner->method('run')->willReturnCallback(function (array $args) use ($url) {
            if ($args[0] === 'clone') {
                $this->seedCacheWithSkill($url, 'my-skill', 'Do something cool.');

                return [0, '', ''];
            }

            return [0, '', ''];
        });

        $source = new GitSkillSource($url, 'test', $runner, null, $this->cacheBase);
        $skills = $source->load();

        $this->assertArrayHasKey('my-skill', $skills);
        $this->assertInstanceOf(SkillDefinition::class, $skills['my-skill']);
        $this->assertSame('Test skill', $skills['my-skill']->description);
    }

    public function test_sync_calls_clone_when_no_cache(): void
    {
        $url = 'https://example.com/skills.git';
        $runner = $this->createMock(GitRunner::class);

        $runner->expects($this->once())
            ->method('run')
            ->with($this->callback(fn ($args) => in_array('clone', $args, true)))
            ->willReturnCallback(function () use ($url) {
                $this->seedCacheWithSkill($url, 'skill-a', 'Skill A body.');

                return [0, '', ''];
            });

        $source = new GitSkillSource($url, 'test', $runner, null, $this->cacheBase);
        $count = $source->sync();

        $this->assertSame(1, $count);
    }

    public function test_sync_calls_pull_when_cache_exists(): void
    {
        $url = 'https://example.com/skills.git';
        $this->seedCacheWithSkill($url, 'existing', 'Existing skill body.');

        $runner = $this->createMock(GitRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->with(['pull', '--depth', '1'], $this->isType('string'))
            ->willReturn([0, '', '']);

        $source = new GitSkillSource($url, 'test', $runner, null, $this->cacheBase);
        $count = $source->sync();

        $this->assertSame(1, $count);
    }

    public function test_sync_returns_negative_on_git_failure(): void
    {
        $url = 'https://example.com/skills.git';

        $runner = $this->makeRunner(exitCode: 128, stderr: 'fatal: could not read Username');
        $source = new GitSkillSource($url, 'test', $runner, null, $this->cacheBase);
        $count = $source->sync();

        $this->assertSame(-1, $count);
    }

    public function test_cache_dir_uses_sha1_of_url(): void
    {
        $url = 'https://example.com/repo.git';
        $source = new GitSkillSource($url, 'alias', $this->makeRunner(), null, $this->cacheBase);
        $expected = $this->cacheBase.'/'.sha1($url);

        // Reflect to access private property
        $ref = new \ReflectionProperty($source, 'cacheDir');
        $ref->setAccessible(true);
        $this->assertSame($expected, $ref->getValue($source));
    }

    public function test_load_reads_legacy_md_files(): void
    {
        $url = 'https://example.com/legacy.git';
        $cacheDir = $this->cacheBase.'/'.sha1($url);
        mkdir($cacheDir, 0755, true);
        file_put_contents($cacheDir.'/cool-skill.md', "---\ndescription: A legacy skill\n---\nLegacy body.");

        $source = new GitSkillSource($url, 'legacy', $this->makeRunner(), null, $this->cacheBase);
        $skills = $source->load();

        $this->assertArrayHasKey('cool-skill', $skills);
        $this->assertSame('A legacy skill', $skills['cool-skill']->description);
    }
}
