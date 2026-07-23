<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HtmlPageFetcherTest extends TestCase
{
    /**
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsMap
     */
    private function fetcher(
        callable|iterable $responses,
        array $dnsMap = ['example.com' => ['93.184.216.34']],
    ): HtmlPageFetcher {
        $resolver = new class ($dnsMap) implements DnsResolverInterface {
            /** @param array<string, list<string>> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function resolve(string $hostname): array
            {
                return $this->map[$hostname] ?? [];
            }
        };

        return new HtmlPageFetcher(new MockHttpClient($responses), new UrlGuard($resolver, new IpValidator()));
    }

    public function testReturnsBodyAndFinalUrlOnSuccess(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<html lang="en"><body>hi</body></html>', [
            'http_code' => 200,
        ])]);

        $result = $fetcher->fetch('https://example.com/post');

        self::assertStringContainsString('hi', $result->html);
        self::assertSame('https://example.com/post', $result->finalUrl);
    }

    public function testWrapsSsrfBlockInPageFetchException(): void
    {
        // Link-local IP literal (169.254.0.0/16) is rejected by the guard.
        $fetcher = $this->fetcher([]);

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
    }

    public function testRejectsNon2xx(): void
    {
        $fetcher = $this->fetcher([new MockResponse('nope', ['http_code' => 404])]);

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('https://example.com/missing');
    }
}
