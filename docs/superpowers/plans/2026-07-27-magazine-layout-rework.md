# Magazine Layout Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist the image every feed already publishes, then replace the magazine planner's fixed four-block cadence with an authored template rhythm over eight block types.

**Architecture:** Three dependency stages in one branch. (A) Backend — a `ParsedImage` value object carrying url + optional dimensions, largest-variant selection in `ItemImageExtractor`, three nullable columns on `Entry`, persistence plus an opportunistic backfill in `EntryIngestor`, and a snippet service that strips image markup. (B) Frontend blocks — five new components and an adaptive-aspect hero, all reading the new DTO fields. (C) Planner — a template library as data, plus an engine that assigns entries to template slots in chronological order with transitive demotion, a height budget, a page-indexed shuffle and seeded slot jitter.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM + Migrations, PHPUnit; Angular 20 standalone components with signal inputs, SCSS, Jest.

**Spec:** [docs/superpowers/specs/2026-07-27-magazine-layout-rework-design.md](../specs/2026-07-27-magazine-layout-rework-design.md)

**Issue:** [#148](https://github.com/larspohlmann/simple-feed-reader/issues/148)

**Branch:** `feature/148-magazine-layout-rework` (already created)

---

## Working agreements

- Backend commands run from `backend/`, frontend commands from `frontend/`.
- **Backend gate before every backend commit:** `composer cs && composer stan && composer md && php bin/phpunit`. `composer stan` needs a warm cache — run `bin/console cache:warmup` first if it complains.
- **Every `src` file you touch must be PHPMD-clean**, not merely free of *new* findings. Fix the design the metric points at; never tune a threshold.
- **Frontend gate before every frontend commit:** `npm run check` (ESLint + Prettier + Stylelint + Jest).
- **No hex colours in `.scss` outside `src/app/theme/`**, and no ad-hoc `px` spacing or media-query literals — all three fail `npm run check`. Use `var(--space-N)` and `bp.$bp-sm/md/lg`.
- **Component styles live in a sibling `.scss` file** via `styleUrl`, never inline in the `.ts` — Stylelint has no TS syntax installed, so inline styles are silently unlinted.
- Commit after every task. This branch merges to `develop` via PR; never commit to `develop` directly.
- Run `mcp__phpstorm__lint_files` on changed PHP; block on ERROR and WARNING.
- Scan `backend/var/log/dev.log` after backend work — deprecations and swallowed errors surface there.
- Concurrent Claude sessions share this checkout. Check `git status` before any `checkout`, `reset` or `stash`.
- Where a task says "expected: N", the number is from the state at planning time. A different count is information, not necessarily a bug — investigate before proceeding.

---

## File structure

**Created:**

| Path | Responsibility |
|---|---|
| `backend/src/Service/Parser/ParsedImage.php` | Immutable url + nullable width/height |
| `backend/src/Service/EntrySnippet.php` | Plain-text snippet with image markup stripped; null for junk |
| `backend/migrations/Version20260727120000.php` | Three nullable columns on `entry` |
| `backend/tests/Service/Parser/ItemImageExtractorTest.php` | Variant selection and dimension capture |
| `backend/tests/Service/EntrySnippetTest.php` | Snippet derivation |
| `frontend/src/app/reader/magazine/magazine-block.ts` | The `MagazineBlock` union, one place |
| `frontend/src/app/reader/magazine/magazine-templates.ts` | Template library as reviewable data |
| `frontend/src/app/reader/magazine/entry-split.component.{ts,html,scss,spec.ts}` | 38% side image |
| `frontend/src/app/reader/magazine/entry-wide.component.{ts,html,scss,spec.ts}` | Full-width 3:1 band |
| `frontend/src/app/reader/magazine/entry-thumb.component.{ts,html,scss,spec.ts}` | 88px thumbnail row |
| `frontend/src/app/reader/magazine/entry-quote.component.{ts,html,scss,spec.ts}` | Serif pull-quote, image suppressed |
| `frontend/src/app/reader/magazine/entry-kicker.component.{ts,html,scss,spec.ts}` | Oversized title, no image |

**Modified:**

| Path | Change |
|---|---|
| `backend/src/Service/Parser/ItemImageExtractor.php` | Return `?ParsedImage`; largest declared width wins |
| `backend/src/Service/Parser/ParsedEntry.php` | `?ParsedImage $image` replaces `?string $imageUrl` |
| `backend/src/Service/Parser/{Rss1Parser,Rss2Parser,AbstractAtomParser}.php` | Pool candidates, pass the value object |
| `backend/src/Entity/Entry.php` | `imageUrl`, `imageWidth`, `imageHeight` + accessors |
| `backend/src/Service/EntryIngestor.php` | Persist the image; `fillMissingImages()`; use `EntrySnippet` |
| `backend/src/Service/Refresh/*` (caller of `ingest`) | Call `fillMissingImages()` alongside `ingest()` |
| `backend/src/Http/EntryJson.php` | Expose the three fields |
| `backend/src/Service/Preview/FeedPreviewService.php` | Follow the `ParsedImage` rename |
| `frontend/src/app/reader/models.ts` | Three fields on `EntryDto` |
| `frontend/src/app/reader/preview-image.ts` | `entryImage(entry)` prefers the DTO field |
| `frontend/src/app/reader/magazine/magazine-planner.ts` | Rewritten as a template engine |
| `frontend/src/app/reader/magazine/entry-hero.component.{ts,html,scss}` | Adaptive aspect, `width`/`height` |
| `frontend/src/app/reader/magazine/source-group.component.ts` | Bounded digest |
| `frontend/src/app/reader/entry-list/entry-list.component.html` | Render the new union |
| `docs/design-language.md` | The eight block types |

---

# Stage A — Data pipeline

## Task 1: `ParsedImage` value object

**Files:**
- Create: `backend/src/Service/Parser/ParsedImage.php`

- [ ] **Step 1: Write the value object**

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * An image attached to a feed item: the URL, plus the dimensions the feed
 * declared. Roughly 60% of feeds declare neither, and the Guardian declares
 * width without height, so both are independently nullable — a caller must
 * treat "unknown" as a first-class case rather than defaulting to a guess.
 */
final readonly class ParsedImage
{
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
```

- [ ] **Step 2: Verify it loads**

Run: `composer cs && composer stan`
Expected: no violations.

- [ ] **Step 3: Commit**

```bash
git add backend/src/Service/Parser/ParsedImage.php
git commit -m "feat(parser): add ParsedImage value object for feed-declared image metadata"
```

---

## Task 2: `ItemImageExtractor` picks the largest declared variant

The current extractor returns the **first** matching element. The Guardian ships three `<media:content>` per item at widths 140/460/700 in ascending order, so "first" is 140px — below the 200px gate that already suppresses heroes.

**Files:**
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php`
- Test: `backend/tests/Service/Parser/ItemImageExtractorTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemImageExtractor;
use PHPUnit\Framework\TestCase;

final class ItemImageExtractorTest extends TestCase
{
    private function item(string $innerXml): \DOMElement
    {
        $doc = new \DOMDocument();
        $doc->loadXML(
            '<rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
            . $innerXml
            . '</item></channel></rss>',
        );
        $item = $doc->getElementsByTagName('item')->item(0);
        self::assertInstanceOf(\DOMElement::class, $item);

        return $item;
    }

    public function testPicksTheWidestMediaContentVariant(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/small.jpg" medium="image" width="140"/>'
            . '<media:content url="https://i/mid.jpg" medium="image" width="460"/>'
            . '<media:content url="https://i/big.jpg" medium="image" width="700"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/big.jpg', $image->url);
        self::assertSame(700, $image->width);
        self::assertNull($image->height);
    }

    public function testCapturesBothDeclaredDimensions(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:thumbnail url="https://i/t.jpg" width="948" height="474"/>',
        ));

        self::assertNotNull($image);
        self::assertSame(948, $image->width);
        self::assertSame(474, $image->height);
    }

    public function testAWiderContentBeatsAThumbnail(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:thumbnail url="https://i/t.jpg" width="240" height="135"/>'
            . '<media:content url="https://i/c.jpg" medium="image" width="2400"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/c.jpg', $image->url);
    }

    public function testAnUndeclaredWidthLosesToADeclaredOne(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/unknown.jpg" medium="image"/>'
            . '<media:content url="https://i/known.jpg" medium="image" width="300"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/known.jpg', $image->url);
    }

    public function testFallsBackToDocumentOrderWhenNothingDeclaresAWidth(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:content url="https://i/first.jpg" medium="image"/>'
            . '<media:content url="https://i/second.jpg" medium="image"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/first.jpg', $image->url);
    }

    public function testSearchesInsideAMediaGroup(): void
    {
        $image = ItemImageExtractor::fromMedia($this->item(
            '<media:group><media:content url="https://i/g.jpg" medium="image" width="500"/></media:group>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/g.jpg', $image->url);
    }

    public function testReadsAnRssEnclosure(): void
    {
        $image = ItemImageExtractor::fromRssEnclosure($this->item(
            '<enclosure url="https://i/e.jpg" type="image/jpeg" length="0"/>',
        ));

        self::assertNotNull($image);
        self::assertSame('https://i/e.jpg', $image->url);
        self::assertNull($image->width);
    }

    public function testIgnoresANonImageEnclosure(): void
    {
        self::assertNull(ItemImageExtractor::fromRssEnclosure($this->item(
            '<enclosure url="https://i/a.mp3" type="audio/mpeg" length="10"/>',
        )));
    }

    public function testReadsAnInlineImgWithoutDimensions(): void
    {
        $image = ItemImageExtractor::fromHtml('<p>x</p><img src="https://i/inline.jpg">');

        self::assertNotNull($image);
        self::assertSame('https://i/inline.jpg', $image->url);
        self::assertNull($image->width);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Parser/ItemImageExtractorTest.php`
Expected: FAIL — the extractor returns `string`, so `$image->url` is an error on a string.

- [ ] **Step 3: Rewrite the extractor**

Replace the whole body of `backend/src/Service/Parser/ItemImageExtractor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Finds the best image attached to a feed item. Callers combine the sources in
 * the precedence their format prefers (Media RSS, then a format's enclosure,
 * then an inline <img>).
 *
 * Within Media RSS the WIDEST declared variant wins, not the first. Feeds
 * routinely ship a ladder of sizes — the Guardian publishes 140/460/700 in
 * ascending order, so "first" would persist a thumbnail too small to feature.
 * An element that declares no width loses to any element that declares one;
 * when nothing declares a width, document order decides.
 *
 * URLs are returned verbatim — callers that need an absolute URL resolve it
 * themselves.
 */
final class ItemImageExtractor
{
    private const string MEDIA_NS = 'http://search.yahoo.com/mrss/';

    /** Media RSS image, searching <media:group> when nothing is attached directly. */
    public static function fromMedia(\DOMElement $item): ?ParsedImage
    {
        $candidates = self::mediaCandidatesIn($item);

        foreach ($item->childNodes as $child) {
            if (self::isMediaElement($child, 'group')) {
                /** @var \DOMElement $child */
                $candidates = [...$candidates, ...self::mediaCandidatesIn($child)];
            }
        }

        return self::widest($candidates);
    }

    /** RSS 2.0 <enclosure type="image/*" url="…">. */
    public static function fromRssEnclosure(\DOMElement $item): ?ParsedImage
    {
        foreach ($item->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'enclosure') {
                continue;
            }
            if (!str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                continue;
            }
            $url = trim($child->getAttribute('url'));
            if ($url !== '') {
                return self::imageFrom($child, $url);
            }
        }

        return null;
    }

    /** Atom <link rel="enclosure" type="image/*" href="…">. */
    public static function fromAtomEnclosure(\DOMElement $entry, string $ns): ?ParsedImage
    {
        foreach ($entry->childNodes as $child) {
            if (
                !$child instanceof \DOMElement
                || $child->localName !== 'link'
                || $child->namespaceURI !== $ns
                || $child->getAttribute('rel') !== 'enclosure'
            ) {
                continue;
            }
            if (!str_starts_with(strtolower($child->getAttribute('type')), 'image/')) {
                continue;
            }
            $href = trim($child->getAttribute('href'));
            if ($href !== '') {
                return self::imageFrom($child, $href);
            }
        }

        return null;
    }

    /** First <img src="…"> in a fragment of HTML. Dimensions are never trusted here. */
    public static function fromHtml(?string $html): ?ParsedImage
    {
        if ($html === null || $html === '') {
            return null;
        }
        if (preg_match('/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/i', $html, $matches) !== 1) {
            return null;
        }
        $src = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5));

        return $src === '' ? null : new ParsedImage($src);
    }

    /** @return list<ParsedImage> */
    private static function mediaCandidatesIn(\DOMElement $parent): array
    {
        $candidates = [];
        foreach ($parent->childNodes as $child) {
            if (!self::isMediaElement($child, 'thumbnail') && !self::isMediaElement($child, 'content')) {
                continue;
            }
            /** @var \DOMElement $child */
            $url = trim($child->getAttribute('url'));
            if ($url === '' || !self::isImageElement($child)) {
                continue;
            }
            $candidates[] = self::imageFrom($child, $url);
        }

        return $candidates;
    }

    /**
     * <media:thumbnail> is an image by definition. <media:content> is only an
     * image when it says so — the same element carries audio and video, so an
     * element that declares neither medium nor type is rejected rather than
     * guessed at. Guessing would let a podcast's bare <media:content> mp3 be
     * persisted as an entry image.
     */
    private static function isImageElement(\DOMElement $element): bool
    {
        if ($element->localName === 'thumbnail') {
            return true;
        }
        $medium = strtolower($element->getAttribute('medium'));
        $type = strtolower($element->getAttribute('type'));

        return $medium === 'image' || str_starts_with($type, 'image/');
    }

    private static function isMediaElement(\DOMNode $node, string $localName): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === $localName
            && $node->namespaceURI === self::MEDIA_NS;
    }

    private static function imageFrom(\DOMElement $element, string $url): ParsedImage
    {
        return new ParsedImage(
            $url,
            self::positiveInt($element->getAttribute('width')),
            self::positiveInt($element->getAttribute('height')),
        );
    }

    private static function positiveInt(string $raw): ?int
    {
        $value = filter_var(trim($raw), FILTER_VALIDATE_INT);

        return \is_int($value) && $value > 0 ? $value : null;
    }

    /** @param list<ParsedImage> $candidates */
    private static function widest(array $candidates): ?ParsedImage
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null) {
                $best = $candidate;
                continue;
            }
            if (($candidate->width ?? 0) > ($best->width ?? 0)) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Service/Parser/ItemImageExtractorTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 5: Gate and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Service/Parser/ItemImageExtractor.php backend/tests/Service/Parser/ItemImageExtractorTest.php
git commit -m "feat(parser): select the widest declared image variant and capture dimensions (#148)"
```

---

## Task 3: Thread `ParsedImage` through the format parsers

> **Amended during execution:** Tasks 2 and 3 were implemented as one commit. Task 2
> alone cannot pass its own gate — changing the extractor's return type breaks
> PHPStan level max and the suite until `ParsedEntry` changes with it.

**Files:**
- Modify: `backend/src/Service/Parser/ParsedEntry.php`
- Modify: `backend/src/Service/Parser/Rss2Parser.php:57-70`
- Modify: `backend/src/Service/Parser/Rss1Parser.php:66`
- Modify: `backend/src/Service/Parser/AbstractAtomParser.php:98`
- Modify: `backend/src/Service/Preview/FeedPreviewService.php:92`
- Modify: `backend/src/Service/Scraper/HtmlItemExtractor.php:139` — **missed when this
  plan was written.** The scraper builds a `ParsedEntry` too, from its own
  `ScrapedItem::$imageUrl` (`?string`). Wrap it: `image: $item->imageUrl === null ? null : new ParsedImage($item->imageUrl)`.
  `ScrapedItem`, `CardFields` and the rest of `Service/Scraper/` keep plain strings — do not rename them.
- Modify the assertions in `tests/Service/Parser/{Rss2,Rss1,Atom10}ParserTest.php` and
  `tests/Service/Scraper/HtmlItemExtractorTest.php` — rename to `image?->url`, never delete.

- [ ] **Step 1: Run the suite to see the current baseline**

Run: `php bin/phpunit`
Expected: PASS. Record the test count — the next step must not reduce it.

- [ ] **Step 2: Change `ParsedEntry`**

Replace the `imageUrl` property in `backend/src/Service/Parser/ParsedEntry.php`:

```php
        public ?\DateTimeImmutable $publishedAt,
        public ?ParsedImage $image = null,
    ) {
    }
```

- [ ] **Step 3: Update `Rss2Parser::parseItem()`**

Replace the `$image = …` assignment and the `imageUrl:` argument:

```php
        $image = ItemImageExtractor::fromMedia($item)
            ?? ItemImageExtractor::fromRssEnclosure($item)
            ?? ItemImageExtractor::fromHtml($contentEncoded ?? $description);
```

```php
            image: $image,
```

- [ ] **Step 4: Update `Rss1Parser` and `AbstractAtomParser`**

In both, rename the named argument `imageUrl:` to `image:`. The local `$image` variable already holds the extractor result and needs no change.

- [ ] **Step 5: Update `FeedPreviewService`**

Replace line 92:

```php
            hasImage: $entry->image !== null,
```

- [ ] **Step 6: Run the suite**

Run: `php bin/phpunit`
Expected: PASS with the same test count as Step 1. Any parser test asserting `imageUrl` needs its assertion renamed to `image?->url` — do that rather than deleting the assertion.

- [ ] **Step 7: Gate and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Service/Parser backend/src/Service/Preview backend/tests
git commit -m "refactor(parser): carry ParsedImage instead of a bare image URL (#148)"
```

---

## Task 4: Three nullable columns on `Entry`

**Files:**
- Modify: `backend/src/Entity/Entry.php`
- Create: `backend/migrations/Version20260727120000.php`

- [ ] **Step 1: Add the columns and accessors**

After the `contentHtml` property in `backend/src/Entity/Entry.php`:

```php
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $imageUrl = null;

    /** As DECLARED by the feed. Null means unknown, not "no image". */
    #[ORM\Column(nullable: true)]
    private ?int $imageWidth = null;

    #[ORM\Column(nullable: true)]
    private ?int $imageHeight = null;
```

After `setContentHtml()`:

```php
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getImageWidth(): ?int
    {
        return $this->imageWidth;
    }

    public function getImageHeight(): ?int
    {
        return $this->imageHeight;
    }

    public function setImage(?string $url, ?int $width, ?int $height): void
    {
        $this->imageUrl = $url;
        $this->imageWidth = $width;
        $this->imageHeight = $height;
    }
```

- [ ] **Step 2: Write the migration**

Create `backend/migrations/Version20260727120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the feed-supplied image to entry. The parser has always extracted it and
 * the ingestor always dropped it (#148).
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration, so a dialect error here is caught only by CI's dedicated
 * migrate-from-empty leg.
 */
final class Version20260727120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_url, image_width and image_height to entry';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        // Already baselined from ORM metadata: nothing to add.
        if ($schema->getTable('entry')->hasColumn('image_url')) {
            return;
        }

        if ($mysql) {
            $this->addSql('ALTER TABLE entry ADD image_url VARCHAR(2048) DEFAULT NULL, ADD image_width INT DEFAULT NULL, ADD image_height INT DEFAULT NULL');
        }

        if ($sqlite) {
            $this->addSql('ALTER TABLE entry ADD COLUMN image_url VARCHAR(2048) DEFAULT NULL');
            $this->addSql('ALTER TABLE entry ADD COLUMN image_width INTEGER DEFAULT NULL');
            $this->addSql('ALTER TABLE entry ADD COLUMN image_height INTEGER DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry DROP image_url, DROP image_width, DROP image_height');

            return;
        }

        $this->addSql('ALTER TABLE entry DROP COLUMN image_url');
        $this->addSql('ALTER TABLE entry DROP COLUMN image_width');
        $this->addSql('ALTER TABLE entry DROP COLUMN image_height');
    }
}
```

- [ ] **Step 3: Verify the migration on an empty SQLite database**

```bash
rm -f var/migrate-check.db
DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate
rm -f var/migrate-check.db
```

Expected: migrations run clean; `schema:validate` reports the mapping and database are in sync.

- [ ] **Step 4: Verify on MySQL**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: same. If Docker is not running, `docker compose up -d` first.

- [ ] **Step 5: Run the suite**

Run: `php bin/phpunit`
Expected: PASS.

- [ ] **Step 6: Gate and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Entity/Entry.php backend/migrations/Version20260727120000.php
git commit -m "feat(entry): add image_url, image_width and image_height columns (#148)"
```

---

## Task 5: `EntrySnippet` — strip image markup, reject junk

11% of ZEIT entries have `summary = null` and `contentHtml = '<a><img/></a> None'`, so the literal token `None` renders as body copy.

**Files:**
- Create: `backend/src/Service/EntrySnippet.php`
- Test: `backend/tests/Service/EntrySnippetTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EntrySnippet;
use PHPUnit\Framework\TestCase;

final class EntrySnippetTest extends TestCase
{
    public function testReturnsPlainTextWithoutMarkup(): void
    {
        self::assertSame(
            'Hello there',
            EntrySnippet::from('<p>Hello <strong>there</strong></p>'),
        );
    }

    public function testDropsALeadingImageLink(): void
    {
        self::assertSame(
            'The real summary.',
            EntrySnippet::from('<a href="https://x"><img src="https://i/a.jpg"/></a> The real summary.'),
        );
    }

    public function testReturnsNullForAnImageOnlyBody(): void
    {
        self::assertNull(EntrySnippet::from('<a href="https://x"><img src="https://i/a.jpg"/></a>'));
    }

    public function testReturnsNullForTheLiteralNoneArtifact(): void
    {
        self::assertNull(EntrySnippet::from('<a href="https://x"><img src="https://i/a.jpg"/></a> None'));
    }

    public function testKeepsNoneWhenItIsPartOfARealSentence(): void
    {
        self::assertSame(
            'None of the proposals survived the vote.',
            EntrySnippet::from('None of the proposals survived the vote.'),
        );
    }

    public function testReturnsNullForNull(): void
    {
        self::assertNull(EntrySnippet::from(null));
    }

    public function testCollapsesWhitespace(): void
    {
        self::assertSame('a b c', EntrySnippet::from("a\n  b\t\tc"));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/EntrySnippetTest.php`
Expected: FAIL with "Class App\Service\EntrySnippet not found".

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Produces PLAIN TEXT, not HTML, from an entry body.
 *
 * Images are removed before the text is extracted: now that the image is its
 * own column, an <img> contributes nothing to a snippet, and feeds that wrap a
 * thumbnail in an anchor would otherwise leave the anchor's whitespace behind.
 *
 * A body that reduces to nothing — or to a single junk token, which is how DIE
 * ZEIT's CMS leaks a Python `None` into content:encoded — yields null rather
 * than a snippet, so the caller can route the entry to a title-led block.
 *
 * The result may contain <, > and & as literal characters and has NOT been
 * through EntrySanitizer. Render it as text only; never with |raw or innerHTML.
 */
final class EntrySnippet
{
    private const int MAX_LENGTH = 500;

    /** Single-token bodies that carry no meaning. Matched only when they are the ENTIRE body. */
    private const array JUNK = ['none', 'null', 'nil', 'n/a', '-', '—'];

    public static function from(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        $withoutImages = preg_replace('/<img\b[^>]*>/i', ' ', $html) ?? $html;
        $text = trim(html_entity_decode(strip_tags($withoutImages), ENT_QUOTES | ENT_HTML5));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || \in_array(mb_strtolower($text), self::JUNK, true)) {
            return null;
        }

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Service/EntrySnippetTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Gate and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Service/EntrySnippet.php backend/tests/Service/EntrySnippetTest.php
git commit -m "feat(entry): derive snippets with image markup stripped and junk rejected (#148)"
```

---

## Task 6: `EntryIngestor` persists the image and backfills known entries

Feeds serve 15–50 items against 2238 stored entries, so a re-fetch reaches ~3.5% of the archive. Backfill is therefore opportunistic: it fills what a normal refresh happens to see, and never overwrites.

**Files:**
- Modify: `backend/src/Service/EntryIngestor.php`
- Test: `backend/tests/Service/EntryIngestorTest.php` (extend; create if absent)

- [ ] **Step 1: Write the failing tests**

Add to the ingestor test class. `$this->ingestor`, `$this->em` and a `feed()` helper follow whatever the existing test class already sets up; if the file does not exist, mirror the fixture style of `backend/tests/Service/Parser/`.

```php
    public function testPersistsTheFeedSuppliedImage(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed('T', null, null, [
            new ParsedEntry('g1', 'https://x/1', 'One', null, null, '<p>body</p>', null,
                new ParsedImage('https://i/1.jpg', 948, 474)),
        ]);

        $this->ingestor->ingest($feed, $parsed);
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g1']);
        self::assertNotNull($entry);
        self::assertSame('https://i/1.jpg', $entry->getImageUrl());
        self::assertSame(948, $entry->getImageWidth());
        self::assertSame(474, $entry->getImageHeight());
    }

    public function testFillMissingImagesPopulatesAnEntryIngestedWithoutOne(): void
    {
        $feed = $this->feed();
        $withoutImage = new ParsedFeed('T', null, null, [
            new ParsedEntry('g2', null, 'Two', null, null, '<p>body</p>', null, null),
        ]);
        $this->ingestor->ingest($feed, $withoutImage);
        $this->em->flush();

        $withImage = new ParsedFeed('T', null, null, [
            new ParsedEntry('g2', null, 'Two', null, null, '<p>body</p>', null,
                new ParsedImage('https://i/2.jpg', 700, null)),
        ]);
        $filled = $this->ingestor->fillMissingImages($feed, $withImage);
        $this->em->flush();

        self::assertSame(1, $filled);
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g2']);
        self::assertNotNull($entry);
        self::assertSame('https://i/2.jpg', $entry->getImageUrl());
        self::assertSame(700, $entry->getImageWidth());
    }

    public function testFillMissingImagesNeverOverwritesAnExistingImage(): void
    {
        $feed = $this->feed();
        $this->ingestor->ingest($feed, new ParsedFeed('T', null, null, [
            new ParsedEntry('g3', null, 'Three', null, null, null, null,
                new ParsedImage('https://i/original.jpg', 900, 600)),
        ]));
        $this->em->flush();

        $filled = $this->ingestor->fillMissingImages($feed, new ParsedFeed('T', null, null, [
            new ParsedEntry('g3', null, 'Three', null, null, null, null,
                new ParsedImage('https://i/replacement.jpg', 100, 100)),
        ]));
        $this->em->flush();

        self::assertSame(0, $filled);
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g3']);
        self::assertNotNull($entry);
        self::assertSame('https://i/original.jpg', $entry->getImageUrl());
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/EntryIngestorTest.php`
Expected: FAIL — `fillMissingImages` does not exist.

- [ ] **Step 3: Persist the image in `ingest()`**

In `backend/src/Service/EntryIngestor.php`, replace the `setSummary` line and add the image call:

```php
            $entry->setSummary(EntrySnippet::from($parsedEntry->summary ?? $parsedEntry->contentHtml));
            $entry->setContentHtml($this->sanitizer->sanitize($parsedEntry->contentHtml));
            $entry->setPublishedAt($parsedEntry->publishedAt);
            $entry->setImage(
                $parsedEntry->image === null
                    ? null
                    : mb_substr($parsedEntry->image->url, 0, self::URL_MAX),
                $parsedEntry->image?->width,
                $parsedEntry->image?->height,
            );
```

Delete the now-unused private `summarize()` method and the `SUMMARY_MAX` constant — `EntrySnippet` owns both. Add `use App\Service\Parser\ParsedImage;` only if PHPStan asks for it.

- [ ] **Step 4: Add `fillMissingImages()`**

Immediately after `correctPublishedDates()`, whose shape it follows:

```php
    /**
     * Fill in the image on entries ingested before the feed's image was
     * persisted (#148), matching by guid hash against a fresh parse.
     *
     * Only entries whose image is currently NULL are touched — a feed that
     * later drops or downgrades its images must never erase what we have. The
     * archive this can reach is bounded by what the feed still serves (15–50
     * items against thousands stored), so this is opportunistic repair, not a
     * migration. Caller flushes. Returns the number updated.
     */
    public function fillMissingImages(Feed $feed, ParsedFeed $parsed): int
    {
        if ($parsed->entries === []) {
            return 0;
        }

        $hashes = array_map(
            static fn ($entry): string => hash('sha256', $entry->guid),
            $parsed->entries,
        );
        $existing = $this->entryRepository->findByFeedIndexedByGuidHash($feed, $hashes);

        $updated = 0;
        foreach ($parsed->entries as $parsedEntry) {
            if ($parsedEntry->image === null) {
                continue;
            }
            $entry = $existing[hash('sha256', $parsedEntry->guid)] ?? null;
            if ($entry === null || $entry->getImageUrl() !== null) {
                continue;
            }
            $entry->setImage(
                mb_substr($parsedEntry->image->url, 0, self::URL_MAX),
                $parsedEntry->image->width,
                $parsedEntry->image->height,
            );
            $updated++;
        }

        return $updated;
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `php bin/phpunit tests/Service/EntryIngestorTest.php`
Expected: PASS.

- [ ] **Step 6: Gate and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Service/EntryIngestor.php backend/tests/Service/EntryIngestorTest.php
git commit -m "feat(ingest): persist the feed image and backfill entries missing one (#148)"
```

---

## Task 7: Call `fillMissingImages()` from the refresh path

**Files:**
- Modify: the service in `backend/src/Service/Refresh/` that calls `EntryIngestor::ingest()`

- [ ] **Step 1: Find the call site**

Run: `grep -rn "->ingest(" backend/src`
Expected: one call site inside `backend/src/Service/Refresh/`.

- [ ] **Step 2: Add the backfill call**

Immediately after the existing `ingest()` call, in the same unit of work so the caller's single flush covers both:

```php
        $created = $this->ingestor->ingest($feed, $parsed);
        $this->ingestor->fillMissingImages($feed, $parsed);
```

If the surrounding method is already at the PHPMD complexity threshold, extract the two calls into a private `private function storeEntries(Feed $feed, ParsedFeed $parsed): int` rather than raising the threshold.

- [ ] **Step 3: Run the suite**

Run: `php bin/phpunit`
Expected: PASS.

- [ ] **Step 4: Gate and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Service/Refresh
git commit -m "feat(refresh): backfill missing images on every refresh (#148)"
```

---

## Task 8: Expose the fields on the API

**Files:**
- Modify: `backend/src/Http/EntryJson.php`
- Test: `backend/tests/` — whichever functional test asserts the entries payload shape

- [ ] **Step 1: Extend the docblock and the array**

In `backend/src/Http/EntryJson.php`, add to the `@return` shape after `contentHtml: string|null,`:

```
     *   imageUrl: string|null, imageWidth: int|null, imageHeight: int|null,
```

and after the `'contentHtml' => …` line:

```php
            'imageUrl' => $e->getImageUrl(),
            'imageWidth' => $e->getImageWidth(),
            'imageHeight' => $e->getImageHeight(),
```

- [ ] **Step 2: Run the suite**

Run: `php bin/phpunit`
Expected: PASS. A functional test asserting an exact key set will fail — add the three keys to its expectation rather than loosening the assertion.

- [ ] **Step 3: Verify against the running stack**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

Then refresh a feed from the UI and confirm a request to `/api/entries` returns a non-null `imageUrl` for a SPIEGEL or WIRED entry.

- [ ] **Step 4: Check the log**

Run: `tail -n 100 backend/var/log/dev.log`
Expected: no new deprecations or errors.

- [ ] **Step 5: Gate and commit**

```bash
composer cs && composer stan && composer md
git add backend/src/Http/EntryJson.php backend/tests
git commit -m "feat(api): expose imageUrl, imageWidth and imageHeight on entries (#148)"
```

---

# Stage B — Block catalog

## Task 9: `EntryDto` fields and the `entryImage()` accessor

The inline-`<img>` fallback stays: backfill cannot reach the deep archive, so archive rows still depend on it.

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/preview-image.ts`
- Test: `frontend/src/app/reader/preview-image.spec.ts` (create if absent)

- [ ] **Step 1: Write the failing test**

```ts
import { entryImage } from './preview-image';
import { EntryDto } from './models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 't',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'S',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

describe('entryImage', () => {
  it('prefers the persisted image and carries its dimensions', () => {
    expect(
      entryImage(entry({ imageUrl: 'https://i/a.jpg', imageWidth: 948, imageHeight: 474 })),
    ).toEqual({ url: 'https://i/a.jpg', width: 948, height: 474 });
  });

  it('falls back to an inline https img for archive rows', () => {
    expect(entryImage(entry({ contentHtml: '<img src="https://i/b.jpg">' }))).toEqual({
      url: 'https://i/b.jpg',
      width: null,
      height: null,
    });
  });

  it('rejects a non-https inline src', () => {
    expect(entryImage(entry({ contentHtml: '<img src="http://i/c.jpg">' }))).toBeNull();
  });

  it('returns null when there is no image anywhere', () => {
    expect(entryImage(entry())).toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest src/app/reader/preview-image.spec.ts`
Expected: FAIL — `entryImage` is not exported.

- [ ] **Step 3: Add the DTO fields**

In `frontend/src/app/reader/models.ts`, inside `EntryDto` after `contentHtml`:

```ts
  /** Absolute image URL the feed supplied, or null. Persisted server-side. */
  imageUrl: string | null;
  /** Dimensions AS DECLARED by the feed. Null means unknown, not square. */
  imageWidth: number | null;
  imageHeight: number | null;
```

- [ ] **Step 4: Add `entryImage()`**

Append to `frontend/src/app/reader/preview-image.ts`:

```ts
import { EntryDto } from './models';

export interface EntryImage {
  url: string;
  /** Declared width, or null when the feed did not say. */
  width: number | null;
  height: number | null;
}

/** The entry's image: the persisted field when present, else an inline <img>.
 *  The fallback exists for rows ingested before the image column landed — a
 *  refresh only backfills what the feed still serves, so the deep archive keeps
 *  depending on inline markup indefinitely. */
export function entryImage(entry: EntryDto): EntryImage | null {
  if (entry.imageUrl) {
    return { url: entry.imageUrl, width: entry.imageWidth, height: entry.imageHeight };
  }
  const inline = firstPreviewImage(entry.contentHtml, entry.summary);
  return inline === null ? null : { url: inline, width: null, height: null };
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `npx jest src/app/reader/preview-image.spec.ts`
Expected: PASS, 4 tests.

- [ ] **Step 6: Fix the fixtures the new required fields break**

Run: `npm run check`
Expected: TypeScript errors in every spec whose `EntryDto` fixture omits the three fields. Add `imageUrl: null, imageWidth: null, imageHeight: null` to each fixture factory — there is one factory per spec file, so this is a handful of edits, not one per test.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/preview-image.ts frontend/src/app/reader/preview-image.spec.ts frontend/src/app
git commit -m "feat(reader): expose the persisted entry image with an inline fallback (#148)"
```

---

## Task 10: Hero — adaptive aspect ratio and reserved space

Today the hero hard-codes `aspect-ratio: 16 / 9` and drops any image under 200px wide *after* it loads, which is why ZEIT heroes render as bare text. With dimensions persisted, both become plan-time facts.

**Files:**
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.ts`
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.html`
- Modify: `frontend/src/app/reader/magazine/entry-hero.component.scss`
- Test: `frontend/src/app/reader/magazine/entry-hero.component.spec.ts`

- [ ] **Step 1: Write the failing tests**

Add to `entry-hero.component.spec.ts`:

```ts
  it('sets the aspect ratio from the declared dimensions', () => {
    const el = mount(
      entry({ imageUrl: 'https://i/a.jpg', imageWidth: 1232, imageHeight: 1232 }),
    ).nativeElement as HTMLElement;
    const img = el.querySelector('img.img') as HTMLImageElement;
    expect(img.style.aspectRatio).toBe('1232 / 1232');
    expect(img.getAttribute('width')).toBe('1232');
    expect(img.getAttribute('height')).toBe('1232');
  });

  it('falls back to 16 / 9 when the feed declared no dimensions', () => {
    const el = mount(entry({ imageUrl: 'https://i/a.jpg' })).nativeElement as HTMLElement;
    const img = el.querySelector('img.img') as HTMLImageElement;
    expect(img.style.aspectRatio).toBe('16 / 9');
    expect(img.getAttribute('width')).toBeNull();
  });
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest src/app/reader/magazine/entry-hero.component.spec.ts`
Expected: FAIL — `aspectRatio` is empty; the ratio lives in SCSS.

- [ ] **Step 3: Update the component**

In `entry-hero.component.ts`, replace the `image`/`showImage` computeds:

```ts
  readonly image = computed(() => entryImage(this.entry()));
  readonly showImage = computed(() => !!this.image() && !this.imgError() && !this.tooSmall());
  /** Honour the feed's own ratio so a square image is not cropped by 46%.
   *  Unknown dimensions keep the editorial default. */
  readonly aspect = computed(() => {
    const img = this.image();
    return img?.width && img?.height ? `${img.width} / ${img.height}` : '16 / 9';
  });
```

Change the import from `firstPreviewImage` to `entryImage`, keeping `textSnippet`.

`onLoad()` stays — it is still the only defence for archive rows whose dimensions are unknown.

- [ ] **Step 4: Update the template**

In `entry-hero.component.html`, replace the `<img>` open tag attributes:

```html
    <img
      class="img"
      [src]="image()!.url"
      [style.aspect-ratio]="aspect()"
      [attr.width]="image()!.width"
      [attr.height]="image()!.height"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (load)="onLoad($event)"
      (error)="imgError.set(true)"
    />
```

- [ ] **Step 5: Drop the hard-coded ratio from SCSS**

In `entry-hero.component.scss`, delete the `aspect-ratio: 16 / 9;` line from `.img`. Width, `object-fit` and `display` stay.

- [ ] **Step 6: Run to verify it passes**

Run: `npx jest src/app/reader/magazine/entry-hero.component.spec.ts`
Expected: PASS.

- [ ] **Step 7: Gate and commit**

```bash
npm run check
git add frontend/src/app/reader/magazine
git commit -m "feat(magazine): size hero images from the feed's declared aspect ratio (#148)"
```

---

## Task 11: `EntrySplitComponent` — the fixed medium block

The image goes from 88px to 38% of the column: **148px on mobile, 258px on desktop.**

**Files:**
- Create: `frontend/src/app/reader/magazine/entry-split.component.{ts,html,scss,spec.ts}`

- [ ] **Step 1: Write the failing test**

`entry-split.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { EntrySplitComponent } from './entry-split.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A medium headline',
  url: null,
  author: null,
  summary: 'A meaningful summary.',
  contentHtml: null,
  imageUrl: 'https://i/a.jpg',
  imageWidth: 700,
  imageHeight: 400,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'Src',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

function mount(e: EntryDto, side: 'left' | 'right' = 'right') {
  TestBed.configureTestingModule({ imports: [EntrySplitComponent] });
  const f = TestBed.createComponent(EntrySplitComponent);
  f.componentRef.setInput('entry', e);
  f.componentRef.setInput('imageSide', side);
  f.detectChanges();
  return f;
}

describe('EntrySplitComponent', () => {
  it('renders the title, snippet and image', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.textContent).toContain('A medium headline');
    expect(el.textContent).toContain('A meaningful summary.');
    expect(el.querySelector('img.img')).not.toBeNull();
  });

  it('flips the image to the left on request', () => {
    const el = mount(entry(), 'left').nativeElement as HTMLElement;
    expect(el.querySelector('.split.img-left')).not.toBeNull();
  });

  it('emits open on click', () => {
    const f = mount(entry());
    let opened: EntryDto | null = null;
    f.componentInstance.open.subscribe((e: EntryDto) => (opened = e));
    (f.nativeElement as HTMLElement).querySelector('article')!.dispatchEvent(new Event('click'));
    expect(opened).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest src/app/reader/magazine/entry-split.component.spec.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Write the component**

`entry-split.component.ts`:

```ts
// src/app/reader/magazine/entry-split.component.ts
import { Component, computed, inject, input, output, signal, effect } from '@angular/core';
import { EntryDto, SubscriptionTagDto } from '../models';
import { entryImage, textSnippet } from '../preview-image';
import { relativeTime } from '../format';
import { LanguageService } from '../../core/language.service';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';

@Component({
  selector: 'app-entry-split',
  imports: [FaviconComponent, SourceTagsComponent],
  templateUrl: './entry-split.component.html',
  styleUrl: './entry-split.component.scss',
})
export class EntrySplitComponent {
  readonly entry = input.required<EntryDto>();
  readonly imageSide = input<'left' | 'right'>('right');
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  readonly image = computed(() => entryImage(this.entry()));
  readonly showImage = computed(() => !!this.image() && !this.imgError());
  readonly snippet = computed(() => textSnippet(this.entry().summary || this.entry().contentHtml));
  private readonly language = inject(LanguageService);
  readonly when = computed(() =>
    relativeTime(this.entry().publishedAt ?? this.entry().createdAt, this.language.lang()),
  );

  // Reset the error gate when the host reuses this component for a different entry.
  private readonly _reset = effect(() => {
    this.entry();
    this.imgError.set(false);
  });
}
```

`entry-split.component.html`:

```html
<article
  class="split"
  role="button"
  tabindex="0"
  [class.read]="entry().isRead"
  [class.img-left]="imageSide() === 'left'"
  (click)="open.emit(entry())"
  (keydown.enter)="open.emit(entry())"
  (keydown.space)="$event.preventDefault(); open.emit(entry())"
>
  @if (showImage()) {
    <img
      class="img"
      [src]="image()!.url"
      [attr.width]="image()!.width"
      [attr.height]="image()!.height"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="imgError.set(true)"
    />
  }
  <div class="body">
    <p class="kicker">
      <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
      <app-favicon [url]="entry().faviconUrl" [size]="12" />{{ entry().source }} · {{ when() }}
    </p>
    <p class="title">{{ entry().title }}</p>
    @if (snippet()) {
      <p class="dek">{{ snippet() }}</p>
    }
    <app-source-tags [tags]="tags()" />
  </div>
</article>
```

`entry-split.component.scss`:

```scss
:host {
  display: block;
}

.split {
  display: flex;
  gap: var(--space-3);
  align-items: flex-start;
  padding: var(--space-3);
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  cursor: pointer;
}

.split:hover {
  border-color: var(--border-strong);
}

.split.img-left {
  flex-direction: row-reverse;
}

.img {
  /* The medium block's defining proportion: 38% of the column, so the image is
     148px on a phone and 258px at the 680px measure. The old 88px thumbnail is
     what made this block feel like a list row (#148). */
  flex: 0 0 38%;
  aspect-ratio: 3 / 2;
  object-fit: cover;
  border-radius: var(--radius);
  display: block;
}

.body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.kicker {
  margin: 0;
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

.kicker app-favicon {
  margin-inline-end: 0;
}

.dot {
  width: var(--space-2);
  height: var(--space-2);
  border-radius: 50%;
  border: 1px solid var(--border-strong);
  flex: none;
}

.dot.on {
  background: var(--accent);
  border-color: var(--accent);
}

.title {
  margin: 0;
  font-size: var(--fs-md);
  font-weight: 500;
  line-height: 1.35;
  color: var(--text-primary);
}

.split.read .title {
  color: var(--text-secondary);
  font-weight: 400;
}

.dek {
  margin: 0;
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `npx jest src/app/reader/magazine/entry-split.component.spec.ts`
Expected: PASS, 3 tests.

- [ ] **Step 5: Gate and commit**

```bash
npm run check
git add frontend/src/app/reader/magazine
git commit -m "feat(magazine): add the split block with a 38% side image (#148)"
```

---

## Task 12: `EntryWideComponent` and `EntryThumbComponent`

**Files:**
- Create: `frontend/src/app/reader/magazine/entry-wide.component.{ts,html,scss,spec.ts}`
- Create: `frontend/src/app/reader/magazine/entry-thumb.component.{ts,html,scss,spec.ts}`

- [ ] **Step 1: Write the failing tests**

`entry-wide.component.spec.ts` — copy the fixture factory and `mount` helper from `entry-split.component.spec.ts` verbatim, swapping the class to `EntryWideComponent` and dropping the `imageSide` input, then:

```ts
describe('EntryWideComponent', () => {
  it('renders a full-width image and the title, but no snippet', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('img.img')).not.toBeNull();
    expect(el.textContent).toContain('A medium headline');
    expect(el.textContent).not.toContain('A meaningful summary.');
  });

  it('emits open on click', () => {
    const f = mount(entry());
    let opened: EntryDto | null = null;
    f.componentInstance.open.subscribe((e: EntryDto) => (opened = e));
    (f.nativeElement as HTMLElement).querySelector('article')!.dispatchEvent(new Event('click'));
    expect(opened).not.toBeNull();
  });
});
```

`entry-thumb.component.spec.ts` — same fixture, class `EntryThumbComponent`:

```ts
describe('EntryThumbComponent', () => {
  it('renders a small image and the title', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('img.img')).not.toBeNull();
    expect(el.textContent).toContain('A medium headline');
  });

  it('renders without an image when the entry has none', () => {
    const el = mount(entry({ imageUrl: null })).nativeElement as HTMLElement;
    expect(el.querySelector('img.img')).toBeNull();
    expect(el.textContent).toContain('A medium headline');
  });
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx jest src/app/reader/magazine/entry-wide src/app/reader/magazine/entry-thumb`
Expected: FAIL — modules not found.

- [ ] **Step 3: Write both components**

Both `.ts` files are `EntrySplitComponent` with the `imageSide` input removed and the selector and template/style URLs renamed — copy it, then delete `readonly imageSide = …`. `EntryWideComponent` also drops the `snippet` computed.

`entry-wide.component.html`:

```html
<article
  class="wide"
  role="button"
  tabindex="0"
  [class.read]="entry().isRead"
  (click)="open.emit(entry())"
  (keydown.enter)="open.emit(entry())"
  (keydown.space)="$event.preventDefault(); open.emit(entry())"
>
  @if (showImage()) {
    <img
      class="img"
      [src]="image()!.url"
      [attr.width]="image()!.width"
      [attr.height]="image()!.height"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      (error)="imgError.set(true)"
    />
  }
  <div class="body">
    <p class="kicker">
      <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
      <app-favicon [url]="entry().faviconUrl" [size]="12" />{{ entry().source }} · {{ when() }}
    </p>
    <p class="title">{{ entry().title }}</p>
    <app-source-tags [tags]="tags()" />
  </div>
</article>
```

`entry-wide.component.scss` — the hero's SCSS with `.hero` renamed to `.wide`, `.title` at `--fs-lg`, no `.dek` and no `.actions`, plus:

```scss
.img {
  width: 100%;
  /* A cinematic band: image-forward at a third of a hero's height, which is
     what lets the planner spend an image block without spending a screenful. */
  aspect-ratio: 3 / 1;
  object-fit: cover;
  display: block;
}
```

`entry-thumb.component.html` — the split template with the `img-left` binding removed and no `.dek` paragraph.

`entry-thumb.component.scss` — the split SCSS with:

```scss
.img {
  /* stylelint-disable-next-line declaration-property-unit-allowed-list --
     tuned thumbnail box, not a spacing value. */
  flex: 0 0 88px;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border-radius: var(--radius);
  display: block;
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `npx jest src/app/reader/magazine/entry-wide src/app/reader/magazine/entry-thumb`
Expected: PASS, 4 tests.

- [ ] **Step 5: Gate and commit**

```bash
npm run check
git add frontend/src/app/reader/magazine
git commit -m "feat(magazine): add the wide band and thumb row blocks (#148)"
```

---

## Task 13: `EntryQuoteComponent` and `EntryKickerComponent`

Quote's trigger is **inverted** from the obvious one: it is requested by a template and fills from any long-text entry, with the image deliberately suppressed. Gated on "no image" it fired zero times in simulation, because after Stage A ~1% of entries lack an image.

**Files:**
- Create: `frontend/src/app/reader/magazine/entry-quote.component.{ts,html,scss,spec.ts}`
- Create: `frontend/src/app/reader/magazine/entry-kicker.component.{ts,html,scss,spec.ts}`

- [ ] **Step 1: Write the failing tests**

`entry-quote.component.spec.ts` — same fixture factory as Task 11, class `EntryQuoteComponent`, with `summary` set to `'First sentence here. Second sentence follows on.'`:

```ts
describe('EntryQuoteComponent', () => {
  it('leads with the first sentence and never renders an image', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.pull')!.textContent).toContain('First sentence here.');
    expect(el.querySelector('.pull')!.textContent).not.toContain('Second sentence');
    expect(el.querySelector('img.img')).toBeNull();
  });

  it('falls back to the whole snippet when there is no sentence break', () => {
    const el = mount(entry({ summary: 'One long clause with no terminator' }))
      .nativeElement as HTMLElement;
    expect(el.querySelector('.pull')!.textContent).toContain('One long clause with no terminator');
  });
});
```

`entry-kicker.component.spec.ts` — class `EntryKickerComponent`:

```ts
describe('EntryKickerComponent', () => {
  it('renders an oversized title and no image, even when the entry has one', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.textContent).toContain('A medium headline');
    expect(el.querySelector('img.img')).toBeNull();
  });
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `npx jest src/app/reader/magazine/entry-quote src/app/reader/magazine/entry-kicker`
Expected: FAIL — modules not found.

- [ ] **Step 3: Write both components**

`entry-quote.component.ts` — `EntrySplitComponent` with the image members deleted (no `imgError`, no `image`, no `showImage`, no `_reset` effect) and one addition:

```ts
  /** The pull-quote is the first sentence, not a clamped paragraph: a clamp
   *  ends mid-word, which reads as a rendering bug at this type size. */
  readonly lead = computed(() => {
    const text = textSnippet(this.entry().summary || this.entry().contentHtml);
    const stop = text.search(/[.!?](\s|$)/);
    return stop === -1 ? text : text.slice(0, stop + 1);
  });
```

`entry-quote.component.html`:

```html
<article
  class="quote"
  role="button"
  tabindex="0"
  [class.read]="entry().isRead"
  (click)="open.emit(entry())"
  (keydown.enter)="open.emit(entry())"
  (keydown.space)="$event.preventDefault(); open.emit(entry())"
>
  <p class="kicker">
    <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
    <app-favicon [url]="entry().faviconUrl" [size]="12" />{{ entry().source }} · {{ when() }}
  </p>
  <p class="pull">{{ lead() }}</p>
  <p class="title">{{ entry().title }}</p>
  <app-source-tags [tags]="tags()" />
</article>
```

`entry-quote.component.scss` — the split SCSS with `.split` renamed to `.quote`, `flex-direction: column`, no `.img`, plus:

```scss
.pull {
  margin: 0;
  font-family: var(--font-voice);
  font-size: var(--fs-lg);
  line-height: 1.4;
  color: var(--text-primary);
}

.title {
  margin: 0;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
}
```

`entry-kicker.component.ts` — identical to the quote component minus the `lead` computed.

`entry-kicker.component.html` — the quote template with the `.pull` paragraph removed and `.title` promoted to the lead element.

`entry-kicker.component.scss` — the quote SCSS with `.quote` renamed to `.kicker-card`, no `.pull`, and `.title` at `--fs-lg` / `font-weight: 500` / `color: var(--text-primary)`.

> Rename the block class deliberately: `.kicker` is already the meta line inside every other block, and reusing it for the card would collide.

- [ ] **Step 4: Run to verify they pass**

Run: `npx jest src/app/reader/magazine/entry-quote src/app/reader/magazine/entry-kicker`
Expected: PASS, 3 tests.

- [ ] **Step 5: Gate and commit**

```bash
npm run check
git add frontend/src/app/reader/magazine
git commit -m "feat(magazine): add the quote and kicker text-led blocks (#148)"
```

---

## Task 14: `SourceGroupComponent` becomes a bounded digest

**Files:**
- Modify: `frontend/src/app/reader/magazine/source-group.component.ts`
- Test: `frontend/src/app/reader/magazine/source-group.component.spec.ts`

- [ ] **Step 1: Write the failing test**

```ts
  it('never reports a remainder, because the planner no longer hides one', () => {
    const f = mount([e(1), e(2), e(3)], 0);
    expect((f.nativeElement as HTMLElement).textContent).not.toContain('more from');
  });
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest src/app/reader/magazine/source-group.component.spec.ts`
Expected: FAIL if the template renders the "more" line unconditionally.

- [ ] **Step 3: Make the "more" link conditional**

In `source-group.component.html`, wrap the "more" anchor:

```html
@if (moreCount() > 0) {
  <a class="more" [routerLink]="['/reader']" [queryParams]="{ subscription: subscriptionId() }">
    {{ 'reader.moreFrom' | transloco: { source: source(), count: moreCount() } }}
    <app-icon name="arrow_forward" size="sm" />
  </a>
}
```

Keep the existing anchor's own attributes and label expression — only the `@if` wrapper is new.

- [ ] **Step 4: Run to verify it passes**

Run: `npx jest src/app/reader/magazine/source-group.component.spec.ts`
Expected: PASS.

- [ ] **Step 5: Gate and commit**

```bash
npm run check
git add frontend/src/app/reader/magazine
git commit -m "feat(magazine): hide the group's more-link when nothing is held back (#148)"
```

---

# Stage C — Planner

## Task 15: The block union and the template library

**Files:**
- Create: `frontend/src/app/reader/magazine/magazine-block.ts`
- Create: `frontend/src/app/reader/magazine/magazine-templates.ts`

- [ ] **Step 1: Write the block union**

`magazine-block.ts`:

```ts
// src/app/reader/magazine/magazine-block.ts
import { EntryDto } from '../models';

/** Every block kind that can carry a single entry. Ordered by height, largest
 *  first — `DEMOTION` walks this ladder and the planner's budget compares on it. */
export const ENTRY_KINDS = [
  'hero',
  'wide',
  'quote',
  'split',
  'kicker',
  'thumb',
  'compact',
] as const;

export type EntryKind = (typeof ENTRY_KINDS)[number];

export type MagazineBlock =
  | { kind: 'hero'; entry: EntryDto }
  | { kind: 'wide'; entry: EntryDto }
  | { kind: 'quote'; entry: EntryDto }
  | { kind: 'split'; entry: EntryDto; imageSide: 'left' | 'right' }
  | { kind: 'kicker'; entry: EntryDto }
  | { kind: 'thumb'; entry: EntryDto }
  | { kind: 'compact'; entry: EntryDto }
  | {
      kind: 'group';
      subscriptionId: number;
      source: string;
      entries: EntryDto[];
      moreCount: number;
    };

/** Measured at 390px viewport width. The planner's budget is in these units;
 *  they need only be right relative to each other. */
export const BLOCK_HEIGHT: Record<EntryKind, number> = {
  hero: 463,
  wide: 260,
  quote: 180,
  split: 150,
  kicker: 140,
  thumb: 90,
  compact: 66,
};

/** Where a slot goes when its entry cannot fill it. Applied REPEATEDLY until
 *  the slot fits or reaches `compact` — one step is not enough: demoting a hero
 *  to `wide` in an image-less view still leaves an image block with no image. */
export const DEMOTION: Record<EntryKind, EntryKind> = {
  hero: 'wide',
  wide: 'split',
  split: 'thumb',
  thumb: 'compact',
  quote: 'kicker',
  kicker: 'compact',
  compact: 'compact',
};
```

- [ ] **Step 2: Write the template library**

`magazine-templates.ts`:

```ts
// src/app/reader/magazine/magazine-templates.ts
import { EntryKind } from './magazine-block';

/** A slot is either a fixed kind, or one of two kinds chosen by seeded jitter.
 *  Jitter is what keeps a library this small from reading as a cycle: twelve
 *  templates alone repeat every ~66 blocks, which is findable within a single
 *  sitting. */
export type Slot = EntryKind | { either: [EntryKind, EntryKind] };

/**
 * The rhythm, as data.
 *
 * Authored, not derived. Content-driven sizing was simulated against 300 real
 * entries and produced the MOST monotonous output of three candidates
 * (3-gram entropy 1.57 vs 4.88 for templates): once the image pipeline works,
 * ~99% of entries carry a large image and a usable snippet, so every entry
 * scores the same and every entry gets the same block. Variety therefore lives
 * here, and content only decides whether an entry can FILL the slot it is given.
 *
 * Rules when editing:
 * - Vary length (4–6). Equal-length templates re-introduce a beat.
 * - At most one `hero` per template, and never as the last slot.
 * - Keep the mixed heights: an all-large template costs a whole screenful.
 * - Add templates rather than lengthening them.
 */
export const TEMPLATES: readonly (readonly Slot[])[] = [
  ['hero', 'split', 'compact', 'compact', { either: ['thumb', 'compact'] }],
  ['wide', 'quote', 'split', 'compact', 'compact', 'compact'],
  ['split', 'thumb', 'hero', 'compact', 'compact'],
  ['quote', 'split', 'split', 'compact', 'wide'],
  ['hero', 'compact', 'compact', { either: ['thumb', 'compact'] }, 'thumb', 'split'],
  ['wide', 'split', 'compact', 'quote', { either: ['compact', 'thumb'] }],
  ['split', 'compact', 'hero', 'thumb', 'compact', 'compact'],
  ['kicker', 'split', 'thumb', 'compact', 'wide'],
  ['hero', 'thumb', 'compact', 'split', 'compact'],
  ['quote', 'compact', 'wide', 'compact', { either: ['split', 'thumb'] }, 'compact'],
  ['split', 'wide', 'compact', 'compact', 'thumb'],
  ['kicker', 'compact', 'split', 'hero', 'compact', 'compact'],
];
```

- [ ] **Step 3: Verify it compiles**

Run: `npm run check`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/app/reader/magazine/magazine-block.ts frontend/src/app/reader/magazine/magazine-templates.ts
git commit -m "feat(magazine): declare the block union and the template library (#148)"
```

---

## Task 16: The planner engine

**Files:**
- Modify: `frontend/src/app/reader/magazine/magazine-planner.ts` (rewrite)
- Test: `frontend/src/app/reader/magazine/magazine-planner.spec.ts` (rewrite)

- [ ] **Step 1: Write the failing tests**

Replace `magazine-planner.spec.ts`:

```ts
import { planMagazine } from './magazine-planner';
import { MagazineBlock } from './magazine-block';
import { EntryDto } from '../models';

const e = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: 'A headline of reasonable length',
  url: null,
  author: null,
  summary: 'A snippet long enough to fill a quote slot when one is asked for.',
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'S',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});
const big = (id: number, over: Partial<EntryDto> = {}): EntryDto =>
  e(id, { imageUrl: `https://i/${id}.jpg`, imageWidth: 900, imageHeight: 600, ...over });

const many = (n: number, make: (i: number) => EntryDto): EntryDto[] =>
  Array.from({ length: n }, (_, i) => make(i + 1));
const kinds = (bs: MagazineBlock[]) => bs.map((b) => b.kind);

const entryCount = (bs: MagazineBlock[]): number =>
  bs.reduce((n, b) => n + (b.kind === 'group' ? b.entries.length + b.moreCount : 1), 0);

const trigramEntropy = (ks: string[]): number => {
  const counts = new Map<string, number>();
  for (let i = 0; i + 2 < ks.length; i++) {
    const g = ks.slice(i, i + 3).join('>');
    counts.set(g, (counts.get(g) ?? 0) + 1);
  }
  const total = [...counts.values()].reduce((a, b) => a + b, 0);
  return -[...counts.values()].reduce((a, v) => a + (v / total) * Math.log2(v / total), 0);
};

describe('planMagazine', () => {
  it('emits every entry exactly once — nothing is ever hidden', () => {
    const entries = many(120, (i) => big(i, { subscriptionId: (i % 7) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(entryCount(blocks)).toBe(120);
  });

  it('is prefix-stable when more entries arrive', () => {
    const entries = many(120, (i) => big(i, { subscriptionId: (i % 7) + 1 }));
    const first = planMagazine({ entries: entries.slice(0, 60), grouping: true, complete: false });
    const second = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(second).slice(0, first.length)).toEqual(kinds(first));
  });

  it('preserves reverse-chronological order', () => {
    const entries = many(60, (i) => big(i, { subscriptionId: (i % 5) + 1 }));
    const ids = planMagazine({ entries, grouping: false, complete: true }).flatMap((b) =>
      b.kind === 'group' ? b.entries.map((x) => x.id) : [b.entry.id],
    );
    expect(ids).toEqual([...ids].sort((a, b) => a - b));
  });

  it('keeps 3-gram entropy above the boredom floor', () => {
    const entries = many(200, (i) => big(i, { subscriptionId: (i % 9) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(trigramEntropy(kinds(blocks))).toBeGreaterThan(4);
  });

  it('emits no image block when no entry has an image', () => {
    const entries = many(80, (i) => e(i, { subscriptionId: (i % 6) + 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('hero');
    expect(kinds(blocks)).not.toContain('wide');
    expect(kinds(blocks)).not.toContain('split');
    expect(kinds(blocks)).not.toContain('thumb');
  });

  it('does not group when one source dominates the view', () => {
    const entries = many(40, (i) => big(i, { subscriptionId: 1 }));
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    expect(kinds(blocks)).not.toContain('group');
    expect(entryCount(blocks)).toBe(40);
  });

  it('groups a minority source and bounds what the digest consumes', () => {
    const entries = [
      ...many(30, (i) => big(i, { subscriptionId: (i % 6) + 2 })),
      ...many(8, (i) => big(100 + i, { subscriptionId: 1, source: 'Burst' })),
      ...many(30, (i) => big(200 + i, { subscriptionId: (i % 6) + 2 })),
    ];
    const blocks = planMagazine({ entries, grouping: true, complete: true });
    const group = blocks.find((b) => b.kind === 'group');
    expect(group).toBeDefined();
    expect(group!.kind === 'group' && group!.entries.length).toBeLessThanOrEqual(3);
    expect(entryCount(blocks)).toBe(68);
  });

  it('holds back a partial trailing page while more can load', () => {
    const entries = many(20, (i) => big(i, { subscriptionId: (i % 5) + 1 }));
    const held = planMagazine({ entries, grouping: true, complete: false });
    const done = planMagazine({ entries, grouping: true, complete: true });
    expect(held.length).toBeLessThanOrEqual(done.length);
    expect(entryCount(done)).toBe(20);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `npx jest src/app/reader/magazine/magazine-planner.spec.ts`
Expected: FAIL — `planMagazine` still takes positional arguments.

- [ ] **Step 3: Write the planner**

Replace `magazine-planner.ts`:

```ts
// src/app/reader/magazine/magazine-planner.ts
import { EntryDto } from '../models';
import { entryImage, textSnippet } from '../preview-image';
import { BLOCK_HEIGHT, DEMOTION, EntryKind, MagazineBlock } from './magazine-block';
import { Slot, TEMPLATES } from './magazine-templates';

export interface MagazinePlanInput {
  entries: EntryDto[];
  /** True in aggregated views (All / tag / favorites / kept). */
  grouping: boolean;
  /** False while `hasMore` — a partial trailing page is held back. */
  complete: boolean;
}

/** A source is only grouped while it is a MINORITY of the view. Grouping exists
 *  to stop one chatty feed monopolising an aggregated list; in a single-feed tag
 *  it fired at full strength with nothing to protect against, and hid 84% of the
 *  entries (#148). */
const DOMINANT_SHARE = 0.4;
const GROUP_MIN = 3;
const GROUP_SHOW = 3;
/** A digest consumes its lead plus at most GROUP_SHOW; the rest of the run
 *  flows on as ordinary blocks, so no entry is ever unreachable. */
const GROUP_CONSUMES = GROUP_SHOW + 1;
/** The largest slot may reach this far ahead for an entry that fits it. */
const LOOK_AHEAD = 2;
/** Per-page height ceiling, in BLOCK_HEIGHT units — about one and a half phone
 *  screens. Without it three heroes can land in one page. */
const PAGE_HEIGHT_CAP = 1100;
const QUOTE_MIN_TEXT = 300;

// Amended during execution: dominance is judged over a FIXED LEADING WINDOW, not
// the whole loaded set. A source's share of the full list shrinks as more pages
// load; judging over everything let a front-loaded source flip from dominant to
// minority between renders, regrouping already-shown blocks and breaking prefix
// stability. The window (24) is far smaller than one API page (PAGE_SIZE = 100),
// so every render samples the identical leading entries.
const DOMINANCE_SAMPLE = 24;

export function planMagazine(input: MagazinePlanInput): MagazineBlock[] {
  const { entries, grouping, complete } = input;
  const blocks: MagazineBlock[] = [];
  const dominant = grouping
    ? dominantSources(entries.slice(0, DOMINANCE_SAMPLE))
    : new Set<number>();

  let index = 0;
  let page = 0;

  while (index < entries.length) {
    if (grouping) {
      const run = sameSourceRun(entries, index);
      const source = entries[index].subscriptionId;
      if (run >= GROUP_MIN && !dominant.has(source)) {
        if (!complete && index + run === entries.length) break;
        const consumed = Math.min(run, GROUP_CONSUMES);
        blocks.push(digest(entries.slice(index, index + consumed)));
        index += consumed;
        continue;
      }
    }

    const template = templateFor(page);
    const remaining = entries.length - index;
    if (remaining < template.length && !complete) break;

    const slice = entries.slice(index, index + Math.min(template.length, remaining));
    blocks.push(...layOutPage(template, slice, page));
    index += slice.length;
    page += 1;
  }

  return blocks;
}

/** Sources holding more than DOMINANT_SHARE of the loaded entries. */
function dominantSources(entries: EntryDto[]): Set<number> {
  const counts = new Map<number, number>();
  for (const entry of entries) {
    counts.set(entry.subscriptionId, (counts.get(entry.subscriptionId) ?? 0) + 1);
  }
  const dominant = new Set<number>();
  for (const [id, count] of counts) {
    if (count / entries.length > DOMINANT_SHARE) dominant.add(id);
  }
  return dominant;
}

/** Deterministic and page-indexed, so re-planning a longer list re-emits an
 *  identical prefix. The stride is coprime with the library size, which walks
 *  every template before repeating one. */
function templateFor(page: number): readonly Slot[] {
  return TEMPLATES[(page * 5 + 1) % TEMPLATES.length];
}

/** Cheap deterministic hash. Seeded from the page index for the same reason
 *  the template is: the plan must not change when more entries arrive. */
function seed(page: number, salt: number): number {
  const x = Math.sin(page * 127.1 + salt * 311.7) * 43758.5453;
  return x - Math.floor(x);
}

function resolveSlot(slot: Slot, page: number, position: number): EntryKind {
  if (typeof slot === 'string') return slot;
  return seed(page, position) < 0.5 ? slot.either[0] : slot.either[1];
}

function layOutPage(
  template: readonly Slot[],
  slice: EntryDto[],
  page: number,
): MagazineBlock[] {
  const wanted = template
    .slice(0, slice.length)
    .map((slot, position) => resolveSlot(slot, page, position));
  const budgeted = withinBudget(wanted);
  const assigned = assign(budgeted, slice);

  return assigned.map((kind, position) => toBlock(kind, slice[position], page, position));
}

/** Demote the largest slot until the page fits the height cap. */
function withinBudget(kinds: EntryKind[]): EntryKind[] {
  const result = [...kinds];
  let height = result.reduce((sum, kind) => sum + BLOCK_HEIGHT[kind], 0);

  while (height > PAGE_HEIGHT_CAP) {
    let tallest = 0;
    for (let i = 1; i < result.length; i++) {
      if (BLOCK_HEIGHT[result[i]] > BLOCK_HEIGHT[result[tallest]]) tallest = i;
    }
    const demoted = DEMOTION[result[tallest]];
    if (demoted === result[tallest]) break;
    height -= BLOCK_HEIGHT[result[tallest]] - BLOCK_HEIGHT[demoted];
    result[tallest] = demoted;
  }

  return result;
}

/**
 * Entries fill slots IN ORDER — a reader is chronological by contract. The one
 * exception is the page's tallest slot, which may reach up to LOOK_AHEAD
 * positions ahead for an entry that can actually fill it; that generalises what
 * the old `preferredGroupHero` already did, and is bounded so nothing visibly
 * jumps. Any slot whose entry still cannot fill it demotes TRANSITIVELY.
 */
function assign(kinds: EntryKind[], slice: EntryDto[]): EntryKind[] {
  const order = [...slice];
  let tallest = 0;
  for (let i = 1; i < kinds.length; i++) {
    if (BLOCK_HEIGHT[kinds[i]] > BLOCK_HEIGHT[kinds[tallest]]) tallest = i;
  }

  if (!fits(kinds[tallest], order[tallest])) {
    const limit = Math.min(order.length, tallest + LOOK_AHEAD + 1);
    for (let j = tallest + 1; j < limit; j++) {
      if (fits(kinds[tallest], order[j])) {
        const [picked] = order.splice(j, 1);
        order.splice(tallest, 0, picked);
        break;
      }
    }
  }

  slice.splice(0, slice.length, ...order);

  return kinds.map((kind, position) => settle(kind, order[position]));
}

function settle(kind: EntryKind, entry: EntryDto): EntryKind {
  let current = kind;
  while (!fits(current, entry)) {
    const next = DEMOTION[current];
    if (next === current) return current;
    current = next;
  }
  return current;
}

function fits(kind: EntryKind, entry: EntryDto): boolean {
  const image = entryImage(entry);
  const width = image?.width ?? 0;
  switch (kind) {
    case 'hero':
      // An unknown width is trusted at hero size only when it is the persisted
      // field: an inline <img> from an archive row is a 148px thumbnail as often
      // as not, which is what produced heroes with no picture.
      return !!image && (width >= 500 || (width === 0 && !!entry.imageUrl));
    case 'wide':
      // Amended during execution: an unknown width is trusted only when it is the
      // persisted field, exactly as `hero` does. Trusting `width === 0` outright
      // let an inline archive thumbnail (~148px) fill a full-width band.
      return !!image && (width >= 400 || (width === 0 && !!entry.imageUrl));
    case 'split':
      return !!image && (width >= 300 || (width === 0 && !!entry.imageUrl));
    case 'thumb':
      return !!image;
    case 'quote':
      return textSnippet(entry.summary || entry.contentHtml).length >= QUOTE_MIN_TEXT;
    case 'kicker':
    case 'compact':
      return true;
  }
}

function toBlock(
  kind: EntryKind,
  entry: EntryDto,
  page: number,
  position: number,
): MagazineBlock {
  if (kind === 'split') {
    return { kind, entry, imageSide: seed(page, position + 97) < 0.5 ? 'left' : 'right' };
  }
  return { kind, entry } as MagazineBlock;
}

function digest(items: EntryDto[]): MagazineBlock {
  const shown = Math.min(items.length, GROUP_SHOW);
  return {
    kind: 'group',
    subscriptionId: items[0].subscriptionId,
    source: items[0].source,
    entries: items.slice(0, shown),
    moreCount: items.length - shown,
  };
}

function sameSourceRun(entries: EntryDto[], start: number): number {
  const subscription = entries[start].subscriptionId;
  let length = 1;
  while (
    start + length < entries.length &&
    entries[start + length].subscriptionId === subscription
  ) {
    length += 1;
  }
  return length;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `npx jest src/app/reader/magazine/magazine-planner.spec.ts`
Expected: PASS, 8 tests.

If the entropy test fails, the fix is the **template library**, not the threshold: add templates or add `either` slots. Never lower the floor — the floor is the regression test for the defect this whole branch exists to fix.

- [ ] **Step 5: Gate and commit**

```bash
npm run check
git add frontend/src/app/reader/magazine
git commit -m "feat(magazine): plan by page template with budget, demotion and jitter (#148)"
```

---

## Task 17: Render the new union

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html:106-160`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts`

- [ ] **Step 1: Import the components**

Add `EntrySplitComponent`, `EntryWideComponent`, `EntryThumbComponent`, `EntryQuoteComponent` and `EntryKickerComponent` to the `imports` array of `EntryListComponent`. Change the planner call to the object signature:

```ts
  readonly blocks = computed(() =>
    planMagazine({
      entries: this.entries(),
      grouping: this.selection().kind !== 'subscription',
      complete: !this.hasMore(),
    }),
  );
```

Replace the `hero`/`feat`/`compact`/`grp` narrowing helpers with one generic helper, since seven of the eight kinds now share a shape:

```ts
  /** Narrow a block to its entry-carrying form for the template. */
  entryOf(block: MagazineBlock): EntryDto {
    return (block as Extract<MagazineBlock, { entry: EntryDto }>).entry;
  }

  side(block: MagazineBlock): 'left' | 'right' {
    return block.kind === 'split' ? block.imageSide : 'right';
  }

  grp(block: MagazineBlock): Extract<MagazineBlock, { kind: 'group' }> {
    return block as Extract<MagazineBlock, { kind: 'group' }>;
  }
```

- [ ] **Step 2: Replace the `@switch` body**

```html
      @switch (b.kind) {
        @case ('hero') {
          <app-entry-hero
            [entry]="entryOf(b)"
            [tags]="tagsFor(entryOf(b).subscriptionId)"
            (favorite)="favorite.emit($event)"
            (keep)="keep.emit($event)"
            (read)="read.emit($event)"
            (open)="open.emit($event)"
          />
        }
        @case ('wide') {
          <app-entry-wide
            [entry]="entryOf(b)"
            [tags]="tagsFor(entryOf(b).subscriptionId)"
            (open)="open.emit($event)"
          />
        }
        @case ('quote') {
          <app-entry-quote
            [entry]="entryOf(b)"
            [tags]="tagsFor(entryOf(b).subscriptionId)"
            (open)="open.emit($event)"
          />
        }
        @case ('split') {
          <app-entry-split
            [entry]="entryOf(b)"
            [imageSide]="side(b)"
            [tags]="tagsFor(entryOf(b).subscriptionId)"
            (open)="open.emit($event)"
          />
        }
        @case ('kicker') {
          <app-entry-kicker
            [entry]="entryOf(b)"
            [tags]="tagsFor(entryOf(b).subscriptionId)"
            (open)="open.emit($event)"
          />
        }
        @case ('thumb') {
          <app-entry-thumb
            [entry]="entryOf(b)"
            [tags]="tagsFor(entryOf(b).subscriptionId)"
            (open)="open.emit($event)"
          />
        }
        @case ('compact') {
          <app-entry-compact
            [entry]="entryOf(b)"
            [tags]="tagsFor(entryOf(b).subscriptionId)"
            (open)="open.emit($event)"
          />
        }
        @case ('group') {
          <app-source-group
            [source]="grp(b).source"
            [subscriptionId]="grp(b).subscriptionId"
            [entries]="grp(b).entries"
            [moreCount]="grp(b).moreCount"
            [tags]="tagsFor(grp(b).subscriptionId)"
            (open)="open.emit($event)"
          />
        }
      }
```

- [ ] **Step 3: Update `blockKey`**

`blockKey` must stay stable per block so `@for` tracking does not thrash:

```ts
  blockKey(block: MagazineBlock): string {
    return block.kind === 'group'
      ? `g${block.subscriptionId}:${block.entries[0].id}`
      : `${block.kind}:${block.entry.id}`;
  }
```

- [ ] **Step 4: Run the frontend suite**

Run: `npm run check`
Expected: PASS. `entry-list.component.spec.ts` assertions naming `feature` need renaming to `split`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/entry-list
git commit -m "feat(reader): render the eight magazine block types (#148)"
```

---

## Task 18: Document the blocks

**Files:**
- Modify: `docs/design-language.md`

- [ ] **Step 1: Add the block catalog section**

Add a "Magazine blocks" section listing all eight with their measured mobile height, image proportion and fill condition, matching the table in the spec's §4.3. Record two deliberate exceptions the linter will otherwise flag as smells: the `88px` thumb box and the `38%` split image are **tuned proportions, not spacing values**, and both carry a `stylelint-disable-next-line` comment saying so.

- [ ] **Step 2: Commit**

```bash
git add docs/design-language.md
git commit -m "docs(design): record the eight magazine block types (#148)"
```

---

## Task 19: Verify against real data

- [ ] **Step 1: Bring the stack up and migrate**

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 2: Refresh every feed from the UI**, then check coverage

In the browser console at `http://localhost:4200`:

```js
const t = localStorage.getItem('sfr.jwt');
const j = await (await fetch('https://localhost:8443/api/entries?view=all&limit=100', {
  headers: { Authorization: 'Bearer ' + t },
})).json();
const withImage = j.entries.filter((e) => e.imageUrl).length;
console.log(withImage + ' / ' + j.entries.length);
```

Expected: **at least 90 / 100.** At planning time the client could find an image for 42 of 100. Anything under 90 means a parser path is still dropping the image — check SPIEGEL, BBC and WIRED entries specifically, since all three were at 0%.

- [ ] **Step 3: Check the Tech tag**

Open the Tech tag in magazine layout. Expected: images throughout — it was at 0% before this branch — and no `hero → group → hero → group` alternation.

- [ ] **Step 4: Check the bike tag**

Open the bike tag. Expected: all 25 entries reachable by scrolling, not one hero plus three titles plus a "21 more" link.

- [ ] **Step 5: Check both breakpoints**

Resize to 390px and to 1600px. Expected at 390: roughly 5–6 items per screen, no horizontal scroll. Expected at 1600: the 680px measure is unchanged, and no image is stretched beyond its natural width.

- [ ] **Step 6: Check the log**

Run: `tail -n 200 backend/var/log/dev.log`
Expected: no new deprecations or errors.

---

## Task 20: Final gates and PR

- [ ] **Step 1: Backend, both legs**

```bash
composer check
composer md
php bin/phpunit
docker compose exec php vendor/bin/phpunit
```

Expected: all PASS.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` over every changed PHP file. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 3: Frontend**

```bash
cd frontend && npm run check
```

Expected: PASS.

- [ ] **Step 4: E2E**

```bash
cd backend && composer e2e
```

Run through `composer e2e`, never raw phpunit — Homebrew PHP ignores the macOS keychain, so the script builds a CA bundle containing the mkcert root.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin feature/148-magazine-layout-rework
gh pr create --base develop --title "Magazine layout rework: persist feed images, template-driven rhythm" --body "Closes #148"
```

The PR targets `develop`, never `main`.

- [ ] **Step 6: Confirm the issue closes**

`develop` is the default branch, so `Closes #148` auto-closes on merge. Verify after merging rather than closing by hand.

---

## Self-review notes

Checked against the spec:

- §1.1–1.2 (extraction, variant selection) → Tasks 2, 3
- §1.3 (dimensions) → Tasks 1, 2, 4
- §1.4 (group black hole) → Tasks 14, 16 (`DOMINANT_SHARE`, `GROUP_CONSUMES`)
- §1.5 (fixed cadence) → Tasks 15, 16
- §1.6 (signals) → Task 16 `fits()`
- §1.7 (junk snippets) → Task 5
- §1.8 (layout shift, `srcset`) → Task 10 covers `width`/`height`. **`srcset` is not implemented by any task.** It is listed in the spec's §4.2 as a benefit of multi-variant feeds, but the Guardian is the only feed that ships a usable variant ladder, and `ItemImageExtractor` discards the non-widest candidates rather than keeping the set. Implementing it properly means persisting a variant list, which is a schema decision beyond this branch — treat it as a follow-up issue rather than smuggling it in.
- §2 (template rhythm) → Tasks 15, 16
- §2.1 (degradation, transitive demotion) → Task 16 `settle()`, plus the zero-image test
- §3 decisions 1–11 → all covered
- §4.5 (testing) → Task 16 tests cover prefix stability, entropy floor, degradation and the no-hidden-entries invariant; Tasks 2, 5, 6 cover the backend; Task 4 Steps 3–4 cover the migration on both platforms
