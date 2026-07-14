<?php

namespace HaoCode\Sdk\Sandbox;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** @internal */
final class SandboxBinaryInstaller
{
    private const RELEASE_BASE = 'https://github.com/skvdhshuk-blip/hao-code/releases/download';

    /** @return string[] */
    public static function install(
        ?string $releaseTag = null,
        ?string $releaseBase = null,
        bool $force = false,
        ?HttpClientInterface $client = null,
    ): array {
        $tag = self::releaseTag($releaseTag);
        $base = rtrim($releaseBase ?? (getenv('HAOCODE_SANDBOX_RELEASE_BASE') ?: self::RELEASE_BASE), '/');
        $url = $base.'/'.rawurlencode($tag);
        $directory = self::cacheDirectory($tag);
        self::ensureDirectory($directory);

        $client ??= HttpClient::create(['timeout' => 60, 'max_duration' => 600]);
        $installed = [];
        foreach (self::platformAssetNames() as $name) {
            $installed[] = self::downloadAsset($client, $url, $directory, $name, $force);
        }

        return $installed;
    }

    public static function releaseTag(?string $explicit = null): string
    {
        $tag = $explicit ?: (getenv('HAOCODE_SANDBOX_RELEASE_TAG') ?: null);
        if ($tag !== null) {
            return self::normalizeReleaseTag($tag);
        }

        if (class_exists(\Composer\InstalledVersions::class)) {
            $version = \Composer\InstalledVersions::getPrettyVersion('sk-wang/hao-code');
            if (is_string($version) && preg_match('/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1) {
                return self::normalizeReleaseTag($version);
            }
        }

        throw new \RuntimeException(
            'Cannot infer the hao-code release tag from a development checkout. '
            .'Pass --release-tag or set HAOCODE_SANDBOX_RELEASE_TAG.',
        );
    }

    public static function platformBinaryName(): string
    {
        [$os, $arch] = self::platformTarget();
        $suffix = $os === 'windows' ? '.exe' : '';

        return "haocode-sandbox-{$os}-{$arch}{$suffix}";
    }

    public static function cachedBinary(?string $releaseTag = null): string
    {
        $tag = self::releaseTag($releaseTag);
        $path = self::cacheDirectory($tag).'/'.self::platformBinaryName();

        return self::assertVerified($path, $path.'.sha256');
    }

    public static function cacheDirectory(string $releaseTag): string
    {
        $releaseTag = self::normalizeReleaseTag($releaseTag);
        $override = getenv('HAOCODE_SANDBOX_CACHE');
        if (is_string($override) && $override !== '') {
            $root = rtrim($override, '/\\');
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $root = rtrim((string) (getenv('LOCALAPPDATA') ?: getenv('TEMP') ?: sys_get_temp_dir()), '/\\')
                .'\\hao-code\\sandbox';
        } else {
            $root = rtrim((string) (getenv('XDG_CACHE_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()).'/.cache')), '/')
                .'/hao-code/sandbox';
        }

        return $root.DIRECTORY_SEPARATOR.'runners'.DIRECTORY_SEPARATOR.$releaseTag;
    }

    public static function assertVerified(string $path, string $checksumFile): string
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Tokimo sandbox binary does not exist: {$path}");
        }
        $checksum = is_file($checksumFile) ? trim((string) file_get_contents($checksumFile)) : '';
        if (preg_match('/^([a-f0-9]{64})(?:  .+)?$/', $checksum, $matches) !== 1) {
            throw new \RuntimeException("Tokimo sandbox checksum is missing or invalid: {$checksumFile}");
        }
        $actual = hash_file('sha256', $path);
        if (! is_string($actual) || ! hash_equals($matches[1], $actual)) {
            throw new \RuntimeException("Tokimo sandbox binary checksum verification failed: {$path}");
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($path)) {
            throw new \RuntimeException("Tokimo sandbox binary is not executable: {$path}");
        }

        return $path;
    }

    /** @return array{string, string} */
    private static function platformTarget(): array
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => throw new \RuntimeException('Tokimo sandbox is unsupported on '.PHP_OS_FAMILY.'.'),
        };
        $machine = strtolower(php_uname('m'));
        $arch = match ($machine) {
            'arm64', 'aarch64' => 'arm64',
            'amd64', 'x86_64', 'x64' => 'amd64',
            default => throw new \RuntimeException("Tokimo sandbox is unsupported on CPU architecture {$machine}."),
        };
        if ($os === 'darwin' && $arch !== 'arm64') {
            throw new \RuntimeException('Tokimo sandbox currently supports macOS on Apple Silicon only.');
        }
        if ($os === 'windows' && $arch !== 'amd64') {
            throw new \RuntimeException('Tokimo sandbox currently supports Windows on amd64 only.');
        }

        return [$os, $arch];
    }

    /** @return string[] */
    private static function platformAssetNames(): array
    {
        $names = [self::platformBinaryName()];
        if (PHP_OS_FAMILY === 'Windows') {
            $names[] = 'haocode-sandbox-svc-windows-amd64.exe';
        }

        return $names;
    }

    private static function downloadAsset(
        HttpClientInterface $client,
        string $baseUrl,
        string $directory,
        string $name,
        bool $force,
    ): string {
        $path = $directory.'/'.$name;
        $checksumPath = $path.'.sha256';
        $checksum = trim($client->request('GET', $baseUrl.'/'.$name.'.sha256')->getContent());
        if (preg_match('/^([a-f0-9]{64})  '.preg_quote($name, '/').'$/', $checksum, $matches) !== 1) {
            throw new \RuntimeException("Invalid release checksum for {$name}.");
        }
        if (! $force && is_file($path) && hash_file('sha256', $path) === $matches[1]) {
            self::writeChecksum($checksumPath, $checksum);
            return self::assertVerified($path, $checksumPath);
        }

        $response = $client->request('GET', $baseUrl.'/'.$name);
        $file = fopen($path.'.part', 'wb');
        if ($file === false) {
            throw new \RuntimeException("Unable to write sandbox binary: {$path}.part");
        }
        try {
            foreach ($client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException("Sandbox binary download timed out: {$name}");
                }
                $content = $chunk->getContent();
                if ($content !== '' && fwrite($file, $content) === false) {
                    throw new \RuntimeException("Failed writing sandbox binary: {$path}.part");
                }
            }
        } finally {
            fclose($file);
        }
        if (hash_file('sha256', $path.'.part') !== $matches[1]) {
            @unlink($path.'.part');
            throw new \RuntimeException("Sandbox binary checksum verification failed: {$name}");
        }
        if (is_file($path) && ! unlink($path)) {
            @unlink($path.'.part');
            throw new \RuntimeException("Unable to replace sandbox binary: {$path}");
        }
        if (! rename($path.'.part', $path)) {
            throw new \RuntimeException("Unable to finalize sandbox binary: {$path}");
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0755);
        }
        self::writeChecksum($checksumPath, $checksum);

        return self::assertVerified($path, $checksumPath);
    }

    private static function normalizeReleaseTag(string $tag): string
    {
        if (preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/', $tag, $matches) !== 1) {
            throw new \InvalidArgumentException("Invalid hao-code release tag: {$tag}");
        }

        return 'v'.$matches[1];
    }

    private static function writeChecksum(string $path, string $checksum): void
    {
        if (file_put_contents($path, $checksum."\n") === false) {
            throw new \RuntimeException("Unable to write sandbox checksum: {$path}");
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create sandbox binary cache: {$directory}");
        }
    }
}
