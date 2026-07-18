<?php

declare(strict_types=1);

namespace Tests\Unit\Hitl;

use HaoCode\Services\Hitl\HitlAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Covers the user-saved "always allow" rule store: frozen JSON format,
 * exact-match semantics, and fail-closed loading (missing/corrupt/wrong
 * version files degrade to an empty allowlist without throwing).
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
            'version' => 2,
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
}
