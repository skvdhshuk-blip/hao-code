<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Sdk\Sandbox\RevisionAwareSandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

trait LocalSandboxBackendConstructConcern
{

    public function __construct(private readonly SandboxConfig $config)
    {
        if ($config->root !== null && $config->root !== '') {
            $this->root = rtrim($config->root, '/');
            // Resume path may reclaim ownership of a previously detached temp root.
            $this->ownsRoot = (bool) ($config->options['owns_root'] ?? false);
            if (! is_dir($this->root) && ! mkdir($this->root, 0755, true)) {
                throw new \RuntimeException("Failed to create sandbox root: {$this->root}");
            }
        } else {
            $this->root = sys_get_temp_dir().'/haocode-sandbox-'.bin2hex(random_bytes(8));
            $this->ownsRoot = true;
            if (! mkdir($this->root, 0700, true)) {
                throw new \RuntimeException("Failed to create sandbox root: {$this->root}");
            }
        }

        $canonicalRoot = realpath($this->root);
        if ($canonicalRoot === false) {
            throw new \RuntimeException("Failed to resolve sandbox root: {$this->root}");
        }
        $this->root = rtrim($canonicalRoot, DIRECTORY_SEPARATOR);
        if ($this->root === '') {
            $this->root = DIRECTORY_SEPARATOR;
        }

        $this->ensureDirectory($this->resolve($this->config->remoteCwd));
    }

    public function stat(string $path): array
    {
        $local = $this->resolve($path);
        if (! file_exists($local)) {
            return ['exists' => false];
        }

        return [
            'exists' => true,
            'isFile' => is_file($local),
            'isDir' => is_dir($local),
            'size' => is_file($local) ? (filesize($local) ?: 0) : 0,
            'mtime' => filemtime($local) ?: 0,
        ];
    }

    public function readFile(string $path): string
    {
        $local = $this->resolve($path);
        if (! is_file($local)) {
            throw new \RuntimeException("Sandbox file does not exist: {$path}");
        }
        if (! is_readable($local)) {
            throw new \RuntimeException("Sandbox file is not readable: {$path}");
        }

        $content = file_get_contents($local);
        if ($content === false) {
            throw new \RuntimeException("Failed to read sandbox file: {$path}");
        }

        return $content;
    }

    public function writeFile(string $path, string $content): void
    {
        $local = $this->resolve($path);
        $this->ensureDirectory(dirname($local));
        if (file_put_contents($local, $content) === false) {
            throw new \RuntimeException("Failed to write sandbox file: {$path}");
        }
    }

    /** @internal */
    public function writeFileIfUnchanged(
        string $path,
        string $content,
        ?string $expectedSha256,
    ): void {
        $local = $this->resolve($path);
        $this->ensureDirectory(dirname($local));

        $expectedRevision = null;
        if ($expectedSha256 !== null) {
            $expectedRevision = FileRevision::capture($local);
            if ($expectedRevision === null
                || ! hash_equals($expectedSha256, $expectedRevision->sha256)) {
                throw new FileConflictException(
                    "Sandbox file changed since it was read: {$path}. Read it again before writing.",
                );
            }
        }

        (new AtomicFileWriter())->write(
            $local,
            $content,
            $expectedRevision,
        );
    }

    public function delete(string $path): void
    {
        $local = $this->resolve($path);
        if (! file_exists($local)) {
            return;
        }
        if (is_dir($local)) {
            $this->removeDirectory($local);
            return;
        }
        @unlink($local);
    }

    public function list(string $path): array
    {
        $local = $this->resolve($path);
        if (! is_dir($local)) {
            throw new \RuntimeException("Sandbox directory does not exist: {$path}");
        }

        $items = [];
        foreach (scandir($local) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $items[] = rtrim($path, '/').'/'.$item;
        }
        sort($items, SORT_STRING);

        return $items;
    }

    public function glob(string $pattern, ?string $path = null): array
    {
        $pattern = $this->normalizePattern($pattern);
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new \InvalidArgumentException('Sandbox glob pattern is too long; narrow the search pattern.');
        }

        $baseRemote = $path ?? $this->config->remoteCwd;
        $baseLocal = $this->resolve($baseRemote);
        if (! is_dir($baseLocal)) {
            return [];
        }

        $matches = [];
        $regexPatterns = array_map(fn (string $p): string => $this->globToRegex($p), $this->expandBracePatterns($pattern));
        $visitedFiles = 0;

        foreach ($this->iterFiles($baseLocal, $visitedFiles) as $file) {
            $localPath = $file->getPathname();
            $relative = $this->relativeLocalPath($localPath, $baseLocal);
            foreach ($regexPatterns as $regex) {
                if (preg_match($regex, $relative) === 1) {
                    $this->addTopGlobMatch($matches, $localPath);
                    break;
                }
            }
        }

        return array_map(
            fn (array $match): string => $match['path'],
            $matches,
        );
    }

    public function grep(string $pattern, ?string $path = null, ?string $glob = null, bool $caseInsensitive = false, int $limit = 250): array
    {
        if ($limit <= 0) {
            return [];
        }
        $limit = min($limit, 1000);

        $baseRemote = $path ?? $this->config->remoteCwd;
        $baseLocal = $this->resolve($baseRemote);
        if (! file_exists($baseLocal)) {
            return [];
        }

        $globRegex = $glob !== null && $glob !== '' ? $this->globToRegex($this->normalizePattern($glob)) : null;
        $flags = $caseInsensitive ? 'i' : '';
        $safePattern = str_replace('/', '\/', $pattern);
        $regex = '/'.$safePattern.'/'.$flags;
        set_error_handler(fn () => true);
        $valid = preg_match($regex, '') !== false;
        restore_error_handler();
        if (! $valid) {
            throw new \InvalidArgumentException("Invalid regex pattern: {$pattern}");
        }

        $matches = [];
        $visitedFiles = 0;
        $baseForRelative = is_dir($baseLocal) ? $baseLocal : dirname($baseLocal);
        $files = is_file($baseLocal)
            ? (is_link($baseLocal) ? [] : [new \SplFileInfo($baseLocal)])
            : $this->iterFiles($baseLocal, $visitedFiles);

        foreach ($files as $fileInfo) {
            if (! $fileInfo instanceof \SplFileInfo) {
                continue;
            }
            $file = $fileInfo->getPathname();
            $relative = $this->relativeLocalPath($file, $baseForRelative);
            if ($globRegex !== null && preg_match($globRegex, $relative) !== 1) {
                continue;
            }
            if (! $this->isTextFile($file)) {
                continue;
            }
            $handle = @fopen($file, 'rb');
            if (! is_resource($handle)) {
                continue;
            }

            $lineNumber = 0;
            while (($line = fgets($handle, self::MAX_TEXT_LINE_BYTES + 1)) !== false) {
                $lineNumber++;
                if (strlen($line) > self::MAX_TEXT_LINE_BYTES && ! str_ends_with($line, "\n")) {
                    $this->skipRestOfLine($handle);
                    continue;
                }

                $line = rtrim($line, "\r\n");
                if (preg_match($regex, $line) === 1) {
                    $matches[] = ['file' => $this->toRemotePath($file), 'line' => $lineNumber, 'text' => $line];
                    if (count($matches) >= $limit) {
                        fclose($handle);

                        return $matches;
                    }
                }
            }
            fclose($handle);
        }

        return $matches;
    }

    public function exec(string $command, ?string $cwd = null, int $timeoutMs = 120000, ?callable $shouldAbort = null): array
    {
        $cwdLocal = $this->resolve($cwd ?? $this->config->remoteCwd);
        $this->ensureDirectory($cwdLocal);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = \HaoCode\Support\Runtime\SpawnEnvironment::build();

        try {
            $opened = \HaoCode\Support\Runtime\ProcessSupervisor::open(
                $command,
                $cwdLocal,
                $env,
                $descriptors,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to execute sandbox command: {$command}\n".$e->getMessage(), 0, $e);
        }

        foreach ([1, 2] as $index) {
            if (isset($opened['pipes'][$index]) && is_resource($opened['pipes'][$index])) {
                stream_set_blocking($opened['pipes'][$index], false);
            }
        }

        $stdout = '';
        $stderr = '';
        $capturedBytes = 0;
        $outputLimited = false;
        $aborted = false;
        $timedOut = false;
        $exitCode = -1;
        $status = ['running' => true, 'exitcode' => -1];
        $deadline = microtime(true) + max(0.001, $timeoutMs / 1000);
        $process = $opened['process'];
        $pid = $opened['pid'];

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                $aborted = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            if ($this->drainExecPipes($opened['pipes'], $stdout, $stderr, $capturedBytes)) {
                $outputLimited = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                $exitCode = ($status['signaled'] ?? false)
                    ? 128 + (int) ($status['termsig'] ?? 0)
                    : (int) ($status['exitcode'] ?? -1);
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
                break;
            }

            usleep(20_000);
        }

        if (! $outputLimited && $this->drainExecPipes($opened['pipes'], $stdout, $stderr, $capturedBytes)) {
            $outputLimited = true;
            \HaoCode\Support\Runtime\ProcessSupervisor::terminateTree($pid, false);
        }

        foreach ([1, 2] as $index) {
            if (isset($opened['pipes'][$index]) && is_resource($opened['pipes'][$index])) {
                fclose($opened['pipes'][$index]);
            }
        }

        $closed = @proc_close($process);
        if ($outputLimited) {
            $exitCode = 1;
        } elseif ($exitCode < 0 && ! $timedOut && ! $aborted) {
            $exitCode = $closed;
        }

        $exitCode = $aborted ? 130 : ($timedOut ? 124 : $exitCode);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode,
            'timedOut' => $timedOut,
            'aborted' => $aborted,
            'outputLimited' => $outputLimited,
        ];
    }

    public function upload(string $localPath, string $remotePath): void
    {
        if (! is_file($localPath)) {
            throw new \InvalidArgumentException("Local file does not exist: {$localPath}");
        }
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read local file: {$localPath}");
        }
        $this->writeFile($remotePath, $content);
    }

    public function download(string $remotePath, string $localPath): void
    {
        $content = $this->readFile($remotePath);
        $dir = dirname($localPath);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create local directory: {$dir}");
        }
        if (file_put_contents($localPath, $content) === false) {
            throw new \RuntimeException("Failed to write local file: {$localPath}");
        }
    }

    public function close(): void
    {
        if ($this->preserveOnClose) {
            return;
        }
        if ($this->ownsRoot && $this->config->cleanup === 'always') {
            $this->removeDirectory($this->root);
        }
    }

    /**
     * Keep the filesystem root for durable HITL resume instead of cleaning it.
     *
     * @internal
     */
    public function detach(): void
    {
        $this->preserveOnClose = true;
    }

    /**
     * Durable lease: identity for reattach; policy is reapplied from caller config.
     *
     * @return array<string, mixed>
     * @internal
     */
    public function exportLease(): array
    {
        return [
            'version' => 1,
            'provider' => $this->config->provider,
            'identity' => [
                'root' => $this->root,
                'owns_root' => $this->ownsRoot,
                'remote_cwd' => $this->config->remoteCwd,
            ],
            // Snapshot of original policy for stricter-merge on resume (not secrets).
            'mode' => $this->config->mode,
            'remote_cwd' => $this->config->remoteCwd,
            'sync' => $this->config->sync,
            'cleanup' => $this->config->cleanup,
            'root' => $this->root,
            'owns_root' => $this->ownsRoot,
            'exclude' => $this->config->exclude,
            'options' => array_diff_key(
                $this->config->options,
                array_flip(['apiKey', 'authorization', 'token', 'password', 'secret']),
            ),
        ];
    }

    public function rootLabel(): string
    {
        return $this->root;
    }

    private function resolve(string $path): string
    {
        $path = trim($path) === '' ? $this->config->remoteCwd : $path;
        if (! str_starts_with($path, '/')) {
            $path = rtrim($this->config->remoteCwd, '/').'/'.$path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        $resolved = $this->root.'/'.implode('/', $parts);
        $this->assertPathInsideRoot($resolved, $path);

        return $resolved;
    }

    private function assertPathInsideRoot(string $path, string $requestedPath): void
    {
        $existing = $path;
        while (! file_exists($existing) && ! is_link($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }

        $canonical = realpath($existing);
        if ($canonical === false) {
            throw new \RuntimeException("Failed to resolve sandbox path: {$requestedPath}");
        }

        if ($canonical !== $this->root && ! str_starts_with($canonical, $this->root.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Sandbox path escapes through a symbolic link: {$requestedPath}");
        }
    }
}
