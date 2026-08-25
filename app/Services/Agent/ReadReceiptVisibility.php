<?php

declare(strict_types=1);

namespace HaoCode\Services\Agent;

use HaoCode\Tools\ToolUseContext;

/** Revokes write receipts when the model only saw a compacted Read preview. @internal */
final class ReadReceiptVisibility
{
    public static function invalidate(array $toolCalls, array $before, array $after, ToolUseContext $context): void
    {
        $paths = [];
        foreach ($toolCalls as $call) {
            $path = $call->input['file_path'] ?? null;
            if ($call->name === 'Read' && is_string($path) && $path !== '') {
                $paths[$call->id] = $path;
            }
        }
        if ($paths === []) {
            return;
        }
        $visible = [];
        foreach ($after as $result) {
            if (is_string($result['tool_use_id'] ?? null)
                && is_string($result['content'] ?? null)) {
                $visible[$result['tool_use_id']] = $result['content'];
            }
        }
        foreach ($before as $result) {
            $id = $result['tool_use_id'] ?? null;
            $content = $result['content'] ?? null;
            if (is_string($id) && is_string($content)
                && isset($paths[$id], $visible[$id])
                && ! hash_equals($content, $visible[$id])) {
                $context->markFileReadIncomplete($paths[$id]);
            }
        }
    }
}
