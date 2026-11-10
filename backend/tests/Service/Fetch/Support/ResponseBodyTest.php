<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\Support\ResponseBody;
use App\Tests\Support\CorruptGzip;
use App\Tests\Support\GzipBomb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseBodyTest extends TestCase
{
    private const string URL = 'https://example.com/page';
    private const string HTML = '<html lang="en"><body><p>Hello, compressed world.</p></body></html>';

    private static function response(string $body, ?string $encoding = null): ResponseInterface
    {
        $headers = null === $encoding ? [] : ['content-encoding' => $encoding];

        $client = new MockHttpClient(new MockResponse($body, ['response_headers' => $headers]));

        return $client->request('GET', self::URL);
    }

    public function testTheHeaderValueAsksForGzipAndDeflate(): void
    {
        self::assertSame('gzip, deflate', ResponseBody::ACCEPT_ENCODING);
    }

    public function testABodyWithoutAnEncodingIsReturnedAsItIs(): void
    {
        self::assertSame(self::HTML, ResponseBody::decoded(self::response(self::HTML), ResponseSizeLimit::Page));
    }

    public function testAnIdentityBodyIsReturnedAsItIs(): void
    {
        $body = ResponseBody::decoded(self::response(self::HTML, 'identity'), ResponseSizeLimit::Page);

        self::assertSame(self::HTML, $body);
    }

    /** @return iterable<string, array{string}> */
    public static function gzipNames(): iterable
    {
        yield 'gzip' => ['gzip'];
        yield 'x-gzip' => ['x-gzip'];
        yield 'upper case' => ['GZIP'];
    }

    #[DataProvider('gzipNames')]
    public function testAGzipBodyIsInflated(string $encoding): void
    {
        $response = self::response((string) gzencode(self::HTML), $encoding);

        $body = ResponseBody::decoded($response, ResponseSizeLimit::Page);

        self::assertSame(self::HTML, $body);
    }

    public function testADeflateBodyIsInflated(): void
    {
        $response = self::response((string) gzcompress(self::HTML), 'deflate');

        $body = ResponseBody::decoded($response, ResponseSizeLimit::Page);

        self::assertSame(self::HTML, $body);
    }

    public function testABodySpanningManySlicesIsInflatedWhole(): void
    {
        $page = str_repeat(self::HTML . bin2hex(random_bytes(64)), 2_000);

        $response = self::response((string) gzencode($page), 'gzip');

        $body = ResponseBody::decoded($response, ResponseSizeLimit::Page);

        self::assertSame($page, $body);
    }

    public function testAnEmptyCompressedBodyIsEmpty(): void
    {
        self::assertSame('', ResponseBody::decoded(self::response('', 'gzip'), ResponseSizeLimit::Page));
    }

    public function testAnUncompressedBodyOverTheLimitIsTooLarge(): void
    {
        $this->expectException(ResponseTooLargeException::class);
        $this->expectExceptionMessage(self::URL . ': the response is larger than the 64 kB limit');

        ResponseBody::decoded(
            self::response(str_repeat('a', ResponseSizeLimit::ChapterFile->bytes() + 1)),
            ResponseSizeLimit::ChapterFile,
        );
    }

    public function testACompressedBodyThatInflatesExactlyToTheLimitPasses(): void
    {
        $exact = str_repeat('a', ResponseSizeLimit::ChapterFile->bytes());

        $response = self::response((string) gzencode($exact), 'gzip');

        $body = ResponseBody::decoded($response, ResponseSizeLimit::ChapterFile);

        self::assertSame($exact, $body);
    }

    public function testABombStopsAtTheLimitWithoutInflatingWhole(): void
    {
        $response = self::response(GzipBomb::inflatingTo(100_000_000), 'gzip');
        $response->getContent(false);
        memory_reset_peak_usage();
        $before = memory_get_usage();

        try {
            ResponseBody::decoded($response, ResponseSizeLimit::Page);
            self::fail('expected the bomb to be refused');
        } catch (ResponseTooLargeException $refused) {
            self::assertStringContainsString('larger than the 3 MB limit', $refused->getMessage());
        }

        self::assertLessThan(3 * ResponseSizeLimit::Page->bytes(), memory_get_peak_usage() - $before);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedEncodings(): iterable
    {
        yield 'brotli' => ['br'];
        yield 'zstd' => ['zstd'];
        yield 'stacked' => ['gzip, gzip'];
    }

    #[DataProvider('unsupportedEncodings')]
    public function testAnUnsupportedEncodingIsUnreachable(string $encoding): void
    {
        try {
            ResponseBody::decoded(self::response('irrelevant', $encoding), ResponseSizeLimit::Page);
            self::fail('expected the encoding to be refused');
        } catch (FeedUnreachableException $refused) {
            self::assertStringStartsWith(self::URL . ': ', $refused->getMessage());
            self::assertStringContainsString($encoding, $refused->getMessage());
            self::assertNull($refused->statusCode);
        }
    }

    public function testACorruptStreamIsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);
        $this->expectExceptionMessage(self::URL . ': corrupt gzip body');

        ResponseBody::decoded(self::response(CorruptGzip::bytes(), 'gzip'), ResponseSizeLimit::Page);
    }

    public function testARawDeflateStreamIsCorrupt(): void
    {
        $this->expectException(FeedUnreachableException::class);

        ResponseBody::decoded(self::response((string) gzdeflate(self::HTML), 'deflate'), ResponseSizeLimit::Page);
    }

    public function testAStreamCutShortKeepsWhatItInflated(): void
    {
        $page = str_repeat(self::HTML, 500);
        $truncated = substr((string) gzencode($page), 0, -20);

        $body = ResponseBody::decoded(self::response($truncated, 'gzip'), ResponseSizeLimit::Page);

        self::assertNotSame('', $body);
        self::assertSame(substr($page, 0, \strlen($body)), $body);
    }
}
