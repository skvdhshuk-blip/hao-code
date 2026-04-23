<?php

namespace HaoCode\Services\Cron\Daemon;

use HaoCode\Services\Security\SecretScanner;
use HaoCode\Services\Telemetry\PhoenixTracer;
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
    ) {}

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
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(['sh', '-c', $job['command']], $descriptors, $pipes, null, $env);

        if (! is_resource($process)) {
            $endedAt = time();
            $this->endSpan($span, -1, $endedAt - $startedAt, false);

            return [
                'exit_code' => -1,
                'stderr_tail' => 'proc_open failed',
                'secret_detected' => false,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stderrBuffer = '';
        $exitCode = -1;
        $deadline = time() + self::GRACEFUL_TIMEOUT;
        $killed = false;

        while (true) {
            $status = proc_get_status($process);

            // Drain stderr
            $chunk = fread($pipes[2], 8192);
            if ($chunk !== false && $chunk !== '') {
                $stderrBuffer .= $chunk;
            }

            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (time() > $deadline) {
                // Graceful kill attempt
                proc_terminate($process, SIGTERM);
                usleep(500000); // 0.5s grace

                $status = proc_get_status($process);
                if ($status['running']) {
                    // Hard kill
                    proc_terminate($process, SIGKILL);
                }
                $killed = true;
                $exitCode = -1;
                break;
            }

            usleep(100000); // 100ms poll
        }

        // Final drain
        while (($chunk = fread($pipes[2], 8192)) !== false && $chunk !== '') {
            $stderrBuffer .= $chunk;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $endedAt = time();

        // Trim stderr to tail limit
        if (strlen($stderrBuffer) > self::STDERR_TAIL_BYTES) {
            $stderrBuffer = substr($stderrBuffer, -self::STDERR_TAIL_BYTES);
        }

        // Secret scan stderr before persisting
        $secretDetected = $this->secretScanner->containsSecrets($stderrBuffer);
        $maskedStderr = $secretDetected ? $this->secretScanner->redact($stderrBuffer) : $stderrBuffer;

        $this->endSpan($span, $killed ? -1 : $exitCode, $endedAt - $startedAt, $secretDetected);

        return [
            'exit_code' => $exitCode,
            'stderr_tail' => $maskedStderr,
            'secret_detected' => $secretDetected,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ];
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
