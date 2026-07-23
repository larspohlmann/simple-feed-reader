# HTML Feed Scraper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Synthesize a subscribable feed from a plain HTML page when a site offers no RSS/Atom feed, with honest per-reason warnings when a page can't be scraped.

**Architecture:** Approach A from the spec (`docs/superpowers/specs/2026-07-23-html-feed-scraper-design.md`): a `sourceFormat` column on `Feed` ('xml'|'scraped'), a new `HtmlItemExtractor` (3 heuristic layers over PHP 8.4 `\Dom\HTMLDocument`) that returns the same `ParsedFeed`/`ParsedEntry` objects `FeedParser` returns, a discovery fallback that yields either a `scraped` `FeedCandidate` or a machine-readable `scrapeFailureReason`, preview/subscribe DTOs that carry the candidate `format`, and a `FeedBodyParser` seam in the refresh pipeline. Frontend: warning block + scraped badge/hint in the add-feed dialog, badge in feed management.

**Tech Stack:** PHP 8.4 / Symfony 7.4 / Doctrine 3 (backend, runs in Docker service `php`, repo mounted at `/app`); Angular 20 standalone + signals + Jest (frontend); Playwright e2e.

**Conventions that bind every task:**
- Backend tests run in Docker: `docker compose exec -T php vendor/bin/phpunit <path>`.
- Every touched PHP src file must be phpmd-clean (`composer md`), phpcs-clean (`composer cs`), phpstan-clean (`composer stan`) — run inside the container via `docker compose exec -T php composer <script>`.
- New PHP files: `declare(strict_types=1);`, `final` classes, readonly where possible, constructor property promotion.
- Frontend: standalone components, inline templates, `@if`/`@for` control flow, signals, hardcoded English strings, token-only styles (`var(--…)`).
- Commit after each task (conventional commits).

**Existing objects used throughout (exact signatures, verified):**
- `ParsedFeed(?string $title, ?string $siteUrl, ?string $description, array $entries)`
- `ParsedEntry(string $guid, ?string $url, string $title, ?string $author, ?string $summary, ?string $contentHtml, ?\DateTimeImmutable $publishedAt, ?string $imageUrl = null)`
- `GuidFallback::for(?string $guid, ?string $url, ?string $title): string` (returns `$guid` when non-empty)
- `DateParser::parse(?string $value): ?\DateTimeImmutable` (static)
- `UrlResolver::resolve(string $base, string $href): ?string` (static, `App\Service\Fetch`)
- `FetchResponse::fetched(string $finalUrl, bool $permanentRedirect, string $body, ?string $etag, ?string $lastModified)`
- `FeedCandidate(string $url, ?string $title, string $format)`
- `StubFeedFetcher` (tests/Support): `willReturn(string $url, FetchResponse $r)`, `willThrow(string $url, FetchException $e)`
- Fixtures: `tests/Fixtures/scraped/tagesschau-2026-07-23.html` (38 `a.teaser__link` cards, `<p class="teaser__shorttext">` teasers, soft hyphens), `tests/Fixtures/scraped/treehugger-rendered-2026-07-23.html` (45 `a.mntl-card` cards, no headings, `<span class="card__title-text">` titles, descriptions ONLY in `data-card-description` attributes).

---

## File structure (new files)

```
backend/src/Service/Scraper/
  HtmlItemExtractor.php        # facade: parse doc, run layers, guards, build ParsedFeed
  ScrapedItem.php              # intermediate value object (url/title/teaser/image/date)
  TextNormalizer.php           # soft-hyphen strip + whitespace collapse
  CardFields.php               # per-card field extraction (title chain, teaser, image, date)
  Exception/HtmlExtractionException.php   # extends FeedParseException
  Layer/ScrapeLayerInterface.php
  Layer/JsonLdLayer.php        # layer 1: JSON-LD ItemList / article arrays
  Layer/SemanticLayer.php      # layer 2: repeated <article> elements
  Layer/ClusterLayer.php       # layer 3: anchor DOM-path clustering
backend/src/Service/Refresh/FeedBodyParser.php  # sourceFormat branch (keeps RefreshRunner at 10 deps)
backend/migrations/Version20260723200000.php
backend/tests/Service/Scraper/…              # unit tests per class
backend/tests/Fixtures/scraped/jsonld-list.html, articles-blog.html, nav-only.html, footer-links.html
```

Modified: `Feed` entity, `FeedUnreachableException`, `HttpFeedFetcher`, `FeedDiscovery`, `FeedDiscoveryResult`, `FeedPreviewService` (+DTO/controller), `SubscriptionService`/`SubscribeOutcome`/`SubscriptionController` (+DTO), `SubscriptionJson`, `RefreshRunner`; frontend `models.ts`, `reader-api.ts`, `add-feed-dialog.component.ts` (+spec), `feeds-section.component.ts` (+spec), `e2e/reader-smoke.spec.ts`.

---

### Task 1: Structured status code on FeedUnreachableException

**Files:**
- Modify: `backend/src/Service/Fetch/Exception/FeedUnreachableException.php`
- Modify: `backend/src/Service/Fetch/HttpFeedFetcher.php` (the two `FeedUnreachableException` throw sites)
- Test: `backend/tests/Service/Fetch/HttpFeedFetcherTest.php` (add assertions to existing suite)

- [ ] **Step 1: Write the failing test** — add to the existing `HttpFeedFetcherTest` (it uses Symfony `MockHttpClient`; follow the file's existing helper pattern for building the fetcher):

```php
public function testHttpErrorCarriesStatusCode(): void
{
    $client = new MockHttpClient([new MockResponse('denied', ['http_code' => 403])]);
    $fetcher = $this->fetcher($client); // reuse the file's existing factory helper

    try {
        $fetcher->fetch('https://example.com/feed');
        self::fail('Expected FeedUnreachableException');
    } catch (FeedUnreachableException $e) {
        self::assertSame(403, $e->statusCode);
    }
}

public function testTransportErrorHasNullStatusCode(): void
{
    $client = new MockHttpClient([new MockResponse('', ['error' => 'DNS failure'])]);
    $fetcher = $this->fetcher($client);

    try {
        $fetcher->fetch('https://example.com/feed');
        self::fail('Expected FeedUnreachableException');
    } catch (FeedUnreachableException $e) {
        self::assertNull($e->statusCode);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T php vendor/bin/phpunit tests/Service/Fetch/HttpFeedFetcherTest.php`
Expected: FAIL — property `statusCode` does not exist.

- [ ] **Step 3: Implement**

`FeedUnreachableException.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

final class FeedUnreachableException extends FetchException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

In `HttpFeedFetcher`: the non-2xx status throw becomes
`throw new FeedUnreachableException(sprintf('%s: HTTP %d', $currentUrl, $status), $status);`
and the transport-error rethrow becomes
`throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), null, $e);`
(check for any other `FeedUnreachableException(` construction sites with `grep -rn "FeedUnreachableException(" src tests` and align argument order everywhere — the old second positional argument, where present, was `previous`).

- [ ] **Step 4: Run to verify pass** — same command, plus the full fetch suite: `docker compose exec -T php vendor/bin/phpunit tests/Service/Fetch`
- [ ] **Step 5: Commit** — `git add -A backend/src/Service/Fetch backend/tests/Service/Fetch && git commit -m "feat(fetch): carry HTTP status on FeedUnreachableException"`

---

### Task 2: Feed.sourceFormat column + migration

**Files:**
- Modify: `backend/src/Entity/Feed.php`
- Create: `backend/migrations/Version20260723200000.php`
- Test: `backend/tests/Entity/FeedTest.php` (create if absent; plain TestCase)

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    public function testSourceFormatDefaultsToXmlAndIsMutable(): void
    {
        $feed = new Feed('https://example.com/page');
        self::assertSame('xml', $feed->getSourceFormat());
        $feed->setSourceFormat('scraped');
        self::assertSame('scraped', $feed->getSourceFormat());
    }
}
```

- [ ] **Step 2: Run — FAIL** (`getSourceFormat` undefined): `docker compose exec -T php vendor/bin/phpunit tests/Entity/FeedTest.php`
- [ ] **Step 3: Implement entity field** — in `Feed.php`, after the `$status` property:

```php
/**
 * How this feed's body is turned into entries: 'xml' (RSS/Atom via FeedParser)
 * or 'scraped' (HTML listing via HtmlItemExtractor). Open string, matching
 * FeedCandidate::$format.
 */
#[ORM\Column(length: 20, options: ['default' => 'xml'])]
private string $sourceFormat = 'xml';
```

with plain getter/setter (`getSourceFormat(): string`, `setSourceFormat(string $sourceFormat): void`) placed with the other accessors.

- [ ] **Step 4: Migration** — `backend/migrations/Version20260723200000.php`, copying the platform-split + per-column-idempotence pattern of `Version20260723120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add feed.source_format ('xml'|'scraped') for the HTML scraper";
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            'Migration supports MySQL and SQLite only.'
        );

        if ($schema->getTable('feed')->hasColumn('source_format')) {
            return;
        }

        $this->addSql("ALTER TABLE feed ADD source_format VARCHAR(20) DEFAULT 'xml' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('feed')->hasColumn('source_format')) {
            $this->addSql('ALTER TABLE feed DROP COLUMN source_format');
        }
    }
}
```

(`DEFAULT 'xml' NOT NULL` backfills existing rows in the same statement on both platforms; no `postUp` needed.)

- [ ] **Step 5: Validate migration against the real MySQL container**

Run: `docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec -T php bin/console doctrine:schema:validate`
Expected: migration applies; schema validate reports in sync. Then FeedTest passes.

- [ ] **Step 6: Commit** — `git add backend/src/Entity/Feed.php backend/migrations/Version20260723200000.php backend/tests/Entity/FeedTest.php && git commit -m "feat(feed): sourceFormat column ('xml'|'scraped') with backfilling migration"`

---

### Task 3: TextNormalizer + ScrapedItem + HtmlExtractionException

**Files:**
- Create: `backend/src/Service/Scraper/TextNormalizer.php`, `backend/src/Service/Scraper/ScrapedItem.php`, `backend/src/Service/Scraper/Exception/HtmlExtractionException.php`
- Test: `backend/tests/Service/Scraper/TextNormalizerTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testStripsSoftHyphensAndCollapsesWhitespace(): void
    {
        self::assertSame(
            'Gesundheitsministerin Warken',
            TextNormalizer::normalize("Gesundheits\u{00AD}ministerin\n   Warken  ")
        );
    }

    public function testEmptyBecomesEmptyString(): void
    {
        self::assertSame('', TextNormalizer::normalize("\u{00AD} \n "));
    }
}
```

- [ ] **Step 2: Run — FAIL**: `docker compose exec -T php vendor/bin/phpunit tests/Service/Scraper/TextNormalizerTest.php`
- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Scraper;

/** Cleans text extracted from scraped HTML (soft hyphens, run-on whitespace). */
final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        $text = str_replace("\u{00AD}", '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Scraper;

/** One article-like item found on a scraped HTML page, before ParsedEntry mapping. */
final readonly class ScrapedItem
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $teaser = null,
        public ?string $imageUrl = null,
        public ?\DateTimeImmutable $publishedAt = null,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Scraper\Exception;

use App\Service\Parser\Exception\FeedParseException;

/**
 * No article list could be extracted from the page. Extends FeedParseException
 * so the refresh pipeline's existing parse-failure handling applies unchanged.
 */
final class HtmlExtractionException extends FeedParseException
{
}
```

- [ ] **Step 4: Run — PASS**, then commit: `git add backend/src/Service/Scraper backend/tests/Service/Scraper && git commit -m "feat(scraper): TextNormalizer, ScrapedItem, HtmlExtractionException"`

---

### Task 4: CardFields (title chain, teaser, image, date per card)

**Files:**
- Create: `backend/src/Service/Scraper/CardFields.php`
- Test: `backend/tests/Service/Scraper/CardFieldsTest.php`

- [ ] **Step 1: Failing tests** (synthetic HTML snippets; parse with `\Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR)`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\CardFields;
use PHPUnit\Framework\TestCase;

final class CardFieldsTest extends TestCase
{
    /** @return array{\Dom\Element, \Dom\Element} container + anchor from a snippet */
    private function card(string $html): array
    {
        $doc = \Dom\HTMLDocument::createFromString("<html><body>{$html}</body></html>", \LIBXML_NOERROR);
        $container = $doc->querySelector('[data-card]');
        \assert($container instanceof \Dom\Element);
        $anchor = $container->tagName === 'A' ? $container : $container->querySelector('a');
        \assert($anchor instanceof \Dom\Element);

        return [$container, $anchor];
    }

    public function testTitleFromHeadingAndTeaserFromLongestParagraph(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <div data-card><a href="/a/1"><h3>A proper headline here</h3>
            <p>Short.</p>
            <p>This teaser paragraph is comfortably longer than forty characters in total.</p></a></div>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertNotNull($item);
        self::assertSame('https://site.test/a/1', $item->url);
        self::assertSame('A proper headline here', $item->title);
        self::assertStringContainsString('comfortably longer', (string) $item->teaser);
    }

    public function testTitleFromClassNameWhenNoHeading(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <a data-card href="/a/2"><span class="card__title-text">Span-only title text</span>
            <div class="byline">By Someone</div></a>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertSame('Span-only title text', $item?->title);
    }

    public function testTitleFallsBackToFirstAnchorTextLineNeverFullText(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <a data-card href="/a/3">First line of the card
            <div>Second block that must not be part of the title but is long enough to matter here.</div></a>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertSame('First line of the card', $item?->title);
    }

    public function testTeaserFromDataAttributeFallback(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <a data-card href="/a/4" data-card-description="Attribute description text well over forty characters long for the fallback.">
            <span class="card__title">Attr card</span><div class="card__description"></div></a>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertStringContainsString('Attribute description', (string) $item?->teaser);
    }

    public function testImageAndTimeAndRejectsNonHttpLinks(): void
    {
        [$c, $a] = $this->card(<<<HTML
            <div data-card><a href="/a/5"><h2>With media data</h2></a>
            <img data-src="/img/pic.jpg"><time datetime="2026-07-20T10:00:00+02:00">yesterday</time></div>
            HTML);
        $item = CardFields::item($c, $a, 'https://site.test/');
        self::assertSame('https://site.test/img/pic.jpg', $item?->imageUrl);
        self::assertSame('2026-07-20', $item?->publishedAt?->format('Y-m-d'));

        [$c2, $a2] = $this->card('<div data-card><a href="javascript:alert(1)"><h2>Bad link</h2></a></div>');
        self::assertNull(CardFields::item($c2, $a2, 'https://site.test/'));
    }

    public function testShortTitleRejected(): void
    {
        [$c, $a] = $this->card('<div data-card><a href="/a/6"><h2>Hi</h2></a></div>');
        self::assertNull(CardFields::item($c, $a, 'https://site.test/'));
    }
}
```

- [ ] **Step 2: Run — FAIL**: `docker compose exec -T php vendor/bin/phpunit tests/Service/Scraper/CardFieldsTest.php`
- [ ] **Step 3: Implement** — `CardFields.php`. Structure (keep each private method small for phpmd):

```php
<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Fetch\UrlResolver;
use App\Service\Parser\DateParser;

/**
 * Extracts one ScrapedItem from a card container + its anchor. Field rules per
 * the design spec: title = heading, else class~=title|headline element, else the
 * anchor's first text line (never the anchor's full text); teaser = longest
 * non-title text block >= MIN_TEASER_LENGTH, else a data-*description* attribute;
 * image = first img src/data-src/srcset; date = first <time datetime>.
 */
final class CardFields
{
    private const int MIN_TITLE_LENGTH = 5;
    private const int MAX_TITLE_LENGTH = 300;
    private const int MIN_TEASER_LENGTH = 40;
    private const int MAX_TEASER_LENGTH = 1000;

    public static function item(\Dom\Element $container, \Dom\Element $anchor, string $baseUrl): ?ScrapedItem
    {
        $url = self::httpUrl($anchor->getAttribute('href'), $baseUrl);
        if ($url === null) {
            return null;
        }
        $title = self::title($container, $anchor);
        if ($title === null) {
            return null;
        }

        return new ScrapedItem(
            url: $url,
            title: $title,
            teaser: self::teaser($container, $title),
            imageUrl: self::image($container, $baseUrl),
            publishedAt: self::publishedAt($container),
        );
    }
    // private helpers below
}
```

Private helpers (implement exactly this logic):
- `httpUrl(?string $href, string $baseUrl): ?string` — empty/null href → null; `UrlResolver::resolve($baseUrl, $href)`; result must `preg_match('#^https?://#i', …)` else null.
- `title(\Dom\Element $container, \Dom\Element $anchor): ?string` — chain: (1) `$container->querySelector('h1, h2, h3, h4')`; (2) first element in `$container->querySelectorAll('*')` whose `class` attribute matches `/(title|headline)/i` **and** whose normalized own text is non-empty (iterate; skip elements with a matching descendant to prefer the innermost — simplest correct rule: take the LAST match in document order whose text is non-empty, which is the innermost for nested `card__title > card__title-text` wrappers, then verify against the treehugger fixture in Task 8); (3) first text line: `explode("\n", $anchor->textContent ?? '')`, first entry whose `TextNormalizer::normalize()` is non-empty. Normalize; return null unless length in `[MIN_TITLE_LENGTH, MAX_TITLE_LENGTH]` (mb_strlen; truncate at MAX rather than reject when over).
- `teaser(\Dom\Element $container, string $title): ?string` — walk `$container->querySelectorAll('p, div, span')`; keep elements that are "leaf-ish": no child element from `p, div, ul, ol, h1, h2, h3, h4, article, section`; normalize own textContent; drop if `< MIN_TEASER_LENGTH` or equal to `$title` or containing `$title` as substring; take the longest; if none, data-attribute fallback: scan `$container` attributes and each direct child element's attributes for a name matching `/descri/i` with normalized value ≥ MIN_TEASER_LENGTH — take the first. `mb_substr(…, 0, MAX_TEASER_LENGTH)`.
- `image(\Dom\Element $container, string $baseUrl): ?string` — first `img` element; candidate = `src`, else `data-src`, else first URL token of `srcset` (split on `,`, take first, split on whitespace, take first); resolve via `httpUrl()`.
- `publishedAt(\Dom\Element $container): ?\DateTimeImmutable` — first `time[datetime]`, `DateParser::parse($el->getAttribute('datetime'))`.

- [ ] **Step 4: Run — PASS**, full scraper dir: `docker compose exec -T php vendor/bin/phpunit tests/Service/Scraper`
- [ ] **Step 5: Commit** — `git add -A backend/src/Service/Scraper backend/tests/Service/Scraper && git commit -m "feat(scraper): CardFields per-card extraction (title chain, teaser, image, date)"`

---

### Task 5: JsonLdLayer

**Files:**
- Create: `backend/src/Service/Scraper/Layer/ScrapeLayerInterface.php`, `backend/src/Service/Scraper/Layer/JsonLdLayer.php`
- Create fixture: `backend/tests/Fixtures/scraped/jsonld-list.html`
- Test: `backend/tests/Service/Scraper/JsonLdLayerTest.php`

- [ ] **Step 1: Fixture** — hand-written page with a JSON-LD `ItemList` of 4 `ListItem`s (mix of `item: {…NewsArticle…}` objects and bare `url`), one `@graph` wrapper, plus one decoy `Organization` block. Include `headline`, `description`, `image` (once as string, once as `{url: …}`), `datePublished`, and one relative `url` to prove base-URL resolution.
- [ ] **Step 2: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\Layer\JsonLdLayer;
use PHPUnit\Framework\TestCase;

final class JsonLdLayerTest extends TestCase
{
    private function extract(string $fixture): array
    {
        $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $fixture);
        $doc = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);

        return new JsonLdLayer()->extract($doc, 'https://news.test/section/');
    }

    public function testExtractsItemListWithFields(): void
    {
        $items = $this->extract('jsonld-list.html');
        self::assertCount(4, $items);
        self::assertSame('https://news.test/story-1', $items[0]->url);   // relative resolved
        self::assertNotNull($items[0]->teaser);
        self::assertNotNull($items[0]->imageUrl);
        self::assertSame('2026-07-20', $items[0]->publishedAt?->format('Y-m-d'));
    }

    public function testIgnoresPagesWithoutArticleStructures(): void
    {
        $doc = \Dom\HTMLDocument::createFromString(
            '<html><body><script type="application/ld+json">{"@type":"Organization","name":"X"}</script></body></html>',
            \LIBXML_NOERROR
        );
        self::assertSame([], new JsonLdLayer()->extract($doc, 'https://news.test/'));
    }
}
```

- [ ] **Step 3: Run — FAIL**, then implement:

```php
<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Scraper\ScrapedItem;

interface ScrapeLayerInterface
{
    /** @return list<ScrapedItem> */
    public function extract(\Dom\HTMLDocument $doc, string $baseUrl): array;
}
```

`JsonLdLayer implements ScrapeLayerInterface`:
- `extract()`: for each `script[type="application/ld+json"]`, `json_decode($el->textContent ?? '', true)`; skip non-arrays; recurse `collect($node)`; return the merged list.
- `collect(array $node, string $baseUrl): array` — if list (array_is_list): merge collect of each element. If `@graph` present: collect it. If `@type` (string or array) contains `ItemList`: map `itemListElement` entries — each `ListItem` uses `item` (assoc → treat as article node) or `url`+`name`. If `@type` contains one of `NewsArticle|BlogPosting|Article`: build one item.
- Article node → item: url from `url` (string) or `mainEntityOfPage` (string or `['@id']`); title from `headline` ?? `name`; teaser `description`; image from `image` (string, `['url']`, or first element of list); date `DateParser::parse($node['datePublished'] ?? null)`. Resolve url against `$baseUrl` and require http(s) (reuse the same resolution rule as CardFields — extract a small shared static helper `CardFields::httpUrl()` made `public static`, documented as shared with layers, rather than duplicating). Normalize title/teaser via `TextNormalizer::normalize()`; drop nodes without url or with title shorter than 5 chars.

- [ ] **Step 4: Run — PASS**, commit: `git add -A backend/src/Service/Scraper backend/tests && git commit -m "feat(scraper): JSON-LD layer (ItemList and article arrays)"`

---

### Task 6: SemanticLayer (repeated `<article>`)

**Files:**
- Create: `backend/src/Service/Scraper/Layer/SemanticLayer.php`
- Create fixture: `backend/tests/Fixtures/scraped/articles-blog.html` (a small blog page: `<main>` with 5 `<article>` elements, each `<h2><a href>` + `<p>` teaser ≥40 chars + one `<img>`; plus `<nav>`/`<footer>` link lists as decoys)
- Test: `backend/tests/Service/Scraper/SemanticLayerTest.php`

- [ ] **Step 1: Failing test**

```php
public function testExtractsRepeatedArticleElements(): void
{
    $items = $this->extract('articles-blog.html'); // same helper shape as JsonLdLayerTest
    self::assertCount(5, $items);
    self::assertStringStartsWith('https://blog.test/', $items[0]->url);
    self::assertNotNull($items[0]->teaser);
}

public function testFewerThanThreeArticlesYieldsNothing(): void
{
    $doc = \Dom\HTMLDocument::createFromString(
        '<html><body><article><h2><a href="/one">Single article headline</a></h2></article></body></html>',
        \LIBXML_NOERROR
    );
    self::assertSame([], new SemanticLayer()->extract($doc, 'https://blog.test/'));
}
```

- [ ] **Step 2: Implement** — `SemanticLayer implements ScrapeLayerInterface`:

```php
public function extract(\Dom\HTMLDocument $doc, string $baseUrl): array
{
    $articles = $doc->querySelectorAll('article');
    if (\count($articles) < 3) {
        return [];
    }
    $items = [];
    foreach ($articles as $article) {
        $anchor = $article->querySelector('a[href]');
        if (!$anchor instanceof \Dom\Element) {
            continue;
        }
        $item = CardFields::item($article, $anchor, $baseUrl);
        if ($item !== null) {
            $items[] = $item;
        }
    }

    return $items;
}
```

(Heading-anchor patterns without `<article>` are deliberately left to the cluster layer — the spec was amended accordingly.)

- [ ] **Step 3: Run — PASS**, commit: `git commit -am "feat(scraper): semantic layer (repeated article elements)"`

---

### Task 7: ClusterLayer (anchor DOM-path clustering)

**Files:**
- Create: `backend/src/Service/Scraper/Layer/ClusterLayer.php`
- Create fixtures: `backend/tests/Fixtures/scraped/nav-only.html` (a page whose only repeated links are inside `<nav>`/`<footer>`/`<header>` — menus, tag clouds; zero article cards), `backend/tests/Fixtures/scraped/footer-links.html` (3 real cards in `<main>` with headings + teasers, but a 20-link footer list as the numerically biggest cluster)
- Test: `backend/tests/Service/Scraper/ClusterLayerTest.php`

- [ ] **Step 1: Failing tests**

```php
public function testFindsTagesschauTeaserCluster(): void
{
    $items = $this->extract('tagesschau-2026-07-23.html', 'https://www.tagesschau.de/');
    self::assertGreaterThanOrEqual(20, \count($items));
    $first = $items[0];
    self::assertStringNotContainsString("\u{00AD}", $first->title);
    self::assertMatchesRegularExpression('#^https://www\.tagesschau\.de/#', $first->url);
    $withTeaser = array_filter($items, static fn ($i) => $i->teaser !== null);
    self::assertGreaterThanOrEqual(10, \count($withTeaser));
}

public function testChromeOnlyPagesYieldNothing(): void
{
    self::assertSame([], $this->extract('nav-only.html', 'https://nav.test/'));
}

public function testFooterListDoesNotBeatRealCards(): void
{
    $items = $this->extract('footer-links.html', 'https://footer.test/');
    self::assertCount(3, $items);
    self::assertStringContainsString('/posts/', $items[0]->url);
}
```

- [ ] **Step 2: Implement** — `ClusterLayer implements ScrapeLayerInterface`. Algorithm (keep helpers small):

1. **Collect eligible anchors:** every `a[href]` in the document EXCEPT those with a `nav`, `header`, `footer`, or `aside` ancestor (walk `parentElement` chain, or `closest('nav, header, footer, aside') !== null`). This implements the spec's chrome penalty as exclusion.
2. **Signature:** for each anchor, build the DOM-path signature from `<body>` down: each segment `strtolower($el->tagName) . '.' . $firstClass` where `$firstClass` = first whitespace-separated token of the `class` attribute (empty string when none). Join with `>`.
3. **Group** anchors by signature; drop groups with < 3 anchors.
4. **Score** each group: derive candidate items first (steps 5–6) — a group's score is `count($items) * (1 + $titledFraction)` where `$titledFraction` = fraction of items whose title came from a heading or class-hint (expose which chain step matched via a small enum or by having `CardFields` return null-vs-item only — simplest: score = `count($items)` and, as tiebreak, prefer the group with the higher mean title length; document the choice in code). Require ≥3 items after CardFields filtering.
5. **Container per anchor:** start at the anchor; ascend up to 3 `parentElement` hops while the parent exists, is not `body`, and contains exactly 1 eligible anchor (`count of a[href] descendants === 1`) — this finds the card wrapper without swallowing sibling cards.
6. **Fields:** `CardFields::item($container, $anchor, $baseUrl)`; drop nulls.
7. Return the best-scoring group's items in document order; `[]` when none qualifies.

- [ ] **Step 3: Run — PASS** (iterate on scoring until the three tests hold; the tagesschau fixture is the ground truth): `docker compose exec -T php vendor/bin/phpunit tests/Service/Scraper/ClusterLayerTest.php`
- [ ] **Step 4: Commit** — `git add -A backend/src backend/tests && git commit -m "feat(scraper): cluster layer (anchor DOM-path clustering with chrome exclusion)"`

---

### Task 8: HtmlItemExtractor facade

**Files:**
- Create: `backend/src/Service/Scraper/HtmlItemExtractor.php`
- Test: `backend/tests/Service/Scraper/HtmlItemExtractorTest.php`

- [ ] **Step 1: Failing tests**

```php
final class HtmlItemExtractorTest extends TestCase
{
    private function extractor(): HtmlItemExtractor
    {
        return new HtmlItemExtractor(new JsonLdLayer(), new SemanticLayer(), new ClusterLayer());
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/' . $name);
    }

    public function testTagesschauFullExtraction(): void
    {
        $parsed = $this->extractor()->extract($this->fixture('tagesschau-2026-07-23.html'), 'https://www.tagesschau.de/');
        self::assertNotNull($parsed->title);
        self::assertSame('https://www.tagesschau.de/', $parsed->siteUrl);
        self::assertGreaterThanOrEqual(20, \count($parsed->entries));
        self::assertLessThanOrEqual(50, \count($parsed->entries));
        $urls = array_map(static fn ($e) => $e->url, $parsed->entries);
        self::assertSame($urls, array_unique($urls));               // dedupe
        $first = $parsed->entries[0];
        self::assertSame($first->url, $first->guid);                // guid = article URL
        self::assertNotNull($first->contentHtml);
        self::assertStringStartsWith('<p>', (string) $first->contentHtml);
    }

    public function testTreehuggerTitlesAndAttributeTeasers(): void
    {
        $parsed = $this->extractor()->extract($this->fixture('treehugger-rendered-2026-07-23.html'), 'https://www.treehugger.com/');
        self::assertGreaterThanOrEqual(10, \count($parsed->entries));
        $titles = array_map(static fn ($e) => $e->title, $parsed->entries);
        self::assertContains('Your Yard’s Next Big Upgrade: A Rain Garden You Can Build Yourself', $titles);
        foreach ($titles as $t) {
            self::assertLessThan(301, mb_strlen($t)); // no full-card text mashed into titles
        }
        $teasers = array_filter($parsed->entries, static fn ($e) => $e->summary !== null);
        self::assertNotEmpty($teasers); // data-card-description fallback delivered
    }

    public function testJsonLdWinsOverClustering(): void
    {
        // jsonld-list.html carries exactly 4 JSON-LD items; if clustering ran
        // instead, the count would differ (the fixture also contains link markup).
        $parsed = $this->extractor()->extract($this->fixture('jsonld-list.html'), 'https://news.test/section/');
        self::assertCount(4, $parsed->entries);
    }

    public function testHostilePagesThrow(): void
    {
        $this->expectException(HtmlExtractionException::class);
        $this->extractor()->extract($this->fixture('nav-only.html'), 'https://nav.test/');
    }

    public function testUnparseableInputThrows(): void
    {
        $this->expectException(HtmlExtractionException::class);
        $this->extractor()->extract('', 'https://empty.test/');
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Parser\GuidFallback;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Scraper\Exception\HtmlExtractionException;
use App\Service\Scraper\Layer\ClusterLayer;
use App\Service\Scraper\Layer\JsonLdLayer;
use App\Service\Scraper\Layer\ScrapeLayerInterface;
use App\Service\Scraper\Layer\SemanticLayer;

/**
 * Synthesizes a feed from an HTML listing page: three heuristic layers
 * (structured data, semantic markup, anchor clustering), first success wins.
 * Guards: >=3 items or HtmlExtractionException; dedupe by URL; cap at 50.
 */
final readonly class HtmlItemExtractor
{
    private const int MIN_ITEMS = 3;
    private const int MAX_ITEMS = 50;

    public function __construct(
        private JsonLdLayer $jsonLd,
        private SemanticLayer $semantic,
        private ClusterLayer $cluster,
    ) {
    }

    public function extract(string $html, string $baseUrl): ParsedFeed
    {
        $doc = $this->parse($html);
        $items = $this->firstSuccessfulLayer($doc, $baseUrl);

        return new ParsedFeed(
            title: $this->feedTitle($doc),
            siteUrl: $baseUrl,
            description: $this->metaDescription($doc),
            entries: array_map($this->toEntry(...), \array_slice($items, 0, self::MAX_ITEMS)),
        );
    }
    // private: parse() (createFromString wrapped, \Throwable → HtmlExtractionException;
    //   also throw on trim($html) === ''),
    // firstSuccessfulLayer() (foreach [$this->jsonLd, $this->semantic, $this->cluster] as
    //   ScrapeLayerInterface: $items = $this->guarded($layer->extract($doc, $baseUrl), $baseUrl);
    //   if count >= MIN_ITEMS return; after loop throw HtmlExtractionException('No article list was detected on the page.')),
    // guarded(array $items, string $baseUrl): list<ScrapedItem>
    //   (drop items whose url equals baseUrl after rtrim '/'; dedupe by url, first wins),
    // feedTitle(): meta[property="og:site_name"] content, else <title> text, normalized, null when empty,
    // metaDescription(): meta[name="description"] content, normalized, null when empty,
    // toEntry(ScrapedItem $item): ParsedEntry — exactly:
    //   new ParsedEntry(
    //       guid: GuidFallback::for($item->url, $item->url, $item->title),
    //       url: $item->url,
    //       title: $item->title,
    //       author: null,
    //       summary: $item->teaser,
    //       contentHtml: $item->teaser === null ? null
    //           : '<p>' . htmlspecialchars($item->teaser, \ENT_QUOTES) . '</p>',
    //       publishedAt: $item->publishedAt,
    //       imageUrl: $item->imageUrl,
    //   )
}
```

(Write the private methods out fully — the comment block above specifies each one's exact behavior.)

- [ ] **Step 3: Run — PASS**: `docker compose exec -T php vendor/bin/phpunit tests/Service/Scraper`
- [ ] **Step 4: Quality gates on the whole Scraper namespace**: `docker compose exec -T php composer cs && docker compose exec -T php composer stan && docker compose exec -T php composer md`
- [ ] **Step 5: Commit** — `git add -A backend && git commit -m "feat(scraper): HtmlItemExtractor facade — layered extraction to ParsedFeed"`

---

### Task 9: Discovery fallback + failure reasons

**Files:**
- Modify: `backend/src/Service/Discovery/FeedDiscoveryResult.php`, `backend/src/Service/Discovery/FeedDiscovery.php`
- Check/modify: usages of `FeedDiscoveryException` (`grep -rn "FeedDiscoveryException" backend/src backend/tests`) — if the class becomes unused, delete it and its listener/controller mappings; update affected tests
- Test: `backend/tests/Service/Discovery/FeedDiscoveryTest.php` (KernelTestCase + StubFeedFetcher pattern already in the file)

- [ ] **Step 1: Failing tests** (follow the file's existing stub/DI pattern):

```php
public function testFeedlessPageYieldsScrapedCandidate(): void
{
    $html = (string) file_get_contents(__DIR__ . '/../../Fixtures/scraped/tagesschau-2026-07-23.html');
    // stub fetcher returns FetchResponse::fetched('https://www.tagesschau.de/', false, $html, null, null)
    $result = $discovery->discover('https://www.tagesschau.de/');
    self::assertFalse($result->isDirectFeed);
    self::assertNull($result->scrapeFailureReason);
    self::assertCount(1, $result->candidates);
    self::assertSame('scraped', $result->candidates[0]->format);
    self::assertSame('https://www.tagesschau.de/', $result->candidates[0]->url);
    self::assertNotNull($result->candidates[0]->title);
}

public function testNativeFeedLinksSuppressScraping(): void { /* page with <link rel=alternate>: candidates have formats rss/atom only, no 'scraped', reason null */ }

public function testBlockedFetchReason(): void
{
    // stub willThrow(url, new FeedUnreachableException('x: HTTP 403', 403))
    $result = $discovery->discover($url);
    self::assertSame('blocked', $result->scrapeFailureReason);
    self::assertSame([], $result->candidates);
}

public function testUnreachableFetchReason(): void { /* willThrow FeedUnreachableException('DNS', null) → 'unreachable' */ }

public function testArticleFreePageReason(): void
{
    // stub returns nav-only.html body → 'not_scrapable'
}
```

- [ ] **Step 2: Implement**

`FeedDiscoveryResult`: extend the private constructor with `public ?string $scrapeFailureReason = null` (last param, default null so `directFeed()`/`candidates()` stay unchanged) and add:

```php
/** @param 'blocked'|'unreachable'|'not_scrapable' $reason */
public static function scrapeFailed(string $reason): self
{
    return new self(false, null, [], $reason);
}
```

`FeedDiscovery`:
- Constructor gains `private HtmlItemExtractor $extractor`.
- Replace the fetch-error rethrow with:

```php
try {
    $response = $this->fetcher->fetch($url);
} catch (FeedUnreachableException $e) {
    return FeedDiscoveryResult::scrapeFailed(
        \in_array($e->statusCode, [401, 403, 429], true) ? 'blocked' : 'unreachable'
    );
} catch (FetchException) {
    return FeedDiscoveryResult::scrapeFailed('unreachable');
}
```

- Empty body → `return FeedDiscoveryResult::scrapeFailed('not_scrapable');`
- `[] === $candidates` after `scanHtml()` → replace the throw with:

```php
try {
    $parsed = $this->extractor->extract($body, $response->finalUrl);
} catch (\Throwable) {
    return FeedDiscoveryResult::scrapeFailed('not_scrapable');
}

return FeedDiscoveryResult::candidates([
    new FeedCandidate($response->finalUrl, $parsed->title, 'scraped'),
]);
```

- If `FeedDiscoveryException` is now unreferenced (grep!), delete the class and remove its mapping/catch sites (likely `ApiExceptionListener` and/or `SubscriptionController`); update any test asserting the old problem+json error for unfetchable/feedless URLs to expect the new candidate/reason shape instead (those assertions move to Task 10's controller tests).

- [ ] **Step 3: Run — PASS**: `docker compose exec -T php vendor/bin/phpunit tests/Service/Discovery` then the full suite `docker compose exec -T php vendor/bin/phpunit` (expect fallout only in subscription/preview tests that Task 10/11 own; fix any discovery-owned fallout now).
- [ ] **Step 4: Commit** — `git add -A backend && git commit -m "feat(discovery): scrape fallback candidate + blocked/unreachable/not_scrapable reasons"`

---

### Task 10: Subscribe path carries format + reason; SubscriptionJson exposes sourceFormat

**Files:**
- Modify: `backend/src/Service/Subscription/SubscribeOutcome.php`, `backend/src/Service/Subscription/SubscriptionService.php`, `backend/src/Controller/Api/SubscriptionController.php`, `backend/src/Dto/Subscription/SubscribeRequest.php`, `backend/src/Http/SubscriptionJson.php`
- Test: `backend/tests/Controller/Api/SubscriptionControllerTest.php`

- [ ] **Step 1: Failing tests** (WebTestCase + JWT + `self::getContainer()->set(FeedFetcherInterface::class, $stub)` — all patterns already in this file):

```php
public function testSubscribeToFeedlessPageReturnsScrapedCandidate(): void
{
    // stub: tagesschau fixture body
    // POST /api/subscriptions {"url": "https://www.tagesschau.de/"}
    // assert 200, json.candidates[0].format === 'scraped', no scrapeFailureReason key or null
}

public function testSubscribeToBlockedSiteReturnsReason(): void
{
    // stub willThrow FeedUnreachableException('x: HTTP 403', 403)
    // assert 200, json == {"candidates": [], "scrapeFailureReason": "blocked"}
}

public function testSubscribingScrapedCandidatePersistsSourceFormat(): void
{
    // stub: tagesschau fixture body for the page URL
    // POST {"url": "https://www.tagesschau.de/", "format": "scraped"}
    // assert 201, json.subscription.sourceFormat === 'scraped'
    // and via container FeedRepository: feed(url)->getSourceFormat() === 'scraped'
}

public function testPlainSubscribeKeepsXmlFormat(): void
{
    // existing direct-feed stub path: subscription.sourceFormat === 'xml'
}
```

- [ ] **Step 2: Implement**
- `SubscribeRequest`: add `public ?string $format = null;` with `#[Assert\Length(max: 20)]` (open string by design; no Choice constraint).
- `SubscribeOutcome`: private ctor gains `public ?string $scrapeFailureReason = null`; `candidates(array $candidates, ?string $scrapeFailureReason = null)` passes it through. (`subscribed()` unchanged.)
- `SubscriptionService::subscribe(User $user, string $url, ?string $format = null)`:
  - candidates branch: `return SubscribeOutcome::candidates($result->candidates, $result->scrapeFailureReason);`
  - direct-feed branch: when creating a NEW `Feed` and `$format === 'scraped'`, call `$feed->setSourceFormat('scraped')` — **but** the discovery result for a scraped page is NOT a direct feed. Resolve this precisely: when `$format === 'scraped'`, skip discovery entirely and treat `$url` as the feed URL (the user is re-posting a candidate URL the previous discovery produced): guard with the same subscription cap, `findOneBy(['url' => $url])`, create `Feed` + `setSourceFormat('scraped')` if absent (do NOT override an existing feed's format), then the shared subscription-creation code. Extract the existing row-creation block into a private method `subscribeToFeedUrl(User $user, string $feedUrl, ?string $format): SubscribeOutcome` used by both paths.
- `SubscriptionController::create`: pass `$request->format` through; include the reason in the candidates response:

```php
$payload = ['candidates' => array_map(/* existing mapper */, $outcome->candidates)];
if ($outcome->scrapeFailureReason !== null) {
    $payload['scrapeFailureReason'] = $outcome->scrapeFailureReason;
}
return new JsonResponse($payload);
```

- `SubscriptionJson::one()`: add `'sourceFormat' => $feed->getSourceFormat(),` (locate the `$feed` variable already used for `status`).
- [ ] **Step 3: Run — PASS**: `docker compose exec -T php vendor/bin/phpunit tests/Controller/Api/SubscriptionControllerTest.php`, then full suite; fix fallout in tests asserting the old subscription JSON shape (add `sourceFormat`).
- [ ] **Step 4: Commit** — `git add -A backend && git commit -m "feat(subscribe): scraped-candidate subscribe, failure reasons in response, sourceFormat in JSON"`

---

### Task 11: Preview branches on format

**Files:**
- Modify: `backend/src/Dto/Feed/PreviewFeedRequest.php`, `backend/src/Service/Preview/FeedPreviewService.php`, `backend/src/Controller/Api/FeedPreviewController.php`
- Test: `backend/tests/Controller/Api/FeedPreviewControllerTest.php` (existing file; extend)

- [ ] **Step 1: Failing test**

```php
public function testScrapedPreviewExtractsListing(): void
{
    // stub fetcher: tagesschau fixture body
    // POST /api/feeds/preview {"url": "https://www.tagesschau.de/", "format": "scraped"}
    // assert 200; json.feed.itemCount >= 20; json.feed.items[0].title non-empty;
    // json.feed.content === 'summary' (teasers, no full text)
}

public function testScrapedPreviewOfArticleFreePageIs422(): void
{
    // stub: nav-only.html body; format 'scraped' → 422 problem+json
}
```

- [ ] **Step 2: Implement**
- `PreviewFeedRequest`: add `public ?string $format = null;` with `#[Assert\Length(max: 20)]`.
- `FeedPreviewService`: constructor gains `private HtmlItemExtractor $extractor`; signature `preview(string $url, ?string $format = null): FeedPreview`; after the body guard:

```php
try {
    $parsed = $format === 'scraped'
        ? $this->extractor->extract($body, $response->finalUrl)
        : $this->parser->parse($body);
} catch (FeedParseException $e) {
    throw new FeedPreviewException('That address is not a readable feed.', 0, $e);
}
```

(`HtmlExtractionException extends FeedParseException`, so one catch covers both branches.)
- Controller: `$this->previews->preview($request->url, $request->format)`.
- [ ] **Step 3: Run — PASS** + commit: `git add -A backend && git commit -m "feat(preview): scraped-format preview via HtmlItemExtractor"`

---

### Task 12: Refresh pipeline branch via FeedBodyParser

**Files:**
- Create: `backend/src/Service/Refresh/FeedBodyParser.php`
- Modify: `backend/src/Service/Refresh/RefreshRunner.php` (swap `FeedParser $parser` dep for `FeedBodyParser`, call `$this->bodyParser->parse($feed, $body)`)
- Test: `backend/tests/Service/Refresh/RefreshRunnerTest.php` (existing file; extend using its stub-fetcher pattern)

- [ ] **Step 1: Failing tests**

```php
public function testScrapedFeedRefreshCreatesEntriesIdempotently(): void
{
    // create Feed('https://www.tagesschau.de/'), setSourceFormat('scraped'), persist + subscription per file pattern
    // stub fetch: tagesschau fixture body
    // run refresh → assert >= 20 Entry rows for the feed, entry guids are article URLs,
    //   contentHtml of a teasered entry starts with '<p>'
    // run refresh again with same body → assert entry count unchanged (guid dedupe)
}

public function testScrapedFeedExtractionFailureRecordsError(): void
{
    // scraped feed; stub body = nav-only.html fixture
    // run refresh → feed->getStatus() === FeedStatus::Erroring, lastErrorMessage mentions 'article list'
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Parser\FeedParser;
use App\Service\Parser\ParsedFeed;
use App\Service\Scraper\HtmlItemExtractor;

/** Turns a fetched feed body into ParsedFeed according to the feed's sourceFormat. */
final readonly class FeedBodyParser
{
    public function __construct(
        private FeedParser $parser,
        private HtmlItemExtractor $extractor,
    ) {
    }

    public function parse(Feed $feed, string $body): ParsedFeed
    {
        return $feed->getSourceFormat() === 'scraped'
            ? $this->extractor->extract($body, $feed->getUrl())
            : $this->parser->parse($body);
    }
}
```

`RefreshRunner`: replace the `FeedParser` constructor param with `FeedBodyParser $bodyParser`; `fetchParseAndPersist()` calls `$parsed = $this->bodyParser->parse($feed, $body);`. Fix `RefreshRunner` construction sites in tests (grep for `new RefreshRunner(`).

- [ ] **Step 3: Run — PASS**: `docker compose exec -T php vendor/bin/phpunit tests/Service/Refresh` then the FULL backend suite: `docker compose exec -T php vendor/bin/phpunit`
- [ ] **Step 4: Commit** — `git add -A backend && git commit -m "feat(refresh): sourceFormat branch via FeedBodyParser"`

---

### Task 13: Frontend — models, API, dialog warning + scraped hint

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/reader-api.ts`, `frontend/src/app/reader/add-feed/add-feed-dialog.component.ts`
- Test: `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`

- [ ] **Step 1: Failing tests** (HttpTestingController pattern already in the spec file):

```typescript
it('shows a blocked warning instead of subscribe options', () => {
  const f = create();
  f.componentInstance.form.setValue({ url: 'https://blocked.example' });
  f.componentInstance.submit();
  ctrl.expectOne('https://api.test/api/subscriptions').flush({
    candidates: [],
    scrapeFailureReason: 'blocked',
  });
  f.detectChanges();
  const el = f.nativeElement as HTMLElement;
  expect(el.querySelector('.warn')?.textContent).toContain('blocks automated access');
  expect(el.querySelector('button.subscribe')).toBeNull();
  expect(el.querySelector('button[type="submit"]')).toBeNull(); // footer Add hidden too
});

it('clears the warning when the URL is edited', () => {
  // …flush blocked as above…
  f.componentInstance.form.setValue({ url: 'https://other.example' });
  f.detectChanges();
  expect((f.nativeElement as HTMLElement).querySelector('.warn')).toBeNull();
  expect((f.nativeElement as HTMLElement).querySelector('button[type="submit"]')).not.toBeNull();
});

it('renders a scraped candidate with badge, hint, and format-carrying preview/subscribe', () => {
  // flush { candidates: [{ url: 'https://page.example/', title: 'Page', format: 'scraped' }] }
  // expect .badge.format text 'Scraped'; expect .scraped-hint text to contain 'article list'
  // expect the preview POST body to equal { url: 'https://page.example/', format: 'scraped' }
  // click .subscribe → expect POST body { url: 'https://page.example/', format: 'scraped' }
});
```

- [ ] **Step 2: Run — FAIL**: `cd frontend && npx jest src/app/reader/add-feed`
- [ ] **Step 3: Implement**

`models.ts`:

```typescript
export type ScrapeFailureReason = 'blocked' | 'unreachable' | 'not_scrapable';

/** POST /subscriptions returns the created subscription, candidates, or a scrape failure. */
export type SubscribeResult =
  | { subscription: SubscriptionDto }
  | { candidates: FeedCandidate[]; scrapeFailureReason?: ScrapeFailureReason };
```

`SubscriptionDto` gains `sourceFormat: string;` (after `status`).

`reader-api.ts`:

```typescript
subscribe(url: string, format?: string): Observable<SubscribeResult> {
  return this.http.post<SubscribeResult>(`${this.base}/api/subscriptions`, format ? { url, format } : { url });
}

/** Preview a candidate feed's contents before subscribing. */
previewFeed(url: string, format?: string): Observable<{ feed: FeedPreview }> {
  return this.http.post<{ feed: FeedPreview }>(`${this.base}/api/feeds/preview`, format ? { url, format } : { url });
}
```

`add-feed-dialog.component.ts` class changes:

```typescript
readonly failureReason = signal<ScrapeFailureReason | null>(null);

constructor() {
  this.form.controls.url.valueChanges
    .pipe(takeUntilDestroyed())
    .subscribe(() => this.failureReason.set(null));
}

failureText(reason: ScrapeFailureReason): string {
  switch (reason) {
    case 'blocked':
      return "This site blocks automated access — it can't be subscribed.";
    case 'unreachable':
      return "The site couldn't be reached — check the address or try again later.";
    case 'not_scrapable':
      return "This page offers no feed and no article list could be detected — it can't be subscribed.";
  }
}
```

(import `takeUntilDestroyed` from `@angular/core/rxjs-interop`; import `ScrapeFailureReason` from the models.)

In `subscribe(url, format?)` (private method): add `format?: string` param, `this.failureReason.set(null)` alongside the other resets, pass `format` to `this.api.subscribe(url, format)`, and in `next`:

```typescript
if ('subscription' in res) this.ref.close(res.subscription);
else if (res.scrapeFailureReason) {
  this.failureReason.set(res.scrapeFailureReason);
  this.searched.set(false);
} else {
  this.candidates.set(res.candidates);
  this.searched.set(true);
  this.loadPreviews(res.candidates);
}
```

`pick` becomes `pick(c: FeedCandidate): void { this.subscribe(c.url, c.format === 'scraped' ? 'scraped' : undefined); }` — template `(click)="pick(c)"`. `submit()` stays URL-only. `loadPreviews` passes `this.api.previewFeed(c.url, c.format === 'scraped' ? 'scraped' : undefined)`.

Template changes:
- Inside the candidate card, after the `.badges` div:

```html
@if (c.format === 'scraped') {
  <p class="muted scraped-hint">No feed found — generated from the page's article list.</p>
}
```

- After the candidates block / empty-state hint:

```html
@if (failureReason(); as r) {
  <div class="warn" role="alert">{{ failureText(r) }}</div>
}
```

- Footer submit condition becomes `@if (!candidates().length && !failureReason()) {`.
- Styles: add

```scss
.warn {
  color: var(--danger);
  background: var(--bg-danger);
  border-radius: var(--radius);
  padding: var(--space-2) var(--space-3);
  font-size: var(--fs-sm);
}
```

- [ ] **Step 4: Run — PASS**: `npx jest src/app/reader/add-feed` then the full frontend suite `npm test`
- [ ] **Step 5: Commit** — `git add frontend/src && git commit -m "feat(add-feed): scrape failure warnings, scraped badge hint, format-carrying subscribe/preview"`

---

### Task 14: Feed management badge

**Files:**
- Modify: `frontend/src/app/settings/feeds-section.component.ts`
- Test: `frontend/src/app/settings/feeds-section.component.spec.ts` (extend if present, else create following the add-feed spec's TestBed pattern; the component reads from `SubscriptionsStore` — mock per existing convention in neighboring settings specs)

- [ ] **Step 1: Failing test** — a subscription with `sourceFormat: 'scraped'` renders a `.badge.scraped` with text `scraped`; one with `'xml'` doesn't.
- [ ] **Step 2: Implement** — in the `.sub` row, after the status badge:

```html
@if (s.sourceFormat === 'scraped') {
  <span class="badge scraped" title="Generated from the page's article list — the site offers no feed">scraped</span>
}
```

No new styles needed (`.badge` base style exists in this component).

- [ ] **Step 3: Run — PASS** (`npx jest src/app/settings`), commit: `git add frontend/src && git commit -m "feat(settings): scraped badge on feed list"`

---

### Task 15: Playwright e2e — warning path

**Files:**
- Modify: `frontend/e2e/reader-smoke.spec.ts`

- [ ] **Step 1: Add spec** (SSRF guard blocks locally served fixture pages, so scrape-success stays functional-test territory; e2e proves the warning UX against the real stack):

```typescript
test('add-feed shows a warning for an unreachable site', async ({ page }) => {
  await signInAsAdmin(page); // existing helper + skip behavior
  await page.getByRole('button', { name: 'Add feed' }).click();
  const dialog = page.getByRole('dialog', { name: 'Add a feed' });
  await dialog
    .getByRole('textbox', { name: 'Feed or site URL' })
    .fill('https://no-such-host.sfr-e2e.example/');
  await dialog.getByRole('button', { name: 'Add' }).click();
  await expect(dialog.getByRole('alert')).toContainText("couldn't be reached", { timeout: 30_000 });
  await expect(dialog.getByRole('button', { name: 'Subscribe' })).toHaveCount(0);
  await dialog.getByRole('button', { name: 'Cancel' }).click();
});
```

- [ ] **Step 2: Run** (Docker stack up): `cd frontend && npm run e2e`
Expected: PASS (NXDOMAIN on `.example` subdomain resolves fast → `unreachable`).
- [ ] **Step 3: Commit** — `git add frontend/e2e && git commit -m "test(e2e): add-feed unreachable warning path"`

---

### Task 16: Full gates + docs sync

- [ ] Backend, in container: `vendor/bin/phpunit` (full), `composer cs`, `composer stan`, `composer md` — all green.
- [ ] Frontend: `npm test`, `npm run check` (or the repo's lint script per `frontend/package.json`), `npm run build` — all green.
- [ ] `composer e2e` (backend HTTP e2e) + `npm run e2e` (Playwright) against the running stack.
- [ ] PhpStorm inspections: `mcp lint_files` on every changed/created PHP file; block on ERROR/WARNING.
- [ ] Scan `backend/var/log/dev.log` for new deprecations/errors after exercising the flow.
- [ ] Update `README`/docs if they enumerate feed formats (grep `rss/atom` mentions in `docs/` + README).
- [ ] Commit any fixes: `git commit -am "chore: quality-gate fixes for html-feed-scraper"`

### Task 17: Adversarial review

- [ ] Dispatch a reviewer subagent over the full branch diff with specific attack prompts: malformed HTML (unclosed tags, deeply nested), pages with 3 identical URLs (dedupe under MIN_ITEMS → must fail, not pass with 1), `javascript:`/`data:` hrefs and `data:` image URLs, absurdly long titles/teasers (DB column limits: Entry title/summary lengths — verify truncation happens in ingestor or extractor), non-UTF-8 pages (`createFromString` encoding behavior), XSS through teaser/data-attributes (must be neutralized by htmlspecialchars + EntrySanitizer), scraped feed whose page later grows a native feed (sourceFormat stays scraped — acceptable? document), preview/subscribe with `format: 'scraped'` on a URL that is actually an XML feed (extractor on XML → must fail cleanly, not crash), SubscriptionService cap bypass via the new skip-discovery path (cap must still be enforced — verify Task 10 kept it).
- [ ] Fix every confirmed finding with a test; commit each as `fix(scraper): …`.

### Task 18: Finish branch

- [ ] REQUIRED SUB-SKILL: superpowers:finishing-a-development-branch — merge `feature/html-feed-scraper` into `develop` with `--no-ff` (user pre-authorized; no PR), do not delete the spec/plan docs, update memory files (`feed-reader-plan-progress`, `planned-html-feed-scraper`).

---

## Self-review notes (performed)

- **Spec coverage:** data model (T2), pipeline branch (T12), extractor layers/guards/normalization (T3–T8), teaser rules incl. data-attribute fallback (T4), discovery fallback + all three failure reasons (T9), subscribe-only-when-scrapable UX + warnings (T10, T13), preview branching with format (T11), management badge (T10 backend field, T14 UI), error handling (T9/T12), security (T4 http-only URLs, T8 htmlspecialchars, sanitizer downstream unchanged), testing incl. hostile fixtures (T5–T8), migration CI (T2), e2e (T15), quality gates (T16). Reuters-class behavior = `blocked` warning (T9/T13) — honest, per spec.
- **Known deviations from spec, both amended in the spec doc:** semantic layer covers `<article>` only (heading patterns handled by clustering); e2e covers the warning path while scrape-success lives in functional tests (SSRF guard blocks localhost fixtures).
- **Type consistency check:** `ScrapedItem` fields used by CardFields/layers/facade match; `scrapeFailureReason` string union identical across `FeedDiscoveryResult`, `SubscribeOutcome`, controller JSON, and the frontend `ScrapeFailureReason` type; `sourceFormat` naming identical in entity, JSON, DTO, and frontend.
