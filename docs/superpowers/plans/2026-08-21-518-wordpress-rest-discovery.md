# WordPress REST Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect a WordPress REST posts endpoint during feed discovery and offer it as a richer, full-content candidate alongside the site's RSS/Atom feeds.

**Architecture:** A new open `SourceFormat` value (`wp-json`) with a `FeedBodyParserInterface` refresh strategy, a `WordPressJsonParser` that maps a posts array to `ParsedFeed`, and a `WordPressRestProbe` discovery collaborator that reads the REST root from the page (head link, or a WordPress-fingerprint-gated default `/wp-json/`), verifies the posts endpoint through the SSRF-guarded fetcher, and returns a `FeedCandidate`. Preview and subscribe route the new format through the existing seams.

**Tech Stack:** PHP 8.4 / Symfony 7.4, Doctrine, PHPUnit; Angular 20 / Jest.

**Spec:** [docs/superpowers/specs/2026-08-21-518-wordpress-rest-discovery-design.md](../specs/2026-08-21-518-wordpress-rest-discovery-design.md)

## Global Constraints

- `declare(strict_types=1);` in every PHP file. PSR-12 (`composer cs:fix`).
- PHPStan level max over `src` and `tests`; no new baselines, no unexplained `@phpstan-ignore`.
- PHPMD codesize clean on every touched `src` file (fix the design, not the threshold).
- Clean Code house rules: intention-revealing names, one-thing functions, guard clauses, no boolean-flag params, `final readonly` with constructor promotion, depend on interfaces.
- Datetimes are stored as **naive UTC** — parse `date_gmt` explicitly as UTC; never use `date`.
- All outbound fetches go through the SSRF-guarded `FeedFetcherInterface`; reguard every redirect hop (inherited by using the shared fetcher).
- Native iOS readiness: JSON in / JSON out, Bearer auth, no browser-only coupling.
- Frontend: no hex colours / raw px outside `src/app/theme/`; component styles in sibling `.scss`; `npm run check` from `frontend/` is the gate.
- Detection values (locked): REST candidate presented **first**; badge label **"WordPress"**; probe `per_page=50`; head-link primary, WordPress-fingerprint-gated default `/wp-json/` fallback; `?rest_route=` root unsupported.
- Commit message format: `type(#518): summary` (issue number is the scope).

---

### Task 1: `WordPressJsonParser` — map a posts array to `ParsedFeed`

**Files:**
- Create: `backend/src/Service/Parser/WordPressJsonParser.php`
- Test: `backend/tests/Service/Parser/WordPressJsonParserTest.php`

**Interfaces:**
- Consumes: `App\Service\Parser\ParsedFeed`, `ParsedEntry`, `ParsedImage`, `Exception\FeedParseException`.
- Produces: `WordPressJsonParser::parse(string $body): ParsedFeed` — decodes a WordPress `wp/v2/posts?_embed` JSON array. Throws `FeedParseException` when the body is not a JSON array; an empty array yields a zero-entry `ParsedFeed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\WordPressJsonParser;
use PHPUnit\Framework\TestCase;

final class WordPressJsonParserTest extends TestCase
{
    private const POST = <<<'JSON'
        [
          {
            "id": 101,
            "date_gmt": "2026-08-20T14:23:15",
            "guid": { "rendered": "https://site.example/?p=101" },
            "link": "https://site.example/hello-world/",
            "title": { "rendered": "Hello &amp; <em>welcome</em>" },
            "content": { "rendered": "<p>Full body.</p>" },
            "excerpt": { "rendered": "<p>Short.</p>" },
            "_embedded": {
              "author": [ { "name": "Jane Doe" } ],
              "wp:featuredmedia": [
                { "source_url": "https://site.example/img.jpg",
                  "media_details": { "width": 800, "height": 600 } }
              ]
            }
          }
        ]
        JSON;

    private function parse(string $body): \App\Service\Parser\ParsedFeed
    {
        return (new WordPressJsonParser())->parse($body);
    }

    public function testMapsAFullPost(): void
    {
        $entry = $this->parse(self::POST)->entries[0];

        self::assertSame('https://site.example/?p=101', $entry->guid);
        self::assertSame('https://site.example/hello-world/', $entry->url);
        self::assertSame('Hello & welcome', $entry->title);
        self::assertSame('<p>Full body.</p>', $entry->contentHtml);
        self::assertSame('<p>Short.</p>', $entry->summary);
        self::assertSame('Jane Doe', $entry->author);
        self::assertSame('https://site.example/img.jpg', $entry->image?->url);
        self::assertSame(800, $entry->image?->width);
        self::assertSame(600, $entry->image?->height);
    }

    public function testParsesDateGmtAsUtc(): void
    {
        $publishedAt = $this->parse(self::POST)->entries[0]->publishedAt;

        self::assertNotNull($publishedAt);
        self::assertSame('2026-08-20T14:23:15+00:00', $publishedAt->format('c'));
    }

    public function testTitleIsNullSiteLevel(): void
    {
        self::assertNull($this->parse(self::POST)->title);
    }

    public function testMissingEmbeddedLeavesAuthorAndImageNull(): void
    {
        $entry = $this->parse('[{"id":7,"link":"https://x.example/7","title":{"rendered":"T"}}]')
            ->entries[0];

        self::assertNull($entry->author);
        self::assertNull($entry->image);
        self::assertNull($entry->publishedAt);
        self::assertSame('7', $entry->guid);
    }

    public function testEmptyArrayIsAZeroEntryFeed(): void
    {
        self::assertSame([], $this->parse('[]')->entries);
    }

    public function testNonArrayBodyThrows(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parse('{"not":"a list"}');
    }

    public function testEmptyBodyThrows(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parse('   ');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Parser/WordPressJsonParserTest.php`
Expected: FAIL — `WordPressJsonParser` class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

/**
 * Turns a WordPress `wp/v2/posts?_embed` JSON array into a ParsedFeed. The
 * reusable core shared by the refresh strategy (WpJsonBodyParser) and the
 * subscribe-dialog preview, mirroring how FeedParser and HtmlItemExtractor are
 * used directly by both pipelines.
 *
 * The posts endpoint carries no site name, so ParsedFeed::title is null; the
 * discovery candidate supplies a readable title from the page instead.
 */
final readonly class WordPressJsonParser
{
    public function parse(string $body): ParsedFeed
    {
        $posts = json_decode(trim($body), true);
        if (!\is_array($posts) || !array_is_list($posts)) {
            // A non-array body is a WordPress error object or a broken payload;
            // an empty list is a legitimately empty feed and falls through.
            throw new FeedParseException('WordPress REST body is not a post array');
        }

        $entries = [];
        foreach ($posts as $post) {
            if (\is_array($post)) {
                $entries[] = $this->entry($post);
            }
        }

        return new ParsedFeed(null, null, null, $entries);
    }

    /** @param array<string, mixed> $post */
    private function entry(array $post): ParsedEntry
    {
        return new ParsedEntry(
            guid: $this->guid($post),
            url: $this->stringOrNull($post['link'] ?? null),
            title: $this->plainTitle($this->rendered($post, 'title')),
            author: $this->author($post),
            summary: $this->rendered($post, 'excerpt'),
            contentHtml: $this->rendered($post, 'content'),
            publishedAt: $this->publishedAt($post),
            image: $this->image($post),
        );
    }

    /** @param array<string, mixed> $post */
    private function guid(array $post): string
    {
        $guid = $this->stringOrNull($this->rendered($post, 'guid'))
            ?? $this->stringOrNull($post['id'] ?? null)
            ?? $this->stringOrNull($post['link'] ?? null);

        if (null === $guid) {
            throw new FeedParseException('WordPress post has no id, guid or link');
        }

        return $guid;
    }

    /**
     * The `.rendered` sub-value WordPress wraps title/content/excerpt/guid in.
     *
     * @param array<string, mixed> $post
     */
    private function rendered(array $post, string $field): ?string
    {
        $value = $post[$field] ?? null;

        return \is_array($value) ? $this->stringOrNull($value['rendered'] ?? null) : null;
    }

    private function plainTitle(?string $rendered): string
    {
        if (null === $rendered) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($rendered), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string, mixed> $post */
    private function author(array $post): ?string
    {
        $author = $post['_embedded']['author'][0]['name'] ?? null;

        return $this->stringOrNull($author);
    }

    /** @param array<string, mixed> $post */
    private function publishedAt(array $post): ?\DateTimeImmutable
    {
        $dateGmt = $this->stringOrNull($post['date_gmt'] ?? null);
        if (null === $dateGmt) {
            return null;
        }

        try {
            // date_gmt is UTC wall-clock with no offset designator; pin the zone
            // so it is never read in the server's local time (naive-UTC gotcha).
            return new \DateTimeImmutable($dateGmt, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $post */
    private function image(array $post): ?ParsedImage
    {
        $media = $post['_embedded']['wp:featuredmedia'][0] ?? null;
        $url = \is_array($media) ? $this->stringOrNull($media['source_url'] ?? null) : null;
        if (null === $url) {
            return null;
        }

        $details = \is_array($media['media_details'] ?? null) ? $media['media_details'] : [];

        return new ParsedImage($url, $this->intOrNull($details['width'] ?? null), $this->intOrNull($details['height'] ?? null));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (\is_string($value) && '' !== trim($value)) {
            return $value;
        }

        return \is_int($value) ? (string) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Parser/WordPressJsonParserTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Parser/WordPressJsonParser.php backend/tests/Service/Parser/WordPressJsonParserTest.php
git commit -m "feat(#518): map WordPress REST posts array to ParsedFeed"
```

---

### Task 2: `SourceFormat::WP_JSON` + `WpJsonBodyParser` refresh strategy

**Files:**
- Modify: `backend/src/Enum/SourceFormat.php`
- Create: `backend/src/Service/Refresh/WpJsonBodyParser.php`
- Modify: `backend/tests/Service/Refresh/FeedBodyParserWiringTest.php`

**Interfaces:**
- Consumes: `WordPressJsonParser::parse()` (Task 1); `App\Service\Refresh\FeedBodyParserInterface`.
- Produces: `SourceFormat::WP_JSON` (`'wp-json'`); `WpJsonBodyParser` implementing `FeedBodyParserInterface`, `format()` → `'wp-json'`. Auto-registered by the `app.feed_body_parser` tag.

- [ ] **Step 1: Write the failing test** (append to `FeedBodyParserWiringTest`)

```php
    public function testWpJsonFormatResolvesToTheWordPressParser(): void
    {
        $feed = new \App\Entity\Feed('https://site.example/wp-json/wp/v2/posts?per_page=50&_embed');
        $feed->setSourceFormat(\App\Enum\SourceFormat::WP_JSON);

        $body = '[{"id":1,"link":"https://site.example/p","title":{"rendered":"Post"},'
            . '"content":{"rendered":"<p>Body.</p>"},"date_gmt":"2026-08-20T10:00:00"}]';

        $parsed = $this->parser()->parse($feed, $body);

        self::assertCount(1, $parsed->entries);
        self::assertSame('Post', $parsed->entries[0]->title);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Refresh/FeedBodyParserWiringTest.php`
Expected: FAIL — `SourceFormat::WP_JSON` undefined / no parser for `wp-json` (xml fallback throws).

- [ ] **Step 3: Write minimal implementation**

Add to `backend/src/Enum/SourceFormat.php` after the `SCRAPED` constant:

```php
    /** WordPress REST posts endpoint (wp/v2/posts?_embed) — full-content JSON. */
    public const string WP_JSON = 'wp-json';
```

Create `backend/src/Service/Refresh/WpJsonBodyParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\ParsedFeed;
use App\Service\Parser\WordPressJsonParser;

/**
 * Refresh strategy for feeds subscribed as a WordPress REST posts endpoint.
 * Wraps WordPressJsonParser so refresh and the subscribe-dialog preview read
 * the same JSON through one implementation, exactly as XmlBodyParser wraps
 * FeedParser. Parse failures surface as FeedParseException, so the runner's
 * recordFailure / backoff / Erroring handling applies unchanged.
 */
final readonly class WpJsonBodyParser implements FeedBodyParserInterface
{
    public function __construct(private WordPressJsonParser $parser)
    {
    }

    public static function format(): string
    {
        return SourceFormat::WP_JSON;
    }

    public function parse(string $body, Feed $feed): ParsedFeed
    {
        return $this->parser->parse($body);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Refresh/FeedBodyParserWiringTest.php`
Expected: PASS (all wiring tests, including the new one).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Enum/SourceFormat.php backend/src/Service/Refresh/WpJsonBodyParser.php backend/tests/Service/Refresh/FeedBodyParserWiringTest.php
git commit -m "feat(#518): add wp-json source format and refresh strategy"
```

---

### Task 3: `WordPressRestProbe` — detect the REST root and verify the posts endpoint

**Files:**
- Create: `backend/src/Service/Discovery/WordPressRestProbe.php`
- Test: `backend/tests/Service/Discovery/WordPressRestProbeTest.php`

**Interfaces:**
- Consumes: `App\Service\Fetch\FeedFetcherInterface`, `App\Service\Fetch\PageUrls`, `App\Service\Html\HtmlDocumentParser`, `App\Service\Discovery\FeedCandidate`, `App\Enum\SourceFormat`.
- Produces: `WordPressRestProbe::offer(string $body, string $pageUrl): ?FeedCandidate` — returns a `wp-json` candidate (posts URL + page `<title>`) or null. Makes at most one probe request, and none when no REST root can be resolved.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Enum\SourceFormat;
use App\Service\Discovery\WordPressRestProbe;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\TestCase;

final class WordPressRestProbeTest extends TestCase
{
    private const POSTS = 'https://site.example/wp-json/wp/v2/posts?per_page=50&_embed';

    private function fetcher(): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrowForEverythingElse(new FeedUnreachableException('x: HTTP 404', 404));

        return $fetcher;
    }

    private function postsResponse(string $json): FetchResponse
    {
        return FetchResponse::fetched(self::POSTS, permanentRedirect: false, body: $json, etag: null, lastModified: null);
    }

    private function headLinkPage(): string
    {
        return '<!doctype html><html><head><title>Site Example</title>'
            . '<link rel="https://api.w.org/" href="https://site.example/wp-json/">'
            . '</head><body>Hi</body></html>';
    }

    public function testOffersACandidateFromTheHeadLink(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(self::POSTS, $this->postsResponse('[{"id":1}]'));

        $candidate = (new WordPressRestProbe($fetcher))->offer($this->headLinkPage(), 'https://site.example/');

        self::assertNotNull($candidate);
        self::assertSame(self::POSTS, $candidate->url);
        self::assertSame('Site Example', $candidate->title);
        self::assertSame(SourceFormat::WP_JSON, $candidate->format);
    }

    public function testFallsBackToTheDefaultRootBehindAFingerprint(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(self::POSTS, $this->postsResponse('[{"id":9}]'));

        // No api.w.org link, but a wp-content asset path betrays WordPress.
        $body = '<!doctype html><html><head><title>Fp</title></head>'
            . '<body><img src="https://site.example/wp-content/uploads/x.jpg"></body></html>';

        $candidate = (new WordPressRestProbe($fetcher))->offer($body, 'https://site.example/');

        self::assertSame(self::POSTS, $candidate?->url);
        self::assertSame([self::POSTS], $fetcher->fetchedUrls);
    }

    public function testNoLinkAndNoFingerprintMakesNoRequest(): void
    {
        $fetcher = $this->fetcher();

        $candidate = (new WordPressRestProbe($fetcher))->offer(
            '<!doctype html><html><head><title>Plain</title></head><body>Hi</body></html>',
            'https://site.example/',
        );

        self::assertNull($candidate);
        self::assertSame([], $fetcher->fetchedUrls);
    }

    public function testAGatedEndpointYieldsNoCandidate(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willThrow(self::POSTS, new FeedUnreachableException('x: HTTP 401', 401));

        self::assertNull((new WordPressRestProbe($fetcher))->offer($this->headLinkPage(), 'https://site.example/'));
    }

    public function testAnEmptyPostArrayYieldsNoCandidate(): void
    {
        $fetcher = $this->fetcher();
        $fetcher->willReturn(self::POSTS, $this->postsResponse('[]'));

        self::assertNull((new WordPressRestProbe($fetcher))->offer($this->headLinkPage(), 'https://site.example/'));
    }

    public function testARestRouteQueryRootIsUnsupported(): void
    {
        $fetcher = $this->fetcher();
        $body = '<!doctype html><html><head><title>Q</title>'
            . '<link rel="https://api.w.org/" href="https://site.example/?rest_route=/">'
            . '</head><body>Hi</body></html>';

        self::assertNull((new WordPressRestProbe($fetcher))->offer($body, 'https://site.example/'));
        self::assertSame([], $fetcher->fetchedUrls);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Discovery/WordPressRestProbeTest.php`
Expected: FAIL — `WordPressRestProbe` class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\SourceFormat;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\PageUrls;
use App\Service\Html\HtmlDocumentParser;
use Dom\HTMLDocument;

/**
 * Offers a WordPress REST posts endpoint as a richer alternative to a site's
 * RSS feed. Sibling of WellKnownFeedProbe, but it runs ALONGSIDE the link scan
 * rather than as a fallback: the whole point is to sit next to the RSS
 * candidate on a page that advertises both.
 *
 * The REST root is resolved in two tiers, both silent on absence:
 *   1. the canonical head link <link rel="https://api.w.org/">, or
 *   2. the default {origin}/wp-json/ — but only when the page body carries a
 *      WordPress fingerprint, so a non-WordPress page is never probed.
 * A resolved root is verified once through the SSRF-guarded fetcher: only a
 * non-empty JSON post array becomes a candidate, so a disabled or gated REST
 * API (the common reason the head link is stripped) simply offers nothing.
 */
final readonly class WordPressRestProbe
{
    private const string REST_ROOT_REL = 'https://api.w.org/';

    /** Substrings that mark a page as WordPress when the head link is absent. */
    private const array FINGERPRINTS = ['wp-content', 'wp-includes', 'content="WordPress'];

    private const int PER_PAGE = 50;

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    public function offer(string $body, string $pageUrl): ?FeedCandidate
    {
        $document = HtmlDocumentParser::parseOrNull($body);
        if (null === $document) {
            return null;
        }

        $pageUrls = new PageUrls($pageUrl);
        $root = $this->restRoot($document, $pageUrls, $body);
        $postsUrl = null === $root ? null : $this->postsUrl($root);
        if (null === $postsUrl || !$this->hasPosts($postsUrl)) {
            return null;
        }

        return new FeedCandidate($postsUrl, $this->pageTitle($document), SourceFormat::WP_JSON);
    }

    private function restRoot(HTMLDocument $document, PageUrls $pageUrls, string $body): ?string
    {
        $advertised = $this->advertisedRoot($document, $pageUrls);
        if (null !== $advertised) {
            return $advertised;
        }

        return $this->looksLikeWordPress($body) ? $pageUrls->origin() . '/wp-json/' : null;
    }

    private function advertisedRoot(HTMLDocument $document, PageUrls $pageUrls): ?string
    {
        foreach ($document->querySelectorAll('link[rel]') as $link) {
            if (self::REST_ROOT_REL === strtolower(trim($link->getAttribute('rel') ?? ''))) {
                return $pageUrls->httpUrl(trim($link->getAttribute('href') ?? ''));
            }
        }

        return null;
    }

    private function looksLikeWordPress(string $body): bool
    {
        foreach (self::FINGERPRINTS as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The posts URL under a pretty-permalink root. A `?rest_route=` root carries
     * a query, so appending a path and a second query cannot form a valid URL —
     * that install is left to its RSS feed.
     */
    private function postsUrl(string $root): ?string
    {
        if (str_contains($root, '?')) {
            return null;
        }

        return rtrim($root, '/') . '/wp/v2/posts?per_page=' . self::PER_PAGE . '&_embed';
    }

    private function hasPosts(string $postsUrl): bool
    {
        try {
            $response = $this->fetcher->fetch($postsUrl);
        } catch (FetchException) {
            // Gone, blocked, 401/403, SSRF-refused: no alternative to offer.
            return false;
        }

        $posts = json_decode($response->body ?? '', true);

        return \is_array($posts) && array_is_list($posts) && [] !== $posts;
    }

    private function pageTitle(HTMLDocument $document): ?string
    {
        $title = trim($document->querySelector('title')?->textContent ?? '');

        return '' === $title ? null : $title;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Discovery/WordPressRestProbeTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Discovery/WordPressRestProbe.php backend/tests/Service/Discovery/WordPressRestProbeTest.php
git commit -m "feat(#518): detect and verify the WordPress REST posts endpoint"
```

---

### Task 4: Wire the probe into `FeedDiscovery` (REST candidate first)

**Files:**
- Modify: `backend/src/Service/Discovery/FeedDiscovery.php`
- Modify: `backend/tests/Service/Discovery/BuildsFeedDiscovery.php`
- Modify: `backend/tests/Service/Discovery/FeedDiscoveryTest.php`

**Interfaces:**
- Consumes: `WordPressRestProbe::offer()` (Task 3).
- Produces: `FeedDiscovery` prepends the REST candidate to the scanned candidates when a page carries one.

- [ ] **Step 1: Write the failing tests**

First update the builder trait so the graph includes the probe. In `BuildsFeedDiscovery.php`, add `use App\Service\Discovery\WordPressRestProbe;` and pass it as the final constructor argument:

```php
        return new FeedDiscovery(
            $fetcher,
            $parser,
            $extractor,
            new FeedLinkScanner(),
            new WellKnownFeedProbe($fetcher, $parser),
            new BotChallengePage(),
            new SubstackProfileFeed($fetcher),
            new WordPressRestProbe($fetcher),
        );
```

Add to `FeedDiscoveryTest.php`:

```php
    public function testOffersTheRestCandidateBeforeTheRssCandidate(): void
    {
        // @lang TEXT
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head><title>WP Site</title>
              <link rel="alternate" type="application/rss+xml" href="/feed/">
              <link rel="https://api.w.org/" href="https://wp.example/wp-json/">
            </head><body>Hi</body></html>
            HTML;

        $fetcher = $this->fetcherReturning('https://wp.example/', 'https://wp.example/', $html);
        $fetcher->willReturn(
            'https://wp.example/wp-json/wp/v2/posts?per_page=50&_embed',
            FetchResponse::fetched(
                'https://wp.example/wp-json/wp/v2/posts?per_page=50&_embed',
                permanentRedirect: false,
                body: '[{"id":1}]',
                etag: null,
                lastModified: null,
            ),
        );

        $result = $this->discovery($fetcher)->discover('https://wp.example/', ScrapeFallback::Enabled);

        self::assertCount(2, $result->candidates);
        self::assertSame('wp-json', $result->candidates[0]->format);
        self::assertSame('https://wp.example/feed/', $result->candidates[1]->url);
        self::assertSame('rss', $result->candidates[1]->format);
    }

    public function testAGatedRestApiLeavesOnlyTheRssCandidate(): void
    {
        // @lang TEXT
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head><title>WP Site</title>
              <link rel="alternate" type="application/rss+xml" href="/feed/">
              <link rel="https://api.w.org/" href="https://wp.example/wp-json/">
            </head><body>Hi</body></html>
            HTML;

        // Everything-else throws 404, so the posts probe fails and no candidate is offered.
        $fetcher = $this->fetcherReturning('https://wp.example/', 'https://wp.example/', $html);

        $result = $this->discovery($fetcher)->discover('https://wp.example/', ScrapeFallback::Enabled);

        self::assertCount(1, $result->candidates);
        self::assertSame('rss', $result->candidates[0]->format);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Discovery/FeedDiscoveryTest.php`
Expected: FAIL — `FeedDiscovery::__construct()` expects 7 args / no `wordPressRest` property.

- [ ] **Step 3: Write minimal implementation**

In `FeedDiscovery.php`, add the constructor dependency (after `$substackProfile`):

```php
        private SubstackProfileFeed $substackProfile,
        private WordPressRestProbe $wordPressRest,
```

Replace the candidate assembly at the end of `discover()`:

```php
        $restCandidate = $this->wordPressRest->offer($body, $response->finalUrl);
        $candidates = array_values(array_filter([
            $restCandidate,
            ...$this->links->scan($body, $response->finalUrl),
        ]));

        return [] !== $candidates
            ? FeedDiscoveryResult::candidates($candidates)
            : $this->feedThePageNeverMentions($body, $response->finalUrl, $fallback);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Discovery/FeedDiscoveryTest.php`
Expected: PASS (existing tests + 2 new).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Discovery/FeedDiscovery.php backend/tests/Service/Discovery/BuildsFeedDiscovery.php backend/tests/Service/Discovery/FeedDiscoveryTest.php
git commit -m "feat(#518): offer the WordPress REST candidate first during discovery"
```

---

### Task 5: Preview the `wp-json` candidate

**Files:**
- Modify: `backend/src/Service/Preview/FeedPreviewService.php`
- Modify: `backend/tests/Service/Preview/FeedPreviewServiceTest.php`

**Interfaces:**
- Consumes: `WordPressJsonParser::parse()` (Task 1), `SourceFormat::WP_JSON`.
- Produces: `FeedPreviewService::preview()` renders a `wp-json` candidate; no permission gate.

- [ ] **Step 1: Write the failing test**

Update the `service()` helper to construct with the new dependency, then add a test. In `FeedPreviewServiceTest.php` add `use App\Service\Parser\WordPressJsonParser;` and change the helper's return:

```php
        return new FeedPreviewService($fetcher, $parser, $extractor, new ScrapeFallbackPolicy(), new WordPressJsonParser());
```

Add the test:

```php
    public function testPreviewsAWpJsonCandidateAsFullContent(): void
    {
        $body = '[{"id":1,"link":"https://site.example/p","title":{"rendered":"Post"},'
            . '"content":{"rendered":' . json_encode($this->longParagraph()) . '},'
            . '"date_gmt":"2026-08-20T10:00:00"}]';
        $fetcher = $this->fetcherWithBody($body);

        $preview = $this->service($fetcher)->preview($this->user(), self::URL, SourceFormat::WP_JSON);

        self::assertSame(1, $preview->itemCount);
        self::assertSame('full', $preview->content);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Preview/FeedPreviewServiceTest.php`
Expected: FAIL — `FeedPreviewService::__construct()` arg count / `wp-json` parsed as XML and throws.

- [ ] **Step 3: Write minimal implementation**

In `FeedPreviewService.php` add `use App\Service\Parser\WordPressJsonParser;`, inject it:

```php
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private WordPressJsonParser $wordPressJsonParser,
```

Replace the `$feed = …` ternary in `preview()` with a match that adds the `wp-json` arm:

```php
            $feed = match ($format) {
                SourceFormat::SCRAPED => $this->extractor->extract($body, $response->finalUrl),
                SourceFormat::WP_JSON => $this->wordPressJsonParser->parse($body),
                default => $this->parser->parse($body),
            };
```

The existing `catch (FeedParseException)` block keeps the scraped message branch; `wp-json` falls into the generic "That address is not a readable feed." message, which is correct.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Preview/FeedPreviewServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Preview/FeedPreviewService.php backend/tests/Service/Preview/FeedPreviewServiceTest.php
git commit -m "feat(#518): preview the WordPress REST candidate"
```

---

### Task 6: Subscribe the `wp-json` candidate verbatim

**Files:**
- Modify: `backend/src/Service/Subscription/SubscriptionService.php`
- Modify: `backend/src/Service/Subscription/SubscriptionCreator.php` (PHPDoc union only)
- Modify: `backend/tests/Service/Subscription/SubscriptionServiceTest.php`

**Interfaces:**
- Consumes: `SourceFormat::WP_JSON`, `SubscriptionCreator::create()`.
- Produces: `SubscriptionService::subscribe($user, $url, 'wp-json', $tags)` stores a feed with `sourceFormat = wp-json` verbatim, without re-running discovery and without a scrape-permission check.

- [ ] **Step 1: Write the failing tests**

```php
    public function testWpJsonSubscribeStoresTheFormatVerbatimWithoutDiscovery(): void
    {
        $user = $this->factory()->create('wpjson@example.com');
        // Discovery must NOT run: hand it a result that would fail the assertion if used.
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResult::candidates([])));

        $url = 'https://wp.example/wp-json/wp/v2/posts?per_page=50&_embed';
        $outcome = $service->subscribe($user, $url, SourceFormat::WP_JSON);

        self::assertNotNull($outcome->subscription);
        self::assertSame($url, $outcome->subscription->getFeed()->getUrl());
        self::assertSame(SourceFormat::WP_JSON, $outcome->subscription->getFeed()->getSourceFormat());
    }

    public function testWpJsonSubscribeNeedsNoScrapingPermission(): void
    {
        // A user with scraping disabled (the default) must still be able to
        // subscribe a wp-json candidate — the scrape gate is scraped-only.
        $user = $this->factory()->create('wpjson-nopref@example.com');
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResult::candidates([])));

        $outcome = $service->subscribe(
            $user,
            'https://wp.example/wp-json/wp/v2/posts?per_page=50&_embed',
            SourceFormat::WP_JSON,
        );

        self::assertNotNull($outcome->subscription);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/SubscriptionServiceTest.php`
Expected: FAIL — `wp-json` falls through to discovery, which returns an empty candidate list, so `subscription` is null.

- [ ] **Step 3: Write minimal implementation**

In `SubscriptionService.php`, replace the scraped branch with a shared verbatim helper and add the `wp-json` case:

```php
        // A 'scraped' or 'wp-json' subscribe re-posts a candidate URL discovery
        // itself just produced: the URL IS the source. Running discovery again
        // would re-fetch for nothing — or fail this time and block a subscribe
        // the user was already offered. Both are stored VERBATIM.
        if (SourceFormat::SCRAPED === $format) {
            // Discovery never offers a scraped candidate to an account with the
            // preference off, so a request that reaches here with it off is a
            // hand-made one — refuse it rather than let this shortcut become
            // the bypass discovery's own gate cannot see.
            $this->scrapeFallbackPolicy->assertMayScrape($user);

            return $this->subscribeVerbatim($user, $url, SourceFormat::SCRAPED, $tags);
        }

        if (SourceFormat::WP_JSON === $format) {
            // No permission gate: unlike scraping, a REST endpoint is a real
            // machine source the site publishes, not a synthesized page scrape.
            return $this->subscribeVerbatim($user, $url, SourceFormat::WP_JSON, $tags);
        }
```

Add the private helper below `subscribe()`:

```php
    /**
     * A candidate whose URL is the source itself (scraped page, REST endpoint):
     * store it verbatim and skip re-discovery.
     *
     * @param list<Tag> $tags
     */
    private function subscribeVerbatim(User $user, string $url, string $format, array $tags): SubscribeOutcome
    {
        return SubscribeOutcome::subscribed($this->creator->create($user, $url, $format, $tags));
    }
```

In `SubscriptionCreator.php`, widen the PHPDoc union on `create()`:

```php
     * @param 'xml'|'scraped'|'wp-json' $sourceFormat
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/SubscriptionServiceTest.php`
Expected: PASS (existing tests + 2 new).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Subscription/SubscriptionService.php backend/src/Service/Subscription/SubscriptionCreator.php backend/tests/Service/Subscription/SubscriptionServiceTest.php
git commit -m "feat(#518): subscribe the WordPress REST candidate verbatim"
```

---

### Task 7: Frontend — label and route the `wp-json` candidate

**Files:**
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.ts`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`

**Interfaces:**
- Consumes: `FeedCandidate.format` carrying `'wp-json'` from the backend.
- Produces: the badge reads "WordPress"; `pick()` and `loadPreviews()` pass the `wp-json` format to subscribe and preview.

- [ ] **Step 1: Write the failing test** (add to `add-feed-dialog.component.spec.ts`)

```ts
  it('labels a wp-json candidate "WordPress" and passes the format to preview and subscribe', () => {
    openDialog();
    httpMock
      .expectOne((r) => r.url.endsWith('/subscriptions'))
      .flush({
        candidates: [{ url: 'https://wp.example/wp-json/wp/v2/posts', title: 'WP', format: 'wp-json' }],
      });

    const previewReq = httpMock.expectOne((r) => r.url.endsWith('/feeds/preview'));
    expect(previewReq.request.body).toEqual({
      url: 'https://wp.example/wp-json/wp/v2/posts',
      format: 'wp-json',
    });
    previewReq.flush({ feed: { title: 'WP', itemCount: 5, content: 'full', hasImages: true, items: [] } });

    fixture.detectChanges();
    const card = fixture.nativeElement.querySelector('.card');
    expect(card.querySelector('.badge.format')?.textContent?.trim()).toBe('WordPress');

    card.querySelector('.subscribe').click();
    const subReq = httpMock.expectOne((r) => r.url.endsWith('/subscriptions'));
    expect(subReq.request.body).toEqual({
      url: 'https://wp.example/wp-json/wp/v2/posts',
      format: 'wp-json',
      tagIds: [],
    });
  });
```

(Model `openDialog()` / `httpMock` on the existing scraped test in the same file — reuse its setup helpers exactly.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx jest add-feed-dialog`
Expected: FAIL — badge reads "Wp-json"; preview/subscribe bodies omit the format.

- [ ] **Step 3: Write minimal implementation**

In `add-feed-dialog.component.ts`, add to `formatLabel()` before the RSS check:

```ts
    if (format === 'wp-json') return 'WordPress';
```

Add a private helper and use it in both `pick()` and `loadPreviews()`:

```ts
  /** Formats the backend must persist verbatim (it cannot re-derive them by
   *  parsing): scraped pages and WordPress REST endpoints. Others re-run
   *  discovery, so they pass no format. */
  private storedFormat(c: FeedCandidate): string | undefined {
    return c.format === 'scraped' || c.format === 'wp-json' ? c.format : undefined;
  }
```

Change `pick()`:

```ts
  pick(c: FeedCandidate): void {
    this.subscribe(c.url, this.storedFormat(c));
  }
```

Change the `loadPreviews()` call:

```ts
      this.api.previewFeed(c.url, this.storedFormat(c)).subscribe({
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx jest add-feed-dialog`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/add-feed/add-feed-dialog.component.ts frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts
git commit -m "feat(#518): label and route the WordPress REST candidate in the add-feed dialog"
```

---

### Task 8: Document the WordPress REST option in the README

**Files:**
- Modify: `README.md` (the "Feeds" feature list)

**Interfaces:** none — user-facing documentation only.

- [ ] **Step 1: Add the feature bullet**

In `README.md`, in the **Feeds** section, immediately AFTER the existing scrape bullet ("For sites without any feed, an opt-in experimental mode scrapes the article list into a pseudo-feed."), add:

```markdown
- When a WordPress site's feed carries only summaries, the app detects its
  REST API while finding the feed and offers it as a richer alternative —
  full article text, chosen in the same subscribe dialog.
```

Match the surrounding voice: plain, user-facing, benefit-first. Do not add a new heading; it is one bullet in the existing list. Touch nothing else.

- [ ] **Step 2: Verify the render**

Run: `git diff README.md`
Expected: exactly one added bullet in the Feeds list, correct Markdown, no reflow of neighbouring bullets.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(#518): note the WordPress REST full-content option in the README"
```

---

### Task 9: Full-gate verification

**Files:** none (verification only).

- [ ] **Step 1: Warm the cache and run the backend gates**

```bash
cd backend && php bin/console cache:warmup && composer check && composer md
```
Expected: cs, stan (level max), tramp, and PHPMD all green. Fix any finding in a touched file (design fix, not threshold tuning).

- [ ] **Step 2: Run the backend suite (both legs)**

```bash
cd backend && php bin/phpunit
```
Then the MySQL leg:
```bash
docker compose exec -T php vendor/bin/phpunit
```
Expected: green on both SQLite and MySQL.

- [ ] **Step 3: Gate mutation coverage on the changed files**

```bash
cd backend && composer infection:diff
```
Expected: MSI at or above `infection.json5`'s `minMsi`. Escaped mutants arrive as PR annotations — add the missing assertion in the owning test until the gate passes.

- [ ] **Step 4: PhpStorm inspections on changed PHP**

Run `mcp__phpstorm__lint_files` over the created/modified `.php` files. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 5: Frontend gate**

```bash
cd frontend && npm run check
```
Expected: ESLint + Prettier + Stylelint + Jest all green.

- [ ] **Step 6: Scan the dev log for swallowed errors**

```bash
tail -n 80 backend/var/log/dev.log
```
Expected: no new deprecations or errors from the discovery/preview/refresh paths.

- [ ] **Step 7: No commit** — verification only. If a gate forced a fix, commit it with `test(#518): …` or `fix(#518): …` as appropriate.

---

## Self-Review

**Spec coverage:**
- New source format `wp-json` → Task 2. ✓
- `WordPressJsonParser` mapping (title/link/date_gmt-UTC/content/excerpt/author/media/guid, empty-array, non-array) → Task 1. ✓
- `WpJsonBodyParser` refresh strategy + tag wiring → Task 2. ✓
- `WordPressRestProbe` two-tier detection (head link + fingerprint-gated default), `?rest_route` guard, non-empty verification, page-title candidate → Task 3. ✓
- `FeedDiscovery` prepends REST candidate (REST first), silent absence → Task 4. ✓
- `FeedPreviewService` wp-json branch, no scrape gate → Task 5. ✓
- `SubscriptionService` verbatim wp-json subscribe, no permission gate, DRY helper → Task 6. ✓
- Frontend badge "WordPress" + format pass-through, spec owns its stubbed route → Task 7. ✓
- README documents the wp-json source format → Task 8. ✓
- SSRF boundary via shared fetcher → Tasks 3/4 (probe) and existing refresh path. ✓
- Testing + mutation gate → Task 9. ✓

**Placeholder scan:** No TBD/TODO; every code step carries full code.

**Type consistency:** `offer(string $body, string $pageUrl): ?FeedCandidate` (Task 3) is the exact signature called in Task 4. `WordPressJsonParser::parse(string): ParsedFeed` (Task 1) is consumed unchanged by Tasks 2 and 5. `SourceFormat::WP_JSON` (`'wp-json'`) is the single spelling across Tasks 2–7. `storedFormat()` is defined once (Task 7) and used in both `pick()` and `loadPreviews()`.

**Note on constructor changes:** `FeedDiscovery` (7→8 args) and `FeedPreviewService` (4→5 args) are autowired in production; only the test builders (`BuildsFeedDiscovery`, `FeedPreviewServiceTest::service()`) construct them by hand and are updated in Tasks 4 and 5. Grep for other direct `new FeedDiscovery(`/`new FeedPreviewService(` before finishing each task; there are none today.
