<?php

namespace HaoCode\Sdk\Sandbox;

use HaoCode\Sdk\Sandbox\Backends\LocalSandboxBackend;
use HaoCode\Sdk\Sandbox\Backends\AgentRunSandboxBackend;
use HaoCode\Sdk\Sandbox\Backends\NativeSandboxBackend;

/** @internal */
final class SandboxManager
{
    public static function create(SandboxConfig $config, ?string $localCwd = null): SandboxRuntime
    {
        $backend = match ($config->provider) {
            'local' => new LocalSandboxBackend($config),
            'native' => new NativeSandboxBackend($config),
            'agentrun' => new AgentRunSandboxBackend($config),
            default => throw new \InvalidArgumentException("Unsupported sandbox provider: {$config->provider}"),
        };

        if ($config->sync === 'upload-cwd') {
            if ($localCwd === null || $localCwd === '') {
                throw new \InvalidArgumentException('sandbox sync upload-cwd requires HaoCodeConfig::cwd.');
            }
            self::syncDirectory($backend, $localCwd, $config->remoteCwd, $config->exclude);
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
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $localPath = $file->getPathname();
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
