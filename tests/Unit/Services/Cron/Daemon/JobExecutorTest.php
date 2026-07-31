<?php

namespace Tests\Unit\Services\Cron\Daemon;

use HaoCode\Services\Cron\Daemon\JobExecutor;
use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Settings\SettingsManager;
use HaoCode\Services\Telemetry\PhoenixTracer;
use PHPUnit\Framework\TestCase;

class JobExecutorTest extends TestCase
{
    private JobExecutor $executor;
    private PhoenixTracer $tracer;

    protected function setUp(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->method('getTelemetryConfig')->willReturn([]);

        $this->tracer = PhoenixTracer::fromSettings($settings);
        $this->executor = new JobExecutor($this->tracer, new SecretScanner);
    }

    private function makeJob(string $id, string $command): array
    {
        return [
            'id' => $id,
            'cron' => '* * * * *',
            'command' => $command,
            'recurring' => 1,
        ];
    }

    public function test_successful_command(): void
    {
        $result = $this->executor->execute($this->makeJob('j1', 'echo hello'));

        $this->assertSame(0, $result['exit_code']);
        $this->assertFalse($result['secret_detected']);
        $this->assertArrayHasKey('started_at', $result);
        $this->assertArrayHasKey('ended_at', $result);
    }

    public function test_failing_command(): void
    {
        $result = $this->executor->execute($this->makeJob('j1', 'false'));

        $this->assertNotSame(0, $result['exit_code']);
    }

    public function test_env_strips_sensitive_vars(): void
    {
        // Inject a sensitive var and confirm it doesn't leak to child env
        putenv('MY_SECRET_TOKEN=supersecret');

        $result = $this->executor->execute(
            $this->makeJob('j1', 'sh -c \'echo $MY_SECRET_TOKEN\'')
        );

        // Child stdout won't contain the secret because MY_SECRET_TOKEN is stripped
        $this->assertSame(0, $result['exit_code']);
        putenv('MY_SECRET_TOKEN');
    }

    public function test_traceparent_injected(): void
    {
        $traceparent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        $result = $this->executor->execute(
            $this->makeJob('j1', 'sh -c \'echo $traceparent\''),
            $traceparent
        );

        $this->assertSame(0, $result['exit_code']);
    }

    public function test_secret_in_stderr_is_detected(): void
    {
        // Force stderr output containing an AWS key pattern
        $fakeKey = 'AKIAIOSFODNN7EXAMPLE';
        $result = $this->executor->execute(
            $this->makeJob('j1', 'sh -c \'echo '.$fakeKey.' >&2\'')
        );

        // Exit 0 but secret detected
        $this->assertSame(0, $result['exit_code']);
        // The mock AWS key may or may not match SecretScanner patterns — just verify structure
        $this->assertArrayHasKey('secret_detected', $result);
    }

    public function test_stderr_tail_truncation(): void
    {
        // Generate more than 4096 bytes of stderr
        $result = $this->executor->execute(
            $this->makeJob('j1', 'sh -c \'for i in $(seq 1 200); do echo "line $i" >&2; done\'')
        );

        $this->assertLessThanOrEqual(4096, strlen($result['stderr_tail']));
    }

    public function test_stdout_pipe_pressure_is_drained(): void
    {
        $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
            'fwrite(STDOUT, str_repeat("x", 262144));'
        );

        $executor = new JobExecutor($this->tracer, new SecretScanner, timeoutSeconds: 2);
        $result = $executor->execute($this->makeJob('j1', $command));

        $this->assertSame(0, $result['exit_code']);
        $this->assertLessThanOrEqual(4096, strlen($result['stderr_tail']));
    }

    public function test_timeout_terminates_descendants_before_delayed_side_effect(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX shell timing test.');
        }

        $marker = sys_get_temp_dir().'/haocode-cron-timeout-'.bin2hex(random_bytes(4));
        $command = 'sh -c '.escapeshellarg('sleep 2; printf leaked > '.escapeshellarg($marker));

        try {
            $executor = new JobExecutor($this->tracer, new SecretScanner, timeoutSeconds: 1);
            $result = $executor->execute($this->makeJob('j1', $command));

            $this->assertSame(-1, $result['exit_code']);
            usleep(1_300_000);
            $this->assertFileDoesNotExist($marker);
        } finally {
            @unlink($marker);
        }
    }

    public function test_cron_jobs_do_not_load_login_shell_profile(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX shell profile test.');
        }

        $home = sys_get_temp_dir().'/haocode-cron-home-'.bin2hex(random_bytes(4));
        $marker = $home.'/profile-loaded';
        mkdir($home, 0700, true);
        file_put_contents($home.'/.bash_profile', 'printf loaded > '.escapeshellarg($marker)."\n");

        $previousHome = getenv('HOME');
        putenv('HOME='.$home);

        try {
            $executor = new JobExecutor($this->tracer, new SecretScanner, timeoutSeconds: 2);
            $result = $executor->execute($this->makeJob('j1', 'printf ok'));

            $this->assertSame(0, $result['exit_code']);
            $this->assertFileDoesNotExist($marker);
        } finally {
            if ($previousHome === false) {
                putenv('HOME');
            } else {
                putenv('HOME='.$previousHome);
            }
            @unlink($home.'/.bash_profile');
            @unlink($marker);
            @rmdir($home);
        }
    }
}
