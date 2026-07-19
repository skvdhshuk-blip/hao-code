<?php

namespace Tests\Services\Permissions\Policy;

use HaoCode\Services\Permissions\Policy\PolicyLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PolicyLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/policy_loader_test_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir.'/*.yml'));
        rmdir($this->tmpDir);
    }

    private function writeYaml(string $name, string $content): string
    {
        $path = $this->tmpDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    private function validRule(array $overrides = []): array
    {
        return array_merge([
            'name' => 'test-rule',
            'tool' => 'Bash',
            'cmd' => 'git',
            'risk' => 'normal',
            'allow_chain' => false,
            'allow_auto' => false,
            'env_deny' => PolicyLoader::REQUIRED_ENV_DENY,
        ], $overrides);
    }

    // Validation 1: regex length > 1000 → fail-closed
    public function test_rejects_regex_exceeding_1000_chars(): void
    {
        $longPattern = '/'.str_repeat('a', 1001).'/';
        $path = $this->writeYaml('v1.yml', "rules:\n  - ".$this->flatRule([
            'name' => 'v1',
            'tool' => 'Bash',
            'cmd' => 'git',
            'args_match' => [$longPattern],
            'env_deny' => PolicyLoader::REQUIRED_ENV_DENY,
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds 1000 chars/');

        (new PolicyLoader)->load($path);
    }

    public function test_accepts_regex_within_1000_chars(): void
    {
        $pattern = '/'.str_repeat('a', 998).'/';
        $rules = $this->validRule(['args_match' => [$pattern]]);
        $path = $this->writeYamlFromRules([$rules]);

        $loaded = (new PolicyLoader)->load($path);
        $this->assertCount(1, $loaded);
    }

    // Validation 2: conflicting rules for same tool+cmd → fail-closed
    public function test_rejects_conflicting_rules_same_tool_cmd(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['name' => 'rule-a', 'allow_chain' => false]),
            $this->validRule(['name' => 'rule-b', 'allow_chain' => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Conflicting rules/');

        (new PolicyLoader)->load($path);
    }

    public function test_accepts_compatible_rules_different_cmds(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['name' => 'rule-a', 'cmd' => 'git']),
            $this->validRule(['name' => 'rule-b', 'cmd' => 'composer']),
        ]);

        $loaded = (new PolicyLoader)->load($path);
        $this->assertCount(2, $loaded);
    }

    // Validation 2 (expanded): same tool+cmd but different args_match signatures
    // must coexist — this is the case the bundled policies/default.yml relies
    // on (git status vs git push --force share cmd=git but differ on risk).
    public function test_accepts_same_cmd_with_different_args(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['name' => 'git-status', 'args_match' => ['status*'], 'allow_auto' => true]),
            $this->validRule(['name' => 'git-force-push', 'args_match' => ['/push.*--force/'], 'risk' => 'high']),
        ]);

        $loaded = (new PolicyLoader)->load($path);
        $this->assertCount(2, $loaded);
    }

    public function test_rejects_same_cmd_same_args_different_risk(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['name' => 'rule-a', 'args_match' => ['status*'], 'risk' => 'normal']),
            $this->validRule(['name' => 'rule-b', 'args_match' => ['status*'], 'risk' => 'high']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Conflicting rules/');

        (new PolicyLoader)->load($path);
    }

    public function test_rejects_same_cmd_same_args_different_allow_auto(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['name' => 'rule-a', 'args_match' => ['status*'], 'allow_auto' => false]),
            $this->validRule(['name' => 'rule-b', 'args_match' => ['status*'], 'allow_auto' => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Conflicting rules/');

        (new PolicyLoader)->load($path);
    }

    public function test_loads_bundled_default_yml_end_to_end(): void
    {
        // Regression: the bundled policies/default.yml must load cleanly through
        // the real Loader. Before the args_signature fix this threw on Bash::git
        // because git-status/git-log/git-diff (normal) collided with
        // git-push-force (high).
        $loaded = (new PolicyLoader)->load(dirname(__DIR__, 4).'/policies/default.yml');
        $this->assertGreaterThan(0, count($loaded));
    }

    public function test_loads_bundled_laravel_dev_yml_end_to_end(): void
    {
        $loaded = (new PolicyLoader)->load(dirname(__DIR__, 4).'/policies/laravel-dev.yml');
        $this->assertGreaterThan(0, count($loaded));
    }

    // Validation 3: risk=high + allow_auto=true → fail-closed
    public function test_rejects_high_risk_with_allow_auto(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['risk' => 'high', 'allow_auto' => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/risk=high combined with allow_auto=true/');

        (new PolicyLoader)->load($path);
    }

    public function test_accepts_high_risk_without_allow_auto(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['risk' => 'high', 'allow_auto' => false]),
        ]);

        $loaded = (new PolicyLoader)->load($path);
        $this->assertTrue($loaded[0]->isHighRisk());
    }

    // Validation 4: env_deny missing default entries → fail-closed
    public function test_rejects_env_deny_missing_required_entries(): void
    {
        $incompleteEnvDeny = ['LD_PRELOAD']; // missing others
        $path = $this->writeYamlFromRules([
            $this->validRule(['env_deny' => $incompleteEnvDeny]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/env_deny is missing required entries/');

        (new PolicyLoader)->load($path);
    }

    public function test_accepts_env_deny_with_all_required_entries(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['env_deny' => PolicyLoader::REQUIRED_ENV_DENY]),
        ]);

        $loaded = (new PolicyLoader)->load($path);
        $this->assertNotEmpty($loaded[0]->envDeny);
    }

    // Validation 5: cwd_restriction invalid path → fail-closed
    public function test_rejects_relative_cwd_restriction(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['cwd_restriction' => 'relative/path']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be an absolute path/');

        (new PolicyLoader)->load($path);
    }

    public function test_accepts_valid_absolute_cwd_restriction(): void
    {
        $path = $this->writeYamlFromRules([
            $this->validRule(['cwd_restriction' => '/var/www/app']),
        ]);

        $loaded = (new PolicyLoader)->load($path);
        $this->assertSame('/var/www/app', $loaded[0]->cwdRestriction);
    }

    public function test_file_not_found_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Policy file not found/');

        (new PolicyLoader)->load('/nonexistent/path/policy.yml');
    }

    private function writeYamlFromRules(array $rules): string
    {
        $lines = ['rules:'];
        foreach ($rules as $rule) {
            $lines[] = '  - '.$this->flatRule($rule);
        }

        return $this->writeYaml('test_'.uniqid().'.yml', implode("\n", $lines));
    }

    private function flatRule(array $rule): string
    {
        $yaml = "name: {$rule['name']}\n    tool: {$rule['tool']}\n    cmd: {$rule['cmd']}";

        if (! empty($rule['risk'])) {
            $yaml .= "\n    risk: {$rule['risk']}";
        }
        if (isset($rule['allow_chain'])) {
            $yaml .= "\n    allow_chain: ".($rule['allow_chain'] ? 'true' : 'false');
        }
        if (isset($rule['allow_auto'])) {
            $yaml .= "\n    allow_auto: ".($rule['allow_auto'] ? 'true' : 'false');
        }
        if (isset($rule['cwd_restriction'])) {
            $yaml .= "\n    cwd_restriction: {$rule['cwd_restriction']}";
        }
        if (! empty($rule['args_match'])) {
            $yaml .= "\n    args_match:";
            foreach ($rule['args_match'] as $p) {
                $yaml .= "\n      - \"$p\"";
            }
        }
        if (! empty($rule['env_deny'])) {
            $yaml .= "\n    env_deny:";
            foreach ($rule['env_deny'] as $e) {
                $yaml .= "\n      - $e";
            }
        }

        return $yaml;
    }
}
