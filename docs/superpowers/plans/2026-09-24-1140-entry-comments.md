# Entry comments feeds and platform entry rules — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the reader from extracting Reddit thread pages, and show an entry's comments (lazy, below the article) wherever the entry has a comments feed — auto-loaded for Reddit, behind a button for feed-declared sources.

**Architecture:** A `Discussion` value object (discussion page URL, comments-feed URL, load mode) travels from the parsers through `ParsedEntry` into an `EntryDiscussion` embeddable on `Entry`. A tagged `PlatformEntryRule` set rewrites a `ParsedEntry` at ingest where a platform declares nothing — Reddit is the first rule: it moves the thread URL into the discussion, makes the external `[link]` target the entry `url` (or `null` for self posts), and strips the "submitted by" footer. A stateless `GET /api/entries/{id}/comments` fetches and parses the comments feed through the existing guarded fetcher, sharing a per-host throttle memory with feed refresh. The SPA skips extraction when `url` is null, and a new `<app-entry-comments>` renders the section with an in-memory 1-hour cache.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM, PHPUnit 12; Angular 20 (standalone, signals), Jest, Transloco.

**Spec:** GitHub issue #1140 (the design body). Threaded comments are out of scope (#1141).

## Global Constraints

- Branch `feature/1140-entry-comments`; commits `type(#1140): …`; PR into `develop`, body `Closes #1140`.
- CLAUDE.md Clean Code rules are mandatory: `final readonly` value classes, no boolean flag params, typed exceptions, **default to no comment** (≤ 3 lines when one is truly needed), no `get…` with side effects.
- Every `src` file touched must be PHPMD-clean (`composer md`), `composer check` green (cs + stan level max + tramp), `ThinControllerRule` satisfied (controller has no private methods).
- Datetimes are naive UTC — normalise comment dates to UTC before serialising.
- Native-client constraint: the endpoint is bearer-auth JSON, stateless, `application/problem+json` on errors, always `200` with a `status` discriminator for fetch outcomes (the `/reader` convention).
- Frontend: standalone components, signals, styles in a sibling `.scss`, tokens only (no hex, no raw px outside `theme/`), `npm run check` green. Frontend tests run in the container: `docker compose exec -T frontend npm test`.
- Migration must run on MySQL **and** SQLite (CI migrates from empty on both); verify it by hand with `doctrine:migrations:migrate` on the Docker stack.
- Backup format carries every entry column (`BackupFieldDeclarations` test enforces it).
- **No `ReaderCacheService.VERSION` bump.** The issue asked for one, but nothing cached goes stale: old Reddit entries keep their thread `url` by design (no backfill), and new entries have new ids. Say so in the PR body.

---

## File map

**Backend — create**
- `backend/src/Enum/CommentsLoad.php` — `Auto` | `Manual`.
- `backend/src/Service/Discussion/Discussion.php` — value object: page URL, comments-feed URL, load mode.
- `backend/src/Entity/EntryDiscussion.php` — embeddable, three nullable columns.
- `backend/migrations/Version20260924120000.php` — adds the columns.
- `backend/src/Service/Ingest/Platform/PlatformEntryRule.php` — interface.
- `backend/src/Service/Ingest/Platform/PlatformEntryRules.php` — tagged-iterator dispatcher.
- `backend/src/Service/Ingest/Platform/RedditEntryRule.php` — the first rule.
- `backend/src/Service/Fetch/HostThrottle.php` — per-host "do not ask before" memory.
- `backend/src/Service/Comments/EntryComment.php`, `CommentsResult.php`, `CommentsLoader.php`, `Exception/NoCommentsFeedException.php`.
- `backend/src/Http/CommentsJson.php`, `backend/src/Controller/Api/EntryCommentsController.php`.

**Backend — modify**
- `Service/Parser/ParsedEntry.php`, `XmlHelper.php`, `Rss2Parser.php`, `AbstractAtomParser.php`
- `Entity/Entry.php`, `Service/Ingest/EntryIngestor.php`, `Http/EntryJson.php`
- `Service/Backup/BackupLines.php`, `Service/Backup/Dto/EntryLine.php`, `Service/Backup/EntryBatchInserter.php`
- `Service/FeedScheduler.php`, `config/services.yaml`, `config/packages/cache.yaml`, `config/packages/rate_limiter.yaml`

**Frontend — create**
- `frontend/src/app/reader/comments.service.ts` (+ spec)
- `frontend/src/app/reader/entry-comments/entry-comments.component.{ts,html,scss,spec.ts}`

**Frontend — modify**
- `reader/models.ts`, `reader/reader-api.ts`, `reader/reader-view/reader-view.component.{ts,html,spec.ts}`, `public/i18n/en.json`, `public/i18n/de.json`

---

### Task 1: Discussion value object and parser support

**Files:**
- Create: `backend/src/Enum/CommentsLoad.php`, `backend/src/Service/Discussion/Discussion.php`
- Modify: `backend/src/Service/Parser/ParsedEntry.php`, `XmlHelper.php`, `Rss2Parser.php`, `AbstractAtomParser.php`
- Test: `backend/tests/Service/Discussion/DiscussionTest.php`, `backend/tests/Service/Parser/Rss2ParserTest.php`, `backend/tests/Service/Parser/Atom10ParserTest.php`

**Interfaces:**
- Produces: `enum CommentsLoad: string { Auto = 'auto'; Manual = 'manual' }`;
  `Discussion::none()`, `Discussion::page(string $url)`, `Discussion::withCommentsFeed(?string $pageUrl, string $commentsFeedUrl, CommentsLoad $load)`; readonly props `?string $url`, `?string $commentsFeedUrl`, `?CommentsLoad $commentsLoad`; `hasCommentsFeed(): bool`.
  `ParsedEntry` gains trailing params `Discussion $discussion = new Discussion…` (see below) and `?string $authorUrl = null`.
  `XmlHelper::childHttpUrl(\DOMElement $parent, string $localName, ?string $namespaceUri): ?string`.

- [ ] **Step 1: Write the failing `Discussion` test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discussion;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use PHPUnit\Framework\TestCase;

final class DiscussionTest extends TestCase
{
    public function testNoneCarriesNothing(): void
    {
        $discussion = Discussion::none();

        self::assertNull($discussion->url);
        self::assertNull($discussion->commentsFeedUrl);
        self::assertNull($discussion->commentsLoad);
        self::assertFalse($discussion->hasCommentsFeed());
    }

    public function testPageHasNoCommentsFeed(): void
    {
        $discussion = Discussion::page('https://news.example/item?id=1');

        self::assertSame('https://news.example/item?id=1', $discussion->url);
        self::assertFalse($discussion->hasCommentsFeed());
    }

    public function testCommentsFeedCarriesItsLoadMode(): void
    {
        $discussion = Discussion::withCommentsFeed(null, 'https://blog.example/post/feed/', CommentsLoad::Manual);

        self::assertNull($discussion->url);
        self::assertSame('https://blog.example/post/feed/', $discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $discussion->commentsLoad);
        self::assertTrue($discussion->hasCommentsFeed());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Service/Discussion/DiscussionTest.php`
Expected: FAIL — `Class "App\Service\Discussion\Discussion" not found`.

- [ ] **Step 3: Implement the enum and the value object**

`backend/src/Enum/CommentsLoad.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum CommentsLoad: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
```

`backend/src/Service/Discussion/Discussion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discussion;

use App\Enum\CommentsLoad;

final readonly class Discussion
{
    private function __construct(
        public ?string $url,
        public ?string $commentsFeedUrl,
        public ?CommentsLoad $commentsLoad,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function page(string $url): self
    {
        return new self($url, null, null);
    }

    public static function withCommentsFeed(?string $pageUrl, string $commentsFeedUrl, CommentsLoad $load): self
    {
        return new self($pageUrl, $commentsFeedUrl, $load);
    }

    public function hasCommentsFeed(): bool
    {
        return $this->commentsFeedUrl !== null;
    }
}
```

- [ ] **Step 4: Run the test and see it pass**

Run: `cd backend && php bin/phpunit tests/Service/Discussion/DiscussionTest.php` — Expected: PASS.

- [ ] **Step 5: Extend `ParsedEntry`**

`Discussion`'s constructor is private, so `new` in an initializer is not available and `Discussion::none()` is not a constant expression. Take `?Discussion $discussion = null` and normalise it in the body, so readers always get a non-null `Discussion`:

```php
final readonly class ParsedEntry
{
    public Discussion $discussion;

    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
        public ParsedEntryMedia $media = new ParsedEntryMedia(),
        /** @var list<ParsedCategory> */
        public array $categories = [],
        ?Discussion $discussion = null,
        public ?string $authorUrl = null,
    ) {
        $this->discussion = $discussion ?? Discussion::none();
    }
}
```

Add `use App\Service\Discussion\Discussion;`. No other `new ParsedEntry(` call site changes (all use named or positional args up to `categories`).

- [ ] **Step 6: Add `XmlHelper::childHttpUrl`**

RSS `<comments>` shares its local name with WordPress's `<slash:comments>` comment *count*, and `childText()` with a null namespace matches both. A URL-only lookup sidesteps the count without needing a "no namespace" mode. Add below `childElement()`:

```php
    /** The first matching direct child whose text is an absolute http(s) URL. */
    public static function childHttpUrl(\DOMElement $parent, string $localName, ?string $namespaceUri = null): ?string
    {
        foreach (self::childElements($parent, $localName, $namespaceUri) as $child) {
            $text = trim($child->textContent);
            if (preg_match('#^https?://#i', $text) === 1) {
                return $text;
            }
        }

        return null;
    }
```

- [ ] **Step 7: Write failing RSS 2.0 parser tests**

Append to `backend/tests/Service/Parser/Rss2ParserTest.php` (follow the file's existing helper for building a document; if it parses via `(new FeedParser(...))->parse($xml)` or a local `parse()` helper, use that same helper):

```php
    public function testWordPressCommentFeedIsAManualCommentsFeed(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Post</title>
              <link>https://blog.example/post/</link>
              <comments>https://blog.example/post/#comments</comments>
              <wfw:commentRss>https://blog.example/post/feed/</wfw:commentRss>
              <slash:comments>3</slash:comments>
            </item>
            XML);

        self::assertSame('https://blog.example/post/#comments', $entry->discussion->url);
        self::assertSame('https://blog.example/post/feed/', $entry->discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $entry->discussion->commentsLoad);
    }

    public function testCommentsPageWithoutFeedIsADiscussionPageOnly(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Show HN</title>
              <link>https://project.example/</link>
              <comments>https://news.ycombinator.com/item?id=1</comments>
            </item>
            XML);

        self::assertSame('https://news.ycombinator.com/item?id=1', $entry->discussion->url);
        self::assertFalse($entry->discussion->hasCommentsFeed());
    }

    public function testSlashCommentsCountIsNeverTakenForAUrl(): void
    {
        $entry = $this->parseSingleItem(<<<'XML'
            <item>
              <title>Post</title>
              <link>https://blog.example/post/</link>
              <slash:comments>0</slash:comments>
            </item>
            XML);

        self::assertNull($entry->discussion->url);
    }
```

with a private helper (only if the test class has none equivalent):

```php
    private function parseSingleItem(string $itemXml): ParsedEntry
    {
        $document = new \DOMDocument();
        $document->loadXML(<<<XML
            <rss version="2.0"
                 xmlns:wfw="http://wellformedweb.org/CommentAPI/"
                 xmlns:slash="http://purl.org/rss/1.0/modules/slash/">
              <channel><title>Blog</title>{$itemXml}</channel>
            </rss>
            XML);

        return (new Rss2Parser())->parse($document)->entries[0];
    }
```

Run: `cd backend && php bin/phpunit tests/Service/Parser/Rss2ParserTest.php` — Expected: the three new tests FAIL (`discussion->url` is null).

- [ ] **Step 8: Implement the RSS 2.0 side**

In `Rss2Parser`: add `private const string WFW_NS = 'http://wellformedweb.org/CommentAPI/';`, pass `discussion: self::discussion($item),` to `new ParsedEntry(...)`, and add:

```php
    private static function discussion(\DOMElement $item): Discussion
    {
        $page = XmlHelper::childHttpUrl($item, 'comments');
        $commentsFeed = XmlHelper::childHttpUrl($item, 'commentRss', self::WFW_NS);
        if ($commentsFeed !== null) {
            return Discussion::withCommentsFeed($page, $commentsFeed, CommentsLoad::Manual);
        }

        return $page === null ? Discussion::none() : Discussion::page($page);
    }
```

Run the RSS tests again — Expected: PASS.

- [ ] **Step 9: Write failing Atom tests**

Append to `backend/tests/Service/Parser/Atom10ParserTest.php` (reuse its document helper; otherwise add one like Step 7 with an Atom `<feed xmlns="http://www.w3.org/2005/Atom"><title>F</title>…</feed>` wrapper and `new Atom10Parser()`):

```php
    public function testRepliesLinkWithAFeedTypeIsAManualCommentsFeed(): void
    {
        $entry = $this->parseSingleEntry(<<<'XML'
            <entry>
              <title>Post</title><id>urn:1</id>
              <link href="https://blog.example/post"/>
              <link rel="replies" type="application/atom+xml" href="https://blog.example/post/comments.xml"/>
              <link rel="replies" type="text/html" href="https://blog.example/post#comments"/>
            </entry>
            XML);

        self::assertSame('https://blog.example/post#comments', $entry->discussion->url);
        self::assertSame('https://blog.example/post/comments.xml', $entry->discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $entry->discussion->commentsLoad);
    }

    public function testRepliesLinkWithoutATypeIsADiscussionPage(): void
    {
        $entry = $this->parseSingleEntry(<<<'XML'
            <entry>
              <title>Post</title><id>urn:1</id>
              <link href="https://blog.example/post"/>
              <link rel="replies" href="https://forum.example/t/1"/>
            </entry>
            XML);

        self::assertSame('https://forum.example/t/1', $entry->discussion->url);
        self::assertFalse($entry->discussion->hasCommentsFeed());
    }

    public function testAuthorUriIsCarried(): void
    {
        $entry = $this->parseSingleEntry(<<<'XML'
            <entry>
              <title>Post</title><id>urn:1</id>
              <author><name>/u/someone</name><uri>https://www.reddit.com/user/someone</uri></author>
            </entry>
            XML);

        self::assertSame('https://www.reddit.com/user/someone', $entry->authorUrl);
    }
```

Run: `cd backend && php bin/phpunit tests/Service/Parser/Atom10ParserTest.php` — Expected: new tests FAIL.

- [ ] **Step 10: Implement the Atom side**

In `AbstractAtomParser::parseEntry`, pass `discussion: $this->discussion($entry, $ns), authorUrl: $this->authorUri($entry, $ns),` and add:

```php
    private function discussion(\DOMElement $entry, string $ns): Discussion
    {
        $page = null;
        $commentsFeed = null;
        foreach ($this->links($entry, $ns, 'replies') as $link) {
            $href = trim($link->getAttribute('href'));
            if (self::isFeedType($link->getAttribute('type'))) {
                $commentsFeed ??= $href;
                continue;
            }
            $page ??= $href;
        }

        if ($commentsFeed !== null) {
            return Discussion::withCommentsFeed($page, $commentsFeed, CommentsLoad::Manual);
        }

        return $page === null ? Discussion::none() : Discussion::page($page);
    }

    private static function isFeedType(string $type): bool
    {
        return preg_match('#(atom|rss)\+xml$|/xml$#i', $type) === 1;
    }

    /** @return iterable<\DOMElement> */
    private function links(\DOMElement $parent, string $ns, string $rel): iterable
    {
        foreach ($parent->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === 'link'
                && $child->namespaceURI === $ns
                && $child->getAttribute('rel') === $rel
                && self::httpUrlOrNull(trim($child->getAttribute('href'))) !== null
            ) {
                yield $child;
            }
        }
    }

    private function authorUri(\DOMElement $entry, string $ns): ?string
    {
        $author = XmlHelper::childElement($entry, 'author', $ns);

        return $author === null ? null : self::httpUrlOrNull(XmlHelper::childText($author, 'uri', $ns));
    }
```

Run both parser test files — Expected: PASS. Then run the whole parser suite: `php bin/phpunit tests/Service/Parser` — Expected: PASS.

- [ ] **Step 11: Gates and commit**

Run: `cd backend && composer cs && composer stan && vendor/bin/phpmd src/Service/Parser,src/Service/Discussion,src/Enum text phpmd.xml.dist` (use the ruleset file `composer md` uses; check `composer.json` `scripts.md`).
Expected: clean. If PHPMD flags `AbstractAtomParser` class length, move `discussion()`/`links()`/`isFeedType()` into a new `final class AtomDiscussion` in `Service/Parser/` with a static `from(\DOMElement $entry, string $ns): Discussion`, and call that.

```bash
git add backend/src/Enum/CommentsLoad.php backend/src/Service/Discussion backend/src/Service/Parser backend/tests/Service/Discussion backend/tests/Service/Parser
git commit -m "feat(#1140): read discussion pages and comments feeds from RSS and Atom"
```

---

### Task 2: Persist the discussion on Entry

**Files:**
- Create: `backend/src/Entity/EntryDiscussion.php`, `backend/migrations/Version20260924120000.php`
- Modify: `backend/src/Entity/Entry.php`, `backend/src/Service/Ingest/EntryIngestor.php`, `backend/src/Http/EntryJson.php`, `backend/src/Service/Backup/BackupLines.php`, `backend/src/Service/Backup/Dto/EntryLine.php`, `backend/src/Service/Backup/EntryBatchInserter.php`
- Test: `backend/tests/Entity/EntryDiscussionTest.php`, `backend/tests/Service/Ingest/EntryIngestorTest.php`, `backend/tests/Controller/Api/EntryControllerTest.php`, the backup round-trip test in `backend/tests/Service/Backup/`

**Interfaces:**
- Consumes: `Discussion`, `CommentsLoad` (Task 1).
- Produces: `Entry::getDiscussion(): Discussion`, `Entry::setDiscussion(Discussion $discussion): void`. Entry JSON (`listRow` and `detail`) gains `discussionUrl: string|null` and `comments: 'auto'|'manual'|null`. Backup entry line gains `discussionUrl`, `commentsFeedUrl`, `commentsLoad`.

- [ ] **Step 1: Write the failing embeddable test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryDiscussion;
use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use PHPUnit\Framework\TestCase;

final class EntryDiscussionTest extends TestCase
{
    public function testRoundTripsACommentsFeed(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::withCommentsFeed('https://t.example/1', 'https://t.example/1/.rss', CommentsLoad::Auto));

        $read = $stored->read();

        self::assertSame('https://t.example/1', $read->url);
        self::assertSame('https://t.example/1/.rss', $read->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $read->commentsLoad);
    }

    public function testRoundTripsAPageOnly(): void
    {
        $stored = new EntryDiscussion();
        $stored->store(Discussion::page('https://t.example/1'));

        self::assertFalse($stored->read()->hasCommentsFeed());
        self::assertSame('https://t.example/1', $stored->read()->url);
    }

    public function testEmptyByDefault(): void
    {
        self::assertEquals(Discussion::none(), (new EntryDiscussion())->read());
    }
}
```

Run: `cd backend && php bin/phpunit tests/Entity/EntryDiscussionTest.php` — Expected: FAIL (class missing).

- [ ] **Step 2: Implement the embeddable**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class EntryDiscussion
{
    #[ORM\Column(name: 'discussion_url', length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'comments_feed_url', length: 2048, nullable: true)]
    private ?string $commentsFeedUrl = null;

    #[ORM\Column(name: 'comments_load', length: 8, nullable: true, enumType: CommentsLoad::class)]
    private ?CommentsLoad $commentsLoad = null;

    public function store(Discussion $discussion): void
    {
        $this->url = self::bounded($discussion->url);
        $this->commentsFeedUrl = self::bounded($discussion->commentsFeedUrl);
        $this->commentsLoad = $this->commentsFeedUrl === null ? null : $discussion->commentsLoad;
    }

    public function read(): Discussion
    {
        if ($this->commentsFeedUrl !== null && $this->commentsLoad !== null) {
            return Discussion::withCommentsFeed($this->url, $this->commentsFeedUrl, $this->commentsLoad);
        }

        return $this->url === null ? Discussion::none() : Discussion::page($this->url);
    }

    private static function bounded(?string $url): ?string
    {
        return $url === null || \strlen($url) > 2048 ? null : $url;
    }
}
```

A URL longer than the column is dropped, not truncated: a cut URL points somewhere else.

Run the test — Expected: PASS.

- [ ] **Step 3: Wire it into `Entry`**

In `Entry.php` add the field after `$mediaSet`, initialise it in the constructor, and add accessors:

```php
    #[ORM\Embedded(class: EntryDiscussion::class, columnPrefix: false)]
    private EntryDiscussion $discussion;
```

constructor: `$this->discussion = new EntryDiscussion();`

```php
    public function getDiscussion(): Discussion
    {
        return $this->discussion->read();
    }

    public function setDiscussion(Discussion $discussion): void
    {
        $this->discussion->store($discussion);
    }
```

(`use App\Service\Discussion\Discussion;`). Run `composer md` on `src/Entity/Entry.php`; the embeddable exists precisely so the field count stays under PHPMD's ceiling (the `EntryMedia` precedent).

- [ ] **Step 4: Write the migration**

`backend/migrations/Version20260924120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924120000 extends AbstractMigration
{
    private const array COLUMNS = [
        'discussion_url' => 'VARCHAR(2048) DEFAULT NULL',
        'comments_feed_url' => 'VARCHAR(2048) DEFAULT NULL',
        'comments_load' => 'VARCHAR(8) DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Add the entry discussion columns (#1140).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn('discussion_url'), 'entry.discussion_url already exists.');

        $platform = $this->connection->getDatabasePlatform();
        foreach (self::COLUMNS as $name => $definition) {
            if ($platform instanceof AbstractMySQLPlatform) {
                $this->addSql(sprintf('ALTER TABLE entry ADD %s %s', $name, $definition));
            } elseif ($platform instanceof SQLitePlatform) {
                $this->addSql(sprintf('ALTER TABLE entry ADD COLUMN %s %s', $name, $definition));
            } else {
                throw new \RuntimeException('Unsupported database platform for the entry discussion migration.');
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::COLUMNS) as $name) {
            $this->addSql(sprintf('ALTER TABLE entry DROP COLUMN %s', $name));
        }
    }
}
```

Verify on both dialects:

```bash
cd backend && rm -f var/migrate-check.db && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate
```

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

```bash
docker compose exec php bin/console doctrine:schema:validate
```

Expected: both `[OK] The database schema is in sync with the mapping files.` The MySQL run also applies the columns to the live dev DB (memory: apply new migrations to the live Docker DB).

- [ ] **Step 5: Store the discussion at ingest — failing test first**

Add to `backend/tests/Service/Ingest/EntryIngestorTest.php` (reuse its fixture for building a `ParsedFeed` and ingesting it):

```php
    public function testStoresTheParsedDiscussion(): void
    {
        $parsed = new ParsedEntry(
            guid: 'g-1',
            url: 'https://blog.example/post',
            title: 'Post',
            author: null,
            summary: null,
            contentHtml: '<p>Body</p>',
            publishedAt: null,
            discussion: Discussion::withCommentsFeed('https://blog.example/post#c', 'https://blog.example/post/feed/', CommentsLoad::Manual),
        );

        $entry = $this->ingestOne($parsed);

        self::assertSame('https://blog.example/post/feed/', $entry->getDiscussion()->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $entry->getDiscussion()->commentsLoad);
    }
```

If the test class has no `ingestOne()` helper, write one next to its existing ingest setup that wraps the entry in `new ParsedFeed('Feed', null, null, null, [$parsed])`, calls `ingest()`, and returns `[0]`.

Run it — Expected: FAIL (`commentsFeedUrl` null). Then in `EntryIngestor::ingest()` add, after `setPublishedAt`:

```php
            $entry->setDiscussion($parsedEntry->discussion);
```

Run — Expected: PASS.

- [ ] **Step 6: Serve it in the entry JSON — failing test first**

In `backend/tests/Controller/Api/EntryControllerTest.php`, add a case that seeds an entry with `setDiscussion(Discussion::withCommentsFeed('https://t.example/1', 'https://t.example/1/.rss', CommentsLoad::Auto))`, requests `GET /api/entries/{id}`, and asserts:

```php
        self::assertSame('https://t.example/1', $body['entry']['discussionUrl']);
        self::assertSame('auto', $body['entry']['comments']);
```

and a second case with no discussion asserting both keys are present and `null`. Run — Expected: FAIL (undefined index).

Implement in `EntryJson::commonFields()` after `'url'`:

```php
            'discussionUrl' => $e->getDiscussion()->url,
            'comments' => $e->getDiscussion()->commentsLoad?->value,
```

and add `discussionUrl: string|null, comments: 'auto'|'manual'|null,` to all three array-shape docblocks. The comments-feed URL itself stays server-side: the client fetches comments by entry id. Run — Expected: PASS.

- [ ] **Step 7: Carry it through backups**

Run the backup field-coverage test first: `php bin/phpunit tests/Service/Backup` — Expected: FAIL naming the three new columns (this is what `BackupFieldDeclarations` exists for). If nothing fails, the coverage test does not see embeddables — then write the round-trip assertion by hand in the backup round-trip test: seed an entry with a comments feed, export, restore into a fresh account, assert `getDiscussion()` equals the original.

Implement:
- `BackupLines::entryLine()` — after `'url'`:

```php
            'discussionUrl' => $entry->getDiscussion()->url,
            'commentsFeedUrl' => $entry->getDiscussion()->commentsFeedUrl,
            'commentsLoad' => $entry->getDiscussion()->commentsLoad?->value,
```

- `EntryLine` — add trailing constructor params `public ?string $discussionUrl = null, public ?string $commentsFeedUrl = null, public ?string $commentsLoad = null,` and in `fromLine()` read them with `LineField::stringOrNull($line, '…')` (a v3 file written before this change has no such keys; `stringOrNull` must treat a missing key as null — check `LineField` and use its missing-key-tolerant accessor if `stringOrNull` throws on absence).
- `EntryBatchInserter::COLUMNS` — append `'discussion_url', 'comments_feed_url', 'comments_load'`, and `row()` — append `$line->discussionUrl, $line->commentsFeedUrl, CommentsLoad::tryFrom((string) $line->commentsLoad)?->value` (an unknown value from a hand-edited file becomes null rather than a row the enum cannot hydrate).

Run `php bin/phpunit tests/Service/Backup` — Expected: PASS.

- [ ] **Step 8: Gates and commit**

Run: `cd backend && composer check && composer md && php bin/phpunit`
Expected: all green.

```bash
git add backend/src/Entity backend/migrations/Version20260924120000.php backend/src/Service/Ingest/EntryIngestor.php backend/src/Http/EntryJson.php backend/src/Service/Backup backend/tests
git commit -m "feat(#1140): persist an entry's discussion and serve it in the entry JSON"
```

---

### Task 3: Platform entry rules, with Reddit as the first

**Files:**
- Create: `backend/src/Service/Ingest/Platform/PlatformEntryRule.php`, `PlatformEntryRules.php`, `RedditEntryRule.php`
- Modify: `backend/config/services.yaml`, `backend/src/Service/Ingest/EntryIngestor.php`
- Test: `backend/tests/Service/Ingest/Platform/RedditEntryRuleTest.php`, `PlatformEntryRulesTest.php`, `PlatformEntryRulesWiringTest.php`, `backend/tests/Fixtures/reddit/subreddit.atom`

**Interfaces:**
- Consumes: `ParsedEntry` (with `discussion`), `Discussion`, `CommentsLoad`.
- Produces: `interface PlatformEntryRule { public function supports(ParsedEntry $entry): bool; public function apply(ParsedEntry $entry): ParsedEntry; }`, tag `app.platform_entry_rule`; `PlatformEntryRules::apply(ParsedEntry $entry): ParsedEntry`.

- [ ] **Step 1: Capture a real fixture**

Save a live subreddit feed as the fixture (this is the artifact the footer regex is derived from — memory: derive filter rules from the artifact):

```bash
curl -s -A "Mozilla/5.0 simple-feed-reader" -o backend/tests/Fixtures/reddit/subreddit.atom "https://www.reddit.com/r/PHP/.rss"
```

Open it and confirm it holds at least one self post (`[link]` href equals the entry `<link>`) and one link post (`[link]` href on another host). If it lacks a link post, fetch another subreddit (`r/programming`) instead. Also note an image post's shape if one is present (`[link]` to `i.redd.it`).

- [ ] **Step 2: Write the failing rule tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Platform;

use App\Enum\CommentsLoad;
use App\Service\Ingest\Platform\RedditEntryRule;
use App\Service\Parser\ParsedEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedditEntryRuleTest extends TestCase
{
    private const string THREAD = 'https://www.reddit.com/r/PHP/comments/1wobnjy/nativephp_mobile_450/';

    private static function footer(string $linkTarget): string
    {
        return ' &#32; submitted by &#32; <a href="https://www.reddit.com/user/someone"> /u/someone </a> <br/>'
            . ' <span><a href="' . $linkTarget . '">[link]</a></span> &#32; <span><a href="' . self::THREAD
            . '">[comments]</a></span>';
    }

    private static function entry(string $url, string $contentHtml): ParsedEntry
    {
        return new ParsedEntry('t3_1wobnjy', $url, 'Title', '/u/someone', null, $contentHtml, null);
    }

    public function testSupportsAThreadUrl(): void
    {
        self::assertTrue((new RedditEntryRule())->supports(self::entry(self::THREAD, '')));
    }

    public function testIgnoresOtherHostsAndNonThreadPaths(): void
    {
        $rule = new RedditEntryRule();

        self::assertFalse($rule->supports(self::entry('https://example.com/r/PHP/comments/1/x/', '')));
        self::assertFalse($rule->supports(self::entry('https://www.reddit.com/r/PHP/', '')));
    }

    public function testSelfPostHasNoArticleAndAnAutoCommentsFeed(): void
    {
        $body = '<div class="md"><p>Question?</p></div>';

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $body . self::footer(self::THREAD)));

        self::assertNull($result->url);
        self::assertSame(self::THREAD, $result->discussion->url);
        self::assertSame(self::THREAD . '.rss', $result->discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $result->discussion->commentsLoad);
        self::assertSame($body, $result->contentHtml);
    }

    public function testLinkPostPointsAtTheExternalArticle(): void
    {
        $result = (new RedditEntryRule())->apply(
            self::entry(self::THREAD, self::footer('http://nativephp.com/blog/nativephp-mobile-450')),
        );

        self::assertSame('http://nativephp.com/blog/nativephp-mobile-450', $result->url);
        self::assertSame(self::THREAD, $result->discussion->url);
    }

    /** @return iterable<string, array{string}> */
    public static function redditHostedTargets(): iterable
    {
        yield 'image' => ['https://i.redd.it/abc123.jpeg'];
        yield 'video' => ['https://v.redd.it/abc123'];
        yield 'gallery' => ['https://www.reddit.com/gallery/1wobnjy'];
        yield 'crosspost' => ['https://www.reddit.com/r/other/comments/9zz/title/'];
    }

    #[DataProvider('redditHostedTargets')]
    public function testRedditHostedTargetsAreNoArticle(string $target): void
    {
        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, self::footer($target)));

        self::assertNull($result->url);
    }

    public function testTableLayoutSurvivesTheFooterStrip(): void
    {
        $html = '<table> <tr><td> <a href="' . self::THREAD . '"><img src="https://b.thumbs.redditmedia.com/t.jpg" /></a> </td><td>'
            . self::footer('https://i.redd.it/abc.jpeg') . ' </td></tr></table>';

        $result = (new RedditEntryRule())->apply(self::entry(self::THREAD, $html));

        self::assertStringContainsString('<img src="https://b.thumbs.redditmedia.com/t.jpg" />', (string) $result->contentHtml);
        self::assertStringNotContainsString('submitted by', (string) $result->contentHtml);
        self::assertStringContainsString('</td></tr></table>', (string) $result->contentHtml);
    }

    public function testKeepsEveryOtherField(): void
    {
        $original = self::entry(self::THREAD, self::footer(self::THREAD));

        $result = (new RedditEntryRule())->apply($original);

        self::assertSame($original->guid, $result->guid);
        self::assertSame($original->title, $result->title);
        self::assertSame($original->author, $result->author);
        self::assertSame($original->media, $result->media);
    }
}
```

Add one fixture-driven test that parses `tests/Fixtures/reddit/subreddit.atom` with `Atom10Parser`, applies the rule to every entry, and asserts for each: `supports()` is true, `contentHtml` does not contain `submitted by`, and `discussion->commentsFeedUrl` ends in `/.rss`.

Run: `php bin/phpunit tests/Service/Ingest/Platform/RedditEntryRuleTest.php` — Expected: FAIL (class missing).

- [ ] **Step 3: Implement the interface and the rule**

`PlatformEntryRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest\Platform;

use App\Service\Parser\ParsedEntry;

interface PlatformEntryRule
{
    public function supports(ParsedEntry $entry): bool;

    public function apply(ParsedEntry $entry): ParsedEntry;
}
```

`RedditEntryRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest\Platform;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use App\Service\Parser\ParsedEntry;

final readonly class RedditEntryRule implements PlatformEntryRule
{
    private const string REDDIT_HOST = '#(^|\.)(reddit\.com|redd\.it)$#i';
    private const string THREAD_PATH = '#/comments/[a-z0-9]+(/|$)#i';
    private const string LINK_TARGET = '#<a href="([^"]+)">\[link\]</a>#';
    private const string FOOTER = '#(?:\s|&\#32;)*submitted by.*?\[comments\]</a>\s*</span>#s';

    public function supports(ParsedEntry $entry): bool
    {
        return $entry->url !== null
            && self::isRedditHosted($entry->url)
            && preg_match(self::THREAD_PATH, (string) parse_url($entry->url, \PHP_URL_PATH)) === 1;
    }

    public function apply(ParsedEntry $entry): ParsedEntry
    {
        $thread = self::withoutQuery((string) $entry->url);

        return new ParsedEntry(
            guid: $entry->guid,
            url: self::externalArticle($entry->contentHtml),
            title: $entry->title,
            author: $entry->author,
            summary: $entry->summary,
            contentHtml: self::withoutFooter($entry->contentHtml),
            publishedAt: $entry->publishedAt,
            media: $entry->media,
            categories: $entry->categories,
            discussion: Discussion::withCommentsFeed($thread, rtrim($thread, '/') . '/.rss', CommentsLoad::Auto),
            authorUrl: $entry->authorUrl,
        );
    }

    private static function externalArticle(?string $contentHtml): ?string
    {
        if ($contentHtml === null || preg_match(self::LINK_TARGET, $contentHtml, $match) !== 1) {
            return null;
        }
        $target = html_entity_decode($match[1], \ENT_QUOTES | \ENT_HTML5);

        return self::isRedditHosted($target) ? null : $target;
    }

    private static function withoutFooter(?string $contentHtml): ?string
    {
        return $contentHtml === null ? null : (string) preg_replace(self::FOOTER, '', $contentHtml);
    }

    private static function isRedditHosted(string $url): bool
    {
        return preg_match(self::REDDIT_HOST, (string) parse_url($url, \PHP_URL_HOST)) === 1;
    }

    private static function withoutQuery(string $url): string
    {
        return strtok($url, '?#') ?: $url;
    }
}
```

Run the rule tests — Expected: PASS. If the fixture test fails on a footer shape the synthetic tests missed, widen `FOOTER` against the fixture, not against a guess.

- [ ] **Step 4: The dispatcher — failing test, then implement**

`PlatformEntryRulesTest`: construct `new PlatformEntryRules([new RedditEntryRule()])` and assert (a) a non-Reddit entry comes back as the same instance (`assertSame`), (b) a Reddit thread entry comes back with `url` rewritten.

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest\Platform;

use App\Service\Parser\ParsedEntry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PlatformEntryRules
{
    /** @param iterable<PlatformEntryRule> $rules */
    public function __construct(
        #[AutowireIterator('app.platform_entry_rule')]
        private iterable $rules,
    ) {
    }

    public function apply(ParsedEntry $entry): ParsedEntry
    {
        foreach ($this->rules as $rule) {
            if ($rule->supports($entry)) {
                return $rule->apply($entry);
            }
        }

        return $entry;
    }
}
```

In `config/services.yaml` under `_instanceof`, after the slideshow block, add:

```yaml
        App\Service\Ingest\Platform\PlatformEntryRule:
            tags: ['app.platform_entry_rule']
```

`PlatformEntryRulesWiringTest` (copy the shape of `tests/Service/Parser/FeedParserWiringTest.php`): boot the kernel, fetch `PlatformEntryRules` from the container, and assert that a Reddit thread `ParsedEntry` is rewritten — this fails if the tag is missing, because the iterator would be empty.

Run the three test files — Expected: PASS.

- [ ] **Step 5: Apply the rules at ingest — failing test first**

In `EntryIngestorTest`, ingest a `ParsedEntry` with `url: 'https://www.reddit.com/r/PHP/comments/1abc/t/'` and a footer whose `[link]` is `https://example.com/a`, then assert the stored entry's `getUrl()` is `https://example.com/a` and `getDiscussion()->commentsLoad` is `CommentsLoad::Auto`. If the test builds `EntryIngestor` by hand, pass `new PlatformEntryRules([new RedditEntryRule()])`; every other `new EntryIngestor(` in tests gets `new PlatformEntryRules([])`.

Run — Expected: FAIL. Implement: add `private readonly PlatformEntryRules $platformRules,` to the `EntryIngestor` constructor and make the rules the first thing `ingest()` does to the entries, so dedupe, url hashing, sanitising and the snippet all see the rewritten entry:

```php
        $entries = array_map($this->platformRules->apply(...), $parsed->entries);
```

then iterate `$entries` instead of `$parsed->entries` in the three places that read them (`guidHashesOf`, `urlHashesOf`, the `foreach`), and hand `$newPairs` the rewritten entry (the category writer only reads `categories`, which the rule keeps).

Run `php bin/phpunit tests/Service/Ingest tests/Service/Refresh` — Expected: PASS.

- [ ] **Step 6: Gates and commit**

`composer check && composer md && php bin/phpunit` — green.

```bash
git add backend/src/Service/Ingest backend/config/services.yaml backend/tests/Service/Ingest backend/tests/Fixtures/reddit
git commit -m "feat(#1140): platform entry rules, and a Reddit rule that separates article from thread"
```

---

### Task 4: Per-host throttle memory shared with refresh

**Files:**
- Create: `backend/src/Service/Fetch/HostThrottle.php`
- Modify: `backend/config/packages/cache.yaml`, `backend/src/Service/FeedScheduler.php`
- Test: `backend/tests/Service/Fetch/HostThrottleTest.php`, `backend/tests/Service/FeedSchedulerTest.php`

**Interfaces:**
- Produces: `HostThrottle::record(string $url, int $seconds): void`, `HostThrottle::remainingSeconds(string $url): int` (0 when free). Keyed by `HostKey::forUrl()`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\HostThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class HostThrottleTest extends TestCase
{
    public function testAFreshHostIsFree(): void
    {
        $throttle = new HostThrottle(new ArrayAdapter(), new MockClock('2026-09-24 12:00:00'));

        self::assertSame(0, $throttle->remainingSeconds('https://www.reddit.com/r/PHP/.rss'));
    }

    public function testARecordedWaitCountsDownAcrossTheWholeHost(): void
    {
        $clock = new MockClock('2026-09-24 12:00:00');
        $throttle = new HostThrottle(new ArrayAdapter(), $clock);

        $throttle->record('https://www.reddit.com/r/PHP/.rss', 60);
        $clock->sleep(15);

        self::assertSame(45, $throttle->remainingSeconds('https://reddit.com/r/PHP/comments/1/x/.rss'));
    }

    public function testTheWaitExpires(): void
    {
        $clock = new MockClock('2026-09-24 12:00:00');
        $throttle = new HostThrottle(new ArrayAdapter(), $clock);

        $throttle->record('https://www.reddit.com/', 60);
        $clock->sleep(61);

        self::assertSame(0, $throttle->remainingSeconds('https://www.reddit.com/'));
    }

    public function testOtherHostsAreUnaffected(): void
    {
        $throttle = new HostThrottle(new ArrayAdapter(), new MockClock('2026-09-24 12:00:00'));

        $throttle->record('https://www.reddit.com/', 60);

        self::assertSame(0, $throttle->remainingSeconds('https://example.com/feed'));
    }
}
```

Run — Expected: FAIL (class missing).

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HostThrottle
{
    public function __construct(
        #[Autowire(service: 'host_throttle.cache')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function record(string $url, int $seconds): void
    {
        $item = $this->cache->getItem(self::key($url));
        $item->set($this->clock->now()->getTimestamp() + $seconds);
        $item->expiresAfter($seconds);
        $this->cache->save($item);
    }

    public function remainingSeconds(string $url): int
    {
        $until = $this->cache->getItem(self::key($url))->get();

        return \is_int($until) ? max(0, $until - $this->clock->now()->getTimestamp()) : 0;
    }

    private static function key(string $url): string
    {
        return 'host_throttle.' . hash('xxh128', HostKey::forUrl($url));
    }
}
```

`config/packages/cache.yaml`, under `pools`:

```yaml
            host_throttle.cache:
                adapter: cache.adapter.filesystem
                default_lifetime: 86400
```

`ArrayAdapter` ignores the clock for expiry, which is why `remainingSeconds()` compares the stored instant itself rather than trusting the item's TTL. Run — Expected: PASS.

- [ ] **Step 3: The scheduler records a host throttle — failing test first**

In `FeedSchedulerTest`, add: construct the scheduler with a `HostThrottle` over an `ArrayAdapter` and the same `MockClock`, call `recordThrottled($feed, 90)` on a feed at `https://www.reddit.com/r/PHP/.rss`, and assert `$throttle->remainingSeconds('https://www.reddit.com/r/x/comments/1/.rss')` is `90`. Update every other `new FeedScheduler(` in `backend/tests` (7 today — `grep -rn "new FeedScheduler(" backend/tests`) to pass a throwaway `new HostThrottle(new ArrayAdapter(), $clock)`.

Run — Expected: FAIL. Implement: add `private readonly HostThrottle $hostThrottle` to `FeedScheduler`'s constructor and, in `recordThrottled()`, after computing `$wait`:

```php
        $this->hostThrottle->record($feed->getUrl(), $wait);
```

Run `php bin/phpunit tests/Service/FeedSchedulerTest.php tests/Service/Refresh` — Expected: PASS.

- [ ] **Step 4: Gates and commit**

`composer check && composer md` — green.

```bash
git add backend/src/Service/Fetch/HostThrottle.php backend/src/Service/FeedScheduler.php backend/config/packages/cache.yaml backend/tests
git commit -m "feat(#1140): remember a rationing host so other callers stop asking it"
```

---

### Task 5: Comments endpoint

**Files:**
- Create: `backend/src/Service/Comments/EntryComment.php`, `CommentsResult.php`, `CommentsLoader.php`, `Exception/NoCommentsFeedException.php`; `backend/src/Http/CommentsJson.php`; `backend/src/Controller/Api/EntryCommentsController.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Service/Comments/CommentsLoaderTest.php`, `backend/tests/Controller/Api/EntryCommentsControllerTest.php`, `backend/tests/Fixtures/reddit/thread-comments.atom`

**Interfaces:**
- Consumes: `Entry::getDiscussion()`, `FeedFetcherInterface::fetch()`, `FeedParser::parse(string $xml): ParsedFeed`, `EntrySanitizer::sanitize()`, `UrlNormalizer::hash()`, `HostThrottle`.
- Produces: `GET /api/entries/{id}/comments` →
  `{"status":"ok","discussionUrl":string|null,"comments":[{"author":string|null,"authorUrl":string|null,"url":string|null,"publishedAt":string|null,"html":string,"byEntryAuthor":bool}]}`
  | `{"status":"throttled","discussionUrl":…,"retryAfter":int}` | `{"status":"failed","discussionUrl":…}`; `404` problem when the entry is not the user's or has no comments feed.

- [ ] **Step 1: Capture the comments fixture**

```bash
curl -s -A "Mozilla/5.0 simple-feed-reader" -o backend/tests/Fixtures/reddit/thread-comments.atom "https://www.reddit.com/r/PHP/comments/1woq4he/what_is_your_php_stack_2026/.rss"
```

Confirm the first `<entry>` has `<id>t3_…</id>` and a `<link href>` equal to the thread URL, and the rest are `t1_…`. (A 429 means wait ~60 s and retry.)

- [ ] **Step 2: Failing loader tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Service\Comments\CommentsLoader;
use App\Service\Discussion\Discussion;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Fetch\HostThrottle;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class CommentsLoaderTest extends KernelTestCase
{
    private const string THREAD = 'https://www.reddit.com/r/PHP/comments/1woq4he/what_is_your_php_stack_2026/';
    private const string FEED = self::THREAD . '.rss';

    private StubFeedFetcher $fetcher;
    private HostThrottle $throttle;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fetcher = new StubFeedFetcher();
        $this->clock = new MockClock('2026-09-24 12:00:00');
        $this->throttle = new HostThrottle(new ArrayAdapter(), $this->clock);
    }

    private function loader(): CommentsLoader
    {
        $container = self::getContainer();
        $container->set(\App\Service\Fetch\FeedFetcherInterface::class, $this->fetcher);
        $container->set(HostThrottle::class, $this->throttle);
        $loader = $container->get(CommentsLoader::class);
        self::assertInstanceOf(CommentsLoader::class, $loader);

        return $loader;
    }

    private static function entry(string $author = '/u/Background_Lie11'): Entry
    {
        $entry = new Entry(new Feed('https://www.reddit.com/r/PHP/.rss'), 't3_1woq4he', null, 'Stack', new \DateTimeImmutable(), new \DateTimeImmutable());
        $entry->setAuthor($author);
        $entry->setDiscussion(Discussion::withCommentsFeed(self::THREAD, self::FEED, CommentsLoad::Auto));

        return $entry;
    }

    public function testParsesCommentsAndDropsThePostItself(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/reddit/thread-comments.atom');
        $this->fetcher->willReturn(self::FEED, FetchResponse::fetched(self::FEED, null, $xml, null, null));

        $result = $this->loader()->load(self::entry());

        self::assertSame('ok', $result->status);
        self::assertNotSame([], $result->comments);
        foreach ($result->comments as $comment) {
            self::assertNotSame(self::THREAD, $comment->url);
            self::assertStringNotContainsString('<script', $comment->html);
        }
    }

    public function testMarksTheEntryAuthorsComments(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/reddit/thread-comments.atom');
        $this->fetcher->willReturn(self::FEED, FetchResponse::fetched(self::FEED, null, $xml, null, null));

        $result = $this->loader()->load(self::entry('/u/Background_Lie11'));

        $byAuthor = array_filter($result->comments, static fn ($c): bool => $c->byEntryAuthor);
        self::assertNotSame([], $byAuthor);
    }

    public function testA429IsThrottledAndRemembered(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedThrottledException('429', null));

        $first = $this->loader()->load(self::entry());
        $second = $this->loader()->load(self::entry());

        self::assertSame('throttled', $first->status);
        self::assertSame(60, $first->retryAfter);
        self::assertSame('throttled', $second->status);
        self::assertCount(1, $this->fetcher->fetchedUrls);
    }

    public function testAKnownThrottleSkipsTheRequest(): void
    {
        $this->throttle->record('https://www.reddit.com/r/PHP/.rss', 40);

        $result = $this->loader()->load(self::entry());

        self::assertSame('throttled', $result->status);
        self::assertSame(40, $result->retryAfter);
        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAFetchFailureIsFailed(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedUnreachableException('HTTP 500', statusCode: 500));

        self::assertSame('failed', $this->loader()->load(self::entry())->status);
    }

    public function testUnparseableBodyIsFailed(): void
    {
        $this->fetcher->willReturn(self::FEED, FetchResponse::fetched(self::FEED, null, '<html>nope', null, null));

        self::assertSame('failed', $this->loader()->load(self::entry())->status);
    }
}
```

Check `FetchResponse::fetched()`'s real signature and `StubFeedFetcher::$fetchedUrls` before running; adjust the calls to match. Run — Expected: FAIL (class missing).

- [ ] **Step 3: Implement the value objects and the exception**

`EntryComment.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments;

final readonly class EntryComment
{
    public function __construct(
        public ?string $author,
        public ?string $authorUrl,
        public ?string $url,
        public ?\DateTimeImmutable $publishedAt,
        public string $html,
        public bool $byEntryAuthor,
    ) {
    }
}
```

`CommentsResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments;

final readonly class CommentsResult
{
    /** @param list<EntryComment> $comments */
    private function __construct(
        public string $status,
        public array $comments = [],
        public ?int $retryAfter = null,
    ) {
    }

    /** @param list<EntryComment> $comments */
    public static function ok(array $comments): self
    {
        return new self('ok', $comments);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self('throttled', retryAfter: $retryAfter);
    }

    public static function failed(): self
    {
        return new self('failed');
    }
}
```

`Exception/NoCommentsFeedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments\Exception;

final class NoCommentsFeedException extends \RuntimeException
{
}
```

- [ ] **Step 4: Implement the loader**

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments;

use App\Entity\Entry;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\ParsedEntry;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Url\UrlNormalizer;

final readonly class CommentsLoader
{
    private const int UNSTATED_WAIT_SECONDS = 60;

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
        private EntrySanitizer $sanitizer,
        private UrlNormalizer $urlNormalizer,
        private HostThrottle $hostThrottle,
    ) {
    }

    public function load(Entry $entry): CommentsResult
    {
        $feedUrl = $entry->getDiscussion()->commentsFeedUrl
            ?? throw new NoCommentsFeedException('The entry has no comments feed.');

        $wait = $this->hostThrottle->remainingSeconds($feedUrl);
        if ($wait > 0) {
            return CommentsResult::throttled($wait);
        }

        try {
            $body = (string) $this->fetcher->fetch($feedUrl)->body;

            return CommentsResult::ok($this->comments($entry, $this->parser->parse($body)->entries));
        } catch (FeedThrottledException $e) {
            $wait = $e->retryAfterSeconds ?? self::UNSTATED_WAIT_SECONDS;
            $this->hostThrottle->record($feedUrl, $wait);

            return CommentsResult::throttled($wait);
        } catch (FetchException | FeedParseException) {
            return CommentsResult::failed();
        }
    }

    /**
     * @param list<ParsedEntry> $parsed
     *
     * @return list<EntryComment>
     */
    private function comments(Entry $entry, array $parsed): array
    {
        $postHash = $this->urlNormalizer->hash($entry->getDiscussion()->url);
        $comments = [];
        foreach ($parsed as $item) {
            if ($postHash !== null && $this->urlNormalizer->hash($item->url) === $postHash) {
                continue;
            }
            $comments[] = new EntryComment(
                $item->author,
                $item->authorUrl,
                $item->url,
                $item->publishedAt,
                (string) $this->sanitizer->sanitize($item->contentHtml),
                $item->author !== null && $item->author === $entry->getAuthor(),
            );
        }

        return $comments;
    }
}
```

Check `DateParser::parse()` returns UTC-normalised values (the Gotchas rule); if not, convert with `->setTimezone(new \DateTimeZone('UTC'))` when serialising in `CommentsJson`. Check `FetchResponse::$body`'s real name. Run the loader tests — Expected: PASS.

- [ ] **Step 5: JSON mapper, limiter, controller — failing controller test first**

`EntryCommentsControllerTest` (copy `setUp()`, `auth()`, `seedEntry()` from `EntryReaderControllerTest`, and install a `StubFeedFetcher` via `self::getContainer()->set(FeedFetcherInterface::class, $stub)`):

- `testOkReturnsComments` — seed with a comments feed, stub the fixture body, `GET /api/entries/{id}/comments` → 200, `status` `ok`, `discussionUrl` set, `comments[0]` has keys `author`, `authorUrl`, `url`, `publishedAt`, `html`, `byEntryAuthor`.
- `testThrottledCarriesRetryAfter` — stub a `FeedThrottledException('429', 30)` → 200, `status` `throttled`, `retryAfter` `30`.
- `testEntryWithoutCommentsFeedIs404` — seed with no discussion → 404, `Content-Type` `application/problem+json`.
- `testSomeoneElsesEntryIs404`.
- `testUnauthenticatedIs401`.

Run — Expected: FAIL (route missing).

`backend/src/Http/CommentsJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Comments\CommentsResult;
use App\Service\Comments\EntryComment;

final class CommentsJson
{
    /** @return array<string, mixed> */
    public static function one(CommentsResult $result, ?string $discussionUrl): array
    {
        return match ($result->status) {
            'ok' => [
                'status' => 'ok',
                'discussionUrl' => $discussionUrl,
                'comments' => array_map(self::comment(...), $result->comments),
            ],
            'throttled' => ['status' => 'throttled', 'discussionUrl' => $discussionUrl, 'retryAfter' => $result->retryAfter],
            default => ['status' => 'failed', 'discussionUrl' => $discussionUrl],
        };
    }

    /** @return array<string, string|bool|null> */
    private static function comment(EntryComment $comment): array
    {
        return [
            'author' => $comment->author,
            'authorUrl' => $comment->authorUrl,
            'url' => $comment->url,
            'publishedAt' => $comment->publishedAt?->format(\DateTimeInterface::ATOM),
            'html' => $comment->html,
            'byEntryAuthor' => $comment->byEntryAuthor,
        ];
    }
}
```

`rate_limiter.yaml`, after `reader`:

```yaml
        # Per-user cap on comments-feed fetches. Each call is at most one
        # outbound request, and the client caches for an hour.
        comments:
            policy: 'sliding_window'
            limit: 30
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```

`EntryCommentsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CommentsJson;
use App\Repository\EntryListRepository;
use App\Service\Comments\CommentsLoader;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryCommentsController
{
    public function __construct(
        private EntryListRepository $entryList,
        private CommentsLoader $comments,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    #[Route('/{id}/comments', name: 'api_entries_comments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function comments(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->findOneSubscribedByUser($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such entry.');

        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $user);

        try {
            $result = $this->comments->load($entry);
        } catch (NoCommentsFeedException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return new JsonResponse(CommentsJson::one($result, $entry->getDiscussion()->url));
    }
}
```

The limiter binds by argument name `$commentsLimiter` → `limiter.comments` (the `$readerLimiter` precedent). If autowiring does not pick it up, add the `bind` the reader limiter uses in `services.yaml`.

Run the controller tests — Expected: PASS.

- [ ] **Step 6: Gates, dev log, commit**

`composer check && composer md && php bin/phpunit && docker compose exec php composer test` — green on both legs. Scan today's dev log for deprecations: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'` — expected: nothing new.

```bash
git add backend/src/Service/Comments backend/src/Http/CommentsJson.php backend/src/Controller/Api/EntryCommentsController.php backend/config/packages/rate_limiter.yaml backend/tests
git commit -m "feat(#1140): GET /api/entries/{id}/comments over the entry's comments feed"
```

---

### Task 6: Frontend models, API and CommentsService

**Files:**
- Create: `frontend/src/app/reader/comments.service.ts`, `comments.service.spec.ts`
- Modify: `frontend/src/app/reader/models.ts`, `reader-api.ts`, `reader-api.spec.ts`

**Interfaces:**
- Produces (models.ts):

```ts
export type CommentsLoad = 'auto' | 'manual';

export interface EntryCommentDto {
  author: string | null;
  authorUrl: string | null;
  url: string | null;
  publishedAt: string | null;
  html: string;
  byEntryAuthor: boolean;
}

export type CommentsResponse =
  | { status: 'ok'; discussionUrl: string | null; comments: EntryCommentDto[] }
  | { status: 'throttled'; discussionUrl: string | null; retryAfter: number }
  | { status: 'failed'; discussionUrl: string | null };
```

  and on `EntryDto`: `discussionUrl: string | null;` and `comments: CommentsLoad | null;` (each with a one-line doc comment in the file's style: "The entry's discussion page — Reddit thread, HN item — or null." / "Whether the entry has a comments feed, and whether it loads without a click.").
- `ReaderApi.comments(entryId: number): Observable<CommentsResponse>`.
- `CommentsService`: `state(id: number): Signal<CommentsState>`, `load(id: number): void`, `reload(id: number): void`, where

```ts
export type CommentsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ok'; comments: EntryCommentDto[] }
  | { status: 'throttled'; retryAt: number }
  | { status: 'failed' };
```

  `retryAt` is an epoch-ms instant so a countdown needs no second timer source.

- [ ] **Step 1: Add the types and the API method; failing API test**

In `reader-api.spec.ts`, add a test that calls `api.comments(7)`, expects one `GET` to `${base}/api/entries/7/comments`, flushes `{ status: 'failed', discussionUrl: null }` and asserts it arrives. Run in the container: `docker compose exec -T frontend npx jest src/app/reader/reader-api.spec.ts` — Expected: FAIL. Implement in `reader-api.ts` next to `readerContent`:

```ts
  comments(entryId: number): Observable<CommentsResponse> {
    return this.http.get<CommentsResponse>(`${this.base}/api/entries/${entryId}/comments`);
  }
```

Add the model types. Fix every `EntryDto` literal in specs and test builders that the compiler now rejects (`grep -rln "isViewed:" frontend/src/app --include='*.spec.ts'` and `reader/testing/`) by adding `discussionUrl: null, comments: null` — prefer updating the shared builder in `reader/testing/` if one exists. Run — Expected: PASS.

- [ ] **Step 2: Failing service tests**

```ts
import { TestBed } from '@angular/core/testing';
import { of, throwError, Subject } from 'rxjs';
import { CommentsService } from './comments.service';
import { ReaderApi } from './reader-api';
import { CommentsResponse } from './models';

describe('CommentsService', () => {
  let api: { comments: jest.Mock };
  let service: CommentsService;

  const ok: CommentsResponse = {
    status: 'ok',
    discussionUrl: 'https://t.example/1',
    comments: [
      { author: '/u/a', authorUrl: null, url: null, publishedAt: null, html: '<p>x</p>', byEntryAuthor: false },
    ],
  };

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-09-24T12:00:00Z'));
    api = { comments: jest.fn(() => of(ok)) };
    TestBed.configureTestingModule({ providers: [{ provide: ReaderApi, useValue: api }] });
    service = TestBed.inject(CommentsService);
  });

  afterEach(() => jest.useRealTimers());

  it('starts idle and fetches nothing until asked', () => {
    expect(service.state(1)()).toEqual({ status: 'idle' });
    expect(api.comments).not.toHaveBeenCalled();
  });

  it('loads once and serves the cache inside the hour', () => {
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({ status: 'ok', comments: ok.comments });
  });

  it('refetches once the hour has passed', () => {
    service.load(1);
    jest.setSystemTime(new Date('2026-09-24T13:00:01Z'));
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('reload bypasses the cache', () => {
    service.load(1);
    service.reload(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('maps throttled to a retry instant', () => {
    api.comments.mockReturnValue(of({ status: 'throttled', discussionUrl: null, retryAfter: 40 }));
    service.load(1);
    expect(service.state(1)()).toEqual({ status: 'throttled', retryAt: Date.parse('2026-09-24T12:00:40Z') });
  });

  it('does not cache a throttled or failed result', () => {
    api.comments.mockReturnValue(of({ status: 'failed', discussionUrl: null }));
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(2);
  });

  it('maps a transport error to failed', () => {
    api.comments.mockReturnValue(throwError(() => new Error('offline')));
    service.load(1);
    expect(service.state(1)()).toEqual({ status: 'failed' });
  });

  it('ignores a second load while one is in flight', () => {
    const pending = new Subject<CommentsResponse>();
    api.comments.mockReturnValue(pending);
    service.load(1);
    service.load(1);
    expect(api.comments).toHaveBeenCalledTimes(1);
    expect(service.state(1)()).toEqual({ status: 'loading' });
  });
});
```

Run: `docker compose exec -T frontend npx jest src/app/reader/comments.service.spec.ts` — Expected: FAIL (module missing).

- [ ] **Step 3: Implement**

```ts
import { Injectable, Signal, WritableSignal, inject, signal } from '@angular/core';
import { onIdentityChange } from '../core/session-identity';
import { CommentsResponse, EntryCommentDto } from './models';
import { ReaderApi } from './reader-api';

const CACHE_CAP = 50;
const FRESH_MS = 60 * 60 * 1000;

export type CommentsState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ok'; comments: EntryCommentDto[] }
  | { status: 'throttled'; retryAt: number }
  | { status: 'failed' };

interface Slot {
  state: WritableSignal<CommentsState>;
  loadedAt: number | null;
}

/** In memory on purpose: comments go stale within the hour, so a reload is a fair reason to refetch. */
@Injectable({ providedIn: 'root' })
export class CommentsService {
  private readonly api = inject(ReaderApi);
  private readonly slots = new Map<number, Slot>();
  private generation = 0;

  constructor() {
    onIdentityChange(() => this.clear());
  }

  state(id: number): Signal<CommentsState> {
    return this.slotFor(id).state.asReadonly();
  }

  load(id: number): void {
    const slot = this.slotFor(id);
    if (slot.state().status === 'loading' || this.isFresh(slot)) return;
    this.fetch(id, slot);
  }

  reload(id: number): void {
    const slot = this.slotFor(id);
    if (slot.state().status === 'loading') return;
    this.fetch(id, slot);
  }

  private isFresh(slot: Slot): boolean {
    return slot.loadedAt !== null && Date.now() - slot.loadedAt < FRESH_MS;
  }

  private slotFor(id: number): Slot {
    const slot = this.slots.get(id) ?? { state: signal<CommentsState>({ status: 'idle' }), loadedAt: null };
    this.slots.delete(id);
    this.slots.set(id, slot);
    const oldest = this.slots.size > CACHE_CAP ? this.slots.keys().next().value : undefined;
    if (oldest !== undefined) this.slots.delete(oldest);
    return slot;
  }

  private fetch(id: number, slot: Slot): void {
    const generation = this.generation;
    slot.state.set({ status: 'loading' });
    slot.loadedAt = null;
    this.api.comments(id).subscribe({
      next: (response) => {
        if (generation !== this.generation) return;
        this.settle(slot, response);
      },
      error: () => {
        if (generation !== this.generation) return;
        slot.state.set({ status: 'failed' });
      },
    });
  }

  private settle(slot: Slot, response: CommentsResponse): void {
    if (response.status === 'ok') {
      slot.state.set({ status: 'ok', comments: response.comments });
      slot.loadedAt = Date.now();
      return;
    }
    if (response.status === 'throttled') {
      slot.state.set({ status: 'throttled', retryAt: Date.now() + response.retryAfter * 1000 });
      return;
    }
    slot.state.set({ status: 'failed' });
  }

  private clear(): void {
    this.generation++;
    this.slots.clear();
  }
}
```

Run — Expected: PASS.

- [ ] **Step 4: Gate and commit**

`docker compose exec -T frontend npm run check` — green.

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts frontend/src/app/reader/comments.service.ts frontend/src/app/reader/comments.service.spec.ts frontend/src/app/reader/testing
git commit -m "feat(#1140): comments API client and an hour-long in-memory cache"
```

---

### Task 7: Reader view — no extraction without an article URL, and a Discussion link

**Files:**
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts`, `.html`, `.spec.ts`; `frontend/public/i18n/en.json`, `de.json`

**Interfaces:**
- Consumes: `EntryDto.url`, `EntryDto.discussionUrl`.
- Produces: reader state gains `{ status: 'feed-only' }`.

- [ ] **Step 1: Failing specs**

In `reader-view.component.spec.ts` (reuse its harness for setting the entry input and its `ReaderContentService` mock):

```ts
  it('does not ask for an extraction when the entry has no article URL', () => {
    setEntry({ ...baseEntry, url: null, discussionUrl: 'https://www.reddit.com/r/x/comments/1/t/' });
    expect(readerContent.load).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('.reader-fallback')).toBeNull();
    expect(fixture.nativeElement.querySelector('.mode')).toBeNull();
  });

  it('links the discussion page when there is one', () => {
    setEntry({ ...baseEntry, discussionUrl: 'https://news.ycombinator.com/item?id=1' });
    const link = fixture.nativeElement.querySelector('a.discussion-link');
    expect(link.getAttribute('href')).toBe('https://news.ycombinator.com/item?id=1');
  });
```

Adapt `setEntry`/`baseEntry`/`readerContent` to the names the spec file already uses. Run: `docker compose exec -T frontend npx jest src/app/reader/reader-view` — Expected: FAIL.

- [ ] **Step 2: Implement**

In `reader-view.component.ts`, widen the state union (line ~231) with `| { status: 'feed-only' }`, and in the entry effect replace `this.runLoad(this.reader.load(e.id));` with:

```ts
      if (e.url === null) {
        this.loadSub?.unsubscribe();
        this.state.set({ status: 'feed-only' });
        this.readerMode.setOriginalOnly();
        return;
      }
      this.runLoad(this.reader.load(e.id));
```

`failed()` stays `status === 'failed'`, so the fallback warning does not render; `heroSource()` returns `null` for `feed-only`, so no hero either. Check `refreshArticle()` and the `mode()`-toggle template guard: the toggle already hides when the mode service is original-only — confirm in the spec, not by assumption.

In the `.meta` line of the template, after the `@if (e.url) { … }` block:

```html
        @if (e.discussionUrl) {
          ·
          <a class="discussion-link" [href]="e.discussionUrl" target="_blank" rel="noopener noreferrer"
            >{{ 'reader.discussion' | transloco }} <app-icon name="forum" size="text"
          /></a>
        }
```

i18n — `en.json` under `"reader"`: `"discussion": "Discussion"`; `de.json`: `"discussion": "Diskussion"`.

Run — Expected: PASS.

- [ ] **Step 3: Gate and commit**

`docker compose exec -T frontend npm run check` — green.

```bash
git add frontend/src/app/reader/reader-view frontend/public/i18n
git commit -m "feat(#1140): skip extraction for entries without an article URL, link the discussion"
```

---

### Task 8: The comments section

**Files:**
- Create: `frontend/src/app/reader/entry-comments/entry-comments.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/reader/reader-view/reader-view.component.ts` (imports), `.html`; `frontend/public/i18n/en.json`, `de.json`

**Interfaces:**
- Consumes: `CommentsService` (Task 6), `EntryDto.comments`, `EntryDto.discussionUrl`, `relativeTime` from `../format`, `LanguageService` for the locale (check how `reader-view` gets its locale for `when(e)` and do the same).
- Produces: `<app-entry-comments [entry]="e" />`.

Agreed layout (mockup "A · quiet list", #1140): heading `Comments` + muted count + icon-only reload button at the right; hairline-separated rows at comfortable density; author link in accent, `OP` pill when `byEntryAuthor`, muted relative time linking the comment permalink; body at `--fs-read`; closing link "All comments" to `discussionUrl`. States: auto → IntersectionObserver-triggered load; manual → `Load comments` button; loading → `<app-skeleton>`; ok/0 → "No comments yet."; throttled → `<app-warning-box>` with countdown, `Try again`, `Open discussion`; failed → quiet text with `Try again` and `Open discussion`.

- [ ] **Step 1: i18n keys**

`en.json` → inside `"reader"`:

```json
    "comments": {
      "heading": "Comments",
      "reload": "Reload comments",
      "load": "Load comments",
      "loading": "Loading comments",
      "empty": "No comments yet.",
      "op": "OP",
      "all": "All comments",
      "openDiscussion": "Open discussion",
      "retry": "Try again",
      "throttled": "The site is rate-limiting. Try again in {{ seconds }} s.",
      "throttledNow": "The site is rate-limiting. Try again now.",
      "failed": "Comments could not be loaded."
    },
```

`de.json`:

```json
    "comments": {
      "heading": "Kommentare",
      "reload": "Kommentare neu laden",
      "load": "Kommentare laden",
      "loading": "Kommentare werden geladen",
      "empty": "Noch keine Kommentare.",
      "op": "OP",
      "all": "Alle Kommentare",
      "openDiscussion": "Diskussion öffnen",
      "retry": "Erneut versuchen",
      "throttled": "Die Seite drosselt Anfragen. Erneut versuchen in {{ seconds }} s.",
      "throttledNow": "Die Seite drosselt Anfragen. Jetzt erneut versuchen.",
      "failed": "Kommentare konnten nicht geladen werden."
    },
```

The throttle copy says "the site", not "Reddit": the component is general, and the endpoint does not name the platform.

- [ ] **Step 2: Failing component specs**

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { EntryCommentsComponent } from './entry-comments.component';
import { CommentsService, CommentsState } from '../comments.service';
import { provideTranslocoTesting } from '../testing/transloco-testing';

describe('EntryCommentsComponent', () => {
  let fixture: ComponentFixture<EntryCommentsComponent>;
  let state: ReturnType<typeof signal<CommentsState>>;
  let service: { state: jest.Mock; load: jest.Mock; reload: jest.Mock };
  let observe: jest.Mock;
  let trigger: (visible: boolean) => void;

  beforeEach(() => {
    state = signal<CommentsState>({ status: 'idle' });
    service = { state: jest.fn(() => state), load: jest.fn(), reload: jest.fn() };
    observe = jest.fn();
    (globalThis as any).IntersectionObserver = jest.fn((cb: IntersectionObserverCallback) => {
      trigger = (visible) => cb([{ isIntersecting: visible } as IntersectionObserverEntry], {} as IntersectionObserver);
      return { observe, disconnect: jest.fn() };
    });
    TestBed.configureTestingModule({
      imports: [EntryCommentsComponent],
      providers: [{ provide: CommentsService, useValue: service }, provideTranslocoTesting()],
    });
    fixture = TestBed.createComponent(EntryCommentsComponent);
  });

  function show(comments: 'auto' | 'manual'): void {
    fixture.componentRef.setInput('entry', {
      id: 5, author: '/u/op', comments, discussionUrl: 'https://www.reddit.com/r/x/comments/1/t/',
    });
    fixture.detectChanges();
  }

  it('auto-loads only once the section nears the viewport', () => {
    show('auto');
    expect(service.load).not.toHaveBeenCalled();
    trigger(true);
    expect(service.load).toHaveBeenCalledWith(5);
  });

  it('never auto-loads a manual source; the button does', () => {
    show('manual');
    trigger(true);
    expect(service.load).not.toHaveBeenCalled();
    fixture.nativeElement.querySelector('button.load').click();
    expect(service.load).toHaveBeenCalledWith(5);
  });

  it('renders comments with the OP pill and the count', () => {
    show('auto');
    state.set({
      status: 'ok',
      comments: [
        { author: '/u/op', authorUrl: 'https://www.reddit.com/user/op', url: 'https://c/1', publishedAt: null, html: '<p>Hi</p>', byEntryAuthor: true },
        { author: '/u/b', authorUrl: null, url: null, publishedAt: null, html: '<p>Yo</p>', byEntryAuthor: false },
      ],
    });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelectorAll('.comment').length).toBe(2);
    expect(el.querySelectorAll('.op').length).toBe(1);
    expect(el.querySelector('.count')?.textContent?.trim()).toBe('2');
  });

  it('shows the empty line for zero comments', () => {
    show('auto');
    state.set({ status: 'ok', comments: [] });
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.empty')).not.toBeNull();
  });

  it('reload calls the service', () => {
    show('auto');
    state.set({ status: 'ok', comments: [] });
    fixture.detectChanges();
    fixture.nativeElement.querySelector('button.reload').click();
    expect(service.reload).toHaveBeenCalledWith(5);
  });

  it('shows the throttle box and retries through reload', () => {
    show('auto');
    state.set({ status: 'throttled', retryAt: Date.now() + 30_000 });
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('app-warning-box')).not.toBeNull();
    el.querySelector<HTMLButtonElement>('button.retry')!.click();
    expect(service.reload).toHaveBeenCalledWith(5);
  });

  it('shows a quiet failure with a retry', () => {
    show('auto');
    state.set({ status: 'failed' });
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.failed')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('app-warning-box')).toBeNull();
  });
});
```

Use the transloco testing provider the other reader specs use (grep `provideTransloco` in `reader/**/*.spec.ts`) — replace `provideTranslocoTesting` with it. Run: `docker compose exec -T frontend npx jest src/app/reader/entry-comments` — Expected: FAIL.

- [ ] **Step 3: Implement the component**

`entry-comments.component.ts`:

```ts
import {
  ChangeDetectionStrategy, Component, DestroyRef, ElementRef, NgZone, computed, inject, input, signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { CommentsService } from '../comments.service';
import { LanguageService } from '../../core/language.service';
import { relativeTime } from '../format';
import { EntryDto } from '../models';

type CommentsEntry = Pick<EntryDto, 'id' | 'comments' | 'discussionUrl'>;

@Component({
  selector: 'app-entry-comments',
  imports: [TranslocoPipe, IconComponent, SkeletonComponent, WarningBoxComponent],
  templateUrl: './entry-comments.component.html',
  styleUrl: './entry-comments.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryCommentsComponent {
  readonly entry = input.required<CommentsEntry>();

  private readonly service = inject(CommentsService);
  private readonly language = inject(LanguageService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly now = signal(Date.now());

  readonly state = computed(() => this.service.state(this.entry().id)());
  readonly retryInSeconds = computed(() => {
    const s = this.state();
    return s.status === 'throttled' ? Math.max(0, Math.ceil((s.retryAt - this.now()) / 1000)) : 0;
  });

  constructor() {
    const destroyRef = inject(DestroyRef);
    const observer = new IntersectionObserver(
      ([hit]) => {
        if (hit?.isIntersecting && this.entry().comments === 'auto') this.service.load(this.entry().id);
      },
      { rootMargin: '400px 0px' },
    );
    observer.observe(this.host.nativeElement);
    const tick = inject(NgZone).runOutsideAngular(() => setInterval(() => this.now.set(Date.now()), 1000));
    destroyRef.onDestroy(() => {
      observer.disconnect();
      clearInterval(tick);
    });
  }

  load(): void {
    this.service.load(this.entry().id);
  }

  reload(): void {
    this.service.reload(this.entry().id);
  }

  when(iso: string | null): string {
    return iso === null ? '' : relativeTime(iso, this.language.current());
  }
}
```

The ticker runs outside the zone because an in-zone `setInterval` hangs `whenStable()`; signals do not need the zone to update the view. Check `LanguageService`'s real accessor name and use whatever `reader-view` calls for `when(e)`. The observer fires once per crossing, and `load()` is idempotent inside the hour, so re-entering the viewport costs nothing.

`entry-comments.component.html`:

```html
@let s = state();
<section class="comments" [attr.aria-busy]="s.status === 'loading' ? 'true' : null">
  <header class="head">
    <h2 class="title">{{ 'reader.comments.heading' | transloco }}</h2>
    @if (s.status === 'ok') {
      <span class="count">{{ s.comments.length }}</span>
      <button
        type="button"
        class="reload"
        [attr.aria-label]="'reader.comments.reload' | transloco"
        [title]="'reader.comments.reload' | transloco"
        (click)="reload()"
      >
        <app-icon name="refresh" size="sm" />
      </button>
    }
  </header>

  @switch (s.status) {
    @case ('idle') {
      @if (entry().comments === 'manual') {
        <button type="button" class="load" (click)="load()">
          <app-icon name="forum" size="sm" /> {{ 'reader.comments.load' | transloco }}
        </button>
      }
    }
    @case ('loading') {
      <app-skeleton [label]="'reader.comments.loading' | transloco" [rows]="3" />
    }
    @case ('ok') {
      @if (s.comments.length === 0) {
        <p class="empty">{{ 'reader.comments.empty' | transloco }}</p>
      } @else {
        <ol class="list">
          @for (c of s.comments; track $index) {
            <li class="comment">
              <p class="byline">
                @if (c.authorUrl) {
                  <a class="author" [href]="c.authorUrl" target="_blank" rel="noopener noreferrer">{{ c.author }}</a>
                } @else {
                  <span class="author">{{ c.author }}</span>
                }
                @if (c.byEntryAuthor) {
                  <span class="op">{{ 'reader.comments.op' | transloco }}</span>
                }
                @if (c.publishedAt) {
                  ·
                  @if (c.url) {
                    <a class="when" [href]="c.url" target="_blank" rel="noopener noreferrer">{{ when(c.publishedAt) }}</a>
                  } @else {
                    <span class="when">{{ when(c.publishedAt) }}</span>
                  }
                }
              </p>
              <div class="body" [innerHTML]="c.html"></div>
            </li>
          }
        </ol>
      }
      @if (entry().discussionUrl; as url) {
        <a class="all" [href]="url" target="_blank" rel="noopener noreferrer"
          >{{ 'reader.comments.all' | transloco }} <app-icon name="open_in_new" size="text"
        /></a>
      }
    }
    @case ('throttled') {
      <app-warning-box class="throttled">
        <p class="note">
          @if (retryInSeconds() > 0) {
            {{ 'reader.comments.throttled' | transloco: { seconds: retryInSeconds() } }}
          } @else {
            {{ 'reader.comments.throttledNow' | transloco }}
          }
          <button type="button" class="retry" (click)="reload()">{{ 'reader.comments.retry' | transloco }}</button>
          @if (entry().discussionUrl; as url) {
            <a [href]="url" target="_blank" rel="noopener noreferrer">{{ 'reader.comments.openDiscussion' | transloco }}</a>
          }
        </p>
      </app-warning-box>
    }
    @case ('failed') {
      <p class="failed">
        {{ 'reader.comments.failed' | transloco }}
        <button type="button" class="retry" (click)="reload()">{{ 'reader.comments.retry' | transloco }}</button>
        @if (entry().discussionUrl; as url) {
          <a [href]="url" target="_blank" rel="noopener noreferrer">{{ 'reader.comments.openDiscussion' | transloco }}</a>
        }
      </p>
    }
  }
</section>
```

Retry goes through `reload()` because a throttled or failed slot is never fresh, so `load()` would work too — `reload()` states the intent.

`entry-comments.component.scss` — tokens only; the `.retry`/link inline actions reuse the reader's `.reader-note-link` look (copy its declarations from `reader-view.component.scss` rather than inventing a second button style):

```scss
:host {
  display: block;
  margin-top: var(--space-6);
  padding-top: var(--space-5);
  border-top: 1px solid var(--border-strong);
}

.head {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}

.title {
  margin: 0;
  font-size: var(--fs-lg);
  line-height: var(--lh-tight);
}

.count {
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.reload {
  margin-left: auto;
}

.list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.comment {
  padding: var(--row-pad-comfy-y) 0;
  border-bottom: 1px solid var(--border);

  &:last-child {
    border-bottom: 0;
  }
}

.byline {
  margin: 0;
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

.author {
  color: var(--accent);
  font-weight: 500;
}

.when {
  color: var(--text-muted);
}

.op {
  margin-left: var(--space-1);
  padding: 0 var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: var(--fs-xs);
}

.body {
  margin-top: var(--space-1);
  font-size: var(--fs-read);
  line-height: var(--lh-normal);
  color: var(--text-primary);
}

.all {
  display: inline-block;
  margin-top: var(--space-3);
  font-size: var(--fs-sm);
  color: var(--accent);
}

.empty,
.failed {
  color: var(--text-secondary);
  font-size: var(--fs-sm);
}
```

Style the `.reload` and `.load` buttons with the shared `appListAction` / `appIconButton` directive the reader toolbar uses rather than bespoke CSS (`button[appIconButton]` is in `shared/icon-button/`). Use the same icon family as the rest of the app (`<app-icon>` Material Symbols: `refresh`, `forum`, `open_in_new`).

Run the component specs — Expected: PASS.

- [ ] **Step 4: Mount it in the reader view — failing spec first**

In `reader-view.component.spec.ts`: an entry with `comments: 'auto'` renders `app-entry-comments`; an entry with `comments: null` does not; while `loading()` the section is absent. Run — Expected: FAIL. Implement: import `EntryCommentsComponent` in `reader-view.component.ts`'s `imports`, and in the template, inside `<article>`, after the categories block:

```html
      @if (!loading() && e.comments) {
        <app-entry-comments [entry]="e" />
      }
```

Run the reader-view and entry-comments specs — Expected: PASS.

- [ ] **Step 5: Gate and commit**

`docker compose exec -T frontend npm run check` — green (Stylelint catches any raw px or hex).

```bash
git add frontend/src/app/reader/entry-comments frontend/src/app/reader/reader-view frontend/public/i18n
git commit -m "feat(#1140): lazy comments section below the article"
```

---

### Task 9: Verify in the real app and open the PR

- [ ] **Step 1: Containers are current** (memory *verify-containers-are-current*): `docker compose exec php bin/console cache:clear`, restart the worker (`docker compose restart worker` — it holds stale code), and confirm `:4200` serves the new chunk (hard reload; the dev container can serve a stale chunk).

- [ ] **Step 2: Real Reddit feed.** Subscribe the dev account to `https://www.reddit.com/r/PHP/.rss` (or refresh it if subscribed) so new entries ingest through the rule. In the browser pane (Mobile viewport — the built-in browser UA is bot-blocked on desktop):
  - open a **self post**: no "Loading reader view", no fallback warning, body without "submitted by", "Discussion" link in the meta line, comments appear on open; the author's own comments show `OP`.
  - open a **link post**: the external article is extracted; comments below; "Open original" goes to the external site.
  - press j/k through several posts quickly: the network tab shows a comments request only for posts whose section came near the viewport.
  - hit reload on the comments twice in a row to provoke a 429 and see the amber box with a countdown; wait it out and retry.
  - dark mode and phone width: nothing overflows.

- [ ] **Step 3: A manual source.** Subscribe to a WordPress blog whose feed has `wfw:commentRss` (most WordPress feeds do, e.g. `https://wordpress.org/news/feed/`), open a post, confirm the `Load comments` button and no request before the click.

- [ ] **Step 4: Full gates, both legs**

```bash
cd backend && composer check && composer md && php bin/phpunit
```

```bash
docker compose exec php composer test
```

```bash
cd backend && composer infection:diff
```

```bash
docker compose exec -T frontend npm run check
```

Scan today's dev log for warnings (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq -c 'select(.level >= 300)'`).

- [ ] **Step 5: Push and open the PR** into `develop` with body `Closes #1140`, a summary, the gate results, the manual verification notes, and the note that no `ReaderCacheService.VERSION` bump is needed (and why).
