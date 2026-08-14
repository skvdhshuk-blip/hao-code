<?php

namespace HaoCode\Services\ToolResult;

use HaoCode\Support\Filesystem\CanonicalPathResolver;
use HaoCode\Support\Runtime\SdkRuntime;

trait ToolResultStorageIsRestorableReplacementConcern
{

    private function isRestorableReplacement(string $toolUseId, string $message): bool
    {
        if (! $this->ensureStorageDirectory()
            || preg_match('/Full output saved to: ([^\r\n]+)$/m', $message, $matches) !== 1
        ) {
            return false;
        }

        $storedPath = realpath(trim($matches[1]));
        try {
            $expectedPath = realpath($this->safeStoragePath($toolUseId));
        } catch (\RuntimeException) {
            return false;
        }
        $realDirectory = realpath($this->storageDir);

        if ($storedPath === false || $expectedPath === false || $realDirectory === false
            || ! is_file($storedPath) || $storedPath !== $expectedPath
        ) {
            return false;
        }

        $prefix = rtrim($realDirectory, '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($storedPath, $prefix);
    }
}
