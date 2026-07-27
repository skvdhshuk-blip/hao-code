<?php

namespace HaoCode\Sdk\Sandbox;

/** @api */
interface SandboxBackendInterface
{
    /** @return array{exists: bool, isFile?: bool, isDir?: bool, size?: int, mtime?: int} */
    public function stat(string $path): array;

    public function readFile(string $path): string;

    public function writeFile(string $path, string $content): void;

    public function delete(string $path): void;

    /** @return string[] */
    public function list(string $path): array;

    /** @return string[] */
    public function glob(string $pattern, ?string $path = null): array;

    /** @return array<int, array{file: string, line: int, text: string}> */
    public function grep(string $pattern, ?string $path = null, ?string $glob = null, bool $caseInsensitive = false, int $limit = 250): array;

    /**
     * @param  callable(): bool|null  $shouldAbort
     * @return array{stdout: string, stderr: string, exitCode: int, timedOut: bool, aborted?: bool}
     */
    public function exec(string $command, ?string $cwd = null, int $timeoutMs = 120000, ?callable $shouldAbort = null): array;

    public function upload(string $localPath, string $remotePath): void;

    public function download(string $remotePath, string $localPath): void;

    public function close(): void;

    public function rootLabel(): string;
}
