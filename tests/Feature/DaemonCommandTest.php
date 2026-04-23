<?php

namespace Tests\Feature;

use HaoCode\Console\Commands\DaemonCommand;
use HaoCode\Services\Cron\Daemon\JobStore;
use HaoCode\Services\Cron\Migration\LegacyImporter;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DaemonCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/daemon_cmd_test_'.uniqid();
        mkdir($this->tmpDir, 0700, true);

        // Clear HAO_CODE_CRON_MODE from previous tests
        putenv('HAO_CODE_CRON_MODE');
    }

    protected function tearDown(): void
    {
        putenv('HAO_CODE_CRON_MODE');
        @array_map('unlink', glob($this->tmpDir.'/*') ?: []);
        @rmdir($this->tmpDir);
    }

    public function test_daemon_start_requires_explicit_cron_mode(): void
    {
        // No HAO_CODE_CRON_MODE set — must fail with non-zero exit
        putenv('HAO_CODE_CRON_MODE');

        $dbPath = $this->tmpDir.'/jobs.sqlite';
        $pidPath = $this->tmpDir.'/daemon.pid';

        $cmd = $this->buildCommand(['action' => 'start', '--db' => $dbPath, '--pid' => $pidPath]);
        $result = $cmd->handle(
            $this->makeSettings(),
            $this->makeTracer(),
        );

        $this->assertSame(Command::FAILURE, $result);
    }

    public function test_daemon_start_rejects_inprocess_mode(): void
    {
        putenv('HAO_CODE_CRON_MODE=inprocess');

        $cmd = $this->buildCommand(['action' => 'start', '--db' => $this->tmpDir.'/jobs.sqlite', '--pid' => $this->tmpDir.'/daemon.pid']);
        $result = $cmd->handle($this->makeSettings(), $this->makeTracer());

        $this->assertSame(Command::FAILURE, $result);
    }

    public function test_daemon_status_when_not_running(): void
    {
        $pidPath = $this->tmpDir.'/daemon.pid';
        $cmd = $this->buildCommand(['action' => 'status', '--pid' => $pidPath]);
        $result = $cmd->handle($this->makeSettings(), $this->makeTracer());

        $this->assertSame(Command::SUCCESS, $result);
    }

    public function test_daemon_stop_when_not_running(): void
    {
        $pidPath = $this->tmpDir.'/daemon.pid';
        $cmd = $this->buildCommand(['action' => 'stop', '--pid' => $pidPath]);
        $result = $cmd->handle($this->makeSettings(), $this->makeTracer());

        $this->assertSame(Command::SUCCESS, $result);
    }

    public function test_invalid_action_returns_invalid(): void
    {
        $cmd = $this->buildCommand(['action' => 'restart']);
        $result = $cmd->handle($this->makeSettings(), $this->makeTracer());

        $this->assertSame(Command::INVALID, $result);
    }

    public function test_job_store_rejects_invalid_cron(): void
    {
        $store = new JobStore($this->tmpDir.'/jobs.sqlite');
        $this->expectException(\InvalidArgumentException::class);
        $store->addJob('j1', 'bad cron', 'echo x');
    }

    public function test_legacy_importer_migrates_jobs(): void
    {
        $legacyPath = $this->tmpDir.'/scheduled_tasks.json';
        $jobs = [
            'cron_abc123' => [
                'id' => 'cron_abc123',
                'cron' => '* * * * *',
                'prompt' => 'echo hello',
                'status' => 'active',
                'durable' => true,
                'recurring' => true,
                'created_at' => date('c'),
            ],
        ];
        file_put_contents($legacyPath, json_encode($jobs));

        $store = new JobStore($this->tmpDir.'/jobs.sqlite');
        $importer = new LegacyImporter($store, $legacyPath);
        $count = $importer->import();

        $this->assertSame(1, $count);
        $this->assertFileExists($legacyPath.'.bak');
        $this->assertFileDoesNotExist($legacyPath);
        $this->assertNotNull($store->getJob('cron_abc123'));
    }

    public function test_legacy_importer_skips_missing_file(): void
    {
        $store = new JobStore($this->tmpDir.'/jobs.sqlite');
        $importer = new LegacyImporter($store, $this->tmpDir.'/nonexistent.json');
        $this->assertSame(0, $importer->import());
    }

    private function buildCommand(array $args): DaemonCommand
    {
        $cmd = new DaemonCommand;

        $definition = $cmd->getDefinition();
        $input = new ArrayInput($args, $definition);
        $output = new BufferedOutput;

        $cmd->setInput($input);
        $cmd->setOutput(new OutputStyle($input, $output));

        return $cmd;
    }

    private function makeSettings(): SettingsManager
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getTelemetryConfig')->willReturn([]);

        return $settings;
    }

    private function makeTracer(): PhoenixTracer
    {
        return PhoenixTracer::fromSettings($this->makeSettings());
    }
}
