<?php

namespace HaoCode\Console\Commands;

use HaoCode\Skill\Registry\BuiltinArraySource;
use HaoCode\Skill\Registry\GitSkillSource;
use HaoCode\Skill\Registry\ProcOpenGitRunner;
use HaoCode\Skill\Registry\SkillRegistry;
use HaoCode\Tools\Skill\SkillLoader;
use Illuminate\Console\Command;

class SkillsListCommand extends Command
{
    protected $signature = 'skills:list
                            {--source= : Filter by source alias (shows only skills from that source)}';

    protected $description = 'List all available skills (from builtin paths and registered git sources)';

    public function handle(): int
    {
        $filterSource = $this->option('source');

        $registry = new SkillRegistry;

        // Load builtin skills via SkillLoader
        if ($filterSource === null) {
            $loader = new SkillLoader;
            $builtins = $loader->loadSkills();
            $registry->addSource(new BuiltinArraySource('builtin', $builtins));
        }

        // Load registered git sources
        $config = $this->loadConfig();
        $sources = $config['sources'] ?? [];
        $runner = new ProcOpenGitRunner;

        foreach ($sources as $entry) {
            $alias = $entry['alias'] ?? '';
            $url = $entry['url'] ?? '';

            if ($filterSource !== null && $alias !== $filterSource) {
                continue;
            }

            $registry->addSource(new GitSkillSource($url, $alias, $runner));
        }

        $skills = $registry->mergeSources();

        if (empty($skills)) {
            $this->warn('No skills found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($skills as $name => $skill) {
            $rows[] = [$name, mb_substr($skill->description, 0, 80)];
        }

        $this->table(['Name', 'Description'], $rows);
        $this->line('Total: '.count($skills).' skill(s)');

        return self::SUCCESS;
    }

    private function loadConfig(): array
    {
        $path = $this->configPath();
        if (! file_exists($path)) {
            return ['sources' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : ['sources' => []];
    }

    private function configPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return "{$home}/.hao-code/skill-sources.json";
    }
}
