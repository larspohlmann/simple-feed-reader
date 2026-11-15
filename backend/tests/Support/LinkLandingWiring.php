<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Content\LinkResolution\LinkLanding;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Outbound\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Outbound\Fetch\IpValidator;
use App\Service\Outbound\Fetch\UrlGuard;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/** A LinkLanding over a scripted HTTP client; `internal.example` resolves to a private address, every other host not. */
final class LinkLandingWiring
{
    /** @var list<string> method and URL of each request sent */
    public array $requests = [];

    /** Runs with each request's URL as it is sent, so a test can let the clock run or break a request. */
    public ?\Closure $onRequest = null;

    /** @var list<array{timeout: mixed, max_duration: mixed, headers: list<string>}> */
    public array $requestOptions = [];

    /** @param list<MockResponse> $responses answered in order; a request past them gets a 404 */
    public function landing(array $responses): LinkLanding
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $this->requests[] = $method . ' ' . $url;
            if ($this->onRequest !== null) {
                ($this->onRequest)($url);
            }
            /** @var list<string> $headers */
            $headers = $options['headers'];
            $this->requestOptions[] = [
                'timeout' => $options['timeout'],
                'max_duration' => $options['max_duration'],
                'headers' => $headers,
            ];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
        });
        $resolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return [$hostname === 'internal.example' ? '10.0.0.1' : '93.184.216.34'];
            }
        };

        return new LinkLanding(
            FetchWiring::redirectFollower(
                $client,
                FetchWiring::egressProxy(null),
                new UrlGuard($resolver, new IpValidator()),
            ),
            new UrlNormalizer(),
            'TestAgent/1.0',
        );
    }

    public static function redirect(string $location): MockResponse
    {
        return new MockResponse('', [
            'http_code' => Response::HTTP_MOVED_PERMANENTLY,
            'response_headers' => ['location' => [$location]],
        ]);
    }

    public static function answer(int $status): MockResponse
    {
        return new MockResponse('', ['http_code' => $status]);
    }

    public static function failure(): MockResponse
    {
        return new MockResponse('', ['error' => 'Connection refused']);
    }
}
