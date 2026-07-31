<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Services\FileEdit\AtomicFileWriter;
use HaoCode\Services\FileEdit\FileConflictException;
use HaoCode\Services\FileEdit\FileRevision;
use HaoCode\Sdk\Sandbox\RevisionAwareSandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;

/** @api */
final class LocalSandboxBackend implements SandboxBackendInterface, RevisionAwareSandboxBackendInterface
{
    private const MAX_GLOB_RESULTS = 100;
    private const MAX_VISITED_FILES = 20_000;
    private const MAX_PATTERN_LENGTH = 512;
    private const MAX_BRACE_EXPANSIONS = 256;
    private const MAX_TEXT_LINE_BYTES = 1_000_000;
    private const IGNORED_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
        '.claude/worktrees',
        'node_modules',
        'vendor',
    ];

    private string $root;
    private bool $ownsRoot;
    private bool $preserveOnClose = false;

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
        $stdoutFile = tempnam(sys_get_temp_dir(), 'haocode_sandbox_stdout_');
        $stderrFile = tempnam(sys_get_temp_dir(), 'haocode_sandbox_stderr_');
        if ($stdoutFile === false || $stderrFile === false) {
            throw new \RuntimeException('Failed to allocate command output files.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
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
            @unlink($stdoutFile);
            @unlink($stderrFile);
            throw new \RuntimeException("Failed to execute sandbox command: {$command}\n".$e->getMessage(), 0, $e);
        }

        $wait = \HaoCode\Support\Runtime\ProcessSupervisor::wait(
            $opened['process'],
            $opened['pid'],
            max(0.001, $timeoutMs / 1000),
            $shouldAbort,
        );

        $stdout = file_get_contents($stdoutFile) ?: '';
        $stderr = file_get_contents($stderrFile) ?: '';
        @unlink($stdoutFile);
        @unlink($stderrFile);

        $aborted = $wait['aborted'];
        $timedOut = $wait['timedOut'];
        $exitCode = $aborted ? 130 : ($timedOut ? 124 : $wait['exitCode']);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode,
            'timedOut' => $timedOut,
            'aborted' => $aborted,
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

    private function toRemotePath(string $localPath): string
    {
        $relative = ltrim(str_replace($this->root, '', $localPath), DIRECTORY_SEPARATOR);
        return '/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isLink()) {
                @unlink($file->getPathname());
            } elseif ($file instanceof \SplFileInfo && $file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** @return \Generator<int, \SplFileInfo> */
    private function iterFiles(string $dir, int &$visitedFiles): \Generator
    {
        $directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $current) use ($dir): bool {
                $relative = $this->relativeLocalPath($current->getPathname(), $dir);
                if ($this->isIgnoredPath($relative)) {
                    return false;
                }

                return ! $current->isDir() || ! $current->isLink();
            },
        );
        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && ! $file->isLink() && $file->isFile()) {
                $visitedFiles++;
                if ($visitedFiles > self::MAX_VISITED_FILES) {
                    break;
                }

                yield $file;
            }
        }
    }

    /**
     * @param list<array{path: string, mtime: int}> $matches
     */
    private function addTopGlobMatch(array &$matches, string $localPath): void
    {
        $matches[] = [
            'path' => $this->toRemotePath($localPath),
            'mtime' => filemtime($localPath) ?: 0,
        ];
        usort($matches, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        if (count($matches) > self::MAX_GLOB_RESULTS) {
            array_pop($matches);
        }
    }

    private function relativeLocalPath(string $localPath, string $baseLocal): string
    {
        $prefix = rtrim($baseLocal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($localPath, $prefix)
            ? substr($localPath, strlen($prefix))
            : $localPath;

        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim($relative, DIRECTORY_SEPARATOR));
    }

    private function isIgnoredPath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
        foreach (self::IGNORED_DIRECTORIES as $ignored) {
            if ($relativePath === $ignored || str_starts_with($relativePath, $ignored.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isTextFile(string $localPath): bool
    {
        $sample = @file_get_contents($localPath, false, null, 0, 1024);
        if (! is_string($sample)) {
            return false;
        }

        return ! str_contains($sample, "\0");
    }

    private function skipRestOfLine($handle): void
    {
        while (($chunk = fgets($handle, 8192)) !== false) {
            if (str_ends_with($chunk, "\n")) {
                return;
            }
        }
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        return str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;
    }

    private function globToRegex(string $pattern): string
    {
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\\*\\*/', '__DOUBLE_STAR_SLASH__', $regex);
        $regex = str_replace('\\*\\*', '__DOUBLE_STAR__', $regex);
        $regex = str_replace('\\*', '[^/]*', $regex);
        $regex = str_replace('\\?', '[^/]', $regex);
        $regex = str_replace('__DOUBLE_STAR_SLASH__', '(?:.*/)?', $regex);
        $regex = str_replace('__DOUBLE_STAR__', '.*', $regex);
        return '#^'.$regex.'$#';
    }

    /** @return string[] */
    private function expandBracePatterns(string $pattern): array
    {
        $expanded = $this->expandBracePatternsBounded($pattern, self::MAX_BRACE_EXPANSIONS);
        if (count($expanded) > self::MAX_BRACE_EXPANSIONS) {
            throw new \LengthException('Sandbox glob brace expansion is too broad; narrow the pattern to fewer alternatives.');
        }

        return array_values(array_unique($expanded));
    }

    /** @return list<string> */
    private function expandBracePatternsBounded(string $pattern, int $limit): array
    {
        if (! preg_match('/\{([^{}]+)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
            return [$pattern];
        }
        $brace = $matches[0][0];
        $offset = $matches[0][1];
        $options = explode(',', $matches[1][0]);
        $prefix = substr($pattern, 0, $offset);
        $suffix = substr($pattern, $offset + strlen($brace));
        $expanded = [];
        foreach ($options as $option) {
            foreach ($this->expandBracePatternsBounded($prefix.$option.$suffix, $limit) as $variant) {
                $expanded[] = $variant;
                if (count($expanded) > $limit) {
                    return $expanded;
                }
            }
        }
        return array_values(array_unique($expanded));
    }
}
