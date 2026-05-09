<?php

namespace HaoCode\Sdk\AgentRun\Tools;

use HaoCode\Sdk\AgentRun\AgentRunSandboxClient;
use HaoCode\Tools\BaseTool;
use HaoCode\Tools\ToolUseContext;

abstract class AgentRunSandboxTool extends BaseTool
{
    public function __construct(protected readonly AgentRunSandboxClient $client) {}

    protected function remoteCwd(ToolUseContext $context): string
    {
        return $context->workingDirectory !== '' ? $context->workingDirectory : $this->client->config()->remoteCwd;
    }

    protected function resolveRemotePath(string $path, ToolUseContext $context): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }
        if (str_starts_with($path, '~/')) {
            return '/home/user/'.substr($path, 2);
        }
        if (str_starts_with($path, '/')) {
            return $this->normalizeRemotePath($path);
        }

        return $this->normalizeRemotePath(rtrim($this->remoteCwd($context), '/').'/'.$path);
    }

    protected function normalizeRemotePath(string $path): string
    {
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

    protected function shellQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    protected function formatCommandResult(array $result): string
    {
        $stdout = $this->stringValue($result, ['stdout', 'output', 'data.stdout', 'result.stdout']);
        $stderr = $this->stringValue($result, ['stderr', 'error', 'data.stderr', 'result.stderr']);
        $exitCode = $this->scalarValue($result, ['exitCode', 'exit_code', 'code', 'data.exitCode', 'result.exitCode']);

        $out = '';
        if ($stdout !== '') {
            $out .= $stdout;
        }
        if ($stderr !== '') {
            $out .= ($out !== '' ? "\n" : '').$stderr;
        }
        if ($out === '') {
            $out = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        if ($exitCode !== null) {
            $out .= "\n[exit_code: {$exitCode}]";
        }

        return trim($out);
    }

    /** @param string[] $paths */
    protected function stringValue(array $data, array $paths): string
    {
        $value = $this->scalarValue($data, $paths);

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param string[] $paths */
    protected function scalarValue(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }
}
