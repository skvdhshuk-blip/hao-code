<?php

namespace Tests\Unit\Services\Cron\Daemon;

use HaoCode\Services\Cron\Daemon\JobStore;
use HaoCode\Services\Permissions\Policy\PolicyMatcher;
use HaoCode\Services\Permissions\Policy\PolicyRule;
use PHPUnit\Framework\TestCase;

class JobStoreTest extends TestCase
{
    private string $dbPath;

    private JobStore $store;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir().'/jobstore_test_'.uniqid().'.sqlite';
        $this->store = new JobStore($this->dbPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbPath);
    }

    public function test_add_and_get_job(): void
    {
        $this->store->addJob('job1', '* * * * *', 'echo hello');
        $job = $this->store->getJob('job1');

        $this->assertNotNull($job);
        $this->assertSame('job1', $job['id']);
        $this->assertSame('* * * * *', $job['cron']);
        $this->assertSame('echo hello', $job['command']);
        $this->assertSame('active', $job['status']);
    }

    public function test_get_all_jobs(): void
    {
        $this->store->addJob('j1', '* * * * *', 'echo a');
        $this->store->addJob('j2', '0 * * * *', 'echo b');

        $jobs = $this->store->getAllJobs();
        $this->assertCount(2, $jobs);
    }

    public function test_remove_job(): void
    {
        $this->store->addJob('j1', '* * * * *', 'echo x');
        $this->assertTrue($this->store->removeJob('j1'));
        $this->assertNull($this->store->getJob('j1'));
        $this->assertFalse($this->store->removeJob('j1'));
    }

    public function test_mark_fired_and_completed(): void
    {
        $this->store->addJob('j1', '* * * * *', 'echo x', recurring: false);
        $this->store->markFired('j1');
        $this->store->markCompleted('j1');

        $job = $this->store->getJob('j1');
        $this->assertSame('completed', $job['status']);
        $this->assertSame(1, (int) $job['fire_count']);
    }

    public function test_record_and_get_history(): void
    {
        $this->store->addJob('j1', '* * * * *', 'echo x');
        $this->store->recordHistory('j1', 100, 105, 0, '', false);
        $this->store->recordHistory('j1', 200, 201, 1, 'error msg', true);

        $history = $this->store->getHistory('j1');
        $this->assertCount(2, $history);
        $this->assertSame(1, (int) $history[0]['exit_code']); // Most recent first
        $this->assertSame(1, (int) $history[0]['secret_detected']);
    }

    public function test_invalid_cron_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->addJob('j1', 'bad cron', 'echo x');
    }

    public function test_policy_matcher_precheck(): void
    {
        // PolicyMatcher with only an 'echo' allow rule → 'rm' command is denied (fail-closed)
        $allowRule = PolicyRule::fromArray([
            'name' => 'allow-echo',
            'tool' => 'Bash',
            'cmd' => 'echo',
        ]);
        $matcher = new PolicyMatcher([$allowRule]);
        $store = new JobStore($this->dbPath, $matcher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/policy/i');
        // 'rm' not in allowlist → deny by default (fail-closed)
        $store->addJob('j1', '* * * * *', 'rm -rf /');
    }

    public function test_policy_matcher_allows_good_command(): void
    {
        $allowRule = PolicyRule::fromArray([
            'name' => 'allow-echo',
            'tool' => 'Bash',
            'cmd' => 'echo',
        ]);
        $matcher = new PolicyMatcher([$allowRule]);
        $store = new JobStore($this->dbPath, $matcher);

        // Should not throw
        $store->addJob('j1', '* * * * *', 'echo hello');
        $this->assertNotNull($store->getJob('j1'));
    }

    public function test_schema_version_validation(): void
    {
        // Corrupt the schema version — should throw on re-open
        $pdo = new \PDO('sqlite:'.$this->dbPath, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('UPDATE schema_version SET version = 999');
        unset($pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/schema version mismatch/i');
        new JobStore($this->dbPath);
    }
}
