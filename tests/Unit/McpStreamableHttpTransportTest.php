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

final class McpStreamableHttpTransportTest extends TestCase
{
    public function test_sse_decoder_handles_chunk_boundaries_crlf_multiline_data_and_retry(): void
    {
        $decoder = new McpSseDecoder(4096);

        $this->assertSame([], $decoder->push("id: event-1\r"));
        $events = $decoder->push("\nretry: 25\r\ndata: first\r\ndata: second\r\n\r\n");

        $this->assertSame([[
            'data' => "first\nsecond",
            'id' => 'event-1',
            'retry' => 25,
            'event' => null,
        ]], $events);
    }

    public function test_sse_decoder_rejects_oversized_unterminated_line_before_buffering_it(): void
    {
        $decoder = new McpSseDecoder(32);

        $this->expectException(McpConnectionException::class);
        $decoder->push(str_repeat('x', 33));
    }

    public function test_sse_decoder_counts_event_metadata_toward_buffer_limit(): void
    {
        $decoder = new McpSseDecoder(64);

        $this->expectException(McpConnectionException::class);
        $decoder->push("event: ".str_repeat('x', 65)."\n");
    }

    public function test_sse_decoder_counts_event_id_metadata_toward_buffer_limit(): void
    {
        $decoder = new McpSseDecoder(64);

        $this->expectException(McpConnectionException::class);
        $decoder->push("id: ".str_repeat('x', 65)."\n");
    }

    public function test_sse_decoder_counts_multiline_data_toward_buffer_limit(): void
    {
        $decoder = new McpSseDecoder(64);

        $this->expectException(McpConnectionException::class);
        $decoder->push("data: ".str_repeat('x', 40)."\ndata: ".str_repeat('y', 40)."\n");
    }

    public function test_post_sse_dispatches_notification_and_answers_reverse_request(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $payload = $this->decodeRequestBody($options);
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options, 'payload' => $payload];

            if (($payload['method'] ?? null) === 'tools/call') {
                return new MockResponse([
                    "id: event-1\nretry: 0\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\",\"params\":{\"progress\":1}}\n\n",
                    "data: {\"jsonrpc\":\"2.0\",\"id\":\"server-1\",\"method\":\"roots/list\",\"params\":{}}\n\n",
                    "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":true}}\n\n",
                ], [
                    'http_code' => 200,
                    'response_headers' => ['Content-Type: text/event-stream'],
                ]);
            }

            return new MockResponse('', ['http_code' => 202]);
        });
        $transport = $this->makeTransport($http);
        $progress = [];
        $transport->onNotification('notifications/progress', function (array $params) use (&$progress): void {
            $progress[] = $params;
        });
        $transport->onRequest('roots/list', fn (array $params): array => ['roots' => [['uri' => 'file:///tmp']]]);

        $result = $transport->request('tools/call', ['name' => 'demo']);

        $this->assertSame(['ok' => true], $result);
        $this->assertSame([['progress' => 1]], $progress);
        $this->assertCount(2, $requests);
        $this->assertSame('server-1', $requests[1]['payload']['id']);
        $this->assertSame('file:///tmp', $requests[1]['payload']['result']['roots'][0]['uri']);
    }

    public function test_request_preserves_fractional_remaining_timeout(): void
    {
        $capturedTimeout = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedTimeout): MockResponse {
            $capturedTimeout = $options['timeout'] ?? null;
            $payload = $this->decodeRequestBody($options);

            return $this->jsonResponse([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => ['ok' => true],
            ]);
        });
        $transport = $this->makeTransport($http);

        $this->assertSame(['ok' => true], $transport->request('tools/list', [], 0.25));
        $this->assertIsFloat($capturedTimeout);
        $this->assertLessThanOrEqual(0.25, $capturedTimeout);
        $this->assertGreaterThanOrEqual(0.001, $capturedTimeout);
    }

    public function test_http_json_response_is_bounded_before_decode(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            str_repeat('x', 4 * 1024 * 1024 + 1),
            [
                'http_code' => 200,
                'response_headers' => ['Content-Type: application/json'],
            ],
        ));
        $transport = $this->makeTransport($http);

        try {
            $transport->request('tools/list');
            $this->fail('Expected oversized HTTP response to be rejected.');
        } catch (McpConnectionException $exception) {
            $this->assertStringContainsString('exceeded', $exception->getMessage());
        }
    }

    public function test_sse_request_resumes_with_last_event_id(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'options' => $options];

            if ($method === 'POST') {
                return new MockResponse([
                    "id: cursor-1\nretry: 0\ndata:\n\n",
                ], [
                    'http_code' => 200,
                    'response_headers' => ['Content-Type: text/event-stream'],
                ]);
            }

            return new MockResponse([
                "id: cursor-2\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"resumed\":true}}\n\n",
            ], [
                'http_code' => 200,
                'response_headers' => ['Content-Type: text/event-stream'],
            ]);
        });
        $transport = $this->makeTransport($http);

        $result = $transport->request('tools/list');

        $this->assertSame(['resumed' => true], $result);
        $this->assertSame('GET', $requests[1]['method']);
        $this->assertStringContainsString(
            'last-event-id: cursor-1',
            strtolower(implode("\n", $requests[1]['options']['headers'])),
        );
    }

    public function test_client_reinitializes_session_before_retrying_original_request(): void
    {
        $methods = [];
        $initializeCount = 0;
        $toolsCount = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$methods, &$initializeCount, &$toolsCount): MockResponse {
            $payload = $this->decodeRequestBody($options);
            $rpcMethod = $payload['method'] ?? $method;
            $methods[] = $rpcMethod;

            if ($rpcMethod === 'initialize') {
                $initializeCount++;

                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['tools' => new \stdClass],
                        'serverInfo' => ['name' => 'fixture', 'version' => '1'],
                    ],
                ], ['Mcp-Session-Id: session-'.$initializeCount]);
            }
            if ($rpcMethod === 'notifications/initialized') {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ($method === 'GET') {
                return new MockResponse('', ['http_code' => 405]);
            }
            if ($rpcMethod === 'tools/list') {
                $toolsCount++;
                if ($toolsCount === 1) {
                    return new MockResponse('', ['http_code' => 404]);
                }

                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => [['name' => 'demo', 'inputSchema' => ['type' => 'object']]]],
                ]);
            }

            return new MockResponse('', ['http_code' => 204]);
        });
        $transport = $this->makeTransport($http);
        $client = new McpClient($transport, 'fixture');
        $client->connect();

        $tools = $client->listTools(false, 1);

        $this->assertSame('demo', $tools[0]['name']);
        $this->assertSame(2, $initializeCount);
        $this->assertSame(2, $toolsCount);
        $this->assertSame([
            'initialize',
            'notifications/initialized',
            'GET',
            'tools/list',
            'initialize',
            'notifications/initialized',
            'GET',
            'tools/list',
        ], $methods);
    }

    public function test_get_stream_reconnects_with_last_event_id_and_close_sends_delete(): void
    {
        $requests = [];
        $getCount = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$getCount): MockResponse {
            $payload = $this->decodeRequestBody($options);
            $requests[] = ['method' => $method, 'options' => $options, 'payload' => $payload];

            if (($payload['method'] ?? null) === 'initialize') {
                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => new \stdClass,
                        'serverInfo' => ['name' => 'fixture', 'version' => '1'],
                    ],
                ], ['Mcp-Session-Id: session-1']);
            }
            if (($payload['method'] ?? null) === 'notifications/initialized') {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ($method === 'GET') {
                $getCount++;

                return new MockResponse([
                    "id: event-{$getCount}\nretry: 0\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/message\",\"params\":{\"value\":{$getCount}}}\n\n",
                    "data: {\"jsonrpc\":\"2.0\",\"id\":\"server-{$getCount}\",\"method\":\"roots/list\",\"params\":{}}\n\n",
                ], [
                    'http_code' => 200,
                    'response_headers' => ['Content-Type: text/event-stream'],
                ]);
            }
            if (isset($payload['id'], $payload['result'])) {
                return new MockResponse('', ['http_code' => 202]);
            }

            return new MockResponse('', ['http_code' => 405]);
        });
        $transport = $this->makeTransport($http);
        $messages = [];
        $transport->onNotification('notifications/message', function (array $params) use (&$messages): void {
            $messages[] = $params['value'];
        });
        $client = new McpClient($transport, 'fixture');
        $client->connect(1);

        $client->poll(0.01);
        $client->poll(0.01);
        $client->close();

        $this->assertSame([1], $messages);
        $getRequests = array_values(array_filter($requests, fn (array $request): bool => $request['method'] === 'GET'));
        $this->assertCount(2, $getRequests);
        $this->assertStringContainsString(
            'last-event-id: event-1',
            strtolower(implode("\n", $getRequests[1]['options']['headers'])),
        );
        $this->assertGreaterThan(0.0, $getRequests[0]['options']['timeout']);
        $this->assertLessThanOrEqual(1.0, $getRequests[0]['options']['timeout']);
        $this->assertSame(86400.0, $getRequests[0]['options']['max_duration']);
        $initializedRequests = array_values(array_filter(
            $requests,
            fn (array $request): bool => ($request['payload']['method'] ?? null) === 'notifications/initialized',
        ));
        $this->assertCount(1, $initializedRequests);
        $this->assertGreaterThan(0.0, $initializedRequests[0]['options']['timeout']);
        $this->assertLessThanOrEqual(1.0, $initializedRequests[0]['options']['timeout']);
        $reverseResponses = array_values(array_filter(
            $requests,
            fn (array $request): bool => $request['method'] === 'POST'
                && isset($request['payload']['id'], $request['payload']['result']),
        ));
        $this->assertCount(1, $reverseResponses);
        $this->assertSame('server-1', $reverseResponses[0]['payload']['id']);
        $delete = array_values(array_filter($requests, fn (array $request): bool => $request['method'] === 'DELETE'));
        $this->assertCount(1, $delete);
        $deleteHeaders = strtolower(implode("\n", $delete[0]['options']['headers']));
        $this->assertStringContainsString('mcp-session-id: session-1', $deleteHeaders);
        $this->assertStringContainsString('mcp-protocol-version: 2025-11-25', $deleteHeaders);
    }

    public function test_oauth_client_credentials_refreshes_after_unauthorized(): void
    {
        $secretName = 'HAOCODE_TEST_MCP_CLIENT_SECRET_'.bin2hex(random_bytes(4));
        $tokenName = 'HAOCODE_TEST_MCP_ACCESS_TOKEN_'.bin2hex(random_bytes(4));
        putenv($secretName.'=secret-value');
        putenv($tokenName.'=stale-token');
        $requests = [];
        $mcpAttempts = 0;
        try {
            $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$mcpAttempts): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
                if ($url === 'https://auth.example/token') {
                    return new MockResponse('{"access_token":"fresh-token","token_type":"Bearer","expires_in":3600}', [
                        'http_code' => 200,
                        'response_headers' => ['Content-Type: application/json'],
                    ]);
                }

                $mcpAttempts++;
                if ($mcpAttempts === 1) {
                    return new MockResponse('', ['http_code' => 401]);
                }
                $payload = $this->decodeRequestBody($options);

                return $this->jsonResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['ok' => true],
                ]);
            });
            $transport = McpTransport::fromConfig([
                'transport' => 'http',
                'command' => null,
                'args' => [],
                'url' => 'https://mcp.example/mcp',
                'env' => [],
                'headers' => [],
                'oauth' => [
                    'token_endpoint' => 'https://auth.example/token',
                    'client_id' => 'client-id',
                    'client_secret_env' => $secretName,
                    'access_token_env' => $tokenName,
                ],
            ], $http);

            $result = $transport->request('ping');

            $this->assertSame(['ok' => true], $result);
            $this->assertCount(3, $requests);
            $this->assertStringContainsString(
                'authorization: bearer fresh-token',
                strtolower(implode("\n", $requests[2]['options']['headers'])),
            );
        } finally {
            putenv($secretName);
            putenv($tokenName);
        }
    }

    public function test_optional_get_stream_connection_failure_does_not_fail_startup(): void
    {
        $http = new MockHttpClient(function (string $method): MockResponse {
            if ($method === 'GET') {
                throw new \RuntimeException('GET stream unavailable');
            }

            return new MockResponse('', ['http_code' => 202]);
        });
        $transport = $this->makeTransport($http);
        $transport->connect();

        $transport->startServerEventStream();

        $this->assertTrue($transport->isConnected());
    }

    public function test_poll_passes_its_timeout_to_optional_stream_reconnect(): void
    {
        $getTimeout = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$getTimeout): MockResponse {
            if ($method === 'GET') {
                $getTimeout = $options['timeout'] ?? null;
                throw new \RuntimeException('GET stream unavailable');
            }

            return new MockResponse('', ['http_code' => 202]);
        });
        $transport = $this->makeTransport($http);

        $transport->poll(0.01);

        $this->assertIsFloat($getTimeout);
        $this->assertLessThanOrEqual(0.01, $getTimeout);
    }

    public function test_invalid_json_on_independent_get_stream_is_reported(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            "data: not-json\n\n",
            [
                'http_code' => 200,
                'response_headers' => ['Content-Type: text/event-stream'],
            ],
        ));
        $transport = $this->makeTransport($http);
        $transport->connect();
        $transport->startServerEventStream();

        $this->expectException(McpConnectionException::class);
        $this->expectExceptionMessage('invalid JSON');

        $transport->poll(0.01);
    }

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
