<?php

declare(strict_types=1);

namespace Tests\Unit\Hitl;

use HaoCode\Services\Hitl\HitlAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Covers the user-saved "always allow" rule store: frozen dual JSON format
 * (v1 exact-match entries plus v2 exact/prefix rules with per-segment
 * coverage) and fail-closed loading (missing/corrupt/wrong-version files
 * degrade to an empty allowlist without throwing).
 */
class HitlAllowlistTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function rulesFile(mixed $payload): string
    {
        $path = sys_get_temp_dir().'/haocode_allowlist_'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, is_string($payload) ? $payload : json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private static function validFile(string ...$commands): array
    {
        return [
            'version' => 1,
            'rules' => array_map(
                static fn (string $command): array => [
                    'command' => $command,
                    'addedAt' => '2025-01-01T00:00:00+00:00',
                    'source' => 'user',
                ],
                $commands,
            ),
        ];
    }

    public function test_exact_command_matches(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile(self::validFile('sudo ls', 'git push origin main')));

        $this->assertFalse($allowlist->isEmpty());
        $this->assertTrue($allowlist->matches('sudo ls'));
        $this->assertTrue($allowlist->matches('git push origin main'));
        $this->assertFalse($allowlist->matches('sudo ls -la'));
    }

    public function test_surrounding_whitespace_on_the_action_is_trimmed(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile(self::validFile('ls -la')));

        $this->assertTrue($allowlist->matches('  ls -la  '));
        $this->assertTrue($allowlist->matches("\tls -la\n"));
    }

    public function test_inner_whitespace_and_case_differences_do_not_match(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile(self::validFile('sudo ls')));

        $this->assertFalse($allowlist->matches('sudo  ls')); // double space
        $this->assertFalse($allowlist->matches('SUDO ls')); // case
        $this->assertFalse($allowlist->matches('sudo')); // prefix rules are not a thing in v1
    }

    public function test_missing_file_loads_empty_without_throwing(): void
    {
        $allowlist = HitlAllowlist::fromFile(sys_get_temp_dir().'/haocode_allowlist_missing_'.bin2hex(random_bytes(6)).'.json');

        $this->assertTrue($allowlist->isEmpty());
        $this->assertFalse($allowlist->matches('anything'));
    }

    public function test_corrupt_json_loads_empty_without_throwing(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile('{not json'));

        $this->assertTrue($allowlist->isEmpty());
        $this->assertFalse($allowlist->matches('anything'));
    }

    public function test_wrong_version_loads_empty(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 3,
            'rules' => [['command' => 'sudo ls']],
        ]));

        $this->assertTrue($allowlist->isEmpty());
        $this->assertFalse($allowlist->matches('sudo ls'));
    }

    public function test_missing_rules_array_loads_empty(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile(['version' => 1]));

        $this->assertTrue($allowlist->isEmpty());
    }

    public function test_malformed_entries_are_skipped_but_valid_ones_kept(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 1,
            'rules' => [
                'not-an-array',
                ['command' => ''],
                ['command' => 42],
                ['addedAt' => '2025-01-01T00:00:00+00:00'],
                ['command' => 'make deploy', 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertFalse($allowlist->isEmpty());
        $this->assertTrue($allowlist->matches('make deploy'));
        $this->assertFalse($allowlist->matches('42'));
    }

    public function test_empty_allowlist_matches_nothing(): void
    {
        $this->assertFalse(HitlAllowlist::empty()->matches('sudo ls'));
    }

    // --- v2: prefix rules -------------------------------------------------

    public function test_v2_prefix_rule_matches_leading_tokens(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'prefix', 'tokens' => ['git', 'commit'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertFalse($allowlist->isEmpty());
        $this->assertTrue($allowlist->matches('git commit'));
        $this->assertTrue($allowlist->matches('git commit -m "init"'));
        $this->assertFalse($allowlist->matches('git push origin main'));
        $this->assertFalse($allowlist->matches('git')); // shorter than the prefix
        $this->assertFalse($allowlist->matches('git checkout commit'));
    }

    public function test_v2_prefix_rule_strips_quotes_from_token_ends(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'prefix', 'tokens' => ['echo', 'hello'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertTrue($allowlist->matches('echo "hello"'));
        $this->assertTrue($allowlist->matches("echo 'hello'"));
        $this->assertFalse($allowlist->matches('echo "hello-world"'));
    }

    public function test_every_segment_must_hit_a_rule(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'prefix', 'tokens' => ['git', 'commit'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
                ['type' => 'prefix', 'tokens' => ['ls'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        // All segments covered by some rule.
        $this->assertTrue($allowlist->matches('git commit -m x && ls -la'));
        $this->assertTrue($allowlist->matches("git commit -m x; ls | ls -la\nls -lb"));
        // One uncovered segment poisons the whole command.
        $this->assertFalse($allowlist->matches('git commit -m x && rm -rf /'));
        $this->assertFalse($allowlist->matches('git commit -m x && git status'));
        // Quoted separators do not split segments.
        $this->assertFalse($allowlist->matches('echo "a | b"'));
    }

    public function test_whole_command_exact_match_short_circuits_segment_coverage(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'exact', 'command' => 'git commit && rm -rf /', 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        // The whole string equals the exact rule, so the uncovered "rm -rf /"
        // segment never gets evaluated on its own.
        $this->assertTrue($allowlist->matches('git commit && rm -rf /'));
        $this->assertTrue($allowlist->matches('  git commit && rm -rf /  '));
        $this->assertFalse($allowlist->matches('git commit && rm -rf ~'));
    }

    public function test_v2_exact_rule_matches_the_full_command_only(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'exact', 'command' => 'node scripts/foo.js --flag', 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertTrue($allowlist->matches('node scripts/foo.js --flag'));
        $this->assertFalse($allowlist->matches('node scripts/foo.js'));
        $this->assertFalse($allowlist->matches('node scripts/foo.js --flag --extra'));
    }

    public function test_v2_legacy_entry_without_type_behaves_like_v1(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['command' => 'make deploy', 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertFalse($allowlist->isEmpty());
        $this->assertTrue($allowlist->matches('make deploy'));
        $this->assertFalse($allowlist->matches('make deploy fast'));
        // A legacy entry also covers a single segment of a chained command.
        $this->assertFalse($allowlist->matches('make deploy && echo done'));
    }

    public function test_heredoc_command_stored_as_whole_exact_matches(): void
    {
        $command = "cat <<EOF\nhello\nEOF";
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'exact', 'command' => $command, 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertTrue($allowlist->matches($command));
        $this->assertFalse($allowlist->matches("cat <<EOF\nbye\nEOF"));
    }

    public function test_leading_env_assignments_are_stripped_before_matching(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'prefix', 'tokens' => ['ls'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
                ['type' => 'exact', 'command' => 'git status', 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertTrue($allowlist->matches('FOO=1 BAR=2 ls -la'));
        $this->assertTrue($allowlist->matches('FOO=1 ls -la && BAR=2 git status'));
        $this->assertFalse($allowlist->matches('FOO=1')); // nothing left after stripping
    }

    public function test_malformed_v2_entries_are_skipped_but_valid_ones_kept(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'prefix', 'tokens' => []],
                ['type' => 'prefix', 'tokens' => 'nope'],
                ['type' => 'prefix', 'tokens' => ['ok', 42]],
                ['type' => 'prefix'],
                ['type' => 'wildcard', 'command' => 'git *'],
                ['type' => 'exact', 'command' => ''],
                ['type' => 'prefix', 'tokens' => ['ls'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertFalse($allowlist->isEmpty());
        $this->assertTrue($allowlist->matches('ls -la'));
        $this->assertFalse($allowlist->matches('git *'));
    }

    public function test_prefix_rule_in_a_v1_file_is_ignored(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 1,
            'rules' => [
                ['type' => 'prefix', 'tokens' => ['git', 'commit'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $this->assertTrue($allowlist->isEmpty());
        $this->assertFalse($allowlist->matches('git commit -m x'));
    }

    public function test_match_reports_hit_details_for_the_decider_reason(): void
    {
        $allowlist = HitlAllowlist::fromFile($this->rulesFile([
            'version' => 2,
            'rules' => [
                ['type' => 'prefix', 'tokens' => ['git', 'commit'], 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
                ['type' => 'exact', 'command' => 'make deploy', 'addedAt' => '2025-01-01T00:00:00+00:00', 'source' => 'user'],
            ],
        ]));

        $prefix = $allowlist->match('git commit -m x');
        $this->assertSame('prefix', $prefix['type']);
        $this->assertSame(['git', 'commit'], $prefix['tokens']);

        $exact = $allowlist->match('make deploy');
        $this->assertSame('exact', $exact['type']);

        $this->assertNull($allowlist->match('rm -rf /'));
    }
}
