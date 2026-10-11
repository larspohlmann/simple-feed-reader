<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\PageHeadFetcher;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PageHeadFetcherTest extends TestCase
{
    use NoEgressProxy;

    private int $pulledBytes = 0;

    /** @param callable|iterable<MockResponse> $responses */
    private function fetcher(callable|iterable $responses): PageHeadFetcher
    {
        $resolver = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return $hostname === 'example.com' ? ['93.184.216.34'] : [];
            }
        };

        return new PageHeadFetcher(FetchWiring::pageLanding(FetchWiring::redirectFollower(
            new MockHttpClient($responses),
            $this->noEgressProxy(),
            new UrlGuard($resolver, new IpValidator()),
        )));
    }

    private static function metaRefresh(string $target): string
    {
        return '<html lang="en"><head><meta http-equiv="refresh" content="0; url=' . $target . '">'
            . '</head><body>interstitial</body></html>';
    }

    /** @param list<string> $opening */
    private function headOf(array $opening): string
    {
        $chunks = function () use ($opening): \Generator {
            foreach ([...$opening, ...array_fill(0, 200, str_repeat('x', 16_384))] as $chunk) {
                $this->pulledBytes += \strlen($chunk);
                yield $chunk;
            }
        };
        $fetcher = $this->fetcher([new MockResponse($chunks(), ['http_code' => 200])]);

        return $fetcher->fetch('https://example.com/huge')->head;
    }

    public function testAHeadStopsReadingOnceTheHeadHasEnded(): void
    {
        $opening = str_pad('<html lang="en"><head><title>Story</title></head><body>', 20_000);

        self::assertSame($opening, $this->headOf([$opening]));
    }

    public function testAHeadThatNeverEndsIsCutAt1MbAndTheDownloadStopsNearThere(): void
    {
        $head = $this->headOf(['<html lang="en"><head><title>Story</title>']);

        self::assertSame(1_048_576, \strlen($head));
        self::assertLessThan(1_250_000, $this->pulledBytes);
    }

    public function testAHeadEndsAtABodyThatOpensWithoutAClosedHead(): void
    {
        $head = $this->headOf(['<HTML lang="en"><TITLE>Story</TITLE><BODY>']);

        self::assertLessThan(40_000, \strlen($head));
    }

    public function testAHeadEndCutByAChunkBoundaryIsFound(): void
    {
        $opening = '<html lang="en"><head><title>Story</title>';
        $head = $this->headOf([str_pad($opening, 24_572) . '</he', 'ad>']);

        self::assertLessThan(40_000, \strlen($head));
    }

    public function testAHeadIsReadFarEnoughToSeeAChallengeInTheBody(): void
    {
        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('challenge');
        $this->headOf([
            '<html lang="en"><head><title>Just a moment</title></head><body>',
            str_repeat(' ', 12_000) . '<form id="challenge-form">',
        ]);
    }

    public function testAHeadCutShortByTheConnectionThrows(): void
    {
        $chunks = static function (): \Generator {
            yield '<html lang="en"><head>';
            yield new TransportException('connection reset');
        };
        $fetcher = $this->fetcher([new MockResponse($chunks(), ['http_code' => 200])]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('connection reset');
        $fetcher->fetch('https://example.com/reset');
    }

    public function testAHeadIsFetchedWithShortTimeouts(): void
    {
        $options = [];
        $fetcher = $this->fetcher(static function (string $method, string $url, array $given) use (&$options) {
            $options = $given;

            return new MockResponse('<html lang="en"><head><title>T</title></head></html>', ['http_code' => 200]);
        });

        $fetcher->fetch('https://example.com/post');

        self::assertSame(5.0, $options['timeout']);
        self::assertSame(8.0, $options['max_duration']);
    }

    public function testAHeadsRedirectsShareItsEightSeconds(): void
    {
        $maxDurations = [];
        $fetcher = $this->fetcher(static function (string $method, string $url, array $options) use (&$maxDurations) {
            $maxDurations[] = $options['max_duration'];

            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'https://example.com/hop' . \count($maxDurations)],
                'total_time' => 3.0,
            ]);
        });

        try {
            $fetcher->fetch('https://example.com/slow');
            self::fail('expected a PageFetchException');
        } catch (PageFetchException $failure) {
            self::assertStringContainsString('out of time', $failure->getMessage());
        }

        self::assertSame([8.0, 5.0, 2.0], $maxDurations);
    }

    public function testAHeadOfAPrivateAddressIsRefused(): void
    {
        $fetcher = $this->fetcher([]);

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
    }

    public function testAHeadWhoseRedirectLeadsToAPrivateAddressIsRefused(): void
    {
        $requested = [];
        $fetcher = $this->fetcher(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'],
            ]);
        });

        try {
            $fetcher->fetch('https://example.com/bounce');
            self::fail('expected a PageFetchException');
        } catch (PageFetchException) {
        }

        self::assertSame(['https://example.com/bounce'], $requested);
    }

    public function testAHeadDoesNotChaseAMetaRefresh(): void
    {
        $fetcher = $this->fetcher([
            new MockResponse(self::metaRefresh('https://example.com/elsewhere'), ['http_code' => 200]),
            new MockResponse('<html lang="en"><body>elsewhere</body></html>', ['http_code' => 200]),
        ]);

        $head = $fetcher->fetch('https://example.com/post');

        self::assertSame('https://example.com/post', $head->finalUrl);
        self::assertStringContainsString('interstitial', $head->head);
    }

    public function testAHeadOfAFailedPageThrowsAsAWholePageDoes(): void
    {
        $fetcher = $this->fetcher([new MockResponse('nope', ['http_code' => 404])]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('HTTP 404 Not Found');
        $fetcher->fetch('https://example.com/missing');
    }

    public function testADeclaredFileIsNotRead(): void
    {
        $failsOnceRead = static function (): \Generator {
            yield '%PDF-1.7 <title>Not a page</title>';
            yield new TransportException('the body was read');
        };
        $fetcher = $this->fetcher([new MockResponse($failsOnceRead(), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/pdf'],
        ])]);

        $head = $fetcher->fetch('https://example.com/paper.pdf');

        self::assertSame('application/pdf', $head->fileType);
        self::assertSame('', $head->head);
        self::assertSame('https://example.com/paper.pdf', $head->finalUrl);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function markupHeaders(): iterable
    {
        yield 'html with a charset' => [['content-type' => 'text/html; charset=utf-8']];
        yield 'plain text' => [['content-type' => 'text/plain']];
        yield 'xhtml' => [['content-type' => 'Application/XHTML+XML']];
        yield 'xml' => [['content-type' => 'application/xml']];
        yield 'a blank type' => [['content-type' => ' ']];
        yield 'no type' => [[]];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('markupHeaders')]
    public function testAMarkupOrUndeclaredTypeIsReadAsAPage(array $headers): void
    {
        $fetcher = $this->fetcher([new MockResponse('<html lang="en"><head><title>T</title></head></html>', [
            'http_code' => 200,
            'response_headers' => $headers,
        ])]);

        $head = $fetcher->fetch('https://example.com/post');

        self::assertNull($head->fileType);
        self::assertStringContainsString('<title>T</title>', $head->head);
    }
}
