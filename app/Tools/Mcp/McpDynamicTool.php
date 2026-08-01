<?php

namespace HaoCode\Tools\Mcp;

use HaoCode\Contracts\ToolInterface;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpConnectionManager;
use HaoCode\Services\Permissions\PermissionDecision;
use HaoCode\Tools\ToolInputSchema;
use HaoCode\Tools\ToolResult;
use HaoCode\Tools\ToolUseContext;

/**
 * A dynamically generated tool that wraps a single tool from an MCP server.
 * One instance is created per MCP server tool during discovery.
 *
 * Tool name follows the pattern: mcp__<server>__<tool>
 */
final class McpDynamicTool implements ToolInterface
{
    private const MAX_FORMATTED_OUTPUT_BYTES = 100_000;

    private readonly ToolInputSchema $schema;

    public function __construct(
        private readonly string $qualifiedName,
        private readonly string $serverName,
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly array $inputJsonSchema,
        private readonly array $annotations,
        private readonly McpConnectionManager $connectionManager,
    ) {
        $this->schema = ToolInputSchema::make($this->inputJsonSchema);
    }

    public function name(): string
    {
        return $this->qualifiedName;
    }

    public function description(): string
    {
        $desc = $this->toolDescription;
        if (mb_strlen($desc) > 2048) {
            $desc = mb_substr($desc, 0, 2045) . '...';
        }
        return $desc;
    }

    public function inputSchema(): ToolInputSchema
    {
        return $this->schema;
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $client = $this->connectionManager->getClient($this->serverName);

        if ($client === null || !$client->isConnected()) {
            // Try to reconnect
            try {
                $client = $this->connectionManager->connectByName($this->serverName);
            } catch (McpConnectionException $e) {
                return ToolResult::error("MCP server '{$this->serverName}' is not connected: {$e->getMessage()}");
            }
        }

        try {
            $result = $client->callTool($this->toolName, $input);
        } catch (McpConnectionException $e) {
            return ToolResult::error("MCP tool call failed: {$e->getMessage()}");
        }

        $output = $this->formatMcpResult($result);

        if (($result['isError'] ?? false) === true) {
            return ToolResult::error($output);
        }

        return ToolResult::success($output);
    }

    public function isConcurrencySafe(array $input): bool
    {
        return $this->isReadOnly($input);
    }

    public function isReadOnly(array $input): bool
    {
        // Check MCP annotations for readOnlyHint
        if (isset($this->annotations['readOnlyHint'])) {
            return (bool) $this->annotations['readOnlyHint'];
        }
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function userFacingName(array $input): string
    {
        $title = $this->annotations['title'] ?? $this->toolName;
        return "{$this->serverName} - {$title} (MCP)";
    }

    public function checkPermissions(array $input, ToolUseContext $context): PermissionDecision
    {
        // MCP tools always require user approval unless explicitly allowed
        return PermissionDecision::ask("MCP tool: {$this->userFacingName($input)}");
    }

    public function validateInput(array $input, ToolUseContext $context): ?string
    {
        return null;
    }

    public function maxResultSizeChars(): int
    {
        return 50000;
    }

    public function backfillObservableInput(array $input, ToolUseContext $context): array
    {
        return $input;
    }

    public function getActivityDescription(array $input): ?string
    {
        return "Calling {$this->serverName}/{$this->toolName}";
    }

    public function isSearchOrReadCommand(array $input): array
    {
        return [
            'isSearch' => false,
            'isRead' => $this->isReadOnly($input),
            'isList' => false,
        ];
    }

    /**
     * Format MCP result content blocks into a string.
     */
    private function formatMcpResult(array $result): string
    {
        $content = $result['content'] ?? [];

        if (! is_array($content) || $content === []) {
            return '(empty response)';
        }

        $output = '';
        $truncated = false;
        foreach ($content as $block) {
            $part = $this->formatContentBlock($block);
            $separator = $output === '' ? '' : "\n";
            $remaining = self::MAX_FORMATTED_OUTPUT_BYTES - strlen($output);
            if ($remaining <= 0) {
                $truncated = true;
                break;
            }

            $piece = $separator.$part;
            if (strlen($piece) > $remaining) {
                $output .= substr($piece, 0, $remaining);
                $truncated = true;
                break;
            }
            $output .= $piece;
        }

        if ($truncated) {
            $marker = "\n[MCP result truncated at ".self::MAX_FORMATTED_OUTPUT_BYTES.' bytes]';
            $room = self::MAX_FORMATTED_OUTPUT_BYTES - strlen($marker);
            $output = substr($output, 0, max(0, $room)).$marker;
        }

        return $output;
    }

    private function formatContentBlock(mixed $block): string
    {
        if (! is_array($block)) {
            return $this->stringifyValue($block);
        }

        $type = is_string($block['type'] ?? null) ? $block['type'] : 'text';

        return match ($type) {
            'text' => $this->stringifyValue($block['text'] ?? ''),
            'image' => '[Image: '.$this->stringifyValue($block['mimeType'] ?? 'unknown')
                .', '.(is_string($block['data'] ?? null) ? strlen($block['data']) : 0).' bytes]',
            'resource' => $this->formatResourceContent($block),
            default => $this->stringifyValue($block),
        };
    }

    private function formatResourceContent(array $block): string
    {
        $resource = $block['resource'] ?? [];
        if (! is_array($resource)) {
            return 'Resource [unknown]';
        }
        $uri = $this->stringifyValue($resource['uri'] ?? 'unknown');
        $text = $resource['text'] ?? null;
        $blob = $resource['blob'] ?? null;

        if ($text !== null) {
            return "Resource [{$uri}]:\n".$this->stringifyValue($text);
        }
        if ($blob !== null) {
            return "Resource [{$uri}]: binary data ("
                .(is_string($blob) ? strlen($blob) : 0).' bytes)';
        }
        return "Resource [{$uri}]";
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null || is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($encoded) ? $encoded : '[unrepresentable MCP value]';
    }
}
