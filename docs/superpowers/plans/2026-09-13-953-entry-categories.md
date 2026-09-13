# Feed-declared entry categories Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse, normalize and store the categories feeds declare per entry (RSS `<category>`, Dublin Core `<dc:subject>`, Atom `<category term=>`), and show them as a muted, comma-separated row in the reader article footer.

**Architecture:** Categories live in two new tables — `category` (global identity: `canonical_key` + `scheme`) and `entry_category` (a composite-key join entity carrying the feed-declared `position` and `label`). A shared `ItemCategoryExtractor` reads the three XML dialects into a new `ParsedEntry::$categories` list; `CategoryNormalizer` canonicalizes and caps them; `EntryCategoryWriter` resolves global `category` rows and writes `entry_category` links only for newly created entries (write-once, matching the existing dedup-skip ingest discipline). On read, `EntryCategoryLoader` batch-loads each page's categories in one query and `EntryJson` emits `categories: string[]`; the Angular reader renders them in the footer.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM (MySQL + SQLite), PHPUnit; Angular 20 (standalone + signals), Jest.

**Spec:** `docs/superpowers/specs/2026-09-13-953-entry-categories-design.md` (issue #953)

## Global Constraints

- Branch `feature/953-entry-categories` off `develop` (already created). Commit format `type(#953): …`.
- `declare(strict_types=1)` in every PHP file. PSR-12. `final readonly class` with constructor promotion is the house style; `final` unless designed for extension.
- Clean Code is mandatory: intent-revealing names, one-thing functions, guard clauses, no boolean-flag params, depend on interfaces. Comments only for a non-obvious invariant — default to none.
- Backend gates on touched files: `composer check` (cs + stan level max + tramp) and `composer md` (PHPMD codesize) must be clean. `ThinControllerRule`: controllers delegate, no private work methods.
- `Entry` is at the PHPMD field-count ceiling (15/15) — **do not add any field to `Entry`**. Categories are read through their own query.
- Migration must be **platform-aware** (branch `AbstractMySQLPlatform` vs `SQLitePlatform`, throw otherwise), `isTransactional(): false`. Tests never run migrations; only CI's migrate-from-empty leg does, then `doctrine:schema:validate`.
- Datetimes are naive UTC (not relevant here, but the "don't rewrite unchanged rows on refresh" discipline is: categories are written once, at entry creation only).
- Backend tests: `php bin/phpunit` (SQLite) natively; parallel runs need `TEST_TOKEN`.
- Frontend: standalone + signals, no NgModules. Component styles in sibling `.scss` (`styleUrl`), never inline. No hex colours / ad-hoc `px` outside `theme/`. Gate: `docker compose exec -T frontend npm test` and `npm run check`.
- WordPress-JSON category capture is **out of scope** (deferred follow-up). Filtering, per-category views, clickable chips are out of scope.

---

### Task 1: `ParsedCategory` VO + `ParsedEntry::$categories`

**Files:**
- Create: `backend/src/Service/Parser/ParsedCategory.php`
- Modify: `backend/src/Service/Parser/ParsedEntry.php:9-22`
- Test: `backend/tests/Service/Parser/ParsedEntryTest.php` (create)

**Interfaces:**
- Produces:
  - `final readonly class ParsedCategory { public function __construct(public string $label, public ?string $scheme = null) {} }`
  - `ParsedEntry` gains a trailing promoted param `public array $categories = []` typed `list<ParsedCategory>`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Parser/ParsedEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ParsedCategory;
use App\Service\Parser\ParsedEntry;
use PHPUnit\Framework\TestCase;

final class ParsedEntryTest extends TestCase
{
    public function testCategoriesDefaultToEmptyList(): void
    {
        $entry = new ParsedEntry('guid', null, 'Title', null, null, null, null);

        self::assertSame([], $entry->categories);
    }

    public function testCategoriesArePreserved(): void
    {
        $entry = new ParsedEntry(
            'guid', null, 'Title', null, null, null, null,
            categories: [new ParsedCategory('Politics', 'https://example.test/tax')],
        );

        self::assertCount(1, $entry->categories);
        self::assertSame('Politics', $entry->categories[0]->label);
        self::assertSame('https://example.test/tax', $entry->categories[0]->scheme);
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Parser/ParsedEntryTest.php`
Expected: FAIL (unknown named argument `$categories` / class `ParsedCategory` not found).

- [ ] **Step 3: Create `ParsedCategory`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * One category a feed declared for an entry, as read from the document: the
 * label shown, and the taxonomy it belongs to when the feed named one (RSS
 * <category domain>, Atom <category scheme>). Normalization happens later.
 */
final readonly class ParsedCategory
{
    public function __construct(
        public string $label,
        public ?string $scheme = null,
    ) {
    }
}
```

- [ ] **Step 4: Add the field to `ParsedEntry`**

Add `use` nothing new; append the promoted param after `$mediaBundle`:

```php
    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
        public ?DeclaredImage $image = null,
        public ?ParsedMediaBundle $mediaBundle = null,
        /** @var list<ParsedCategory> */
        public array $categories = [],
    ) {
    }
```

- [ ] **Step 5: Run test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Parser/ParsedEntryTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Parser/ParsedCategory.php backend/src/Service/Parser/ParsedEntry.php backend/tests/Service/Parser/ParsedEntryTest.php
git commit -m "feat(#953): add ParsedCategory and ParsedEntry.categories"
```

---

### Task 2: `CategoryNormalizer` + `NormalizedCategory`

**Files:**
- Create: `backend/src/Service/Category/NormalizedCategory.php`
- Create: `backend/src/Service/Category/CategoryNormalizer.php`
- Test: `backend/tests/Service/Category/CategoryNormalizerTest.php`

**Interfaces:**
- Consumes: `App\Service\Parser\ParsedCategory`.
- Produces:
  - `final readonly class NormalizedCategory { public function __construct(public string $canonicalKey, public string $displayLabel, public string $scheme) {} public function identity(): string; }` where `identity()` returns `$canonicalKey."\0".$scheme` (the dedup + resolver map key).
  - `final class CategoryNormalizer { /** @param list<ParsedCategory> $raw @return list<NormalizedCategory> */ public function normalize(array $raw): array; }`
- Rules: trim; drop empty labels; `canonicalKey` = `mb_strtolower` of whitespace-collapsed label; absent/empty scheme → `''`; de-duplicate on `identity()` within the entry, first-seen `displayLabel` wins; cap at 30 categories/entry; truncate `displayLabel` and `canonicalKey` to 128 chars, `scheme` to 255.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Category;

use App\Service\Category\CategoryNormalizer;
use App\Service\Parser\ParsedCategory;
use PHPUnit\Framework\TestCase;

final class CategoryNormalizerTest extends TestCase
{
    private CategoryNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CategoryNormalizer();
    }

    public function testTrimsAndDropsEmpty(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory('  Politics  '),
            new ParsedCategory('   '),
            new ParsedCategory(''),
        ]);

        self::assertCount(1, $out);
        self::assertSame('Politics', $out[0]->displayLabel);
        self::assertSame('politics', $out[0]->canonicalKey);
        self::assertSame('', $out[0]->scheme);
    }

    public function testCanonicalKeyLowercasesAndCollapsesWhitespace(): void
    {
        $out = $this->normalizer->normalize([new ParsedCategory("Middle   East\tNews")]);

        self::assertSame('middle east news', $out[0]->canonicalKey);
        self::assertSame("Middle   East\tNews", $out[0]->displayLabel);
    }

    public function testDeduplicatesCaseInsensitivelyKeepingFirstLabel(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory('Politics'),
            new ParsedCategory('POLITICS'),
        ]);

        self::assertCount(1, $out);
        self::assertSame('Politics', $out[0]->displayLabel);
    }

    public function testSameLabelDifferentSchemeStaySeparate(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory('Politics', 'https://a.test'),
            new ParsedCategory('Politics', 'https://b.test'),
        ]);

        self::assertCount(2, $out);
    }

    public function testCapsCountAtThirty(): void
    {
        $raw = [];
        for ($i = 0; $i < 40; $i++) {
            $raw[] = new ParsedCategory('cat' . $i);
        }

        self::assertCount(30, $this->normalizer->normalize($raw));
    }

    public function testTruncatesLongValues(): void
    {
        $out = $this->normalizer->normalize([
            new ParsedCategory(str_repeat('a', 200), str_repeat('s', 400)),
        ]);

        self::assertSame(128, mb_strlen($out[0]->displayLabel));
        self::assertSame(128, mb_strlen($out[0]->canonicalKey));
        self::assertSame(255, mb_strlen($out[0]->scheme));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Category/CategoryNormalizerTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Create `NormalizedCategory`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Category;

final readonly class NormalizedCategory
{
    public function __construct(
        public string $canonicalKey,
        public string $displayLabel,
        public string $scheme,
    ) {
    }

    /** The global identity key: same canonical key AND scheme is the same category. */
    public function identity(): string
    {
        return $this->canonicalKey . "\0" . $this->scheme;
    }
}
```

- [ ] **Step 4: Create `CategoryNormalizer`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Category;

use App\Service\Parser\ParsedCategory;

/**
 * Turns the raw categories a feed declared for one entry into the rows to
 * persist: trimmed, de-duplicated on (canonical key, scheme), capped against
 * abusive feeds. Pure — no I/O.
 */
final class CategoryNormalizer
{
    private const int MAX_PER_ENTRY = 30;
    private const int LABEL_MAX = 128;
    private const int SCHEME_MAX = 255;

    /**
     * @param list<ParsedCategory> $raw
     *
     * @return list<NormalizedCategory>
     */
    public function normalize(array $raw): array
    {
        $byIdentity = [];
        foreach ($raw as $category) {
            $normalized = $this->normalizeOne($category);
            if ($normalized === null) {
                continue;
            }
            $byIdentity[$normalized->identity()] ??= $normalized;
            if (\count($byIdentity) >= self::MAX_PER_ENTRY) {
                break;
            }
        }

        return array_values($byIdentity);
    }

    private function normalizeOne(ParsedCategory $category): ?NormalizedCategory
    {
        $label = trim($category->label);
        if ($label === '') {
            return null;
        }

        $collapsed = (string) preg_replace('/\s+/u', ' ', $label);
        $canonicalKey = mb_substr(mb_strtolower($collapsed), 0, self::LABEL_MAX);

        return new NormalizedCategory(
            $canonicalKey,
            mb_substr($label, 0, self::LABEL_MAX),
            mb_substr(trim($category->scheme ?? ''), 0, self::SCHEME_MAX),
        );
    }
}
```

- [ ] **Step 5: Run test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Category/CategoryNormalizerTest.php`
Expected: PASS (all 6).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Category/ backend/tests/Service/Category/CategoryNormalizerTest.php
git commit -m "feat(#953): add CategoryNormalizer and NormalizedCategory"
```

---

### Task 3: `ItemCategoryExtractor` + wire into the three XML parsers

**Files:**
- Create: `backend/src/Service/Parser/ItemCategoryExtractor.php`
- Modify: `backend/src/Service/Parser/Rss2Parser.php:59-73` (add extraction + pass `categories:`)
- Modify: `backend/src/Service/Parser/Rss1Parser.php:60-72`
- Modify: `backend/src/Service/Parser/AbstractAtomParser.php:98-110`
- Test: `backend/tests/Service/Parser/ItemCategoryExtractorTest.php`

**Interfaces:**
- Consumes: `ParsedCategory`, `XmlHelper::DUBLIN_CORE_NAMESPACE`.
- Produces: `final class ItemCategoryExtractor { /** @return list<ParsedCategory> */ public static function extract(\DOMElement $item): array; }`
- Reads, over direct children of the item/entry:
  - `localName === 'category'` (RSS 2.0 and Atom, any namespace): label = trimmed `term` attribute if present, else trimmed text content; scheme = `scheme` attribute if present, else `domain` attribute, else null. Skip if label empty.
  - `localName === 'subject'` in the Dublin Core namespace (RSS 1.0/DC): label = trimmed text content; scheme = null. Skip if empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemCategoryExtractor;
use PHPUnit\Framework\TestCase;

final class ItemCategoryExtractorTest extends TestCase
{
    private function firstItem(string $xml): \DOMElement
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $item = $doc->getElementsByTagName('*')->item(0);
        \assert($item instanceof \DOMElement);

        // Return the element that actually holds the categories: the wrapper root.
        return $item;
    }

    public function testRss2CategoryTextAndDomain(): void
    {
        $item = $this->firstItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<category domain="https://tax.test">Politics</category>'
            . '<category>World</category>'
            . '</item>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('Politics', $out[0]->label);
        self::assertSame('https://tax.test', $out[0]->scheme);
        self::assertSame('World', $out[1]->label);
        self::assertNull($out[1]->scheme);
    }

    public function testAtomCategoryTermAndScheme(): void
    {
        $item = $this->firstItem(
            '<entry xmlns="http://www.w3.org/2005/Atom">'
            . '<category term="tech" scheme="https://s.test"/>'
            . '</entry>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('tech', $out[0]->label);
        self::assertSame('https://s.test', $out[0]->scheme);
    }

    public function testDublinCoreSubject(): void
    {
        $item = $this->firstItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:subject>Science</dc:subject>'
            . '</item>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('Science', $out[0]->label);
        self::assertNull($out[0]->scheme);
    }

    public function testEmptyCategoriesAreSkipped(): void
    {
        $item = $this->firstItem('<item><category>   </category><category></category></item>');

        self::assertSame([], ItemCategoryExtractor::extract($item));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Parser/ItemCategoryExtractorTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Create `ItemCategoryExtractor`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Reads the categories a feed item declares into a flat list, across the three
 * XML dialects: RSS 2.0 <category> (text, optional domain), Atom <category
 * term= scheme=>, and Dublin Core <dc:subject>. Reads only what the parsed
 * document already holds — no fetch. Normalization happens in CategoryNormalizer.
 */
final class ItemCategoryExtractor
{
    /** @return list<ParsedCategory> */
    public static function extract(\DOMElement $item): array
    {
        $categories = [];
        foreach ($item->childNodes as $child) {
            $category = self::fromChild($child);
            if ($category !== null) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    private static function fromChild(\DOMNode $child): ?ParsedCategory
    {
        if (!$child instanceof \DOMElement) {
            return null;
        }
        if ($child->localName === 'category') {
            return self::fromCategoryElement($child);
        }
        if ($child->localName === 'subject' && $child->namespaceURI === XmlHelper::DUBLIN_CORE_NAMESPACE) {
            $label = trim($child->textContent);

            return $label === '' ? null : new ParsedCategory($label);
        }

        return null;
    }

    private static function fromCategoryElement(\DOMElement $element): ?ParsedCategory
    {
        $term = trim($element->getAttribute('term'));
        $label = $term !== '' ? $term : trim($element->textContent);
        if ($label === '') {
            return null;
        }

        $scheme = trim($element->getAttribute('scheme'));
        if ($scheme === '') {
            $scheme = trim($element->getAttribute('domain'));
        }

        return new ParsedCategory($label, $scheme === '' ? null : $scheme);
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Parser/ItemCategoryExtractorTest.php`
Expected: PASS.

- [ ] **Step 5: Wire into `Rss2Parser::parseItem`**

After the `$mediaBundle = ItemMediaExtractor::extract($item);` line, the `new ParsedEntry(...)` call gains a trailing named arg:

```php
        $mediaBundle = ItemMediaExtractor::extract($item);

        return new ParsedEntry(
            guid: GuidFallback::for(XmlHelper::childText($item, 'guid'), $link, $title),
            url: $link,
            title: PlainText::from($title) ?? '(untitled)',
            author: XmlHelper::childText($item, 'author') ?? XmlHelper::childText($item, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentEncoded ?? $description,
            publishedAt: DateParser::parse(
                XmlHelper::childText($item, 'pubDate') ?? XmlHelper::childText($item, 'date', self::DC_NS),
            ),
            image: $image,
            mediaBundle: $mediaBundle,
            categories: ItemCategoryExtractor::extract($item),
        );
```

- [ ] **Step 6: Wire into `Rss1Parser::parseItem`**

Add `categories: ItemCategoryExtractor::extract($item),` as the trailing named arg of its `new ParsedEntry(...)` (after `mediaBundle: $mediaBundle,`).

- [ ] **Step 7: Wire into `AbstractAtomParser::parseEntry`**

Add `categories: ItemCategoryExtractor::extract($entry),` as the trailing named arg of its `new ParsedEntry(...)` (after `mediaBundle: $mediaBundle,`).

- [ ] **Step 8: Write an end-to-end parser test proving each dialect populates categories**

Add to `backend/tests/Service/Parser/ItemCategoryExtractorTest.php` a method that runs a full feed document through `FeedParser` if a convenient harness exists; otherwise assert via the concrete parser. Minimal version (RSS2 through the parser):

```php
    public function testRss2ParserPopulatesEntryCategories(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
            . '<item><title>A</title><link>https://x.test/a</link>'
            . '<category domain="https://d.test">Politics</category>'
            . '<category>World</category></item></channel></rss>';
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $feed = (new \App\Service\Parser\Rss2Parser())->parse($doc);

        self::assertCount(2, $feed->entries[0]->categories);
        self::assertSame('Politics', $feed->entries[0]->categories[0]->label);
    }
```

- [ ] **Step 9: Run the full parser test file**

Run: `cd backend && php bin/phpunit tests/Service/Parser/ItemCategoryExtractorTest.php tests/Service/Parser/ParsedEntryTest.php`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Parser/ItemCategoryExtractor.php backend/src/Service/Parser/Rss2Parser.php backend/src/Service/Parser/Rss1Parser.php backend/src/Service/Parser/AbstractAtomParser.php backend/tests/Service/Parser/ItemCategoryExtractorTest.php
git commit -m "feat(#953): extract per-entry categories in RSS2, RSS1/DC and Atom parsers"
```

---

### Task 4: `Category` and `EntryCategory` entities + repositories

**Files:**
- Create: `backend/src/Entity/Category.php`
- Create: `backend/src/Entity/EntryCategory.php`
- Create: `backend/src/Repository/CategoryRepository.php`
- Test: `backend/tests/Entity/CategoryTest.php`

**Interfaces:**
- Produces:
  - `Category` (table `category`): `getId(): ?int`, `getCanonicalKey(): string`, `getScheme(): string`, constructor `__construct(string $canonicalKey, string $scheme)`. Unique constraint `uniq_category_key_scheme` on `(canonical_key, scheme)`.
  - `EntryCategory` (table `entry_category`): composite id `entry` (ManyToOne, `ON DELETE CASCADE`) + `category` (ManyToOne); columns `position` (SMALLINT), `label` (VARCHAR 128). Constructor `__construct(Entry $entry, Category $category, int $position, string $label)`; getters `getEntry`, `getCategory`, `getPosition`, `getLabel`.
  - `CategoryRepository::findOneByIdentity(string $canonicalKey, string $scheme): ?Category`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\EntryCategory;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testCategoryExposesIdentity(): void
    {
        $category = new Category('politics', '');

        self::assertNull($category->getId());
        self::assertSame('politics', $category->getCanonicalKey());
        self::assertSame('', $category->getScheme());
    }

    public function testEntryCategoryCarriesPositionAndLabel(): void
    {
        $category = new Category('politics', '');
        $entry = $this->createStub(\App\Entity\Entry::class);

        $link = new EntryCategory($entry, $category, 2, 'Politics');

        self::assertSame($category, $link->getCategory());
        self::assertSame(2, $link->getPosition());
        self::assertSame('Politics', $link->getLabel());
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd backend && php bin/phpunit tests/Entity/CategoryTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Create `Category`**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A feed-declared category, shared globally: one (canonical_key, scheme) is one
 * row across every feed. Grouping and counting join on it; the label a reader
 * sees is the per-entry label on EntryCategory, not this identity row.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_key_scheme', columns: ['canonical_key', 'scheme'])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'canonical_key', length: 128)]
    private string $canonicalKey;

    /**
     * The taxonomy the feed named (RSS domain / Atom scheme), or '' when none.
     * Empty string, never NULL: MySQL and SQLite both treat NULLs as distinct
     * in a unique index, which would let duplicate schemeless rows through.
     */
    #[ORM\Column(length: 255, options: ['default' => ''])]
    private string $scheme;

    public function __construct(string $canonicalKey, string $scheme)
    {
        $this->canonicalKey = $canonicalKey;
        $this->scheme = $scheme;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCanonicalKey(): string
    {
        return $this->canonicalKey;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }
}
```

- [ ] **Step 4: Create `EntryCategory`**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The entry↔category link, a promoted many-to-many join so it can carry the
 * feed-declared order and the label this feed used for this entry. No collection
 * lives on Entry (which is at its field ceiling); the link is read by its own
 * query.
 */
#[ORM\Entity]
#[ORM\Table(name: 'entry_category')]
class EntryCategory
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(name: 'entry_id', onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id')]
    private Category $category;

    #[ORM\Column(type: 'smallint')]
    private int $position;

    #[ORM\Column(length: 128)]
    private string $label;

    public function __construct(Entry $entry, Category $category, int $position, string $label)
    {
        $this->entry = $entry;
        $this->category = $category;
        $this->position = $position;
        $this->label = $label;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
```

- [ ] **Step 5: Create `CategoryRepository`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
final class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findOneByIdentity(string $canonicalKey, string $scheme): ?Category
    {
        return $this->findOneBy(['canonicalKey' => $canonicalKey, 'scheme' => $scheme]);
    }
}
```

- [ ] **Step 6: Run test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Entity/CategoryTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Entity/Category.php backend/src/Entity/EntryCategory.php backend/src/Repository/CategoryRepository.php backend/tests/Entity/CategoryTest.php
git commit -m "feat(#953): add Category and EntryCategory entities"
```

---

### Task 5: Migration for `category` and `entry_category`

**Files:**
- Create: `backend/migrations/Version<timestamp>.php` (use the current UTC timestamp, format `YYYYMMDDHHMMSS`)

**Interfaces:** none (schema only). Depends on Task 4 entities existing.

- [ ] **Step 1: Confirm the ORM sees the new tables**

Run: `cd backend && bin/console doctrine:migrations:diff --no-interaction`
This generates a migration with the canonical DDL Doctrine wants. Read it, copy the exact table/column/index/FK names it produced, then **delete that generated file** — you will hand-write the platform-aware version so it also runs on SQLite.

```bash
rm backend/migrations/Version<the-diff-timestamp>.php
```

- [ ] **Step 2: Write the platform-aware migration**

Create `backend/migrations/Version<newtimestamp>.php` (pick a timestamp later than every existing one):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create category and entry_category (#953): the feed-declared per-entry
 * categories, normalized. PLATFORM-AWARE DDL — SQLite's INTEGER PRIMARY KEY
 * AUTOINCREMENT is not valid MySQL. Tests build schema from ORM metadata and
 * never run a migration, so a dialect error here is caught only by CI's
 * migrate-from-empty leg.
 */
final class Version<newtimestamp> extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create category and entry_category for feed-declared entry categories (#953)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('category'), 'category already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE category (
                    id INT AUTO_INCREMENT NOT NULL,
                    canonical_key VARCHAR(128) NOT NULL,
                    scheme VARCHAR(255) DEFAULT '' NOT NULL,
                    UNIQUE INDEX uniq_category_key_scheme (canonical_key, scheme),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql(<<<'SQL'
                CREATE TABLE entry_category (
                    entry_id INT NOT NULL,
                    category_id INT NOT NULL,
                    position SMALLINT NOT NULL,
                    label VARCHAR(128) NOT NULL,
                    INDEX IDX_entry_category_category (category_id),
                    PRIMARY KEY (entry_id, category_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql('ALTER TABLE entry_category ADD CONSTRAINT FK_entry_category_entry FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE entry_category ADD CONSTRAINT FK_entry_category_category FOREIGN KEY (category_id) REFERENCES category (id)');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE category (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    canonical_key VARCHAR(128) NOT NULL,
                    scheme VARCHAR(255) DEFAULT '' NOT NULL
                )
                SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_category_key_scheme ON category (canonical_key, scheme)');
            $this->addSql(<<<'SQL'
                CREATE TABLE entry_category (
                    entry_id INTEGER NOT NULL,
                    category_id INTEGER NOT NULL,
                    position SMALLINT NOT NULL,
                    label VARCHAR(128) NOT NULL,
                    PRIMARY KEY (entry_id, category_id),
                    CONSTRAINT FK_entry_category_entry FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                    CONSTRAINT FK_entry_category_category FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE
                )
                SQL);
            $this->addSql('CREATE INDEX IDX_entry_category_category ON entry_category (category_id)');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the entry categories migration.');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('entry_category'), 'entry_category does not exist.');
        $this->addSql('DROP TABLE entry_category');
        $this->addSql('DROP TABLE category');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
```

Adjust index/constraint names ONLY if the Step-1 diff produced different canonical names — `doctrine:schema:validate` compares against the ORM mapping, so they must match what Doctrine expects.

- [ ] **Step 3: Apply and validate on SQLite (native)**

```bash
cd backend
bin/console doctrine:database:create --env=test 2>/dev/null || true
bin/console doctrine:migrations:migrate --no-interaction --env=test
bin/console doctrine:schema:validate --env=test
```
Expected: migration runs; schema:validate reports the mapping in sync (or only pre-existing, unrelated warnings).

- [ ] **Step 4: Apply and validate on MySQL (Docker)**

```bash
docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php bin/console doctrine:schema:validate
```
Expected: both tables created; schema in sync.

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/Version<newtimestamp>.php
git commit -m "feat(#953): migration for category and entry_category tables"
```

---

### Task 6: `EntryCategoryWriter` — resolve globally, write links once

**Files:**
- Create: `backend/src/Service/Ingest/EntryCategoryWriter.php`
- Test: `backend/tests/Service/Ingest/EntryCategoryWriterTest.php` (integration, extends the project's Kernel test base)

**Interfaces:**
- Consumes: `CategoryNormalizer`, `CategoryRepository`, `EntityManagerInterface`, `App\Entity\Entry`, `App\Service\Parser\ParsedEntry`.
- Produces: `final class EntryCategoryWriter { /** @param list<array{0: Entry, 1: ParsedEntry}> $pairs */ public function attach(array $pairs): void; }`
- Behavior: normalize each entry's `ParsedEntry->categories`; resolve every distinct `NormalizedCategory` to a managed `Category` (in-process cache keyed by `identity()`; DB `findOneByIdentity` on miss; `persist` + flush new ones once so identities exist before links are written); then persist one `EntryCategory(entry, category, position, displayLabel)` per normalized category, `position` being its index. No final flush (the ingest caller flushes entries and links together). Concurrency note: a rare cross-process race can fail on the category insert's unique index; the feed's refresh retries next cycle. The unique constraint guarantees no duplicate rows.

- [ ] **Step 1: Write the failing integration test**

Look at an existing integration test that boots the kernel and has a persisted `Feed`/`Entry` (e.g. under `backend/tests/Service/Ingest/` or `tests/Repository/`) to copy the base class and fixture helpers. Then:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Service\Ingest\EntryCategoryWriter;
use App\Service\Parser\ParsedCategory;
use App\Service\Parser\ParsedEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntryCategoryWriterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EntryCategoryWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->writer = self::getContainer()->get(EntryCategoryWriter::class);
    }

    private function persistEntry(Feed $feed, string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($feed, $guid, 'https://x.test/' . $guid, 'T', $now, $now, null);
        $this->em->persist($entry);

        return $entry;
    }

    private function persistFeed(): Feed
    {
        $feed = new Feed('https://feed.test/' . uniqid());
        $this->em->persist($feed);

        return $feed;
    }

    private function parsed(string $guid, ParsedCategory ...$cats): ParsedEntry
    {
        return new ParsedEntry($guid, null, 'T', null, null, null, null, categories: array_values($cats));
    }

    public function testWritesLinksAndCreatesCategories(): void
    {
        $feed = $this->persistFeed();
        $entry = $this->persistEntry($feed, 'a');

        $this->writer->attach([[$entry, $this->parsed('a', new ParsedCategory('Politics'), new ParsedCategory('World'))]]);
        $this->em->flush();
        $this->em->clear();

        $links = $this->em->getRepository(EntryCategory::class)->findBy(['entry' => $entry->getId()]);
        self::assertCount(2, $links);
        self::assertSame(['Politics', 'World'], array_map(static fn (EntryCategory $l) => $l->getLabel(), $links));
    }

    public function testTwoFeedsShareOneGlobalCategoryRow(): void
    {
        $feedA = $this->persistFeed();
        $feedB = $this->persistFeed();
        $entryA = $this->persistEntry($feedA, 'a');
        $entryB = $this->persistEntry($feedB, 'b');

        $this->writer->attach([[$entryA, $this->parsed('a', new ParsedCategory('Politics'))]]);
        $this->em->flush();
        $this->writer->attach([[$entryB, $this->parsed('b', new ParsedCategory('POLITICS'))]]);
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(Category::class)->findBy(['canonicalKey' => 'politics']));
    }
}
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Ingest/EntryCategoryWriterTest.php`
Expected: FAIL (service not found).

- [ ] **Step 3: Create `EntryCategoryWriter`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Repository\CategoryRepository;
use App\Service\Category\CategoryNormalizer;
use App\Service\Category\NormalizedCategory;
use App\Service\Parser\ParsedEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes the feed-declared categories of newly created entries: resolves each
 * to a globally shared Category row, then links it with the feed's declared
 * order and label. Write-once — only called for entries the ingest just
 * created, never on refresh of an existing entry.
 */
final class EntryCategoryWriter
{
    /** @var array<string, Category> */
    private array $resolved = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categories,
        private readonly CategoryNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<array{0: Entry, 1: ParsedEntry}> $pairs
     */
    public function attach(array $pairs): void
    {
        $normalizedByPair = [];
        $needed = [];
        foreach ($pairs as $index => [, $parsedEntry]) {
            $normalized = $this->normalizer->normalize($parsedEntry->categories);
            $normalizedByPair[$index] = $normalized;
            foreach ($normalized as $category) {
                $needed[$category->identity()] = $category;
            }
        }

        if ($needed === []) {
            return;
        }

        $this->resolveAll($needed);

        foreach ($pairs as $index => [$entry]) {
            foreach ($normalizedByPair[$index] as $position => $category) {
                $this->em->persist(new EntryCategory(
                    $entry,
                    $this->resolved[$category->identity()],
                    $position,
                    $category->displayLabel,
                ));
            }
        }
    }

    /**
     * @param array<string, NormalizedCategory> $needed
     */
    private function resolveAll(array $needed): void
    {
        $created = false;
        foreach ($needed as $identity => $category) {
            if (isset($this->resolved[$identity])) {
                continue;
            }
            $existing = $this->categories->findOneByIdentity($category->canonicalKey, $category->scheme);
            if ($existing !== null) {
                $this->resolved[$identity] = $existing;
                continue;
            }
            $new = new Category($category->canonicalKey, $category->scheme);
            $this->em->persist($new);
            $this->resolved[$identity] = $new;
            $created = true;
        }

        if ($created) {
            $this->em->flush();
        }
    }
}
```

- [ ] **Step 4: Run test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Ingest/EntryCategoryWriterTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ingest/EntryCategoryWriter.php backend/tests/Service/Ingest/EntryCategoryWriterTest.php
git commit -m "feat(#953): resolve global categories and write entry links once"
```

---

### Task 7: Wire `EntryCategoryWriter` into `EntryIngestor`

**Files:**
- Modify: `backend/src/Service/Ingest/EntryIngestor.php:42-48` (constructor), `:71-103` (loop + call)
- Test: `backend/tests/Service/Ingest/EntryIngestorCategoriesTest.php` (or extend an existing ingest integration test)

**Interfaces:**
- Consumes: `EntryCategoryWriter::attach()`.
- Produces: no signature change to `ingest()`; new entries get their categories written; refreshed (deduplicated) entries do not.

- [ ] **Step 1: Write the failing test — create, then no-churn on re-ingest**

Copy the ingest integration base from an existing test (one that builds a `ParsedFeed` and calls `EntryIngestor::ingest`). Assert:

```php
    public function testIngestWritesCategoriesForNewEntriesAndDoesNotChurnOnReingest(): void
    {
        // Arrange: a ParsedFeed with one entry carrying two categories.
        // (Use the project's existing ParsedFeed/ParsedEntry builders + a persisted Feed.)
        $parsed = $this->parsedFeedWithEntry('guid-1', [
            new \App\Service\Parser\ParsedCategory('Politics'),
            new \App\Service\Parser\ParsedCategory('World'),
        ]);

        $this->ingestor->ingest($this->feed, $parsed, $this->context());
        $this->em->flush();

        $categoryCount = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM category');
        $linkCount = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM entry_category');
        self::assertSame(2, $categoryCount);
        self::assertSame(2, $linkCount);

        // Re-ingest the SAME feed (entry deduplicates): no new rows, no churn.
        $this->ingestor->ingest($this->feed, $parsed, $this->context());
        $this->em->flush();

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM category'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM entry_category'));
    }
```

- [ ] **Step 2: Run test, verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Ingest/EntryIngestorCategoriesTest.php`
Expected: FAIL (0 categories — writer not wired).

- [ ] **Step 3: Inject the writer**

Add to the constructor:

```php
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entryRepository,
        private readonly EntrySanitizer $sanitizer,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly EntryCategoryWriter $categoryWriter,
    ) {
    }
```

Add `use App\Service\Ingest\EntryCategoryWriter;` is unnecessary (same namespace) — `EntryCategoryWriter` is in `App\Service\Ingest`.

- [ ] **Step 4: Collect new pairs in the loop and attach after it**

In `ingest()`, collect each created entry with its parsed source, then attach once after the loop:

```php
        $created = [];
        $newPairs = [];
        foreach ($parsed->entries as $parsedEntry) {
            $guidHash = self::guidHash($parsedEntry->guid);
            $urlHash = $this->urlHash($parsedEntry->url);
            if ($deduplicator->isDuplicate($guidHash, $urlHash)) {
                continue;
            }
            $deduplicator->remember($guidHash, $urlHash);

            $entry = new Entry(/* … unchanged … */);
            // … existing setAuthor/setSummary/setContentHtml/setPublishedAt/applyImage/applyMedia …
            $this->em->persist($entry);
            $created[] = $entry;
            $newPairs[] = [$entry, $parsedEntry];
        }

        $this->categoryWriter->attach($newPairs);

        return $created;
```

- [ ] **Step 5: Run test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Ingest/EntryIngestorCategoriesTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full ingest + parser suites for regressions**

Run: `cd backend && php bin/phpunit tests/Service/Ingest tests/Service/Parser`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Ingest/EntryIngestor.php backend/tests/Service/Ingest/EntryIngestorCategoriesTest.php
git commit -m "feat(#953): write entry categories during ingest, write-once"
```

---

### Task 8: Read path — batch loader, `EntryListRow.categories`, `EntryJson`

**Files:**
- Modify: `backend/src/Repository/EntryListRow.php` (add `categories` + `withCategories()`)
- Create: `backend/src/Repository/EntryCategoryLoader.php`
- Modify: `backend/src/Http/EntryJson.php:12-53`
- Modify: `backend/src/Controller/Api/EntryController.php` (list + get: enrich rows)
- Test: `backend/tests/Repository/EntryCategoryLoaderTest.php`, and update any `EntryJson` shape assertions.

**Interfaces:**
- Produces:
  - `EntryListRow` gains trailing `public array $categories = []` (`list<string>`) and `withCategories(array $categories): self`.
  - `EntryCategoryLoader::loadInto(array $rows): array` — takes and returns `list<EntryListRow>`, each enriched (including its `duplicates`), using one query. Injected `EntityManagerInterface`.
  - `EntryJson::one` emits `'categories' => $row->categories` and adds `categories: list<string>` to its `@return` shape.

- [ ] **Step 1: Add `categories` + `withCategories()` to `EntryListRow`**

Add the field as the last constructor param (after `$duplicates`):

```php
        /** @var list<self> */
        public array $duplicates = [],
        /** @var list<string> the feed-declared category labels, in declared order */
        public array $categories = [],
```

Add the wither next to `withDuplicates()` (mirror its exact reconstruction, passing `categories: $this->categories` in `withDuplicates` and `duplicates: $this->duplicates` here):

```php
    /** @param list<string> $categories */
    public function withCategories(array $categories): self
    {
        return new self(
            $this->entry,
            new EntryListRowSubscription($this->subscriptionId, $this->subscriptionTitle),
            $this->isHidden,
            $this->isFavorite,
            $this->isKept,
            $this->isViewed,
            $this->viewedAt,
            $this->markedReadUntil,
            $this->duplicates,
            $categories,
        );
    }
```

Also update the existing `withDuplicates()` to carry `categories` through (append `$this->categories` as the final arg of its `new self(...)`), so enriching order never drops data.

- [ ] **Step 2: Write the failing loader test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Repository\EntryCategoryLoader;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntryCategoryLoaderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EntryCategoryLoader $loader;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loader = self::getContainer()->get(EntryCategoryLoader::class);
    }

    public function testLoadsCategoriesInDeclaredOrder(): void
    {
        $feed = new Feed('https://f.test/' . uniqid());
        $this->em->persist($feed);
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($feed, 'g', 'https://x.test/g', 'T', $now, $now, null);
        $this->em->persist($entry);
        $politics = new Category('politics', '');
        $world = new Category('world', '');
        $this->em->persist($politics);
        $this->em->persist($world);
        $this->em->persist(new EntryCategory($entry, $world, 1, 'World'));
        $this->em->persist(new EntryCategory($entry, $politics, 0, 'Politics'));
        $this->em->flush();

        $row = new EntryListRow($entry, new EntryListRowSubscription(1, 'S'), false, false, false, false, null, null);
        $out = $this->loader->loadInto([$row]);

        self::assertSame(['Politics', 'World'], $out[0]->categories);
    }

    public function testEntryWithoutCategoriesGetsEmptyList(): void
    {
        $feed = new Feed('https://f.test/' . uniqid());
        $this->em->persist($feed);
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($feed, 'g2', 'https://x.test/g2', 'T', $now, $now, null);
        $this->em->persist($entry);
        $this->em->flush();

        $row = new EntryListRow($entry, new EntryListRowSubscription(1, 'S'), false, false, false, false, null, null);
        $out = $this->loader->loadInto([$row]);

        self::assertSame([], $out[0]->categories);
    }
}
```

- [ ] **Step 3: Run test, verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/EntryCategoryLoaderTest.php`
Expected: FAIL (loader not found).

- [ ] **Step 4: Create `EntryCategoryLoader`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Batch-loads the feed-declared category labels for a page of entry rows in a
 * single query, so the list, single-entry and recommendation responses can
 * carry them without an N+1. Labels come back in the feed-declared order
 * (entry_category.position).
 */
final class EntryCategoryLoader
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function loadInto(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $labelsByEntryId = $this->labelsFor($this->entryIdsOf($rows));

        return array_map(
            fn (EntryListRow $row): EntryListRow => $this->enrich($row, $labelsByEntryId),
            $rows,
        );
    }

    /**
     * @param array<int, list<string>> $labelsByEntryId
     */
    private function enrich(EntryListRow $row, array $labelsByEntryId): EntryListRow
    {
        $enrichedDuplicates = array_map(
            fn (EntryListRow $duplicate): EntryListRow => $this->enrich($duplicate, $labelsByEntryId),
            $row->duplicates,
        );

        return $row
            ->withDuplicates($enrichedDuplicates)
            ->withCategories($labelsByEntryId[$row->entry->getId()] ?? []);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private function entryIdsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row->entry->getId();
            if ($id !== null) {
                $ids[$id] = $id;
            }
            foreach ($row->duplicates as $duplicate) {
                $duplicateId = $duplicate->entry->getId();
                if ($duplicateId !== null) {
                    $ids[$duplicateId] = $duplicateId;
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<string>>
     */
    private function labelsFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int, label: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(ec.entry) AS entryId', 'ec.label AS label')
            ->from(EntryCategory::class, 'ec')
            ->where('ec.entry IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->orderBy('ec.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byEntry = [];
        foreach ($rows as $row) {
            $byEntry[(int) $row['entryId']][] = $row['label'];
        }

        return $byEntry;
    }
}
```

Note: if `getArrayResult()` types come back loosely, cast `entryId` to int (done) and keep `label` as string. If PHPStan complains about the shape, add a local `@var` as shown.

- [ ] **Step 5: Run loader test, verify it passes**

Run: `cd backend && php bin/phpunit tests/Repository/EntryCategoryLoaderTest.php`
Expected: PASS (both).

- [ ] **Step 6: Emit `categories` from `EntryJson`**

Add to the `@return` array shape (after `attachments`): `categories: list<string>,`. Add to the returned array (after the `attachments` line):

```php
            'attachments' => EntryMedia::toJsonList($e->getAttachments()),
            'categories' => $row->categories,
```

- [ ] **Step 7: Enrich rows in `EntryController`**

Inject the loader:

```php
    public function __construct(
        private EntryListRepository $entryList,
        private EntryCategoryLoader $categoryLoader,
        private EntryStateUpdater $entryStateUpdater,
        private MarkReadService $markRead,
        private ForYouFeedResponder $forYouFeed,
        private ForYouMarkReadService $forYouMarkRead,
    ) {
    }
```

Add `use App\Repository\EntryCategoryLoader;`.

In `list()`, wrap the row list:

```php
        return new JsonResponse(EntryPage::of(
            $this->categoryLoader->loadInto($this->entryList->listForUser($query)),
            $query->limit,
            EntryListSort::forView($view),
        ));
```

In `get()`:

```php
        $row = $this->entryList->oneRowForUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');
        $row = $this->categoryLoader->loadInto([$row])[0];

        return new JsonResponse(['entry' => EntryJson::one($row)]);
```

Note the `ThinControllerRule`: `loadInto` is a single delegating call, not private logic, so the controller stays thin.

- [ ] **Step 8: Extend the for-you / recommendation path (third consumer)**

Read `backend/src/Service/Recommendation/ForYouFeedResponder.php` and `backend/src/Http/RecommendationFeedJson.php`. Where the `RecommendationFeedRow` list is built into `EntryJson::one($row->row)`, enrich the underlying `EntryListRow`s with `EntryCategoryLoader` first (inject it into the responder). If the row wrapper does not expose a settable `EntryListRow`, map through the loader before wrapping. Add a small integration assertion that a for-you entry with categories serializes them. (If the responder's shape makes this a larger change than the footer needs, note it in the PR and cover list + single-entry only — the footer's primary sources — leaving a `// #953 follow-up` for for-you.)

- [ ] **Step 9: Fix any existing `EntryJson` shape assertions**

Run: `cd backend && php bin/phpunit tests/Http tests/Controller/Api/EntryControllerTest.php 2>/dev/null; php bin/phpunit tests/Controller`
Any test asserting the exact `EntryJson::one` array now needs `'categories' => []` added. Update them to expect the new key.

- [ ] **Step 10: Run the read-path suites**

Run: `cd backend && php bin/phpunit tests/Repository tests/Http tests/Controller/Api`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add backend/src/Repository/EntryListRow.php backend/src/Repository/EntryCategoryLoader.php backend/src/Http/EntryJson.php backend/src/Controller/Api/EntryController.php backend/tests
git commit -m "feat(#953): batch-load and expose entry categories in the API"
```

---

### Task 9: Frontend — `EntryDto.categories` + reader footer

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`EntryDto`)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.html` (before `</article>`, ~line 232)
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.scss` (add `.categories`)
- Test: `frontend/src/app/reader/reader-view/reader-view.component.spec.ts`
- Modify: any shared entry test factory/mock that constructs `EntryDto` (add `categories: []`).

**Interfaces:**
- `EntryDto` gains `categories: string[]` (always sent by the API, empty when none — mirrors `media`/`attachments`).

- [ ] **Step 1: Add the field to `EntryDto`**

After the `attachments: EntryAttachmentDto[];` block:

```ts
  /** Feed-declared category labels, in declared order (#953). Always sent by
   *  the API, empty when the feed declared none. */
  categories: string[];
```

- [ ] **Step 2: Update the shared entry factory/mock**

Search for where tests build an `EntryDto` (e.g. a `makeEntry` helper or inline object literals in specs). Add `categories: []` to the factory's defaults so existing specs compile. Run:

```bash
grep -rn "media: \[\], attachments: \[\]\|attachments: \[\]" frontend/src/app | head
```
Add `categories: []` alongside those defaults.

- [ ] **Step 3: Write the failing component test**

In `reader-view.component.spec.ts`, following the file's existing harness (it already renders an entry), add:

```ts
  it('renders feed-declared categories as a comma-separated footer row', async () => {
    // set the component's entry input to one with categories (use the spec's
    // existing entry factory + { categories: ['Politics', 'World'] })
    // then detect changes and query the footer.
    const row = fixture.nativeElement.querySelector('.categories');
    expect(row?.textContent?.trim()).toBe('Politics, World');
  });

  it('omits the categories row when there are none', async () => {
    // entry factory + { categories: [] }
    expect(fixture.nativeElement.querySelector('.categories')).toBeNull();
  });
```

Adapt the entry-setting lines to the spec file's actual pattern (input signal set + `fixture.detectChanges()` / `await fixture.whenStable()`).

- [ ] **Step 4: Run test, verify it fails**

Run: `docker compose exec -T frontend npx jest reader-view.component --silent`
Expected: FAIL (`.categories` not found).

- [ ] **Step 5: Add the footer row to the template**

Immediately before the closing `</article>` (after the content `@if` block, ~line 232):

```html
      @if (e.categories.length) {
        <p class="categories">{{ e.categories.join(', ') }}</p>
      }
    </article>
```

- [ ] **Step 6: Style it like the meta row**

Append to `reader-view.component.scss`:

```scss
/* Feed-declared categories (#953): a muted footnote line closing the article,
   the same weight as the meta row that opens it. */
.categories {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  margin: var(--space-3) 0 0;
}
```

- [ ] **Step 7: Run test, verify it passes**

Run: `docker compose exec -T frontend npx jest reader-view.component --silent`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-view/reader-view.component.html frontend/src/app/reader/reader-view/reader-view.component.scss frontend/src/app/reader/reader-view/reader-view.component.spec.ts
git commit -m "feat(#953): show feed-declared categories in the reader footer"
```

---

### Task 10: Full gates + verification

**Files:** none (verification only).

- [ ] **Step 1: Backend gates on touched files**

Run: `cd backend && composer check && composer md`
Expected: clean. Fix any PHPMD codesize / PHPStan / cs findings in touched files (design the metric away, do not tune thresholds).

- [ ] **Step 2: Full backend suite (SQLite)**

Run: `cd backend && php bin/phpunit`
Expected: green.

- [ ] **Step 3: Backend suite (MySQL leg)**

Run: `docker compose exec php composer test`
Expected: green.

- [ ] **Step 4: Scan today's dev log for deprecations/swallowed errors**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq -r 'select(.level_name!="DEBUG") | .message' 2>/dev/null | tail -30`
Expected: nothing new attributable to this change.

- [ ] **Step 5: Frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: green (ESLint + Prettier 100-col + Stylelint + Jest).

- [ ] **Step 6: Mutation testing on changed files**

Run: `cd backend && composer infection:diff`
Expected: meets `minMsi`. Kill escaped mutants with real assertions (they arrive as annotations on the offending line).

- [ ] **Step 7: PhpStorm inspections on changed PHP**

Use `mcp__phpstorm__lint_files` on the changed PHP files; block on ERROR and WARNING.

- [ ] **Step 8: Manual smoke in the running app**

Open the Docker stack reader, open an article from a category-rich feed (e.g. The Guardian, netzpolitik, Ars Technica), and confirm the muted comma-separated row appears in the footer; open a Substack article and confirm no row appears.

---

## Self-Review notes (author)

- **Spec coverage:** parse (Task 3) · normalize (Task 2) · store: entities (4) + migration (5) + write-once ingest (6, 7) · global identity + scheme='' (4, 6) · read/API (8) · footer UI (9) · tests + gates (each task + 10). WordPress-JSON explicitly deferred. All spec sections map to a task.
- **Type consistency:** `NormalizedCategory.identity()` is the single dedup/resolve key everywhere; `EntryCategory(entry, category, position, label)` used identically in Tasks 4/6/8; `EntryListRow.withCategories(list<string>)` matches `EntryJson`’s `categories: list<string>` and `EntryDto.categories: string[]`.
- **Known follow-ups:** for-you/recommendation and saved-search footers may show no categories until Task 8 Step 8 is fully wired; capped scope is acceptable and noted for the PR.
