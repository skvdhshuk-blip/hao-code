<?php

namespace HaoCode\Services\Cron\Daemon;

use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Telemetry\PhoenixTracer;
use HaoCode\Support\Runtime\ProcessSupervisor;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Executes a cron job via proc_open with:
 * - Environment whitelist (strips sensitive vars)
 * - W3C traceparent injection for OTEL span chaining
 * - SIGTERM graceful shutdown with SIGKILL fallback after timeout
 * - stdout/stderr secret masking before persistence
 */
class JobExecutor
{
    private const GRACEFUL_TIMEOUT = 30;

    private const STDERR_TAIL_BYTES = 4096;

    private int $timeoutSeconds;

    /** Whitelisted env var prefixes/names passed to child process */
    private const ENV_ALLOWLIST = [
        'PATH',
        'HOME',
        'USER',
        'LANG',
        'LC_ALL',
        'LC_CTYPE',
        'TMPDIR',
        'TZ',
    ];

    private const ENV_ALLOWLIST_PREFIXES = [
        'HAO_CODE_',
        'OTEL_',
    ];

    /** W3C trace propagation keys always passed through */
    private const TRACE_KEYS = ['traceparent', 'tracestate'];

    public function __construct(
        private readonly PhoenixTracer $tracer,
        private readonly SecretScanner $secretScanner,
        ?int $timeoutSeconds = null,
    ) {
        $this->timeoutSeconds = max(1, $timeoutSeconds ?? self::GRACEFUL_TIMEOUT);
    }

    /**
     * @return array{exit_code: int, stderr_tail: string, secret_detected: bool, started_at: int, ended_at: int}
     */
    public function execute(array $job, ?string $traceparent = null): array
    {
        $startedAt = time();

        $span = $this->tracer->startSpan('cron.job.execute', PhoenixTracer::KIND_TOOL, [
            'job_id' => $job['id'],
            // OTEL red line: never log raw command
            'cron' => $job['cron'],
        ]);

        $env = $this->buildEnv($traceparent);
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $opened = ProcessSupervisor::open(
                (string) $job['command'],
                getcwd() ?: sys_get_temp_dir(),
                $env,
                $descriptors,
            );
        } catch (\RuntimeException $e) {
            $endedAt = time();
            $this->endSpan($span, -1, $endedAt - $startedAt, false);

            return [
                'exit_code' => -1,
                'stderr_tail' => $e->getMessage(),
                'secret_detected' => false,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
            ];
        }

        $process = $opened['process'];
        $pid = $opened['pid'];
        $pipes = $opened['pipes'];

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderrBuffer = '';
        $exitCode = -1;
        $deadline = microtime(true) + $this->timeoutSeconds;
        $timedOut = false;

        while (true) {
            $this->drainPipes($pipes, $stderrBuffer);

            $status = proc_get_status($process);

            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                ProcessSupervisor::terminateTree($pid);
                $exitCode = -1;
                break;
            }

            $read = [];
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index]) && ! feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }

            if ($read === []) {
                usleep(10_000);
                continue;
            }

            $remainingUs = max(1, (int) (($deadline - microtime(true)) * 1_000_000));
            $remainingUs = min($remainingUs, 100_000);
            $seconds = intdiv($remainingUs, 1_000_000);
            $microseconds = $remainingUs % 1_000_000;
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, $seconds, $microseconds);
        }

        $this->drainPipes($pipes, $stderrBuffer);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($exitCode < 0 && ! $timedOut) {
            $exitCode = $closed;
        }

        $endedAt = time();

        // Trim stderr to tail limit
        if (strlen($stderrBuffer) > self::STDERR_TAIL_BYTES) {
            $stderrBuffer = substr($stderrBuffer, -self::STDERR_TAIL_BYTES);
        }

        // Secret scan stderr before persisting
        $secretDetected = $this->secretScanner->containsSecrets($stderrBuffer);
        $maskedStderr = $secretDetected ? $this->secretScanner->redact($stderrBuffer) : $stderrBuffer;

        $this->endSpan($span, $timedOut ? -1 : $exitCode, $endedAt - $startedAt, $secretDetected);

        return [
            'exit_code' => $exitCode,
            'stderr_tail' => $maskedStderr,
            'secret_detected' => $secretDetected,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ];
    }

    /**
     * Drain both output pipes so a cron job cannot block on stdout while we only
     * persist stderr. Stdout is intentionally discarded; stderr is kept as a
     * bounded tail for diagnostics and secret scanning.
     *
     * @param array<int, resource> $pipes
     */
    private function drainPipes(array $pipes, string &$stderrBuffer): void
    {
        foreach ([1, 2] as $index) {
            if (! isset($pipes[$index]) || ! is_resource($pipes[$index])) {
                continue;
            }

            while (($chunk = fread($pipes[$index], 8192)) !== false && $chunk !== '') {
                if ($index !== 2) {
                    continue;
                }

                $stderrBuffer .= $chunk;
                if (strlen($stderrBuffer) > self::STDERR_TAIL_BYTES) {
                    $stderrBuffer = substr($stderrBuffer, -self::STDERR_TAIL_BYTES);
                }
            }
        }
    }

    private function buildEnv(?string $traceparent): array
    {
        $env = [];

        foreach ($_ENV as $key => $value) {
            if ($this->isAllowed($key)) {
                $env[$key] = $value;
            }
        }

        // Also pull from getenv for CLI contexts
        foreach (array_keys(array_merge($_ENV, $_SERVER)) as $key) {
            if (! isset($env[$key]) && $this->isAllowed($key)) {
                $val = getenv($key);
                if ($val !== false) {
                    $env[$key] = $val;
                }
            }
        }

        // Inject W3C traceparent for span chaining
        if ($traceparent !== null) {
            $env['traceparent'] = $traceparent;
        }

        return $env;
    }

    private function isAllowed(string $key): bool
    {
        if (in_array($key, self::ENV_ALLOWLIST, true)) {
            return true;
        }
        if (in_array(strtolower($key), self::TRACE_KEYS, true)) {
            return true;
        }
        foreach (self::ENV_ALLOWLIST_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function endSpan(?SpanInterface $span, int $exitCode, int $durationMs, bool $secretDetected): void
    {
        if ($span === null) {
            return;
        }
        $span->setAttribute('exit_code', $exitCode);
        $span->setAttribute('duration_ms', $durationMs * 1000);
        $span->setAttribute('secret_detected', $secretDetected ? 'true' : 'false');

        if ($exitCode !== 0) {
            $span->setStatus(StatusCode::STATUS_ERROR, 'exit_code='.$exitCode);
        }
        $span->end();
    }
}
