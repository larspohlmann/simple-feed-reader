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

    public function testABodyWithoutAnEncodingIsReturnedAsItIs(): void
    {
        self::assertSame(self::HTML, ResponseBody::decoded(self::response(self::HTML), ResponseSizeLimit::Page));
    }

    public function testAnIdentityBodyIsReturnedAsItIs(): void
    {
        $body = ResponseBody::decoded(self::response(self::HTML, 'identity'), ResponseSizeLimit::Page);

        self::assertSame(self::HTML, $body);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function compressedBodies(): iterable
    {
        yield 'gzip' => [(string) gzencode(self::HTML), 'gzip', self::HTML];
        yield 'x-gzip' => [(string) gzencode(self::HTML), 'x-gzip', self::HTML];
        yield 'upper case' => [(string) gzencode(self::HTML), 'GZIP', self::HTML];
        yield 'deflate' => [(string) gzcompress(self::HTML), 'deflate', self::HTML];
        $incompressible = self::HTML . bin2hex(random_bytes(20_000));
        yield 'many slices' => [(string) gzencode($incompressible), 'gzip', $incompressible];
    }

    #[DataProvider('compressedBodies')]
    public function testACompressedBodyIsInflatedWhole(string $compressed, string $encoding, string $expected): void
    {
        $body = ResponseBody::decoded(self::response($compressed, $encoding), ResponseSizeLimit::Page);

        self::assertSame($expected, $body);
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
        $response = self::response(GzipBomb::inflatingTo(30_000_000), 'gzip');
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
        yield 'compress' => ['compress'];
        yield 'x-compress' => ['x-compress'];
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

    /** @return iterable<string, array{string}> */
    public static function junkEncodingLabels(): iterable
    {
        yield 'none' => ['none'];
        yield 'utf-8' => ['utf-8'];
        yield 'upper case utf8' => ['UTF8'];
        yield 'binary' => ['binary'];
    }

    #[DataProvider('junkEncodingLabels')]
    public function testAJunkEncodingLabelPassesTheBodyThroughAsIdentity(string $encoding): void
    {
        $body = ResponseBody::decoded(self::response(self::HTML, $encoding), ResponseSizeLimit::Page);

        self::assertSame(self::HTML, $body);
    }

    public function testAnEncodingNameWithSurroundingWhitespaceStillInflates(): void
    {
        $response = self::response((string) gzencode(self::HTML), ' gzip ');

        self::assertSame(self::HTML, ResponseBody::decoded($response, ResponseSizeLimit::Page));
    }

    public function testSlicesAfterTheEndOfTheStreamAreNotRead(): void
    {
        $page = self::HTML . bin2hex(random_bytes(2_000));
        $response = self::response(gzencode($page) . random_bytes(3_000), 'gzip');

        self::assertSame($page, ResponseBody::decoded($response, ResponseSizeLimit::Page));
    }

    public function testABodyOneByteOverTheLimitIsTooLargeEvenWhenASliceEndsOnTheLimit(): void
    {
        $limit = ResponseSizeLimit::ChapterFile;
        $payload = random_bytes(max(1, $limit->bytes() + 1));
        // 10 header bytes, the name and its NUL, then the 5-byte stored block header: the 63rd slice ends on the limit.
        $nameLength = 63 * 1_024 - $limit->bytes() - 5 - 10 - 1;
        $gzip = "\x1f\x8b\x08\x08" . pack('V', 0) . "\x00\x03" . str_repeat('a', $nameLength) . "\0"
            . gzdeflate($payload, 0) . pack('V', crc32($payload)) . pack('V', \strlen($payload));

        $this->expectException(ResponseTooLargeException::class);

        ResponseBody::decoded(self::response($gzip, 'gzip'), $limit);
    }

    public function testAnIncompressibleBodyAtExactlyTheLimitSpanningManySlicesPasses(): void
    {
        $exact = random_bytes(max(1, ResponseSizeLimit::ChapterFile->bytes()));

        $response = self::response((string) gzencode($exact), 'gzip');

        self::assertSame($exact, ResponseBody::decoded($response, ResponseSizeLimit::ChapterFile));
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
