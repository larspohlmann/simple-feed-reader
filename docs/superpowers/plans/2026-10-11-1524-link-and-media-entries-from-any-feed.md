# #1524 Link and Media Entries From Any Feed — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Any feed entry that is really a shared link or a shared image/video gets the magazine's `link` or `media` block, decided by one persisted share classification instead of `titleDerived`.

**Architecture:** A new `Service/Share` module classifies a stored `Entry` into a `Share` (kind `link`/`media` + target url) held by an `EntryShare` embeddable. `ShareEnrichmentQueue` (renamed from `PostEnrichmentQueue`) runs the classifier right after ingest and queues card enrichment for every `link` share; the card pass (`ShareLinkCards`, renamed from `PostLinkCards`) fetches a titled share's target and turns it into `media` when the page declares an image or video file. The API sends `share`; the magazine's slot fit reads only `share`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM (MySQL + SQLite), Angular 20 signals, Jest, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-10-11-1524-link-and-media-entries-from-any-feed-design.md`

## Global Constraints

- Own-prose limit: **500 characters** (`EntryShareClassifier::OWN_PROSE_MAX`). Applies to posts and titled entries; a YouTube Short is exempt.
- Media is decided by the target, never by the body's shape.
- The media card shows only images and video files; a player page (YouTube/Vimeo iframe) stays a link card. Own-URL video: only Shorts.
- No Reddit-specific work. `RedditEntryRule` is untouched.
- Module dependencies: `Share → LinkCard, Reader, Parser, Fetch, Text, Url`; `Completion → Share`. `Ingest` and `LinkCard` must **not** depend on `Share` (cycle `ServiceModuleCycleRule` reports).
- House style per `CLAUDE.md`: `final readonly` services, static-only helpers in `Support/`, no comments unless a future reader would get the code wrong, names without abbreviations, PHPMD-clean touched files, `declare(strict_types=1)`.
- Commits: `type(#1524): …`, no attribution lines.
- Backend tests run natively from `backend/`: `php bin/phpunit <path>`. Frontend tests run in Docker: `docker compose exec -T frontend npm test -- <path>` (from the repo root).

## File Map

Created:
- `backend/src/Enum/ShareKind.php` — `link` / `media`.
- `backend/src/Entity/Share.php` — readonly value: kind + target url.
- `backend/src/Entity/EntryShare.php` — embeddable, columns `share_kind`, `share_url`.
- `backend/migrations/Version20261011150000.php` — rename table, add columns.
- `backend/src/Service/Parser/Support/VisualMedium.php` — an image/video file URL as an `EntryMedium`.
- `backend/src/Service/Share/EntryShareClassifier.php` — the verdict.
- `backend/src/Service/Share/Support/OwnProse.php` — the entry's own text.
- `backend/src/Service/Share/Support/TitledShareTarget.php` — on-site home + off-site target.
- `backend/src/Service/LinkCard/Model/PageCardModel.php` — card + declared medium.
- `backend/src/Service/LinkCard/Support/DeclaredPageMedium.php` — medium from meta tags.
- `backend/src/Service/LinkCard/Support/ShareTargets.php` — the URLs an entry's cards come from.
- Tests mirroring each.

Renamed (Task 1): `PostEnrichment` → `ShareEnrichment`, `PostEnrichmentKind` → `ShareEnrichmentKind`, `PostEnrichmentRepository` → `ShareEnrichmentRepository`, `PostEnrichmentQueue` → `ShareEnrichmentQueue`, `PostLinkCards` → `ShareLinkCards`, `tests/Support/PostLinkCardsWiring` → `ShareLinkCardsWiring`, their tests. `Service/Bluesky/PostEnricher` and `tests/Support/PostEnrichers` keep their names: they are the Bluesky post enricher.

Modified: `Entry.php`, `EntryJson.php`, backup carriers, `EntryEmbedWriter.php`, `PageCardReader.php`, `HtmlPageFetcher.php`, `PageResponseModel.php`, `FeedMediaClassifier.php`, frontend `models.ts`, `magazine-slot-fit.ts`, `entry-link`, `entry-media`, `entry-kicker` scss, `_magazine-card.scss`, every `EntryDto` test factory.

---

### Task 1: Rename the post enrichment to share enrichment

Pure rename, no behaviour change. Lean testing: the existing suite proves it.

**Files:**
- Rename (git mv + class/namespace edits): `backend/src/Entity/PostEnrichment.php` → `ShareEnrichment.php`; `backend/src/Enum/PostEnrichmentKind.php` → `ShareEnrichmentKind.php`; `backend/src/Repository/PostEnrichmentRepository.php` → `ShareEnrichmentRepository.php`; `backend/src/Service/Completion/PostEnrichmentQueue.php` → `ShareEnrichmentQueue.php`; `backend/src/Service/LinkCard/PostLinkCards.php` → `ShareLinkCards.php`; `backend/tests/Support/PostLinkCardsWiring.php` → `ShareLinkCardsWiring.php`; `backend/tests/Repository/PostEnrichmentRepositoryTest.php` → `ShareEnrichmentRepositoryTest.php`; `backend/tests/Service/Completion/PostEnrichmentQueueTest.php` → `ShareEnrichmentQueueTest.php`; `backend/tests/Service/LinkCard/PostLinkCardsTest.php` → `ShareLinkCardsTest.php`.
- Modify every referencing file (list from `grep -rl -e PostEnrichment -e PostLinkCards -e post_enrichment backend/src backend/config backend/tests`): `EntryPendingLoader.php`, `Bluesky/PostEnricher.php`, `Completion/EntryCompletion.php`, `Refresh/FeedOutcomePersister.php`, `Subscription/FirstFetchRecorder.php`, `tests/Support/PostEnrichers.php`, `BackupSchemaCoverageTest.php`, and the tests listed by the grep. Leave the two old migrations untouched.
- Create: `backend/migrations/Version20261011150000.php` (table part only; Task 2 adds columns to the same migration).

**Interfaces:**
- Produces: `App\Entity\ShareEnrichment` (table `share_enrichment`, same columns and constants `APPVIEW_GIVE_UP_AFTER`, `CLAIM_LEASE`), `App\Enum\ShareEnrichmentKind::{AppView, LinkCards}`, `App\Repository\ShareEnrichmentRepository` (same methods), `App\Service\Completion\ShareEnrichmentQueue::queue(list<Entry>): void`, `App\Service\LinkCard\ShareLinkCards::complete(list<ShareEnrichment>): void`, `App\Tests\Support\ShareLinkCardsWiring::build(...)`, `PostEnrichers::queue(...)` now returns `ShareEnrichmentQueue`. Properties holding them: `$enrichments`, `$enrichmentQueue`, `$shareLinkCards`.

- [ ] **Step 1: Rename the files and classes**

Use PhpStorm's `rename_refactoring` (mcp__phpstorm__rename_refactoring) per class if available; otherwise `git mv` then:

```bash
cd backend
grep -rlE 'PostEnrichment|PostLinkCards' src tests config \
  | xargs perl -pi -e 's/PostEnrichmentKind/ShareEnrichmentKind/g; s/PostEnrichmentRepository/ShareEnrichmentRepository/g;
       s/PostEnrichmentQueue/ShareEnrichmentQueue/g; s/PostLinkCardsWiring/ShareLinkCardsWiring/g;
       s/PostLinkCards/ShareLinkCards/g; s/\bPostEnrichment\b/ShareEnrichment/g'
```

Then rename properties and variables that named the old classes: `$postLinkCards` → `$shareLinkCards` in `EntryCompletion`. Keep `PostEnricher` (Bluesky) and `$postEnricher` unchanged. In `ShareEnrichment.php` set `#[ORM\Table(name: 'share_enrichment')]`, the `requireId()` message to `'A share enrichment has no id before it is flushed.'`, and the class docblock to:

```php
/**
 * An entry whose embed or link cards are still to come. An AppView row is deleted once settled, or turns into a
 * link-card row while the post shares a link the AppView gave no card; a link-card row stays, marked done, as the fact
 * that its one attempt was made.
 */
```

In `BackupSchemaCoverageTest` the skip reason becomes `'The instance\'s queue of entries whose embed or link cards are still to come (#1499, #1502, #1524); instance data, not the reader\'s.'`.

- [ ] **Step 2: Get Doctrine's index and FK names for the renamed table**

Run: `docker compose exec php bin/console doctrine:schema:update --dump-sql` (repo root). Expected: SQL creating `share_enrichment` with `UNIQ_<hash>` and `FK_<hash>` names — copy those two names. (It also shows dropping `post_enrichment`; ignore the rest.)

- [ ] **Step 3: Write the migration (table part)**

`backend/migrations/Version20261011150000.php`, following `Version20261011090000.php` exactly (non-transactional, MySQL and SQLite branches, `abortIfUnsupportedPlatform`). Replace `UNIQ_X`/`FK_X` with the names from Step 2:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011150000 extends AbstractMigration
{
    private const string COPY_TO_SHARE = 'INSERT INTO share_enrichment'
        . ' (id, entry_id, kind, queued_at, claimed_at, done_at)'
        . ' SELECT id, entry_id, kind, queued_at, claimed_at, done_at FROM post_enrichment';
    private const string COPY_TO_POST = 'INSERT INTO post_enrichment'
        . ' (id, entry_id, kind, queued_at, claimed_at, done_at)'
        . ' SELECT id, entry_id, kind, queued_at, claimed_at, done_at FROM share_enrichment';

    public function getDescription(): string
    {
        return 'Rename post_enrichment to share_enrichment, the queue of every shared link (#1524).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('share_enrichment'), 'share_enrichment already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->createQueueTable('share_enrichment', 'UNIQ_X', 'FK_X');
        $this->addSql(self::COPY_TO_SHARE);
        $this->addSql('DROP TABLE post_enrichment');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('share_enrichment'), 'share_enrichment does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->createQueueTable('post_enrichment', 'UNIQ_9FA2FF0FBA364942', 'FK_9FA2FF0FBA364942');
        $this->addSql(self::COPY_TO_POST);
        $this->addSql('DROP TABLE share_enrichment');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function createQueueTable(string $table, string $uniqueIndex, string $foreignKey): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql(sprintf('CREATE TABLE %s (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL,'
                . ' queued_at DATETIME NOT NULL, claimed_at DATETIME DEFAULT NULL, done_at DATETIME DEFAULT NULL,'
                . ' entry_id INT NOT NULL, UNIQUE INDEX %s (entry_id), PRIMARY KEY (id))'
                . ' DEFAULT CHARACTER SET utf8mb4', $table, $uniqueIndex));
            $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE', $table, $foreignKey));

            return;
        }

        $this->addSql(sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' kind VARCHAR(16) NOT NULL, queued_at DATETIME NOT NULL, claimed_at DATETIME DEFAULT NULL,'
            . ' done_at DATETIME DEFAULT NULL, entry_id INTEGER NOT NULL, CONSTRAINT %s'
            . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            $table, $foreignKey));
        $this->addSql(sprintf('CREATE UNIQUE INDEX %s ON %s (entry_id)', $uniqueIndex, $table));
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
```

- [ ] **Step 4: Verify the migration on both platforms and the suite**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
cd backend && php bin/phpunit
```

Expected: migrate OK, `[OK] The database schema is in sync with the mapping files.`, suite green. Also migrate a fresh SQLite: `cd backend && DATABASE_URL="sqlite:///$PWD/var/migrate-check.db" php bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///$PWD/var/migrate-check.db" php bin/console doctrine:schema:validate && rm var/migrate-check.db` — expected in sync. Then roll back one and forward again on Docker (`doctrine:migrations:migrate prev`, then `migrate`) to prove `down()`.

- [ ] **Step 5: Gates on the touched files, commit**

```bash
cd backend && composer cs && composer stan && composer md
git add -A backend && git commit -m "refactor(#1524): the post enrichment queue becomes the share enrichment queue"
```

---

### Task 2: Store an entry's share and send it to the client

**Files:**
- Create: `backend/src/Enum/ShareKind.php`, `backend/src/Entity/Share.php`, `backend/src/Entity/EntryShare.php`
- Modify: `backend/src/Entity/Entry.php` (embed + `getShare()`/`setShare()` + `bodyStandsInForSummary()`), `backend/migrations/Version20261011150000.php` (columns), `backend/src/Http/EntryJson.php`, `backend/src/Service/Backup/BackupLines.php`, `backend/src/Service/Backup/Dto/EntryLine.php`, `backend/src/Repository/EntryBatchInserter.php`, `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Support/FullyPopulatedAccount.php`
- Test: `backend/tests/Entity/EntryShareTest.php`, `backend/tests/Http/EntryJsonTest.php`, `backend/tests/Repository/EntryBatchInserterTest.php`

**Interfaces:**
- Produces:
  - `enum App\Enum\ShareKind: string { case Link = 'link'; case Media = 'media'; }`
  - `final readonly class App\Entity\Share { public ?ShareKind $kind; public ?string $url; static none(): self; static link(?string $url): self; static media(?string $url): self; isNone(): bool }`
  - `Entry::getShare(): Share`, `Entry::setShare(Share $share): void`, `Entry::bodyStandsInForSummary(): bool`
  - JSON field `share: 'link'|'media'|null` on every entry row.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Entity/EntryShareTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Share;
use App\Enum\ShareKind;
use PHPUnit\Framework\TestCase;

final class EntryShareTest extends TestCase
{
    private static function entry(): Entry
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');

        return new Entry(new Feed('https://example.org/feed'), 'guid', null, 'Title', $now, $now);
    }

    public function testANewEntrySharesNothing(): void
    {
        self::assertTrue(self::entry()->getShare()->isNone());
    }

    public function testStoresAShareWithItsTarget(): void
    {
        $entry = self::entry();
        $entry->setShare(Share::link('https://example.com/story'));

        self::assertSame(ShareKind::Link, $entry->getShare()->kind);
        self::assertSame('https://example.com/story', $entry->getShare()->url);
    }

    public function testATargetTooLongForItsColumnIsDropped(): void
    {
        $entry = self::entry();
        $entry->setShare(Share::media('https://example.com/' . str_repeat('a', 2048)));

        self::assertSame(ShareKind::Media, $entry->getShare()->kind);
        self::assertNull($entry->getShare()->url);
    }

    public function testTheBodyStandsInForTheSummaryOnlyOfATitledEntrySharingNothing(): void
    {
        $article = self::entry();
        $share = self::entry();
        $share->setShare(Share::link('https://example.com/story'));
        $untitled = new Entry(new Feed('https://example.org/feed'), 'g', null, '', new \DateTimeImmutable(), new \DateTimeImmutable());
        $untitled->markTitleDerived();

        self::assertTrue($article->bodyStandsInForSummary());
        self::assertFalse($share->bodyStandsInForSummary());
        self::assertFalse($untitled->bodyStandsInForSummary());
    }
}
```

In `backend/tests/Http/EntryJsonTest.php` add (reuse the file's existing `row()` / `entryTitled()` helpers — read them first):

```php
    public function testSendsTheShareKind(): void
    {
        $shared = $this->entryTitled('Shared');
        $shared->setShare(Share::link('https://example.com/story'));

        self::assertSame('link', EntryJson::listRow($this->row($shared))['share']);
        self::assertNull(EntryJson::listRow($this->row($this->entryTitled('Ordinary')))['share']);
    }

    public function testAShareWithoutOwnTextHasNoExcerptFromItsBody(): void
    {
        $shared = $this->entryTitled('Shared');
        $shared->setSummary(null);
        $shared->setContentHtml('<p>Article URL: https://example.com/story</p>');
        $shared->setShare(Share::link('https://example.com/story'));

        self::assertSame('', EntryJson::listRow($this->row($shared))['excerpt']);
    }
```

In `backend/tests/Repository/EntryBatchInserterTest.php` extend the `entryLine()` helper with `?string $shareKind = null, ?string $shareUrl = null` (passed to `EntryLine`) and add a test that a restored line with `shareKind: 'media', shareUrl: 'https://example.com/a.jpg'` reads back as `Share::media('https://example.com/a.jpg')` (mirror the existing `derived-guid` test at line ~124).

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend && php bin/phpunit tests/Entity/EntryShareTest.php tests/Http/EntryJsonTest.php tests/Repository/EntryBatchInserterTest.php`
Expected: FAIL — `Class "App\Entity\Share" not found`.

- [ ] **Step 3: Implement**

`backend/src/Enum/ShareKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum ShareKind: string
{
    case Link = 'link';
    case Media = 'media';
}
```

`backend/src/Entity/Share.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareKind;

/** What an entry shares: a link or a picture or video, and the target a titled entry points at. */
final readonly class Share
{
    private function __construct(
        public ?ShareKind $kind,
        public ?string $url,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function link(?string $url): self
    {
        return new self(ShareKind::Link, $url);
    }

    public static function media(?string $url): self
    {
        return new self(ShareKind::Media, $url);
    }

    public static function of(?ShareKind $kind, ?string $url): self
    {
        return $kind === null ? self::none() : new self($kind, $url);
    }

    public function isNone(): bool
    {
        return $this->kind === null;
    }
}
```

`backend/src/Entity/EntryShare.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ShareKind;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryShare
{
    private const int URL_MAX = 2048;

    #[ORM\Column(name: 'share_kind', length: 8, nullable: true, enumType: ShareKind::class)]
    private ?ShareKind $kind = null;

    #[ORM\Column(name: 'share_url', length: self::URL_MAX, nullable: true)]
    private ?string $url = null;

    public function store(Share $share): void
    {
        $this->kind = $share->kind;
        $this->url = $share->url === null || \strlen($share->url) > self::URL_MAX ? null : $share->url;
    }

    public function read(): Share
    {
        return Share::of($this->kind, $this->url);
    }
}
```

In `Entry.php`, beside `$discussion`:

```php
    #[ORM\Embedded(class: EntryShare::class, columnPrefix: false)]
    private EntryShare $share;
```

initialise it in the constructor next to the other embeddables (`$this->share = new EntryShare();`), and add after `setDiscussion()`:

```php
    public function getShare(): Share
    {
        return $this->share->read();
    }

    public function setShare(Share $share): void
    {
        $this->share->store($share);
    }

    /** A share's summary is its own text, so the body never stands in for it; nor for a post whose title is its body. */
    public function bodyStandsInForSummary(): bool
    {
        return !$this->isUntitledPost() && $this->getShare()->isNone();
    }
```

Doctrine hydrates embeddables without the constructor; if Entry already guards a null embeddable after hydration (check how `$discussion` is handled), do the same for `$share`.

In `EntryJson::listRow` replace `$row->entry->isUntitledPost() ? null : $row->entry->getContentHtml()` with `$row->entry->bodyStandsInForSummary() ? $row->entry->getContentHtml() : null`. In `commonFields` add after `'titleDerived'`:

```php
            'share' => $entry->getShare()->kind?->value,
```

and add `share: 'link'|'media'|null` to all three `@return array{…}` shapes.

Backup: in `BackupLines::entryLine` add after `'titleDerived'`:

```php
            'shareKind' => $entry->getShare()->kind?->value,
            'shareUrl' => $entry->getShare()->url,
```

`EntryLine`: constructor params `public ?string $shareKind = null, public ?string $shareUrl = null,` after `$titleDerived`; in `fromLine`: `shareKind: LineField::stringOrNull($line, 'shareKind'), shareUrl: LineField::stringOrNull($line, 'shareUrl'),` (an old backup lacks them; check that `stringOrNull` tolerates a missing key — if not, use the `LineFieldWithDefault` pattern `titleDerived` uses). `EntryBatchInserter`: append `'share_kind', 'share_url'` to `COLUMNS` and `ShareKind::tryFrom((string) $line->shareKind)?->value, $line->shareUrl,` to `row()`. `BackupFieldDeclarations` (Entry block): `'share.kind' => 'shareKind', 'share.url' => 'shareUrl',`. `FullyPopulatedAccount`: `$entry->setShare(Share::link('https://populated.example/shared'));` after `markTitleDerived()`.

Migration: add to `Version20261011150000::up()` before the table work (both platforms accept this syntax):

```php
        $this->addSql('ALTER TABLE entry ADD share_kind VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE entry ADD share_url VARCHAR(2048) DEFAULT NULL');
```

and to `down()` after the table work:

```php
        $this->addSql('ALTER TABLE entry DROP COLUMN share_url');
        $this->addSql('ALTER TABLE entry DROP COLUMN share_kind');
```

Update `getDescription()` to `'Rename post_enrichment to share_enrichment and give an entry its share (#1524).'`. The `skipIf` on `share_enrichment` now guards both parts; that is fine because they ship together.

- [ ] **Step 4: Run the tests and the migration check**

```bash
cd backend && php bin/phpunit tests/Entity/EntryShareTest.php tests/Http/EntryJsonTest.php tests/Repository/EntryBatchInserterTest.php tests/Service/Backup
docker compose exec php bin/console doctrine:migrations:migrate prev --no-interaction
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: PASS; schema in sync. Repeat the fresh-SQLite migrate + validate from Task 1 Step 4.

- [ ] **Step 5: Gates, commit**

```bash
cd backend && composer cs && composer stan && composer md
git add -A backend && git commit -m "feat(#1524): an entry stores what it shares and the API sends it"
```

---

### Task 3: An entry's own text (`OwnProse`)

**Files:**
- Create: `backend/src/Service/Share/Support/OwnProse.php`
- Test: `backend/tests/Service/Share/Support/OwnProseTest.php`

**Interfaces:**
- Produces: `App\Service\Share\Support\OwnProse::of(string $html): string` — the body as plain text, one line per block, without links whose text is a URL and without `Label: number` lines; `''` when nothing is left.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Share\Support;

use App\Service\Share\Support\OwnProse;
use PHPUnit\Framework\TestCase;

final class OwnProseTest extends TestCase
{
    public function testAHackerNewsBodyHasNoOwnText(): void
    {
        $body = '<p>Article URL: <a href="https://bd103.dev/blog/c">https://bd103.dev/blog/c</a></p>'
            . "\n" . '<p>Comments URL: <a href="https://news.ycombinator.com/item?id&#61;5">'
            . 'https://news.ycombinator.com/item?id&#61;5</a></p>' . "\n<p>Points: 110</p>\n<p># Comments: 45</p>";

        self::assertSame('', OwnProse::of($body));
    }

    public function testKeepsProseAndWordedLinks(): void
    {
        $body = '<p>David Smith, <a href="https://mastodon.social/@x/1">last week</a>:</p>'
            . '<blockquote><p>Interesting adoption curve.</p></blockquote>';

        self::assertSame("David Smith, last week:\nInteresting adoption curve.", OwnProse::of($body));
    }

    public function testDropsALinkThatReadsAsItsUrlButKeepsTheSentence(): void
    {
        self::assertSame(
            'Read this:',
            OwnProse::of('<p>Read this: <a href="https://example.com/a">example.com/a</a></p>'),
        );
    }

    public function testReadsPlainText(): void
    {
        self::assertSame('Just words.', OwnProse::of('Just words.'));
    }
}
```

Note: `example.com/a` as visible text reads as its URL only if the rule accepts host-led text; `URL_TEXT` below accepts `https?://`, `www.` and the bare host-led form `host.tld/…`.

- [ ] **Step 2: Run it to see it fail**

Run: `cd backend && php bin/phpunit tests/Service/Share/Support/OwnProseTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Service\Text\Support\PlainText;

/** An entry's own words: its text without the links that read as their URL and the `Label: number` metadata lines. */
final class OwnProse
{
    private const string URL_TEXT_LINK = '#<a\b[^>]*>\s*(?:https?://|www\.|[a-z0-9-]+(?:\.[a-z0-9-]+)+/)[^<]*</a>#iu';
    private const string METADATA_LINE = '/^[#\p{L}][\p{L}\p{N} #]{0,40}:\s*[\p{N}.,]*$/u';

    public static function of(string $html): string
    {
        $withoutUrlLinks = preg_replace(self::URL_TEXT_LINK, ' ', $html) ?? $html;
        $lines = array_filter(
            PlainText::linesFromHtmlBlocks($withoutUrlLinks),
            static fn (string $line): bool => preg_match(self::METADATA_LINE, $line) !== 1,
        );

        return implode("\n", $lines);
    }

    private function __construct()
    {
    }
}
```

`PlainText::linesFromHtmlBlocks` collapses whitespace per line, so `Read this:  ` comes back as `Read this:`. If the third test returns `Read this:` with trailing space, wrap each kept line in `trim()`.

- [ ] **Step 4: Run it to see it pass**

Run: `cd backend && php bin/phpunit tests/Service/Share/Support/OwnProseTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs && composer stan
git add -A backend && git commit -m "feat(#1524): OwnProse reads an entry's own words"
```

---

### Task 4: A titled entry's share target (`TitledShareTarget`)

**Files:**
- Create: `backend/src/Service/Share/Support/TitledShareTarget.php`
- Test: `backend/tests/Service/Share/Support/TitledShareTargetTest.php`

**Interfaces:**
- Produces: `App\Service\Share\Support\TitledShareTarget::of(Entry $entry): ?string` — the entry's `url` when it is off the feed's site while the entry's home (discussion URL, URL GUID or `tag:` GUID) is on it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Share\Support;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Service\Share\Support\TitledShareTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TitledShareTargetTest extends TestCase
{
    private static function entry(string $siteUrl, string $guid, ?string $url, ?string $discussionUrl = null): Entry
    {
        $feed = new Feed('https://feeds.example/feed');
        $feed->setSiteUrl($siteUrl);
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $entry = new Entry($feed, $guid, $url, 'Title', $now, $now);
        if ($discussionUrl !== null) {
            $entry->setDiscussion(Discussion::of($discussionUrl, null, CommentsLoad::Manual));
        }

        return $entry;
    }

    /** @return iterable<string, array{Entry, ?string}> */
    public static function entries(): iterable
    {
        yield 'Hacker News: home is the discussion' => [
            self::entry('https://news.ycombinator.com/', 'https://news.ycombinator.com/item?id=5',
                'https://ghuntley.com/unikernels/', 'https://news.ycombinator.com/item?id=5'),
            'https://ghuntley.com/unikernels/',
        ];
        yield 'Daring Fireball: home is a tag GUID' => [
            self::entry('https://daringfireball.net/', 'tag:daringfireball.net,2026:/linked//6.42',
                'https://x.com/bzamayo/status/1'),
            'https://x.com/bzamayo/status/1',
        ];
        yield 'POLITICO Europe: home is a URL GUID' => [
            self::entry('https://www.politico.eu/', 'https://www.politico.eu/?p=8891306',
                'https://www.politico.com/news/a'),
            'https://www.politico.com/news/a',
        ];
        yield 'ZDF: an opaque GUID is no home' => [
            self::entry('https://www.zdfheute.de/', 'SCMS_02561894', 'https://www.3sat.de/kultur/a'),
            null,
        ];
        yield 'an article on its own site' => [
            self::entry('https://www.bbc.co.uk/news', 'https://www.bbc.co.uk/news/a', 'https://www.bbc.co.uk/news/a'),
            null,
        ];
        yield 'Ask HN: the target is the home' => [
            self::entry('https://news.ycombinator.com/', 'https://news.ycombinator.com/item?id=6',
                'https://news.ycombinator.com/item?id=6', 'https://news.ycombinator.com/item?id=6'),
            null,
        ];
        yield 'no url' => [
            self::entry('https://news.ycombinator.com/', 'https://news.ycombinator.com/item?id=7', null),
            null,
        ];
    }

    #[DataProvider('entries')]
    public function testFindsTheTarget(Entry $entry, ?string $expected): void
    {
        self::assertSame($expected, TitledShareTarget::of($entry));
    }

    public function testAFeedWithoutASiteHasNoTitledShares(): void
    {
        $now = new \DateTimeImmutable();
        $entry = new Entry(new Feed('https://feeds.example/feed'), 'https://feeds.example/a', 'https://other.example/a', 'T', $now, $now);

        self::assertNull(TitledShareTarget::of($entry));
    }
}
```

Check `Feed::setSiteUrl` exists (it does: `EntryIngestor` calls it). Data providers build entities statically; that is fine in PHPUnit 12 (`#[DataProvider]`, see memory note).

- [ ] **Step 2: Run it to see it fail**

Run: `cd backend && php bin/phpunit tests/Service/Share/Support/TitledShareTargetTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Entity\Entry;
use App\Service\Fetch\Support\HostKey;
use App\Service\Url\Support\AbsoluteHttpUrl;

/**
 * The page a titled entry shares: its url, when that leaves the feed's site while the entry itself lives there. An
 * opaque GUID proves no home, so a news site linking a sister brand is no share.
 */
final class TitledShareTarget
{
    private const string TAG_AUTHORITY = '#^tag:(?:www\.)?([^,]+),#i';

    public static function of(Entry $entry): ?string
    {
        $target = $entry->getUrl();
        $siteUrl = $entry->getFeed()->getSiteUrl();
        if ($target === null || $siteUrl === null) {
            return null;
        }

        $siteHost = HostKey::forUrl($siteUrl);

        return self::homeHost($entry) === $siteHost && HostKey::forUrl($target) !== $siteHost ? $target : null;
    }

    private static function homeHost(Entry $entry): ?string
    {
        $discussionUrl = $entry->getDiscussion()->url;
        if ($discussionUrl !== null) {
            return HostKey::forUrl($discussionUrl);
        }
        $guid = $entry->getGuid();
        if (AbsoluteHttpUrl::matches($guid)) {
            return HostKey::forUrl($guid);
        }

        return preg_match(self::TAG_AUTHORITY, $guid, $matches) === 1 ? strtolower($matches[1]) : null;
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `cd backend && php bin/phpunit tests/Service/Share/Support/TitledShareTargetTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs && composer stan
git add -A backend && git commit -m "feat(#1524): a titled entry shares the off-site page it points at from its own site"
```

---

### Task 5: A media file URL as a medium (`VisualMedium`)

**Files:**
- Modify: `backend/src/Service/Parser/Support/FeedMediaClassifier.php` (public `kindOfUrl`)
- Create: `backend/src/Service/Parser/Support/VisualMedium.php`
- Test: `backend/tests/Service/Parser/Support/VisualMediumTest.php`

**Interfaces:**
- Produces: `FeedMediaClassifier::kindOfUrl(string $url): FeedMediaKind` (the existing private `fromExtension`, made public and renamed; its one internal caller updated). `VisualMedium::at(string $url, ?string $posterUrl = null): ?EntryMedium` — an https image or video file as an `EntryMedium` (`kind` `image`/`video`), else null.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\VisualMedium;
use PHPUnit\Framework\TestCase;

final class VisualMediumTest extends TestCase
{
    public function testAnImageFile(): void
    {
        $medium = VisualMedium::at('https://i.example/a.PNG?x=1');

        self::assertNotNull($medium);
        self::assertSame('image', $medium->kind);
        self::assertSame('https://i.example/a.PNG?x=1', $medium->url);
    }

    public function testAVideoFileWithItsPoster(): void
    {
        $medium = VisualMedium::at('https://v.example/clip.mp4', 'https://v.example/poster.jpg');

        self::assertNotNull($medium);
        self::assertSame('video', $medium->kind);
        self::assertSame('https://v.example/poster.jpg', $medium->previewImageUrl);
    }

    public function testNoPageNoAudioNoPlainHttp(): void
    {
        self::assertNull(VisualMedium::at('https://example.com/story'));
        self::assertNull(VisualMedium::at('https://example.com/episode.mp3'));
        self::assertNull(VisualMedium::at('http://example.com/a.jpg'));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd backend && php bin/phpunit tests/Service/Parser/Support/VisualMediumTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

In `FeedMediaClassifier` rename `private static function fromExtension(string $url)` to `public static function kindOfUrl(string $url)` and update the call in `kind()`.

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\EntryMedium;
use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Url\Support\HttpsImageUrl;

final class VisualMedium
{
    public static function at(string $url, ?string $posterUrl = null): ?EntryMedium
    {
        $secure = HttpsImageUrl::orNull($url);
        if ($secure === null) {
            return null;
        }

        return match (FeedMediaClassifier::kindOfUrl($secure)) {
            FeedMediaKind::Image => new EntryMedium($secure, VisualMediaKind::Image->value),
            FeedMediaKind::Video => new EntryMedium(
                $secure,
                VisualMediaKind::Video->value,
                previewImageUrl: HttpsImageUrl::orNull($posterUrl),
            ),
            default => null,
        };
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run it and the classifier's tests**

Run: `cd backend && php bin/phpunit tests/Service/Parser/Support`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs && composer stan
git add -A backend && git commit -m "feat(#1524): VisualMedium turns an image or video file URL into a medium"
```

---

### Task 6: The share verdict (`EntryShareClassifier`)

**Files:**
- Create: `backend/src/Service/Share/EntryShareClassifier.php`
- Test: `backend/tests/Service/Share/EntryShareClassifierTest.php`

**Interfaces:**
- Consumes: `OwnProse::of` (Task 3), `TitledShareTarget::of` (Task 4), `VisualMedium::at` (Task 5), `SharedLinks::of` (`LinkCard/Support`), `YouTubeShortUrl::is` (`Reader/Media/Support`), `Entry::setShare/getShare` (Task 2).
- Produces: `App\Service\Share\EntryShareClassifier::classify(Entry $entry): void` — writes the entry's share; for a titled share also its summary (own text) and, for a media target, the target as first medium. `public const int OWN_PROSE_MAX = 500`.

- [ ] **Step 1: Write the failing test**

Bodies are trimmed real samples from the dev DB.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Share;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\Share;
use App\Enum\CommentsLoad;
use App\Service\Share\EntryShareClassifier;
use PHPUnit\Framework\TestCase;

final class EntryShareClassifierTest extends TestCase
{
    private const string HN_SITE = 'https://news.ycombinator.com/';
    private const string HN_ITEM = 'https://news.ycombinator.com/item?id=5';
    private const string HN_BODY = '<p>Article URL: <a href="https://ghuntley.com/unikernels/">https://ghuntley.com/unikernels/</a></p>'
        . '<p>Comments URL: <a href="https://news.ycombinator.com/item?id&#61;5">https://news.ycombinator.com/item?id&#61;5</a></p>'
        . '<p>Points: 110</p><p># Comments: 45</p>';

    private function classified(Entry $entry): Entry
    {
        (new EntryShareClassifier())->classify($entry);

        return $entry;
    }

    private static function entry(string $siteUrl, string $guid, ?string $url, ?string $body): Entry
    {
        $feed = new Feed('https://feeds.example/feed');
        $feed->setSiteUrl($siteUrl);
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $entry = new Entry($feed, $guid, $url, 'A real headline', $now, $now);
        $entry->setContentHtml($body);

        return $entry;
    }

    private static function hackerNews(string $target): Entry
    {
        $entry = self::entry(self::HN_SITE, self::HN_ITEM, $target, self::HN_BODY);
        $entry->setDiscussion(Discussion::of(self::HN_ITEM, null, CommentsLoad::Manual));

        return $entry;
    }

    private static function post(string $body, array $media = []): Entry
    {
        $entry = self::entry('https://mastodon.example/@someone', 'https://mastodon.example/@someone/1', 'https://mastodon.example/@someone/1', $body);
        $entry->markTitleDerived();
        $entry->setMedia($media, []);

        return $entry;
    }

    public function testAHackerNewsItemSharesItsArticleWithoutOwnText(): void
    {
        $entry = $this->classified(self::hackerNews('https://ghuntley.com/unikernels/'));

        self::assertEquals(Share::link('https://ghuntley.com/unikernels/'), $entry->getShare());
        self::assertNull($entry->getSummary());
    }

    public function testATitledShareOfAnImageFileIsMediaWithTheFileFirst(): void
    {
        $entry = self::hackerNews('https://i.example/chart.png');
        $entry->setMedia([new EntryMedium('https://cdn.example/thumb.jpg', 'image')], []);

        $this->classified($entry);

        self::assertEquals(Share::media('https://i.example/chart.png'), $entry->getShare());
        self::assertSame('https://i.example/chart.png', $entry->getMedia()[0]->url);
        self::assertCount(2, $entry->getMedia());
    }

    public function testALinkBlogItemWithShortCommentaryKeepsItAsItsText(): void
    {
        $entry = $this->classified(self::entry(
            'https://daringfireball.net/',
            'tag:daringfireball.net,2026:/linked//6.42',
            'https://x.com/bzamayo/status/1',
            '<p>Benjamin Mayo, commenting:</p><blockquote><p>There needs to be a name for this.</p></blockquote>',
        ));

        self::assertEquals(Share::link('https://x.com/bzamayo/status/1'), $entry->getShare());
        self::assertSame("Benjamin Mayo, commenting:\nThere needs to be a name for this.", $entry->getSummary());
    }

    public function testALinkBlogEssayIsAnArticle(): void
    {
        $entry = $this->classified(self::entry(
            'https://daringfireball.net/',
            'tag:daringfireball.net,2026:/linked//6.43',
            'https://mastodon.social/@x/1',
            '<p>' . str_repeat('Long commentary. ', 40) . '</p>',
        ));

        self::assertTrue($entry->getShare()->isNone());
        self::assertNull($entry->getSummary());
    }

    public function testASisterBrandTeaserIsAnArticle(): void
    {
        $entry = $this->classified(self::entry('https://www.zdfheute.de/', 'SCMS_1', 'https://www.3sat.de/a', '<p>Teaser.</p>'));

        self::assertTrue($entry->getShare()->isNone());
    }

    public function testANewsTeaserWithAnImageIsAnArticle(): void
    {
        $entry = self::entry('https://www.zeit.de/', 'https://www.zeit.de/a', 'https://www.zeit.de/a', '<p>Teaser.</p>');
        $entry->setMedia([new EntryMedium('https://img.zeit.de/a.jpg', 'image')], []);

        self::assertTrue($this->classified($entry)->getShare()->isNone());
    }

    public function testAPostSharingALinkIsALinkShareWithoutATarget(): void
    {
        $entry = $this->classified(self::post('<p>Look: <a href="https://example.com/a">https://example.com/a</a></p>'));

        self::assertEquals(Share::link(null), $entry->getShare());
    }

    public function testAPostWithAPictureAndNoLinkIsMedia(): void
    {
        $entry = $this->classified(self::post('<p>Sunrise.</p>', [new EntryMedium('https://files.example/a.jpg', 'image')]));

        self::assertEquals(Share::media(null), $entry->getShare());
    }

    public function testAPostUpToTheLimitKeepsItsCardAndALongerOneDoesNot(): void
    {
        $link = ' <a href="https://example.com/a">https://example.com/a</a>';
        $atLimit = $this->classified(self::post('<p>' . str_repeat('a', 500) . $link . '</p>'));
        $overLimit = $this->classified(self::post('<p>' . str_repeat('a', 501) . $link . '</p>'));

        self::assertEquals(Share::link(null), $atLimit->getShare());
        self::assertTrue($overLimit->getShare()->isNone());
    }

    public function testAPostLeavesItsSummaryAlone(): void
    {
        $entry = self::post('<p>Look: <a href="https://example.com/a">https://example.com/a</a></p>');
        $entry->setSummary('Look: https://example.com/a');

        self::assertSame('Look: https://example.com/a', $this->classified($entry)->getSummary());
    }

    public function testAYouTubeShortIsMediaWhateverItsDescription(): void
    {
        $entry = $this->classified(self::entry(
            'https://www.youtube.com/channel/UC1',
            'yt:video:abc',
            'https://www.youtube.com/shorts/abcdefghijk',
            '<p>' . str_repeat('Description. ', 60) . '</p>',
        ));

        self::assertEquals(Share::media(null), $entry->getShare());
    }

    public function testARegularYouTubeVideoIsAnArticle(): void
    {
        $entry = $this->classified(self::entry(
            'https://www.youtube.com/channel/UC1',
            'yt:video:abc',
            'https://www.youtube.com/watch?v=abcdefghijk',
            '<p>Description.</p>',
        ));

        self::assertTrue($entry->getShare()->isNone());
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd backend && php bin/phpunit tests/Service/Share/EntryShareClassifierTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Entry;
use App\Entity\Share;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Parser\Support\VisualMedium;
use App\Service\Reader\Media\Support\YouTubeShortUrl;
use App\Service\Share\Support\OwnProse;
use App\Service\Share\Support\TitledShareTarget;

/**
 * Whether an entry is a shared link or a shared picture or video, so it takes the matching card. Only a short entry
 * shares: more of its own text than a Mastodon post holds makes it an article. The body's shape never decides media,
 * since a news teaser looks the same; the target does.
 */
final readonly class EntryShareClassifier
{
    public const int OWN_PROSE_MAX = 500;

    public function classify(Entry $entry): void
    {
        $body = $entry->getContentHtml() ?? $entry->getSummary() ?? '';
        $ownProse = OwnProse::of($body);
        $share = $this->shareOf($entry, $body, $ownProse);
        $entry->setShare($share);
        if ($share->isNone() || $entry->isTitleDerived()) {
            return;
        }

        $entry->setSummary($ownProse === '' ? null : mb_substr($ownProse, 0, self::OWN_PROSE_MAX));
        $this->putTargetFirst($entry, $share);
    }

    private function shareOf(Entry $entry, string $body, string $ownProse): Share
    {
        if (YouTubeShortUrl::is($entry->getUrl())) {
            return Share::media(null);
        }
        if (mb_strlen($ownProse) > self::OWN_PROSE_MAX) {
            return Share::none();
        }

        return $entry->isTitleDerived() ? $this->postShare($entry, $body) : $this->titledShare($entry);
    }

    private function postShare(Entry $entry, string $body): Share
    {
        if (SharedLinks::of($body)->urls !== []) {
            return Share::link(null);
        }

        return $entry->getMedia() === [] ? Share::none() : Share::media(null);
    }

    private function titledShare(Entry $entry): Share
    {
        $target = TitledShareTarget::of($entry);
        if ($target === null) {
            return Share::none();
        }

        return VisualMedium::at($target) === null ? Share::link($target) : Share::media($target);
    }

    private function putTargetFirst(Entry $entry, Share $share): void
    {
        $medium = $share->url === null ? null : VisualMedium::at($share->url);
        if ($medium !== null) {
            $entry->setMedia([$medium, ...$entry->getMedia()], $entry->getAttachments());
        }
    }
}
```

`Entry::setMedia` takes `list<EntryMedium>` (check its docblock: line ~229). If a run reports `Share::media()` comparison failures via `assertEquals` on enums, keep `assertEquals` — readonly value objects compare by value.

- [ ] **Step 4: Run it to see it pass**

Run: `cd backend && php bin/phpunit tests/Service/Share`
Expected: PASS.

- [ ] **Step 5: Gates, commit**

```bash
cd backend && composer cs && composer stan && composer md
git add -A backend && git commit -m "feat(#1524): EntryShareClassifier decides what an entry shares"
```

`composer stan` runs `ServiceModuleCycleRule` and `ServiceRoleRule`: a cycle or a misplaced class fails here.

---

### Task 7: Classify on ingest and queue every link share

**Files:**
- Modify: `backend/src/Service/Completion/ShareEnrichmentQueue.php`, `backend/tests/Support/PostEnrichers.php` (`queue()` passes the classifier)
- Test: `backend/tests/Service/Completion/ShareEnrichmentQueueTest.php`

**Interfaces:**
- Consumes: `EntryShareClassifier::classify` (Task 6), `ShareKind`.
- Produces: `ShareEnrichmentQueue::queue(list<Entry>)` classifies each created entry; queues `AppView` for an AT-URI GUID (and gives a still-unclassified Bluesky post `Share::link(null)` so it holds the link placeholder while its embed loads, as before), `LinkCards` for every other `link` share. A titled share's body is not rewritten; a post's bare URLs are linked as before.

- [ ] **Step 1: Write the failing tests**

Add to `ShareEnrichmentQueueTest` (it already has `post()`, `entry()`, `queuedKinds()`; the feed is `https://mastodon.example/@someone.rss`). Give the feed a site URL in `setUp()`: `$this->feed->setSiteUrl('https://mastodon.example/@someone');`.

```php
    public function testATitledShareIsQueuedForItsCard(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $share = new Entry($this->feed, 'https://mastodon.example/@someone/9', 'https://example.com/story', 'Headline', $now, $now);
        $share->setContentHtml('<p>Short.</p>');
        $share->getImage()->storePending(null, null, null);
        $this->entityManager->persist($share);

        $this->queue->queue([$share]);
        $this->entityManager->flush();

        self::assertSame(['https://mastodon.example/@someone/9' => ShareEnrichmentKind::LinkCards], $this->queuedKinds());
        self::assertSame(ShareKind::Link, $this->reload($share)->getShare()->kind);
    }

    public function testABlueskyPostHoldsTheLinkPlaceholderUntilItsEmbedArrives(): void
    {
        $post = $this->post(self::AT_POST, '<p>Hello</p>');

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertSame(ShareKind::Link, $this->reload($post)->getShare()->kind);
    }

    public function testAPostWithAPictureIsMediaAndNotQueued(): void
    {
        $post = $this->post('https://mastodon.example/@someone/3', '<p>Sunrise.</p>');
        $post->setMedia([new EntryMedium('https://files.example/a.jpg', 'image')], []);

        $this->queue->queue([$post]);
        $this->entityManager->flush();

        self::assertSame([], $this->queuedKinds());
        self::assertSame(ShareKind::Media, $this->reload($post)->getShare()->kind);
    }
```

The existing `testAnArticleAPostSharingNoLinkAndAnEmptyPostAreNotQueued` keeps passing: the article `https://example.org/article` GUID with no url has no titled target.

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Completion/ShareEnrichmentQueueTest.php`
Expected: FAIL — the titled share is not queued; the share kinds are null.

- [ ] **Step 3: Implement**

`ShareEnrichmentQueue`:

```php
/** Classifies each new entry and queues the ones whose embed or link cards are still to come. */
final readonly class ShareEnrichmentQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntrySanitizer $sanitizer,
        private NaiveUtcClock $clock,
        private EntryShareClassifier $classifier,
    ) {
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function queue(array $createdEntries): void
    {
        $queuedAt = $this->clock->now();
        foreach ($createdEntries as $entry) {
            $this->classifier->classify($entry);
            $this->queueCreated($entry, $queuedAt);
        }
    }

    private function queueCreated(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        if (AtPostUri::matches($entry->getGuid())) {
            $this->awaitEmbed($entry, $queuedAt);

            return;
        }
        if ($entry->getShare()->kind !== ShareKind::Link) {
            return;
        }
        if ($entry->isTitleDerived()) {
            $this->storeLinked($entry, SharedLinks::of((string) $entry->getContentHtml()));
        }
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
    }

    private function awaitEmbed(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        if ($entry->getShare()->isNone()) {
            $entry->setShare(Share::link(null));
        }
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $queuedAt));
    }

    private function storeLinked(Entry $entry, SharedLinksModel $shared): void
    {
        if ($shared->linkedHtml !== $entry->getContentHtml()) {
            $entry->setContentHtml($this->sanitizer->sanitize($shared->linkedHtml));
        }
    }
}
```

`PostEnrichers::queue()` adds `new EntryShareClassifier()` as the fourth argument.

- [ ] **Step 4: Run the queue test and the refresh/subscribe tests that use it**

Run: `cd backend && php bin/phpunit tests/Service/Completion tests/Service/Refresh tests/Service/Subscription tests/Controller/Api/EntryCompletionControllerTest.php`
Expected: PASS. A Bluesky refresh test that asserted a pending post's JSON may now also see `share: 'link'` — that is the intended state; update the assertion, not the code.

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs && composer stan && composer md
git add -A backend && git commit -m "feat(#1524): ingest classifies every new entry and queues each link share for its card"
```

---

### Task 8: A filled Bluesky post settles its share

**Files:**
- Modify: `backend/src/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php`
- Test: `backend/tests/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriterTest.php` (find the existing test with `find backend/tests -name 'EntryEmbedWriter*Test.php'`)

**Interfaces:**
- Produces: after `fill()`, the entry's share is `link` when it has cards or awaits them, else `media` when it carries media, else none.

- [ ] **Step 1: Write the failing tests**

Using the existing test's helpers for a rendered post with an external embed, with images, and with text only, add one assertion per case:

```php
        self::assertSame(ShareKind::Link, $entry->getShare()->kind);   // external embed (card)
        self::assertSame(ShareKind::Media, $entry->getShare()->kind);  // images embed
        self::assertTrue($entry->getShare()->isNone());                // text-only post, Filled
```

and one for a post whose leftover link awaits its card (`FillOutcome::AwaitingLinkCards`): `ShareKind::Link`.

- [ ] **Step 2: Run to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky`
Expected: FAIL — share is still what the queue set (`link`) for the media and text-only cases.

- [ ] **Step 3: Implement**

In `fill()`, replace the final `return` with:

```php
        $outcome = $body->uncardedBy($cardedUrls) === [] ? FillOutcome::Filled : FillOutcome::AwaitingLinkCards;
        $entry->setShare(self::shareOf($embed, $outcome, $assembled->media));

        return $outcome;
```

and add:

```php
    /** @param list<EntryMedium> $media */
    private static function shareOf(RenderedEmbedModel $embed, FillOutcome $outcome, array $media): Share
    {
        if ($embed->linkCards !== [] || $outcome === FillOutcome::AwaitingLinkCards) {
            return Share::link(null);
        }

        return $media === [] ? Share::none() : Share::media(null);
    }
```

- [ ] **Step 4: Run to see them pass**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky tests/Service/Refresh/FeedOutcomePersisterBlueskyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs && composer stan && composer md
git add -A backend && git commit -m "feat(#1524): a filled Bluesky post settles whether it shares a link or media"
```

---

### Task 9: The page a share points at says what it is

**Files:**
- Modify: `backend/src/Service/Reader/Model/PageResponseModel.php` (`?string $contentType = null`), `backend/src/Service/Reader/HtmlPageFetcher.php` (`fetchHead` fills it), `backend/src/Service/LinkCard/PageCardReader.php`
- Create: `backend/src/Service/LinkCard/Model/PageCardModel.php`, `backend/src/Service/LinkCard/Support/DeclaredPageMedium.php`
- Test: `backend/tests/Service/LinkCard/PageCardReaderTest.php`

**Interfaces:**
- Consumes: `VisualMedium::at` (Task 5).
- Produces: `PageCardReader::read(string $sharedUrl): PageCardModel` with `final readonly class PageCardModel { public ?LinkCardContent $card; public ?EntryMedium $medium; }`. `$medium` is: the shared URL itself when the response `Content-Type` is `image/*` or `video/*`; else an `og:video`/`og:video:url`/`og:video:secure_url` that is a video file, with `og:image` as poster; else `og:image` when the page is a photo (`twitter:card` = `photo`, or `og:type` ends with `photo`); else null.

- [ ] **Step 1: Write the failing tests**

The existing tests call `->read(...)` and read the card's fields: change them to `->read(...)->card`. Add:

```php
    public function testAnArticleDeclaresNoMedium(): void
    {
        $read = $this->reader($this->page('<meta property="og:type" content="article"><meta property="og:title" content="Headline">'
            . '<meta property="og:image" content="https://cdn.example.com/a.jpg">'))->read(self::SHARED);

        self::assertNull($read->medium);
        self::assertNotNull($read->card);
    }

    public function testAnImageFileIsItsOwnMedium(): void
    {
        $read = $this->reader(new MockResponse("\x89PNG\r\n", [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'image/png'],
        ]))->read('https://i.example.com/chart');

        self::assertSame('https://i.example.com/chart', $read->medium?->url);
        self::assertSame('image', $read->medium?->kind);
    }

    public function testAVideoFilePageDeclaresItsVideoWithThePoster(): void
    {
        $read = $this->reader($this->page('<meta property="og:title" content="Clip">'
            . '<meta property="og:video" content="https://cdn.example.com/clip.mp4">'
            . '<meta property="og:image" content="https://cdn.example.com/poster.jpg">'))->read(self::SHARED);

        self::assertSame('https://cdn.example.com/clip.mp4', $read->medium?->url);
        self::assertSame('https://cdn.example.com/poster.jpg', $read->medium?->previewImageUrl);
    }

    public function testAPlayerPageIsNoMedium(): void
    {
        $read = $this->reader($this->page('<meta property="og:type" content="video.other"><meta property="og:title" content="V">'
            . '<meta property="og:video:url" content="https://player.vimeo.com/video/1">'))->read(self::SHARED);

        self::assertNull($read->medium);
    }

    public function testAPhotoPageDeclaresItsImage(): void
    {
        $read = $this->reader($this->page('<meta name="twitter:card" content="photo"><meta property="og:title" content="P">'
            . '<meta property="og:image" content="https://cdn.example.com/photo.jpg">'))->read(self::SHARED);

        self::assertSame('https://cdn.example.com/photo.jpg', $read->medium?->url);
        self::assertSame('image', $read->medium?->kind);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/LinkCard/PageCardReaderTest.php`
Expected: FAIL — `read()` returns `?LinkCardContent`.

- [ ] **Step 3: Implement**

`PageResponseModel`: add `public ?string $contentType = null,` as a third promoted parameter. `HtmlPageFetcher::fetchHead`: `return new PageResponseModel($landed->url, $head, $landed->header('content-type'));`.

`PageCardModel` (`LinkCard/Model/`):

```php
<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;

final readonly class PageCardModel
{
    public function __construct(
        public ?LinkCardContent $card,
        public ?EntryMedium $medium,
    ) {
    }
}
```

`DeclaredPageMedium` (`LinkCard/Support/`):

```php
<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Entity\EntryMedium;
use App\Service\Parser\Support\VisualMedium;

/** The picture or video file a page declares itself to be. A player page names an iframe, which is no file: none. */
final class DeclaredPageMedium
{
    private const array VIDEO_KEYS = ['og:video:secure_url', 'og:video:url', 'og:video'];

    /** @param array<string, string> $meta */
    public static function of(array $meta): ?EntryMedium
    {
        $poster = $meta['og:image'] ?? null;
        foreach (self::VIDEO_KEYS as $key) {
            $video = isset($meta[$key]) ? VisualMedium::at($meta[$key], $poster) : null;
            if ($video?->kind === 'video') {
                return $video;
            }
        }

        return self::isPhoto($meta) && $poster !== null ? VisualMedium::at($poster) : null;
    }

    /** @param array<string, string> $meta */
    private static function isPhoto(array $meta): bool
    {
        return strtolower($meta['twitter:card'] ?? '') === 'photo'
            || str_ends_with(strtolower($meta['og:type'] ?? ''), 'photo');
    }

    private function __construct()
    {
    }
}
```

`VisualMedium::at` judges by extension, so a photo page's extension-less `og:image` returns null; that is accepted (no card flip, the link card still shows the image).

`PageCardReader::read`:

```php
    /** @throws PageFetchException */
    public function read(string $sharedUrl): PageCardModel
    {
        $page = $this->pages->fetchHead($sharedUrl);
        $file = self::fileMedium($sharedUrl, $page->contentType);
        if ($file !== null) {
            return new PageCardModel(null, $file);
        }
        $document = HtmlDocumentParser::parseOrEmpty($page->html);
        $meta = MetaTags::contentByKey($document);

        return new PageCardModel(
            StorableLinkCard::of(new LinkCardContent(
                $sharedUrl,
                self::declared($meta, 'og:title', 'twitter:title') ?: TextNormalizer::normalize($document->title),
                self::declared($meta, 'og:description', 'twitter:description', 'description') ?: null,
                self::image($meta, $page->finalUrl),
                self::declared($meta, 'og:site_name') ?: HostKey::forUrl($sharedUrl),
            )),
            DeclaredPageMedium::of($meta),
        );
    }

    private static function fileMedium(string $sharedUrl, ?string $contentType): ?EntryMedium
    {
        $type = strtolower((string) $contentType);
        $kind = match (true) {
            str_starts_with($type, 'image/') => VisualMediaKind::Image,
            str_starts_with($type, 'video/') => VisualMediaKind::Video,
            default => null,
        };
        $url = HttpsImageUrl::orNull($sharedUrl);

        return $kind === null || $url === null ? null : new EntryMedium($url, $kind->value);
    }
```

Update the class docblock: `/** Reads the card a page declares for itself, and the picture or video file it is or declares. */`. In `ShareLinkCards::cardsFor` keep the cards only for now: `$card = $this->pageCards->read($uncardedUrl)->card;` and collect non-null ones (Task 10 uses the medium).

- [ ] **Step 4: Run to see them pass**

Run: `cd backend && php bin/phpunit tests/Service/LinkCard tests/Service/Reader`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs && composer stan && composer md
git add -A backend && git commit -m "feat(#1524): a shared page says whether it is a picture or a video file"
```

---

### Task 10: The card pass serves titled shares and turns media targets into media shares

**Files:**
- Create: `backend/src/Service/LinkCard/Support/ShareTargets.php`
- Modify: `backend/src/Service/LinkCard/ShareLinkCards.php`
- Test: `backend/tests/Service/LinkCard/Support/ShareTargetsTest.php`, `backend/tests/Service/LinkCard/ShareLinkCardsTest.php`

**Interfaces:**
- Consumes: `PageCardModel` (Task 9), `Entry::getShare()/setShare()`.
- Produces: `ShareTargets::uncardedOf(Entry $entry, list<string> $cardedUrls): list<string>` — `[share url]` for a titled share (minus carded), else the post's `SharedLinks` uncarded. `ShareLinkCards` uses it for the budget; after fetching, a titled `link` share whose target page declared a medium becomes `Share::media($target)` with that medium first in `entry.media`.

- [ ] **Step 1: Write the failing tests**

`ShareTargetsTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Share;
use App\Service\LinkCard\Support\ShareTargets;
use PHPUnit\Framework\TestCase;

final class ShareTargetsTest extends TestCase
{
    private static function entry(?string $body): Entry
    {
        $now = new \DateTimeImmutable();
        $entry = new Entry(new Feed('https://feeds.example/feed'), 'g', 'https://example.com/story', 'T', $now, $now);
        $entry->setContentHtml($body);

        return $entry;
    }

    public function testATitledShareHasOnlyItsTarget(): void
    {
        $entry = self::entry('<p><a href="https://other.example/x">https://other.example/x</a></p>');
        $entry->setShare(Share::link('https://example.com/story'));

        self::assertSame(['https://example.com/story'], ShareTargets::uncardedOf($entry, []));
        self::assertSame([], ShareTargets::uncardedOf($entry, ['https://example.com/story']));
    }

    public function testAPostHasTheLinksItsBodyShares(): void
    {
        $entry = self::entry('<p><a href="https://a.example/1">https://a.example/1</a> <a href="https://b.example/2">https://b.example/2</a></p>');
        $entry->setShare(Share::link(null));

        self::assertSame(['https://b.example/2'], ShareTargets::uncardedOf($entry, ['https://a.example/1']));
    }
}
```

In `ShareLinkCardsTest` (read its existing helpers first — it builds entries, queues `LinkCards` rows and answers with `MockHttpClient`), add:

```php
    public function testATitledShareGetsTheCardOfItsTarget(): void
    // entry: titled, url https://example.com/story, share Share::link('https://example.com/story'), body without links;
    // MockResponse: an og page; assert one LinkCard stored with url https://example.com/story and the row marked done.

    public function testATitledShareWhoseTargetIsAPictureBecomesAMediaShare(): void
    // same entry with target https://i.example.com/chart, MockResponse content-type image/png;
    // assert reloaded entry share == Share::media('https://i.example.com/chart') and getMedia()[0]->url is that URL.

    public function testAPostSharingAPictureStaysALinkShare(): void
    // derived-title post sharing https://i.example.com/chart (body link), same image response;
    // assert share kind stays Link.
```

Write each with the file's own builder helpers; the three comments above are the exact arrange/assert for each.

- [ ] **Step 2: Run to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/LinkCard`
Expected: FAIL — `ShareTargets` not found; titled share gets no card (its body shares no link).

- [ ] **Step 3: Implement**

`ShareTargets`:

```php
<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Entity\Entry;

/** The pages an entry's cards come from: a titled share's one target, or the links a post's text shares. */
final class ShareTargets
{
    /**
     * @param list<string> $cardedUrls
     *
     * @return list<string> in the entry's order, without those already carded
     */
    public static function uncardedOf(Entry $entry, array $cardedUrls): array
    {
        $target = $entry->getShare()->url;
        if ($target !== null) {
            return \in_array($target, $cardedUrls, true) ? [] : [$target];
        }

        return SharedLinks::of((string) $entry->getContentHtml())->uncardedBy($cardedUrls);
    }

    private function __construct()
    {
    }
}
```

`ShareLinkCards`:
- `withinBudget`: replace `SharedLinks::of((string) $entry->getContentHtml())->uncardedBy($cardedUrls[$entry->requireId()] ?? [])` with `ShareTargets::uncardedOf($entry, $cardedUrls[$entry->requireId()] ?? [])`.
- `cardsFor` returns `list<PageCardModel>` (renamed `pageCardsFor`), keeping failures logged and skipped.
- `$cardsByRowId` becomes `$pageCardsByRowId` (`array<int, list<PageCardModel>>`).
- In `store()` per held row:

```php
            $pageCards = $pageCardsByRowId[$row->requireId()];
            $cards = self::cardsOf($pageCards);
            $this->linkCards->add($row->getEntry(), $storedCount, $cards);
            $this->dropCardedQuoteLines($row->getEntry(), $cards);
            $this->settleMediaTarget($row->getEntry(), $pageCards);
```

with

```php
    /**
     * @param list<PageCardModel> $pageCards
     *
     * @return list<LinkCardContent>
     */
    private static function cardsOf(array $pageCards): array
    {
        return array_values(array_filter(array_map(
            static fn (PageCardModel $pageCard): ?LinkCardContent => $pageCard->card,
            $pageCards,
        )));
    }

    /**
     * A titled share whose page is a picture or video file is shown as that medium. A post keeps its link card: its
     * own media, not a linked file, make it a media post.
     *
     * @param list<PageCardModel> $pageCards
     */
    private function settleMediaTarget(Entry $entry, array $pageCards): void
    {
        $target = $entry->getShare()->url;
        $medium = $pageCards[0]->medium ?? null;
        if ($target === null || $medium === null) {
            return;
        }
        $entry->setShare(Share::media($target));
        $entry->setMedia([$medium, ...$entry->getMedia()], $entry->getAttachments());
    }
```

Remove the unused `SharedLinks` import if `FETCHES_PER_CALL = SharedLinks::MAX` still needs it (it does — keep). Update the class docblock's "post" wording to "share".

- [ ] **Step 4: Run to see them pass**

Run: `cd backend && php bin/phpunit tests/Service/LinkCard tests/Service/Completion tests/Controller/Api/EntryCompletionControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs && composer stan && composer md && composer tramp
git add -A backend && git commit -m "feat(#1524): the card pass cards a titled share's target and shows a picture target as media"
```

---

### Task 11: The magazine reads the share

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/list/magazine/magazine-slot-fit.ts`, `frontend/src/app/reader/list/magazine/blocks/entry-link/entry-link.component.html|scss`, `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.html|scss|ts`, `frontend/src/app/reader/list/magazine/blocks/entry-kicker/entry-kicker.component.scss`, `frontend/src/app/reader/list/magazine/_magazine-card.scss`, every `EntryDto` factory (`grep -rl "titleDerived: " frontend/src frontend/e2e`)
- Test: `magazine-slot-fit.spec.ts`, `entry-link.component.spec.ts`, `entry-media.component.spec.ts`

**Interfaces:**
- Consumes: JSON `share: 'link' | 'media' | null` (Task 2).
- Produces: `EntryDto.share: EntryShare | null` with `export type EntryShare = 'link' | 'media';`. `isLinkPost(entry) = entry.share === 'link' && (entry.linkCards.length > 0 || entry.pending)`; `isMediaPost(entry) = entry.share === 'media' && postMedia(entry).length > 0`. Both blocks show `headline()` (styled as a post's text only for a post) and, for a titled share, `snippet()` beneath as a clamped dek.

- [ ] **Step 1: Add the field and the factories' default**

`models.ts`, after `titleDerived`:

```ts
  /** What the entry shares, so it takes the link or the media card; null for an article (#1524). */
  share: EntryShare | null;
```

and near the other DTO types: `export type EntryShare = 'link' | 'media';`. Then give every factory the default:

```bash
cd frontend
grep -rlE "^\s+titleDerived: (true|false),$" src e2e | xargs perl -pi -e 's/^(\s+)titleDerived: (true|false),\n/$&$1share: null,\n/'
```

Check the diff: only `EntryDto` literals should change; revert any hit that is not one (`git diff --stat`, then read each file's hunk).

- [ ] **Step 2: Write the failing tests**

`magazine-slot-fit.spec.ts` — replace the `titleDerived`/`isShort`-driven cases:

```ts
  it('settles a share still being completed as a link block whatever its slot', () => {
    expect(settle('thumb', entry({ share: 'link', pending: true }), 'thumb')).toBe('link');
  });

  it('settles a titled link share with its card as a link block', () => {
    expect(settle('hero', entry({ share: 'link', linkCards: [card] }), 'thumb')).toBe('link');
  });

  it('keeps a link share whose card never came in the slot it fits', () => {
    expect(settle('hero', entry({ share: 'link' }), 'thumb')).toBe('hero');
  });
```

(`card` — reuse the `LinkCardDto` constant the `isMediaPost` block already defines; hoist it to module scope.)

```ts
describe('isMediaPost', () => {
  it('is a media share with a picture', () => {
    expect(isMediaPost(entry({ share: 'media' }))).toBe(true);
  });

  it('is not an article, a link share or a media share without a picture', () => {
    expect(isMediaPost(entry())).toBe(false);
    expect(isMediaPost(entry({ titleDerived: true }))).toBe(false);
    expect(isMediaPost(entry({ share: 'link', linkCards: [card] }))).toBe(false);
    expect(isMediaPost(entry({ share: 'media', imageUrl: null }))).toBe(false);
  });

  it('takes a Short only by its share, like every other entry', () => {
    expect(isMediaPost(entry({ isShort: true }))).toBe(false);
    expect(isMediaPost(entry({ isShort: true, share: 'media' }))).toBe(true);
  });
});
```

Every other existing case that relied on `titleDerived: true` meaning "media post" or `isShort: true` meaning "media post" gets `share: 'media'` (or `share: 'link'` for link posts) added; the planner spec likewise (`magazine-planner.spec.ts`: grep `titleDerived: true` and `isShort: true` and add the matching share).

`entry-link.component.spec.ts`:

```ts
  it('shows a titled share’s headline as a headline, its own text beneath, then the card', () => {
    // mount entry({ share: 'link', title: 'C for Rust programmers', excerpt: 'Worth a read.', linkCards: [card] })
    // expect .title text 'C for Rust programmers' and no .post class; .dek text 'Worth a read.'; app-link-card present
  });

  it('shows no dek for a post: its text is already its headline', () => {
    // mount entry({ share: 'link', titleDerived: true, excerpt: 'Post text', linkCards: [card] })
    // expect .title has class post and text 'Post text'; no .dek
  });
```

`entry-media.component.spec.ts`:

```ts
  it('captions a titled share with its title and puts its own text beneath', async () => {
    // mount entry({ share: 'media', title: 'Chart of the day', excerpt: 'From the report.' , media: [picture] })
    // expect .caption text 'Chart of the day'; .dek text 'From the report.'
  });
```

Write these with each spec's own `mount`/`entry`/`card`/`picture` helpers; the comments are the exact arrange/assert.

- [ ] **Step 3: Run to see them fail**

Run (repo root): `docker compose exec -T frontend npm test -- src/app/reader/list/magazine`
Expected: FAIL on the new cases.

- [ ] **Step 4: Implement**

`magazine-slot-fit.ts`:

```ts
/** A link share with its card, or one still being completed, takes the `link` block
 *  whatever slot it was given (#1502, #1524). */
export function isLinkPost(entry: EntryDto): boolean {
  return entry.share === 'link' && (entry.linkCards.length > 0 || entry.pending);
}
```

```ts
/** A share whose point is its picture or video (#1522, #1524). */
export function isMediaPost(entry: EntryDto): boolean {
  return entry.share === 'media' && postMedia(entry).length > 0;
}
```

`_magazine-card.scss` — add the dek the kicker already draws, so three blocks share it:

```scss
@mixin dek($lines: 3) {
  margin: 0;
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: $lines;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
```

`entry-kicker.component.scss`: `.dek { @include magazine-card.dek; }`.

`entry-link.component.html`:

```html
  @if (headline()) {
    <p class="title" [class.post]="isPost()">{{ headline() }}</p>
  }
  @if (snippet()) {
    <p class="dek">{{ snippet() }}</p>
  }
```

`entry-link.component.scss`: the `.title` rule becomes the kicker's headline (`margin: 0; font-size: var(--fs-lg); font-weight: 500; color: var(--text-primary);`), `.title.post { @include post-text.post-text; }`, `.link.read .title { color: var(--text-secondary); }`, `.dek { @include magazine-card.dek; }`.

`entry-media.component.ts`: delete `caption`. `headline()` (from `EntryBlockBase`) is a post's text and a titled entry's title, the old caption rule, except that an untitled post with no text falls back to its title; use `headline()` everywhere the template read `caption()` (the caption, the player's `[title]`, the video's `aria-label`). `entry-media.component.html`:

```html
  @if (headline()) {
    <p class="caption" [class.post]="isPost()">{{ headline() }}</p>
  }
  @if (snippet()) {
    <p class="dek">{{ snippet() }}</p>
  }
```

`entry-media.component.scss`: `.caption` keeps `post-text(6)` only as `.caption.post`; the plain `.caption` takes the kicker headline rule; add `.dek { @include magazine-card.dek; }` and `@use '../../magazine-card';` if missing.

- [ ] **Step 5: Run the whole frontend gate**

Run (repo root): `docker compose exec -T frontend npm run check`
Expected: lint, prettier, stylelint, tsc (app, spec, e2e) and Jest all pass.

- [ ] **Step 6: Commit**

```bash
git add -A frontend && git commit -m "feat(#1524): the magazine takes the link and media card from the entry's share"
```

---

### Task 12: Verify end to end, backfill the local DB, look at it

- [ ] **Step 1: Both backend legs and the gates**

```bash
cd backend && composer check && composer md && composer test:parallel
docker compose exec php composer test
cd backend && composer infection:diff
```

Expected: green. Infection: escaped mutants on changed lines get a test, not a lower `minMsi`.

- [ ] **Step 2: Migrate and refresh the Docker stack**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console cache:clear
docker compose restart worker
```

- [ ] **Step 3: One-off backfill (not committed)**

Create an untracked, temporary `backend/src/Command/BackfillSharesCommand.php` (`app:backfill-shares`) that, in batches of 500 entries ordered by id with `$entityManager->clear()` between batches:
- for a Bluesky AT-URI entry: sets `Share::link(null)` if it has link cards or a pending row, else `Share::media(null)` if it has media, else none;
- for any other entry: runs `EntryShareClassifier::classify()`; then, when the share is `link` and the entry has no `share_enrichment` row, persists a `LinkCards` row (`queuedAt` now).

Run `docker compose exec php bin/console app:backfill-shares`, report the counts per kind, then delete the file (`rm backend/src/Command/BackfillSharesCommand.php`) and confirm `git status` is clean of it. Spot-check: HN 580009 → `link` with `share_url` its article; Daring Fireball 579491 → `link`; tagesschau and ZDF entries → none; a Mastodon post with a link → `link`.

- [ ] **Step 4: Look at it in the browser**

Open the magazine at https://localhost:8443 in the built-in browser, mobile viewport (`resize_window` preset `mobile`; the desktop UA is bot-blocked per memory) and desktop: the HN feed (cards with real titles, cards load as the rows are seen), Daring Fireball (short items as cards with commentary, essays as articles), a Mastodon feed (posts with links/pictures as before), a Bluesky feed (placeholder, then card or media), a YouTube channel (Shorts as media, other videos as articles), tagesschau (articles). Screenshot each, send them with `SendUserFile`.

- [ ] **Step 5: Scan the dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'
```

Expected: no new warnings beyond expected `No link card for {url}` lines.
