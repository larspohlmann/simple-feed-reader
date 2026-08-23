# Feed intro block Implementation Plan (#568)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the reader is scoped to one feed, show that feed's image, description and homepage link at the top of the entry list, above the first row.

**Architecture:** The description and site URL are already persisted; only the API and the SPA never carried them. The feed image is new end to end: a new `FeedImageExtractor` reads it per format, `ParsedFeed` carries it, a new `Feed.imageUrl` column stores it, and the backup projection follows it. The SPA renders a new `app-feed-intro` through the entry list's existing, so-far-unused `topBlock` outlet, so the sticky header keeps its height.

**Tech Stack:** PHP 8.4 / Symfony 7.4 / Doctrine ORM, PHPUnit; Angular 20 standalone components with signals, Jest, Transloco.

**Spec:** [docs/superpowers/specs/2026-08-23-568-feed-intro-block-design.md](../specs/2026-08-23-568-feed-intro-block-design.md)

## Global Constraints

- `declare(strict_types=1);` in every PHP file. PSR-12. PHPStan level max over `src` **and** `tests`.
- Every `src` file you touch must be PHPMD-clean before commit (`composer md`), not merely free of new findings.
- Comments explain **why**, never **what**. Delete commented-out code.
- `final readonly class` with constructor promotion is the house style; `final` unless designed for extension.
- Commit message format is `type(#568): summary` — the issue number is the scope, never a word scope and never trailing parens.
- Frontend: standalone components and signals, no NgModules. Component styles live in a sibling `.scss` file via `styleUrl`, never inline.
- **No hex colours and no raw `px` in `.scss` outside `src/app/theme/`.** Both fail `npm run check`.
- Image URL rule, used verbatim wherever a feed-supplied image URL is accepted: upgrade a `//host/path` protocol-relative URL to `https:`, then require the `https://` prefix, then reject anything longer than 2048 characters. Do not truncate a long URL — truncation produces a different, broken URL.
- Backup fixtures under `backend/tests/Fixtures/backup/` are a **frozen corpus**. An additive field adds nothing to either file. Do not edit them.
- After backend work, scan `backend/var/log/dev.log` for deprecations and swallowed errors.

**Command reference** (backend commands run from `backend/`, frontend from `frontend/`):

```bash
php bin/phpunit --filter SomeTest
composer check
composer md
npx jest --testPathPattern some-name
npm run check
```

---

### Task 1: `FeedImageExtractor` and `XmlHelper::childElement()`

Reads the channel-level image out of a feed document, per format, and applies the image URL rule. Nothing calls it yet — it is pure and unit-testable on its own.

**Files:**
- Create: `backend/src/Service/Parser/FeedImageExtractor.php`
- Modify: `backend/src/Service/Parser/XmlHelper.php`
- Test: `backend/tests/Service/Parser/FeedImageExtractorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `XmlHelper::childElement(\DOMElement $parent, string $localName, ?string $namespaceUri = null): ?\DOMElement`
  - `FeedImageExtractor::fromRss2Channel(\DOMElement $channel): ?string`
  - `FeedImageExtractor::fromRss1Document(\DOMDocument $document, string $rss1Namespace): ?string`
  - `FeedImageExtractor::fromAtomFeed(\DOMElement $root, string $atomNamespace): ?string`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Parser/FeedImageExtractorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\FeedImageExtractor;
use PHPUnit\Framework\TestCase;

final class FeedImageExtractorTest extends TestCase
{
    private const string RSS1_NS = 'http://purl.org/rss/1.0/';
    private const string ATOM_NS = 'http://www.w3.org/2005/Atom';

    private function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    private function rss2Channel(string $imageMarkup): \DOMElement
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration.
        $document = $this->document(/** @lang TEXT */ <<<XML
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    $imageMarkup
                </channel>
            </rss>
            XML);
        $channel = $document->getElementsByTagName('channel')->item(0);
        self::assertInstanceOf(\DOMElement::class, $channel);

        return $channel;
    }

    public function testReadsTheRss2ChannelImage(): void
    {
        $channel = $this->rss2Channel('<image><url>https://example.com/logo.png</url></image>');

        self::assertSame('https://example.com/logo.png', FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testRss2ChannelWithoutAnImageYieldsNull(): void
    {
        self::assertNull(FeedImageExtractor::fromRss2Channel($this->rss2Channel('')));
    }

    public function testRss2ImageWithoutAUrlYieldsNull(): void
    {
        $channel = $this->rss2Channel('<image><title>Logo</title></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testUpgradesAProtocolRelativeUrl(): void
    {
        $channel = $this->rss2Channel('<image><url>//cdn.example.com/logo.png</url></image>');

        self::assertSame('https://cdn.example.com/logo.png', FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testDropsAPlainHttpUrl(): void
    {
        $channel = $this->rss2Channel('<image><url>http://example.com/logo.png</url></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testDropsASiteRelativeUrl(): void
    {
        $channel = $this->rss2Channel('<image><url>/img/logo.png</url></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testDropsAUrlOverTheColumnLimit(): void
    {
        $tooLong = 'https://example.com/' . str_repeat('a', 2048) . '.png';
        $channel = $this->rss2Channel('<image><url>' . $tooLong . '</url></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testReadsTheRss1ImageFromTheRdfRoot(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns="http://purl.org/rss/1.0/">
                <channel rdf:about="https://example.com/">
                    <title>Example</title>
                    <image rdf:resource="https://example.com/logo.png"/>
                </channel>
                <image rdf:about="https://example.com/logo.png">
                    <title>Example</title>
                    <url>https://example.com/logo.png</url>
                </image>
            </rdf:RDF>
            XML);

        self::assertSame(
            'https://example.com/logo.png',
            FeedImageExtractor::fromRss1Document($document, self::RSS1_NS),
        );
    }

    public function testRss1DocumentWithoutAnImageYieldsNull(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns="http://purl.org/rss/1.0/">
                <channel rdf:about="https://example.com/"><title>Example</title></channel>
            </rdf:RDF>
            XML);

        self::assertNull(FeedImageExtractor::fromRss1Document($document, self::RSS1_NS));
    }

    public function testReadsTheAtomLogo(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
                <title>Example</title>
                <logo>https://example.com/banner.png</logo>
                <icon>https://example.com/favicon.ico</icon>
            </feed>
            XML);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        self::assertSame(
            'https://example.com/banner.png',
            FeedImageExtractor::fromAtomFeed($root, self::ATOM_NS),
        );
    }

    public function testAtomIconIsNotUsedAsTheFeedImage(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
                <title>Example</title>
                <icon>https://example.com/favicon.ico</icon>
            </feed>
            XML);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        self::assertNull(FeedImageExtractor::fromAtomFeed($root, self::ATOM_NS));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit --filter FeedImageExtractorTest`
Expected: FAIL with `Class "App\Service\Parser\FeedImageExtractor" not found`.

- [ ] **Step 3: Add `XmlHelper::childElement()`**

Add to `backend/src/Service/Parser/XmlHelper.php`, after `childText()`:

```php
    /**
     * First matching direct child element, or null when absent. When
     * $namespaceUri is null, any namespace matches. childText() cannot serve
     * here: a feed's <image> holds its address in a grandchild <url>, so the
     * element itself has to be handed back to be descended into.
     */
    public static function childElement(
        \DOMElement $parent,
        string $localName,
        ?string $namespaceUri = null,
    ): ?\DOMElement {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== $localName) {
                continue;
            }
            if ($namespaceUri !== null && $child->namespaceURI !== $namespaceUri) {
                continue;
            }

            return $child;
        }

        return null;
    }
```

- [ ] **Step 4: Write `FeedImageExtractor`**

Create `backend/src/Service/Parser/FeedImageExtractor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Finds the image a feed publishes for ITSELF — its logo or banner — as
 * opposed to the image on one of its items, which ItemImageExtractor finds.
 *
 * Atom's <logo> is read; its <icon> deliberately is not. <icon> is
 * favicon-shaped by specification and Feed::$faviconUrl already holds that
 * role, so reading both into one column would make the field mean two things.
 *
 * The returned URL is ready to persist and to put in an <img src>: see
 * usable() for the two ways a URL is rejected.
 */
final class FeedImageExtractor
{
    /** Matches the length of the column this URL is persisted into. */
    private const int URL_MAX = 2048;

    /** RSS 2.0: <channel><image><url>. */
    public static function fromRss2Channel(\DOMElement $channel): ?string
    {
        $image = XmlHelper::childElement($channel, 'image');

        return $image === null ? null : self::usable(XmlHelper::childText($image, 'url'));
    }

    /**
     * RSS 1.0: the channel only points at the image with an rdf:resource
     * attribute; the <image> element carrying the <url> is its SIBLING at the
     * RDF root. Following the reference would mean resolving rdf:about across
     * the document for a value the sibling states outright.
     */
    public static function fromRss1Document(\DOMDocument $document, string $rss1Namespace): ?string
    {
        $image = $document->getElementsByTagNameNS($rss1Namespace, 'image')->item(0);
        if (!$image instanceof \DOMElement) {
            return null;
        }

        return self::usable(XmlHelper::childText($image, 'url', $rss1Namespace));
    }

    /** Atom: <feed><logo>. */
    public static function fromAtomFeed(\DOMElement $root, string $atomNamespace): ?string
    {
        return self::usable(XmlHelper::childText($root, 'logo', $atomNamespace));
    }

    /**
     * Rejects a feed-supplied image URL in the two ways it can be unusable,
     * rather than trying to repair it. Mirrors
     * EntryIngestor::persistableImageUrl(), for the same two reasons:
     *
     * - Scheme: the reader SPA is served over https, so an http:// image is
     *   mixed-content-blocked and never renders. A `//host/path` URL is
     *   unambiguous and upgraded; a `data:` URI or a site-relative path has no
     *   scheme to upgrade and no base URL is plumbed this deep to resolve one
     *   against, so it is dropped rather than guessed at. The same check keeps
     *   a `javascript:` value out of the DOM.
     * - Length: a URL over URL_MAX is not truncated. Cutting it at exactly
     *   URL_MAX characters does not shorten a valid URL, it produces a
     *   different, broken one.
     */
    private static function usable(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $absolute = str_starts_with($url, '//') ? 'https:' . $url : $url;

        if (!str_starts_with($absolute, 'https://')) {
            return null;
        }

        return mb_strlen($absolute) > self::URL_MAX ? null : $absolute;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php bin/phpunit --filter FeedImageExtractorTest`
Expected: PASS, 11 tests.

- [ ] **Step 6: Run the gates**

Run: `composer check && composer md`
Expected: no findings.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Parser/FeedImageExtractor.php backend/src/Service/Parser/XmlHelper.php backend/tests/Service/Parser/FeedImageExtractorTest.php
git commit -m "feat(#568): read the channel-level image out of a feed document"
```

---

### Task 2: `ParsedFeed` carries the image, and every construction site answers for it

`ParsedFeed` has six construction sites. The new parameter is **required**, so each one fails to compile until it answers. One site is not a parser: `FirstFetchRecorder::newest()` rebuilds a `ParsedFeed` field by field, which is how a new subscription would silently lose its image — it gets a `withEntries()` method instead.

**Files:**
- Modify: `backend/src/Service/Parser/ParsedFeed.php`
- Modify: `backend/src/Service/Parser/Rss2Parser.php:35-40`
- Modify: `backend/src/Service/Parser/Rss1Parser.php:37-42`
- Modify: `backend/src/Service/Parser/AbstractAtomParser.php:70-75`
- Modify: `backend/src/Service/Parser/WordPressJsonParser.php:39`
- Modify: `backend/src/Service/Scraper/HtmlItemExtractor.php:47`
- Modify: `backend/src/Service/Subscription/FirstFetchRecorder.php:106-113`
- Test: `backend/tests/Service/Parser/Rss2ParserTest.php`, `Rss1ParserTest.php`, `Atom10ParserTest.php`, `backend/tests/Service/Subscription/FirstFetchRecorderTest.php`

**Interfaces:**
- Consumes: `FeedImageExtractor::fromRss2Channel()`, `::fromRss1Document()`, `::fromAtomFeed()` from Task 1.
- Produces: `ParsedFeed::$imageUrl` (`?string`, 4th constructor parameter, before `$entries`), and `ParsedFeed::withEntries(array $entries): self`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Parser/Rss2ParserTest.php`:

```php
    public function testReadsTheChannelImage(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <image><url>https://example.com/logo.png</url></image>
                    <item><title>One</title><link>https://example.com/1</link></item>
                </channel>
            </rss>
            XML;

        self::assertSame('https://example.com/logo.png', (new Rss2Parser())->parse($this->document($xml))->imageUrl);
    }

    public function testAChannelWithoutAnImageParsesToNull(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item><title>One</title><link>https://example.com/1</link></item>
                </channel>
            </rss>
            XML;

        self::assertNull((new Rss2Parser())->parse($this->document($xml))->imageUrl);
    }
```

Append to `backend/tests/Service/Parser/Rss1ParserTest.php`:

```php
    public function testReadsTheImageFromTheRdfRoot(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns="http://purl.org/rss/1.0/">
                <channel rdf:about="https://example.com/">
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <image rdf:resource="https://example.com/logo.png"/>
                </channel>
                <image rdf:about="https://example.com/logo.png">
                    <url>https://example.com/logo.png</url>
                </image>
                <item rdf:about="https://example.com/1">
                    <title>One</title>
                    <link>https://example.com/1</link>
                </item>
            </rdf:RDF>
            XML;

        self::assertSame('https://example.com/logo.png', (new Rss1Parser())->parse($this->document($xml))->imageUrl);
    }
```

Append to `backend/tests/Service/Parser/Atom10ParserTest.php`:

```php
    public function testReadsTheFeedLogo(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
                <title>Example</title>
                <logo>https://example.com/banner.png</logo>
                <entry><title>One</title><id>urn:1</id></entry>
            </feed>
            XML;

        self::assertSame('https://example.com/banner.png', (new Atom10Parser())->parse($this->document($xml))->imageUrl);
    }
```

Append to `backend/tests/Service/Subscription/FirstFetchRecorderTest.php` (this is the regression `withEntries()` exists to prevent — the entry cap must not drop the feed's own metadata):

```php
    public function testCappingTheEntryListKeepsTheFeedImage(): void
    {
        $document = new ParsedFeed(
            'Example',
            'https://example.com/',
            'Example feed',
            'https://example.com/logo.png',
            [],
        );

        $capped = $document->withEntries([]);

        self::assertSame('https://example.com/logo.png', $capped->imageUrl);
        self::assertSame('Example', $capped->title);
        self::assertSame('https://example.com/', $capped->siteUrl);
        self::assertSame('Example feed', $capped->description);
    }
```

Add `use App\Service\Parser\ParsedFeed;` to that test file if it is not already imported.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit --filter 'Rss2ParserTest|Rss1ParserTest|Atom10ParserTest|FirstFetchRecorderTest'`
Expected: FAIL — `ParsedFeed::$imageUrl` and `ParsedFeed::withEntries()` do not exist.

- [ ] **Step 3: Add the field and the copy method to `ParsedFeed`**

Replace the body of `backend/src/Service/Parser/ParsedFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

final readonly class ParsedFeed
{
    /**
     * @param list<ParsedEntry> $entries
     */
    public function __construct(
        public ?string $title,
        public ?string $siteUrl,
        public ?string $description,
        public ?string $imageUrl,
        public array $entries,
    ) {
    }

    /**
     * The same feed with a different entry list. Callers that narrow the
     * entries — FirstFetchRecorder caps them on subscribe — used to rebuild
     * the object field by field, which quietly dropped every field added
     * afterwards. Copying here means a new field is carried by construction.
     *
     * @param list<ParsedEntry> $entries
     */
    public function withEntries(array $entries): self
    {
        return new self($this->title, $this->siteUrl, $this->description, $this->imageUrl, $entries);
    }
}
```

- [ ] **Step 4: Answer for the new parameter at all six construction sites**

`backend/src/Service/Parser/Rss2Parser.php` — replace the `return new ParsedFeed(...)`:

```php
        return new ParsedFeed(
            PlainText::from(XmlHelper::childText($channel, 'title')),
            XmlHelper::childText($channel, 'link'),
            XmlHelper::childText($channel, 'description'),
            FeedImageExtractor::fromRss2Channel($channel),
            $entries,
        );
```

`backend/src/Service/Parser/Rss1Parser.php` — replace the `return new ParsedFeed(...)`:

```php
        return new ParsedFeed(
            PlainText::from(XmlHelper::childText($channel, 'title', self::RSS1_NS)),
            XmlHelper::childText($channel, 'link', self::RSS1_NS),
            XmlHelper::childText($channel, 'description', self::RSS1_NS),
            FeedImageExtractor::fromRss1Document($document, self::RSS1_NS),
            $entries,
        );
```

`backend/src/Service/Parser/AbstractAtomParser.php` — replace the `return new ParsedFeed(...)`:

```php
        return new ParsedFeed(
            PlainText::from($title),
            $this->alternateLink($root, $ns),
            XmlHelper::childText($root, $this->descriptionElement(), $ns),
            FeedImageExtractor::fromAtomFeed($root, $ns),
            $entries,
        );
```

`backend/src/Service/Parser/WordPressJsonParser.php` line 39 — the posts endpoint carries no channel metadata at all:

```php
        return new ParsedFeed(null, null, null, null, $entries);
```

`backend/src/Service/Scraper/HtmlItemExtractor.php` line 47 — a scraped page has no feed image. `og:image` is the page's picture, not the site's mark, so it is deliberately not used:

```php
        // No feed image: og:image is the page's picture, not the site's mark,
        // so guessing with it would put an article photo in the feed header.
        return new ParsedFeed(
            $this->feedTitle($doc),
            $baseUrl,
            $this->metaDescription($doc),
            null,
            $entries,
        );
```

`backend/src/Service/Subscription/FirstFetchRecorder.php` — replace the `return new ParsedFeed(...)` inside `newest()`:

```php
        return $document->withEntries($newest);
```

Each of the three parsers needs `use App\Service\Parser\FeedImageExtractor;` only if it is outside that namespace — `Rss2Parser`, `Rss1Parser` and `AbstractAtomParser` are all in `App\Service\Parser`, so no import is required. `HtmlItemExtractor` is in `App\Service\Scraper` and already imports `ParsedFeed`; it needs no new import because it passes a literal `null`.

- [ ] **Step 5: Run the full backend suite**

Run: `php bin/phpunit`
Expected: PASS. Any other construction site the compiler finds is a site this step missed — fix it the same way.

- [ ] **Step 6: Run the gates**

Run: `composer check && composer md`
Expected: no findings. `composer tramp` runs inside `composer check`; `withEntries()` shortens a chain rather than lengthening one, so it must not report.

- [ ] **Step 7: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#568): carry the feed image through ParsedFeed"
```

---

### Task 3: The `Feed.imageUrl` column and its migration

**Files:**
- Modify: `backend/src/Entity/Feed.php:33-35` (beside `faviconUrl`)
- Create: `backend/migrations/Version20260823120000.php`
- Test: `backend/tests/Entity/FeedTest.php` (create if absent)

**Interfaces:**
- Consumes: nothing.
- Produces: `Feed::getImageUrl(): ?string`, `Feed::setImageUrl(?string $imageUrl): void`, column `feed.image_url`.

- [ ] **Step 1: Write the failing test**

Create or append `backend/tests/Entity/FeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    public function testAFeedStartsWithNoImage(): void
    {
        self::assertNull((new Feed('https://example.com/feed.xml'))->getImageUrl());
    }

    public function testTheImageUrlRoundTrips(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setImageUrl('https://example.com/logo.png');

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit --filter FeedTest`
Expected: FAIL with `Call to undefined method App\Entity\Feed::getImageUrl()`.

- [ ] **Step 3: Add the mapped field and its accessors**

In `backend/src/Entity/Feed.php`, directly after the `$faviconUrl` property:

```php
    /**
     * The image the feed publishes for ITSELF — its logo or banner, from RSS
     * <channel><image> or Atom <logo>. Not $faviconUrl: that one is the site's
     * icon, resolved by RefreshRunner from the page rather than read from the
     * feed document.
     */
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $imageUrl = null;
```

And after `setFaviconUrl()`:

```php
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }
```

- [ ] **Step 4: Write the migration**

Create `backend/migrations/Version20260823120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds feed.image_url, the image a feed publishes for itself (#568).
 *
 * PLATFORM-AWARE DDL: a `doctrine:migrations:diff` on a SQLite dev box emits
 * SQLite-only DDL MySQL cannot parse, and the suite would not catch it because
 * tests build their schema from ORM metadata rather than by executing this
 * chain. Only CI's migrate-from-empty leg runs this.
 *
 * ADDITIVE ONLY. One nullable column: every feed that exists today predates
 * the field and correctly holds NULL until its next successful fetch.
 */
final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed.image_url for the image a feed publishes for itself (#568)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        // Per-column idempotence for a database baselined from
        // doctrine:schema:create, where ORM metadata already produced the column.
        if (!$schema->hasTable('feed') || $schema->getTable('feed')->hasColumn('image_url')) {
            return;
        }

        // `ADD image_url` (MySQL) vs `ADD COLUMN image_url` (SQLite).
        $verb = $mysql ? 'ADD' : 'ADD COLUMN';
        $this->addSql(\sprintf('ALTER TABLE feed %s image_url VARCHAR(2048) DEFAULT NULL', $verb));
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('feed') && $schema->getTable('feed')->hasColumn('image_url')) {
            $this->addSql('ALTER TABLE feed DROP COLUMN image_url');
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php bin/phpunit --filter FeedTest`
Expected: PASS.

- [ ] **Step 6: Verify the migration on a scratch database, not the dev database**

**Never run this against the dev database** — a from-empty check destroyed a real account once (#321). Use a throwaway SQLite file:

```bash
DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-568.db" php bin/console doctrine:migrations:migrate --no-interaction --env=dev
```

Then:

```bash
DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-568.db" php bin/console doctrine:schema:validate --env=dev
```

Expected: the migration chain runs clean from empty, and the mapping validates. Delete `backend/var/scratch-568.db` afterwards.

- [ ] **Step 7: Run the gates**

Run: `composer check && composer md`
Expected: no findings. `Feed` is PHPMD-clean today; PHPMD's `TooManyMethods` ignores accessors, so the two new methods must not change that.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Feed.php backend/migrations/Version20260823120000.php backend/tests/Entity/FeedTest.php
git commit -m "feat(#568): add feed.image_url"
```

---

### Task 4: `EntryIngestor` persists the feed image

**Files:**
- Modify: `backend/src/Service/Ingest/EntryIngestor.php:131-142` (`updateFeedMetadata`)
- Test: `backend/tests/Service/Ingest/EntryIngestorTest.php`

**Interfaces:**
- Consumes: `ParsedFeed::$imageUrl` (Task 2), `Feed::setImageUrl()` (Task 3).
- Produces: nothing new.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Ingest/EntryIngestorTest.php`. Match the file's existing idiom for building a `Feed` and invoking the ingestor; the two behaviours to assert are:

```php
    public function testAFetchStoresTheFeedImage(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $parsed = new ParsedFeed('Example', 'https://example.com/', 'Desc', 'https://example.com/logo.png', []);

        $this->ingestor()->ingest($feed, $parsed);

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }

    public function testALaterFetchWithoutAnImageKeepsTheStoredOne(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setImageUrl('https://example.com/logo.png');
        $parsed = new ParsedFeed('Example', 'https://example.com/', 'Desc', null, []);

        $this->ingestor()->ingest($feed, $parsed);

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }
```

Read the existing tests in that file first and reuse whatever helper they use to obtain the ingestor and to call it — the two method names above (`ingestor()`, `ingest()`) are placeholders for that file's real idiom and must be replaced with it. The **assertions** are the contract and must not change.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit --filter EntryIngestorTest`
Expected: FAIL — the first test asserts `null`.

- [ ] **Step 3: Persist it**

In `updateFeedMetadata()` in `backend/src/Service/Ingest/EntryIngestor.php`, after the `description` branch:

```php
        // Guarded like the fields above: a feed that stops sending its <image>
        // on one fetch must not erase the logo the reader already shows.
        // FeedImageExtractor has already applied the scheme and length rules,
        // so no truncation belongs here.
        if ($parsed->imageUrl !== null) {
            $feed->setImageUrl($parsed->imageUrl);
        }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit --filter EntryIngestorTest`
Expected: PASS.

- [ ] **Step 5: Run the gates**

Run: `composer check && composer md`
Expected: no findings.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Ingest/EntryIngestor.php backend/tests/Service/Ingest/EntryIngestorTest.php
git commit -m "feat(#568): persist the feed image on every fetch"
```

---

### Task 5: Carry the feed image through the backup

`feed` is a backed-up table, so the #556 drift guard fails the suite until the projection follows the new column in all five places. **Start by running the guard and reading what it says** — it names the field and the file.

**Files:**
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:233-243` (`feedLine`)
- Modify: `backend/src/Service/Backup/Dto/FeedLine.php`
- Modify: `backend/src/Service/Backup/RestoreLoadPass.php:131-148` (`loadFeed`)
- Modify: `backend/tests/Support/BackupFieldDeclarations.php:67-71` (the `Feed::class` block)
- Modify: `backend/tests/Support/FullyPopulatedAccount.php:99-109` (`feedFor`)
- Modify: `backend/tests/Service/Backup/GoldenBackupRestoreTest.php` (one docblock sentence only)
- Modify: `docs/backup.md:137`
- **Do not touch** `backend/tests/Fixtures/backup/*.ndjson`.

**Interfaces:**
- Consumes: `Feed::getImageUrl()`, `Feed::setImageUrl()` (Task 3).
- Produces: the `imageUrl` key on the backup file's `feed` line; `FeedLine::$imageUrl`.

- [ ] **Step 1: Run the drift guard and watch it fail**

Run: `php bin/phpunit --filter 'BackupSchemaCoverageTest|AccountRestorerTest|GoldenBackupRestoreTest'`
Expected: FAIL. `BackupSchemaCoverageTest` reports that `Feed::$imageUrl` has no declaration. This failure is the guard doing its job — record what it says before fixing it.

- [ ] **Step 2: Write the export side**

`backend/src/Service/Backup/AccountBackupExporter.php`, in `feedLine()`, after `'faviconUrl'`:

```php
            'imageUrl' => $feed->getImageUrl(),
```

- [ ] **Step 3: Write the read side**

`backend/src/Service/Backup/Dto/FeedLine.php` — add the promoted property after `$faviconUrl`:

```php
        public ?string $faviconUrl,
        public ?string $imageUrl,
        public string $sourceFormat,
```

and in `fromLine()`, after the `faviconUrl` line:

```php
            imageUrl: LineField::stringOrNull($line, 'imageUrl'),
```

`LineField::stringOrNull()` returns null for an absent key, which is exactly what a backup written before this change must produce.

`backend/src/Service/Backup/RestoreLoadPass.php`, in `loadFeed()`, after `setFaviconUrl`:

```php
        $feed->setImageUrl($line->imageUrl);
```

- [ ] **Step 4: Declare the field**

`backend/tests/Support/BackupFieldDeclarations.php`, in the `Feed::class` block, extend it to:

```php
        Feed::class => [
            'url' => 'url', 'siteUrl' => 'siteUrl', 'title' => 'title',
            'description' => 'description', 'faviconUrl' => 'faviconUrl',
            'imageUrl' => 'imageUrl',
            'sourceFormat' => 'sourceFormat',
        ],
```

- [ ] **Step 5: Populate the round-trip fixture**

The round-trip guard asserts every `BACKED_UP` value is non-null in the source account, so `FullyPopulatedAccount` must set it. In `backend/tests/Support/FullyPopulatedAccount.php`, in `feedFor()`, after the `setFaviconUrl` line:

```php
        $feed->setImageUrl('https://populated.example/logo.png');
```

- [ ] **Step 6: Keep the frozen-corpus docblock true**

The corpus is frozen: an additive field adds nothing to either fixture, and `oldest-supported.ndjson` lacking `imageUrl` **is** the test that an old file still restores. That makes one sentence in `GoldenBackupRestoreTest`'s docblock stale. Amend only that sentence:

Replace:

```
 * current.ndjson carries every field the format holds
 * today.
```

with:

```
 * current.ndjson carries every field the format held when the corpus was
 * frozen. It is not extended when a field is added — freezing is the point,
 * and the standing rule below says so.
```

- [ ] **Step 7: Update the user-facing documentation**

`docs/backup.md` line 137 — extend the `feed` row:

```
| `feed` | Each feed you subscribe to: `url`, `siteUrl`, `title`, `description`, `faviconUrl`, `imageUrl` and `sourceFormat`. |
```

- [ ] **Step 8: Run the guards to verify they pass**

Run: `php bin/phpunit --filter 'BackupSchemaCoverageTest|AccountRestorerTest|GoldenBackupRestoreTest'`
Expected: PASS. `GoldenBackupRestoreTest` still restores `oldest-supported.ndjson`, whose feed line carries no `imageUrl`, as null.

- [ ] **Step 9: Run the whole suite and the gates**

Run: `php bin/phpunit && composer check && composer md`
Expected: PASS, no findings.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Backup backend/tests/Support backend/tests/Service/Backup docs/backup.md
git commit -m "feat(#568): carry the feed image through a backup"
```

---

### Task 6: `SubscriptionJson` sends the description and the image

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php`
- Test: `backend/tests/Http/SubscriptionJsonTest.php` (create if absent)

**Interfaces:**
- Consumes: `Feed::getImageUrl()` (Task 3), `PlainText::fromHtmlBlocks()` (existing, `App\Service\Text\PlainText`).
- Produces: the `description` and `imageUrl` keys on the subscription payload. `siteUrl` was already sent.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Http/SubscriptionJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\SubscriptionJson;
use PHPUnit\Framework\TestCase;

final class SubscriptionJsonTest extends TestCase
{
    private function subscriptionTo(Feed $feed): Subscription
    {
        return new Subscription(new User(), $feed);
    }

    public function testFlattensAnHtmlDescriptionToPlainText(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setDescription('<p>One</p><p>Two</p>');

        self::assertSame('One Two', SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testCapsALongDescription(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setDescription(str_repeat('a', 400));

        $description = SubscriptionJson::one($this->subscriptionTo($feed))['description'];

        self::assertIsString($description);
        self::assertSame(300, mb_strlen($description));
    }

    public function testAMissingDescriptionStaysNull(): void
    {
        $feed = new Feed('https://example.com/feed.xml');

        self::assertNull(SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testADescriptionOfOnlyMarkupBecomesNull(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setDescription('<p></p>');

        self::assertNull(SubscriptionJson::one($this->subscriptionTo($feed))['description']);
    }

    public function testSendsTheFeedImage(): void
    {
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setImageUrl('https://example.com/logo.png');

        self::assertSame('https://example.com/logo.png', SubscriptionJson::one($this->subscriptionTo($feed))['imageUrl']);
    }
}
```

Check `App\Entity\Subscription`'s real constructor before running this and adjust `subscriptionTo()` to match it; the assertions are the contract and must not change.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit --filter SubscriptionJsonTest`
Expected: FAIL — the `description` and `imageUrl` keys are absent.

- [ ] **Step 3: Add the two keys**

In `backend/src/Http/SubscriptionJson.php`, add the constant and the private helper, extend the return-shape docblock with `description: string|null, imageUrl: string|null,`, and add the keys after `'siteUrl'`:

```php
    /**
     * The sidebar bootstrap returns every subscription in one payload, so a
     * feed that ships a whole About page as its <description> would weigh the
     * whole reader down for a block that shows a few lines.
     */
    private const int DESCRIPTION_MAX = 300;
```

```php
            'description' => self::description($feed),
            'imageUrl' => $feed->getImageUrl(),
```

```php
    /**
     * Feed descriptions routinely carry markup. Reducing to plain text at the
     * boundary keeps the SPA out of any sanitiser decision: what it receives
     * is text, and it renders it as text.
     */
    private static function description(Feed $feed): ?string
    {
        $text = PlainText::fromHtmlBlocks($feed->getDescription());

        return $text === null ? null : mb_substr($text, 0, self::DESCRIPTION_MAX);
    }
```

Add `use App\Entity\Feed;` and `use App\Service\Text\PlainText;` to the imports.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit --filter SubscriptionJsonTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the whole suite and the gates**

Run: `php bin/phpunit && composer check && composer md`
Expected: PASS, no findings. Other tests asserting the subscription payload shape may need the two new keys added to their expectations — fix those expectations, do not remove the keys.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Http/SubscriptionJson.php backend/tests/Http/SubscriptionJsonTest.php backend/tests
git commit -m "feat(#568): send the feed description and image to the reader"
```

---

### Task 7: The `app-feed-intro` component

A presentational component only. It is wired into the reader in Task 8, so it is reviewable on its own.

**Files:**
- Modify: `frontend/src/app/reader/models.ts:22-44` (`SubscriptionDto`)
- Create: `frontend/src/app/reader/feed-intro/feed-intro.component.ts`
- Create: `frontend/src/app/reader/feed-intro/feed-intro.component.html`
- Create: `frontend/src/app/reader/feed-intro/feed-intro.component.scss`
- Create: `frontend/src/app/reader/feed-intro/feed-intro.component.spec.ts`
- Modify: `frontend/src/app/theme/tokens.scss` (structural sizing block)
- Modify: `docs/design-language.md:50-59` (structural sizing table)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: the `description` and `imageUrl` keys from Task 6; `IconComponent` from `../../shared/icon/icon.component`.
- Produces: `FeedIntroComponent`, selector `app-feed-intro`, inputs
  `description = input<string | null>(null)`,
  `imageUrl = input<string | null>(null)`,
  `siteUrl = input<string | null>(null)`,
  and `readonly hasContent: Signal<boolean>`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/reader/feed-intro/feed-intro.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { getTranslocoModule } from '../../testing/transloco-testing';
import { FeedIntroComponent } from './feed-intro.component';

describe('FeedIntroComponent', () => {
  let fixture: ComponentFixture<FeedIntroComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [FeedIntroComponent, getTranslocoModule()],
    }).compileComponents();
    fixture = TestBed.createComponent(FeedIntroComponent);
  });

  function render(values: { description?: string; imageUrl?: string; siteUrl?: string }): HTMLElement {
    fixture.componentRef.setInput('description', values.description ?? null);
    fixture.componentRef.setInput('imageUrl', values.imageUrl ?? null);
    fixture.componentRef.setInput('siteUrl', values.siteUrl ?? null);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders the description', () => {
    expect(render({ description: 'A feed about things.' }).textContent).toContain('A feed about things.');
  });

  it('renders the image', () => {
    const img = render({ imageUrl: 'https://example.com/logo.png' }).querySelector('img');
    expect(img?.getAttribute('src')).toBe('https://example.com/logo.png');
  });

  it('renders the homepage link in a new tab, without leaking the referrer', () => {
    const link = render({ siteUrl: 'https://example.com/' }).querySelector('a');
    expect(link?.getAttribute('href')).toBe('https://example.com/');
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('omits each part whose value is null', () => {
    const host = render({ description: 'Only text.' });
    expect(host.querySelector('img')).toBeNull();
    expect(host.querySelector('a')).toBeNull();
  });

  it('reports no content when every value is null', () => {
    render({});
    expect(fixture.componentInstance.hasContent()).toBe(false);
  });

  it('hides a broken image instead of leaving a broken-image box', () => {
    const host = render({ imageUrl: 'https://example.com/dead.png' });
    host.querySelector('img')?.dispatchEvent(new Event('error'));
    fixture.detectChanges();
    expect(host.querySelector('img')).toBeNull();
  });
});
```

Check how sibling specs in `frontend/src/app/reader/` obtain a Transloco testing module and copy that import exactly; the import path above is a placeholder for whatever they use.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx jest --testPathPattern feed-intro`
Expected: FAIL — the component file does not exist.

- [ ] **Step 3: Add the size token**

In `frontend/src/app/theme/tokens.scss`, in the structural sizing block beside `--control-h` and `--bar-h`:

```scss
  --feed-logo-max-h: 56px;
```

In `docs/design-language.md`, add a row to the Structural sizing table:

```
| `--feed-logo-max-h` | `56px` | the ceiling for a feed's own logo in `<app-feed-intro>`; feed logos range from 88×31 buttons to full-width banners, so the block caps height and lets width follow |
```

- [ ] **Step 4: Add the translations**

In `frontend/public/i18n/en.json`, inside the `reader` object:

```json
    "feedHomepage": "Website",
```

In `frontend/public/i18n/de.json`, inside the `reader` object:

```json
    "feedHomepage": "Website",
```

- [ ] **Step 5: Write the component**

`frontend/src/app/reader/feed-intro/feed-intro.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';

/**
 * What a feed says about itself: its own image, its description and a link to
 * its site. Shown once, at the top of the entry list, when the reader is
 * scoped to a single feed (#568).
 *
 * It is rendered through the list's `topBlock` outlet rather than inside the
 * list header, so it scrolls away with the rows. The header is sticky and
 * collapses on scroll; making it taller moves the rows under the finger (#419).
 */
@Component({
  selector: 'app-feed-intro',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './feed-intro.component.html',
  styleUrl: './feed-intro.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedIntroComponent {
  readonly description = input<string | null>(null);
  /** Plain https URL, already flattened and capped by the API. */
  readonly imageUrl = input<string | null>(null);
  readonly siteUrl = input<string | null>(null);

  /** A dead logo URL must leave no broken-image box, so the <img> is dropped
   *  rather than left to render its own failure. Mirrors app-favicon. */
  protected readonly broken = signal(false);
  protected readonly src = computed(() => (this.broken() ? null : this.imageUrl()));

  /** Whether there is anything at all to show. The caller uses this to leave
   *  the block out entirely rather than render an empty box above the rows. */
  readonly hasContent = computed(
    () => this.description() !== null || this.imageUrl() !== null || this.siteUrl() !== null,
  );
}
```

`frontend/src/app/reader/feed-intro/feed-intro.component.html`:

```html
<div class="feed-intro">
  @if (src(); as url) {
    <!-- alt is empty on purpose: the feed's name is already the list heading
         directly above, so naming it again only repeats it to a screen reader. -->
    <img
      class="logo"
      [src]="url"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="broken.set(true)"
    />
  }
  <div class="text">
    @if (description(); as text) {
      <p class="description">{{ text }}</p>
    }
    @if (siteUrl(); as site) {
      <a class="homepage" [href]="site" target="_blank" rel="noopener noreferrer">
        {{ 'reader.feedHomepage' | transloco }} <app-icon name="open_in_new" size="text" />
      </a>
    }
  </div>
</div>
```

`frontend/src/app/reader/feed-intro/feed-intro.component.scss`:

```scss
.feed-intro {
  display: flex;
  align-items: flex-start;
  gap: var(--space-4);
  padding: var(--space-4);
}

.logo {
  flex: none;
  max-height: var(--feed-logo-max-h);
  max-width: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
  border-radius: var(--radius-sm);
}

.text {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  min-width: 0;
}

.description {
  margin: 0;
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

.homepage {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  align-self: flex-start;
  font-size: var(--fs-sm);
}

/* Stacked on a phone: a 56px logo beside text leaves the description a column
   too narrow to read. Uses the shared breakpoint mixin, never a literal. */
@include mq-narrow {
  .feed-intro {
    flex-direction: column;
  }
}
```

Open `frontend/src/app/theme/_breakpoints.scss` and use whatever mixin or media-query helper it actually exports; `mq-narrow` above is a placeholder for that name, and the file must be `@use`d the way sibling `.scss` files in `reader/` do it. A media-query literal fails `npm run check`.

- [ ] **Step 6: Extend the DTO**

In `frontend/src/app/reader/models.ts`, inside `SubscriptionDto`, after `siteUrl`:

```ts
  /** The feed's own description, already plain text and capped by the API. */
  description: string | null;
  /** The image the feed publishes for itself (its logo or banner), https-only,
   *  or null. Not `faviconUrl` — that is the site's icon. */
  imageUrl: string | null;
```

Every fixture in the frontend test suite that builds a `SubscriptionDto` now needs the two keys. Let TypeScript find them: run `npx tsc --noEmit -p tsconfig.json` and fix each error by adding `description: null, imageUrl: null`.

- [ ] **Step 7: Run the test to verify it passes**

Run: `npx jest --testPathPattern feed-intro`
Expected: PASS, 6 tests.

- [ ] **Step 8: Run the gate**

Run: `npm run check`
Expected: PASS. Stylelint must report no hex colour, no raw `px` and no media-query literal in the new `.scss`.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader/feed-intro frontend/src/app/reader/models.ts frontend/src/app/theme/tokens.scss frontend/public/i18n docs/design-language.md frontend/src
git commit -m "feat(#568): add the feed intro block component"
```

---

### Task 8: Show the block at the top of the feed's entry list

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.html:93-121` and `:140-168` (both `<app-entry-list>` instances)
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (imports)
- Test: `frontend/src/app/reader/reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: `FeedIntroComponent` (Task 7); `ReaderShellComponent.selectedSubscription()` (existing, `reader-shell.component.ts:288`); the entry list's `topBlock` input (existing, `entry-list.component.ts:179`).
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/app/reader/reader-shell.component.spec.ts`, following that file's existing idiom for mounting the shell with a selection and a subscription list:

```ts
  it('shows the feed intro at the top of the list for a single-feed selection', () => {
    const host = mountWithSubscriptionSelected({
      description: 'A feed about things.',
      imageUrl: 'https://example.com/logo.png',
      siteUrl: 'https://example.com/',
    });

    expect(host.querySelector('app-feed-intro')).not.toBeNull();
  });

  it('shows no feed intro for the aggregated and saved views', () => {
    for (const view of ['all', 'tag', 'search', 'favorites', 'kept', 'viewed', 'for-you']) {
      const host = mountWithSelectionKind(view);
      expect(host.querySelector('app-feed-intro')).toBeNull();
    }
  });

  it('shows no feed intro for a feed that has none of the three values', () => {
    const host = mountWithSubscriptionSelected({
      description: null,
      imageUrl: null,
      siteUrl: null,
    });

    expect(host.querySelector('app-feed-intro')).toBeNull();
  });
```

`mountWithSubscriptionSelected` and `mountWithSelectionKind` are placeholders: read the spec file and reuse or extend its existing mounting helpers. The three assertions are the contract and must not change.

- [ ] **Step 2: Run the test to verify it fails**

Run: `npx jest --testPathPattern reader-shell`
Expected: FAIL — no `app-feed-intro` is rendered.

- [ ] **Step 3: Declare the template and pass it to both lists**

In `frontend/src/app/reader/reader-shell.component.html`, add the template once, beside the other `<ng-template>` outlets the shell already declares (`forYouActions`, `listEditAction`):

```html
<!-- Rendered through the list's `topBlock` outlet, so it sits above the first
     row and scrolls away with them instead of growing the sticky header (#321,
     #419). Declared once and handed to both list instances. -->
<ng-template #feedIntro>
  @if (selectedSubscription(); as sub) {
    <app-feed-intro
      [description]="sub.description"
      [imageUrl]="sub.imageUrl"
      [siteUrl]="sub.siteUrl"
    />
  }
</ng-template>
```

`selectedSubscription()` is already null for every non-subscription selection, so the `@if` is the whole "only for a single feed" rule.

Add `[topBlock]="feedIntro"` to **both** `<app-entry-list>` instances, beside the existing `[headerActions]` line.

- [ ] **Step 4: Suppress the empty block**

An entirely empty block must not paint padding above the rows. `FeedIntroComponent.hasContent()` cannot gate its own host, so gate it in the shell. Add a computed to `frontend/src/app/reader/reader-shell.component.ts`, beside `selectedSubscription`:

```ts
  /** The selected feed, but only when it has something to introduce itself
   *  with. A feed with no description, image or site URL renders no block at
   *  all rather than an empty box above the first row. */
  readonly feedIntroSubscription = computed(() => {
    const sub = this.selectedSubscription();
    if (sub === null) return null;
    return sub.description !== null || sub.imageUrl !== null || sub.siteUrl !== null ? sub : null;
  });
```

Then use `feedIntroSubscription()` in the template's `@if` instead of `selectedSubscription()`.

Add `FeedIntroComponent` to the component's `imports` array, and its import statement:

```ts
import { FeedIntroComponent } from './feed-intro/feed-intro.component';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `npx jest --testPathPattern reader-shell`
Expected: PASS.

- [ ] **Step 6: Run both gates**

Run: `npm run check`
Expected: PASS.

Run from `backend/`: `php bin/phpunit && composer check && composer md`
Expected: PASS, no findings.

- [ ] **Step 7: See it in the running app**

Bring the Docker stack up, apply the migration to the live database (a mid-branch migration verified only on a scratch database will 500 the running MySQL), restart the worker so it loads the new code, then select a feed that has a description:

```bash
docker compose up -d && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose restart worker
```

Refresh one feed so `EntryIngestor` fills `image_url`, then open the reader and select that feed. Confirm the block sits above the first row, scrolls away with the list, and that the header does not grow. Scan `backend/var/log/dev.log` for deprecations.

- [ ] **Step 8: Run the mutation gate**

Run from `backend/`: `composer infection:diff`
Expected: at or above the `minMsi` in `infection.json5`. Escaped mutants point at an assertion this plan's tests do not make — add the assertion, never lower the threshold.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/reader
git commit -m "feat(#568): show the feed intro above the feed's entries"
```

---

## Self-Review

**Spec coverage.** Every section of the spec maps to a task: image sources and the https guard → Task 1; `ParsedFeed` plus `withEntries()` and all six construction sites → Task 2; the column and migration → Task 3; `EntryIngestor` → Task 4; the five backup files → Task 5; `SubscriptionJson` → Task 6; the DTO, component, token and translations → Task 7; the `topBlock` wiring and the layout rules → Task 8. The spec's non-goals appear as explicit code comments (no `og:image` in Task 2, no `<icon>` in Task 1) so a later reader finds the reason at the site of the decision. The spec's "no backfill" needs no task by definition, and Task 3's migration docblock records it.

**Type consistency.** `imageUrl` is the name in every layer: `ParsedFeed::$imageUrl`, `Feed::$imageUrl` / `getImageUrl()` / `setImageUrl()`, the backup line key `imageUrl`, `FeedLine::$imageUrl`, the JSON key `imageUrl`, `SubscriptionDto.imageUrl`, and the component input `imageUrl`. The column is `feed.image_url`, which is the Doctrine default for that property name, so no explicit column name is needed. `FeedImageExtractor` returns `?string` everywhere, and every consumer takes `?string`.

**Placeholders.** Four steps name a helper whose real form must be read out of the existing file first — the ingestor's test idiom (Task 4), `Subscription`'s constructor (Task 6), the Transloco testing import and the breakpoint mixin (Task 7), and the shell spec's mounting helpers (Task 8). Each says so explicitly and states that the assertions are the contract. These are deliberate: inventing a name that the file does not export would be worse than naming the lookup.
