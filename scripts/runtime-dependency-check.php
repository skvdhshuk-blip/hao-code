<?php

declare(strict_types=1);

namespace HaoCode\Scripts;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class RuntimeDependencyCheck
{
    /** @return list<string> */
    public static function audit(string $projectRoot): array
    {
        $projectRoot = realpath($projectRoot) ?: throw new RuntimeException('Project root does not exist.');
        $issues = [];
        foreach ([$projectRoot.'/app/Services', $projectRoot.'/app/Tools'] as $root) {
            foreach (self::phpFiles($root) as $path) {
                $source = file_get_contents($path);
                if (! is_string($source)) {
                    throw new RuntimeException("Unable to read {$path}.");
                }
                array_push($issues, ...self::scan($source, ltrim(str_replace($projectRoot, '', $path), '/')));
            }
        }
        sort($issues);

        return $issues;
    }

    /** @return list<string> */
    private static function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return list<string> */
    private static function scan(string $source, string $path): array
    {
        $tokens = token_get_all($source);
        $issues = [];
        $previous = null;
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token)) {
                if (trim($token) !== '') {
                    $previous = $token;
                }
                continue;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $text = ltrim($token[1], '\\');
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && ($text === 'SdkRuntime' || str_ends_with($text, '\\SdkRuntime'))) {
                $issues[] = "{$path}:{$token[2]} references SdkRuntime below the SDK composition edge.";
            }

            $next = self::nextSignificant($tokens, $index + 1);
            if ($token[0] === T_STRING && $token[1] === 'HaoCode' && $next === T_DOUBLE_COLON) {
                $issues[] = "{$path}:{$token[2]} calls the HaoCode facade below the SDK composition edge.";
            }
            if ($token[0] === T_STRING
                && in_array($token[1], ['app', 'config', 'storage_path'], true)
                && $next === '('
                && ! in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $issues[] = "{$path}:{$token[2]} calls global helper {$token[1]}().";
            }
            $previous = $token[0];
        }

        return $issues;
    }

    private static function nextSignificant(array $tokens, int $start): int|string|null
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[0] : $token;
        }

        return null;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        $issues = RuntimeDependencyCheck::audit(dirname(__DIR__));
        if ($issues !== []) {
            fwrite(STDERR, "Runtime dependency check failed:\n- ".implode("\n- ", $issues)."\n");
            exit(1);
        }
        fwrite(STDOUT, "OK: app/Services and app/Tools do not depend on SDK runtime globals.\n");
    } catch (\Throwable $exception) {
        fwrite(STDERR, "Runtime dependency check failed: {$exception->getMessage()}\n");
        exit(1);
    }
}
