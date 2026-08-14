<?php

declare(strict_types=1);

namespace HaoCode\Support\Filesystem;

/**
 * Single bounded line-window contract for host and sandbox text reads.
 *
 * @internal
 */
final class BoundedTextFileReader
{
    public const MAX_LINE_BYTES = 1_000_000;
    public const MAX_OUTPUT_BYTES = 1_000_000;
    public const MAX_OFFSET = 1_000_000;
    public const MAX_LIMIT = 10_000;

    /**
     * @param  callable(): bool|null  $shouldAbort
     * @return array{selectedLines?: list<string>, totalLines?: int, size?: int, sha256?: string, device?: int, inode?: int, mtime?: int, error?: string, aborted?: bool}
     */
    public static function readPath(
        string $physicalPath,
        string $displayPath,
        int $offset,
        int $limit,
        ?callable $shouldAbort = null,
    ): array {
        if (($error = self::boundsError($offset, $limit)) !== null) {
            return $error;
        }

        $handle = @fopen($physicalPath, 'rb');
        if (! is_resource($handle)) {
            return self::readFailure($displayPath);
        }

        $stat = @fstat($handle);
        if (! is_array($stat)) {
            fclose($handle);

            return self::readFailure($displayPath);
        }

        try {
            $result = self::readHandle($handle, $displayPath, $offset, $limit, $shouldAbort);
        } finally {
            fclose($handle);
        }

        if (isset($result['error']) || ($result['aborted'] ?? false) === true) {
            return $result;
        }

        $result['device'] = (int) ($stat['dev'] ?? 0);
        $result['inode'] = (int) ($stat['ino'] ?? 0);
        $result['mtime'] = (int) ($stat['mtime'] ?? 0);

        return $result;
    }

    /**
     * @param  resource  $handle
     * @param  callable(): bool|null  $shouldAbort
     * @return array{selectedLines?: list<string>, totalLines?: int, size?: int, sha256?: string, error?: string, aborted?: bool}
     */
    public static function readHandle(
        $handle,
        string $displayPath,
        int $offset,
        int $limit,
        ?callable $shouldAbort = null,
    ): array {
        if (($error = self::boundsError($offset, $limit)) !== null) {
            return $error;
        }
        if (! is_resource($handle)) {
            return self::readFailure($displayPath);
        }

        return self::readChunks(
            static function () use ($handle): string|false|null {
                if (feof($handle)) {
                    return null;
                }

                return fread($handle, 64 * 1024);
            },
            $displayPath,
            $offset,
            $limit,
            $shouldAbort,
        );
    }

    /**
     * @param  callable(): bool|null  $shouldAbort
     * @return array{selectedLines?: list<string>, totalLines?: int, size?: int, sha256?: string, error?: string, aborted?: bool}
     */
    public static function readString(
        string $content,
        string $displayPath,
        int $offset,
        int $limit,
        ?callable $shouldAbort = null,
    ): array {
        if (($error = self::boundsError($offset, $limit)) !== null) {
            return $error;
        }

        $position = 0;
        $length = strlen($content);

        return self::readChunks(
            static function () use ($content, $length, &$position): ?string {
                if ($position >= $length) {
                    return null;
                }

                $chunk = substr($content, $position, 64 * 1024);
                $position += strlen($chunk);

                return $chunk;
            },
            $displayPath,
            $offset,
            $limit,
            $shouldAbort,
        );
    }

    /**
     * @param  callable(): (string|false|null)  $nextChunk
     * @param  callable(): bool|null  $shouldAbort
     * @return array{selectedLines?: list<string>, totalLines?: int, size?: int, sha256?: string, error?: string, aborted?: bool}
     */
    private static function readChunks(
        callable $nextChunk,
        string $displayPath,
        int $offset,
        int $limit,
        ?callable $shouldAbort,
    ): array {
        $selected = [];
        $lineNumber = 0;
        $buffer = '';
        $hash = hash_init('sha256');
        $size = 0;
        $selectedBytes = 0;

        while (true) {
            if ($shouldAbort !== null && $shouldAbort()) {
                return ['aborted' => true];
            }

            $chunk = $nextChunk();
            if ($chunk === null) {
                break;
            }
            if ($chunk === false) {
                return self::readFailure($displayPath);
            }
            if ($chunk === '') {
                continue;
            }

            hash_update($hash, $chunk);
            $size += strlen($chunk);
            $buffer .= $chunk;

            if (strlen($buffer) > self::MAX_LINE_BYTES
                && preg_match('/\r\n|\n|\r/', $buffer) !== 1) {
                return self::lineTooLong($displayPath);
            }

            while (preg_match('/\r\n|\n|\r/', $buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                $line = substr($buffer, 0, (int) $match[0][1]);
                if (strlen($line) > self::MAX_LINE_BYTES) {
                    return self::lineTooLong($displayPath);
                }

                $lineNumber++;
                if ($lineNumber >= $offset && count($selected) < $limit) {
                    $nextSelectedBytes = $selectedBytes + strlen($line);
                    if ($nextSelectedBytes > self::MAX_OUTPUT_BYTES) {
                        return self::outputTooLarge($displayPath);
                    }
                    $selected[] = $line;
                    $selectedBytes = $nextSelectedBytes;
                }

                $buffer = substr($buffer, (int) $match[0][1] + strlen($match[0][0]));
            }
        }

        if ($buffer !== '') {
            if (strlen($buffer) > self::MAX_LINE_BYTES) {
                return self::lineTooLong($displayPath);
            }

            $lineNumber++;
            if ($lineNumber >= $offset && count($selected) < $limit) {
                $nextSelectedBytes = $selectedBytes + strlen($buffer);
                if ($nextSelectedBytes > self::MAX_OUTPUT_BYTES) {
                    return self::outputTooLarge($displayPath);
                }
                $selected[] = $buffer;
            }
        }

        return [
            'selectedLines' => $selected,
            'totalLines' => $lineNumber,
            'size' => $size,
            'sha256' => hash_final($hash),
        ];
    }

    /** @return array{error: string}|null */
    private static function boundsError(int $offset, int $limit): ?array
    {
        if ($offset < 1 || $offset > self::MAX_OFFSET) {
            return [
                'error' => 'offset must be between 1 and '.self::MAX_OFFSET.'.',
            ];
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            return [
                'error' => 'limit must be between 1 and '.self::MAX_LIMIT.'.',
            ];
        }

        return null;
    }

    /** @return array{error: string} */
    private static function readFailure(string $displayPath): array
    {
        return [
            'error' => "Failed to read file: {$displayPath}",
        ];
    }

    /** @return array{error: string} */
    private static function lineTooLong(string $displayPath): array
    {
        return [
            'error' => 'Line exceeds '.self::MAX_LINE_BYTES." bytes in {$displayPath}. "
                .'Use a more specialized byte-range workflow for extremely long lines.',
        ];
    }

    /** @return array{error: string} */
    private static function outputTooLarge(string $displayPath): array
    {
        return [
            'error' => 'Read output exceeds '.self::MAX_OUTPUT_BYTES." bytes in {$displayPath}. "
                .'Use a smaller limit or a later offset.',
        ];
    }
}
