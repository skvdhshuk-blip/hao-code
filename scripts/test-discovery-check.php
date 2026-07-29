<?php

declare(strict_types=1);

namespace HaoCode\Scripts;

use DOMDocument;
use DOMXPath;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class TestDiscoveryCheck
{
    /**
     * @return array{testFiles: int, issues: list<string>}
     */
    public static function audit(string $projectRoot, ?string $configPath = null): array
    {
        $projectRoot = realpath($projectRoot) ?: throw new RuntimeException(
            "Project root does not exist: {$projectRoot}",
        );
        $projectRoot = self::normalizePath($projectRoot);
        $configPath ??= $projectRoot.'/phpunit.xml';

        $document = self::loadConfig($configPath);
        $xpath = new DOMXPath($document);
        $includedPaths = self::configuredPaths(
            $xpath,
            '/phpunit/testsuites/testsuite/directory | /phpunit/testsuites/testsuite/file',
            $projectRoot,
        );
        $excludedPaths = self::configuredPaths(
            $xpath,
            '/phpunit/testsuites/testsuite/exclude',
            $projectRoot,
        );

        $issues = [];
        foreach ($includedPaths as $path) {
            if (! file_exists($path)) {
                $issues[] = 'Configured test path does not exist: '.self::relativePath($path, $projectRoot);
            }
        }

        $testFiles = self::discoverTestFiles($projectRoot.'/tests');
        foreach ($testFiles as $testFile) {
            if (! self::matchesAnyPath($testFile, $includedPaths)) {
                $issues[] = 'Test file is not covered by phpunit.xml: '
                    .self::relativePath($testFile, $projectRoot);
                continue;
            }

            if (self::matchesAnyPath($testFile, $excludedPaths)
                && ! self::declaresAbstractTestClass($testFile)) {
                $issues[] = 'Concrete test file is excluded by phpunit.xml: '
                    .self::relativePath($testFile, $projectRoot);
            }
        }

        sort($issues);

        return [
            'testFiles' => count($testFiles),
            'issues' => $issues,
        ];
    }

    private static function loadConfig(string $configPath): DOMDocument
    {
        if (! is_file($configPath)) {
            throw new RuntimeException("PHPUnit configuration does not exist: {$configPath}");
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->load($configPath, LIBXML_NONET)) {
                throw new RuntimeException("Invalid PHPUnit XML configuration: {$configPath}");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * @return list<string>
     */
    private static function configuredPaths(
        DOMXPath $xpath,
        string $query,
        string $projectRoot,
    ): array {
        $paths = [];
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            throw new RuntimeException("Unable to inspect PHPUnit configuration with XPath: {$query}");
        }

        foreach ($nodes as $node) {
            $path = trim($node->textContent);
            if ($path === '') {
                continue;
            }
            $paths[] = self::absolutePath($path, $projectRoot);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private static function discoverTestFiles(string $testsPath): array
    {
        if (! is_dir($testsPath)) {
            throw new RuntimeException("Tests directory does not exist: {$testsPath}");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testsPath, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = self::normalizePath($file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $configuredPaths
     */
    private static function matchesAnyPath(string $testFile, array $configuredPaths): bool
    {
        foreach ($configuredPaths as $configuredPath) {
            if ($testFile === $configuredPath
                || str_starts_with($testFile, rtrim($configuredPath, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private static function declaresAbstractTestClass(string $testFile): bool
    {
        $source = file_get_contents($testFile);
        if ($source === false) {
            throw new RuntimeException("Unable to read test file: {$testFile}");
        }

        $abstract = false;
        $previousToken = null;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_ABSTRACT) {
                $abstract = true;
                $previousToken = T_ABSTRACT;
                continue;
            }

            if (is_array($token) && $token[0] === T_CLASS && $previousToken !== T_DOUBLE_COLON) {
                return $abstract;
            }

            $previousToken = is_array($token) ? $token[0] : $token;
        }

        return false;
    }

    private static function absolutePath(string $path, string $projectRoot): string
    {
        if (! self::isAbsolutePath($path)) {
            $path = $projectRoot.'/'.$path;
        }

        return self::normalizePath($path);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private static function normalizePath(string $path): string
    {
        $resolved = realpath($path);
        $path = $resolved !== false ? $resolved : $path;

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function relativePath(string $path, string $projectRoot): string
    {
        $prefix = rtrim(self::normalizePath($projectRoot), '/').'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        $result = TestDiscoveryCheck::audit(dirname(__DIR__));
        if ($result['issues'] !== []) {
            fwrite(STDERR, "Test discovery check failed:\n- ".implode("\n- ", $result['issues'])."\n");
            exit(1);
        }

        fwrite(
            STDOUT,
            "OK: {$result['testFiles']} *Test.php files are covered by phpunit.xml.\n",
        );
    } catch (\Throwable $exception) {
        fwrite(STDERR, "Test discovery check failed: {$exception->getMessage()}\n");
        exit(1);
    }
}
