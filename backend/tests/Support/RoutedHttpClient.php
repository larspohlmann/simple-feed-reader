<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A MockHttpClient that answers by "METHOD url", or through a closure, and records what it was asked. */
final class RoutedHttpClient
{
    /** @var list<array{method: string, url: string, body: string, headers: list<string>}> */
    public array $requests = [];

    /** @var \Closure(string, string, list<string>): MockResponse */
    private \Closure $answer;

    /** @param array<string, MockResponse> $routes */
    public function __construct(array $routes)
    {
        $this->answer = static fn (string $method, string $url): MockResponse => $routes[$method . ' ' . $url]
            ?? Assert::fail(sprintf('Unrouted request: %s %s', $method, $url));
    }

    /** @param \Closure(string, string, list<string>): MockResponse $answer by method, url and request headers */
    public static function answering(\Closure $answer): self
    {
        $http = new self([]);
        $http->answer = $answer;

        return $http;
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

            return ($this->answer)($method, $url, $headers);
        });
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return array_column($this->requests, 'url');
    }

    /** @return list<string> "METHOD url" of every request, in the order they went out */
    public function requestLines(): array
    {
        return array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $this->requests,
        );
    }
}
