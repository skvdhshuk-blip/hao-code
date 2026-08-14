<?php

declare(strict_types=1);

namespace Tests\Unit;

use HaoCode\Services\Mcp\McpClient;
use HaoCode\Services\Mcp\McpConnectionException;
use HaoCode\Services\Mcp\McpSseDecoder;
use HaoCode\Services\Mcp\McpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait McpStreamableHttpTransportTestTestListToolsFollowsNextCursorPaginationConcern
{

    public function test_list_tools_follows_next_cursor_pagination(): void
    {
        $listCalls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$listCalls): MockResponse {
            $payload = $this->decodeRequestBody($options);
            $rpcMethod = $payload['method'] ?? $method;

            if ($rpcMethod === 'initialize') {
                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['tools' => new \stdClass],
                        'serverInfo' => ['name' => 'fixture', 'version' => '1'],
                    ],
                ], ['Mcp-Session-Id: session-page']);
            }
            if ($rpcMethod === 'notifications/initialized') {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ($method === 'GET') {
                return new MockResponse('', ['http_code' => 405]);
            }
            if ($rpcMethod === 'tools/list') {
                $listCalls[] = $payload['params'] ?? [];
                $cursor = $payload['params']['cursor'] ?? null;
                if ($cursor === null) {
                    return $this->jsonResponse([
                        'jsonrpc' => '2.0',
                        'id' => $payload['id'],
                        'result' => [
                            'tools' => [
                                ['name' => 'tool_a', 'description' => 'A', 'inputSchema' => ['type' => 'object']],
                            ],
                            'nextCursor' => 'page-2',
                        ],
                    ]);
                }
                if ($cursor === 'page-2') {
                    return $this->jsonResponse([
                        'jsonrpc' => '2.0',
                        'id' => $payload['id'],
                        'result' => [
                            'tools' => [
                                ['name' => 'tool_b', 'description' => 'B', 'inputSchema' => ['type' => 'object']],
                            ],
                        ],
                    ]);
                }

                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'error' => ['code' => -32602, 'message' => 'unexpected cursor'],
                ]);
            }

            return new MockResponse('', ['http_code' => 204]);
        });

        $transport = $this->makeTransport($http);
        $client = new McpClient($transport, 'fixture');
        $client->connect();

        $tools = $client->listTools(false, 2);

        $this->assertSame(['tool_a', 'tool_b'], array_column($tools, 'name'));
        $this->assertCount(2, $listCalls);
        $this->assertSame([], $listCalls[0] ?? []);
        $this->assertSame(['cursor' => 'page-2'], $listCalls[1] ?? []);
    }

    public function test_list_tools_rejects_unbounded_page_aggregation(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $payload = $this->decodeRequestBody($options);
            $rpcMethod = $payload['method'] ?? $method;

            if ($rpcMethod === 'initialize') {
                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['tools' => new \stdClass],
                        'serverInfo' => ['name' => 'fixture', 'version' => '1'],
                    ],
                ]);
            }
            if ($rpcMethod === 'notifications/initialized') {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ($method === 'GET') {
                return new MockResponse('', ['http_code' => 405]);
            }
            if ($rpcMethod === 'tools/list') {
                $tools = [];
                for ($i = 0; $i < 10_001; $i++) {
                    $tools[] = [
                        'name' => 'tool-'.$i,
                        'description' => '',
                        'inputSchema' => ['type' => 'object'],
                    ];
                }

                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => $tools],
                ]);
            }

            return new MockResponse('', ['http_code' => 204]);
        });
        $transport = $this->makeTransport($http);
        $client = new McpClient($transport, 'fixture');
        $client->connect();

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('aggregated items');
        $client->listTools(false, 2);
    }

    public function test_list_tools_rejects_unbounded_byte_aggregation(): void
    {
        $page = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$page): MockResponse {
            $payload = $this->decodeRequestBody($options);
            $rpcMethod = $payload['method'] ?? $method;

            if ($rpcMethod === 'initialize') {
                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['tools' => new \stdClass],
                        'serverInfo' => ['name' => 'fixture', 'version' => '1'],
                    ],
                ]);
            }
            if ($rpcMethod === 'notifications/initialized') {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ($method === 'GET') {
                return new MockResponse('', ['http_code' => 405]);
            }
            if ($rpcMethod === 'tools/list') {
                $page++;

                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'tools' => [[
                            'name' => 'large-'.$page,
                            'description' => str_repeat('x', 3_500_000),
                            'inputSchema' => ['type' => 'object'],
                        ]],
                        'nextCursor' => $page < 5 ? 'page-'.$page : null,
                    ],
                ]);
            }

            return new MockResponse('', ['http_code' => 204]);
        });
        $transport = $this->makeTransport($http);
        $client = new McpClient($transport, 'fixture');
        $client->connect();

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('aggregated bytes');
        $client->listTools(false, 10);
    }

    public function test_explicit_authorization_header_is_not_replaced_by_oauth_retry(): void
    {
        $secretName = 'HAOCODE_TEST_MCP_CLIENT_SECRET_'.bin2hex(random_bytes(4));
        putenv($secretName.'=secret-value');
        $requests = [];

        try {
            $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 401]);
            });
            $transport = McpTransport::fromConfig([
                'transport' => 'http',
                'command' => null,
                'args' => [],
                'url' => 'https://mcp.example/mcp',
                'env' => [],
                'headers' => ['Authorization' => 'Bearer explicit-token'],
                'oauth' => [
                    'token_endpoint' => 'https://auth.example/token',
                    'client_id' => 'client-id',
                    'client_secret_env' => $secretName,
                ],
            ], $http);

            try {
                $transport->request('ping');
                $this->fail('Expected the unauthorized response to be reported.');
            } catch (McpConnectionException $exception) {
                $this->assertSame(401, $exception->getCode());
            }

            $this->assertCount(1, $requests);
            $this->assertSame('https://mcp.example/mcp', $requests[0]['url']);
            $this->assertStringContainsString(
                'authorization: bearer explicit-token',
                strtolower(implode("\n", $requests[0]['options']['headers'])),
            );
        } finally {
            putenv($secretName);
        }
    }

    private function makeTransport(MockHttpClient $http): McpTransport
    {
        return McpTransport::fromConfig([
            'transport' => 'http',
            'command' => null,
            'args' => [],
            'url' => 'https://mcp.example/mcp',
            'env' => [],
            'headers' => [],
        ], $http);
    }

    /** @param array<string, mixed> $options */
    private function decodeRequestBody(array $options): array
    {
        $body = $options['body'] ?? '';
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }
        $decoded = json_decode(is_string($body) ? $body : '', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $headers
     */
    private function jsonResponse(array $payload, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => array_merge(['Content-Type: application/json'], $headers),
        ]);
    }
}
