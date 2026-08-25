<?php

declare(strict_types=1);

namespace HaoCode\Scripts;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class PhpAggregateSizeCheck
{
    public const MAX_LINES = 1500;
    public const MAX_DIRECT_CONCERNS = 4;

    /** @return array{classes: int, issues: list<string>} */
    public static function audit(
        string $projectRoot,
        int $maxLines = self::MAX_LINES,
        int $maxDirectConcerns = self::MAX_DIRECT_CONCERNS,
    ): array {
        $projectRoot = realpath($projectRoot) ?: throw new RuntimeException('Project root does not exist.');
        $appRoot = $projectRoot.'/app';
        if ($maxLines < 1 || $maxDirectConcerns < 0) {
            throw new RuntimeException('Aggregate limits must be non-negative and line limit must be positive.');
        }

        $declarations = [];
        foreach (self::phpFiles($appRoot) as $path) {
            foreach (self::parseDeclarations($path, $projectRoot) as $declaration) {
                $declarations[$declaration['name']] = $declaration;
            }
        }

        $issues = [];
        $classes = 0;
        foreach ($declarations as $name => $declaration) {
            if ($declaration['type'] !== 'class') {
                continue;
            }
            $classes++;
            $directTraits = self::resolveTraits($declaration, $declarations);
            $concerns = array_values(array_filter(
                $directTraits,
                static fn (string $trait): bool => str_ends_with($trait, 'Concern'),
            ));
            if (count($concerns) > $maxDirectConcerns) {
                $issues[] = "{$declaration['path']} uses ".count($concerns)
                    ." direct Concern traits (max {$maxDirectConcerns}).";
            }

            $visited = [];
            $aggregateLines = $declaration['lines'];
            foreach ($directTraits as $trait) {
                $aggregateLines += self::traitLines($trait, $declarations, $visited);
            }
            if ($aggregateLines > $maxLines) {
                $issues[] = "{$declaration['path']} aggregates {$aggregateLines} lines"
                    ." with direct/transitive traits (max {$maxLines}).";
            }
        }

        sort($issues);

        return ['classes' => $classes, 'issues' => $issues];
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
        sort($files);

        return $files;
    }

    /**
     * @return list<array{name: string, namespace: string, type: string, path: string,
     *     lines: int, traits: list<string>}>
     */
    private static function parseDeclarations(string $path, string $projectRoot): array
    {
        $source = file_get_contents($path);
        if (! is_string($source)) {
            throw new RuntimeException("Unable to read {$path}.");
        }
        $tokens = token_get_all($source);
        $namespace = '';
        $depth = 0;
        $current = null;
        $declarations = [];
        $previousSignificant = null;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_NAMESPACE && $current === null) {
                $namespace = self::readName($tokens, $index + 1, $index);
                continue;
            }
            if (is_array($token)
                && in_array($token[0], [T_CLASS, T_TRAIT], true)
                && $current === null
                && $previousSignificant !== T_NEW) {
                $name = self::readName($tokens, $index + 1, $index);
                if ($name !== '') {
                    $current = [
                        'name' => ltrim(($namespace !== '' ? $namespace.'\\' : '').$name, '\\'),
                        'namespace' => $namespace,
                        'type' => $token[0] === T_TRAIT ? 'trait' : 'class',
                        'path' => ltrim(str_replace($projectRoot, '', $path), '/'),
                        'lines' => substr_count($source, "\n") + (str_ends_with($source, "\n") ? 0 : 1),
                        'traits' => [],
                        'bodyDepth' => null,
                    ];
                }
                continue;
            }
            if ($token === '{') {
                $depth++;
                if ($current !== null && $current['bodyDepth'] === null) {
                    $current['bodyDepth'] = $depth;
                }
                $previousSignificant = '{';
                continue;
            }
            if ($token === '}') {
                if ($current !== null && $current['bodyDepth'] === $depth) {
                    unset($current['bodyDepth']);
                    $declarations[] = $current;
                    $current = null;
                }
                $depth--;
                $previousSignificant = '}';
                continue;
            }
            if (is_array($token)
                && $token[0] === T_USE
                && $current !== null
                && $current['bodyDepth'] === $depth) {
                [$traits, $index] = self::readTraitUse($tokens, $index + 1);
                array_push($current['traits'], ...$traits);
                continue;
            }
            if (is_array($token) && ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previousSignificant = $token[0];
            } elseif (! is_array($token) && trim($token) !== '') {
                $previousSignificant = $token;
            }
        }

        return $declarations;
    }

    /** @param array<int, array|string> $tokens */
    private static function readName(array $tokens, int $start, int &$end): string
    {
        $name = '';
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                if ($token[0] !== T_WHITESPACE) {
                    $name .= $token[1];
                }
                continue;
            }
            $end = $index - 1;
            break;
        }

        return trim($name, '\\');
    }

    /** @param array<int, array|string> $tokens @return array{list<string>, int} */
    private static function readTraitUse(array $tokens, int $start): array
    {
        $traits = [];
        $name = '';
        $end = $start;
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;
            if ($text === ',' || $text === ';' || $text === '{') {
                if (trim($name) !== '') {
                    $traits[] = trim($name, " \\t\n\r\0\x0B\\");
                    $name = '';
                }
                $end = $index;
                if ($text !== ',') {
                    break;
                }
                continue;
            }
            if (is_array($token)
                && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                $name .= $text;
            }
        }

        return [$traits, $end];
    }

    /** @return list<string> */
    private static function resolveTraits(array $declaration, array $declarations): array
    {
        $resolved = [];
        foreach ($declaration['traits'] as $reference) {
            $candidates = [
                ltrim($reference, '\\'),
                ltrim($declaration['namespace'].'\\'.$reference, '\\'),
            ];
            $match = null;
            foreach ($candidates as $candidate) {
                if (($declarations[$candidate]['type'] ?? null) === 'trait') {
                    $match = $candidate;
                    break;
                }
            }
            if ($match === null) {
                $suffix = '\\'.ltrim($reference, '\\');
                $matches = array_keys(array_filter(
                    $declarations,
                    static fn (array $item, string $name): bool => $item['type'] === 'trait'
                        && str_ends_with($name, $suffix),
                    ARRAY_FILTER_USE_BOTH,
                ));
                if (count($matches) === 1) {
                    $match = $matches[0];
                }
            }
            if ($match !== null) {
                $resolved[] = $match;
            }
        }

        return array_values(array_unique($resolved));
    }

    private static function traitLines(string $trait, array $declarations, array &$visited): int
    {
        if (isset($visited[$trait]) || ($declarations[$trait]['type'] ?? null) !== 'trait') {
            return 0;
        }
        $visited[$trait] = true;
        $declaration = $declarations[$trait];
        $lines = $declaration['lines'];
        foreach (self::resolveTraits($declaration, $declarations) as $nested) {
            $lines += self::traitLines($nested, $declarations, $visited);
        }

        return $lines;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        $result = PhpAggregateSizeCheck::audit(dirname(__DIR__));
        if ($result['issues'] !== []) {
            fwrite(STDERR, "PHP aggregate size check failed:\n- ".implode("\n- ", $result['issues'])."\n");
            exit(1);
        }
        fwrite(STDOUT, "OK: {$result['classes']} production classes stay within aggregate limits.\n");
    } catch (\Throwable $exception) {
        fwrite(STDERR, "PHP aggregate size check failed: {$exception->getMessage()}\n");
        exit(1);
    }
}
