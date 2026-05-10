<?php

namespace HaoCode\Sdk\Sandbox\Tools;

use HaoCode\Sdk\Sandbox\SandboxRuntime;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolUseContext;

abstract class SandboxTool extends BaseTool
{
    public function __construct(protected readonly SandboxRuntime $runtime) {}

    protected function resolveRemotePath(string $path, ToolUseContext $context): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->runtime->config->remoteCwd;
        }
        if (str_starts_with($path, '~/')) {
            $path = '/home/user/'.substr($path, 2);
        } elseif ($path === '~') {
            $path = '/home/user';
        }
        if (! str_starts_with($path, '/')) {
            $path = rtrim($context->workingDirectory, '/').'/'.$path;
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

        return '/'.implode('/', $parts);
    }
}
