<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * MockHttpClient::request() always wraps the response it is given in a fresh
 * instance (MockResponse::fromRequest()), so the object passed to the
 * constructor is never the one a client under test actually streams or
 * cancels. This decorator records the instance request() returns — the same
 * one the client holds — so a test can assert on it afterwards, e.g. that
 * cancel() was really called.
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
