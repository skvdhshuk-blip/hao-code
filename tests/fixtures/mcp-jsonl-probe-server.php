<?php

declare(strict_types=1);

$startedFile = getenv('HAOCODE_MCP_STARTED_FILE');
if (is_string($startedFile) && $startedFile !== '') {
    touch($startedFile);
}

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode(trim($line), true);
    if (! is_array($message)) {
        continue;
    }

    $method = $message['method'] ?? '';
    if ($method === 'notifications/initialized') {
        continue;
    }

    $protocolVersion = getenv('HAOCODE_MCP_PROTOCOL_VERSION') ?: '2025-11-25';
    $result = match ($method) {
        'initialize' => [
            'protocolVersion' => $protocolVersion,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => getenv('HAOCODE_MCP_SECRET_PROBE') === false ? 'not-inherited' : 'inherited',
                'version' => 'probe',
            ],
        ],
        'tools/list' => [
            'tools' => [[
                'name' => 'echo-value',
                'description' => 'Echoes the supplied value.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['value' => ['type' => 'string']],
                    'required' => ['value'],
                ],
                'annotations' => ['readOnlyHint' => true],
            ]],
        ],
        'tools/call' => [
            'content' => [[
                'type' => 'text',
                'text' => 'echo: '.(string) ($message['params']['arguments']['value'] ?? ''),
            ]],
            'isError' => false,
        ],
        default => null,
    };

    if ($result !== null && array_key_exists('id', $message)) {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES)."\n";
        fflush(STDOUT);
    }
}
