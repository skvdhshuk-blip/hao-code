<?php

declare(strict_types=1);

namespace HaoCode\Sdk\Sandbox;

/** @internal */
final class SandboxToolPolicy
{
    /** @var list<string> */
    private const HOST_ONLY_TOOL_NAMES = [
        'Edit',
        'apply_patch',
        'NotebookEdit',
        'Lsp',
        'EnterWorktree',
        'ExitWorktree',
        'Agent',
        'SendMessage',
    ];

    /** @return list<string> */
    public static function hostOnlyToolNames(): array
    {
        return self::HOST_ONLY_TOOL_NAMES;
    }

    public static function isHostOnly(string $toolName): bool
    {
        return in_array($toolName, self::HOST_ONLY_TOOL_NAMES, true);
    }
}
