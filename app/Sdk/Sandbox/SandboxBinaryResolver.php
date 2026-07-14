<?php

namespace HaoCode\Sdk\Sandbox;

/** @internal */
final class SandboxBinaryResolver
{
    public static function resolve(?string $explicit = null): string
    {
        $override = $explicit ?: (getenv('HAOCODE_SANDBOX_BINARY') ?: null);
        if ($override !== null) {
            return self::assertUsable($override);
        }

        $root = dirname(__DIR__, 3);
        $path = $root.'/bin/'.SandboxBinaryInstaller::platformBinaryName();
        if (is_file($path)) {
            return self::assertPackagedChecksum(self::assertUsable($path), $root.'/bin/SHA256SUMS');
        }

        try {
            return SandboxBinaryInstaller::cachedBinary();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Tokimo sandbox runner is not installed. Run '
                .'`vendor/bin/hao-code-sandbox install` (or add `--with-runtime` for guest images). '
                .$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private static function assertUsable(string $path): string
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Tokimo sandbox binary does not exist: {$path}");
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($path) && ! str_ends_with(strtolower($path), '.php')) {
            throw new \RuntimeException("Tokimo sandbox binary is not executable: {$path}");
        }

        return $path;
    }

    private static function assertPackagedChecksum(string $path, string $manifest): string
    {
        $contents = is_file($manifest) ? file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($contents === false) {
            throw new \RuntimeException("Tokimo sandbox checksum manifest does not exist: {$manifest}");
        }

        $name = basename($path);
        foreach ($contents as $line) {
            if (preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $matches) !== 1 || $matches[2] !== $name) {
                continue;
            }
            $actual = hash_file('sha256', $path);
            if (! is_string($actual) || ! hash_equals($matches[1], $actual)) {
                throw new \RuntimeException("Tokimo sandbox binary checksum verification failed: {$path}");
            }
            return $path;
        }

        throw new \RuntimeException("Tokimo sandbox checksum is missing for {$name}.");
    }
}
