# #782 follow-up: stream playback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the #782 players actually play: an HLS stream is emitted at the URL that finally serves it and hls.js takes it wherever MSE exists; one schema.org node yields one candidate.

**Architecture:** The redirect loop leaves `HtmlPageFetcher` for a shared `Service/Fetch/RedirectFollower` (per-hop SSRF guard, `max_redirects` forced to 0). A new `StreamLocationResolver` follows each `Stream` candidate to its landing after `PageMediaScanner::scan()` in `ArticleExtractor`. `JsonLdMediaSource` takes the first playable of `contentUrl`, `embedUrl` per node. The frontend attaches hls.js whenever `Hls.isSupported()` instead of trusting `canPlayType`.

**Tech Stack:** PHP 8.4 / Symfony 7.4 (`MockHttpClient` in tests), Angular 20 + hls.js 1.7 (Jest).

**Spec:** `docs/superpowers/specs/2026-09-02-782-stream-playback-follow-up-design.md`

## Global Constraints

- Every hop of every outbound redirect passes `UrlGuard::assertSafe()`; the HTTP client never follows a `Location` itself (`max_redirects` 0). This is the SSRF boundary — the follower is its single copy for the reader.
- `HtmlPageFetcher::fetch()` keeps its contract: `PageResponse` on 2xx, `PageFetchException` for everything else (SSRF block, transport error, redirect faults, non-2xx, byte cap).
- A `Stream` candidate is re-emitted only at a landing that `MediaUrlKind::resolve()` still classes as `Stream` (durable: https, no query, `.m3u8`); otherwise the declared URL stays.
- `JsonLdMediaSource` yields at most one candidate per schema.org node, in `URL_KEYS` order `contentUrl`, `embedUrl`.
- Frontend: hls.js is attached to every `.m3u8` `<video>` when `Hls.isSupported()`; `canPlayType` is not consulted. `preload="none"` semantics stay: `autoStartLoad: false`, `startLoad()` on the first `play`.
- Reader cache `VERSION` 14 → 15.
- Clean Code per CLAUDE.md: `final readonly`, guard clauses, no boolean flags, every touched `src` file PHPMD-clean, comments ≤ 3 lines. Commit messages `type(#782): summary`.
- Run backend tests from `backend/`: `php bin/phpunit <path>`; frontend Jest inside Docker: `docker compose exec -T frontend npx jest <path>`.

---

### Task 1: `RedirectFollower` — the reader's one redirect loop

**Files:**
- Create: `backend/src/Service/Fetch/RedirectFollower.php`
- Create: `backend/src/Service/Fetch/LandedResponse.php`
- Create: `backend/src/Service/Fetch/Exception/RedirectChainException.php`
- Create: `backend/tests/Service/Fetch/RedirectFollowerTest.php`
- Modify: `backend/src/Service/Reader/HtmlPageFetcher.php` (whole file)
- Modify: `backend/tests/Service/Reader/HtmlPageFetcherTest.php:40-44` (constructor)
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php:71-75` and `:218-222` (constructor)

**Interfaces:**
- Produces: `RedirectFollower::follow(string $url, array $options, int $maxRedirects): LandedResponse`; `LandedResponse{url, status, response}` with `isSuccess()`; `RedirectChainException extends FetchException`.
- Consumes: `FailoverRequestSender::send(string $method, string $url, GuardedUrl $guarded, array $options)`, `UrlGuard::assertSafe(string): GuardedUrl`, `UrlResolver::resolve(string $baseUrl, string $location): string`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Fetch/RedirectFollowerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RedirectFollowerTest extends TestCase
{
    /** @param callable|iterable<MockResponse> $responses */
    private function follower(callable|iterable $responses): RedirectFollower
    {
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);

        return new RedirectFollower(
            new FailoverRequestSender(new MockHttpClient($responses), $proxy),
            new UrlGuard($dns, new IpValidator()),
        );
    }

    private static function redirect(string $location, int $status = 301): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => ['location' => $location]]);
    }

    public function testLandsOnTheFirstNonRedirectResolvingARelativeLocation(): void
    {
        $follower = $this->follower([
            self::redirect('/moved/here'),
            self::redirect('https://cdn.example.com/master.m3u8', 302),
            new MockResponse('#EXTM3U', ['http_code' => 200]),
        ]);

        $landed = $follower->follow('https://example.com/start', [], 5);

        self::assertSame('https://cdn.example.com/master.m3u8', $landed->url);
        self::assertSame(200, $landed->status);
        self::assertTrue($landed->isSuccess());
        self::assertSame('#EXTM3U', $landed->response->getContent(false));
    }

    public function testReturnsANonSuccessLandingInsteadOfThrowing(): void
    {
        $landed = $this->follower([new MockResponse('gone', ['http_code' => 404])])
            ->follow('https://example.com/missing', [], 5);

        self::assertSame(404, $landed->status);
        self::assertFalse($landed->isSuccess());
    }

    public function testGuardsEveryHopSoARedirectIntoLinkLocalSpaceIsRefused(): void
    {
        $requested = [];
        $follower = $this->follower(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return self::redirect('http://169.254.169.254/latest/meta-data/');
        });

        try {
            $follower->follow('https://example.com/start', [], 5);
            self::fail('a hop into link-local space must be refused');
        } catch (RedirectChainException $e) {
            self::assertStringContainsString('169.254.169.254', $e->getMessage());
        }
        self::assertSame(['https://example.com/start'], $requested, 'the blocked host is never requested');
    }

    public function testRefusesMoreHopsThanAllowed(): void
    {
        $follower = $this->follower(static fn (): MockResponse => self::redirect('/again'));

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('more than 2 redirects');
        $follower->follow('https://example.com/start', [], 2);
    }

    public function testRefusesARedirectWithoutLocation(): void
    {
        $follower = $this->follower([new MockResponse('', ['http_code' => 302])]);

        $this->expectException(RedirectChainException::class);
        $this->expectExceptionMessage('redirect without Location');
        $follower->follow('https://example.com/start', [], 5);
    }

    public function testWrapsATransportFailure(): void
    {
        $follower = $this->follower([new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(RedirectChainException::class);
        $follower->follow('https://example.com/start', [], 5);
    }

    public function testTheClientNeverFollowsOnItsOwnWhateverTheCallerPasses(): void
    {
        $seen = null;
        $follower = $this->follower(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['max_redirects'];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $follower->follow('https://example.com/start', ['max_redirects' => 5, 'timeout' => 1.0], 5);

        self::assertSame(0, $seen);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Fetch/RedirectFollowerTest.php`
Expected: errors — class `RedirectFollower` not found.

- [ ] **Step 3: Write the production code**

`backend/src/Service/Fetch/Exception/RedirectChainException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

final class RedirectChainException extends FetchException
{
}
```

`backend/src/Service/Fetch/LandedResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\ResponseInterface;

/** Where a followed request came to rest: the URL that answered without redirecting, its status, and the open response. */
final readonly class LandedResponse
{
    public function __construct(
        public string $url,
        public int $status,
        public ResponseInterface $response,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
```

`backend/src/Service/Fetch/RedirectFollower.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\RedirectChainException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Follows a GET to the first response that is not a redirect, passing every hop
 * through the SSRF guard: `max_redirects` is forced to 0, so the client can never
 * follow a Location this class has not checked. The reader's page fetch and its
 * stream locator share it; the feed engine keeps ResponseClassifier, which is
 * built around FetchAttempt.
 */
final readonly class RedirectFollower
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private FailoverRequestSender $requestSender,
        private UrlGuard $urlGuard,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws RedirectChainException on a blocked hop, a transport failure, a redirect without Location, or too many hops
     */
    public function follow(string $url, array $options, int $maxRedirects): LandedResponse
    {
        $currentUrl = $url;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $response = $this->send($currentUrl, $options);
            $status = $this->statusCode($response, $currentUrl);
            if (!\in_array($status, self::REDIRECT_STATUSES, true)) {
                return new LandedResponse($currentUrl, $status, $response);
            }
            $currentUrl = $this->redirectTarget($response, $currentUrl);
        }

        throw new RedirectChainException(sprintf('%s: more than %d redirects', $url, $maxRedirects));
    }

    /** @param array<string, mixed> $options */
    private function send(string $url, array $options): ResponseInterface
    {
        try {
            $guarded = $this->urlGuard->assertSafe($url);

            return $this->requestSender->send('GET', $url, $guarded, ['max_redirects' => 0] + $options);
        } catch (FetchException | ExceptionInterface $e) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function redirectTarget(ResponseInterface $response, string $url): string
    {
        $location = $this->header($response, 'location');
        $response->cancel();
        if ($location === null) {
            throw new RedirectChainException(sprintf('%s: redirect without Location', $url));
        }

        try {
            return UrlResolver::resolve($url, $location);
        } catch (FetchException $e) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            return $response->getHeaders(false)[$name][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
```

`backend/src/Service/Reader/HtmlPageFetcher.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\LandedResponse;
use App\Service\Fetch\RedirectFollower;
use App\Service\Reader\Exception\PageFetchException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Retrieves an article's source HTML for reader-mode extraction: the guarded
 * redirect chain lives in RedirectFollower; this class negotiates HTML, caps the
 * body, and returns the decoded body plus the final URL (readability needs it
 * to resolve relative image URLs).
 */
final readonly class HtmlPageFetcher
{
    private const int MAX_REDIRECTS = 5;
    private const int MAX_BYTES = 3_000_000;
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private RedirectFollower $redirects,
        private string $userAgent,
    ) {
    }

    public function fetch(string $url): PageResponse
    {
        $landed = $this->land($url);
        if (!$landed->isSuccess()) {
            $landed->response->cancel();

            throw new PageFetchException(sprintf('%s: HTTP %d', $landed->url, $landed->status));
        }

        $body = $this->content($landed);
        if (\strlen($body) > self::MAX_BYTES) {
            throw new PageFetchException(sprintf('%s: response exceeds %d bytes', $landed->url, self::MAX_BYTES));
        }

        return new PageResponse($landed->url, $body);
    }

    private function land(string $url): LandedResponse
    {
        try {
            return $this->redirects->follow($url, $this->options(), self::MAX_REDIRECTS);
        } catch (RedirectChainException $e) {
            throw new PageFetchException($e->getMessage(), previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                // Refuse transparent compression: otherwise curl counts the
                // COMPRESSED bytes against MAX_BYTES in on_progress but buffers
                // the DECOMPRESSED body whole before the post-read size check —
                // a small gzip bomb could inflate to GB and OOM the worker.
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                }
            },
        ];
    }

    private function content(LandedResponse $landed): string
    {
        try {
            return $landed->response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $landed->url, $e->getMessage()), previous: $e);
        }
    }
}
```

- [ ] **Step 4: Update the three test constructions**

`backend/tests/Service/Reader/HtmlPageFetcherTest.php` — replace the `return new HtmlPageFetcher(…)` block of `fetcher()` with:

```php
        return new HtmlPageFetcher(
            new RedirectFollower(
                new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
                new UrlGuard($resolver, new IpValidator()),
            ),
            'TestAgent/1.0',
        );
```

and add `use App\Service\Fetch\RedirectFollower;`.

`backend/tests/Service/Reader/ArticleExtractorTest.php` — in `extractor()` (line 71) and `testFetchFailureMapsToFetchReason()` (line 218), build the fetcher as:

```php
        $fetcher = new HtmlPageFetcher(
            new RedirectFollower(
                new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
                new UrlGuard($resolver, new IpValidator()),
            ),
            'TestAgent/1.0',
        );
```

(the second site passes `new MockHttpClient()` with no responses, as today) and add the `use`. Task 2 restructures these two sites again; do the minimal edit here.

- [ ] **Step 5: Run the suites**

Run: `php bin/phpunit tests/Service/Fetch/RedirectFollowerTest.php tests/Service/Reader/HtmlPageFetcherTest.php tests/Service/Reader/ArticleExtractorTest.php`
Expected: PASS, every test.

- [ ] **Step 6: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean (PHPMD on `RedirectFollower.php`, `LandedResponse.php`, `HtmlPageFetcher.php`).

```bash
git add backend/src/Service/Fetch/RedirectFollower.php backend/src/Service/Fetch/LandedResponse.php backend/src/Service/Fetch/Exception/RedirectChainException.php backend/src/Service/Reader/HtmlPageFetcher.php backend/tests/Service/Fetch/RedirectFollowerTest.php backend/tests/Service/Reader/HtmlPageFetcherTest.php backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "refactor(#782): move the guarded redirect loop out of HtmlPageFetcher into RedirectFollower"
```

---

### Task 2: `StreamLocationResolver` — emit a stream where it lands

**Files:**
- Create: `backend/src/Service/Reader/Media/StreamLocationResolver.php`
- Create: `backend/tests/Service/Reader/Media/StreamLocationResolverTest.php`
- Modify: `backend/src/Service/Reader/Media/MediaCandidate.php` (add `at()`)
- Modify: `backend/tests/Service/Reader/Media/MediaCandidateTest.php` (add one test)
- Modify: `backend/src/Service/Reader/ArticleExtractor.php:44-64`
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (factories, ZDF test at ~line 503)

**Interfaces:**
- Consumes: `RedirectFollower::follow()` / `LandedResponse` (Task 1); `MediaUrlKind::resolve(string): ?ResolvedMediaUrl` with `->kind`, `->url`; `MediaKind::Stream`; `ArticleMedia::candidates`.
- Produces: `StreamLocationResolver::resolve(ArticleMedia): ArticleMedia`; `MediaCandidate::at(string $url): self`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Reader/Media/MediaCandidateTest.php`:

```php
    public function testAtMovesOnlyTheUrl(): void
    {
        $declared = new MediaCandidate(MediaKind::Stream, 'https://a.test/x.m3u8', 'p.jpg', null, 'prose');

        $landed = $declared->at('https://cdn.test/master.m3u8');

        self::assertSame('https://cdn.test/master.m3u8', $landed->url);
        self::assertSame(MediaKind::Stream, $landed->kind);
        self::assertSame('p.jpg', $landed->posterUrl);
        self::assertSame('prose', $landed->precedingText);
    }
```

(add `use App\Service\Reader\Media\MediaKind;` if the file lacks it.)

`backend/tests/Service/Reader/Media/StreamLocationResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FailoverRequestSender;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Fetch\RedirectFollower;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\StreamLocationResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StreamLocationResolverTest extends TestCase
{
    private const string DECLARED = 'https://www.zdfheute.de/api/video/istaf-100.m3u8';
    private const string LANDING = 'https://zdfvod.akamaized.net/i/mp4/none/zdf/26/09/istaf,_508k,_808k,v17.mp4.csmil/master.m3u8';

    /** @var list<string> */
    private array $requested = [];

    /** @param list<MockResponse> $responses */
    private function resolver(array $responses): StreamLocationResolver
    {
        $queue = $responses;
        $client = new MockHttpClient(function (string $method, string $url) use (&$queue): MockResponse {
            $this->requested[] = $url;

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
        });
        $dns = new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };
        $proxy = $this->createStub(ProxyEgressResolver::class);
        $proxy->method('resolve')->willReturn(null);
        $providers = new EmbedProviders([new YouTubeEmbedProvider()]);

        return new StreamLocationResolver(
            new RedirectFollower(new FailoverRequestSender($client, $proxy), new UrlGuard($dns, new IpValidator())),
            new MediaUrlKind(new DurableMediaUrl(), $providers),
            'TestAgent/1.0',
        );
    }

    private static function redirect(string $location): MockResponse
    {
        return new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $location]]);
    }

    private static function stream(): ArticleMedia
    {
        return new ArticleMedia([
            new MediaCandidate(MediaKind::Stream, self::DECLARED, 'https://www.zdfheute.de/poster.jpg', null, 'prose'),
        ]);
    }

    public function testReEmitsAStreamAtItsLanding(): void
    {
        $resolved = $this->resolver([self::redirect(self::LANDING), new MockResponse('#EXTM3U', ['http_code' => 200])])
            ->resolve(self::stream());

        self::assertSame(self::LANDING, $resolved->candidates[0]->url);
        self::assertSame(MediaKind::Stream, $resolved->candidates[0]->kind);
        self::assertSame('https://www.zdfheute.de/poster.jpg', $resolved->candidates[0]->posterUrl);
        self::assertSame('prose', $resolved->candidates[0]->precedingText);
    }

    public function testKeepsTheDeclaredUrlWhenTheChainLandsOnAnError(): void
    {
        $resolved = $this->resolver([self::redirect(self::LANDING), new MockResponse('', ['http_code' => 403])])
            ->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testKeepsTheDeclaredUrlWhenTheLandingIsTokenised(): void
    {
        $resolved = $this->resolver([
            self::redirect(self::LANDING . '?hdnts=exp=1'),
            new MockResponse('#EXTM3U', ['http_code' => 200]),
        ])->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testKeepsTheDeclaredUrlWhenTheChainFails(): void
    {
        $resolved = $this->resolver([new MockResponse('', ['error' => 'Connection reset by peer'])])
            ->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testKeepsTheDeclaredUrlWhenTheChainLandsOnAFile(): void
    {
        $resolved = $this->resolver([
            self::redirect('https://cdn.test/clip.mp4'),
            new MockResponse('', ['http_code' => 200]),
        ])->resolve(self::stream());

        self::assertSame(self::DECLARED, $resolved->candidates[0]->url);
    }

    public function testMakesNoRequestForAnythingButAStream(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://a.test/clip.mp4', 'p.jpg'),
            new MediaCandidate(MediaKind::Audio, 'https://a.test/show.mp3'),
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', 'p.jpg'),
        ]);

        $resolved = $this->resolver([])->resolve($media);

        self::assertSame([], $this->requested);
        self::assertSame($media->candidates, $resolved->candidates);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/StreamLocationResolverTest.php tests/Service/Reader/Media/MediaCandidateTest.php`
Expected: errors — `StreamLocationResolver` not found, `at()` undefined.

- [ ] **Step 3: Write the production code**

Add to `backend/src/Service/Reader/Media/MediaCandidate.php`, after `completedBy()`:

```php
    /** The same media served from where its URL finally lands. */
    public function at(string $url): self
    {
        return new self($this->kind, $url, $this->posterUrl, $this->label, $this->precedingText);
    }
```

`backend/src/Service/Reader/Media/StreamLocationResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\RedirectFollower;

/**
 * A stream is fetched by script, not by the media element, so it plays only from
 * the URL that finally serves it: a cross-origin fetch dies on a redirect hop
 * without a CORS header (zdfheute.de answers its playlist URL with a bare 301 to
 * Akamai). Each Stream candidate is followed to where it lands; a chain that
 * fails, or lands anywhere but on a durable playlist, keeps the declared URL —
 * the native client follows redirects on its own.
 */
final readonly class StreamLocationResolver
{
    private const int MAX_REDIRECTS = 5;
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private RedirectFollower $redirects,
        private MediaUrlKind $mediaUrlKind,
        private string $userAgent,
    ) {
    }

    public function resolve(ArticleMedia $media): ArticleMedia
    {
        return new ArticleMedia(array_map($this->located(...), $media->candidates));
    }

    private function located(MediaCandidate $candidate): MediaCandidate
    {
        if ($candidate->kind !== MediaKind::Stream) {
            return $candidate;
        }
        $landing = $this->mediaUrlKind->resolve($this->landingUrlOf($candidate->url));

        return $landing?->kind === MediaKind::Stream ? $candidate->at($landing->url) : $candidate;
    }

    private function landingUrlOf(string $url): string
    {
        try {
            $landed = $this->redirects->follow($url, $this->options(), self::MAX_REDIRECTS);
        } catch (RedirectChainException) {
            return $url;
        }
        $landed->response->cancel();

        return $landed->isSuccess() ? $landed->url : $url;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'headers' => [
                'Accept' => 'application/vnd.apple.mpegurl,application/x-mpegURL,*/*;q=0.8',
                'User-Agent' => $this->userAgent,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS * 2,
        ];
    }
}
```

`backend/src/Service/Reader/ArticleExtractor.php` — add the collaborator and apply it:

```php
    public function __construct(
        private readonly HtmlPageFetcher $fetcher,
        private readonly FetchedPageNormalizer $normalizer,
        private readonly ReaderBodyCleaner $bodyCleaner,
        private readonly EntrySanitizer $sanitizer,
        private readonly PageMediaScanner $mediaScanner,
        private readonly StreamLocationResolver $streamLocations,
    ) {
    }
```

and replace the scan line:

```php
        $media = $this->streamLocations->resolve($this->mediaScanner->scan($page->html, $page->finalUrl));
```

with `use App\Service\Reader\Media\StreamLocationResolver;`.

- [ ] **Step 4: Wire the tests and update the ZDF end-to-end assertion**

In `backend/tests/Service/Reader/ArticleExtractorTest.php`:

Restructure `extractor()` so one `RedirectFollower` serves both the fetcher and the resolver, and pass the resolver as the sixth constructor argument:

```php
        $redirects = new RedirectFollower(
            new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
            new UrlGuard($resolver, new IpValidator()),
        );

        return new ArticleExtractor(
            new HtmlPageFetcher($redirects, 'TestAgent/1.0'),
            new FetchedPageNormalizer(
                new CustomElementUnwrapper(),
                new LazyImageSources(),
                new ShareWidgetRemover(),
                new ShareIntentLinkRemover(),
                new SubstackGatedVideoPlaceholder(),
                new ImageWrapperClassRemover(),
            ),
            $this->bodyCleaner(),
            new EntrySanitizer(),
            $this->mediaScanner(),
            new StreamLocationResolver($redirects, $this->urlKind(), 'TestAgent/1.0'),
        );
```

Extract the `MediaUrlKind` construction that `mediaScanner()` already performs into a `private function urlKind(): MediaUrlKind` used by both `mediaScanner()` and the resolver (same providers list: YouTube + Brightcove). Apply the same shape to `testFetchFailureMapsToFetchReason()` (its `MockHttpClient()` has no responses; that is fine — the fetch fails before any media work).

Replace the body of `testEmitsAnHlsStreamAsAVideoWithItsPoster()`:

```php
    /** ZDF 491430: the stream is a <video> at the Akamai master its playlist URL redirects to (#782 follow-up). */
    public function testEmitsAnHlsStreamAsAVideoAtItsLanding(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/zdf-hls-video.html');
        $master = 'https://zdfvod.akamaized.net/i/mp4/none/zdf/26/08/260831_istaf_moma/1/'
            . '260831_istaf_moma,_508k_p9,_808k_p11,_1628k_p13,_3328k_p15,_6628k_p61,v17.mp4.csmil/master.m3u8';
        $result = $this->extractor(
            [
                new MockResponse($html, ['http_code' => 200]),
                new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => $master]]),
                new MockResponse('#EXTM3U', ['http_code' => 200]),
            ],
            ['www.zdfheute.de' => ['93.184.216.34'], 'zdfvod.akamaized.net' => ['93.184.216.35']],
        )->extract('https://www.zdfheute.de/video/zdf-morgenmagazin/istaf-berlin-em-stars-100.html');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertStringContainsString('src="' . $master . '"', $body);
        self::assertStringNotContainsString('zdfheute.de/api/video', $body);
        self::assertMatchesRegularExpression(
            '#<video[^>]*poster="https://www\.zdfheute\.de/assets/istaf-berlin-em-stars-102~1920x1080[^"]*"#',
            $body,
        );
        self::assertStringNotContainsString('ngp.zdf.de', $body);
    }
```

If the sanitizer entity-encodes any character of `$master` in the emitted `src`, assert with `html_entity_decode` applied to the body's `src="…"` match rather than loosening the URL — the #793 note on `&#61;` applies.

- [ ] **Step 5: Run the suites**

Run: `php bin/phpunit tests/Service/Reader tests/Service/Fetch`
Expected: PASS. In particular every other extractor test still passes with a single mocked response: a page without a stream makes no second request, and a fixture whose stream chain finds the mock exhausted keeps its declared URL.

- [ ] **Step 6: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean.

```bash
git add backend/src/Service/Reader/Media/StreamLocationResolver.php backend/src/Service/Reader/Media/MediaCandidate.php backend/src/Service/Reader/ArticleExtractor.php backend/tests/Service/Reader/Media/StreamLocationResolverTest.php backend/tests/Service/Reader/Media/MediaCandidateTest.php backend/tests/Service/Reader/ArticleExtractorTest.php
git commit -m "fix(#782): emit an HLS stream at the URL its playlist redirects to, so a cross-origin fetch survives"
```

---

### Task 3: One schema.org node, one candidate

**Files:**
- Modify: `backend/src/Service/Reader/Media/Source/JsonLdMediaSource.php:42-100`
- Modify: `backend/tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php` (two tests)
- Modify: `backend/tests/Service/Reader/ArticleExtractorTest.php` (one test)
- Create: `backend/tests/Fixtures/reader/media/aljazeera-file-and-brightcove.html`

**Interfaces:**
- Consumes: `MediaUrlKind`, `EmbedProviders` as today.
- Produces: unchanged public `find()`; at most one candidate per node.

- [ ] **Step 1: Write the failing tests**

Append to `JsonLdMediaSourceTest`:

```php
    /** Al Jazeera 495829: one VideoObject declares its file and its player page — one asset, one player. */
    public function testAFileBeatsThePlayerPageDeclaredOnTheSameNode(): void
    {
        $html = '<html><body><script type="application/ld+json">'
            . '{"@type":"VideoObject","contentUrl":"https://cdn.test/main.mp4",'
            . '"embedUrl":"https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6404485067112",'
            . '"thumbnailUrl":"https://x.test/poster.jpg"}'
            . '</script></body></html>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Video, $found[0]->kind);
        self::assertSame('https://cdn.test/main.mp4', $found[0]->url);
    }

    public function testThePlayerPageServesWhenTheNodesFileIsRefused(): void
    {
        $html = '<html><body><script type="application/ld+json">'
            . '{"@type":"VideoObject","contentUrl":"https://cdn.test/main.mp4",'
            . '"embedUrl":"https://www.youtube.com/watch?v=M1j_uRqKMKI"}'
            . '</script></body></html>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found, 'the poster-less file is refused (D5); the player page is the fallback');
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $found[0]->url);
    }
```

Fixture `backend/tests/Fixtures/reader/media/aljazeera-file-and-brightcove.html` — copy `aljazeera-brightcove.html` and, inside its `VideoObject`, add `"contentUrl":"https://ajmn-aje-vod.akamaized.net/media/v1/pmp4/static/clear/665003303001/f809b925-64e3-4bc0-8b1c-6b0f9dcf3b52/cb625c3e-c720-462f-8cc6-af9cad40a6c5/main.mp4",` directly before `"embedUrl"`. Keep everything else identical (the thumbnail image in the body is what the poster reconciles into).

Append to `ArticleExtractorTest`, beside the Brightcove test:

```php
    /** Al Jazeera 495829: the node's file plays in place of its thumbnail; the Brightcove page is not a second player. */
    public function testOneVideoObjectWithFileAndPlayerPageYieldsOnePlayer(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/media/aljazeera-file-and-brightcove.html');
        $result = $this->extractor(
            [new MockResponse($html, ['http_code' => 200])],
            ['www.aljazeera.com' => ['93.184.216.34']],
        )->extract('https://www.aljazeera.com/video/newsfeed/2026/9/2/video-chinese-president-xi');

        self::assertTrue($result->ok);
        $body = (string) $result->contentHtml;
        self::assertSame(1, substr_count($body, '<video'));
        self::assertStringContainsString('cb625c3e-c720-462f-8cc6-af9cad40a6c5/main.mp4', $body);
        self::assertStringNotContainsString('players.brightcove.net', $body);
    }
```

(Read the existing Brightcove test first and reuse its URL, DNS map and thumbnail-count style; the fixture's thumbnail filename decides the `substr_count` of the poster if you add that assertion.)

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php tests/Service/Reader/ArticleExtractorTest.php --filter 'SameNode|FileIsRefused|OnePlayer'`
Expected: FAIL — two candidates found; two players in the body.

- [ ] **Step 3: Write the production code**

In `JsonLdMediaSource`, replace `find()`'s inner loop, `urlsIn()`, `collect()`, and add `firstPlayable()`:

```php
    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $blocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $script) {
            if (PageFurniture::holds($script)) {
                continue;
            }
            foreach ($this->declarationsIn($script->textContent ?? '') as $declaration) {
                $candidate = $this->firstPlayable($declaration, $blocks->before($script));
                if ($candidate !== null) {
                    $found[$candidate->url] ??= $candidate;
                }
            }
        }

        return array_values($found);
    }

    /** @return list<array{urls: list<string>, poster: ?string}> */
    private function declarationsIn(string $jsonLd): array
    {
        $decoded = json_decode($jsonLd, true);

        return \is_array($decoded) ? $this->collect($decoded) : [];
    }

    /**
     * One node is one asset: its URL keys are gathered together, with the poster
     * schema.org places beside them (`thumbnailUrl` on the same node).
     *
     * @param array<mixed> $node
     *
     * @return list<array{urls: list<string>, poster: ?string}>
     */
    private function collect(array $node): array
    {
        $urls = [];
        foreach (self::URL_KEYS as $key) {
            if (isset($node[$key]) && \is_string($node[$key])) {
                $urls[] = $node[$key];
            }
        }
        $declarations = $urls === [] ? [] : [['urls' => $urls, 'poster' => $this->thumbnailIn($node)]];
        foreach ($node as $value) {
            if (\is_array($value)) {
                array_push($declarations, ...$this->collect($value));
            }
        }

        return $declarations;
    }

    /**
     * The file beats the player page of the same asset; the page is the fallback for a refused file.
     *
     * @param array{urls: list<string>, poster: ?string} $declaration
     */
    private function firstPlayable(array $declaration, ?string $precedingText): ?MediaCandidate
    {
        foreach ($declaration['urls'] as $url) {
            $candidate = $this->toCandidate($url, $declaration['poster'], $precedingText);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }
```

`thumbnailIn()` and `toCandidate()` stay as they are. Update the class docblock's last sentence to say that a node yields its first playable URL in `URL_KEYS` order.

- [ ] **Step 4: Run the suites**

Run: `php bin/phpunit tests/Service/Reader`
Expected: PASS, including every existing JSON-LD test (single-key nodes are unaffected) and `HostAgnosticDiscoveryTest`.

- [ ] **Step 5: Gates and commit**

Run from `backend/`: `composer cs:fix && composer check && composer md`
Expected: clean (PHPMD on `JsonLdMediaSource.php`).

```bash
git add backend/src/Service/Reader/Media/Source/JsonLdMediaSource.php backend/tests/Service/Reader/Media/Source/JsonLdMediaSourceTest.php backend/tests/Service/Reader/ArticleExtractorTest.php backend/tests/Fixtures/reader/media/aljazeera-file-and-brightcove.html
git commit -m "fix(#782): one schema.org node yields one candidate, its file before its player page"
```

---

### Task 4: hls.js takes every stream where MSE exists; cache v15

**Files:**
- Modify: `frontend/src/app/reader/hls-streams.ts`
- Modify: `frontend/src/app/reader/hls-streams.spec.ts`
- Modify: `frontend/src/app/reader/reader-cache.service.ts:30-44`

**Interfaces:**
- Produces: unchanged `attachHlsStreams(host: HTMLElement): void`.

- [ ] **Step 1: Write the failing test**

In `hls-streams.spec.ts`, replace `it('leaves a video alone when the browser plays HLS natively', …)` with:

```ts
  it('takes the stream even when the browser claims native HLS, because Chrome claims and never plays', async () => {
    HTMLMediaElement.prototype.canPlayType = () => 'maybe';
    const el = host('<video src="https://x.test/master.m3u8"></video>');
    attachHlsStreams(el);
    await flush();

    expect(attachMedia).toHaveBeenCalledWith(el.querySelector('video'));
  });
```

Keep `it('leaves the video alone when hls.js reports no support', …)` — that is the native fallback.

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/hls-streams.spec.ts`
Expected: FAIL — `attachMedia` not called.

- [ ] **Step 3: Write the production code**

`hls-streams.ts` — replace the docblock, drop `NATIVE_HLS`, and drop the `canPlayType` clause:

```ts
import type Hls from 'hls.js';

/**
 * Plays an HLS stream the backend emitted as a plain `<video src="….m3u8">`.
 *
 * `canPlayType` is no signal: Chrome answers "maybe" and then never plays the
 * playlist. So hls.js — loaded on demand, a lazy chunk outside the initial
 * bundle — takes every stream where Media Source Extensions exist, and only a
 * browser without them (iOS Safari) plays the playlist natively. `autoStartLoad`
 * is off and loading starts on the first play, so `preload="none"` keeps its
 * meaning.
 *
 * Runs in the reader's post-render pass beside upgradeMediaEmbeds. A re-render
 * replaces the whole body, so instances of detached videos are destroyed first.
 */
const PLAYLIST = /\.m3u8$/i;
const instances = new Map<HTMLVideoElement, Hls>();

export function attachHlsStreams(host: HTMLElement): void {
  destroyDetached();
  for (const video of Array.from(host.querySelectorAll('video'))) {
    const src = video.getAttribute('src') ?? '';
    if (!PLAYLIST.test(src) || instances.has(video)) continue;
    void attach(video, src).catch(() => undefined);
  }
}
```

`attach()` and `destroyDetached()` stay as they are.

`reader-cache.service.ts` — after the v14 comment add:

```ts
  // v15: v14 records hold an HLS stream at the URL the page declared, which a
  // cross-origin fetch cannot follow through its redirect, and two players for
  // a schema.org node that names both its file and its player page (#782).
  private static readonly VERSION = 15;
```

(replacing the `VERSION = 14` line.)

- [ ] **Step 4: Run the tests and the gate**

Run: `docker compose exec -T frontend npx jest src/app/reader/hls-streams.spec.ts src/app/reader/reader-cache.service.spec.ts`
Expected: PASS (adjust a cache spec only if it pins the literal version number).

Run from `frontend/`: `npm run check && npm run build`
Expected: clean; the initial bundle size unchanged from develop (hls.js stays a lazy chunk).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/hls-streams.ts frontend/src/app/reader/hls-streams.spec.ts frontend/src/app/reader/reader-cache.service.ts
git commit -m "fix(#782): let hls.js take every stream where MSE exists; Chrome claims native HLS and never plays it"
```

---

### Task 5: Verification

- [ ] **Step 1: Backend gates and both suite legs**

From `backend/`: `composer check && composer md && php bin/phpunit && composer infection:diff`
From the repo root: `docker compose exec php vendor/bin/phpunit`
Expected: all green; MSI at or above `minMsi`.

- [ ] **Step 2: Refresh the running stack**

The worker daemon and the php container load code once: `docker compose restart php worker` (then check `docker compose exec nginx wget -qO- http://php:9000 >/dev/null 2>&1; docker compose logs --tail=5 nginx` — if `/api` calls 502, recreate nginx too, see the memory note on the stale upstream IP).

- [ ] **Step 3: Live checks in Chrome (Lars, PIA off)**

Reload each article (the reader's refresh control, so v15 refetches):

| entry | expect |
|---|---|
| 491430, 489815, 496263 (ZDF) | the `<video>` src is the `zdfvod.akamaized.net/…/master.m3u8`; it plays after clicking play |
| 495829, 493987 (Al Jazeera, file + player page) | exactly one player, the mp4, where the thumbnail stood |
| 469835 (Al Jazeera, player page only) | the Brightcove player still plays |
| 495401 (vice) | still four players |
| an ardmediathek video entry | still exactly one player |

- [ ] **Step 4: Dev log**

`ls -t backend/var/log/dev-*.log | head -1 | xargs grep -c -i 'deprecat\|error'` — no new deprecations from the fetch refactor.

- [ ] **Step 5: PR**

Branch `fix/782-stream-playback-follow-up` → `develop`. Body: "Follow-up to #782 (PR #793): …" — no `Closes`, #782 is already closed.
