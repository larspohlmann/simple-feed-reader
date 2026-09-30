<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Image\Exception\ImageRefusedException;
use App\Service\Image\Exception\ImageUnavailableException;
use App\Service\Image\ImageDownloader;
use App\Service\Image\Model\ImageRequestModel;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImageDownloaderTest extends TestCase
{
    use NoEgressProxy;

    private const string IMAGE = 'https://www.oxmoxhh.de/wp-content/uploads/2026/09/cover-413x450.png';

    /** @param callable|iterable<MockResponse> $responses */
    private function downloader(callable|iterable $responses): ImageDownloader
    {
        return new ImageDownloader(
            FetchWiring::redirectFollower(
                new MockHttpClient($responses),
                $this->noEgressProxy(),
                new UrlGuard(
                    new StaticDnsResolver(['www.oxmoxhh.de' => ['85.13.150.207']]),
                    new IpValidator(),
                ),
            ),
            'TestAgent/1.0',
        );
    }

    /** @return list<string> */
    private static function headerLines(mixed $headers): array
    {
        return \is_array($headers) ? array_values(array_filter($headers, \is_string(...))) : [];
    }

    private static function png(string $bytes = "\x89PNG-body"): MockResponse
    {
        return new MockResponse($bytes, ['http_code' => 200, 'response_headers' => ['Content-Type: image/png']]);
    }

    public function testReturnsTheBytesAndTheBareContentType(): void
    {
        $downloader = $this->downloader([
            new MockResponse('webp-bytes', [
                'http_code' => 200,
                'response_headers' => ['Content-Type: IMAGE/WebP; charset=binary'],
            ]),
        ]);

        $image = $downloader->download(new ImageRequestModel(self::IMAGE));

        self::assertSame('webp-bytes', $image->bytes);
        self::assertSame('image/webp', $image->contentType);
    }

    public function testSendsTheOriginAsRefererTheAgentAndNoCookieByDefault(): void
    {
        /** @var list<string> $sent */
        $sent = [];
        $downloader = $this->downloader(static function (string $method, string $url, array $options) use (&$sent) {
            $sent = self::headerLines($options['headers']);

            return self::png();
        });

        $downloader->download(new ImageRequestModel(self::IMAGE));

        self::assertContains('Referer: https://www.oxmoxhh.de/', $sent);
        self::assertContains('User-Agent: TestAgent/1.0', $sent);
        self::assertContains('Accept-Encoding: identity', $sent);
        self::assertEmpty(array_filter($sent, static fn (string $header): bool => str_starts_with($header, 'Cookie:')));
    }

    public function testSendsTheCookiesItWasGiven(): void
    {
        /** @var list<string> $sent */
        $sent = [];
        $downloader = $this->downloader(static function (string $method, string $url, array $options) use (&$sent) {
            $sent = self::headerLines($options['headers']);

            return self::png();
        });

        $downloader->download(new ImageRequestModel(self::IMAGE, 'conz_bild=1; visit=7f3'));

        self::assertContains('Cookie: conz_bild=1; visit=7f3', $sent);
    }

    public function testA403IsARefusal(): void
    {
        $downloader = $this->downloader([new MockResponse('Forbidden', ['http_code' => 403])]);

        $this->expectException(ImageRefusedException::class);
        $downloader->download(new ImageRequestModel(self::IMAGE));
    }

    public function testA404IsUnavailableButNotARefusal(): void
    {
        $downloader = $this->downloader([new MockResponse('gone', ['http_code' => 404])]);

        try {
            $downloader->download(new ImageRequestModel(self::IMAGE));
            self::fail('A 404 must throw.');
        } catch (ImageUnavailableException $exception) {
            self::assertNotInstanceOf(ImageRefusedException::class, $exception);
        }
    }

    public function testRejectsSvg(): void
    {
        $downloader = $this->downloader([
            new MockResponse('<svg onload="alert(1)"/>', [
                'http_code' => 200,
                'response_headers' => ['Content-Type: image/svg+xml'],
            ]),
        ]);

        $this->expectException(ImageUnavailableException::class);
        $downloader->download(new ImageRequestModel(self::IMAGE));
    }

    public function testWrapsAnSsrfBlockedHop(): void
    {
        $downloader = $this->downloader([
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location: http://169.254.169.254/x.png'],
            ]),
        ]);

        $this->expectException(ImageUnavailableException::class);
        $downloader->download(new ImageRequestModel(self::IMAGE));
    }

    public function testCapsTheWire(): void
    {
        $downloader = $this->downloader([
            new MockResponse(str_repeat('x', 5_000_001), [
                'http_code' => 200,
                'response_headers' => ['Content-Type: image/png'],
            ]),
        ]);

        $this->expectException(ImageUnavailableException::class);
        $downloader->download(new ImageRequestModel(self::IMAGE));
    }
}
