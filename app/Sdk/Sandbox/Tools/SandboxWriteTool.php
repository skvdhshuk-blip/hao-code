<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Services\Security\SecretScanner;
use HaoCode\Sdk\Sandbox\RevisionAwareSandboxBackendInterface;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

final class SandboxWriteTool extends SandboxTool
{
    public function name(): string { return 'Write'; }

    public function description(): string
    {
        return 'Writes a file inside the configured HaoCode sandbox filesystem. It never writes to the host project cwd.';
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'file_path' => ['type' => 'string', 'description' => 'Sandbox file path to write.'],
                'content' => ['type' => 'string', 'description' => 'Content to write.'],
            ],
            'required' => ['file_path', 'content'],
        ], [
            'file_path' => 'required|string',
            'content' => 'required|string',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $path = $input['file_path'];
        $content = (string) $input['content'];
        $stat = $this->runtime->backend->stat($path);
        $exists = (bool) ($stat['exists'] ?? false);
        $expectedSha256 = null;
        if ($exists) {
            $revision = $context->getFileRevision($path);
            if ($revision === null) {
                return ToolResult::error("Read tool first: {$path} already exists in sandbox and must be read before overwriting.");
            }
            if (! $revision->complete) {
                return ToolResult::error("Read the complete sandbox file first: {$path} was only partially read.");
            }
            $expectedSha256 = $revision->sha256;
        }

        try {
            $backend = $this->runtime->backend;
            if ($backend instanceof RevisionAwareSandboxBackendInterface) {
                $backend->writeFileIfUnchanged($path, $content, $expectedSha256);
            } else {
                $this->assertRemoteRevisionUnchanged($path, $exists, $expectedSha256);
                $backend->writeFile($path, $content);
            }
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }

        $context->recordVirtualFileRead($path, $content, 1, null, false);
        $lines = substr_count($content, "\n") + ($content !== '' ? 1 : 0);
        $bytes = strlen($content);
        $output = 'Successfully '.($exists ? 'updated' : 'created')." sandbox file {$path} ({$lines} lines, {$bytes} bytes)";
        $secrets = (new SecretScanner())->scan($content);
        if ($secrets !== []) {
            $types = array_unique(array_map(fn (array $s): string => (string) $s['type'], $secrets));
            $output .= "\n\nWARNING: Potential secrets detected: ".implode(', ', $types).'.';
        }

        return ToolResult::success($output);
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        $path = trim((string) ($input['file_path'] ?? ''));
        if ($path === '') return 'file_path must not be empty.';
        if ($this->isBareLineReference($path)) return 'file_path must include an actual path, not only a line reference like ":12".';
        return null;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        if (isset($input['file_path'])) {
            $input['file_path'] = $this->resolveRemotePath((string) $input['file_path'], $context);
        }
        return $input;
    }

    public function isReadOnly(array $input): bool { return false; }
    public function getActivityDescription(array $input): ?string { return 'Writing sandbox '.basename($input['file_path'] ?? 'file'); }

    private function assertRemoteRevisionUnchanged(
        string $path,
        bool $expectedToExist,
        ?string $expectedSha256,
    ): void {
        $backend = $this->runtime->backend;
        if (! $expectedToExist) {
            if (($backend->stat($path)['exists'] ?? false) === true) {
                throw new \RuntimeException(
                    "Sandbox file was created concurrently: {$path}. Read it before overwriting.",
                );
            }

            return;
        }

        try {
            $current = $backend->readFile($path);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                "Sandbox file changed since it was read: {$path}. Read it again before writing.",
                0,
                $exception,
            );
        }

        if ($expectedSha256 === null
            || ! hash_equals($expectedSha256, hash('sha256', $current))) {
            throw new \RuntimeException(
                "Sandbox file changed since it was read: {$path}. Read it again before writing.",
            );
        }
    }
}
