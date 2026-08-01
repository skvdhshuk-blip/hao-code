<?php

namespace HaoCode\Sdk\Sandbox\Backends;

use HaoCode\Sdk\Sandbox\AgentRun\AgentRunClient;
use HaoCode\Sdk\Sandbox\SandboxBackendInterface;
use HaoCode\Sdk\Sandbox\SandboxConfig;
use HaoCode\Sdk\Sandbox\SandboxSearchMetadata;

/** @api */
final class AgentRunSandboxBackend implements SandboxBackendInterface
{
    private const MAX_EXEC_OUTPUT_BYTES = 100_000;
    private const MAX_SEARCH_RESULTS = 100;
    private const MAX_SEARCH_VISITED_FILES = 20_000;
    private const SEARCH_RESULT_LIMIT_MARKER = '__HAOCODE_SEARCH_RESULT_LIMIT__';
    private const SEARCH_VISITED_LIMIT_MARKER = '__HAOCODE_SEARCH_VISITED_LIMIT__';
    private const IGNORED_DIRECTORY_NAMES = ['.git', '.hg', '.svn', 'node_modules', 'vendor'];
    private const IGNORED_DIRECTORY_PATHS = ['.claude/worktrees'];

    private AgentRunClient $client;

    public function __construct(private readonly SandboxConfig $config, ?AgentRunClient $client = null)
    {
        $this->client = $client ?? AgentRunClient::fromOptions($config->options);
    }

    public function stat(string $path): array
    {
        try {
            $stat = $this->client->stat($path);
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            // Only map explicit not-found signals; auth/rate-limit/5xx stay exceptions.
            if (str_contains($message, '404')
                || str_contains($message, 'not found')
                || str_contains($message, 'does not exist')
                || str_contains($message, 'no such file')) {
                return ['exists' => false];
            }
            throw $e;
        }

        $type = $stat['type'] ?? null;
        $isFile = array_key_exists('isFile', $stat)
            ? (bool) $stat['isFile']
            : (is_string($type) && strcasecmp($type, 'file') === 0);
        $isDir = array_key_exists('isDir', $stat)
            ? (bool) $stat['isDir']
            : (is_string($type) && (strcasecmp($type, 'dir') === 0 || strcasecmp($type, 'directory') === 0));

        return [
            'exists' => true,
            'isFile' => $isFile,
            'isDir' => $isDir,
            'size' => (int) ($stat['size'] ?? 0),
            'mtime' => (int) ($stat['mtime'] ?? $stat['modifiedTime'] ?? 0),
        ];
    }

    public function readFile(string $path): string
    {
        return $this->client->readFile($path);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->client->writeFile($path, $content);
    }

    public function delete(string $path): void
    {
        $this->client->remove($path);
    }

    public function list(string $path): array
    {
        $result = $this->client->list($path);
        $items = $result['items'] ?? $result['files'] ?? $result['data']['items'] ?? $result['data'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $paths = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $paths[] = $item;
            } elseif (is_array($item) && is_string($item['path'] ?? null)) {
                $paths[] = $item['path'];
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    public function glob(string $pattern, ?string $path = null): array
    {
        SandboxSearchMetadata::begin($this);
        $cwd = $path ?? $this->config->remoteCwd;
        $cmd = $this->buildBoundedGlobCommand($cwd, $pattern);
        $result = $this->exec($cmd, $cwd, 30000);
        [$lines, $resultLimited, $visitedLimited] = $this->parseSearchOutput($result['stdout']);
        $matches = [];
        foreach ($lines as $line) {
            if ($this->matchesSearchPath($line, $cwd, $pattern)) {
                $matches[] = $line;
            }
        }

        $outputLimited = (bool) ($result['outputLimited'] ?? false);
        $resultLimited = $resultLimited || count($matches) > self::MAX_SEARCH_RESULTS;
        $matches = array_values(array_unique(array_slice($matches, 0, self::MAX_SEARCH_RESULTS)));
        $this->recordSearchMetadata('glob', $resultLimited, $visitedLimited, $outputLimited, self::MAX_SEARCH_RESULTS);

        if ($result['exitCode'] !== 0 && $matches === [] && ! $resultLimited && ! $visitedLimited && ! $outputLimited) {
            if (trim($result['stderr']) !== '') {
                throw new \RuntimeException('AgentRun glob failed: '.trim($result['stderr']));
            }
            return [];
        }

        return $matches;
    }

    public function grep(string $pattern, ?string $path = null, ?string $glob = null, bool $caseInsensitive = false, int $limit = 250): array
    {
        SandboxSearchMetadata::begin($this);
        $cwd = $path ?? $this->config->remoteCwd;
        $limit = max(0, min($limit, 1000));
        if ($limit === 0) {
            $this->recordSearchMetadata('grep', true, false, false, 0);

            return [];
        }

        $globPattern = ($glob === null || trim($glob) === '') ? '*' : $glob;
        $find = $this->buildPrunedFindCommand(
            $cwd,
            $this->findPattern($cwd, $globPattern),
            true,
        );
        $grep = 'xargs -0 grep -I -nH'.($caseInsensitive ? ' -i' : '').' -- '.escapeshellarg($pattern);
        $cmd = $find.' | '.$grep.' | head -n '.($limit + 1);
        $result = $this->exec($cmd, $cwd, 30000);
        [$lines, $resultLimited, $visitedLimited] = $this->parseSearchOutput($result['stdout']);
        $outputLimited = (bool) ($result['outputLimited'] ?? false);
        $resultLimited = $resultLimited || count($lines) > $limit;
        $lines = array_slice($lines, 0, $limit);
        $matches = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            [$file, $lineNo, $text] = array_pad(explode(':', $line, 3), 3, '');
            if ($glob !== null && $glob !== '' && ! $this->matchesSearchPath($file, $cwd, $glob)) {
                continue;
            }
            $matches[] = ['file' => $file, 'line' => (int) $lineNo, 'text' => $text];
        }
        $this->recordSearchMetadata('grep', $resultLimited, $visitedLimited, $outputLimited, $limit);

        if ($result['exitCode'] !== 0 && $matches === [] && ! $resultLimited && ! $visitedLimited && ! $outputLimited) {
            if (trim($result['stderr']) !== '') {
                throw new \RuntimeException('AgentRun grep failed: '.trim($result['stderr']));
            }
            return [];
        }

        return $matches;
    }

    public function exec(string $command, ?string $cwd = null, int $timeoutMs = 120000, ?callable $shouldAbort = null): array
    {
        if ($shouldAbort !== null && $shouldAbort()) {
            return ['stdout' => '', 'stderr' => '', 'exitCode' => 130, 'timedOut' => false, 'aborted' => true, 'outputLimited' => false];
        }

        $result = $this->client->cmd(
            $command,
            $cwd ?? $this->config->remoteCwd,
            max(1, (int) ceil($timeoutMs / 1000)),
            $shouldAbort,
        );
        if (($result['__haocode_aborted'] ?? false) === true) {
            return ['stdout' => '', 'stderr' => '', 'exitCode' => 130, 'timedOut' => false, 'aborted' => true, 'outputLimited' => false];
        }
        $exitCode = $result['exitCode']
            ?? $result['code']
            ?? $result['data']['exitCode']
            ?? $result['result']['exitCode']
            ?? null;
        if (! is_numeric($exitCode)) {
            throw new \RuntimeException('AgentRun process response is missing a numeric exitCode.');
        }

        $stdout = (string) ($result['stdout'] ?? $result['data']['stdout'] ?? $result['result']['stdout'] ?? '');
        $stderr = (string) ($result['stderr'] ?? $result['data']['stderr'] ?? $result['result']['stderr'] ?? '');
        $outputLimited = (bool) ($result['outputLimited'] ?? $result['output_limited'] ?? $result['data']['outputLimited'] ?? $result['result']['outputLimited'] ?? false);
        $stdoutLimited = $this->capExecOutput($stdout, 'stdout');
        $stderrLimited = $this->capExecOutput($stderr, 'stderr');
        $outputLimited = $outputLimited || $stdoutLimited || $stderrLimited;
        $timedOut = (bool) ($result['timedOut'] ?? $result['timed_out'] ?? $result['data']['timedOut'] ?? $result['result']['timedOut'] ?? false);
        $aborted = (bool) ($result['aborted'] ?? $result['data']['aborted'] ?? $result['result']['aborted'] ?? false);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $aborted ? 130 : ($timedOut ? 124 : ($outputLimited ? 1 : (int) $exitCode)),
            'timedOut' => $timedOut,
            'aborted' => $aborted,
            'outputLimited' => $outputLimited,
        ];
    }

    public function upload(string $localPath, string $remotePath): void
    {
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

    public function close(): void {}

    /** @internal */
    public function detach(): void
    {
        // Remote sandbox identity is retained server-side; nothing local to delete.
    }

    /**
     * Durable lease identity only — never credentials.
     *
     * @return array<string, mixed>
     * @internal
     */
    public function exportLease(): array
    {
        $resolvedId = $this->client->sandboxId();

        return [
            'version' => 1,
            'provider' => 'agentrun',
            'identity' => [
                'sandbox_id' => $resolvedId,
                'remote_cwd' => $this->config->remoteCwd,
            ],
            // Policy is re-applied from the caller config on resume.
            'mode' => $this->config->mode,
            'remote_cwd' => $this->config->remoteCwd,
            'sync' => $this->config->sync,
            'cleanup' => $this->config->cleanup,
            'root' => null,
            'owns_root' => false,
            'exclude' => $this->config->exclude,
            // Non-secret options only (auth re-read from env/caller on resume).
            'options' => array_filter([
                'sandboxId' => $resolvedId,
                'region' => $this->config->options['region'] ?? null,
                'endpoint' => $this->config->options['endpoint'] ?? null,
                'timeoutSeconds' => $this->config->options['timeoutSeconds'] ?? null,
                'accountId' => $this->config->options['accountId'] ?? null,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        ];
    }

    public function rootLabel(): string
    {
        return 'agentrun:'.$this->client->sandboxId();
    }

    private function findPattern(string $cwd, string $pattern): string
    {
        $pattern = $this->normalizeSearchPattern($pattern);

        if (str_starts_with($pattern, '/')) {
            return $pattern;
        }

        return rtrim($cwd, '/').'/'.$pattern;
    }

    private function buildBoundedGlobCommand(string $cwd, string $pattern): string
    {
        $find = $this->buildPrunedFindCommand($cwd, null, false);
        $awk = <<<'AWK'
{
    visited++;
    if (visited > maxVisited) {
        print "__HAOCODE_SEARCH_VISITED_LIMIT__";
        exit 2;
    }
    if ($0 ~ ENVIRON["HAOCODE_SEARCH_PATTERN"]) {
        print $0;
        matches++;
        if (matches >= maxResults) {
            print "__HAOCODE_SEARCH_RESULT_LIMIT__";
            exit 3;
        }
    }
}
AWK;

        return $find
            .' | HAOCODE_SEARCH_PATTERN='.escapeshellarg($this->globToPosixEre($this->findPattern($cwd, $pattern)))
            .' awk -v maxVisited='.self::MAX_SEARCH_VISITED_FILES
            .' -v maxResults='.(self::MAX_SEARCH_RESULTS + 1)
            .' '.escapeshellarg($awk);
    }

    private function buildPrunedFindCommand(string $cwd, ?string $pathPattern, bool $nullDelimited): string
    {
        $pruneTerms = [];
        foreach (self::IGNORED_DIRECTORY_NAMES as $name) {
            $pruneTerms[] = '-name '.escapeshellarg($name);
        }
        foreach (self::IGNORED_DIRECTORY_PATHS as $path) {
            $pruneTerms[] = '-path '.escapeshellarg(rtrim($cwd, '/').'/'.$path);
        }

        $prune = '\\( -type d \\( '.implode(' -o ', $pruneTerms).' \\) -prune \\) -o';
        $command = 'find '.escapeshellarg($cwd).' '.$prune.' -type f';
        if ($pathPattern !== null) {
            $command .= ' -path '.escapeshellarg($pathPattern);
        }

        return $command.' '.($nullDelimited ? '-print0' : '-print');
    }

    /** @return array{0: list<string>, 1: bool, 2: bool} */
    private function parseSearchOutput(string $output): array
    {
        $lines = [];
        $resultLimited = false;
        $visitedLimited = false;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = rtrim($line, "\r");
            if ($line === self::SEARCH_RESULT_LIMIT_MARKER) {
                $resultLimited = true;
            } elseif ($line === self::SEARCH_VISITED_LIMIT_MARKER) {
                $visitedLimited = true;
            } elseif ($line !== '') {
                $lines[] = $line;
            }
        }

        return [$lines, $resultLimited, $visitedLimited];
    }

    private function recordSearchMetadata(
        string $operation,
        bool $resultLimited,
        bool $visitedLimited,
        bool $outputLimited,
        int $resultLimit,
    ): void {
        SandboxSearchMetadata::record($this, [
            'provider' => 'agentrun',
            'operation' => $operation,
            'searchLimited' => $resultLimited || $visitedLimited || $outputLimited,
            'resultLimit' => $resultLimit,
            'resultLimited' => $resultLimited,
            'visitedLimit' => self::MAX_SEARCH_VISITED_FILES,
            'visitedLimited' => $visitedLimited,
            'outputLimited' => $outputLimited,
            'residualDifferences' => [
                'AgentRun search prunes default heavy directories but does not expose a remote filesystem visit count.',
                'AgentRun search returns only the bounded result window; it does not provide the full candidate set after truncation.',
                ...($operation === 'glob'
                    ? ['AgentRun Glob follows remote traversal order rather than Local Glob newest-file ranking.']
                    : ['AgentRun Grep uses the remote grep regular-expression and text-file semantics.']),
            ],
        ]);
    }

    private function normalizeSearchPattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if (str_starts_with($pattern, './')) {
            $pattern = substr($pattern, 2);
        }

        return $pattern === '' ? '*' : $pattern;
    }

    private function matchesSearchPath(string $file, string $cwd, string $pattern): bool
    {
        $pattern = $this->normalizeSearchPattern($pattern);
        if (str_starts_with($pattern, '/')) {
            $candidate = $file;
        } else {
            $prefix = rtrim($cwd, '/').'/';
            $candidate = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
        }

        return preg_match($this->globToPhpRegex($pattern), $candidate) === 1;
    }

    private function globToPhpRegex(string $pattern): string
    {
        $regex = '#^';
        $length = strlen($pattern);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $pattern[$offset];
            if ($character === '*') {
                if ($offset + 1 < $length && $pattern[$offset + 1] === '*') {
                    $offset++;
                    if ($offset + 1 < $length && $pattern[$offset + 1] === '/') {
                        $offset++;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($character, '#');
            }
        }

        return $regex.'$#';
    }

    private function globToPosixEre(string $pattern): string
    {
        $regex = '^';
        $length = strlen($pattern);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $pattern[$offset];
            if ($character === '*') {
                if ($offset + 1 < $length && $pattern[$offset + 1] === '*') {
                    $offset++;
                    if ($offset + 1 < $length && $pattern[$offset + 1] === '/') {
                        $offset++;
                        $regex .= '(.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } elseif (str_contains('.\\+()|^$[]{}', $character)) {
                $regex .= '\\'.$character;
            } else {
                $regex .= $character;
            }
        }

        return $regex.'$';
    }

    private function capExecOutput(string &$output, string $streamName): bool
    {
        if (strlen($output) <= self::MAX_EXEC_OUTPUT_BYTES) {
            return false;
        }

        $output = substr($output, 0, self::MAX_EXEC_OUTPUT_BYTES)
            ."\n\n[{$streamName} truncated at ".self::MAX_EXEC_OUTPUT_BYTES.' bytes]';

        return true;
    }
}
