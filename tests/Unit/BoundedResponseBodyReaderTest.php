<?php

namespace Tests\Unit;

use HaoCode\Support\Http\BoundedResponseBodyReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BoundedResponseBodyReaderTest extends TestCase
{
    public function test_read_does_not_retain_more_than_the_configured_prefix(): void
    {
        $limit = 64 * 1024;
        $responseBody = [str_repeat('a', $limit), str_repeat('b', $limit)];
        $httpClient = new MockHttpClient([
            new MockResponse($responseBody, ['http_code' => 502]),
        ]);
        $response = $httpClient->request('POST', 'https://api.example.test', ['buffer' => false]);
        $response->getStatusCode();

        $body = BoundedResponseBodyReader::read($httpClient, $response, $limit);

        $this->assertSame($limit, strlen($body));
        $this->assertSame(str_repeat('a', $limit), $body);
        $this->assertTrue($response->getInfo('canceled'));
    }

    public function test_read_preserves_small_error_bodies(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('bad key', ['http_code' => 401]),
        ]);
        $response = $httpClient->request('POST', 'https://api.example.test', ['buffer' => false]);
        $response->getStatusCode();

        $this->assertSame('bad key', BoundedResponseBodyReader::read($httpClient, $response, 64 * 1024));
        $this->assertTrue($response->getInfo('canceled'));
    }
}
