<?php

namespace HaoCode\Console\Commands;

use HaoCode\Skill\Registry\GitSkillSource;
use HaoCode\Skill\Registry\ProcOpenGitRunner;
use Illuminate\Console\Command;

class SkillsUpdateCommand extends Command
{
    protected $signature = 'skills:update
                            {alias? : Update only this source alias (omit to update all)}';

    protected $description = 'Sync registered git skill sources (git pull / clone)';

    public function handle(): int
    {
        $config = $this->loadConfig();
        $sources = $config['sources'] ?? [];

        if (empty($sources)) {
            $this->warn('No skill sources registered. Use <comment>skills:add-source</comment> to add one.');

            return self::SUCCESS;
        }

        $filterAlias = $this->argument('alias');

        $runner = new ProcOpenGitRunner;
        $updated = 0;

        foreach ($sources as $entry) {
            $alias = $entry['alias'] ?? '';
            $url = $entry['url'] ?? '';

            if ($filterAlias !== null && $alias !== $filterAlias) {
                continue;
            }

            $this->line("Syncing <info>{$alias}</info> ({$url})…");

            $source = new GitSkillSource($url, $alias, $runner);
            $count = $source->sync();

            if ($count < 0) {
                $this->error("  Failed to sync '{$alias}'.");
            } else {
                $this->info("  Fetched {$count} skill(s) from '{$alias}'.");
                $updated++;
            }
        }

        if ($filterAlias !== null && $updated === 0) {
            $this->error("No source found with alias '{$filterAlias}'.");

            return self::FAILURE;
        }

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
