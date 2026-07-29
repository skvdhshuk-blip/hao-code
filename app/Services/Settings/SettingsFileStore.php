<?php

declare(strict_types=1);

namespace HaoCode\Services\Settings;

/**
 * Shared, process-safe storage contract for HaoCode settings files.
 *
 * @internal
 */
final class SettingsFileStore
{
    public function __construct(
        private readonly ?string $workingDirectory = null,
    ) {}

    /**
     * @return array{global: string, project: string}
     */
    public function paths(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $projectDirectory = $this->workingDirectory ?? (getcwd() ?: '/');

        return [
            'global' => \HaoCode\Support\Runtime\SdkRuntime::config('haocode.global_settings_path')
                ?: $home.'/.haocode/settings.json',
            'project' => rtrim($projectDirectory, '/').'/.haocode/settings.json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $path): array
    {
        // A writer creates the sidecar before taking LOCK_EX. If that lock
        // exists, readers must participate even while the settings file itself
        // has not been published yet.
        if (! file_exists($path) && ! file_exists($path.'.lock')) {
            return [];
        }

        try {
            $handle = $this->openLock($path, createDirectory: false);
        } catch (\RuntimeException $e) {
            if (is_file($path)
                && ! file_exists($path.'.lock')
                && ! is_writable(dirname($path))) {
                return $this->readReadOnlyFile($path);
            }

            throw $e;
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Failed to lock settings file for reading: {$path}");
            }

            return $this->readUnlocked($path);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Atomically apply a read-modify-write transaction.
     *
     * @template T
     * @param callable(array<string, mixed>&): T $modifier
     * @return T
     */
    public function update(string $path, callable $modifier): mixed
    {
        $handle = $this->openLock($path, createDirectory: true);
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Failed to lock settings file for writing: {$path}");
            }

            $settings = $this->readUnlocked($path);
            $original = $settings;
            $result = $modifier($settings);
            if ($settings !== $original) {
                $this->writeUnlocked($path, $settings);
            }

            return $result;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readUnlocked(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read settings file: {$path}");
        }
        if (trim($contents) === '') {
            return [];
        }

        return $this->decode($path, $contents);
    }

    /**
     * Read an immutable settings file without creating a sidecar. The file's
     * own shared lock still coordinates with any external in-place writer.
     *
     * @return array<string, mixed>
     */
    private function readReadOnlyFile(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Failed to read settings file: {$path}");
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new \RuntimeException("Failed to lock settings file for reading: {$path}");
            }
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new \RuntimeException("Failed to read settings file: {$path}");
            }

            return trim($contents) === '' ? [] : $this->decode($path, $contents);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path, string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "Invalid JSON in settings file {$path}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException("Settings file must contain a JSON object: {$path}");
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeUnlocked(string $path, array $settings): void
    {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.settings-');
        if ($temporary === false) {
            throw new \RuntimeException("Failed to create temporary settings file in: {$directory}");
        }

        $stream = null;
        try {
            $json = json_encode(
                $settings,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";
            $stream = fopen($temporary, 'wb');
            if ($stream === false) {
                throw new \RuntimeException("Failed to open temporary settings file: {$temporary}");
            }

            $written = fwrite($stream, $json);
            if ($written === false || $written !== strlen($json) || ! fflush($stream)) {
                throw new \RuntimeException("Failed to write temporary settings file: {$temporary}");
            }
            if (function_exists('fsync') && ! fsync($stream)) {
                throw new \RuntimeException("Failed to sync temporary settings file: {$temporary}");
            }
            fclose($stream);
            $stream = null;

            if (! @chmod($temporary, 0600)) {
                throw new \RuntimeException("Failed to secure temporary settings file: {$temporary}");
            }
            if (! @rename($temporary, $path)) {
                throw new \RuntimeException("Failed to publish settings file atomically: {$path}");
            }
            @chmod($path, 0600);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                "Failed to encode settings file {$path}: {$e->getMessage()}",
                previous: $e,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return resource
     */
    private function openLock(string $path, bool $createDirectory)
    {
        $directory = dirname($path);
        if ($createDirectory
            && ! is_dir($directory)
            && ! @mkdir($directory, 0700, true)
            && ! is_dir($directory)) {
            throw new \RuntimeException("Failed to create settings directory: {$directory}");
        }

        $handle = @fopen($path.'.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open settings lock file: {$path}.lock");
        }
        @chmod($path.'.lock', 0600);

        return $handle;
    }
}
