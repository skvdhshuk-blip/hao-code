#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoloaders = [
    dirname(__DIR__).'/vendor/autoload.php',
    dirname(__DIR__, 3).'/autoload.php',
];
foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require_once $autoloader;
        break;
    }
}
if (! class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
    throw new RuntimeException('Unable to locate the Composer autoloader for hao-code.');
}

use Symfony\Component\HttpClient\HttpClient;
use HaoCode\Sdk\Sandbox\SandboxBinaryInstaller;
use HaoCode\Sdk\Sandbox\SandboxBinaryResolver;

const KERNEL_TAG = 'vm-kernel-0.2.1';
const ROOTFS_TAG = 'vm-rootfs-0.2.1';
const RELEASE_BASE = 'https://github.com/tokimo-lab/tokimo-package-sandbox/releases/download';

$options = getopt('', ['dir:', 'force', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/sandbox-setup.php [--dir=/cache/path] [--force]\n");
    exit(0);
}

try {
    SandboxBinaryResolver::resolve();
} catch (RuntimeException) {
    SandboxBinaryInstaller::install(force: isset($options['force']));
}

[$platform, $arch, $assetArch] = platformTarget();
$cacheRoot = isset($options['dir'])
    ? absolutePath((string) $options['dir'])
    : defaultCacheRoot();
$baseRootfs = $cacheRoot.'/'.KERNEL_TAG.'+'.ROOTFS_TAG.'/'.$platform.'-'.$arch.'/base';

if (sandboxArtifactsReady($baseRootfs, $platform)) {
    if ($platform === 'linux') {
        prepareLinuxRuntime(dirname($baseRootfs));
    }
    fwrite(STDOUT, "Tokimo sandbox artifacts are ready: {$baseRootfs}\n");
    exit(0);
}
if (file_exists($baseRootfs) && ! isset($options['force'])) {
    fwrite(STDERR, "Partial sandbox artifacts exist at {$baseRootfs}. Re-run with --force to replace them.\n");
    exit(1);
}
if (isset($options['force'])) {
    removeDirectory(dirname($baseRootfs));
}

$downloads = $cacheRoot.'/downloads/'.KERNEL_TAG.'+'.ROOTFS_TAG.'/'.$assetArch;
ensureDirectory($downloads);
ensureDirectory($baseRootfs);

$kernel = asset(
    KERNEL_TAG,
    "tokimo-linux-kernel-{$assetArch}.tar.zst",
    $assetArch === 'arm64'
        ? '399709d36d7cde06be50728f4aff0751881015faa5a534b8465e3b861856d2cd'
        : 'ee5d8836ed48c70ccafa1a36afa6b1e1419395ce8b8d10b97871a12125169009',
);

if ($platform === 'windows') {
    $rootfs = asset(
        ROOTFS_TAG,
        'tokimo-linux-rootfs-x86_64.vhdx.zip',
        'eb598642818c66940a4064d079cdf37ba46b6909c4826be906af70ae6ed62f1b',
    );
} else {
    $rootfs = asset(
        ROOTFS_TAG,
        "tokimo-linux-rootfs-{$assetArch}.tar.zst",
        $assetArch === 'arm64'
            ? '09cf7ae6c54a7aab55d2ade66375c1e6a211a7afb81cb93bd44d8a23f8b9e1ba'
            : '4974c941ecec78fb66f83b7c1136e56e597da8a501b8d5821288aedf1ab404d3',
    );
}

$assets = [$kernel, $rootfs];
if ($platform === 'linux') {
    $assets[] = asset(
        KERNEL_TAG,
        'tokimo-sandbox-host-linux-'.($arch === 'arm64' ? 'arm64' : 'amd64').'-musl.tar.zst',
        $arch === 'arm64'
            ? '20847f4fd5e8cf23cc6fda336c069c03b117a12b682cf874867340f5afa22f0f'
            : 'ac260f1a12c260571fbf9bb6b57968990e278e9a2e1de5b8b55c450a7fd0075e',
    );
}

$client = HttpClient::create(['timeout' => 60, 'max_duration' => 0]);
foreach ($assets as $asset) {
    downloadVerified($client, $asset, $downloads.'/'.$asset['name']);
}

$zstd = requireExecutable('zstd');
$tar = requireExecutable('tar');
$kernelTar = decompressZstd($zstd, $downloads.'/'.$kernel['name']);
runCommand([$tar, '-xf', $kernelTar, '-C', $baseRootfs]);

if ($platform === 'windows') {
    extractZip($downloads.'/'.$rootfs['name'], $baseRootfs);
} else {
    $rootfsDir = $baseRootfs.'/rootfs';
    ensureDirectory($rootfsDir);
    $rootfsTar = decompressZstd($zstd, $downloads.'/'.$rootfs['name']);
    $command = [$tar, '--numeric-owner', '-xpf', $rootfsTar, '-C', $rootfsDir];
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $sudo = requireExecutable('sudo');
        array_unshift($command, $sudo);
        fwrite(STDOUT, "Preserving guest uid/gid requires sudo for rootfs extraction.\n");
    }
    runCommand($command);
}

if ($platform === 'linux') {
    $hostAsset = $assets[2];
    $hostTar = decompressZstd($zstd, $downloads.'/'.$hostAsset['name']);
    $hostBin = $baseRootfs.'/host-bin';
    ensureDirectory($hostBin);
    runCommand([$tar, '-xf', $hostTar, '-C', $hostBin]);
    foreach (glob($hostBin.'/tokimo-*') ?: [] as $binary) {
        chmod($binary, 0755);
    }
    prepareLinuxRuntime(dirname($baseRootfs));
}

file_put_contents($baseRootfs.'/.haocode-sandbox-artifacts.json', json_encode([
    'kernelTag' => KERNEL_TAG,
    'rootfsTag' => ROOTFS_TAG,
    'platform' => $platform,
    'arch' => $arch,
    'assets' => array_column($assets, 'sha256', 'name'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

if (! sandboxArtifactsReady($baseRootfs, $platform)) {
    fwrite(STDERR, "Sandbox artifact setup finished without all required files.\n");
    exit(1);
}

fwrite(STDOUT, "Tokimo sandbox artifacts are ready: {$baseRootfs}\n");

/** @return array{string, string, string} */
function platformTarget(): array
{
    $machine = strtolower(php_uname('m'));
    $arch = match ($machine) {
        'arm64', 'aarch64' => 'arm64',
        'amd64', 'x86_64', 'x64' => 'amd64',
        default => throw new RuntimeException("Unsupported sandbox CPU architecture: {$machine}"),
    };
    $platform = match (PHP_OS_FAMILY) {
        'Darwin' => 'darwin',
        'Linux' => 'linux',
        'Windows' => 'windows',
        default => throw new RuntimeException('Unsupported sandbox platform: '.PHP_OS_FAMILY),
    };
    if ($platform === 'darwin' && $arch !== 'arm64') {
        throw new RuntimeException('Tokimo supports macOS on Apple Silicon only.');
    }
    if ($platform === 'windows' && $arch !== 'amd64') {
        throw new RuntimeException('Tokimo supports Windows on amd64 only.');
    }

    return [$platform, $arch, $arch === 'amd64' ? 'x86_64' : 'arm64'];
}

/** @return array{tag: string, name: string, sha256: string, url: string} */
function asset(string $tag, string $name, string $sha256): array
{
    return [
        'tag' => $tag,
        'name' => $name,
        'sha256' => $sha256,
        'url' => RELEASE_BASE."/{$tag}/{$name}",
    ];
}

function defaultCacheRoot(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $root = getenv('LOCALAPPDATA') ?: getenv('TEMP');
    } else {
        $root = getenv('XDG_CACHE_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()).'/.cache');
    }
    if (! is_string($root) || $root === '') {
        throw new RuntimeException('Unable to determine the sandbox cache directory.');
    }
    return rtrim($root, '/\\').DIRECTORY_SEPARATOR.'hao-code'.DIRECTORY_SEPARATOR.'sandbox';
}

function absolutePath(string $path): string
{
    if ($path === '') {
        throw new InvalidArgumentException('--dir cannot be empty.');
    }
    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
        return rtrim($path, '/\\');
    }
    return getcwd().DIRECTORY_SEPARATOR.$path;
}

function sandboxArtifactsReady(string $base, string $platform): bool
{
    $rootfsReady = $platform === 'windows' ? is_file($base.'/rootfs.vhdx') : is_dir($base.'/rootfs');
    $hostReady = $platform !== 'linux'
        || (is_file($base.'/host-bin/tokimo-sandbox-init') && is_file($base.'/host-bin/tokimo-sandbox-fuse'));

    return is_file($base.'/vmlinuz')
        && is_file($base.'/initrd.img')
        && $rootfsReady
        && $hostReady
        && is_file($base.'/.haocode-sandbox-artifacts.json');
}

function downloadVerified(object $client, array $asset, string $destination): void
{
    if (is_file($destination) && hash_file('sha256', $destination) === $asset['sha256']) {
        fwrite(STDOUT, "Using cached {$asset['name']}\n");
        return;
    }

    fwrite(STDOUT, "Downloading {$asset['url']}\n");
    $response = $client->request('GET', $asset['url']);
    $response->getHeaders();
    $file = fopen($destination.'.part', 'wb');
    if ($file === false) {
        throw new RuntimeException("Unable to write download: {$destination}.part");
    }
    try {
        foreach ($client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                throw new RuntimeException("Download timed out: {$asset['name']}");
            }
            $content = $chunk->getContent();
            if ($content !== '' && fwrite($file, $content) === false) {
                throw new RuntimeException("Failed writing download: {$destination}.part");
            }
        }
    } finally {
        fclose($file);
    }
    if (hash_file('sha256', $destination.'.part') !== $asset['sha256']) {
        @unlink($destination.'.part');
        throw new RuntimeException("SHA-256 verification failed for {$asset['name']}");
    }
    if (! rename($destination.'.part', $destination)) {
        throw new RuntimeException("Unable to finalize download: {$destination}");
    }
}

function decompressZstd(string $zstd, string $source): string
{
    $target = preg_replace('/\.zst$/', '', $source);
    if (! is_string($target) || $target === $source) {
        throw new RuntimeException("Expected a .zst asset: {$source}");
    }
    runCommand([$zstd, '-d', '-f', $source, '-o', $target]);
    return $target;
}

function extractZip(string $source, string $destination): void
{
    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($source) !== true || ! $zip->extractTo($destination)) {
            throw new RuntimeException("Failed to extract {$source}");
        }
        $zip->close();
        return;
    }

    $powershell = requireExecutable('powershell');
    $quote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
    runCommand([$powershell, '-NoProfile', '-Command', 'Expand-Archive -LiteralPath '.$quote($source).' -DestinationPath '.$quote($destination).' -Force']);
}

function requireExecutable(string $name): string
{
    $executable = findExecutable($name);
    if ($executable !== null) {
        return $executable;
    }
    throw new RuntimeException("Required executable is unavailable: {$name}");
}

function findExecutable(string $name): ?string
{
    $extensions = PHP_OS_FAMILY === 'Windows' ? ['', '.exe', '.cmd', '.bat'] : [''];
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        foreach ($extensions as $extension) {
            $base = $directory === '' ? getcwd() : $directory;
            $candidate = rtrim($base, '/\\').DIRECTORY_SEPARATOR.$name.$extension;
            if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
                return realpath($candidate) ?: $candidate;
            }
        }
    }
    return null;
}

function prepareLinuxRuntime(string $runtimeRoot): void
{
    $cloudHypervisor = findExecutable('cloud-hypervisor');
    $virtiofsd = findExecutable('virtiofsd');
    if ($cloudHypervisor === null || $virtiofsd === null) {
        $missing = implode(', ', array_keys(array_filter([
            'cloud-hypervisor' => $cloudHypervisor === null,
            'virtiofsd' => $virtiofsd === null,
        ])));
        $fallback = findExecutable('bwrap') === null
            ? 'bubblewrap is also missing, so no Linux sandbox backend is currently available.'
            : 'Tokimo will use the bubblewrap fallback.';
        fwrite(STDOUT, "Linux micro-VM helpers not linked (missing {$missing}); {$fallback}\n");
        return;
    }

    linkExecutable($cloudHypervisor, $runtimeRoot.'/bin/cloud-hypervisor/current/bin/cloud-hypervisor');
    linkExecutable($virtiofsd, $runtimeRoot.'/bin/virtiofsd/current/virtiofsd');
    fwrite(STDOUT, "Linked Linux micro-VM helpers in {$runtimeRoot}/bin.\n");
}

function linkExecutable(string $source, string $target): void
{
    ensureDirectory(dirname($target));
    if (is_link($target) && readlink($target) === $source) {
        return;
    }
    if (file_exists($target) || is_link($target)) {
        if (! unlink($target)) {
            throw new RuntimeException("Unable to replace runtime helper: {$target}");
        }
    }
    if (! symlink($source, $target)) {
        throw new RuntimeException("Unable to link runtime helper: {$target}");
    }
}

/** @param string[] $command */
function runCommand(array $command): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start command: '.implode(' ', $command));
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Command failed with exit code {$exitCode}: ".implode(' ', $command));
    }
}

function ensureDirectory(string $directory): void
{
    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create directory: {$directory}");
    }
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && ($file->isFile() || $file->isLink())) {
            @unlink($file->getPathname());
        } elseif ($file instanceof SplFileInfo && $file->isDir()) {
            @rmdir($file->getPathname());
        }
    }
    @rmdir($directory);
}
