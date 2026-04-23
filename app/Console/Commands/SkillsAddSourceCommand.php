<?php

namespace HaoCode\Console\Commands;

use Illuminate\Console\Command;

class SkillsAddSourceCommand extends Command
{
    protected $signature = 'skills:add-source
                            {url : Git repository URL (public HTTPS or SSH)}
                            {alias : Short alias used as skill namespace prefix}
                            {--force : Overwrite existing source with same alias}';

    protected $description = 'Register a git repository as a skill source in ~/.hao-code/skill-sources.json';

    public function handle(): int
    {
        $url = $this->argument('url');
        $alias = $this->argument('alias');

        if (! preg_match('/^(https:\/\/|git@)/', $url)) {
            $this->error('URL must use HTTPS (https://) or SSH (git@) protocol. file://, http://, and other protocols are not allowed.');

            return self::FAILURE;
        }

        if (! preg_match('/^[a-z0-9_-]+$/i', $alias)) {
            $this->error('Alias must contain only alphanumeric characters, hyphens, or underscores.');

            return self::FAILURE;
        }

        $config = $this->loadConfig();

        foreach ($config['sources'] as $existing) {
            if ($existing['alias'] === $alias && ! $this->option('force')) {
                $this->error("Source with alias '{$alias}' already exists. Use --force to overwrite.");

                return self::FAILURE;
            }
        }

        // Remove any existing entry with the same alias
        $config['sources'] = array_values(array_filter(
            $config['sources'],
            fn ($s) => $s['alias'] !== $alias
        ));

        $config['sources'][] = ['url' => $url, 'alias' => $alias];

        $this->saveConfig($config);

        $this->info("Added source '{$alias}' → {$url}");
        $this->line('Run <comment>skills:update</comment> to fetch skills from this source.');

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

    private function saveConfig(array $config): void
    {
        $path = $this->configPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        chmod($path, 0600);
    }

    private function configPath(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        return "{$home}/.hao-code/skill-sources.json";
    }
}
