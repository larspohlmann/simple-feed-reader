# Title-less posts (#1495) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A feed item without a `<title>` (Mastodon, Bluesky, any microblog) gets a title derived from its text, the entry remembers that its title was derived, and the reader and the SPA treat such an entry as a post.

**Architecture:** A pure text helper derives the title; the four feed parsers use it in place of their `'(untitled)'` fallback and set `ParsedEntryModel::$titleDerived`. Ingest stores the flag in `entry.title_derived`, backup carries it, `EntryJson` exposes it. The reader answers `feed_body_is_post` without fetching; the SPA skips the reader request and shows the post text in the headline slot of list rows and magazine blocks.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (backend), Angular 20 signals + Jest (frontend).

**Spec:** `docs/superpowers/specs/2026-10-10-1495-title-less-posts-design.md`

## Global Constraints

- Generic, no host list: the trigger is the item's shape (no title, a non-empty body), never the host.
- Hybrid model: the derived title is stored in `entry.title` like any title; the flag is `titleDerived` (PHP property and JSON field), column `title_derived`.
- No backfill: entries already stored as `(untitled)` stay as they are.
- `GuidFallback` keeps receiving the raw feed title (null for a title-less item): guids must not change.
- Derived title: at most 80 characters, cut at a word boundary, `…` appended when cut.
- New reader failure reason wire value: `feed_body_is_post`.
- Out of scope: Bluesky AppView media (#1499); the `[contains quote post or other embedded content]` placeholder stays in the body.
- House rules (CLAUDE.md): `final readonly` / `final` static helpers with a private constructor in `Support/`, guard clauses, no abbreviations, default to no comment, `declare(strict_types=1)`, PHPMD-clean touched files.
- Commits: `type(#1495): lower-case summary`, no attribution lines.
- Gates per backend task: the task's tests, then `composer check` and `composer md` from `backend/`. Frontend: `docker compose exec -T frontend npm run check` (or `npm run check` natively plus Jest in the container).

---

### Task 1: Derive a title from a post's text

**Files:**
- Modify: `backend/src/Service/Text/Support/PlainText.php`
- Create: `backend/src/Service/Text/Support/DerivedTitle.php`
- Test: `backend/tests/Service/Text/Support/PlainTextTest.php`
- Test: `backend/tests/Service/Text/Support/DerivedTitleTest.php`

**Interfaces:**
- Produces: `PlainText::linesFromHtmlBlocks(?string $html): list<string>` and `DerivedTitle::from(?string $bodyHtml): ?string`.

- [ ] **Step 1: Write the failing tests**

Append to `PlainTextTest`:

```php
    public function testLinesFromHtmlBlocksGivesEachBlockAndLineBreakItsOwnLine(): void
    {
        self::assertSame(
            ['RE: https://example.social/@a/1', 'Have you taken the survey yet?', 'Second line'],
            PlainText::linesFromHtmlBlocks(
                "<p>RE: <a href=\"https://example.social/@a/1\">https://example.social/@a/1</a></p>"
                . "<p>Have you taken\n the <em>survey</em> yet?<br />Second line</p><p> </p>",
            ),
        );
    }

    public function testLinesFromHtmlBlocksOfNothingIsNoLines(): void
    {
        self::assertSame([], PlainText::linesFromHtmlBlocks(null));
        self::assertSame([], PlainText::linesFromHtmlBlocks('<p></p>'));
    }
```

Create `DerivedTitleTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\DerivedTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DerivedTitleTest extends TestCase
{
    /** @return iterable<string, array{string|null, string|null}> */
    public static function bodies(): iterable
    {
        yield 'first sentence of the first line' => [
            '<p>Big video update! You can now post videos up to 10 minutes long.</p>',
            'Big video update!',
        ];
        yield 'a line without a sentence end is taken whole' => [
            '<p>a masterclass in alt text</p><p>[contains quote post or other embedded content]</p>',
            'a masterclass in alt text',
        ];
        yield 'only the first line counts' => [
            '<p>Update: group chats can now host up to 100 members<br>More soon</p>',
            'Update: group chats can now host up to 100 members',
        ];
        yield 'a dot inside a word ends no sentence' => [
            '<p>v1.132 is live! Starter packs are easier to find.</p>',
            'v1.132 is live!',
        ];
        yield 'a quote-post line of a label and a link is skipped' => [
            '<p>RE: <a href="https://graz.social/@linos/1">https://graz.social/@linos/1</a></p>'
                . '<p>Congratulations to everyone involved.</p>',
            'Congratulations to everyone involved.',
        ];
        yield 'a line holding only a link is skipped' => [
            '<p>https://example.com/a</p><p>Read this.</p>',
            'Read this.',
        ];
        yield 'a line holding only emoji is skipped' => ['<p>🎉🎉</p><p>We shipped it</p>', 'We shipped it'];
        yield 'a long sentence is cut at a word boundary' => [
            '<p>Today we are sharing additional guidelines about our Trade Mark Policy based on community '
                . 'feedback that we received.</p>',
            'Today we are sharing additional guidelines about our Trade Mark Policy based…',
        ];
        yield 'a long word with no space is cut hard' => [
            '<p>' . str_repeat('a', 100) . '</p>',
            str_repeat('a', 79) . '…',
        ];
        yield 'entities are decoded' => ['<p>Fish &amp; chips</p>', 'Fish & chips'];
        yield 'no words at all' => ['<p>https://example.com/a</p>', null];
        yield 'empty body' => ['', null];
        yield 'no body' => [null, null];
    }

    #[DataProvider('bodies')]
    public function testDerivesTheTitle(?string $bodyHtml, ?string $expected): void
    {
        self::assertSame($expected, DerivedTitle::from($bodyHtml));
    }

    public function testACutTitleIsAtMostEightyCharacters(): void
    {
        $title = DerivedTitle::from('<p>' . str_repeat('word ', 40) . '</p>');

        self::assertNotNull($title);
        self::assertLessThanOrEqual(80, mb_strlen($title));
        self::assertStringEndsWith('word…', $title);
    }
}
```

The cut row was computed with the rule below: `mb_substr($text, 0, 79)` ends inside "on", so the last space falls after "based".

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Text/Support/PlainTextTest.php tests/Service/Text/Support/DerivedTitleTest.php`
Expected: FAIL — `linesFromHtmlBlocks` undefined, class `DerivedTitle` not found.

- [ ] **Step 3: Implement**

In `PlainText`, add below `fromHtmlBlocks()` (and widen the constant's docblock, since a second method now uses it: `/** Block tags are word boundaries in an entry body; from() alone also reads feed titles, where a tag is no break. */`):

```php
    /** @return list<string> fromHtmlBlocks() per block: each block and line break becomes a line of its own. */
    public static function linesFromHtmlBlocks(?string $html): array
    {
        if ($html === null) {
            return [];
        }

        $lines = [];
        foreach (preg_split(self::BLOCK_BOUNDARY_PATTERN, $html) ?: [$html] as $block) {
            $line = self::from($block);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
```

Create `DerivedTitle`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Text\Support;

/** A headline for a post its feed gave none: the first sentence of its first line that holds words, cut to fit. */
final class DerivedTitle
{
    private const int MAX_LENGTH = 80;
    private const string ELLIPSIS = '…';
    private const string LINK_PATTERN = '#\bhttps?://\S+#u';
    /** Punctuation, symbols and at most a short label such as Mastodon's quote-post "RE:". */
    private const string WORDLESS_PATTERN = '/^[\p{P}\p{S}\s]*(?:\p{L}{1,3}:)?[\p{P}\p{S}\s]*$/u';
    private const string FIRST_SENTENCE_PATTERN = '/^.+?[.!?…](?=\s|$)/u';

    public static function from(?string $bodyHtml): ?string
    {
        foreach (PlainText::linesFromHtmlBlocks($bodyHtml) as $line) {
            if (self::holdsWords($line)) {
                return self::cut(self::firstSentence($line));
            }
        }

        return null;
    }

    private static function holdsWords(string $line): bool
    {
        $withoutLinks = preg_replace(self::LINK_PATTERN, '', $line) ?? $line;

        return preg_match(self::WORDLESS_PATTERN, $withoutLinks) !== 1;
    }

    private static function firstSentence(string $line): string
    {
        return preg_match(self::FIRST_SENTENCE_PATTERN, $line, $sentence) === 1 ? $sentence[0] : $line;
    }

    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $head = mb_substr($text, 0, self::MAX_LENGTH - 1);
        $lastSpace = mb_strrpos($head, ' ');
        $kept = $lastSpace === false ? $head : mb_substr($head, 0, $lastSpace);

        return rtrim($kept, ' ,;:-–—') . self::ELLIPSIS;
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Text/Support/`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`. Then:

```bash
git add backend/src/Service/Text/Support/PlainText.php backend/src/Service/Text/Support/DerivedTitle.php backend/tests/Service/Text/Support/PlainTextTest.php backend/tests/Service/Text/Support/DerivedTitleTest.php
git commit -m "feat(#1495): derive a title from a post's first sentence"
```

---

### Task 2: Parsers give a title-less item a derived title

**Files:**
- Create: `backend/src/Service/Parser/Model/ParsedTitleModel.php`
- Create: `backend/src/Service/Parser/Support/EntryTitle.php`
- Modify: `backend/src/Service/Parser/Model/ParsedEntryModel.php`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php:73-98`
- Modify: `backend/src/Service/Parser/FeedFormatParser/Rss1Parser.php:63-89`
- Modify: `backend/src/Service/Parser/FeedFormatParser/AbstractAtomParser.php:88-122`
- Modify: `backend/src/Service/Parser/WordPressJsonParser.php:46-60`
- Test: `backend/tests/Service/Parser/Support/EntryTitleTest.php`, `backend/tests/Service/Parser/Model/ParsedEntryModelTest.php`, `backend/tests/Service/Parser/FeedFormatParser/{Rss2,Rss1,Atom10}ParserTest.php`, `backend/tests/Service/Parser/WordPressJsonParserTest.php`

**Interfaces:**
- Consumes: `DerivedTitle::from(?string $bodyHtml): ?string` (Task 1).
- Produces: `ParsedEntryModel::$titleDerived` (`public bool`, constructor parameter `titleDerived: bool = false`, placed last); `EntryTitle::of(?string $feedTitle, ?string $bodyHtml): ParsedTitleModel`; `ParsedTitleModel { public string $text; public bool $derived; }` with named constructors `fromFeed(string)`, `derived(string)`, `untitled()`.

- [ ] **Step 1: Write the failing tests**

`EntryTitleTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\EntryTitle;
use PHPUnit\Framework\TestCase;

final class EntryTitleTest extends TestCase
{
    public function testAFeedTitleWinsAndIsNotDerived(): void
    {
        $title = EntryTitle::of('A <em>real</em> title', '<p>Body text here.</p>');

        self::assertSame('A real title', $title->text);
        self::assertFalse($title->derived);
    }

    public function testATitleLessItemTakesItsTitleFromTheBody(): void
    {
        $title = EntryTitle::of(null, '<p>Body text here. And more.</p>');

        self::assertSame('Body text here.', $title->text);
        self::assertTrue($title->derived);
    }

    public function testABlankFeedTitleCountsAsNone(): void
    {
        self::assertTrue(EntryTitle::of('   ', '<p>Body text here.</p>')->derived);
    }

    public function testAnItemWithNeitherIsUntitledAndNotDerived(): void
    {
        $title = EntryTitle::of(null, null);

        self::assertSame('(untitled)', $title->text);
        self::assertFalse($title->derived);
    }
}
```

In `ParsedEntryModelTest`, add one test that builds a model with `titleDerived: true` and asserts the flag survives `withContentHtml('<p>x</p>')`, `asDiscussionThread(Discussion::none())` and `withShowArtwork(new DeclaredImageModel('https://example.com/a.jpg'))` (read the existing tests in that file for the constructor call shape and reuse it).

In `Rss2ParserTest` (it has `parseSingleItem(string $itemXml)`):

```php
    public function testAnItemWithoutATitleTakesItsTitleFromTheDescription(): void
    {
        $entry = $this->parseSingleItem(
            '<item><guid>https://example.social/@a/1</guid><link>https://example.social/@a/1</link>'
            . '<description>&lt;p&gt;Hello from the fediverse. Second sentence.&lt;/p&gt;</description></item>',
        );

        self::assertSame('Hello from the fediverse.', $entry->title);
        self::assertTrue($entry->titleDerived);
        self::assertSame('https://example.social/@a/1', $entry->guid);
    }

    public function testATitledItemIsNotDerived(): void
    {
        $entry = $this->parseSingleItem('<item><title>Real</title><link>https://example.com/1</link></item>');

        self::assertSame('Real', $entry->title);
        self::assertFalse($entry->titleDerived);
    }

    public function testAPlainTextBlueskyDescriptionGivesItsFirstLine(): void
    {
        $entry = $this->parseSingleItem(
            "<item><link>https://bsky.app/profile/a/post/1</link><guid>at://did:plc:a/app.bsky.feed.post/1</guid>"
            . "<description>a masterclass in alt text\n\n[contains quote post or other embedded content]</description></item>",
        );

        self::assertSame('a masterclass in alt text', $entry->title);
        self::assertTrue($entry->titleDerived);
    }
```

Add the same shape of test (title-less item with a body → derived title, flag true) to `Rss1ParserTest`, `Atom10ParserTest` (an `<entry>` with `<id>`, `<link rel="alternate" href="…"/>`, `<content type="html">` and no `<title>`; plus one with only `<summary>` and no content, to cover the summary source) and `WordPressJsonParserTest` (a post whose `title.rendered` is `""` and `content.rendered` is `"<p>Hello there.</p>"`). Read each test file first and follow its existing helper for building input.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Parser/`
Expected: FAIL — class `EntryTitle` not found; unknown named parameter `titleDerived`; titles read `(untitled)`.

- [ ] **Step 3: Implement**

`ParsedTitleModel`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

final readonly class ParsedTitleModel
{
    private const string UNTITLED = '(untitled)';

    private function __construct(
        public string $text,
        public bool $derived,
    ) {
    }

    public static function fromFeed(string $text): self
    {
        return new self($text, false);
    }

    public static function derived(string $text): self
    {
        return new self($text, true);
    }

    public static function untitled(): self
    {
        return new self(self::UNTITLED, false);
    }
}
```

`EntryTitle`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Parser\Model\ParsedTitleModel;
use App\Service\Text\Support\DerivedTitle;
use App\Service\Text\Support\PlainText;

final class EntryTitle
{
    public static function of(?string $feedTitle, ?string $bodyHtml): ParsedTitleModel
    {
        $title = PlainText::from($feedTitle);
        if ($title !== null) {
            return ParsedTitleModel::fromFeed($title);
        }

        $derived = DerivedTitle::from($bodyHtml);

        return $derived === null ? ParsedTitleModel::untitled() : ParsedTitleModel::derived($derived);
    }

    private function __construct()
    {
    }
}
```

`ParsedEntryModel`: add `public bool $titleDerived = false,` as the last constructor parameter, and pass `titleDerived: $this->titleDerived,` in `asDiscussionThread`, `withContentHtml` and `withMedia`.

`Rss2Parser::parseEntry` — hoist the body, derive, pass both fields (GuidFallback unchanged):

```php
        $contentHtml = FeedBodyHtml::of($contentEncoded ?? $description) ?? MediaDescription::html($entry);
        $entryTitle = EntryTitle::of($title, $contentHtml);

        return new ParsedEntryModel(
            guid: GuidFallback::for($item->text('guid'), $link, $title),
            url: $link,
            title: $entryTitle->text,
            author: self::coreOrDublinCore($item, 'author', 'creator'),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentHtml,
            publishedAt: DateParser::parse(self::coreOrDublinCore($item, 'pubDate', 'date')),
            media: new ParsedEntryMediaModel($image, $mediaBundle),
            categories: ItemCategoryExtractor::extract($item),
            discussion: self::discussion($item),
            titleDerived: $entryTitle->derived,
        );
```

`Rss1Parser::parseEntry` — same pattern with `$contentHtml = FeedBodyHtml::of($contentEncoded ?? $description);`, `title: $entryTitle->text`, `contentHtml: $contentHtml`, `titleDerived: $entryTitle->derived`.

`AbstractAtomParser::parseEntry` — the local `$contentHtml` already holds the raw content markup; name the body separately:

```php
        $body = FeedBodyHtml::of($contentHtml) ?? ($summary === null ? MediaDescription::html($entry) : null);
        $entryTitle = EntryTitle::of($title, $body ?? $summary);
```

then `title: $entryTitle->text`, `contentHtml: $body`, `titleDerived: $entryTitle->derived`. `$summary` must be read before these two lines (move `$summary = $atomEntry->text('summary');` up).

`WordPressJsonParser::entry`:

```php
        $contentHtml = $this->rendered($post, 'content');
        $summary = $this->rendered($post, 'excerpt');
        $entryTitle = EntryTitle::of($this->rendered($post, 'title'), $contentHtml ?? $summary);
```

then `title: $entryTitle->text`, `summary: $summary`, `contentHtml: $contentHtml`, `titleDerived: $entryTitle->derived`. Remove now-unused `PlainText` imports.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Parser/ tests/Service/Discovery/ tests/Service/Preview/`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`.

```bash
git add backend/src/Service/Parser backend/tests/Service/Parser
git commit -m "feat(#1495): a title-less feed item takes its title from its text"
```

---

### Task 3: Store the flag and carry it through backup

**Files:**
- Modify: `backend/src/Entity/Entry.php`
- Create: `backend/migrations/Version20261010120000.php`
- Modify: `backend/src/Service/Ingest/Factory/IngestedEntryFactory.php`
- Modify: `backend/src/Service/Backup/BackupLines.php`, `backend/src/Service/Backup/Dto/EntryLine.php`, `backend/src/Repository/EntryBatchInserter.php`
- Modify: `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Support/FullyPopulatedAccount.php`, `docs/backup.md`
- Test: `backend/tests/Entity/EntryTest.php`, `backend/tests/Service/Ingest/Factory/IngestedEntryFactoryTest.php` (create if missing; otherwise the ingest test that covers the factory), `backend/tests/Service/Backup/Dto/EntryLineTest.php`, `backend/tests/Repository/EntryBatchInserterTest.php` (wherever the inserter's test lives: `grep -rl EntryBatchInserter backend/tests`)

**Interfaces:**
- Consumes: `ParsedEntryModel::$titleDerived` (Task 2).
- Produces: `Entry::isTitleDerived(): bool`, `Entry::markTitleDerived(): void`; column `entry.title_derived`; backup field `titleDerived`.

- [ ] **Step 1: Write the failing tests**

`EntryTest`: a new entry is not title-derived; after `markTitleDerived()` it is.

Factory/ingest test: ingesting a `ParsedEntryModel` with `titleDerived: true` yields an `Entry` with `isTitleDerived()` true; the default yields false.

`EntryLineTest`: `fromLine([... , 'titleDerived' => true])` gives `titleDerived === true`; a line without the key gives false (copy an existing complete line array from that test).

Inserter test: a line with `titleDerived: true` is restored to an entry whose `isTitleDerived()` is true.

The backup round-trip tests that use `FullyPopulatedAccount` and `BackupFieldDeclarations` cover the export side once Step 3 marks the populated entry and declares the field.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Entity/EntryTest.php tests/Service/Backup tests/Service/Ingest`
Expected: FAIL — undefined method `markTitleDerived`, unknown named parameter `titleDerived`.

- [ ] **Step 3: Implement**

`Entry` — after `$title`:

```php
    #[ORM\Column(name: 'title_derived', options: ['default' => false])]
    private bool $titleDerived = false;
```

and after `setTitle()`:

```php
    public function isTitleDerived(): bool
    {
        return $this->titleDerived;
    }

    public function markTitleDerived(): void
    {
        $this->titleDerived = true;
    }
```

Migration `Version20261010120000`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.title_derived (#1495).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry ADD COLUMN title_derived BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN title_derived');
    }
}
```

`IngestedEntryFactory::create` — after `$entry->setDiscussion(...)`:

```php
        if ($parsed->titleDerived) {
            $entry->markTitleDerived();
        }
```

`BackupLines::entryLine` — after `'title' => $entry->getTitle(),` add `'titleDerived' => $entry->isTitleDerived(),`.

`EntryLine` — constructor: add `public bool $titleDerived = false,` as the last parameter; `fromLine`: add `titleDerived: LineFieldWithDefault::bool($line, 'titleDerived', false),` (import `LineFieldWithDefault` from the same namespace `LineField` comes from, if not imported).

`EntryBatchInserter` — append `'title_derived'` to the column list after `'comments_load'`, and `(int) $line->titleDerived,` to `row()` after the comments-load value.

`BackupFieldDeclarations` — under `Entry::class`, after `'title' => 'title',` add `'titleDerived' => 'titleDerived',` (keep the existing line layout).

`FullyPopulatedAccount` — call `$entry->markTitleDerived();` before `return $entry;` in the entry builder.

`docs/backup.md` — in the `entry` row, after `` `title`, `` insert `` `titleDerived` (the title was taken from the text, the feed gave none), ``.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Entity tests/Service/Backup tests/Service/Ingest tests/Repository`
Expected: PASS. Then verify the migration on a scratch database (never the dev DB):
Run: `DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:migrations:migrate --no-interaction --env=test && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:schema:validate --env=test; rm -f var/migrate-check.db`
Expected: migrations run, schema in sync. (The MySQL leg runs in CI.)

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`.

```bash
git add backend/src/Entity/Entry.php backend/migrations/Version20261010120000.php backend/src/Service/Ingest backend/src/Service/Backup backend/src/Repository/EntryBatchInserter.php backend/tests docs/backup.md
git commit -m "feat(#1495): entries remember a derived title, and backups carry it"
```

---

### Task 4: API field and the reader's answer for a post

**Files:**
- Modify: `backend/src/Http/EntryJson.php` (three array-shape docblocks + `commonFields`)
- Modify: `backend/src/Service/Reader/Model/ExtractionFailure.php`
- Modify: `backend/src/Controller/Api/EntryReaderController.php:52-59`
- Modify: `backend/src/Repository/ReaderAuditRepository.php` (`candidateRows`)
- Test: `backend/tests/Http/EntryJsonTest.php` (or the controller test that asserts list fields — `grep -rl "isShort" backend/tests`), `backend/tests/Service/Reader/Model/ExtractionFailureTest.php`, `backend/tests/Controller/Api/EntryReaderControllerTest.php`, `backend/tests/Repository/ReaderAuditRepositoryTest.php` (or the `AuditSampler` test)

**Interfaces:**
- Consumes: `Entry::isTitleDerived()`, `Entry::markTitleDerived()` (Task 3).
- Produces: JSON field `titleDerived: bool` on every entry row; `ExtractionFailure::FeedBodyIsPost = 'feed_body_is_post'`.

- [ ] **Step 1: Write the failing tests**

`ExtractionFailureTest`: extend the expected list to `['no_url', 'fetch', 'unextractable', 'empty', 'mismatch', 'player_page', 'feed_body_is_post']`.

`EntryReaderControllerTest`, next to `testEntryWithoutUrlShortCircuitsWithoutCallingExtractor`:

```php
    public function testAPostAnswersFeedBodyIsPostWithoutCallingExtractor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-post@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntry($user, 'https://example.social/@a/1');
        $entry->markTitleDerived();
        $this->entityManager()->flush();

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('failed', $body['status']);
        self::assertSame('feed_body_is_post', $body['reason']);
        self::assertSame([], $fake->calls);
    }
```

(Use whatever the test class already uses to reach the entity manager; read `seedEntry` first.)

`EntryJson`: an entry marked title-derived serialises `titleDerived: true`, an ordinary one `false`.

Audit: a title-derived entry with a url is not among `candidateRows`; an ordinary one is.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/Model/ExtractionFailureTest.php tests/Controller/Api/EntryReaderControllerTest.php tests/Http tests/Repository/ReaderAuditRepositoryTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`ExtractionFailure` — after `PlayerPage`:

```php
    /** The entry is a post whose feed body is the whole of it; there is no page to extract. */
    case FeedBodyIsPost = 'feed_body_is_post';
```

`EntryReaderController::reader` — replace the ternary:

```php
        $url = $entry->getUrl();
        $result = match (true) {
            $entry->isTitleDerived() => ExtractionResultModel::failed(null, ExtractionFailure::FeedBodyIsPost),
            $url === null || $url === '' => ExtractionResultModel::failed(null, ExtractionFailure::NoUrl),
            default => $this->extractor->extract($url, new EntryHintsModel(
                title: $entry->getTitle(),
                author: $entry->getAuthor(),
                feedMedia: FeedMediaModel::fromEntry($entry),
            )),
        };
```

Confirm `ExtractionCoverageGate::verify` passes a failed result through unchanged (read it); if it does not, say so in the report.

`EntryJson::commonFields` — after `'isShort' => …,` add `'titleDerived' => $entry->isTitleDerived(),`, and add `titleDerived: bool,` after `isShort: bool,` in all three `@return array{…}` shapes.

`ReaderAuditRepository::candidateRows` — add `AND e.title_derived = 0` to the `WHERE` clause after the url conditions.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`.

```bash
git add backend/src/Http/EntryJson.php backend/src/Service/Reader/Model/ExtractionFailure.php backend/src/Controller/Api/EntryReaderController.php backend/src/Repository/ReaderAuditRepository.php backend/tests
git commit -m "feat(#1495): the api flags derived titles and the reader fetches no page for a post"
```

---

### Task 5: The SPA shows a post's text in the headline slot

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`EntryDto`, the reader failure `reason` union at ~l.444)
- Modify: `frontend/src/app/reader/list/preview-image.ts` (add `entryHeadline`)
- Create: `frontend/src/app/reader/list/_post-text.scss`
- Modify: `frontend/src/app/reader/list/entry-row/entry-row.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/list/magazine/entry-block-base.ts`
- Modify: the seven block templates and their `.scss`: `frontend/src/app/reader/list/magazine/blocks/entry-{hero,wide,quote,split,kicker,thumb,compact}/entry-*.component.{html,scss}`
- Modify: every `EntryDto` fixture the compiler flags (24 files hold `isShort:`; `frontend/e2e/support/reader.ts:107` among them) — add `titleDerived: false`
- Test: `frontend/src/app/reader/list/preview-image.spec.ts`, `frontend/src/app/reader/list/entry-row/entry-row.component.spec.ts`, `frontend/src/app/reader/list/magazine/blocks/entry-hero/entry-hero.component.spec.ts`, `frontend/src/app/reader/list/magazine/blocks/entry-quote/entry-quote.component.spec.ts`

**Interfaces:**
- Consumes: JSON field `titleDerived: boolean`, reason `'feed_body_is_post'` (Task 4).
- Produces: `entryHeadline(entry: EntryDto): string`; `EntryBlockBase.headline`, `EntryBlockBase.isPost`; the `post-text` mixin.

The magazine planner (`magazine-planner.ts`, `magazine-slot-fit.ts`) stays untouched: it keeps reading `entrySnippet`, which is unchanged.

- [ ] **Step 1: Write the failing tests**

`preview-image.spec.ts`:

```ts
describe('entryHeadline', () => {
  it('is the title of an ordinary entry', () => {
    expect(entryHeadline(entry({ title: 'Real', excerpt: 'Body', titleDerived: false }))).toBe('Real');
  });

  it("is the excerpt of a post, whose title only repeats it", () => {
    expect(entryHeadline(entry({ title: 'Body', excerpt: 'Body and more', titleDerived: true }))).toBe(
      'Body and more',
    );
  });
});
```

(Use the spec's existing entry-fixture helper; name it as it is named there.)

`entry-row.component.spec.ts`: for `titleDerived: true` the `.title` element shows the excerpt and has class `post`, and no `.snippet` element renders; for `false` the title and snippet render as before.

`entry-hero.component.spec.ts` and `entry-quote.component.spec.ts`: for a post the `.title` shows the excerpt with class `post` and the dek/quote text element (whatever `@if (snippet())` guards) is absent.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/list/preview-image.spec.ts src/app/reader/list/entry-row src/app/reader/list/magazine/blocks/entry-hero src/app/reader/list/magazine/blocks/entry-quote`
Expected: FAIL — `entryHeadline` not exported; `titleDerived` not in `EntryDto`.

- [ ] **Step 3: Implement**

`models.ts` — in `EntryDto` after `isShort`:

```ts
  /** The feed gave no title (a Mastodon or Bluesky post); `title` was derived from the text (#1495). */
  titleDerived: boolean;
```

and extend the failure reason union with `| 'feed_body_is_post'`.

`preview-image.ts`:

```ts
/** What a card shows in its headline slot: a post's own text, since its derived title only repeats it. */
export function entryHeadline(entry: EntryDto): string {
  return entry.titleDerived ? entry.excerpt : entry.title;
}
```

`_post-text.scss`:

```scss
// A post's text in a headline slot: reads as body copy, clamped, never as a display headline.
@mixin post-text($lines: 4) {
  font-size: var(--fs-base);
  font-weight: 400;
  line-height: var(--lh-normal);
  display: -webkit-box;
  -webkit-line-clamp: $lines;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
```

`entry-block-base.ts` — add (and import `entryHeadline`):

```ts
  readonly isPost = computed(() => this.entry().titleDerived);
  readonly headline = computed(() => entryHeadline(this.entry()));
```

and change `snippet` so a post shows no dek beneath its own text:

```ts
  readonly snippet = computed(() => (this.isPost() ? '' : entrySnippet(this.entry())));
```

Each of the seven block templates: replace `{{ entry().title }}` with `{{ headline() }}` and add `[class.post]="isPost()"` to that same title element. Each block's `.scss`: `@use '../../../post-text';` and

```scss
.title.post {
  @include post-text.post-text;
}
```

(For blocks whose title clamps to fewer lines by design — thumb, compact, kicker — pass that block's existing title clamp, e.g. `@include post-text.post-text(2)`, read from its `.title` rule; if a block's title has no clamp, use the default.)

`entry-row.component.ts` — add `readonly isPost = computed(() => this.entry().titleDerived);` and `readonly headline = computed(() => entryHeadline(this.entry()));`. Template: the `h3.title` gets `[class.post]="isPost()"` and renders `headline()` (both the marked and plain branches); wrap the `p.snippet` in `@if (!isPost()) { … }`. Scss: `@use '../post-text';` and `.title.post { @include post-text.post-text; }`.

Add `titleDerived: false` to every `EntryDto` fixture the TypeScript compiler reports (run `npx tsc -p tsconfig.spec.json --noEmit` and `npx tsc -p e2e/tsconfig.json --noEmit` in the container to list them).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm run check`
Expected: PASS (lint, stylelint, tsc for app/spec/e2e, Jest).

- [ ] **Step 5: Commit**

```bash
git add frontend
git commit -m "feat(#1495): list rows and magazine blocks show a post's text instead of a headline"
```

---

### Task 6: The reader opens a post without a page request

**Files:**
- Modify: `frontend/src/app/reader/article/content/article-source.service.ts:133-147`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html:102-104`
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts`

**Interfaces:**
- Consumes: `EntryDto.titleDerived` (Task 5).

- [ ] **Step 1: Write the failing tests**

In `reader-view.component.spec.ts` (follow the existing `'renders title, meta, content and decorates external links'` setup):
- opening a `titleDerived: true` entry with a url makes **no** request to `/api/entries/{id}/reader` (assert with the spec's `HttpTestingController`: `expectNone`), renders the feed body, and shows no fallback notice;
- its `h1.title` exists (focus target) and has class `sr-only`;
- an ordinary entry's `h1.title` has no `sr-only` class.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/reader-view`
Expected: FAIL — a reader request is made; no `sr-only`.

- [ ] **Step 3: Implement**

`ArticleSource.open()` — a post takes the url-less path:

```ts
    if (!entry.url || entry.titleDerived) {
      this.loadSub?.unsubscribe();
      this.state.set({ status: 'idle' });
      this.readerMode.setOriginalOnly();
      return;
    }
```

`reader-view.component.html` — keep the `h1` (it is the focus target, `#titleHeading`) but hide it visually for a post; the byline then leads:

```html
            <h1 class="title" [class.sr-only]="e.titleDerived" #titleHeading tabindex="-1">{{ e.title }}</h1>
```

The mini title and bar title stay as they are (the derived title is short).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm run check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/article
git commit -m "feat(#1495): the reader shows a post's own text without loading its page"
```

---

### Task 7: Full verification and a live check

**Files:** none new (fixes only, if a gate fails).

- [ ] **Step 1: Both suites**

Run in parallel: `cd backend && composer test:parallel` and `docker compose exec php composer test`.
Expected: both green.

- [ ] **Step 2: Mutation gate**

Run: `cd backend && composer infection:diff`
Expected: MSI at or above `infection.json5`'s `minMsi`. Kill escaped mutants with test rows, not code changes.

- [ ] **Step 3: Live smoke on the Docker stack**

Apply the migration to the Docker DB (`docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`), restart the worker if it holds stale code, then subscribe a test account to `https://mastodon.social/@mastodon` and `https://bsky.app/profile/bsky.app` through the API or the SPA. Check: titles are derived (no `(untitled)`), the `RE:` line is skipped, `titleDerived` is true in `/api/entries`, the reader returns `feed_body_is_post`, and the magazine and list show post text (screenshot both layouts at desktop and Mobile viewport). Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .`) for errors.

- [ ] **Step 4: README**

If `README.md` lists supported sources, add a line that Mastodon and Bluesky profiles show as posts. Commit:

```bash
git add README.md
git commit -m "docs(#1495): mastodon and bluesky profiles read as posts"
```
