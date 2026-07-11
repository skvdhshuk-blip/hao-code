<?php

namespace HaoCode\Services\Agent;

/**
 * 可跨 fork 子进程观察的运行取消令牌。
 *
 * @internal
 */
final class CancellationToken
{
    private bool $cancelled = false;

    private string $signalPath;

    public function __construct()
    {
        $this->signalPath = $this->newSignalPath();
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        @file_put_contents($this->signalPath, 'cancelled');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled || file_exists($this->signalPath);
    }

    public function reset(): void
    {
        @unlink($this->signalPath);
        $this->cancelled = false;
        $this->signalPath = $this->newSignalPath();
    }

    public function close(): void
    {
        @unlink($this->signalPath);
    }

    private function newSignalPath(): string
    {
        return sys_get_temp_dir().'/haocode_cancel_'.getmypid().'_'.bin2hex(random_bytes(8));
    }
}
