<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Records the response instance request() returns, the one the client streams or cancels: MockHttpClient wraps each
 * given response in a fresh instance, so the one passed in never is.
 */
final class ResponseCapturingHttpClient extends MockHttpClient
{
    public ?ResponseInterface $lastResponse = null;

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->lastResponse = parent::request($method, $url, $options);
    }
}
