<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Permissions\SensitivePathGuard;
use PHPUnit\Framework\TestCase;

class SensitivePathGuardTest extends TestCase
{
    /**
     * @dataProvider sensitivePathProvider
     */
    public function test_check_blocks_sensitive_paths(string $key, string $value, string $expectedLabel): void
    {
        $hit = SensitivePathGuard::check('Read', [$key => $value]);

        $this->assertNotNull($hit, "expected hit for {$key}={$value}");
        $this->assertSame($expectedLabel, $hit);
    }

    public static function sensitivePathProvider(): array
    {
        return [
            'ssh dir absolute'        => ['file_path', '/home/u/.ssh/config', 'SSH directory'],
            'ssh private key bare'    => ['file_path', '/home/u/id_ed25519', 'SSH private key'],
            'aws credentials dir'     => ['path', '/home/u/.aws/credentials', 'AWS credentials directory'],
            'dotenv file'             => ['file_path', '/app/.env', 'dotenv file'],
            'dotenv file local'       => ['file_path', '.env.local', 'dotenv file'],
            'credentials filename'    => ['file_path', '/etc/myapp/credentials.json', 'credentials file'],
            'pem key'                 => ['file_path', '/etc/ssl/server.pem', 'key/certificate material'],
            'netrc'                   => ['file_path', '/home/u/.netrc', 'netrc file'],
            'npmrc'                   => ['file_path', '/home/u/.npmrc', 'npmrc file'],
            'pypirc'                  => ['file_path', '/home/u/.pypirc', 'pypirc file'],
            'proc environ'            => ['command', 'cat /proc/self/environ', 'process environment harvesting'],
            'keychain extraction'     => ['command', 'security find-generic-password -s x', 'macOS keychain extraction'],
            'runtime state json'      => ['file_path', '/tmp/runtime-state.json', 'adapter runtime state holding secrets'],
            'windows ssh dir'         => ['file_path', 'C:\\Users\\user\\.ssh\\config', 'SSH directory'],
            'windows aws credentials' => ['path', 'C:\\Users\\user\\.aws\\credentials', 'AWS credentials directory'],
            'windows dotenv file'     => ['file_path', 'C:\\project\\.env.local', 'dotenv file'],
            'windows dotenv ADS'      => ['file_path', 'C:\\project\\.env::$DATA', 'dotenv file'],
            'windows SSH key ADS'     => ['file_path', 'C:\\Users\\user\\id_ed25519::$DATA', 'SSH private key'],
        ];
    }

    /**
     * @dataProvider cleanPathProvider
     */
    public function test_check_passes_clean_paths(string $key, string $value): void
    {
        $this->assertNull(
            SensitivePathGuard::check('Read', [$key => $value]),
            "expected no hit for {$key}={$value}",
        );
    }

    public static function cleanPathProvider(): array
    {
        return [
            'regular source file'  => ['file_path', '/app/src/Service.php'],
            'regular config'       => ['file_path', '/app/config/app.php'],
            'env-in-middle word'   => ['file_path', '/app/Environments.php'], // .env must be boundary-delimited
            'patch file'           => ['file_path', '/tmp/fix.patch'],
            'log file'             => ['file_path', '/var/log/app.log'],
            'innocent bash'        => ['command', 'ls -la /app'],
            'grep command'         => ['command', 'rg "foo" /app/src'],
        ];
    }

    public function test_check_scans_all_path_like_keys(): void
    {
        // The guard must not only look at file_path. Patch, command, target_file
        // etc. all carry path-like content.
        $this->assertNotNull(SensitivePathGuard::check('Edit', ['target_file' => '/home/u/.ssh/id_rsa']));
        $this->assertNotNull(SensitivePathGuard::check('Bash', ['command' => 'cat /home/u/.aws/credentials']));
        $this->assertNotNull(SensitivePathGuard::check('apply_patch', ['patch' => '*** Update File: /x/.env']));
    }

    public function test_check_ignores_non_path_keys(): void
    {
        // Keys outside PATH_LIKE_KEYS are not scanned (avoid false positives
        // on tool result bodies, descriptions, etc.).
        $this->assertNull(
            SensitivePathGuard::check('Read', [
                'description' => 'Read /home/u/.ssh/id_rsa for debugging',
                'content' => 'something about .env file',
            ]),
        );
    }

    public function test_check_ignores_non_string_values(): void
    {
        $this->assertNull(SensitivePathGuard::check('Read', [
            'file_path' => ['array', 'value'],
            'command' => 42,
        ]));
    }

    public function test_match_sensitive_on_resolved_path(): void
    {
        // Callers that have already realpath-ed a value should be able to
        // re-check the canonical form directly.
        $this->assertSame('SSH directory', SensitivePathGuard::matchSensitive('/home/u/.ssh/config'));
        $this->assertSame('SSH directory', SensitivePathGuard::matchSensitive('C:\\Users\\u\\.ssh\\config'));
        $this->assertNull(SensitivePathGuard::matchSensitive('/app/src/File.php'));
    }
}
