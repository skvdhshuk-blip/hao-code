<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Agent\ReadReceiptBatch;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

final class ReadReceiptBatchTest extends TestCase
{
    public function test_owned_batch_promotes_receipts_after_work_returns(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'haocode-read-receipt-batch-');
        file_put_contents($file, 'content');
        $context = new ToolUseContext(dirname($file), 'receipt-batch');

        try {
            $result = ReadReceiptBatch::execute($context, function () use ($context, $file): string {
                $context->recordFileRead($file, 'content');

                return 'done';
            });

            $this->assertSame('done', $result);
            $this->assertTrue($context->wasFileRead($file));
            $this->assertFalse($context->hasReadReceiptBatch());
        } finally {
            @unlink($file);
        }
    }

    public function test_owned_batch_discards_receipts_when_work_fails(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'haocode-read-receipt-batch-');
        file_put_contents($file, 'content');
        $context = new ToolUseContext(dirname($file), 'receipt-batch-failure');

        try {
            try {
                ReadReceiptBatch::execute($context, function () use ($context, $file): never {
                    $context->recordFileRead($file, 'content');
                    throw new \RuntimeException('work failed');
                });
            } catch (\RuntimeException $e) {
                $this->assertSame('work failed', $e->getMessage());
            }

            $this->assertFalse($context->wasFileRead($file));
            $this->assertFalse($context->hasReadReceiptBatch());
        } finally {
            @unlink($file);
        }
    }

    public function test_nested_batch_does_not_promote_before_outer_batch(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'haocode-read-receipt-batch-');
        file_put_contents($file, 'content');
        $context = new ToolUseContext(dirname($file), 'receipt-batch-nested');

        try {
            ReadReceiptBatch::execute($context, function () use ($context, $file): void {
                ReadReceiptBatch::execute($context, function () use ($context, $file): void {
                    $context->recordFileRead($file, 'content');
                });

                $this->assertTrue($context->hasReadReceiptBatch());
                $this->assertFalse($context->wasFileRead($file));
                $this->assertNotEmpty($context->getPendingReadFileStateSnapshot());
            });

            $this->assertFalse($context->hasReadReceiptBatch());
            $this->assertTrue($context->wasFileRead($file));
        } finally {
            @unlink($file);
        }
    }
}
