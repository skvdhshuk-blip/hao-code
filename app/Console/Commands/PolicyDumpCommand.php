<?php

namespace HaoCode\Console\Commands;

use HaoCode\Services\Permissions\Policy\PolicyLoader;
use HaoCode\Services\Permissions\Policy\PolicyRule;
use Illuminate\Console\Command;

class PolicyDumpCommand extends Command
{
    protected $signature = 'policy:dump
                            {files* : One or more policy YAML file paths}
                            {--output= : Output file path (default: stdout)}
                            {--pretty : Pretty-print JSON}';

    protected $description = 'Dump loaded policy rules as JSON (for CI / sharing)';

    public function handle(): int
    {
        $files = $this->argument('files');
        $outputPath = $this->option('output');
        $pretty = $this->option('pretty');

        $loader = new PolicyLoader;
        $rules = [];

        foreach ($files as $file) {
            if (! file_exists($file)) {
                $this->error("Policy file not found: {$file}");

                return self::FAILURE;
            }
            try {
                $loaded = $loader->load($file);
                $rules = array_merge($rules, $loaded);
            } catch (\Throwable $e) {
                $this->error("Failed to load '{$file}': ".$e->getMessage());

                return self::FAILURE;
            }
        }

        $data = array_map(fn (PolicyRule $r) => $this->ruleToArray($r), $rules);
        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES;
        $json = json_encode(['rules' => $data], $flags);

        if ($outputPath !== null) {
            file_put_contents($outputPath, $json."\n");
            $this->info('Dumped '.count($rules)." rule(s) to {$outputPath}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    private function ruleToArray(PolicyRule $rule): array
    {
        $data = [
            'name' => $rule->name,
            'tool' => $rule->tool,
            'cmd' => $rule->cmd,
            'risk' => $rule->risk,
            'allow_chain' => $rule->allowChain,
            'allow_auto' => $rule->allowAuto,
        ];

        if (! empty($rule->argsMatch)) {
            $data['args_match'] = $rule->argsMatch;
        }
        if (! empty($rule->envAllow)) {
            $data['env_allow'] = $rule->envAllow;
        }
        if (! empty($rule->envDeny)) {
            $data['env_deny'] = $rule->envDeny;
        }
        if ($rule->approvalTtl > 0) {
            $data['approval_ttl'] = $rule->approvalTtl;
        }
        if ($rule->cwdRestriction !== null) {
            $data['cwd_restriction'] = $rule->cwdRestriction;
        }
        if ($rule->note !== null) {
            $data['note'] = $rule->note;
        }

        return $data;
    }
}
