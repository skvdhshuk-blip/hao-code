<?php

namespace Tests\Feature;

use HaoCode\Console\Commands\CronAddCommand;
use HaoCode\Console\Commands\CronHistoryCommand;
use HaoCode\Services\Cron\Daemon\JobStore;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CronCommandsTest extends TestCase
{
    private string $tmpDir;

    private string $dbPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/cron_cmd_test_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->dbPath = $this->tmpDir.'/jobs.sqlite';
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->tmpDir.'/*') ?: []);
        @rmdir($this->tmpDir);
    }

    public function test_cron_add_stores_job(): void
    {
        $cmd = $this->buildCommand(CronAddCommand::class, [
            'cron' => '* * * * *',
            'cmd' => 'echo hello',
            '--db' => $this->dbPath,
        ]);

        $result = $cmd->handle();

        $this->assertSame(Command::SUCCESS, $result);

        $store = new JobStore($this->dbPath);
        $jobs = $store->getAllJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('echo hello', $jobs[0]['command']);
        $this->assertSame('* * * * *', $jobs[0]['cron']);
        $this->assertSame(1, (int) $jobs[0]['recurring']);
    }

    public function test_cron_add_once_sets_non_recurring(): void
    {
        $cmd = $this->buildCommand(CronAddCommand::class, [
            'cron' => '0 9 * * *',
            'cmd' => 'echo once',
            '--once' => true,
            '--db' => $this->dbPath,
        ]);

        $cmd->handle();

        $store = new JobStore($this->dbPath);
        $jobs = $store->getAllJobs();
        $this->assertSame(0, (int) $jobs[0]['recurring']);
    }

    public function test_cron_add_rejects_invalid_cron(): void
    {
        $cmd = $this->buildCommand(CronAddCommand::class, [
            'cron' => 'bad',
            'cmd' => 'echo x',
            '--db' => $this->dbPath,
        ]);

        $result = $cmd->handle();
        $this->assertSame(Command::FAILURE, $result);
    }

    public function test_cron_history_shows_all_jobs_when_no_id(): void
    {
        $store = new JobStore($this->dbPath);
        $store->addJob('j1', '* * * * *', 'echo a');

        $cmd = $this->buildCommand(CronHistoryCommand::class, ['--db' => $this->dbPath]);
        $result = $cmd->handle();

        $this->assertSame(Command::SUCCESS, $result);
    }

    public function test_cron_history_returns_failure_for_unknown_job(): void
    {
        $cmd = $this->buildCommand(CronHistoryCommand::class, [
            'job_id' => 'nonexistent',
            '--db' => $this->dbPath,
        ]);

        $result = $cmd->handle();
        $this->assertSame(Command::FAILURE, $result);
    }

    private function buildCommand(string $class, array $args): Command
    {
        $cmd = new $class;
        $definition = $cmd->getDefinition();
        $input = new ArrayInput($args, $definition);
        $output = new BufferedOutput;

        $cmd->setInput($input);
        $cmd->setOutput(new OutputStyle($input, $output));

        return $cmd;
    }
}
