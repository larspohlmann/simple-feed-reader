# Reader Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clicking an article fetches its source page, extracts the main content distraction-free, renders it in the app's style, and caches it in the frontend.

**Architecture:** Backend fetches the source page (SSRF-guarded), extracts with readability.php, sanitizes with the existing `EntrySanitizer`, and returns a normalized JSON discriminated union. The frontend auto-loads it on open, renders it in the existing reader-view surface, caches successes in IndexedDB, and offers a Reader/Original toggle with feed-content fallback.

**Tech Stack:** Symfony 7.4 / PHP 8.3 (`fivefilters/readability.php`, `symfony/http-client`, `symfony/html-sanitizer`, `symfony/rate-limiter`); Angular 20 standalone (signals, IndexedDB, Jest).

**Spec:** `docs/superpowers/specs/2026-07-23-reader-mode-design.md`

---

## File Structure

**Backend (new):**
- `backend/src/Service/Reader/PageResponse.php` — value object `{finalUrl, html}`.
- `backend/src/Service/Reader/Exception/PageFetchException.php` — single failure type from the fetcher.
- `backend/src/Service/Reader/HtmlPageFetcher.php` — SSRF-guarded HTML GET (reuses `UrlGuard`/`UrlResolver`).
- `backend/src/Service/Reader/ExtractionResult.php` — discriminated result `ok(...)` / `failed(url, reason)`.
- `backend/src/Service/Reader/ArticleExtractor.php` — fetch → readability → sanitize → `ExtractionResult`.
- `backend/src/Http/ReaderJson.php` — serialize `ExtractionResult` to the API shape.

**Backend (modified):**
- `backend/composer.json` / `composer.lock` — add `fivefilters/readability.php`.
- `backend/config/packages/rate_limiter.yaml` — add a `reader` limiter.
- `backend/src/Controller/Api/EntryController.php` — add the `GET /api/entries/{id}/reader` action.

**Frontend (new):**
- `frontend/src/app/reader/reader-cache.service.ts` — IndexedDB store (LRU, schema-versioned).
- `frontend/src/app/reader/reader-content.service.ts` — cache-first orchestration.
- Specs for both, plus `reader-cache.service.spec.ts` uses `fake-indexeddb`.

**Frontend (modified):**
- `frontend/src/app/reader/models.ts` — `ReaderContent` union.
- `frontend/src/app/reader/reader-api.ts` — `readerContent(entryId)`.
- `frontend/src/app/reader/reader-view/reader-view.component.ts` (+ `.spec.ts`) — toggle, loading, fallback.
- `frontend/package.json` — add `fake-indexeddb` dev dependency.

---

## Backend

### Task 1: Add the readability.php dependency

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`

- [ ] **Step 1: Require the library**

Run (in `backend/`):
```bash
composer require fivefilters/readability.php
```
Expected: adds `fivefilters/readability.php` (and its `masterminds/html5` dep) to `require`, updates `composer.lock`. It targets PHP 8.1+, compatible with the 8.3 platform pin.

- [ ] **Step 2: Verify it loads and watch for deprecations**

Run:
```bash
php -r 'require "vendor/autoload.php"; new fivefilters\Readability\Readability(new fivefilters\Readability\Configuration()); echo "ok\n";'
```
Expected: prints `ok`. Then run any existing test (`vendor/bin/phpunit --filter Health`) and scan `var/log/dev.log` (or the Docker backend log) for new `User Deprecated` notices originating in `fivefilters/` or `masterminds/`. If deprecations appear, note them for the verification task — do not silence them here.

- [ ] **Step 3: Confirm composer.lock is tracked, then commit**

A global gitignore hides `composer.lock`; confirm this repo force-includes it (`git check-ignore -v composer.lock` should show a `!/composer.lock` negation, or `git status` should list the lock change).

```bash
git add composer.json composer.lock
git commit -m "build(reader): add fivefilters/readability.php for article extraction"
```

---

### Task 2: SSRF-guarded HTML page fetcher

**Files:**
- Create: `backend/src/Service/Reader/PageResponse.php`
- Create: `backend/src/Service/Reader/Exception/PageFetchException.php`
- Create: `backend/src/Service/Reader/HtmlPageFetcher.php`
- Test: `backend/tests/Service/Reader/HtmlPageFetcherTest.php`

This mirrors `HttpFeedFetcher`'s per-hop guard loop (re-validating every redirect target closes the DNS-rebinding window) but returns the HTML body plus the final URL, and sends an HTML `Accept` header. It reuses the shared SSRF core (`UrlGuard`, `IpValidator`, DNS pinning) and `UrlResolver`.

- [ ] **Step 1: Write the value object and exception**

`backend/src/Service/Reader/PageResponse.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

final readonly class PageResponse
{
    public function __construct(
        public string $finalUrl,
        public string $html,
    ) {
    }
}
```

`backend/src/Service/Reader/Exception/PageFetchException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Exception;

/**
 * Any reason the source page could not be retrieved — SSRF-blocked, oversized,
 * non-2xx, too many redirects, or a transport error. ArticleExtractor maps this
 * to the `fetch` failure reason; the underlying cause is preserved for logs.
 */
final class PageFetchException extends \RuntimeException
{
}
```

- [ ] **Step 2: Write the failing test**

`backend/tests/Service/Reader/HtmlPageFetcherTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedUrl;
use App\Service\Fetch\UrlGuard;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HtmlPageFetcherTest extends TestCase
{
    public function testReturnsBodyAndFinalUrlOnSuccess(): void
    {
        $guard = $this->createMock(UrlGuard::class);
        $guard->method('assertSafe')->willReturn(new GuardedUrl('example.com', '93.184.216.34'));

        $client = new MockHttpClient(new MockResponse('<html><body>hi</body></html>', [
            'http_code' => 200,
        ]));

        $fetcher = new HtmlPageFetcher($client, $guard);
        $result = $fetcher->fetch('https://example.com/post');

        self::assertStringContainsString('hi', $result->html);
        self::assertSame('https://example.com/post', $result->finalUrl);
    }

    public function testWrapsSsrfBlockInPageFetchException(): void
    {
        $guard = $this->createMock(UrlGuard::class);
        $guard->method('assertSafe')->willThrowException(new SsrfBlockedException('blocked'));

        $fetcher = new HtmlPageFetcher(new MockHttpClient(), $guard);

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('http://169.254.169.254/latest/meta-data/');
    }

    public function testRejectsNon2xx(): void
    {
        $guard = $this->createMock(UrlGuard::class);
        $guard->method('assertSafe')->willReturn(new GuardedUrl('example.com', '93.184.216.34'));

        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 404]));
        $fetcher = new HtmlPageFetcher($client, $guard);

        $this->expectException(PageFetchException::class);
        $fetcher->fetch('https://example.com/missing');
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run (in `backend/`): `vendor/bin/phpunit tests/Service/Reader/HtmlPageFetcherTest.php`
Expected: FAIL — `HtmlPageFetcher` class not found.

- [ ] **Step 4: Implement `HtmlPageFetcher`**

`backend/src/Service/Reader/HtmlPageFetcher.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedUrl;
use App\Service\Fetch\UrlGuard;
use App\Service\Fetch\UrlResolver;
use App\Service\Reader\Exception\PageFetchException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Retrieves an article's source HTML for reader-mode extraction. Structurally a
 * sibling of HttpFeedFetcher — same SSRF-guarded, per-hop-revalidated redirect
 * loop and byte cap — but returns the decoded body plus the final URL (readability
 * needs it to resolve relative image URLs) and negotiates HTML, not feed XML.
 */
final class HtmlPageFetcher
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 3_000_000;
    private const TIMEOUT_SECONDS = 10.0;
    private const USER_AGENT = 'SimpleFeedReader/1.0 (+https://github.com/larspohlmann/simple-feed-reader)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGuard $urlGuard,
    ) {
    }

    public function fetch(string $url): PageResponse
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $guarded = $this->urlGuard->assertSafe($currentUrl);
            } catch (SsrfBlockedException $e) {
                throw new PageFetchException($e->getMessage(), previous: $e);
            }

            $response = $this->request($currentUrl, $guarded);
            $status = $this->statusCode($response, $currentUrl);

            if (\in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $this->header($response, 'location');
                $response->cancel();
                if ($location === null) {
                    throw new PageFetchException(sprintf('%s: redirect without Location', $currentUrl));
                }
                $currentUrl = UrlResolver::resolve($currentUrl, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                $response->cancel();

                throw new PageFetchException(sprintf('%s: HTTP %d', $currentUrl, $status));
            }

            $body = $this->content($response, $currentUrl);
            if (\strlen($body) > self::MAX_BYTES) {
                throw new PageFetchException(sprintf('%s: response exceeds %d bytes', $currentUrl, self::MAX_BYTES));
            }

            return new PageResponse($currentUrl, $body);
        }

        throw new PageFetchException(sprintf('%s: more than %d redirects', $url, self::MAX_REDIRECTS));
    }

    private function request(string $url, GuardedUrl $guarded): ResponseInterface
    {
        try {
            return $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'User-Agent' => self::USER_AGENT,
                ],
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'resolve' => [$guarded->host => $guarded->ip],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new PageFetchException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                    }
                },
            ]);
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new PageFetchException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
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

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Service/Reader/HtmlPageFetcherTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/PageResponse.php backend/src/Service/Reader/Exception/PageFetchException.php backend/src/Service/Reader/HtmlPageFetcher.php backend/tests/Service/Reader/HtmlPageFetcherTest.php
git commit -m "feat(reader): SSRF-guarded HTML page fetcher"
```

---

### Task 3: Article extractor (readability + sanitizer)

**Files:**
- Create: `backend/src/Service/Reader/ExtractionResult.php`
- Create: `backend/src/Service/Reader/ArticleExtractor.php`
- Test: `backend/tests/Service/Reader/ArticleExtractorTest.php`
- Test fixture: `backend/tests/Fixtures/reader/article.html`

The existing `EntrySanitizer` is reused unchanged: its `allowSafeElements()` already permits article structure (headings, `blockquote`, `pre`, `code`, `figure`, `figcaption`, lists, `p`, `a`) and it explicitly adds `img` — exactly the reader-mode allowlist. readability's `FixRelativeURLs` + `OriginalURL` make image/link URLs absolute so direct image loading works.

- [ ] **Step 1: Write the result object**

`backend/src/Service/Reader/ExtractionResult.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Discriminated outcome of an extraction. `ok` carries the cleaned article;
 * `failed` carries a machine reason the client switches on:
 *   no_url        — the entry has no source URL to fetch
 *   fetch         — the page could not be retrieved (network / SSRF-blocked / oversized)
 *   unextractable — readability could not find an article
 *   empty         — extraction produced nothing after sanitization
 */
final readonly class ExtractionResult
{
    private function __construct(
        public bool $ok,
        public ?string $url,
        public ?string $reason,
        public ?string $title,
        public ?string $byline,
        public ?string $siteName,
        public ?string $contentHtml,
        public ?string $excerpt,
    ) {
    }

    public static function ok(
        string $url,
        string $title,
        ?string $byline,
        ?string $siteName,
        string $contentHtml,
        ?string $excerpt,
    ): self {
        return new self(true, $url, null, $title, $byline, $siteName, $contentHtml, $excerpt);
    }

    public static function failed(?string $url, string $reason): self
    {
        return new self(false, $url, $reason, null, null, null, null, null);
    }
}
```

- [ ] **Step 2: Write the failing test + fixture**

`backend/tests/Fixtures/reader/article.html` — a minimal but realistic page (nav + article + relative image):
```html
<!DOCTYPE html>
<html><head><title>Site — Post</title></head>
<body>
  <nav><a href="/">Home</a><a href="/about">About</a></nav>
  <article>
    <h1>The Real Headline</h1>
    <p>First substantial paragraph with enough words to be recognised as the
       article body by the extraction heuristics, repeated to add length. First
       substantial paragraph with enough words to be recognised as the article
       body by the extraction heuristics, repeated to add length.</p>
    <figure><img src="/img/photo.jpg" alt="A photo"></figure>
    <p>Second substantial paragraph, again long enough to matter to the scoring
       so readability keeps it. Second substantial paragraph, again long enough
       to matter to the scoring so readability keeps it in the output body.</p>
  </article>
  <footer>© 2026</footer>
</body></html>
```

`backend/tests/Service/Reader/ArticleExtractorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\EntrySanitizer;
use App\Service\Reader\ArticleExtractor;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\PageResponse;
use PHPUnit\Framework\TestCase;

final class ArticleExtractorTest extends TestCase
{
    private function extractorReturning(PageResponse $page): ArticleExtractor
    {
        $fetcher = $this->createMock(HtmlPageFetcher::class);
        $fetcher->method('fetch')->willReturn($page);

        return new ArticleExtractor($fetcher, new EntrySanitizer());
    }

    public function testExtractsAndAbsolutisesImages(): void
    {
        $html = file_get_contents(__DIR__ . '/../../Fixtures/reader/article.html');
        $extractor = $this->extractorReturning(new PageResponse('https://site.test/post', $html));

        $result = $extractor->extract('https://site.test/post');

        self::assertTrue($result->ok);
        self::assertStringContainsString('Real Headline', (string) $result->title);
        self::assertStringContainsString('substantial paragraph', (string) $result->contentHtml);
        // Relative image resolved to absolute, and it survived sanitization.
        self::assertStringContainsString('https://site.test/img/photo.jpg', (string) $result->contentHtml);
        // Chrome (nav/footer) is gone.
        self::assertStringNotContainsString('About', (string) $result->contentHtml);
    }

    public function testStripsDangerousMarkup(): void
    {
        $html = '<html><body><article><h1>Hi</h1>'
            . str_repeat('<p>Real readable body content that scores well enough. </p>', 5)
            . '<script>alert(1)</script></article></body></html>';
        $extractor = $this->extractorReturning(new PageResponse('https://site.test/x', $html));

        $result = $extractor->extract('https://site.test/x');

        self::assertTrue($result->ok);
        self::assertStringNotContainsString('<script', (string) $result->contentHtml);
    }

    public function testFetchFailureMapsToFetchReason(): void
    {
        $fetcher = $this->createMock(HtmlPageFetcher::class);
        $fetcher->method('fetch')->willThrowException(new PageFetchException('blocked'));
        $extractor = new ArticleExtractor($fetcher, new EntrySanitizer());

        $result = $extractor->extract('http://169.254.169.254/');

        self::assertFalse($result->ok);
        self::assertSame('fetch', $result->reason);
    }

    public function testUnextractablePageMapsToReason(): void
    {
        $extractor = $this->extractorReturning(new PageResponse('https://site.test/x', '<html><body></body></html>'));

        $result = $extractor->extract('https://site.test/x');

        self::assertFalse($result->ok);
        self::assertContains($result->reason, ['unextractable', 'empty']);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: FAIL — `ArticleExtractor` not found.

- [ ] **Step 4: Implement `ArticleExtractor`**

`backend/src/Service/Reader/ArticleExtractor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\EntrySanitizer;
use App\Service\Reader\Exception\PageFetchException;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;

/**
 * Turns an article URL into clean, sanitized, distraction-free HTML:
 * fetch (SSRF-guarded) → readability extraction → EntrySanitizer (the same XSS
 * barrier feed HTML crosses). Never throws for an ordinary failure — returns a
 * `failed` ExtractionResult with a machine reason so the endpoint stays 200 and
 * the client can fall back to feed content.
 */
final class ArticleExtractor
{
    /** Below this many characters of extracted text, treat as not an article. */
    private const MIN_CONTENT_LENGTH = 200;

    public function __construct(
        private readonly HtmlPageFetcher $fetcher,
        private readonly EntrySanitizer $sanitizer,
    ) {
    }

    public function extract(string $url): ExtractionResult
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (PageFetchException) {
            return ExtractionResult::failed($url, 'fetch');
        }

        $config = new Configuration();
        $config->setFixRelativeURLs(true);
        $config->setOriginalURL($page->finalUrl);

        $readability = new Readability($config);
        try {
            $readability->parse($page->html);
        } catch (ParseException) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        $rawContent = $readability->getContent();
        if ($rawContent === null || mb_strlen(strip_tags($rawContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $clean = $this->sanitizer->sanitize($rawContent);
        if ($clean === null) {
            return ExtractionResult::failed($url, 'empty');
        }

        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $readability->getTitle() ?? '',
            byline: $readability->getAuthor(),
            siteName: $readability->getSiteName(),
            contentHtml: $clean,
            excerpt: $readability->getExcerpt(),
        );
    }
}
```

> **Note for implementer:** verify the exact readability.php method/namespace names against the installed version (`vendor/fivefilters/readability.php`). The library uses the `fivefilters\Readability` namespace; getters are `getContent()`, `getTitle()`, `getExcerpt()`, `getAuthor()`, `getSiteName()`. If a getter differs, adjust — the mapping (author→byline) is what matters. If `getContent()` triggers a PHP 8.3 deprecation, capture it for the verification task rather than suppressing it.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Service/Reader/ArticleExtractorTest.php`
Expected: PASS (4 tests). If readability scores the fixture below threshold, lengthen the fixture paragraphs — do not lower `MIN_CONTENT_LENGTH` below a real-article floor.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Reader/ExtractionResult.php backend/src/Service/Reader/ArticleExtractor.php backend/tests/Service/Reader/ArticleExtractorTest.php backend/tests/Fixtures/reader/article.html
git commit -m "feat(reader): article extractor (readability + sanitizer)"
```

---

### Task 4: Reader endpoint + rate limiter

**Files:**
- Modify: `backend/config/packages/rate_limiter.yaml`
- Create: `backend/src/Http/ReaderJson.php`
- Modify: `backend/src/Controller/Api/EntryController.php`
- Test: `backend/tests/Controller/Api/EntryReaderControllerTest.php`

- [ ] **Step 1: Add the `reader` limiter**

Append to the `framework.rate_limiter:` block in `backend/config/packages/rate_limiter.yaml`:
```yaml
        # Per-user cap on reader-mode extraction. Each open triggers at most one
        # outbound page fetch, and the frontend caches successes in IndexedDB, so
        # honest usage is low; this only guards against a scripted user driving
        # outbound fetches. Keyed on the user id. Sliding window and the same pool
        # as its neighbours, for the reasons documented above.
        reader:
            policy: 'sliding_window'
            limit: 60
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```
This auto-registers an autowire alias `RateLimiterFactoryInterface $readerLimiter` (same mechanism `refresh` → `$refreshLimiter` uses).

- [ ] **Step 2: Write the JSON serializer**

`backend/src/Http/ReaderJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reader\ExtractionResult;

final class ReaderJson
{
    /**
     * @return array{status: 'ok', url: string, title: string, byline: string|null,
     *   siteName: string|null, contentHtml: string, excerpt: string|null, extractedAt: string}
     *  |array{status: 'failed', url: string|null, reason: string}
     */
    public static function one(ExtractionResult $r, \DateTimeImmutable $now): array
    {
        if (!$r->ok) {
            return ['status' => 'failed', 'url' => $r->url, 'reason' => (string) $r->reason];
        }

        return [
            'status' => 'ok',
            'url' => (string) $r->url,
            'title' => (string) $r->title,
            'byline' => $r->byline,
            'siteName' => $r->siteName,
            'contentHtml' => (string) $r->contentHtml,
            'excerpt' => $r->excerpt,
            'extractedAt' => $now->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

- [ ] **Step 3: Write the failing controller test**

`backend/tests/Controller/Api/EntryReaderControllerTest.php` — follow the existing controller-test pattern in `backend/tests/Controller/Api/` (WebTestCase, authenticated client, seeded user/subscription/entry). Cover:
```php
// Sketch — align helpers/fixtures with the sibling EntryController tests.
public function testReturnsOkArticleForOwnedEntry(): void
{
    // Given an owned entry whose URL an injected fake ArticleExtractor extracts OK,
    // GET /api/entries/{id}/reader returns 200 { status: "ok", contentHtml, ... }.
}

public function testReturnsFailedWhenExtractionFails(): void
{
    // Fake extractor returns ExtractionResult::failed(url, 'fetch');
    // endpoint still 200 with { status: "failed", reason: "fetch" }.
}

public function testEntryWithoutUrlReturnsNoUrl(): void
{
    // Owned entry, url = null → 200 { status: "failed", reason: "no_url" }, no fetch attempted.
}

public function testUnownedEntryIs404(): void
{
    // Entry belonging to another user → 404 (IDOR guard), extractor never called.
}

public function testRequiresAuthentication(): void
{
    // No bearer token → 401.
}
```
Inject a fake `ArticleExtractor` for the ok/failed cases by overriding the service in `config/services_test.yaml` or via the container in the test, so no real network call happens. The unowned/no-url/auth cases must assert the extractor is **not** invoked.

- [ ] **Step 4: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Controller/Api/EntryReaderControllerTest.php`
Expected: FAIL — route `api_entries_reader` not found (404 everywhere).

- [ ] **Step 5: Add the controller action**

In `backend/src/Controller/Api/EntryController.php`, add constructor deps and the action.

Add `use` imports:
```php
use App\Exception\RateLimitedException;
use App\Http\ReaderJson;
use App\Service\Reader\ArticleExtractor;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
```

Extend the constructor with:
```php
        private readonly ArticleExtractor $extractor,
        private readonly RateLimiterFactoryInterface $readerLimiter,
```

Add the action (mirrors the ownership + rate-limit patterns already in this controller and `RefreshController`):
```php
    #[Route('/{id}/reader', name: 'api_entries_reader', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function reader(
        int $id,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $entry = $this->entries->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $this->enforceReaderLimit($user);

        $url = $entry->getUrl();
        $result = $url === null || $url === ''
            ? ExtractionResult::failed(null, 'no_url')
            : $this->extractor->extract($url);

        return new JsonResponse(ReaderJson::one($result, $this->clock->now()));
    }

    private function enforceReaderLimit(User $user): void
    {
        $limit = $this->readerLimiter->create('user-' . $user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw new RateLimitedException(
            max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
        );
    }
```
Add `use App\Service\Reader\ExtractionResult;` for the `no_url` branch. Ownership is checked **before** the limiter so an unowned id is a clean 404 that never spends the caller's budget.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Controller/Api/EntryReaderControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Backend quality gates on touched files, then commit**

Run (in `backend/`):
```bash
composer cs && composer stan && vendor/bin/phpmd src/Service/Reader,src/Http/ReaderJson.php,src/Controller/Api/EntryController.php text phpmd.xml.dist
```
Expected: clean on the touched files (phpmd standing rule: touched src files must be phpmd-clean, not merely no-new-findings). Also run the PhpStorm inspection gate (`lint_files`) on the changed PHP; block on ERROR/WARNING.

```bash
git add backend/config/packages/rate_limiter.yaml backend/src/Http/ReaderJson.php backend/src/Controller/Api/EntryController.php backend/tests/Controller/Api/EntryReaderControllerTest.php
git commit -m "feat(reader): GET /api/entries/{id}/reader endpoint with per-user rate limit"
```

---

## Frontend

### Task 5: Reader content model + API method

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Test: `frontend/src/app/reader/reader-api.spec.ts` (extend if it exists; else create)

- [ ] **Step 1: Add the model**

Append to `frontend/src/app/reader/models.ts`:
```ts
/** A successfully extracted reader-mode article (GET /api/entries/{id}/reader). */
export interface ReaderArticle {
  status: 'ok';
  url: string;
  title: string;
  byline: string | null;
  siteName: string | null;
  contentHtml: string;
  excerpt: string | null;
  extractedAt: string;
}

/** Extraction could not produce an article; the client falls back to feed content. */
export interface ReaderFailure {
  status: 'failed';
  url: string | null;
  reason: 'no_url' | 'fetch' | 'unextractable' | 'empty';
}

export type ReaderContent = ReaderArticle | ReaderFailure;
```

- [ ] **Step 2: Write the failing API test**

Create/extend `frontend/src/app/reader/reader-api.spec.ts` using `HttpTestingController` (match the existing test style in the reader folder):
```ts
it('GETs reader content for an entry', () => {
  let received: ReaderContent | undefined;
  api.readerContent(42).subscribe((c) => (received = c));

  const req = httpMock.expectOne((r) => r.url.endsWith('/api/entries/42/reader'));
  expect(req.request.method).toBe('GET');
  req.flush({ status: 'failed', url: null, reason: 'no_url' } satisfies ReaderContent);

  expect(received?.status).toBe('failed');
});
```

- [ ] **Step 3: Run to verify it fails**

Run (in `frontend/`): `npx jest reader-api`
Expected: FAIL — `readerContent` is not a function.

- [ ] **Step 4: Add the API method**

In `frontend/src/app/reader/reader-api.ts`, add `ReaderContent` to the model import and add:
```ts
  readerContent(entryId: number): Observable<ReaderContent> {
    return this.http.get<ReaderContent>(`${this.base}/api/entries/${entryId}/reader`);
  }
```

- [ ] **Step 5: Run to verify it passes**

Run: `npx jest reader-api`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts
git commit -m "feat(reader): ReaderContent model and readerContent() API"
```

---

### Task 6: IndexedDB cache service

**Files:**
- Modify: `frontend/package.json` (add `fake-indexeddb` dev dep)
- Create: `frontend/src/app/reader/reader-cache.service.ts`
- Test: `frontend/src/app/reader/reader-cache.service.spec.ts`

- [ ] **Step 1: Add the test dependency**

Run (in `frontend/`): `npm install --save-dev fake-indexeddb`

- [ ] **Step 2: Write the failing test**

`frontend/src/app/reader/reader-cache.service.spec.ts`:
```ts
import 'fake-indexeddb/auto';
import { ReaderCacheService } from './reader-cache.service';
import { ReaderArticle } from './models';

function article(url: string): ReaderArticle {
  return {
    status: 'ok', url, title: 'T', byline: null, siteName: null,
    contentHtml: '<p>body</p>', excerpt: null, extractedAt: '2026-07-23T00:00:00Z',
  };
}

describe('ReaderCacheService', () => {
  let cache: ReaderCacheService;

  beforeEach(async () => {
    indexedDB = new IDBFactory(); // fresh DB per test
    cache = new ReaderCacheService();
  });

  it('returns null on a miss and the article on a hit', async () => {
    expect(await cache.get(1)).toBeNull();
    await cache.put(1, article('https://x/1'));
    expect((await cache.get(1))?.url).toBe('https://x/1');
  });

  it('evicts the oldest entry past the LRU cap', async () => {
    for (let i = 1; i <= ReaderCacheService.MAX_ENTRIES + 1; i++) {
      await cache.put(i, article('https://x/' + i));
    }
    // The very first inserted entry was evicted; the newest remains.
    expect(await cache.get(1)).toBeNull();
    expect(await cache.get(ReaderCacheService.MAX_ENTRIES + 1)).not.toBeNull();
  });
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `npx jest reader-cache`
Expected: FAIL — service not found.

- [ ] **Step 4: Implement the cache**

`frontend/src/app/reader/reader-cache.service.ts`:
```ts
import { Injectable } from '@angular/core';
import { ReaderArticle } from './models';

interface CacheRecord {
  entryId: number;
  article: ReaderArticle;
  cachedAt: number;
}

/**
 * Persistent, size-capped cache of extracted articles, keyed by entry id.
 * Only successful extractions are stored (failures should be retryable). Article
 * content is immutable per entry, so there is no staleness logic — the schema
 * version is the only cache-buster.
 */
@Injectable({ providedIn: 'root' })
export class ReaderCacheService {
  static readonly MAX_ENTRIES = 100;
  private static readonly DB = 'sfr-reader';
  private static readonly STORE = 'articles';
  private static readonly VERSION = 1;

  private db: Promise<IDBDatabase | null> | null = null;

  async get(entryId: number): Promise<ReaderArticle | null> {
    const db = await this.open();
    if (!db) return null;
    return new Promise((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readonly');
      const req = tx.objectStore(ReaderCacheService.STORE).get(entryId);
      req.onsuccess = () => resolve((req.result as CacheRecord | undefined)?.article ?? null);
      req.onerror = () => resolve(null);
    });
  }

  async put(entryId: number, article: ReaderArticle): Promise<void> {
    const db = await this.open();
    if (!db) return;
    const record: CacheRecord = { entryId, article, cachedAt: Date.now() };
    await new Promise<void>((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readwrite');
      tx.objectStore(ReaderCacheService.STORE).put(record);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
    await this.evict(db);
  }

  private async evict(db: IDBDatabase): Promise<void> {
    await new Promise<void>((resolve) => {
      const tx = db.transaction(ReaderCacheService.STORE, 'readwrite');
      const store = tx.objectStore(ReaderCacheService.STORE);
      const countReq = store.count();
      countReq.onsuccess = () => {
        const over = countReq.result - ReaderCacheService.MAX_ENTRIES;
        if (over <= 0) return;
        // Oldest-first via the cachedAt index; delete the surplus.
        let removed = 0;
        store.index('cachedAt').openCursor().onsuccess = (e) => {
          const cursor = (e.target as IDBRequest<IDBCursorWithValue | null>).result;
          if (!cursor || removed >= over) return;
          cursor.delete();
          removed++;
          cursor.continue();
        };
      };
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    });
  }

  private open(): Promise<IDBDatabase | null> {
    if (this.db) return this.db;
    this.db = new Promise((resolve) => {
      if (typeof indexedDB === 'undefined') return resolve(null);
      const req = indexedDB.open(ReaderCacheService.DB, ReaderCacheService.VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        // Bumping VERSION drops the old store — the schema-version cache-bust.
        if (db.objectStoreNames.contains(ReaderCacheService.STORE)) {
          db.deleteObjectStore(ReaderCacheService.STORE);
        }
        const store = db.createObjectStore(ReaderCacheService.STORE, { keyPath: 'entryId' });
        store.createIndex('cachedAt', 'cachedAt');
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
    });
    return this.db;
  }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `npx jest reader-cache`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/app/reader/reader-cache.service.ts frontend/src/app/reader/reader-cache.service.spec.ts
git commit -m "feat(reader): IndexedDB article cache with LRU cap"
```

---

### Task 7: Cache-first content service

**Files:**
- Create: `frontend/src/app/reader/reader-content.service.ts`
- Test: `frontend/src/app/reader/reader-content.service.spec.ts`

- [ ] **Step 1: Write the failing test**

`frontend/src/app/reader/reader-content.service.spec.ts`:
```ts
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ReaderContentService } from './reader-content.service';
import { ReaderCacheService } from './reader-cache.service';
import { ReaderApi } from './reader-api';
import { ReaderArticle, ReaderContent } from './models';

const ARTICLE: ReaderArticle = {
  status: 'ok', url: 'https://x/1', title: 'T', byline: null, siteName: null,
  contentHtml: '<p>b</p>', excerpt: null, extractedAt: '2026-07-23T00:00:00Z',
};

describe('ReaderContentService', () => {
  let apiGet: jest.Mock;
  let cacheGet: jest.Mock;
  let cachePut: jest.Mock;

  beforeEach(() => {
    apiGet = jest.fn();
    cacheGet = jest.fn();
    cachePut = jest.fn().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      providers: [
        ReaderContentService,
        { provide: ReaderApi, useValue: { readerContent: apiGet } },
        { provide: ReaderCacheService, useValue: { get: cacheGet, put: cachePut } },
      ],
    });
  });

  it('serves a cache hit without calling the API', async () => {
    cacheGet.mockResolvedValue(ARTICLE);
    const svc = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(svc.load(1));
    expect(result).toEqual(ARTICLE);
    expect(apiGet).not.toHaveBeenCalled();
  });

  it('fetches and caches on a miss', async () => {
    cacheGet.mockResolvedValue(null);
    apiGet.mockReturnValue(of(ARTICLE));
    const svc = TestBed.inject(ReaderContentService);
    const result = await firstValueFrom(svc.load(1));
    expect(result).toEqual(ARTICLE);
    expect(cachePut).toHaveBeenCalledWith(1, ARTICLE);
  });

  it('does not cache a failure', async () => {
    cacheGet.mockResolvedValue(null);
    const failure: ReaderContent = { status: 'failed', url: null, reason: 'fetch' };
    apiGet.mockReturnValue(of(failure));
    const svc = TestBed.inject(ReaderContentService);
    await firstValueFrom(svc.load(1));
    expect(cachePut).not.toHaveBeenCalled();
  });
});
```
(Add the `firstValueFrom` import from `rxjs`.)

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest reader-content`
Expected: FAIL — service not found.

- [ ] **Step 3: Implement the service**

`frontend/src/app/reader/reader-content.service.ts`:
```ts
import { Injectable, inject } from '@angular/core';
import { Observable, from, of, switchMap, tap } from 'rxjs';
import { ReaderApi } from './reader-api';
import { ReaderCacheService } from './reader-cache.service';
import { ReaderContent } from './models';

/**
 * Cache-first reader content: an IndexedDB hit resolves immediately; a miss
 * calls the API and caches only successful extractions (failures stay
 * retryable). One method the reader view subscribes to on each open.
 */
@Injectable({ providedIn: 'root' })
export class ReaderContentService {
  private readonly api = inject(ReaderApi);
  private readonly cache = inject(ReaderCacheService);

  load(entryId: number): Observable<ReaderContent> {
    return from(this.cache.get(entryId)).pipe(
      switchMap((cached) =>
        cached
          ? of<ReaderContent>(cached)
          : this.api.readerContent(entryId).pipe(
              tap((c) => {
                if (c.status === 'ok') void this.cache.put(entryId, c);
              }),
            ),
      ),
    );
  }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `npx jest reader-content`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/reader-content.service.ts frontend/src/app/reader/reader-content.service.spec.ts
git commit -m "feat(reader): cache-first reader content service"
```

---

### Task 8: Reader view — toggle, loading, fallback

**Files:**
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`

The view stays the single rendering surface (used by both the pane and full-width shell branches). It gains reader-content state driven by an effect on the `entry()` input, a Reader/Original toggle, a loading state, and automatic fallback to feed content on failure.

- [ ] **Step 1: Write failing tests**

Add to `frontend/src/app/reader/reader-view/reader-view.component.spec.ts` (provide a stub `ReaderContentService`). Cover:
```ts
// Provide { provide: ReaderContentService, useValue: { load: loadMock } } in TestBed.
it('renders extracted reader content when extraction succeeds', () => {
  // loadMock returns of({status:'ok', contentHtml:'<p>READER</p>', ...});
  // set entry input → .content innerHTML contains 'READER'.
});

it('falls back to feed content and shows a note when extraction fails', () => {
  // loadMock returns of({status:'failed', reason:'fetch', url:null});
  // .content shows entry.contentHtml; a subtle fallback note is present.
});

it('toggles between reader and original content', () => {
  // success case; click the toggle → shows entry.contentHtml; click again → reader.
});

it('shows a loading indicator while extraction is pending', () => {
  // loadMock returns a Subject (not yet emitted) → loading element present, content absent.
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest reader-view`
Expected: FAIL — no toggle/loading elements; `ReaderContentService` not injected.

- [ ] **Step 3: Implement the changes**

In `reader-view.component.ts`:

Imports and injection:
```ts
import { Component, ElementRef, computed, effect, inject, input, output, signal, viewChild } from '@angular/core';
import { ReaderContentService } from '../reader-content.service';
import { ReaderArticle } from '../models';
```
(Drop `AfterViewChecked` from the class implements list — the link-decoration hook is replaced below.)

Class state:
```ts
  private readonly reader = inject(ReaderContentService);

  readonly mode = signal<'reader' | 'original'>('reader');
  private readonly state = signal<
    { status: 'idle' | 'loading' } | { status: 'ok'; article: ReaderArticle } | { status: 'failed' }
  >({ status: 'idle' });

  readonly loading = computed(() => this.state().status === 'loading');
  readonly failed = computed(() => this.state().status === 'failed');
  private readonly article = computed(() => {
    const s = this.state();
    return s.status === 'ok' ? s.article : null;
  });
  readonly canToggle = computed(() => this.article() !== null);

  readonly displayHtml = computed(() => {
    const e = this.entry();
    if (!e) return '';
    const a = this.article();
    return this.mode() === 'reader' && a ? a.contentHtml : (e.contentHtml ?? '');
  });

  constructor() {
    effect((onCleanup) => {
      const e = this.entry();
      this.mode.set('reader');
      if (!e) {
        this.state.set({ status: 'idle' });
        return;
      }
      this.state.set({ status: 'loading' });
      const sub = this.reader.load(e.id).subscribe({
        next: (c) => {
          if (c.status === 'ok') {
            this.state.set({ status: 'ok', article: c });
          } else {
            this.state.set({ status: 'failed' });
            this.mode.set('original'); // fall back to the feed's own content
          }
        },
        error: () => {
          this.state.set({ status: 'failed' });
          this.mode.set('original');
        },
      });
      onCleanup(() => sub.unsubscribe());
    });
  }

  toggleMode(): void {
    this.mode.set(this.mode() === 'reader' ? 'original' : 'reader');
  }
```

Template — add a toggle in `.bar` and rework the content region. Replace the `.bar`'s right side to include the toggle (shown only when a reader article is available), and replace the content `<div>`:
```html
        <div class="bar">
          <button class="close" type="button" aria-label="Back to list" (click)="close.emit()">
            <app-icon name="arrow_back" [size]="20" />
          </button>
          <div class="nav">
            @if (canToggle()) {
              <button
                class="mode"
                type="button"
                [attr.aria-pressed]="mode() === 'reader'"
                [attr.aria-label]="mode() === 'reader' ? 'Show original feed content' : 'Show reader view'"
                (click)="toggleMode()"
              >
                <app-icon [name]="mode() === 'reader' ? 'article' : 'feed'" [size]="18" />
                {{ mode() === 'reader' ? 'Reader' : 'Original' }}
              </button>
            }
            <button class="prev" type="button" aria-label="Previous" [disabled]="!hasPrev()" (click)="prev.emit()">
              <app-icon name="chevron_left" [size]="20" />
            </button>
            <button class="next" type="button" aria-label="Next" [disabled]="!hasNext()" (click)="next.emit()">
              <app-icon name="chevron_right" [size]="20" />
            </button>
          </div>
        </div>
```
Content region (replaces the single `<div #content class="content" [innerHTML]="e.contentHtml">`):
```html
          @if (loading()) {
            <div class="loading" role="status">Loading reader view…</div>
          } @else {
            @if (failed() && mode() === 'original') {
              <p class="reader-note">Couldn't load the full article — showing the feed's summary.</p>
            }
            <div #content class="content" [innerHTML]="displayHtml()"></div>
          }
```

Link decoration: keep the "decorate external links to open in a new tab" behavior, but re-run it when the rendered HTML source changes (entry, mode, or async arrival), not only on entry id. Replace `ngAfterViewChecked` with an effect keyed on `displayHtml()`:
```ts
  private readonly content = viewChild<ElementRef<HTMLElement>>('content');

  constructor() {
    // (reader-load effect above) ...

    // Re-decorate links whenever the rendered HTML changes. Reading displayHtml()
    // makes the effect re-run after the innerHTML binding updates.
    effect(() => {
      this.displayHtml();
      queueMicrotask(() => {
        const host = this.content()?.nativeElement;
        if (!host) return;
        for (const a of Array.from(host.querySelectorAll('a'))) {
          if ((a.getAttribute('href') ?? '').startsWith('#')) continue;
          if (a.target !== '_blank') {
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
          }
        }
      });
    });
  }
```
Remove the old `implements AfterViewChecked`, the `ngAfterViewChecked` method, and the `decoratedFor` field.

Add styles for the new elements:
```css
      .mode {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: var(--fs-sm);
      }
      .mode[aria-pressed='true'] {
        color: var(--accent);
      }
      .loading {
        padding: var(--space-5) var(--space-4);
        color: var(--text-muted);
      }
      .reader-note {
        font-size: var(--fs-sm);
        color: var(--text-muted);
        margin: 0 0 var(--space-3);
      }
```

- [ ] **Step 4: Run to verify it passes**

Run: `npx jest reader-view`
Expected: PASS. Fix any `errorOnUnknownProperties` issues from the strict test env by ensuring the stub service is provided.

- [ ] **Step 5: Frontend gates, then commit**

Run (in `frontend/`):
```bash
npx jest && npx eslint src/app/reader && npx prettier --check "src/app/reader/**/*.ts"
```
Expected: all green.
```bash
git add frontend/src/app/reader/reader-view/reader-view.component.ts frontend/src/app/reader/reader-view/reader-view.component.spec.ts
git commit -m "feat(reader): reader-view toggle, loading state, and feed fallback"
```

---

## Verification

### Task 9: Full gates, Docker e2e, dev.log, adversarial review

- [ ] **Step 1: Backend full suite + quality gates**

Run (in `backend/`): `vendor/bin/phpunit` then `composer cs && composer stan && composer md`.
Expected: all green. Confirm the touched `src/Service/Reader/*`, `src/Http/ReaderJson.php`, and `EntryController.php` are phpmd-clean (standing rule).

- [ ] **Step 2: Frontend full suite + build**

Run (in `frontend/`): `npx jest && npx eslint . && npx prettier --check . && npx ng build`.
Expected: all green; AOT build succeeds.

- [ ] **Step 3: Docker e2e smoke + dev.log scan**

Bring up the Docker stack and exercise reader mode end-to-end (open an article with a real `url`, confirm the fetched article renders, toggle to Original and back, reopen to confirm the IndexedDB cache serves it without a second `/reader` request). Then scan the backend `dev.log` for new deprecations or errors — especially any from `fivefilters/` or `masterminds/` on PHP 8.3. Record findings; fix or file them, don't silence.

- [ ] **Step 4: Adversarial review**

Dispatch a review focused on: SSRF completeness (does the reader path share the exact guard as feeds — redirects re-validated, DNS pinned, size-capped?), XSS (is extracted HTML strictly sanitizer-gated before `[innerHTML]`, with Angular's binding sanitizer as backstop?), IDOR (ownership checked before extraction and before the limiter spends budget?), rate-limit correctness (per-user key, 429 shape), cache correctness (LRU eviction under concurrency, failures never cached, schema-version bust), and the reader-view effect (no double-fetch across pane/full branches, cleanup on rapid entry switches). Fix confirmed findings before finishing.

- [ ] **Step 5: Stop and present**

Do NOT merge. Per the standing rule, present the finished branch and its verification results, and wait for explicit merge authorization.

---

## Self-Review Notes (author)

- **Spec coverage:** backend extraction (T1–T4), frontend IndexedDB cache (T6), stateless backend (no migration — confirmed), auto-fetch + Reader/Original toggle + feed fallback (T8), direct absolute-ised images (readability `FixRelativeURLs`, T3), SSRF reuse + sanitizer reuse + rate limit + native-ready JSON (T2/T3/T4). All spec sections map to a task.
- **Sanitizer:** reused unchanged — `EntrySanitizer.allowSafeElements()` already covers article structure + `img`; the spec's "extend the allowlist" step proved unnecessary on inspection. Noted so the implementer doesn't add a redundant config.
- **Type consistency:** `ReaderContent`/`ReaderArticle`/`ReaderFailure` and reasons (`no_url|fetch|unextractable|empty`) are identical across backend `ReaderJson`, frontend model, and all specs. `ReaderCacheService.MAX_ENTRIES`, `.get`, `.put` match between service, spec, and content-service stub. Autowire alias `$readerLimiter` matches the `reader` limiter name.
