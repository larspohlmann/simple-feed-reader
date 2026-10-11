<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\GuardedImageDownloader;

use App\Service\Image\GuardedImageDownloader\GuardedImageDownloader;
use App\Service\Image\Exception\GuardedImageRejectedException;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GuardedImageDownloaderTest extends TestCase
{
    use NoEgressProxy;

    private const string IMAGE_URL = 'https://www.theverge.com/favicon.ico';

    /**
     * UrlGuard is `final`, so it runs for real: a fake DnsResolverInterface stands in for the network and the real
     * IpValidator makes the SSRF check genuine.
     *
     * @param array<string, list<string>> $dnsMap
     */
    private function downloader(
        MockHttpClient $client,
        array $dnsMap = ['www.theverge.com' => ['93.184.216.34']],
    ): GuardedImageDownloader {
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

        return new GuardedImageDownloader(
            FetchWiring::redirectFollower($client, $this->noEgressProxy(), new UrlGuard($resolver, new IpValidator())),
            'TestAgent/1.0',
        );
    }

    public function testReturnsTheBytesAndContentTypeOfAnImageResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('BINARY', [
            'response_headers' => ['content-type' => ['image/png']],
        ]));

        $image = $this->downloader($client)->download(self::IMAGE_URL);

        self::assertSame('BINARY', $image->bytes);
        self::assertSame('image/png', $image->contentType);
        self::assertSame(self::IMAGE_URL, $image->sourceUrl);
    }

    public function testSendsTheOutboundUserAgent(): void
    {
        $headers = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers) {
            $headers = $options['headers'];

            return new MockResponse('BINARY', ['response_headers' => ['content-type' => ['image/png']]]);
        });

        $this->downloader($client)->download(self::IMAGE_URL);

        self::assertIsArray($headers);
        self::assertContains('User-Agent: TestAgent/1.0', $headers);
        self::assertContains('Accept-Encoding: identity', $headers);
    }

    public function testBoundsEachRequestToEightSeconds(): void
    {
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent) {
            $sent = $options;

            return new MockResponse('BINARY', ['response_headers' => ['content-type' => ['image/png']]]);
        });

        $this->downloader($client)->download(self::IMAGE_URL);

        self::assertSame(8.0, $sent['timeout'] ?? null);
        self::assertSame(8.0, $sent['max_duration'] ?? null);
    }

    public function testRejectsANonImageContentType(): void
    {
        $client = new MockHttpClient(new MockResponse('not an image', [
            'response_headers' => ['content-type' => ['text/html']],
        ]));

        $this->expectException(GuardedImageUnavailableException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testRejectsAnOversizedResponse(): void
    {
        $client = new MockHttpClient(new MockResponse(
            str_repeat('x', GuardedImageDownloader::MAX_BYTES + 1),
            ['response_headers' => ['content-type' => ['image/png']]],
        ));

        $this->expectException(GuardedImageUnavailableException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testAWireCapTripNestedTwoLevelsDeepIsRejectedNotMerelyUnavailable(): void
    {
        $client = new MockHttpClient(static function (): never {
            $capTripped = new ResponseTooLargeException('cap tripped');
            $wrappedTwice = new TransportException('wrapped twice', previous: $capTripped);
            throw new TransportException('wrapped once', previous: $wrappedTwice);
        });

        $this->expectException(GuardedImageRejectedException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testRejectsANonSuccessStatus(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(GuardedImageUnavailableException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testPropagatesTheSsrfGuardAsAnUnavailableIconNotARejectedOne(): void
    {
        $client = new MockHttpClient(new MockResponse('BINARY'));

        try {
            $this->downloader($client, ['www.theverge.com' => ['127.0.0.1']])->download(self::IMAGE_URL);
            self::fail('Expected a GuardedImageUnavailableException.');
        } catch (GuardedImageUnavailableException $caught) {
            self::assertNotInstanceOf(GuardedImageRejectedException::class, $caught);
        }
    }

    public function testAWireCapTripIsRejectedNotMerelyUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', 5_000_001), [
            'response_headers' => ['content-type' => ['image/png']],
        ]));

        $this->expectException(GuardedImageRejectedException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testTheWireGuardStopsTheDownloadOnceTheBodyPassesTheCap(): void
    {
        $chunksSent = 0;
        $body = (static function () use (&$chunksSent): \Generator {
            while ($chunksSent < 2 * ResponseSizeLimit::Download->value) {
                ++$chunksSent;
                yield str_repeat('x', 1_000_000);
            }
        })();
        $client = new MockHttpClient(new MockResponse($body, [
            'response_headers' => ['content-type' => ['image/png']],
        ]));

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail('expected the image to be rejected');
        } catch (GuardedImageRejectedException) {
            self::assertLessThan(2 * ResponseSizeLimit::Download->value, $chunksSent);
        }
    }

    public function testRejectsAnEmptyBody(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'response_headers' => ['content-type' => ['image/png']],
        ]));

        $this->expectException(GuardedImageUnavailableException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testFollowsASafeRedirectToTheImage(): void
    {
        $responses = [
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location' => '/icon-2.png'],
            ]),
            new MockResponse('BINARY', [
                'response_headers' => ['content-type' => ['image/png']],
            ]),
        ];

        $image = $this->downloader(new MockHttpClient($responses))->download(self::IMAGE_URL);

        self::assertSame('BINARY', $image->bytes);
        // sourceUrl stays the originally requested URL, not the redirect target.
        self::assertSame(self::IMAGE_URL, $image->sourceUrl);
    }

    public function testA403IsRejectedNotMerelyUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 403]));

        $this->expectException(GuardedImageRejectedException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testA400IsRejectedAtTheLowerBoundaryWithItsStatusInTheMessage(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 400]));

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail('Expected a GuardedImageRejectedException.');
        } catch (GuardedImageRejectedException $caught) {
            self::assertSame('Image responded 400.', $caught->getMessage());
        }
    }

    public function testA499IsRejectedAtTheUpperBoundary(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 499]));

        $this->expectException(GuardedImageRejectedException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testA429IsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 429]));

        $this->expectException(GuardedImageRejectedException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testA404IsUnavailableButNotRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail('Expected a GuardedImageUnavailableException.');
        } catch (GuardedImageUnavailableException $caught) {
            self::assertNotInstanceOf(GuardedImageRejectedException::class, $caught);
        }
    }

    public function testA500IsUnavailableButNotRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail('Expected a GuardedImageUnavailableException.');
        } catch (GuardedImageUnavailableException $caught) {
            self::assertNotInstanceOf(GuardedImageRejectedException::class, $caught);
            self::assertSame('Image responded 500.', $caught->getMessage());
        }
    }

    public function testAnImageTypeOutsideTheAllowListIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('BINARY', [
            'response_headers' => ['content-type' => ['image/avif']],
        ]));

        $this->expectException(GuardedImageRejectedException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    /** @return iterable<string, array{array<string, list<string>>, string, string}> */
    public static function undeclaredImages(): iterable
    {
        yield 'jpeg as octet-stream' => [
            ['content-type' => ['application/octet-stream']],
            "\xFF\xD8\xFF\xE0\x00\x10JFIF",
            'image/jpeg',
        ];
        yield 'png without a content type' => [[], "\x89PNG\r\n\x1A\n", 'image/png'];
        yield 'gif as upper-case octet-stream with a parameter' => [
            ['content-type' => ['Application/Octet-Stream; charset=binary']],
            'GIF89a',
            'image/gif',
        ];
        yield 'webp as octet-stream' => [
            ['content-type' => ['application/octet-stream']],
            "RIFF\x24\x00\x00\x00WEBPVP8 ",
            'image/webp',
        ];
    }

    /** @param array<string, list<string>> $headers */
    #[DataProvider('undeclaredImages')]
    public function testAnUndeclaredBodyIsTypedByItsLeadingBytes(array $headers, string $bytes, string $type): void
    {
        $client = new MockHttpClient(new MockResponse($bytes, ['response_headers' => $headers]));

        $image = $this->downloader($client)->download(self::IMAGE_URL);

        self::assertSame($bytes, $image->bytes);
        self::assertSame($type, $image->contentType);
    }

    public function testADeclaredTypeIsTrustedOverTheLeadingBytes(): void
    {
        $client = new MockHttpClient(new MockResponse('GIF89a', [
            'response_headers' => ['content-type' => ['Image/PNG ; charset=binary']],
        ]));

        self::assertSame('image/png', $this->downloader($client)->download(self::IMAGE_URL)->contentType);
    }

    public function testAnUndeclaredAvifIsRejectedLikeADeclaredOne(): void
    {
        $client = new MockHttpClient(new MockResponse("\x00\x00\x00\x1CftypavifmiaF", [
            'response_headers' => ['content-type' => ['application/octet-stream']],
        ]));

        $this->expectException(GuardedImageRejectedException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testAnUndeclaredBodyThatIsNoImageStaysUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"/>', [
            'response_headers' => ['content-type' => ['application/octet-stream']],
        ]));

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail('Expected a GuardedImageUnavailableException.');
        } catch (GuardedImageUnavailableException $caught) {
            self::assertNotInstanceOf(GuardedImageRejectedException::class, $caught);
            self::assertSame(
                'Content type "application/octet-stream" is not an allowed image type.',
                $caught->getMessage(),
            );
        }
    }

    public function testADeclaredNonImageTypeIsNotSniffed(): void
    {
        $client = new MockHttpClient(new MockResponse("\xFF\xD8\xFF\xE0", [
            'response_headers' => ['content-type' => ['text/html']],
        ]));

        $this->expectException(GuardedImageUnavailableException::class);
        $this->downloader($client)->download(self::IMAGE_URL);
    }

    public function testANonImageTypeIsUnavailableButNotRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('not an image', [
            'response_headers' => ['content-type' => ['text/html']],
        ]));

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail('Expected a GuardedImageUnavailableException.');
        } catch (GuardedImageUnavailableException $caught) {
            self::assertNotInstanceOf(GuardedImageRejectedException::class, $caught);
        }
    }

    public function testABodyOverTheSizeCapIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse(
            str_repeat('x', GuardedImageDownloader::MAX_BYTES + 1),
            ['response_headers' => ['content-type' => ['image/png']]],
        ));

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail('Expected a GuardedImageRejectedException.');
        } catch (GuardedImageRejectedException $caught) {
            self::assertSame('Image exceeded 3145728 bytes.', $caught->getMessage());
        }
    }

    public function testARejectedFetchCarriesNoErrorCode(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('wrapped', previous: new ResponseTooLargeException('cap tripped'));
        });

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail(GuardedImageRejectedException::class . ' was not thrown.');
        } catch (GuardedImageRejectedException $caught) {
            self::assertSame(0, $caught->getCode());
        }
    }

    public function testAnUnavailableFetchCarriesNoErrorCode(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Connection refused');
        });

        try {
            $this->downloader($client)->download(self::IMAGE_URL);
            self::fail(GuardedImageUnavailableException::class . ' was not thrown.');
        } catch (GuardedImageUnavailableException $caught) {
            self::assertNotInstanceOf(GuardedImageRejectedException::class, $caught);
            self::assertSame(0, $caught->getCode());
        }
    }
}
