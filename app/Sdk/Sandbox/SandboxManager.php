<?php

namespace HaoCode\Sdk\Sandbox;

use HaoCode\Sdk\Sandbox\Backends\LocalSandboxBackend;
use HaoCode\Sdk\Sandbox\Backends\AgentRunSandboxBackend;
use HaoCode\Sdk\Sandbox\Backends\NativeSandboxBackend;
use HaoCode\Sdk\Sandbox\Backends\TokimoSandboxBackend;

/** @internal */
final class SandboxManager
{
    public static function create(SandboxConfig $config, ?string $localCwd = null): SandboxRuntime
    {
        $backend = match ($config->provider) {
            'local' => new LocalSandboxBackend($config),
            'native' => new NativeSandboxBackend($config),
            'tokimo' => new TokimoSandboxBackend($config),
            'agentrun' => new AgentRunSandboxBackend($config),
            default => throw new \InvalidArgumentException("Unsupported sandbox provider: {$config->provider}"),
        };

        try {
            if ($config->sync === 'upload-cwd') {
                if ($localCwd === null || $localCwd === '') {
                    throw new \InvalidArgumentException('sandbox sync upload-cwd requires HaoCodeConfig::cwd.');
                }
                self::syncDirectory($backend, $localCwd, $config->remoteCwd, $config->exclude);
            }
        } catch (\Throwable $exception) {
            try {
                $backend->close();
            } catch (\Throwable) {
                // Preserve the configuration or sync error that prevented creation.
            }
            throw $exception;
        }

        return new SandboxRuntime($config, $backend);
    }

    /** @param string[] $exclude */
    private static function syncDirectory(SandboxBackendInterface $backend, string $localDir, string $remoteDir, array $exclude): void
    {
        $root = realpath($localDir);
        if ($root === false || ! is_dir($root)) {
            throw new \InvalidArgumentException("Local cwd does not exist: {$localDir}");
        }

        $exclude = array_merge([
            '.git', '.svn', '.hg', 'node_modules', 'vendor', '.idea', '.vscode',
            '.DS_Store', '__pycache__', 'storage', 'var/cache', '.playwright-cli',
        ], $exclude);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            // Reject symlinks outright. PHP's SplFileInfo reports a symlink to
            // a regular file as isFile()=true AND isLink()=true, so the old
            // isFile() check alone happily read the link target's contents.
            // A malicious repo could ship project/leak.txt -> ~/.ssh/id_rsa
            // and have the key copied into the sandbox before any sandbox-side
            // policy applied. (chatgpt 3rd review #4)
            if (! $file instanceof \SplFileInfo || $file->isLink() || ! $file->isFile()) {
                continue;
            }

            $localPath = $file->getPathname();

            // Defense-in-depth: resolve the canonical path and confirm it sits
            // inside the project root. This guards against future iterator
            // flag changes (e.g. FOLLOW_SYMLINKS) or link farms created after
            // the iterator snapshot.
            $resolved = realpath($localPath);
            if ($resolved === false || ! str_starts_with($resolved . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $localPath), DIRECTORY_SEPARATOR);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if (self::isExcluded($relative, $exclude) || $file->getSize() > 1024 * 1024) {
                continue;
            }

            $content = file_get_contents($localPath);
            if ($content === false || str_contains($content, "\0")) {
                continue;
            }

            $backend->writeFile(rtrim($remoteDir, '/').'/'.$relative, $content);
        }
    }

    /** @param string[] $patterns */
    private static function isExcluded(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($relativePath === $pattern || str_starts_with($relativePath, rtrim($pattern, '/').'/')) {
                return true;
            }
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($relativePath))) {
                return true;
            }
        }

        return false;
    }
}
