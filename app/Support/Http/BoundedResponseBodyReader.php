<?php

namespace HaoCode\Support\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Read only a bounded prefix from an HTTP response body.
 *
 * Error responses are provider-controlled input. They must not be allowed to
 * turn an API failure into an unbounded allocation while we build the
 * exception message.
 *
 * @internal
 */
final class BoundedResponseBodyReader
{
    public static function read(
        HttpClientInterface $httpClient,
        ResponseInterface $response,
        int $maxBytes,
    ): string {
        if ($maxBytes < 1) {
            return '';
        }

        $body = '';
        try {
            foreach ($httpClient->stream($response, 1.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    break;
                }

                if ($chunk->isLast()) {
                    break;
                }

                if ($chunk->isFirst()) {
                    continue;
                }

                $content = $chunk->getContent();
                if ($content === '') {
                    continue;
                }

                $remaining = $maxBytes - strlen($body);
                if (strlen($content) > $remaining) {
                    $body .= substr($content, 0, $remaining);

                    break;
                }

                $body .= $content;
                if (strlen($body) >= $maxBytes) {
                    break;
                }
            }
        } finally {
            // Error responses are never reused by a caller. Cancel even
            // after the last chunk so deferred status checks cannot buffer or
            // throw while the response is being destroyed.
            $response->cancel();
        }

        return $body;
    }
}
