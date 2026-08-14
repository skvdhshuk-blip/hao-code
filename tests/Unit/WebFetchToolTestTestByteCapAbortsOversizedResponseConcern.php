<?php

namespace Tests\Unit;

use HaoCode\Tools\WebFetch\WebFetchTool;
use HaoCode\Tools\ToolOutcome;
use HaoCode\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait WebFetchToolTestTestByteCapAbortsOversizedResponseConcern
{

    public function test_byte_cap_aborts_oversized_response(): void
    {
        $tool = new WebFetchTool(allowPrivateNetworks: false, ssrfAllowList: ['127.0.0.1/32'], maxBytes: 16);
        $tool->setClient(new MockHttpClient([
            new MockResponse(str_repeat('x', 1024), ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/huge'], $this->context);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('exceeded', $result->output);
    }

    public function test_output_truncation_keeps_prefix_and_marker(): void
    {
        $prefix = str_repeat('a', 100_000);
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse($prefix.'TAIL', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]));

        $result = $tool->call(['url' => 'http://127.0.0.1:9999/truncate'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringStartsWith($prefix, $result->output);
        $this->assertStringContainsString('[Content truncated at 100000 characters]', $result->output);
        $this->assertStringNotContainsString('TAIL', $result->output);
    }

    public function test_byte_fallback_does_not_cut_utf8_character(): void
    {
        $method = (new \ReflectionClass($this->tool))->getMethod('truncateUtf8ByBytes');
        $method->setAccessible(true);
        $content = str_repeat('a', 99_999).'界';
        $prefix = $method->invoke($this->tool, $content, 100_000);

        $this->assertSame(99_999, strlen($prefix));
        $this->assertTrue((bool) preg_match('//u', $prefix));
    }

    public function test_call_does_not_disable_tls_or_use_curl(): void
    {
        // Sanity: the tool no longer touches ext-curl. We assert the source
        // has no curl_* calls (WebSearch made the same migration; WebFetch
        // never used curl, but the assertion documents the invariant).
        $source = file_get_contents((new \ReflectionClass(WebFetchTool::class))->getFileName());
        $this->assertStringNotContainsString('curl_init', $source);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER', $source);
    }

    public function test_request_pins_one_checked_ip_without_manual_host_header(): void
    {
        $optionsSeen = null;
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$optionsSeen) {
            $optionsSeen = $options;

            return new MockResponse('ok', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'text/plain'],
            ]);
        }));

        $result = $tool->call(['url' => 'http://127.0.0.1:8080/pinned'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertIsArray($optionsSeen);
        $this->assertSame(['127.0.0.1' => '127.0.0.1'], $optionsSeen['resolve']);
        $this->assertSame('*', $optionsSeen['no_proxy']);
        $this->assertArrayNotHasKey('Host', $optionsSeen['headers']);
    }

    public function test_request_pins_hostname_to_checked_ip_and_preserves_port(): void
    {
        // Exercise the exact options builder without depending on the runner's
        // DNS configuration (localhost is intentionally not resolved in some
        // CI sandboxes).
        $method = (new \ReflectionClass($this->tool))->getMethod('requestOptions');
        $method->setAccessible(true);
        $options = $method->invoke($this->tool, [
            'host' => 'localhost',
            'ips' => ['127.0.0.1'],
        ]);

        $this->assertSame(['localhost' => '127.0.0.1'], $options['resolve']);
        $this->assertSame('*', $options['no_proxy']);
        $this->assertArrayNotHasKey('Host', $options['headers']);
    }

    public function test_request_fails_over_to_the_next_validated_ip_on_transport_error(): void
    {
        $resolves = [];
        $calls = 0;
        $tool = new WebFetchTool;
        $tool->setClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$resolves, &$calls) {
            $resolves[] = $options['resolve'];
            $calls++;

            return $calls === 1
                ? new MockResponse('', ['error' => 'connection failed'])
                : new MockResponse('ok', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]);
        }));

        $method = (new \ReflectionClass($tool))->getMethod('requestValidatedUrl');
        $method->setAccessible(true);
        $response = $method->invoke($tool, 'https://example.com/path', [
            'host' => 'example.com',
            'ips' => ['203.0.113.10', '203.0.113.11'],
        ]);

        $this->assertSame('ok', $response['body']);
        $this->assertSame([
            ['example.com' => '203.0.113.10'],
            ['example.com' => '203.0.113.11'],
        ], $resolves);
    }

    public function test_request_does_not_fail_over_after_an_http_response(): void
    {
        $calls = 0;
        $tool = new WebFetchTool;
        $tool->setClient(new MockHttpClient(function () use (&$calls) {
            $calls++;

            return new MockResponse('unavailable', ['http_code' => 503]);
        }));

        $method = (new \ReflectionClass($tool))->getMethod('requestValidatedUrl');
        $method->setAccessible(true);

        try {
            $method->invoke($tool, 'https://example.com/path', [
                'host' => 'example.com',
                'ips' => ['203.0.113.10', '203.0.113.11'],
            ]);
            $this->fail('Expected an HTTP error.');
        } catch (\ReflectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $exception = $e instanceof \ReflectionException ? $e : ($e->getPrevious() ?? $e);
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        $this->assertSame(1, $calls);
    }

    public function test_prompt_is_reported_as_focus_not_extraction(): void
    {
        $tool = new WebFetchTool(ssrfAllowList: ['127.0.0.1/32']);
        $tool->setClient(new MockHttpClient([
            new MockResponse('full page', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]));

        $result = $tool->call([
            'url' => 'http://127.0.0.1:9999/focus',
            'prompt' => 'look for invoices',
        ], $this->context);

        $this->assertStringContainsString('[Requested focus: look for invoices]', $result->output);
        $this->assertStringContainsString('full page', $result->output);
    }
}
