# Compressed outbound fetches under an inflated-size cap — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reader pages, feeds and guarded API calls download gzip/deflate bodies, decompressed in slices so a gzip bomb stops at each fetcher's existing size limit (#2157).

**Architecture:**
- The client sends `Accept-Encoding: gzip, deflate` itself, so Symfony leaves the body compressed and the existing
  `on_progress` caps keep bounding the bytes on the wire.
- A new static helper, `App\Service\Fetch\Support\ResponseBody::decoded()`, reads the body and decompresses it in
  1 KB slices, stopping just past the limit. It then applies the `ResponseSizeLimit` to the result for every
  encoding, uncompressed included.
- Five call sites switch: `PageLanding`/`HtmlPageFetcher`, the feed engine with its two exchanges, and
  `GuardedApiClient`. Everything else stays `identity`.

**Tech Stack:** PHP 8.4, ext-zlib (`inflate_init`/`inflate_add`, already in `composer.json`), Symfony HttpClient 7.4, PHPUnit 12 with `MockHttpClient`/`MockResponse`.

**Spec:** `docs/superpowers/specs/2026-11-10-gzip-fetches-design.md`

## Global Constraints

- Branch `feature/2157-gzip-fetches` (already created off `develop`). Commit messages: `type(#2157): …`, no Claude
  attribution.
- The header value is `ResponseBody::ACCEPT_ENCODING = 'gzip, deflate'`; every switched call site references the
  constant, never a literal.
- Encodings:
  - `''` and `identity` pass through unchanged;
  - `gzip` and `x-gzip` use `\ZLIB_ENCODING_GZIP`;
  - `deflate` uses `\ZLIB_ENCODING_DEFLATE`;
  - anything else is `FeedUnreachableException`.
  - PHP has **no** `ZLIB_ENCODING_ANY`.
- Each decompression slice is `1_024` input bytes.
- Too large raises `ResponseTooLargeException`, through `ResponseTooLargeException::throwIfExceeded($limit, $bytes, $url)`.
- Corrupt or unsupported encodings raise `FeedUnreachableException('<url>: <reason>')` with **no** status code, so
  `FetchRetryPolicy` does not fail over to the other address family.
- New enum case: `ResponseSizeLimit::Page = 3_000` (3 MB = today's `HtmlPageFetcher::MAX_BYTES`).
- **No behaviour change** for `PageHeadFetcher`, the image fetchers, `OriginCookies`, the LLM, scoring, catalog and
  GitHub clients: they keep `identity`.
- CLAUDE.md rules apply: `final` classes, no abbreviations, no boolean flags, comments only where a reader would
  otherwise get it wrong, PHPMD-clean touched files, `Response::HTTP_*` constants in tests.
- Run tests natively from `backend/`: `php bin/phpunit <file>`.

---

### Task 1: `ResponseBody` decoder, `ResponseSizeLimit::Page`, `GzipBomb` fixture

**Files:**
- Create: `backend/src/Service/Fetch/Support/ResponseBody.php`
- Create: `backend/tests/Service/Fetch/Support/ResponseBodyTest.php`
- Create: `backend/tests/Support/GzipBomb.php`
- Modify: `backend/src/Service/Fetch/Model/ResponseSizeLimit.php` (add the `Page` case)
- Modify: `backend/tests/Service/Fetch/Model/ResponseSizeLimitTest.php`

**Interfaces:**
- Produces:
  - `ResponseBody::ACCEPT_ENCODING` (`string`, `'gzip, deflate'`)
  - `ResponseBody::decoded(ResponseInterface $response, ResponseSizeLimit $limit): string`, which throws Symfony
    `ExceptionInterface` (read failure), `ResponseTooLargeException` and `FeedUnreachableException`
  - `ResponseSizeLimit::Page`
  - `App\Tests\Support\GzipBomb::inflatingTo(int $bytes): string` (gzip of `$bytes` zero bytes)

- [ ] **Step 1: Write the bomb fixture**

`backend/tests/Support/GzipBomb.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Gzip of zero bytes, compressed a megabyte at a time so the test never holds the inflated size. */
final class GzipBomb
{
    private const int CHUNK_BYTES = 1_048_576;

    public static function inflatingTo(int $bytes): string
    {
        $deflate = deflate_init(\ZLIB_ENCODING_GZIP, ['level' => 9]);
        \assert($deflate instanceof \DeflateContext);
        $zeros = str_repeat("\0", self::CHUNK_BYTES);
        $bomb = '';
        for ($written = 0; $written < $bytes; $written += self::CHUNK_BYTES) {
            $bomb .= (string) deflate_add($deflate, substr($zeros, 0, min(self::CHUNK_BYTES, $bytes - $written)), \ZLIB_NO_FLUSH);
        }

        return $bomb . (string) deflate_add($deflate, '', \ZLIB_FINISH);
    }
}
```

- [ ] **Step 2: Write the failing tests**

Add to `ResponseSizeLimitTest::testTheLimitsAreTheMeasuredOnes`:
`self::assertSame(3_000_000, ResponseSizeLimit::Page->bytes());`.

Add to `testALimitReadsInMegabytes…`:
`self::assertSame('3 MB', ResponseSizeLimit::Page->label());`.

`backend/tests/Service/Fetch/Support/ResponseBodyTest.php`:

```php
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

        return (new MockHttpClient(new MockResponse($body, ['response_headers' => $headers])))->request('GET', self::URL);
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
        self::assertSame(self::HTML, ResponseBody::decoded(self::response(self::HTML, 'identity'), ResponseSizeLimit::Page));
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
        $body = ResponseBody::decoded(self::response((string) gzencode(self::HTML), $encoding), ResponseSizeLimit::Page);

        self::assertSame(self::HTML, $body);
    }

    public function testADeflateBodyIsInflated(): void
    {
        $body = ResponseBody::decoded(self::response((string) gzcompress(self::HTML), 'deflate'), ResponseSizeLimit::Page);

        self::assertSame(self::HTML, $body);
    }

    public function testABodySpanningManySlicesIsInflatedWhole(): void
    {
        $page = str_repeat(self::HTML . bin2hex(random_bytes(64)), 2_000);

        $body = ResponseBody::decoded(self::response((string) gzencode($page), 'gzip'), ResponseSizeLimit::Page);

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

        $body = ResponseBody::decoded(self::response((string) gzencode($exact), 'gzip'), ResponseSizeLimit::ChapterFile);

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
        self::assertStringStartsWith($body, $page);
    }
}
```

- [ ] **Step 3: Run the tests and see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/Support/ResponseBodyTest.php tests/Service/Fetch/Model/ResponseSizeLimitTest.php`

Expected: errors saying `Class "App\Service\Fetch\Support\ResponseBody" not found` and that `Page` is not a case.

- [ ] **Step 4: Implement**

In `ResponseSizeLimit`, add `case Page = 3_000;` after `Api`, with the docblock `/** A reader page, the whole HTML read for extraction. */`.

`backend/src/Service/Fetch/Support/ResponseBody.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ResponseSizeLimit;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The body of a response requested with ACCEPT_ENCODING, decoded under its size limit. The wire cap bounds only the
 * compressed bytes, so inflation stops once the output passes the limit: a gzip bomb is never inflated whole.
 */
final class ResponseBody
{
    public const string ACCEPT_ENCODING = 'gzip, deflate';
    // Deflate expands at most about 1032:1, so one slice overshoots the limit by about 1 MB at worst.
    private const int SLICE_BYTES = 1_024;
    private const array UNCOMPRESSED = ['', 'identity'];
    private const array ZLIB_ENCODINGS = [
        'gzip' => \ZLIB_ENCODING_GZIP,
        'x-gzip' => \ZLIB_ENCODING_GZIP,
        'deflate' => \ZLIB_ENCODING_DEFLATE,
    ];

    /**
     * @throws ExceptionInterface        when the body cannot be read
     * @throws ResponseTooLargeException when the decoded body exceeds the limit
     * @throws FeedUnreachableException  when the encoding is unsupported or the stream corrupt
     */
    public static function decoded(ResponseInterface $response, ResponseSizeLimit $limit): string
    {
        $body = $response->getContent(false);
        $url = self::urlOf($response);
        $encoding = strtolower(trim(ResponseHeader::first($response, 'content-encoding') ?? ''));
        if (!\in_array($encoding, self::UNCOMPRESSED, true)) {
            try {
                $body = self::inflatedPast($body, $encoding, $limit->bytes());
            } catch (\UnexpectedValueException $refused) {
                throw new FeedUnreachableException(sprintf('%s: %s', $url, $refused->getMessage()), previous: $refused);
            }
        }
        ResponseTooLargeException::throwIfExceeded($limit, \strlen($body), $url);

        return $body;
    }

    /** @throws \UnexpectedValueException when the encoding is unsupported or the stream corrupt */
    private static function inflatedPast(string $compressed, string $encoding, int $maxBytes): string
    {
        $zlibEncoding = self::ZLIB_ENCODINGS[$encoding]
            ?? throw new \UnexpectedValueException(sprintf('unsupported Content-Encoding "%s"', $encoding));
        $context = inflate_init($zlibEncoding);
        \assert($context instanceof \InflateContext);
        set_error_handler(static function (int $severity, string $message) use ($encoding): never {
            throw new \UnexpectedValueException(sprintf('corrupt %s body: %s', $encoding, $message));
        });
        try {
            return self::inflateSlices($context, $compressed, $maxBytes);
        } finally {
            restore_error_handler();
        }
    }

    /** A stream cut short keeps what it inflated, as a browser does. */
    private static function inflateSlices(\InflateContext $context, string $compressed, int $maxBytes): string
    {
        $inflated = '';
        $length = \strlen($compressed);
        for ($offset = 0; $offset < $length && \strlen($inflated) <= $maxBytes; $offset += self::SLICE_BYTES) {
            $piece = inflate_add($context, substr($compressed, $offset, self::SLICE_BYTES));
            if ($piece === false) {
                throw new \UnexpectedValueException('corrupt compressed body');
            }
            $inflated .= $piece;
            if (inflate_get_status($context) === \ZLIB_STREAM_END) {
                break;
            }
        }

        return $inflated;
    }

    private static function urlOf(ResponseInterface $response): string
    {
        $url = $response->getInfo('url');

        return \is_string($url) ? $url : '';
    }

    private function __construct()
    {
    }
}
```

The corrupt-stream test expects the message `<url>: corrupt gzip body` followed by zlib's text. `inflate_add` raises a
warning, which the error handler turns into that exception. If the actual text differs, adjust the assertion to the
prefix shown, not the code.

- [ ] **Step 5: Run the tests and see them pass**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/Support/ResponseBodyTest.php tests/Service/Fetch/Model/ResponseSizeLimitTest.php`

Expected: everything passes. If PHPStan later rejects the `\assert(... instanceof \InflateContext)`, keep the assert:
`inflate_init` only returns `false` for an invalid encoding, and the map never holds one.

- [ ] **Step 6: Lint and commit**

Run: `cd backend && composer cs && vendor/bin/phpstan analyse src/Service/Fetch/Support/ResponseBody.php tests/Service/Fetch/Support/ResponseBodyTest.php tests/Support/GzipBomb.php && composer md`
Expected: clean.

```bash
git add backend/src/Service/Fetch/Support/ResponseBody.php backend/src/Service/Fetch/Model/ResponseSizeLimit.php backend/tests/Service/Fetch/Support/ResponseBodyTest.php backend/tests/Service/Fetch/Model/ResponseSizeLimitTest.php backend/tests/Support/GzipBomb.php
git commit -m "feat(#2157): decode gzip and deflate bodies under the size limit"
```

---

### Task 2: Reader page fetches travel compressed

**Files:**
- Modify: `backend/src/Service/Reader/PageLanding.php`:
  - `land()` merges the caller's headers;
  - `headers()` gets a new comment;
  - `errorBodySnippet()` decodes.
- Modify: `backend/src/Service/Reader/HtmlPageFetcher.php`:
  - `MAX_BYTES` goes;
  - `options()` asks for compression;
  - `readableBody()` and `content()` decode.
- Test: `backend/tests/Service/Reader/HtmlPageFetcherTest.php`, `backend/tests/Service/Reader/PageHeadFetcherTest.php`

**Interfaces:**
- Consumes: `ResponseBody::ACCEPT_ENCODING`, `ResponseBody::decoded()`, `ResponseSizeLimit::Page`, `GzipBomb::inflatingTo()`
- Produces: `PageLanding::land()` takes `$options['headers']` (an `array<string, string>`), which wins over its
  defaults. With no caller header, `Accept-Encoding` stays `identity`.

- [ ] **Step 1: Write the failing tests**

In `HtmlPageFetcherTest`, replace `testDisablesTransparentCompression` with:

```php
    public function testAsksForACompressedPage(): void
    {
        /** @var list<string> $seenHeaders */
        $seenHeaders = [];
        $fetcher = $this->fetcher(
            function (string $method, string $url, array $options) use (&$seenHeaders): MockResponse {
                /** @var list<string> $headers */
                $headers = $options['headers'];
                $seenHeaders = $headers;

                return new MockResponse('<html lang="en"><body>ok</body></html>', ['http_code' => 200]);
            },
        );

        $fetcher->fetch('https://example.com/post');

        self::assertContains('Accept-Encoding: gzip, deflate', $seenHeaders);
        self::assertNotContains('Accept-Encoding: identity', $seenHeaders);
    }

    public function testAGzipPageLandsAsItsHtml(): void
    {
        $html = '<html lang="en"><body><p>compressed article</p></body></html>';
        $fetcher = $this->fetcher([new MockResponse((string) gzencode($html), [
            'http_code' => 200,
            'response_headers' => ['content-encoding' => 'gzip'],
        ])]);

        self::assertSame($html, $fetcher->fetch('https://example.com/post')->html);
    }

    public function testAGzipBombFailsAsAPageFetch(): void
    {
        $fetcher = $this->fetcher([new MockResponse(GzipBomb::inflatingTo(50_000_000), [
            'http_code' => 200,
            'response_headers' => ['content-encoding' => 'gzip'],
        ])]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('larger than the 3 MB limit');

        $fetcher->fetch('https://example.com/post');
    }

    public function testAnUnsupportedEncodingFailsAsAPageFetch(): void
    {
        $fetcher = $this->fetcher([new MockResponse('brotli bytes', [
            'http_code' => 200,
            'response_headers' => ['content-encoding' => 'br'],
        ])]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('unsupported Content-Encoding "br"');

        $fetcher->fetch('https://example.com/post');
    }

    public function testAGzipErrorPageStillExplainsItself(): void
    {
        $fetcher = $this->fetcher([new MockResponse((string) gzencode('<html><body><h1>Access Denied</h1></body></html>'), [
            'http_code' => 403,
            'response_headers' => ['content-encoding' => 'gzip'],
        ])]);

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('HTTP 403 Forbidden — Access Denied');

        $fetcher->fetch('https://example.com/blocked');
    }

    public function testACorruptErrorPageReportsOnlyTheStatus(): void
    {
        $fetcher = $this->fetcher([new MockResponse(CorruptGzip::bytes(), [
            'http_code' => 404,
            'response_headers' => ['content-encoding' => 'gzip'],
        ])]);

        try {
            $fetcher->fetch('https://example.com/missing');
            self::fail('expected a PageFetchException');
        } catch (PageFetchException $failure) {
            self::assertSame('HTTP 404 Not Found', $failure->getMessage());
        }
    }
```

Add `use App\Tests\Support\CorruptGzip;` and `use App\Tests\Support\GzipBomb;`.

In `PageHeadFetcherTest`, add a test that the head fetch still asks for `identity`, since it streams and cannot
decode. First read the file and reuse its existing fixture helper (the way it builds the fetcher with a callable
`MockHttpClient` factory). Capture `$options['headers']` the same way `testAsksForACompressedPage` does, then assert
`self::assertContains('Accept-Encoding: identity', $seenHeaders);`. Name it `testTheHeadIsRequestedUncompressed`.

- [ ] **Step 2: Run the tests and see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Reader/HtmlPageFetcherTest.php tests/Service/Reader/PageHeadFetcherTest.php`

Expected:
- `testAsksForACompressedPage` fails, because identity is sent;
- the gzip, bomb, `br` and gzip-error-page tests fail (raw bytes come back, no snippet);
- `testTheHeadIsRequestedUncompressed` passes already. It guards the default.

- [ ] **Step 3: Implement `PageLanding`**

In `land()`, replace `['headers' => $this->headers()] + $options` with `$this->withHeaders($options)`, and add:

```php
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function withHeaders(array $options): array
    {
        $callerHeaders = $options['headers'] ?? [];
        \assert(\is_array($callerHeaders));

        return ['headers' => [...$this->headers(), ...$callerHeaders]] + $options;
    }
```

Change the comment in `headers()` above `'Accept-Encoding' => 'identity'` to:

```php
            // Refused unless the caller reads through ResponseBody: on_progress caps only the wire bytes.
```

Change `errorBodySnippet()` to decode:

```php
    private function errorBodySnippet(ResponseInterface $response): ?string
    {
        try {
            return self::visibleText(ResponseBody::decoded($response, ResponseSizeLimit::Page));
        } catch (ExceptionInterface | FetchException) {
            return null;
        }
    }
```

New imports: `App\Service\Fetch\Exception\FetchException`, `App\Service\Fetch\Model\ResponseSizeLimit` and
`App\Service\Fetch\Support\ResponseBody`.

- [ ] **Step 4: Implement `HtmlPageFetcher`**

- Delete `MAX_BYTES`.
- `readableBody()` becomes `return $this->landing->utf8(self::content($landed), $landed);`, and its `strlen` check
  goes, because `ResponseBody` enforces the limit now.
- `options()` gains `'headers' => ['Accept-Encoding' => ResponseBody::ACCEPT_ENCODING],`.
- The `on_progress` closure quotes `ResponseSizeLimit::Page->bytes()` in its comparison and its message:
  `sprintf('response exceeds %d bytes', ResponseSizeLimit::Page->bytes())`.
- `content()`:

```php
    private static function content(LandedResponse $landed): string
    {
        try {
            return ResponseBody::decoded($landed->response, ResponseSizeLimit::Page);
        } catch (ExceptionInterface | FetchException $exception) {
            throw new PageFetchException($exception->getMessage(), previous: $exception);
        }
    }
```

Imports as in step 3.

- [ ] **Step 5: Run the tests and see them pass**

Run: `cd backend && php bin/phpunit tests/Service/Reader/HtmlPageFetcherTest.php tests/Service/Reader/PageHeadFetcherTest.php tests/Service/Reader`
Expected: everything passes, including the existing charset, meta-refresh and challenge tests.

- [ ] **Step 6: Lint and commit**

Run: `cd backend && composer cs && vendor/bin/phpstan analyse src/Service/Reader/PageLanding.php src/Service/Reader/HtmlPageFetcher.php tests/Service/Reader && composer md`

```bash
git add backend/src/Service/Reader/PageLanding.php backend/src/Service/Reader/HtmlPageFetcher.php backend/tests/Service/Reader/HtmlPageFetcherTest.php backend/tests/Service/Reader/PageHeadFetcherTest.php
git commit -m "perf(#2157): fetch reader pages compressed"
```

---

### Task 3: Feed engine travels compressed

**Files:**
- Modify: `backend/src/Service/Fetch/ConcurrentRequestEngine.php` (`headersOf()`)
- Modify: `backend/src/Service/Fetch/ResponseExchange/FeedResponseExchange.php` (`onBody()`)
- Modify: `backend/src/Service/Fetch/ResponseExchange/ApiResponseExchange.php` (`onBody()`)
- Test: `backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php`, `backend/tests/Service/Fetch/ResponseExchange/FeedResponseExchangeTest.php`

**Interfaces:**
- Consumes: `ResponseBody::ACCEPT_ENCODING`, `ResponseBody::decoded()`, `GzipBomb::inflatingTo()`, `CorruptGzip::bytes()`

- [ ] **Step 1: Write the failing tests**

In `ConcurrentRequestEngineTest::testATicketCannotOverrideTheHeadersTheEngineOwns`, change
`self::assertContains('Accept-Encoding: identity', $sent);` to
`self::assertContains('Accept-Encoding: gzip, deflate', $sent);`.
Keep `assertNotContains('accept-encoding: gzip', $sent)`: the ticket's lowercase copy must still be dropped.

Add next to `testAnOversizedAnswerFailsAtTheApiLimit`:

```php
    public function testAGzipAnswerLandsInflated(): void
    {
        $client = new MockHttpClient([new MockResponse((string) gzencode('{"ok":true}'), [
            'response_headers' => ['content-encoding' => 'gzip'],
        ])]);

        self::assertSame('{"ok":true}', $this->onlyAnswer($client)->answerOrThrow()->body);
    }

    public function testAGzipBombFailsAtTheApiLimit(): void
    {
        $client = new MockHttpClient([new MockResponse(GzipBomb::inflatingTo(ResponseSizeLimit::Api->bytes() * 10), [
            'response_headers' => ['content-encoding' => 'gzip'],
        ])]);

        self::assertInstanceOf(ResponseTooLargeException::class, $this->onlyAnswer($client)->failure());
    }
```

In `FeedResponseExchangeTest`, add next to `testABodyOverTheSizeCapIsRejected`:

```php
    public function testAGzipFeedLandsAsItsXml(): void
    {
        $response = $this->respond(new MockResponse((string) gzencode('<rss/>'), [
            'http_code' => 200,
            'response_headers' => ['content-encoding' => 'gzip', 'etag' => '"v2"'],
        ]));

        $fetched = $this->exchange()->onBody($response, $this->attempt())->responseOrThrow();

        self::assertSame('<rss/>', $fetched->modifiedBody());
        self::assertSame('"v2"', $fetched->etag);
    }

    public function testAGzipBombIsRejectedAtTheFeedLimit(): void
    {
        $bomb = new MockResponse(GzipBomb::inflatingTo(ResponseSizeLimit::Feed->bytes() + 1_000_000), [
            'http_code' => 200,
            'response_headers' => ['content-encoding' => 'gzip'],
        ]);

        $this->expectException(ResponseTooLargeException::class);
        $this->expectExceptionMessage('https://example.com/feed: the response is larger than the 20 MB limit');

        $this->exchange()->onBody($this->respond($bomb), $this->attempt());
    }

    public function testACorruptGzipFeedIsUnreachable(): void
    {
        $corrupt = new MockResponse(CorruptGzip::bytes(), [
            'http_code' => 200,
            'response_headers' => ['content-encoding' => 'gzip'],
        ]);

        $this->expectException(FeedUnreachableException::class);
        $this->expectExceptionMessage('https://example.com/feed: corrupt gzip body');

        $this->exchange()->onBody($this->respond($corrupt), $this->attempt());
    }
```

Add `use App\Tests\Support\CorruptGzip;` and `use App\Tests\Support\GzipBomb;` (and `GzipBomb` in the engine test).

- [ ] **Step 2: Run the tests and see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/ConcurrentRequestEngineTest.php tests/Service/Fetch/ResponseExchange/FeedResponseExchangeTest.php`
Expected: the header assertion and the five new tests fail.

- [ ] **Step 3: Implement**

In `ConcurrentRequestEngine::headersOf()`, replace the three-line comment and `'Accept-Encoding' => 'identity',` with:

```php
            // The exchanges inflate through ResponseBody under the same limit on_progress puts on the wire.
            'Accept-Encoding' => ResponseBody::ACCEPT_ENCODING,
```

Import `App\Service\Fetch\Support\ResponseBody`.

In `FeedResponseExchange::onBody()`, replace the first two lines with
`$body = ResponseBody::decoded($response, ResponseSizeLimit::Feed);`. Then drop the `ResponseTooLargeException`
import if nothing else in the file uses it (check with grep).

In `ApiResponseExchange::onBody()`, replace `$response->getContent(false)` with
`ResponseBody::decoded($response, ResponseSizeLimit::Api)`. Import `ResponseBody` and `ResponseSizeLimit`.
`advance()` in the engine already turns a thrown `FetchException` and a Symfony `ExceptionInterface` into the
attempt's failure (the existing oversize test proves the path).

Update the `@throws` docblocks on both `onBody()` methods, and on `ResponseExchangeInterface::onBody()` if it lists
them, to include `FetchException`.

- [ ] **Step 4: Run the tests and see them pass**

Run: `cd backend && php bin/phpunit tests/Service/Fetch`
Expected: everything passes.

- [ ] **Step 5: Lint and commit**

Run: `cd backend && composer cs && vendor/bin/phpstan analyse src/Service/Fetch tests/Service/Fetch && composer md`

```bash
git add backend/src/Service/Fetch/ConcurrentRequestEngine.php backend/src/Service/Fetch/ResponseExchange backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php backend/tests/Service/Fetch/ResponseExchange/FeedResponseExchangeTest.php
git commit -m "perf(#2157): fetch feeds and engine API calls compressed"
```

---

### Task 4: `GuardedApiClient` travels compressed

**Files:**
- Modify: `backend/src/Service/Fetch/GuardedApiClient.php` (`optionsFor()`, `answerFrom()` and its two callers)
- Test: `backend/tests/Service/Fetch/GuardedApiClientTest.php`

**Interfaces:**
- Consumes: `ResponseBody::ACCEPT_ENCODING`, `ResponseBody::decoded()`, `GzipBomb::inflatingTo()`

- [ ] **Step 1: Write the failing tests**

Replace `testTheBodyIsRequestedUncompressedSoTheWireLimitBoundsIt` with:

```php
    public function testTheBodyIsRequestedCompressed(): void
    {
        $seenOptions = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenOptions) {
            $seenOptions = $options;

            return new MockResponse('{}');
        });

        $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertIsArray($seenOptions['headers']);
        self::assertContains('Accept-Encoding: gzip, deflate', $seenOptions['headers']);
    }

    public function testAGzipAnswerIsInflated(): void
    {
        $http = new MockHttpClient(new MockResponse((string) gzencode('{"ok":true}'), [
            'response_headers' => ['content-encoding' => 'gzip'],
        ]));

        self::assertSame(['ok' => true], $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'))->json());
    }

    public function testAGzipBombIsRefusedAtTheBudgetsLimit(): void
    {
        $http = new MockHttpClient(new MockResponse(GzipBomb::inflatingTo(ResponseSizeLimit::ChapterFile->bytes() * 100), [
            'response_headers' => ['content-encoding' => 'gzip'],
        ]));

        $this->expectException(ResponseTooLargeException::class);

        $this->client($http)->sendWithin(
            ApiRequestModel::get('https://api.example.test/x'),
            new ApiBudgetModel(1.5, 2.5, ResponseSizeLimit::ChapterFile),
        );
    }
```

Add `use App\Tests\Support\GzipBomb;`.

- [ ] **Step 2: Run the tests and see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/GuardedApiClientTest.php`
Expected: the three new tests fail.

- [ ] **Step 3: Implement**

- `optionsFor()`: `'Accept-Encoding' => ResponseBody::ACCEPT_ENCODING,`.
- `answerFrom()`:

```php
    /**
     * @throws ExceptionInterface
     * @throws FetchException     when the decoded body is too large or undecodable
     */
    private static function answerFrom(ResponseInterface $response, ResponseSizeLimit $limit): ApiResponseModel
    {
        return new ApiResponseModel(
            $response->getStatusCode(),
            ResponseBody::decoded($response, $limit),
            $response->getHeaders(false),
        );
    }
```

- Both callers (`sendWithin()` and `sendDirectAfterProxyRefusal()`) pass `$budget->sizeLimit` as the second argument.
- Import `ResponseBody` and `ResponseSizeLimit`.
- A `FetchException` from `decoded()` sits outside the `catch (ExceptionInterface)`, so it propagates as the
  `@throws FetchException` both methods already declare. The callers already catch `ResponseTooLargeException`
  (`GuardedTranscriptSource`, `GuardedChapterFileSource`, `GuardedDescriptionRequest`).

- [ ] **Step 4: Run the tests and see them pass**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/GuardedApiClientTest.php tests/Service/Social tests/Service/Transcript tests/Service/Chapter`
Expected: everything passes.

- [ ] **Step 5: Lint and commit**

Run: `cd backend && composer cs && vendor/bin/phpstan analyse src/Service/Fetch/GuardedApiClient.php tests/Service/Fetch/GuardedApiClientTest.php && composer md`

```bash
git add backend/src/Service/Fetch/GuardedApiClient.php backend/tests/Service/Fetch/GuardedApiClientTest.php
git commit -m "perf(#2157): fetch guarded API answers compressed"
```

---

### Task 5: Boy-scout — `ProxyConnectionTester` refuses transparent compression

**Files:**
- Modify: `backend/src/Service/Proxy/ProxyConnectionTester.php` (`'headers'` in `test()`)
- Test: `backend/tests/Service/Proxy/ProxyConnectionTesterTest.php` (`testRequestDisablesRedirectsAndAsksForPlainText`)

- [ ] **Step 1: Write the failing test**

Rename the test to `testRequestDisablesRedirectsAndAsksForUncompressedPlainText`, and add after the `Accept` assertion:
`self::assertContains('Accept-Encoding: identity', $headers);`.

- [ ] **Step 2: Run it and see it fail**

Run: `cd backend && php bin/phpunit tests/Service/Proxy/ProxyConnectionTesterTest.php`
Expected: FAIL on the new assertion.

- [ ] **Step 3: Implement**

`'headers' => ['Accept' => 'text/plain', 'Accept-Encoding' => 'identity'],`. With no `Accept-Encoding`, Symfony
inflates the echo whole before `substr()` caps it.

- [ ] **Step 4: Run it and see it pass, then commit**

Run: `cd backend && php bin/phpunit tests/Service/Proxy/ProxyConnectionTesterTest.php`

```bash
git add backend/src/Service/Proxy/ProxyConnectionTester.php backend/tests/Service/Proxy/ProxyConnectionTesterTest.php
git commit -m "fix(#2157): proxy test asks for an uncompressed echo"
```

---

### Task 6: Verification, live check, benchmark, PR

- [ ] **Step 1: Run all the gates**

From `backend/`:
- `composer check`
- `composer md`
- `php bin/phpunit` (SQLite)
- at the same time, `docker compose exec php composer test:parallel` (MySQL)
- `composer infection:diff`

Expected: all green. MSI must not drop below `minMsi`; an escaped mutant in `ResponseBody` gets a test, not a lower
gate. Also check today's dev log for deprecations:
`ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -r 'select(.level_name!="DEBUG" and .level_name!="INFO") | .message'`.

- [ ] **Step 2: Live check that bodies really travel compressed**

In the PHP container, boot the kernel from a scratch script, the way the #2158 benchmark scripts do. Then:
1. Call `HtmlPageFetcher::fetch()` for `https://www.tagesschau.de/` and `https://techcrunch.com/`.
2. Read the http_client log line, or wrap the client with a `TraceableHttpClient`, to get `size_download`.

Expected: `size_download` is roughly a quarter of `strlen($page->html)`, and the HTML parses (it starts with
`<!DOCTYPE` or `<html`).

Also refresh one real feed through `bin/console` (the worker's refresh command for a single subscription) and check
that it lands with new or unchanged entries, not an error.

- [ ] **Step 3: Benchmark against the #2158 baseline**

Rerun the reader group of the #2158 benchmark with the same lab setup, script and entry IDs. Both are in the issue
and in memory `performance-series-2148`: the prod-mode `php -S` lab server, the rest of the stack stopped, a warm-up
pass. Post the before/after table as a comment on #2158.

- [ ] **Step 4: Simplify, then open the PR**

Run `/simplify` over the branch diff and fix what it finds. Push `feature/2157-gzip-fetches` and open a PR into
`develop` with `Closes #2157`. The PR includes:
- the benchmark table;
- the bomb test's measured peak;
- the boy-scout fix named on its own.

No Claude attribution.
