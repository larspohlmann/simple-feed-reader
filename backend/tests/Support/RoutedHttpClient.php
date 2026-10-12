<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A MockHttpClient that answers by "METHOD url" and records what it was asked. */
final class RoutedHttpClient
{
    /** @var list<array{method: string, url: string, body: string, headers: list<string>}> */
    public array $requests = [];

    /** @param array<string, MockResponse> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
                $headers[] = \is_string($header) ? $header : '';
            }
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($options['body'] ?? null) ? $options['body'] : '',
                'headers' => $headers,
            ];

            return $this->routes[$method . ' ' . $url] ?? new MockResponse('', ['http_code' => 404]);
        });
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return array_column($this->requests, 'url');
    }
}
