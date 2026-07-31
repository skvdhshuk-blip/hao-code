<?php

namespace HaoCode\Services\Lsp;

/**
 * Basic LSP client that communicates with language servers via stdio.
 * Supports: goToDefinition, findReferences, hover, documentSymbol.
 */
class LspClient
{
    private static array $servers = [];

    /**
     * Get or create an LSP server process.
     */
    public static function getServer(string $language): ?LspServerProcess
    {
        if (isset(self::$servers[$language])) {
            return self::$servers[$language];
        }

        $command = self::getServerCommand($language);
        if ($command === null) {
            return null;
        }

        $server = new LspServerProcess($command);
        if ($server->initialize(getcwd())) {
            self::$servers[$language] = $server;
            return $server;
        }

        return null;
    }

    /**
     * Map language to LSP server command.
     */
    private static function getServerCommand(string $language): ?string
    {
        return match ($language) {
            'typescript', 'javascript', 'ts', 'js' => self::findCommand(['typescript-language-server --stdio', 'npx typescript-language-server --stdio']),
            'php' => self::findCommand(['phpactor', 'phan']),
            'python', 'py' => self::findCommand(['pylsp', 'pyright-langserver --stdio', 'pyright']),
            'go' => self::findCommand(['gopls']),
            'rust' => self::findCommand(['rust-analyzer']),
            'java' => self::findCommand(['jdtls']),
            default => null,
        };
    }

    private static function findCommand(array $commands): ?string
    {
        foreach ($commands as $cmd) {
            $binary = explode(' ', $cmd)[0];
            if (self::isExecutableOnPath($binary)) {
                return $cmd;
            }
        }
        return null;
    }

    private static function isExecutableOnPath(string $binary): bool
    {
        if ($binary === '' || str_contains($binary, "\0")) {
            return false;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary);
        }

        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return false;
        }

        $extensions = [''];
        if (PHP_OS_FAMILY === 'Windows') {
            $pathext = getenv('PATHEXT');
            $extensions = array_filter(explode(';', is_string($pathext) && $pathext !== '' ? $pathext : '.COM;.EXE;.BAT;.CMD'));
            array_unshift($extensions, '');
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary.$extension;
                if (is_file($candidate) && is_executable($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect language from file extension.
     */
    public static function detectLanguage(string $filePath): string
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        return match ($ext) {
            'ts', 'tsx' => 'typescript',
            'js', 'jsx', 'mjs' => 'javascript',
            'php' => 'php',
            'py', 'pyi', 'pyw' => 'python',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'c', 'h' => 'c',
            'cpp', 'cc', 'cxx', 'hpp' => 'cpp',
            'rb' => 'ruby',
            'swift' => 'swift',
            'kt' => 'kotlin',
            'scala' => 'scala',
            default => 'unknown',
        };
    }

    /**
     * Shutdown all server processes.
     */
    public static function shutdownAll(): void
    {
        foreach (self::$servers as $server) {
            $server->shutdown();
        }
        self::$servers = [];
    }
}
