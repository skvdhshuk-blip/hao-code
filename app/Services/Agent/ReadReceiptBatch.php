<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Tools\ToolUseContext;

/**
 * Owns the visibility boundary for read receipts produced by a tool batch.
 *
 * Receipts become durable only after the corresponding tool results have been
 * added to model-visible history. Nested callers reuse the outer batch so a
 * child executor cannot promote a receipt too early.
 *
 * @internal
 */
final class ReadReceiptBatch
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function execute(ToolUseContext $context, callable $work): mixed
    {
        $ownsBatch = ! $context->hasReadReceiptBatch();
        if ($ownsBatch) {
            $context->beginReadReceiptBatch();
        }

        try {
            $result = $work();
            if ($ownsBatch) {
                $context->commitReadReceiptBatch();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsBatch) {
                $context->discardReadReceiptBatch();
            }

            throw $e;
        }
    }
}
