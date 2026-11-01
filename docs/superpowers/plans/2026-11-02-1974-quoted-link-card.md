# Quoted Post's Link Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a post quotes a link post, the reader shows the quoted post's link card (image, title, description, host) inside the quote box, like the Bluesky app.

**Architecture:** `QuotedPostContent` gains an optional `?LinkCardContent $linkCard`. Ingest fills it on both networks: Bluesky reads the `app.bsky.embed.external#view` in the quoted record view's `embeds[]` (directly or as a `recordWithMedia` media), and Mastodon reads the quoted status's `card`. Both go through the existing card readers (`BlueskyExternalCard`, `MastodonCard`), and `StorableQuotedPost` gates the card through `StorableLinkCard`. The card is stored in one JSON column, `entry.quoted_link_card`, inside the `EntryQuotedPost` embeddable. It is served as `quote.linkCard` (a full `LinkCardRow`, `readerToken` included), and it round-trips through backups. The frontend `<app-quoted-post>` renders it with the existing `<app-link-card>` in the quote's own layout and counts it in `quotingHeight`/`threadHeight`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (MySQL + SQLite), PHPUnit 12, Angular 20 (standalone, signals), Jest.

**Spec:** GitHub issue #1974 (`gh issue view 1974`). This plan argues from it. The executor reads both.

## Global Constraints

- Store the quoted post's card on ingest, for both networks (Bluesky `app.bsky.embed.external` view inside the record view's `embeds[]`; Mastodon the quoted status's `card`).
- Serve it in the quote JSON and include it in backups.
- Render it inside the quote card the way the Bluesky app does: image, title, description, host.
- Existing entries pick up the card only when they're ingested again: **no backfill, no data migration**.
- CLAUDE.md is binding: Clean Code, role folders, `final readonly`, no comments unless a reader would get the code wrong, PHPStan max, PHPMD-clean touched files, `declare(strict_types=1)`, HTTP status via `Response::HTTP_*`, migrations dialect-correct for MySQL **and** SQLite.
- Commit format `type(#1974): …`; no Claude attribution anywhere.
- Never post to a social network during checks. Never write to Strato.

## Decisions (resolved ambiguities)

1. **Storage: one JSON column, not five.** `EntryQuotedPost` already notes that the `entry` table has no MySQL row width left for more VARCHARs. A JSON column lives off-page like the TEXT urls, and it mirrors `quoted_media`. It is one column in the migration, the batch inserter and the coverage test. Shape: `{url, title, description, imageUrl, siteName}`, the same keys as `link_card`'s row and the backup's `linkCards` objects.
2. **`LinkCardContent` gets `toStored()` / `fromStoredOrNull()`.** The entity can't import `App\Http\LinkCardJson::fieldsOf()`, because persistence knows no HTTP. `BackupLines::linkCardFields()` builds the same array, and the new column would be the third copy. So `BackupLines` switches to `toStored()` and `linkCardFields()` is deleted (DRY). `LinkCardJson::fieldsOf()` stays: it is the wire shape and may diverge.
3. **`QuotedPostContent::$linkCard` defaults to `null`, as the last parameter.** It is a value object, and CLAUDE.md lets those default a value. Some 20 test call sites stay untouched. Every producer (`QuotedViewRecord`, `MastodonQuotedStatus`, `StorableQuotedPost`, `EntryQuotedPost::read`, `EntryLine::quoteOf`) passes it explicitly.
4. **A Bluesky GIF external stays media, not a card.** The rule matches `PostEmbedRenderer::external()`: only an external that `BlueskyEmbedMedia::gif()` rejects becomes a card.
5. **The JSON carries `readerToken`.** `<app-link-card>`'s `LinkCardDto` requires it, and it lets the card open in the reader like every other card. So `QuotedPostJson` becomes an injected `final readonly` service holding `LinkCardJson`. `EntryJson` and `CommentsJson` inject it. Comments quoting a link post get the card at no extra cost.
6. **The card uses the quote's layout.** `block` goes in the magazine (site line first, 1.91:1 image, two-line description) and `article` in the reader (image, title, description, host: the Bluesky order). A quote in `block` reads like its sibling magazine cards, and an article-sized card inside a clamped magazine quote would be oversized. The reader-opening mode follows the same surfaces as today: `layer` in the article, `alone` in the magazine.
7. **No backup `VERSION` bump.** `linkCard` is an additive key that an older file omits. `LineField::objectOrNull` reads a missing key as none, as it does for `origin` and `nestedQuote`.
8. **No FTS concern.** `ALTER TABLE … ADD COLUMN` on SQLite does not rebuild `entry`, so the `entry_search` triggers survive. `EntrySearchIndex::install()` is not needed.

## File Structure

Backend (`backend/`):
- Modify `src/Entity/QuotedPostContent.php`: new `?LinkCardContent $linkCard = null`.
- Modify `src/Entity/LinkCardContent.php`: `toStored()`, `fromStoredOrNull()`.
- Modify `src/Service/LinkCard/Support/StorableQuotedPost.php`: gate the card.
- Modify `src/Service/Bluesky/Support/QuotedViewRecord.php`: `linkCardOf()`.
- Modify `src/Service/Social/Support/MastodonQuotedStatus.php`: `MastodonCard::of($status->node('card'))`.
- Modify `src/Entity/EntryQuotedPost.php`: `quoted_link_card` JSON column.
- Create `migrations/Version20261102120000.php`.
- Modify `src/Repository/EntryBatchInserter.php`: the new column.
- Modify `src/Service/Backup/BackupLines.php`, `src/Service/Backup/Dto/EntryLine.php`.
- Modify `src/Http/QuotedPostJson.php`, `src/Http/EntryJson.php`, `src/Http/CommentsJson.php`.
- Tests: `tests/Service/Bluesky/Support/QuotedViewRecordTest.php`, `tests/Service/Social/Support/MastodonQuotedStatusTest.php`, `tests/Service/LinkCard/Support/StorableQuotedPostTest.php`, create `tests/Entity/LinkCardContentTest.php`, `tests/Entity/EntryQuotedPostTest.php`, `tests/Repository/EntryBatchInserterTest.php`, `tests/Service/Backup/Dto/EntryLineTest.php`, `tests/Support/FullyPopulatedAccount.php`, `tests/Support/BackupFieldDeclarations.php`, `tests/Service/Backup/AccountRestorerTest.php`, `tests/Http/QuotedPostJsonTest.php`, `tests/Http/CommentsJsonTest.php`, `tests/Support/JsonMappers.php`.

Docs: `docs/backup.md`, `docs/design-language.md`.

Frontend (`frontend/src/`):
- Modify `app/reader/quoted-post/quoted-post.ts`, `quoted-post.component.{ts,html,scss,spec.ts}`.
- Modify `app/reader/list/magazine/magazine-block-height.ts` and its spec.
- Modify `testing/entry-testing.ts` (`aQuotedPost` default `linkCard: null`).

---

### Task 1: The quoted post carries its link card from both networks

**Files:**
- Modify: `backend/src/Entity/QuotedPostContent.php`
- Modify: `backend/src/Service/LinkCard/Support/StorableQuotedPost.php`
- Modify: `backend/src/Service/Bluesky/Support/QuotedViewRecord.php`
- Modify: `backend/src/Service/Social/Support/MastodonQuotedStatus.php`
- Test: `backend/tests/Service/Bluesky/Support/QuotedViewRecordTest.php`, `backend/tests/Service/Social/Support/MastodonQuotedStatusTest.php`, `backend/tests/Service/LinkCard/Support/StorableQuotedPostTest.php`

**Interfaces:**
- Consumes: `App\Entity\LinkCardContent` (existing), `BlueskyExternalCard::of(JsonNodeModel $external): ?LinkCardContent`, `MastodonCard::of(JsonNodeModel $card): ?LinkCardContent`, `StorableLinkCard::of(LinkCardContent): ?LinkCardContent`, `BlueskyEmbedMedia::gif(JsonNodeModel): ?ParsedMediumModel`.
- Produces: `QuotedPostContent::__construct(…, ?QuotedPostReference $nestedQuote, ?LinkCardContent $linkCard = null)` with `public ?LinkCardContent $linkCard`.

- [ ] **Step 1: Write the failing Bluesky tests** in `QuotedViewRecordTest.php` (add `use App\Entity\LinkCardContent;`). Replace `testAQuotedLinkCardGivesNoMedia` with the card assertion and add the rest:

```php
    public function testTheRealQuoteOfALinkPostCarriesItsCard(): void
    {
        $quote = QuotedViewRecord::of(Bluesky::post('record')->node('embed')->node('record'));

        self::assertEquals(
            new LinkCardContent(
                'https://www.motherjones.com/politics/2026/10/stress-test-campaign-finance-corruption-midterms-video-podcast/',
                'The midterm spending bonanza is threatening democracy like never before.',
                null,
                null,
                'motherjones.com',
            ),
            $quote?->linkCard,
        );
    }

    public function testAQuotedLinkCardIsItsCardAndNoMedia(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://www.example.com/story',
                'title' => 'T',
                'description' => 'D',
                'thumb' => 'https://cdn.bsky.app/t.jpg',
            ],
        ]));

        self::assertSame([], $quote?->media);
        self::assertNull($quote->nestedQuote);
        self::assertEquals(
            new LinkCardContent('https://www.example.com/story', 'T', 'D', 'https://cdn.bsky.app/t.jpg', 'example.com'),
            $quote->linkCard,
        );
    }

    public function testAQuotedRecordWithMediaCarriesTheCardOfItsMedia(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'media' => [
                '$type' => 'app.bsky.embed.external#view',
                'external' => ['uri' => 'https://example.com/a', 'title' => 'A'],
            ],
            'record' => ['record' => self::nested()],
        ]));

        self::assertSame('https://example.com/a', $quote?->linkCard?->url);
        self::assertSame('nested.bsky.social', $quote->nestedQuote?->handle);
    }

    public function testAQuotedGifIsNoCard(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://media.tenor.com/a/clap.gif?hh=237&ww=316', 'title' => 'Clap'],
        ]));

        self::assertNull($quote?->linkCard);
    }

    public function testAQuotedPostWithImagesOrAnUntitledLinkHasNoCard(): void
    {
        $images = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.images#view',
            'images' => [['fullsize' => 'https://cdn.bsky.app/full/1.jpg']],
        ]));
        $untitled = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://example.com/a'],
        ]));

        self::assertNull($images?->linkCard);
        self::assertNull($untitled?->linkCard);
    }
```

Check that the recordWithMedia view shape matches the one `testAQuotedRecordWithMediaGivesItsMediaAndItsOwnQuoteAsTheNestedLine` already builds, and copy its `record` nesting if it differs.

- [ ] **Step 2: Write the failing Mastodon test** in `MastodonQuotedStatusTest.php` (add `use App\Entity\LinkCardContent;`):

```php
    public function testAQuotedStatusCarriesItsPreviewCard(): void
    {
        $quote = self::quoted(MastodonQuotedStatus::of(JsonNodeModel::of(self::statusFields(['card' => [
            'url' => 'https://news.example/story',
            'title' => 'The story',
            'description' => 'What happened.',
            'image' => 'https://files.mastodon.example/cards/s.jpg',
            'provider_name' => 'News',
        ]]))));

        self::assertEquals(
            new LinkCardContent(
                'https://news.example/story',
                'The story',
                'What happened.',
                'https://files.mastodon.example/cards/s.jpg',
                'News',
            ),
            $quote->linkCard,
        );
    }

    public function testAQuotedStatusWithoutACardHasNone(): void
    {
        self::assertNull(self::quoted(MastodonQuotedStatus::of(JsonNodeModel::of(self::statusFields())))->linkCard);
    }
```

- [ ] **Step 3: Write the failing gate tests** in `StorableQuotedPostTest.php`. Add `use App\Entity\LinkCardContent;`, extend the helper with a last parameter `?LinkCardContent $linkCard = null` and pass it as the 9th constructor argument. Then add:

```php
    public function testAQuotesCardPassesTheGateEveryCardPasses(): void
    {
        $card = new LinkCardContent('https://news.example/s', 'Story', null, 'http://news.example/s.jpg', 'news.example');

        self::assertEquals(
            new LinkCardContent('https://news.example/s', 'Story', null, null, 'news.example'),
            StorableQuotedPost::of(self::quote(linkCard: $card))?->linkCard,
        );
    }

    public function testACardNoCardMayHoldIsDroppedAndTheQuoteKept(): void
    {
        $card = new LinkCardContent('https://news.example/s', '  ', null, null, 'news.example');
        $quote = StorableQuotedPost::of(self::quote(linkCard: $card));

        self::assertNotNull($quote);
        self::assertNull($quote->linkCard);
    }
```

`testAQuoteThatFitsIsKeptAsItIs` must also hold with a card. Change its `self::quote()` to `self::quote(linkCard: new LinkCardContent('https://news.example/s', 'Story', 'D', 'https://news.example/s.jpg', 'news.example'))`.

- [ ] **Step 4: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky/Support/QuotedViewRecordTest.php tests/Service/Social/Support/MastodonQuotedStatusTest.php tests/Service/LinkCard/Support/StorableQuotedPostTest.php`
Expected: errors on the unknown property/argument `linkCard`.

- [ ] **Step 5: Add the field.** `src/Entity/QuotedPostContent.php`, after `$nestedQuote`:

```php
        public ?QuotedPostReference $nestedQuote,
        public ?LinkCardContent $linkCard = null,
```

(`LinkCardContent` is in the same namespace, so no `use` is needed.)

- [ ] **Step 6: Gate it.** In `StorableQuotedPost::of()`, pass a 9th argument, and import `App\Entity\LinkCardContent`:

```php
            self::storableReference($quote->nestedQuote),
            $quote->linkCard === null ? null : StorableLinkCard::of($quote->linkCard),
        );
```

(`StorableLinkCard` is in the same namespace, `App\Service\LinkCard\Support`.)

- [ ] **Step 7: Read the Bluesky card.** In `QuotedViewRecord.php`, import `App\Entity\LinkCardContent`, pass `self::linkCardOf($embed),` after `self::nestedQuoteOf($embed),` in `of()`, and add:

```php
    private static function linkCardOf(JsonNodeModel $embed): ?LinkCardContent
    {
        return match ($embed->type()) {
            self::EXTERNAL => self::cardOfExternal($embed->node('external')),
            self::RECORD_WITH_MEDIA => self::linkCardOf($embed->node('media')),
            default => null,
        };
    }

    private static function cardOfExternal(JsonNodeModel $external): ?LinkCardContent
    {
        return BlueskyEmbedMedia::gif($external) === null ? BlueskyExternalCard::of($external) : null;
    }
```

(`BlueskyExternalCard` is in the same namespace.)

- [ ] **Step 8: Read the Mastodon card.** In `MastodonQuotedStatus::of()`, after `self::nestedOf(…),` add:

```php
            MastodonCard::of($status->node('card')),
```

(`MastodonCard` is in the same namespace, `App\Service\Social\Support`.)

- [ ] **Step 9: Run the tests and watch them pass**

Run: `cd backend && php bin/phpunit tests/Service/Bluesky tests/Service/Social tests/Service/LinkCard`
Expected: PASS. If a test that compares a whole quote from `record`/`record-with-media` fixtures now differs only by the card, extend that test's expected value with the card. Don't loosen its assertion.

- [ ] **Step 10: Gates for the touched files**

Run: `cd backend && composer cs && composer stan && vendor/bin/phpmd src/Entity/QuotedPostContent.php,src/Service/LinkCard/Support/StorableQuotedPost.php,src/Service/Bluesky/Support/QuotedViewRecord.php,src/Service/Social/Support/MastodonQuotedStatus.php text phpmd.xml.dist`
Expected: clean.

- [ ] **Step 11: Commit**

```bash
git add backend/src/Entity/QuotedPostContent.php backend/src/Service/LinkCard/Support/StorableQuotedPost.php backend/src/Service/Bluesky/Support/QuotedViewRecord.php backend/src/Service/Social/Support/MastodonQuotedStatus.php backend/tests/Service/Bluesky/Support/QuotedViewRecordTest.php backend/tests/Service/Social/Support/MastodonQuotedStatusTest.php backend/tests/Service/LinkCard/Support/StorableQuotedPostTest.php
git commit -m "feat(#1974): a quoted post carries its link card from Bluesky and Mastodon"
```

---

### Task 2: Store the card, migrate both dialects, round-trip it through backups

**Files:**
- Modify: `backend/src/Entity/LinkCardContent.php`
- Create: `backend/tests/Entity/LinkCardContentTest.php`
- Modify: `backend/src/Entity/EntryQuotedPost.php`, `backend/tests/Entity/EntryQuotedPostTest.php`
- Create: `backend/migrations/Version20261102120000.php`
- Modify: `backend/src/Repository/EntryBatchInserter.php`, `backend/tests/Repository/EntryBatchInserterTest.php`
- Modify: `backend/src/Service/Backup/BackupLines.php`, `backend/src/Service/Backup/Dto/EntryLine.php`, `backend/tests/Service/Backup/Dto/EntryLineTest.php`
- Modify: `backend/tests/Support/FullyPopulatedAccount.php`, `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Service/Backup/AccountRestorerTest.php`
- Modify: `docs/backup.md`

**Interfaces:**
- Consumes: `QuotedPostContent::$linkCard` (Task 1).
- Produces:
  - `LinkCardContent::toStored(): array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string}`
  - `LinkCardContent::fromStoredOrNull(?array $stored): ?LinkCardContent` (`@param array<string, mixed>|null`)
  - Column `entry.quoted_link_card` (MySQL `JSON`, SQLite `CLOB`), ORM field `share.quotedPost.linkCard`
  - Backup key `quote.linkCard` (object or null)

- [ ] **Step 1: Write the failing value tests.** Create `tests/Entity/LinkCardContentTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LinkCardContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkCardContentTest extends TestCase
{
    public function testAStoredCardReadsBackWhole(): void
    {
        $card = new LinkCardContent('https://n.example/s', 'Story', 'What happened.', 'https://n.example/s.jpg', 'News');

        self::assertSame(
            [
                'url' => 'https://n.example/s',
                'title' => 'Story',
                'description' => 'What happened.',
                'imageUrl' => 'https://n.example/s.jpg',
                'siteName' => 'News',
            ],
            $card->toStored(),
        );
        self::assertEquals($card, LinkCardContent::fromStoredOrNull($card->toStored()));
    }

    public function testNothingStoredIsNoCard(): void
    {
        self::assertNull(LinkCardContent::fromStoredOrNull(null));
    }

    /** @return iterable<string, array{string}> */
    public static function requiredKeys(): iterable
    {
        yield 'url' => ['url'];
        yield 'title' => ['title'];
        yield 'site name' => ['siteName'];
    }

    #[DataProvider('requiredKeys')]
    public function testAStoredCardMissingARequiredKeyIsNoCard(string $missing): void
    {
        $stored = (new LinkCardContent('https://n.example/s', 'Story', null, null, 'News'))->toStored();
        unset($stored[$missing]);

        self::assertNull(LinkCardContent::fromStoredOrNull($stored));
    }

    public function testOptionalFieldsOfTheWrongTypeReadAsNone(): void
    {
        $card = LinkCardContent::fromStoredOrNull(
            ['url' => 'https://n.example/s', 'title' => 'Story', 'siteName' => 'News', 'description' => 3, 'imageUrl' => []],
        );

        self::assertEquals(new LinkCardContent('https://n.example/s', 'Story', null, null, 'News'), $card);
    }
}
```

- [ ] **Step 2: Write the failing embeddable test.** In `tests/Entity/EntryQuotedPostTest.php`, import `App\Entity\LinkCardContent`. In `testAStoredQuoteReadsBackWhole`, add the 9th argument `new LinkCardContent('https://n.example/s', 'Story', 'D', 'https://n.example/s.jpg', 'n.example')`. Add:

```php
    public function testAQuoteWithoutACardReadsBackWithNone(): void
    {
        $stored = new EntryQuotedPost();
        $stored->store(new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, '', [], null));

        self::assertNull($stored->read()?->linkCard);
    }
```

- [ ] **Step 3: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Entity/LinkCardContentTest.php tests/Entity/EntryQuotedPostTest.php`
Expected: FAIL, because `toStored` is undefined and the card is not read back.

- [ ] **Step 4: Implement the value methods** in `src/Entity/LinkCardContent.php`:

```php
    /** @return array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string} */
    public function toStored(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'imageUrl' => $this->imageUrl,
            'siteName' => $this->siteName,
        ];
    }

    /** @param array<string, mixed>|null $stored */
    public static function fromStoredOrNull(?array $stored): ?self
    {
        $url = $stored['url'] ?? null;
        $title = $stored['title'] ?? null;
        $siteName = $stored['siteName'] ?? null;
        if (!\is_string($url) || !\is_string($title) || !\is_string($siteName)) {
            return null;
        }

        return new self($url, $title, self::stringOrNull($stored, 'description'), self::stringOrNull($stored, 'imageUrl'), $siteName);
    }

    /** @param array<string, mixed> $stored */
    private static function stringOrNull(array $stored, string $key): ?string
    {
        $value = $stored[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
```

PHPStan narrows `$stored` to non-null after the guard only through `$url`. If it complains about `$stored` being nullable in the call, add `$stored === null ||` as the guard's first operand.

- [ ] **Step 5: Add the column.** In `src/Entity/EntryQuotedPost.php`, after `$nestedHandle`:

```php
    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'quoted_link_card', type: Types::JSON, nullable: true)]
    private ?array $linkCard = null;
```

In `store()` add `$this->linkCard = $quote->linkCard?->toStored();`. In `read()`, pass `LinkCardContent::fromStoredOrNull($this->linkCard),` as the 9th argument.

- [ ] **Step 6: Write the migration** `migrations/Version20261102120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add entry.quoted_link_card: the link card a quoted post shows (#1974)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('entry')->hasColumn('quoted_link_card'),
            'entry.quoted_link_card already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE entry ADD quoted_link_card JSON DEFAULT NULL');

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE entry ADD COLUMN quoted_link_card CLOB DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the quoted link card migration.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP COLUMN quoted_link_card');
    }
}
```

- [ ] **Step 7: Run the entity tests and watch them pass**

Run: `cd backend && php bin/phpunit tests/Entity/LinkCardContentTest.php tests/Entity/EntryQuotedPostTest.php`
Expected: PASS.

- [ ] **Step 8: Verify the migration on both dialects** (its own verification, see CLAUDE.md Gotchas):

```bash
cd backend
DATABASE_URL="sqlite:///%kernel.project_dir%/var/migration-check-1974.db" php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL="sqlite:///%kernel.project_dir%/var/migration-check-1974.db" php bin/console doctrine:schema:validate
rm -f var/migration-check-1974.db
cd .. && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: both validate "in sync". The Docker step also applies the column to the live dev DB, which the live check in Task 5 needs. Check first that the php container serves this checkout's code (memory: *Check the container is current*).

- [ ] **Step 9: Write the failing restore tests.** In `tests/Repository/EntryBatchInserterTest.php`, in `testARestoredRowKeepsItsQuotedPost`, add the 9th argument `new LinkCardContent('https://news.example/s', 'Story', 'D', 'https://news.example/s.jpg', 'news.example')` (import `App\Entity\LinkCardContent` if it isn't already). `testARestoredQuoteWithoutANestedQuoteKeepsNone` already covers a quote without a card: its `assertEquals($quote, …)` holds with `linkCard: null`.

In `tests/Service/Backup/Dto/EntryLineTest.php`, add to `quoteFields()`:

```php
            'linkCard' => [
                'url' => 'https://news.example/s',
                'title' => 'Story',
                'description' => 'D',
                'imageUrl' => 'https://news.example/s.jpg',
                'siteName' => 'news.example',
            ],
```

Extend the expected `QuotedPostContent` in `testReadsTheQuotedPost` with `new LinkCardContent('https://news.example/s', 'Story', 'D', 'https://news.example/s.jpg', 'news.example')`, and add:

```php
    public function testAnOlderFilesQuoteWithoutACardReadsWithNone(): void
    {
        $fields = self::quoteFields();
        unset($fields['linkCard']);

        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'quote', 'quote' => $fields]);

        self::assertNotNull($line->quote);
        self::assertNull($line->quote->linkCard);
    }

    public function testAQuotesCardNoCardMayHoldIsDroppedAndTheQuoteKept(): void
    {
        $fields = self::quoteFields();
        $fields['linkCard'] = ['url' => 'javascript:alert(1)', 'title' => 'x', 'siteName' => 'x'];

        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'quote', 'quote' => $fields]);

        self::assertNotNull($line->quote);
        self::assertNull($line->quote->linkCard);
    }
```

In `tests/Support/FullyPopulatedAccount.php`, give the populated quote a 9th argument `new LinkCardContent('https://populated.example/story', 'A quoted story', 'Its description.', 'https://populated.example/story.jpg', 'populated.example')`. In `tests/Support/BackupFieldDeclarations.php`, after the `nestedHandle` line, add `'share.quotedPost.linkCard' => 'quote.linkCard',`. In `tests/Service/Backup/AccountRestorerTest.php`, add `'share.quotedPost.linkCard'` to the `Entry` field list after `'share.quotedPost.nestedHandle'`.

- [ ] **Step 10: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Repository/EntryBatchInserterTest.php tests/Service/Backup`
Expected: FAIL. The inserter drops the card, `EntryLine` ignores `linkCard`, and the exporter writes none.

- [ ] **Step 11: Insert the column.** In `EntryBatchInserter.php`, append `'quoted_link_card'` to `COLUMNS` after `'quoted_nested_handle'`. In `quoteColumns()`, change `array_fill(0, 9, null)` to `array_fill(0, 10, null)` and append to the returned list:

```php
            $quote->nestedQuote?->url, $quote->nestedQuote?->handle,
            $quote->linkCard === null ? null : json_encode($quote->linkCard->toStored(), \JSON_THROW_ON_ERROR),
```

- [ ] **Step 12: Export and restore it.** In `BackupLines.php`, delete `linkCardFields()` and write:

```php
            'linkCards' => array_map(static fn (LinkCardContent $card): array => $card->toStored(), $companions->linkCards),
```

In `quoteFields()`, add after `'nestedQuote' => …`:

```php
            'linkCard' => $quote->linkCard?->toStored(),
```

In `EntryLine.php`, extract the per-card gate from `linkCardsOf()` and reuse it for the quote:

```php
    /** @param array<string, mixed> $card */
    private static function linkCardOf(array $card): ?LinkCardContent
    {
        return StorableLinkCard::of(new LinkCardContent(
            LineField::string($card, 'url'),
            LineField::string($card, 'title'),
            LineField::stringOrNull($card, 'description'),
            LineField::stringOrNull($card, 'imageUrl'),
            LineField::string($card, 'siteName'),
        ));
    }
```

`linkCardsOf()` becomes:

```php
        $cards = array_map(self::linkCardOf(...), LineField::objectListOrEmpty($line, 'linkCards'));

        return array_values(array_filter($cards));
```

In `quoteOf()`, read the object before the `return`, `$card = LineField::objectOrNull($fields, 'linkCard');`, and pass a 9th argument to `QuotedPostContent`:

```php
            $card === null ? null : self::linkCardOf($card),
```

- [ ] **Step 13: Run the storage and backup tests and watch them pass**

Run: `cd backend && php bin/phpunit tests/Entity tests/Repository/EntryBatchInserterTest.php tests/Service/Backup`
Expected: PASS, including `BackupSchemaCoverageTest`, which now finds `share.quotedPost.linkCard` declared.

- [ ] **Step 14: Document the backup field.** In `docs/backup.md`, in the `entry` row's quote clause, change "`media` and `nestedQuote`, the post that one quotes in turn, with its `url` and `handle`;" to "`media`, `nestedQuote`, the post that one quotes in turn, with its `url` and `handle`, and `linkCard`, the quoted post's link card with the same fields as a `linkCards` entry (a restore drops a card no card may hold, and keeps the quote);".

- [ ] **Step 15: Gates**

Run: `cd backend && composer cs && composer stan && vendor/bin/phpmd src/Entity/LinkCardContent.php,src/Entity/EntryQuotedPost.php,src/Repository/EntryBatchInserter.php,src/Service/Backup/BackupLines.php,src/Service/Backup/Dto/EntryLine.php text phpmd.xml.dist`
Expected: clean.

- [ ] **Step 16: Commit**

```bash
git add backend/src/Entity/LinkCardContent.php backend/src/Entity/EntryQuotedPost.php backend/migrations/Version20261102120000.php backend/src/Repository/EntryBatchInserter.php backend/src/Service/Backup backend/tests/Entity backend/tests/Repository/EntryBatchInserterTest.php backend/tests/Service/Backup backend/tests/Support/FullyPopulatedAccount.php backend/tests/Support/BackupFieldDeclarations.php docs/backup.md
git commit -m "feat(#1974): store the quoted post's link card and carry it through backups"
```

---

### Task 3: Serve the card in the quote JSON

**Files:**
- Modify: `backend/src/Http/QuotedPostJson.php`, `backend/src/Http/EntryJson.php`, `backend/src/Http/CommentsJson.php`
- Modify: `backend/tests/Support/JsonMappers.php`, `backend/tests/Http/QuotedPostJsonTest.php`, `backend/tests/Http/CommentsJsonTest.php`

**Interfaces:**
- Consumes: `QuotedPostContent::$linkCard` (Task 1), `LinkCardJson::ofOrNull(?LinkCardContent): ?LinkCardRow` (existing).
- Produces: `final readonly class QuotedPostJson { __construct(LinkCardJson $linkCardJson); ofOrNull(?QuotedPostContent): ?QuoteRow }`, where `QuoteRow` gains `linkCard: LinkCardRow|null`. Also `JsonMappers::quotedPostJson(): QuotedPostJson`. The wire key is `quote.linkCard`, with keys `url, title, description, imageUrl, siteName, readerToken`.

- [ ] **Step 1: Write the failing tests.** In `tests/Http/QuotedPostJsonTest.php`, replace every `QuotedPostJson::ofOrNull(` with `JsonMappers::quotedPostJson()->ofOrNull(` (import `App\Tests\Support\JsonMappers`). In the first test's expected array, add `'linkCard' => null,` after `nestedQuote`. Then add:

```php
    public function testAQuotesCardCarriesTheTokenThatOpensItInTheReader(): void
    {
        $card = new LinkCardContent('https://n.example/s', 'Story', 'D', 'https://n.example/s.jpg', 'n.example');
        $quote = new QuotedPostContent('https://x.example/p/1', 'N', 'h', null, null, 't', [], null, $card);

        self::assertSame(
            [
                'url' => 'https://n.example/s',
                'title' => 'Story',
                'description' => 'D',
                'imageUrl' => 'https://n.example/s.jpg',
                'siteName' => 'n.example',
                'readerToken' => JsonMappers::readerToken('https://n.example/s'),
            ],
            JsonMappers::quotedPostJson()->ofOrNull($quote)['linkCard'] ?? null,
        );
    }
```

(import `App\Entity\LinkCardContent`). In `tests/Http/CommentsJsonTest.php`, change line 81's `QuotedPostJson::ofOrNull($quote)` to `JsonMappers::quotedPostJson()->ofOrNull($quote)`, and drop the now-unused `use App\Http\QuotedPostJson;`.

- [ ] **Step 2: Add the mapper factory** in `tests/Support/JsonMappers.php` (import `App\Http\QuotedPostJson`):

```php
    public static function quotedPostJson(): QuotedPostJson
    {
        return new QuotedPostJson(self::linkCardJson());
    }

    public static function commentsJson(): CommentsJson
    {
        return new CommentsJson(self::linkCardJson(), self::quotedPostJson());
    }

    public static function entryJson(): EntryJson
    {
        return new EntryJson(self::linkCardJson(), self::threadPostJson(), self::quotedPostJson());
    }
```

(replacing the two existing factories).

- [ ] **Step 3: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Http`
Expected: errors, because `QuotedPostJson` has no constructor and is called statically.

- [ ] **Step 4: Make `QuotedPostJson` a service** (`src/Http/QuotedPostJson.php`):

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Entity\QuotedPostContent;

/**
 * @phpstan-import-type LinkCardRow from LinkCardJson
 * @phpstan-type QuoteRow array{
 *   url: string, authorName: string, authorHandle: string, authorAvatarUrl: string|null, postedAt: string|null,
 *   text: string, media: list<array<string, string|int|true>>,
 *   nestedQuote: array{url: string, handle: string|null}|null,
 *   linkCard: LinkCardRow|null
 * }
 */
final readonly class QuotedPostJson
{
    public function __construct(private LinkCardJson $linkCardJson)
    {
    }

    /** @return QuoteRow|null */
    public function ofOrNull(?QuotedPostContent $quote): ?array
    {
        if ($quote === null) {
            return null;
        }
        $nested = $quote->nestedQuote;

        return [
            'url' => $quote->url,
            'authorName' => $quote->authorName,
            'authorHandle' => $quote->authorHandle,
            'authorAvatarUrl' => $quote->authorAvatarUrl,
            'postedAt' => $quote->postedAt?->format(\DateTimeInterface::ATOM),
            'text' => $quote->text,
            'media' => EntryMedia::toJsonList($quote->media),
            'nestedQuote' => $nested === null ? null : ['url' => $nested->url, 'handle' => $nested->handle],
            'linkCard' => $this->linkCardJson->ofOrNull($quote->linkCard),
        ];
    }
}
```

- [ ] **Step 5: Inject it.** In `EntryJson`, add the constructor parameter `private QuotedPostJson $quotedPostJson,` after `$threadPostJson`, and replace `QuotedPostJson::ofOrNull($entry->getQuotedPost())` with `$this->quotedPostJson->ofOrNull($entry->getQuotedPost())`. In `CommentsJson`, add `private QuotedPostJson $quotedPostJson,` after `$linkCardJson`, and replace `QuotedPostJson::ofOrNull($comment->embeds->quote)` with `$this->quotedPostJson->ofOrNull($comment->embeds->quote)`. Autowiring covers `src/Http`. Confirm with `grep -rn "QuotedPostJson::\|new EntryJson(\|new CommentsJson(" src tests` that nothing else calls the old forms.

- [ ] **Step 6: Run the HTTP and controller tests and watch them pass**

Run: `cd backend && php bin/phpunit tests/Http tests/Controller`
Expected: PASS.

- [ ] **Step 7: Gates**

Run: `cd backend && composer cs && composer stan && vendor/bin/phpmd src/Http/QuotedPostJson.php,src/Http/EntryJson.php,src/Http/CommentsJson.php text phpmd.xml.dist`
Expected: clean. `EntryJson` is a long file. If PHPMD flags it, fix what the metric points at; don't tune the threshold.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Http backend/tests/Http backend/tests/Support/JsonMappers.php
git commit -m "feat(#1974): serve the quoted post's link card"
```

---

### Task 4: Render the card inside the quote and count it in the magazine height

**Files:**
- Modify: `frontend/src/app/reader/quoted-post/quoted-post.ts`
- Modify: `frontend/src/app/reader/quoted-post/quoted-post.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/reader/list/magazine/magazine-block-height.ts`, `magazine-block-height.spec.ts`
- Modify: `frontend/src/testing/entry-testing.ts`
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: `quote.linkCard` (Task 3), `LinkCardDto` (`app/reader/link-card/link-card.ts`), `LinkCardComponent` with inputs `card`, `layout: LinkCardLayout`, `opensInReader: LinkCardReaderOpening | null` (`app/reader/link-card/link-card.component.ts`), and `aLinkCard()` (`testing/entry-testing.ts`).
- Produces: `QuotedPostDto.linkCard: LinkCardDto | null`.

- [ ] **Step 1: Extend the DTO and the test builder.** In `quoted-post.ts`, add `import { LinkCardDto } from '../link-card/link-card';` and the field after `nestedQuote`:

```ts
  /** The quoted post's own link card (#1974). */
  linkCard: LinkCardDto | null;
```

In `testing/entry-testing.ts`, in `aQuotedPost`, add `linkCard: null,` after `nestedQuote: null,`.

- [ ] **Step 2: Write the failing component specs** in `quoted-post.component.spec.ts` (import `aLinkCard` from `'../../../testing/entry-testing'`):

```ts
  it('shows the quoted post’s link card inside the box, in the quote’s layout', () => {
    const fixture = mount(
      { linkCard: aLinkCard({ title: 'A shared story', siteName: 'news.test' }) },
      'article',
    );

    const card = query(fixture, '.box app-link-card')!;
    expect(card.classList).toContain('article');
    expect(card.querySelector('strong')!.textContent).toBe('A shared story');
    expect(card.querySelector('a')!.getAttribute('href')).toBe('https://news.test/story');
  });

  it('puts the card in the block layout in the magazine', () => {
    expect(query(mount({ linkCard: aLinkCard() }), 'app-link-card')!.classList).toContain('block');
  });

  it('opens the card’s page without reaching a parent click', () => {
    const fixture = mount({ linkCard: aLinkCard() });
    const parentClick = jest.fn();
    (fixture.nativeElement as HTMLElement).addEventListener('click', parentClick);
    const link = query(fixture, 'app-link-card a') as HTMLAnchorElement;
    link.addEventListener('click', (event) => event.preventDefault());

    link.click();

    expect(link.target).toBe('_blank');
    expect(parentClick).not.toHaveBeenCalled();
  });

  it('renders no card without one', () => {
    expect(query(mount({ linkCard: null }), 'app-link-card')).toBeNull();
  });
```

- [ ] **Step 3: Write the failing height spec** in `magazine-block-height.spec.ts`:

```ts
  it('adds the quoted post’s link card to the quoted box, more with an image', () => {
    const bare = quotingHeight(post('Hi', aQuotedPost({ linkCard: aLinkCard() })));
    const pictured = quotingHeight(
      post('Hi', aQuotedPost({ linkCard: aLinkCard({ imageUrl: 'https://news.test/s.jpg' }) })),
    );

    expect(bare).toBeGreaterThan(quotingHeight(post('Hi')));
    expect(pictured).toBeGreaterThan(bare);
  });
```

- [ ] **Step 4: Run them and watch them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/quoted-post src/app/reader/list/magazine/magazine-block-height`
Expected: FAIL. There is no `app-link-card` in the quote and the height is unchanged.

- [ ] **Step 5: Render the card.** In `quoted-post.component.ts`, add `LinkCardComponent` and `LinkCardReaderOpening` from `'../link-card/link-card.component'` to the imports and the `imports` array, then add:

```ts
  protected readonly cardOpening = computed<LinkCardReaderOpening>(() =>
    this.layout() === 'article' ? 'layer' : 'alone',
  );
```

In `quoted-post.component.html`, between the `@if (post.media.length)` block and the `@if (post.nestedQuote …)` block:

```html
  @if (post.linkCard; as card) {
    <app-link-card
      [card]="card"
      [layout]="layout()"
      [opensInReader]="cardOpening()"
      (click)="$event.stopPropagation()"
      (keydown.enter)="$event.stopPropagation()"
      (keydown.space)="$event.stopPropagation()"
    />
  }
```

In `quoted-post.component.scss`, add (the box's stretched `.open` link must not swallow the card's clicks):

```scss
app-link-card {
  z-index: 1;
}
```

(`<app-link-card>`'s own `:host` is already `position: relative`.)

- [ ] **Step 6: Count it in the height.** In `magazine-block-height.ts`, rename `threadCardHeight` to `linkCardHeight` (both call sites) and in `quotedBoxHeight` add the card after the media:

```ts
    withGap(mediaHeight(quote.media, QUOTING.quoteMediaWidth)) +
    withGap(linkCardHeight(quote.linkCard)) +
```

and update its doc line to `/** The quoted box: its header, text, media, link card and nested-quote line. */`. The thread card constants were measured at a 320px inner width. The quote box's inner width is 314px (`quoteMediaWidth`), close enough to reuse them. Task 5's live check confirms.

- [ ] **Step 7: Run them and watch them pass**

Run: `docker compose exec -T frontend npx jest src/app/reader/quoted-post src/app/reader/list/magazine`
Expected: PASS.

- [ ] **Step 8: Update the design language.** In `docs/design-language.md`, in the "A quoted post is one component (#1612)" paragraph, after "videos and the "Quoting a post by @handle" line sit above it.", add: "A quoted link post's card (#1974) sits inside the box as an `<app-link-card>` in the quote's own layout, above the stretched link too, and opens in the reader as a layer in the article and alone in the magazine." In the **Quoting** row of the block table, after "the quoted media in the media share's grid, a video playable in place.", add " The quoted post's link card follows its media."

- [ ] **Step 9: Gates**

Run: `cd frontend && npm run check && npx ng build`
Expected: both pass. `npm run check` misses template errors, so `ng build` is required.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader/quoted-post frontend/src/app/reader/list/magazine/magazine-block-height.ts frontend/src/app/reader/list/magazine/magazine-block-height.spec.ts frontend/src/testing/entry-testing.ts docs/design-language.md
git commit -m "feat(#1974): show the quoted post's link card inside the quote"
```

---

### Task 5: Final gates, simplify, read-only live check

**Files:** none new; fixes only where a gate points.

- [ ] **Step 1: Merge `develop`** (memory: merge develop regularly): `git fetch origin && git merge origin/develop`. Resolve conflicts, then re-run the touched tests.

- [ ] **Step 2: Run `/simplify`** over the branch diff (`git diff origin/develop...HEAD`). Apply its fixes, re-run the affected tests, and commit `refactor(#1974): simplify pass over the quoted link card`.

- [ ] **Step 3: Backend gates, both legs** (they can run at the same time):

```bash
cd backend && composer check && composer md && composer test:parallel
docker compose exec php composer test:parallel
cd backend && composer infection:diff
```

Expected: all green. `composer md` must be clean for every touched `src` file. If `infection:diff` leaves an escaped mutant on a touched line, write a test that kills it (likely spots: the `RECORD_WITH_MEDIA` arm of `linkCardOf`, the GIF check in `cardOfExternal`, the `?->toStored()` in `quoteFields`, the `array_fill(0, 10, …)` count). Don't lower `minMsi`.

- [ ] **Step 4: PhpStorm inspections** on the changed PHP files (`mcp__phpstorm__lint_files`). Block on ERROR and WARNING.

- [ ] **Step 5: Frontend gates**: `cd frontend && npm run check && npx ng build`, plus `docker compose exec -T frontend npm test`.

- [ ] **Step 6: Scan the dev log.** Run `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no new deprecations or errors.

- [ ] **Step 7: Read-only live check on `http://localhost:4200`.** Re-ingest is not automatic, and you must **never post** to check this.
  1. Confirm the Docker php and worker containers run this checkout's code (memory: worker daemon holds stale code, so restart the worker if needed). Confirm the Task 2 migration ran on the dev DB.
  2. Fixture proof: `QuotedViewRecordTest::testTheRealQuoteOfALinkPostCarriesItsCard` (a real Bluesky answer) and the `quote-video` fixture still pass.
  3. Live proof: wait for (or trigger through the app's normal refresh) a **new** timeline entry that quotes a link post, on Bluesky or Mastodon. In the magazine and in the reader, check that the card shows inside the quote box: image, title, description, host. Clicking the card opens the page and does not open the entry. The corner button opens the page in the reader. Measure the quoting block at 390px. If the block's real height and `quotingHeight` differ by more than about 20px, adjust only the card arm (introduce `QUOTING` card constants measured on the real render, rather than reusing `THREAD`'s). If no new quote of a link post appears, report that, and say the live check is pending. Don't fabricate one.

- [ ] **Step 8: Commit any gate fixes** as `fix(#1974): …`, then hand back to the planner (memory: the implementer reports to the planner). The PR body says `Closes #1974`. Nothing gets merged or deployed unasked.

---

## Self-Review

- **Spec coverage:** The card is stored on ingest for Bluesky (Task 1, Steps 1 and 7, both `external` and `recordWithMedia`) and Mastodon (Task 1, Steps 2 and 8). It is served in the quote JSON (Task 3) and included in backups, export and restore, with old files staying compatible (Task 2, Steps 9–14). It renders inside the quote (Task 4). Existing entries get no backfill (Global Constraints, Decision 7, Task 5 Step 7).
- **Names checked against the code:** `QuotedViewRecord::{of,mediaOf,nestedQuoteOf}`, the `EXTERNAL`/`RECORD_WITH_MEDIA` consts, `BlueskyExternalCard::of`, `BlueskyEmbedMedia::gif`, `MastodonCard::of`, `MastodonQuotedStatus::of`, `StorableLinkCard::of`, `StorableQuotedPost::of`, `LinkCardContent` (5 fields, `fromRow`), `EntryQuotedPost::{store,read}`, `StoredList`, `EntryBatchInserter::{COLUMNS,quoteColumns,encodeList}`, `BackupLines::{quoteFields,linkCardFields}`, `EntryLine::{quoteOf,linkCardsOf}`, `LineField::{objectOrNull,objectListOrEmpty,string,stringOrNull}`, `LinkCardJson::ofOrNull`, `JsonMappers::{linkCardJson,threadPostJson,readerToken}`, `Bluesky::post('record')` (its quoted view holds an untitled-description, thumbless `external#view` for motherjones.com), `aQuotedPost`, `aLinkCard`, `LinkCardComponent` inputs, `LinkCardReaderOpening`, `threadCardHeight`, `withGap`, `QUOTING.quoteMediaWidth`.
- **Types consistent:** `linkCard` is `?LinkCardContent` in PHP, `LinkCardRow|null` on the wire and `LinkCardDto | null` in TS. `toStored()` keys match the `linkCards` backup objects and `LinkCardJson::fieldsOf`.
