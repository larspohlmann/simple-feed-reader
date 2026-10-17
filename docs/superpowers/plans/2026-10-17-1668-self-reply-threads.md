# Self-reply threads in the list — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Bluesky or Mastodon RSS entry whose author continued it in their own replies carries up to three of those replies. The list draws them on a thread line inside the one entry card, with each reply's link card openable in the reader.

**Architecture:**
- Both networks share one queue kind, `ShareEnrichmentKind::SelfThread`.
  - Bluesky RSS posts reach it from the AppView pass when `replyCount > 0`.
  - Mastodon RSS posts are queued for it at ingest.
- The pass `Service/Social/SelfThreadEnricher` fetches the public thread through the existing `ReplyThreadSourceInterface` (`inPublic` + `repliesIn`, built for #1616). `SelfReplyChain` picks the author's chain, and `SelfThreadFolder` stores it as `ThreadPost` rows plus reply-owned `LinkCard`s.
- The API adds `thread` to `EntryJson` and a stored `position` to every entry card.
- The SPA draws the thread with a shared thread-line component in a new magazine block, the rows layout and the article view.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM (MySQL + SQLite), PHPUnit 12, Angular 20 standalone + signals, Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-17-self-reply-threads-design.md` (issue #1668). Read it first, especially "Open in reader" and "Alongside #1666 and #1667".

## Global Constraints

- The chain is the author's unbroken reply chain from the root: at each level, the oldest reply by the root's author. It holds **at most 3** replies.
- Only feeds read by URL (RSS). Never a feed whose source format is in `SourceFormat::SOCIAL` (`bluesky-api`, `mastodon-api`).
- A reply holds **at most one** link card, and that card belongs to the reply (`link_card.thread_post_id`). `entry.linkCards` stays the root's cards only.
- An entry's cards, root and replies together, share one `position` sequence, unique per entry. New cards always number from the entry's highest position + 1, counting cards still unflushed in the unit of work.
- Every "open in reader" sends the card's stored `position`, never a list index.
- Datetimes are normalised to UTC before persisting (CLAUDE.md gotcha).
- Migration `backend/migrations/Version20261017160000.php`, written for MySQL **and** SQLite (pattern: `Version20261016120000.php`), non-transactional, `skipIf` guarded. The 2026-10-17 12:00 and 13:00 timestamps belong to #1666.
- **Don't touch** `backend/src/Entity/Entry.php`, `backend/src/Service/Search/EntryIndexer.php`, `backend/src/Service/Backup/**` or `BlueskyTimelinePage`/`BlueskyTimelineSource`. #1666 and #1667 rewrite them in parallel sessions.
- Commit format `type(#1668): …`, with no Claude attribution of any kind.
- Clean Code rules of CLAUDE.md apply in full: `final readonly`, role folders, no boolean flag parameters, no comments that restate code, PHPMD-clean files, `Response::HTTP_*` for statuses.

## Pre-flight notes for the executor

- Work only in the worktree `/Users/lars/Documents/work/eigenes/simple-feed-reader-1668`, branch `feature/1668-self-reply-threads`. The main checkout belongs to #1666's session: don't `checkout`, `stash` or `reset` there.
- The worktree needs its own dependencies: `cd backend && composer install`, and `cd frontend && npm ci`. Use real installs: a symlinked `vendor` breaks `project_dir` (memory: worktree-vendor-symlink).
- The Docker stack serves the **main** checkout, so from this worktree:
  - Backend tests run natively on SQLite: `php bin/phpunit …`. The MySQL leg runs in CI.
  - Frontend gates run natively: `npm run check`. Run `npx jest <path>` for a single spec, with `npx tsc -p tsconfig.spec.json --noEmit` beside it, since native jest skips the typecheck.
  - The Playwright smoke and the live check on `?subscription=1847` wait for Task 13, when the stack can run this branch.
- `composer stan` needs a warm cache: `bin/console cache:warmup`.
- Merge `origin/develop` into the branch before Task 9 and before the PR (memory: merge develop regularly).

## File structure

| File | Responsibility |
|---|---|
| `backend/src/Entity/ThreadPost.php` (new) | One stored reply of an entry's self-thread |
| `backend/src/Entity/ThreadPostContent.php` (new) | What a `ThreadPost` is built from (url, publishedAt, html, media) |
| `backend/src/Repository/ThreadPostRepository.php` (new) | Thread posts by entry ids (with their card), reply URLs of one entry |
| `backend/src/Entity/LinkCard.php` (modify) | Nullable `threadPost`, getters for the position allocator |
| `backend/src/Repository/LinkCardRepository.php` (modify) | Root-only reads, `placedByEntryIds`, `append` and `appendToThreadPost` from the next free position |
| `backend/src/Service/LinkCard/Model/PlacedLinkCardModel.php` (new) | A stored card plus its position |
| `backend/migrations/Version20261017160000.php` (new) | `thread_post`, `link_card.thread_post_id` |
| `backend/src/Enum/ShareEnrichmentKind.php`, `src/Entity/ShareEnrichment.php`, `src/Repository/ShareEnrichmentRepository.php` (modify) | `SelfThread` kind, `awaitSelfThread()`, per-kind feed queries |
| `backend/src/Service/Social/Pass/ReplyNode.php` + its two builders (modify) | Nodes carry `url` and `byEntryAuthor` |
| `backend/src/Service/Social/SelfReplyChain.php` (new) | The chain rule |
| `backend/src/Service/Social/Factory/ThreadPostFactory.php` (new) | Builds a `ThreadPost` from a reply comment |
| `backend/src/Service/Social/SelfThreadFolder.php` (new) | Persists a chain onto an entry |
| `backend/src/Service/Social/SelfThreadEnricher.php` (new) | The queue pass |
| `backend/src/Service/Completion/SelfThreadPass/SelfThreadPassInterface.php` (new) | Lets `EntryCompletion` run the pass without importing `Social` |
| `backend/src/Service/Share/PendingThread/PendingThreadInterface.php` (new), `backend/src/Service/Social/MastodonPendingThread.php` (new) | Which new entries queue `SelfThread` at ingest |
| `backend/src/Service/Bluesky/PostEnricher.php` (modify) | Hands posts with replies on to `SelfThread` |
| `backend/src/Service/Completion/ShareEnrichmentQueue.php`, `EntryCompletion.php`, `backend/src/Service/Refresh/FeedOutcomePersister.php` (modify) | Wiring |
| `backend/src/Service/Social/SocialRepliesSource.php`, `backend/src/Service/Social/Support/FoldedReplies.php` (new) | The replies view skips folded replies |
| `backend/src/Service/Social/Model/ThreadPostModel.php` (new), `backend/src/Http/ThreadPostJson.php` (new), `backend/src/Http/LinkCardJson.php`, `backend/src/Http/EntryJson.php`, `backend/src/Repository/EntryListRowEnrich{er,ment}.php` (modify) | API |
| `frontend/src/app/reader/link-card/link-card.ts`, `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/link-card/link-card-at.ts` (new) | DTOs, card lookup by position |
| `frontend/src/app/shared/thread-line/*` (new) | `app-thread-line`, `app-thread-line-post`, `app-thread-count` |
| `frontend/src/app/reader/list/magazine/blocks/entry-thread/*` (new) + `magazine-block.ts`, `magazine-block.component.*`, `magazine-slot-fit.ts`, `magazine-block-height.ts` | List block |
| `frontend/src/app/reader/list/entry-row/*` (modify) | Rows layout |
| `frontend/src/app/reader/article/thread-replies/*` (new), `frontend/src/app/reader/article/reader-view/reader-view.component.html` (modify) | Article view |
| `frontend/e2e/self-reply-thread.spec.ts` (new) | Smoke with stubbed routes |
| `docs/design-language.md`, `docs/backup.md` (modify) | Catalog entry, backup decision |

---

### Task 1: Store a thread — `ThreadPost`, reply-owned cards, one position sequence

**Files:**
- Create: `backend/src/Entity/ThreadPost.php`, `backend/src/Entity/ThreadPostContent.php`, `backend/src/Repository/ThreadPostRepository.php`, `backend/src/Service/LinkCard/Model/PlacedLinkCardModel.php`, `backend/src/Service/Social/Model/ThreadPostModel.php`, `backend/migrations/Version20261017160000.php`
- Modify: `backend/src/Entity/LinkCard.php`, `backend/src/Repository/LinkCardRepository.php`, `backend/src/Service/LinkCard/EntryCardWriter.php:28`, `backend/src/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php:46`, `backend/tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `docs/backup.md` (§6.1, §6.3)
- Test: `backend/tests/Repository/LinkCardRepositoryTest.php` (update + append), `backend/tests/Repository/ThreadPostRepositoryTest.php` (new)

**Interfaces — Produces:**
- `new ThreadPost(Entry $entry, int $position, ThreadPostContent $content)`; `ThreadPost::getEntry(): Entry`
- `new ThreadPostContent(?string $url, ?\DateTimeImmutable $publishedAt, string $html, array $media /* list<EntryMedium> */)`
- `LinkCardRepository::append(Entry $entry, array $cards): void` (the `$stored` parameter is gone)
- `LinkCardRepository::appendToThreadPost(ThreadPost $post, LinkCardContent $card): void`
- `LinkCardRepository::placedByEntryIds(array $entryIds): array<int, list<PlacedLinkCardModel>>` (root cards only)
- `LinkCardRepository::contentsByEntryIds()` now returns **root cards only**. Its callers (backup, ShareLinkCards, tests) keep working unchanged.
- `new PlacedLinkCardModel(int $position, LinkCardContent $content)`
- `ThreadPostRepository::byEntryIds(array $entryIds): array<int, list<ThreadPostModel>>` in position order
- `ThreadPostRepository::urlsOf(Entry $entry): list<string>`
- `new ThreadPostModel(?string $url, ?\DateTimeImmutable $publishedAt, string $html, array $media, ?PlacedLinkCardModel $linkCard)`

- [ ] **Step 1: Write the failing repository tests**

Read `tests/Repository/LinkCardRepositoryTest.php` first and keep its helpers (`repository()`, `content()`, the entry setup). Replace every `->append($x, [], [...])` with `->append($x, [...])`. Rewrite the test at line ~133 that passes a stored list so that it appends twice and asserts positions `0, 1`. Then append:

```php
    public function testAThreadCardTakesTheNextPositionAndStaysOutOfTheRootCards(): void
    {
        $cards = $this->repository();
        $entry = $this->entry();
        $cards->append($entry, [$this->content('root')]);
        $post = new ThreadPost($entry, 1, new ThreadPostContent('https://bsky.app/profile/a/post/r1', null, '<p>r</p>', []));
        $this->entityManager->persist($post);
        $cards->appendToThreadPost($post, $this->content('reply'));
        $this->entityManager->flush();

        self::assertSame(['https://example.com/root'], array_map(
            static fn (LinkCardContent $card): string => $card->url,
            $cards->contentsByEntryIds([$entry->requireId()])[$entry->requireId()],
        ));
        self::assertSame(
            'https://example.com/reply',
            $cards->contentAt($this->subscribedUserId($entry), $entry->requireId(), 1)->url,
        );
    }

    public function testRootCardsAppendedAfterAThreadCardDoNotCollide(): void
    {
        $cards = $this->repository();
        $entry = $this->entry();
        $post = new ThreadPost($entry, 1, new ThreadPostContent(null, null, '<p>r</p>', []));
        $this->entityManager->persist($post);
        $cards->appendToThreadPost($post, $this->content('reply'));
        $this->entityManager->flush();

        $cards->append($entry, [$this->content('a'), $this->content('b')]);
        $this->entityManager->flush();

        self::assertSame(
            [1, 2],
            array_map(
                static fn (PlacedLinkCardModel $card): int => $card->position,
                $cards->placedByEntryIds([$entry->requireId()])[$entry->requireId()],
            ),
        );
    }

    public function testUnflushedCardsCountWhenNumberingTheNext(): void
    {
        $cards = $this->repository();
        $entry = $this->entry();
        $cards->append($entry, [$this->content('a')]);
        $cards->append($entry, [$this->content('b')]);
        $this->entityManager->flush();

        self::assertSame([0, 1], array_map(
            static fn (PlacedLinkCardModel $card): int => $card->position,
            $cards->placedByEntryIds([$entry->requireId()])[$entry->requireId()],
        ));
    }
```

`subscribedUserId()` stands for however this file's existing `contentAt` tests subscribe a user (search the file for `contentAt`). Reuse that setup. If `content('x')` builds another URL shape than `https://example.com/x`, use that shape in the assertions.

Create `tests/Repository/ThreadPostRepositoryTest.php` (a `DbTestCase`, entry setup as in `LinkCardRepositoryTest`):

```php
    public function testThreadPostsComeInPositionOrderWithTheirCard(): void
    {
        $entry = $this->entry();
        $second = new ThreadPost($entry, 2, new ThreadPostContent('https://x/2', null, '<p>two</p>', []));
        $first = new ThreadPost($entry, 1, new ThreadPostContent('https://x/1', new \DateTimeImmutable('2026-10-17 08:15:31'), '<p>one</p>', []));
        $this->entityManager->persist($second);
        $this->entityManager->persist($first);
        $this->linkCards()->appendToThreadPost($second, new LinkCardContent('https://macrumors.example/a', 'New rules', null, null, 'macrumors.example'));
        $this->entityManager->flush();

        $posts = $this->threadPosts()->byEntryIds([$entry->requireId()])[$entry->requireId()];

        self::assertSame(['https://x/1', 'https://x/2'], array_map(static fn (ThreadPostModel $post): ?string => $post->url, $posts));
        self::assertNull($posts[0]->linkCard);
        self::assertSame(0, $posts[1]->linkCard?->position);
        self::assertSame('https://macrumors.example/a', $posts[1]->linkCard?->content->url);
        self::assertSame(['https://x/1', 'https://x/2'], $this->threadPosts()->urlsOf($entry));
    }

    public function testAnEntryWithoutThreadIsAbsent(): void
    {
        $entry = $this->entry();
        $this->entityManager->flush();

        self::assertSame([], $this->threadPosts()->byEntryIds([$entry->requireId()]));
        self::assertSame([], $this->threadPosts()->byEntryIds([]));
    }
```

- [ ] **Step 2: Run them, expect red**

Run: `cd backend && php bin/phpunit tests/Repository/LinkCardRepositoryTest.php tests/Repository/ThreadPostRepositoryTest.php`
Expected: errors, because `ThreadPost` is unknown and `append()` has the wrong arity.

- [ ] **Step 3: Implement**

`backend/src/Entity/ThreadPostContent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class ThreadPostContent
{
    /** @param list<EntryMedium> $media */
    public function __construct(
        public ?string $url,
        public ?\DateTimeImmutable $publishedAt,
        public string $html,
        public array $media,
    ) {
    }
}
```

`backend/src/Entity/ThreadPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreadPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A reply in which the entry's author continued the post (#1668). */
#[ORM\Entity(repositoryClass: ThreadPostRepository::class)]
#[ORM\Table(name: 'thread_post')]
#[ORM\UniqueConstraint(name: 'uniq_thread_post_entry_position', columns: ['entry_id', 'position'])]
final class ThreadPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'content_html', type: Types::TEXT)]
    private string $contentHtml;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $media;

    public function __construct(Entry $entry, int $position, ThreadPostContent $content)
    {
        $this->entry = $entry;
        $this->position = $position;
        $this->url = $content->url;
        $this->publishedAt = $content->publishedAt;
        $this->contentHtml = $content->html;
        $this->media = StoredList::orNull(EntryMedia::toJsonList($content->media));
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }
}
```

`LinkCard.php`: add after `$entry`:

```php
    #[ORM\ManyToOne(targetEntity: ThreadPost::class)]
    #[ORM\JoinColumn(name: 'thread_post_id', nullable: true, onDelete: 'CASCADE')]
    private ?ThreadPost $threadPost = null;
```

Add the constructor parameter `?ThreadPost $threadPost = null` last, assigned to the field. Add the getters `getEntry(): Entry` and `getPosition(): int`.

`PlacedLinkCardModel` (`App\Service\LinkCard\Model`):

```php
final readonly class PlacedLinkCardModel
{
    public function __construct(public int $position, public LinkCardContent $content)
    {
    }
}
```

`ThreadPostModel` (`App\Service\Social\Model`), fields as in Interfaces, `@param list<EntryMedium> $media`.

`LinkCardRepository`:

```php
    /** @param list<LinkCardContent> $cards in post order */
    public function append(Entry $entry, array $cards): void
    {
        $next = $this->nextPositionFor($entry);
        foreach ($cards as $index => $content) {
            $this->getEntityManager()->persist(new LinkCard($entry, $next + $index, $content));
        }
    }

    public function appendToThreadPost(ThreadPost $post, LinkCardContent $card): void
    {
        $entry = $post->getEntry();
        $this->getEntityManager()->persist(new LinkCard($entry, $this->nextPositionFor($entry), $card, $post));
    }

    /** An entry's root and reply cards share one numbering, cards still waiting for the flush included. */
    private function nextPositionFor(Entry $entry): int
    {
        $highest = -1;
        foreach ($this->getEntityManager()->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof LinkCard && $scheduled->getEntry() === $entry) {
                $highest = max($highest, $scheduled->getPosition());
            }
        }
        if ($entry->getId() === null) {
            return $highest + 1;
        }
        $stored = $this->createQueryBuilder('card')
            ->select('MAX(card.position)')
            ->andWhere('card.entry = :entry')
            ->setParameter('entry', $entry)
            ->getQuery()
            ->getSingleScalarResult();

        return max($highest, $stored === null ? -1 : (int) $stored) + 1;
    }
```

In `contentsByEntryIds()` add `->andWhere('card.threadPost IS NULL')`. Add `placedByEntryIds()`: the same query plus `'card.position'` in the select, returning `PlacedLinkCardModel`s. Factor the shared query-builder part into a private `rootCardRows(array $entryIds)` so the two methods don't duplicate it. `contentAt()` stays unfiltered: a reply's card opens too.

Update the two production callers: `EntryCardWriter::write` calls `->append($entry, $cards->newCards())`, and `EntryEmbedWriter::fill` calls `->append($entry, $embed->linkCards)`. If `EntryCardsModel::$stored` loses its last reader, leave it: `ShareLinkCards` still uses it for the carded-URL check.

`ThreadPostRepository` (`final`, `ServiceEntityRepository<ThreadPost>`):

```php
    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<ThreadPostModel>> by entry id, in position order
     */
    public function byEntryIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        /** @var list<array{id: int|string, entryId: int|string, url: ?string, publishedAt: ?\DateTimeImmutable, contentHtml: string, media: ?list<array<string, mixed>>}> $rows */
        $rows = $this->createQueryBuilder('post')
            ->select('post.id', 'IDENTITY(post.entry) AS entryId', 'post.url', 'post.publishedAt', 'post.contentHtml', 'post.media')
            ->andWhere('post.entry IN (:entryIds)')
            ->setParameter('entryIds', $entryIds)
            ->orderBy('post.position', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $cards = $this->cardsByPostId(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['entryId']][] = new ThreadPostModel(
                $row['url'],
                $row['publishedAt'],
                $row['contentHtml'],
                StoredList::read($row['media'], EntryMedium::isComplete(...), EntryMedium::fromStored(...)),
                $cards[(int) $row['id']] ?? null,
            );
        }

        return $grouped;
    }
```

`cardsByPostId(list<int>): array<int, PlacedLinkCardModel>` queries `LinkCard::class` by `card.threadPost IN (:ids)`, selecting `IDENTITY(card.threadPost) AS postId`, the position and the content columns. `urlsOf(Entry)` returns the non-null `post.url`s of that entry, in position order. Both are queries, so they live here.

Migration `Version20261017160000` (description: `Create thread_post and link_card.thread_post_id for self-reply threads (#1668)`; `skipIf($schema->hasTable('thread_post'), …)`). Generate the MySQL DDL with `docker compose exec php bin/console doctrine:schema:update --dump-sql` after the entity exists. That command runs against the main checkout's code, so copy the two entity files there only as a temporary scratch read if needed, or write the DDL by hand:

MySQL:
```sql
CREATE TABLE thread_post (id INT AUTO_INCREMENT NOT NULL, entry_id INT NOT NULL, position SMALLINT NOT NULL, url VARCHAR(2048) DEFAULT NULL, published_at DATETIME DEFAULT NULL, content_html LONGTEXT NOT NULL, media JSON DEFAULT NULL, INDEX IDX_THREAD_POST_ENTRY (entry_id), UNIQUE INDEX uniq_thread_post_entry_position (entry_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE thread_post ADD CONSTRAINT FK_THREAD_POST_ENTRY FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE;
ALTER TABLE link_card ADD thread_post_id INT DEFAULT NULL;
ALTER TABLE link_card ADD CONSTRAINT FK_LINK_CARD_THREAD_POST FOREIGN KEY (thread_post_id) REFERENCES thread_post (id) ON DELETE CASCADE;
CREATE INDEX IDX_LINK_CARD_THREAD_POST ON link_card (thread_post_id);
```
SQLite:
```sql
CREATE TABLE thread_post (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, entry_id INTEGER NOT NULL, position SMALLINT NOT NULL, url VARCHAR(2048) DEFAULT NULL, published_at DATETIME DEFAULT NULL, content_html CLOB NOT NULL, media CLOB DEFAULT NULL, CONSTRAINT FK_THREAD_POST_ENTRY FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE);
CREATE INDEX IDX_THREAD_POST_ENTRY ON thread_post (entry_id);
CREATE UNIQUE INDEX uniq_thread_post_entry_position ON thread_post (entry_id, position);
ALTER TABLE link_card ADD COLUMN thread_post_id INTEGER DEFAULT NULL REFERENCES thread_post (id) ON DELETE CASCADE;
CREATE INDEX IDX_LINK_CARD_THREAD_POST ON link_card (thread_post_id);
```
Doctrine names its generated indexes and FKs with hashes, and `doctrine:schema:validate` compares column definitions, not those names. If validate reports a difference, adopt the names `--dump-sql` prints, in both branches. `down()` drops the FK, index and column from `link_card`, then the `thread_post` table (SQLite: `link_card` keeps the column, guarded with a `skipIf` message, as other migrations in `migrations/` do for SQLite column drops; search them for `DROP COLUMN` to follow precedent).

Backup coverage: run `php bin/phpunit tests/Service/Backup/Support/BackupSchemaCoverageTest.php`. It fails naming `ThreadPost` and `LinkCard::threadPost`. Add:
- `ThreadPost::class => 'A post\'s self-reply continuation, read from the network at ingest (#1668); a restored entry shows its root post alone.'` to `INSTANCE_SCOPED`.
- `'threadPost' => 'A reply\'s card stays out of the file with its reply (#1668).'` to `LinkCard`'s dropped-fields entry. Find how other entities' dropped fields are declared in the same test, and follow that.
- One row in `docs/backup.md` §6.1 for `ThreadPost`, and one in §6.3 for `LinkCard.threadPost`, in the tables' existing style.

- [ ] **Step 4: Run the tests, expect green**

Run: `php bin/phpunit tests/Repository tests/Service/LinkCard tests/Service/Bluesky tests/Service/Backup/Support/BackupSchemaCoverageTest.php`
Expected: PASS.

Verify the migration from empty on SQLite:
```bash
rm -f var/migration-check.db
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:schema:validate
rm -f var/migration-check.db
```
Expected: `[OK] The database schema is in sync with the mapping files.` CI's migration leg covers MySQL.

- [ ] **Step 5: Gates and commit**

Run: `composer cs && composer stan && composer md` on the touched files, all clean.

```bash
git add backend/src/Entity/ThreadPost.php backend/src/Entity/ThreadPostContent.php backend/src/Entity/LinkCard.php backend/src/Repository/ThreadPostRepository.php backend/src/Repository/LinkCardRepository.php backend/src/Service/LinkCard backend/src/Service/Social/Model/ThreadPostModel.php backend/src/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php backend/migrations/Version20261017160000.php backend/tests docs/backup.md
git commit -m "feat(#1668): store self-reply thread posts with their own link cards"
```

---

### Task 2: The `SelfThread` queue kind and per-kind feed queries

**Files:**
- Modify: `backend/src/Enum/ShareEnrichmentKind.php`, `backend/src/Entity/ShareEnrichment.php`, `backend/src/Repository/ShareEnrichmentRepository.php:24-67`, `backend/src/Service/Bluesky/PostEnricher.php` (call sites only)
- Test: `backend/tests/Repository/ShareEnrichmentRepositoryTest.php` (exists? `ls` it; otherwise append to the test that covers `findClaimableAppViewForFeed`: `grep -rln findClaimableAppViewForFeed backend/tests`)

**Interfaces — Produces:**
- `ShareEnrichmentKind::SelfThread` (value `'self_thread'`, fits `length: 16`)
- `ShareEnrichment::awaitSelfThread(): void`
- `ShareEnrichmentRepository::deleteQueuedBefore(Feed $feed, ShareEnrichmentKind $kind, \DateTimeImmutable $cutoff): void`
- `ShareEnrichmentRepository::hasPendingForFeed(Feed $feed, ShareEnrichmentKind $kind): bool`
- `ShareEnrichmentRepository::findClaimableForFeed(Feed $feed, ShareEnrichmentKind $kind, \DateTimeImmutable $now, int $limit): list<ShareEnrichment>`

These replace the three `…AppView…` methods, which are renamed rather than duplicated (DRY). `PostEnricher` passes `ShareEnrichmentKind::AppView`.

- [ ] **Step 1: Failing test.** In the repository test, add a case: a feed holding one `AppView` row and one `SelfThread` row. `findClaimableForFeed($feed, SelfThread, …)` returns only the self-thread row, and `hasPendingForFeed($feed, SelfThread)` is true. After `deleteQueuedBefore($feed, SelfThread, <after both>)`, the AppView row survives. Rename the existing AppView cases to the new method names.
- [ ] **Step 2:** `php bin/phpunit <that test>`. Expected: FAIL, unknown method or case.
- [ ] **Step 3: Implement.** Add the enum case. `awaitSelfThread()` mirrors `awaitLinkCards()`. In the three repository methods, replace the hardcoded `ShareEnrichmentKind::AppView` parameter with `$kind`. Update `PostEnricher::enrichQueued` and its tests (`grep -rn "AppViewQueuedBefore\|AppViewPendingForFeed\|ClaimableAppViewForFeed" backend`).
- [ ] **Step 4:** `php bin/phpunit tests/Repository tests/Service/Bluesky tests/Service/Completion`. Expected: PASS.
- [ ] **Step 5:** `git commit -m "refactor(#1668): per-kind feed queue queries and a SelfThread kind"`

---

### Task 3: Reply nodes carry their URL and authorship; the chain rule

**Files:**
- Modify: `backend/src/Service/Social/Pass/ReplyNode.php`, `backend/src/Service/Social/BlueskyThreadReplies.php` (`nodesOf`), `backend/src/Service/Social/MastodonContextReplies.php` (`nodesOf`)
- Create: `backend/src/Service/Social/SelfReplyChain.php`
- Test: `backend/tests/Service/Social/SelfReplyChainTest.php` (new); add one assertion each to `BlueskyThreadRepliesTest.php` and `MastodonContextRepliesTest.php`

**Interfaces — Produces:**
- `new ReplyNode(?\DateTimeImmutable $publishedAt, \Closure $commentOf, array $replies = [], ?string $url = null, bool $byEntryAuthor = false)`. These are data fields of a node, not behaviour flags, so the no-boolean-parameter rule doesn't apply. Construct with named arguments.
- `SelfReplyChain::of(array $replies /* list<ReplyNode> */): list<EntryCommentModel>`, a stateless `final readonly` service with `public const int MAX_REPLIES = 3`

Knowing the author and URL on the node itself is what keeps the chain cheap. `comment()` sanitizes HTML, and a popular post has hundreds of top-level replies, so the chain must never build a comment just to test its author.

- [ ] **Step 1: Failing tests.** `SelfReplyChainTest` (plain `TestCase`):

```php
    public function testFollowsTheAuthorsOldestReplyAtEachLevel(): void
    {
        $chain = (new SelfReplyChain())->of([
            self::node('stranger', '08:15:40', false),
            self::node('second-by-author', '08:15:50', true),
            self::node('first-by-author', '08:15:32', true, [
                self::node('stranger-below', '08:15:33', false),
                self::node('third', '08:15:34', true, [self::node('fourth', '08:15:35', true, [self::node('fifth', '08:15:36', true)])]),
            ]),
        ]);

        self::assertSame(['first-by-author', 'third', 'fourth'], array_map(static fn (EntryCommentModel $reply): string => $reply->html, $chain));
    }

    public function testStopsWhereNoReplyIsTheAuthors(): void
    {
        $chain = (new SelfReplyChain())->of([self::node('first', '08:00:00', true, [self::node('stranger', '08:01:00', false)])]);

        self::assertCount(1, $chain);
    }

    public function testNoAuthorReplyMeansNoChain(): void
    {
        self::assertSame([], (new SelfReplyChain())->of([self::node('stranger', '08:00:00', false)]));
    }

    public function testAReplyWithoutADateComesAfterDatedOnes(): void
    {
        $chain = (new SelfReplyChain())->of([self::node('undated', null, true), self::node('dated', '09:00:00', true)]);

        self::assertSame('dated', $chain[0]->html);
    }

    /** @param list<ReplyNode> $replies */
    private static function node(string $html, ?string $time, bool $byAuthor, array $replies = []): ReplyNode
    {
        $publishedAt = $time === null ? null : new \DateTimeImmutable('2026-10-17 ' . $time);

        return new ReplyNode(
            $publishedAt,
            static fn (?\DateTimeImmutable $at): EntryCommentModel => new EntryCommentModel(
                new CommentAuthorModel('A', null, null, null),
                'https://x/' . $html,
                $at,
                $html,
                $byAuthor,
            ),
            $replies,
            url: 'https://x/' . $html,
            byEntryAuthor: $byAuthor,
        );
    }
```

(Check `CommentAuthorModel`'s constructor arity in `src/Service/Comments/Model/CommentAuthorModel.php` and match it.) In `BlueskyThreadRepliesTest`, assert that the op's reply node (`did:plc:op/post/r1a` in its fixture) has `byEntryAuthor === true` and `url === self::WEB . 'did:plc:op/post/r1a'`, and that a stranger's node has `false`. Make the matching assertion in `MastodonContextRepliesTest`, for its fixture's author reply.

- [ ] **Step 2:** `php bin/phpunit tests/Service/Social/SelfReplyChainTest.php tests/Service/Social/BlueskyThreadRepliesTest.php tests/Service/Social/MastodonContextRepliesTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement.** `ReplyNode`: add the two promoted public properties, last, with the defaults shown. `BlueskyThreadReplies::nodesOf` already has `$post` and `$entryAuthor`. Pass `url: AtPostUri::webUrl($post->string('uri') ?? '')` and `byEntryAuthor:` the same test `commentOf` uses. Extract that test into a private static `isByEntryAuthor(JsonNodeModel $author, ?string $entryAuthor): bool` and call it from both places (DRY). `MastodonContextReplies::nodesOf` passes `url:` with the same expression `commentOf` uses (extract a private static `urlOf(JsonNodeModel $status): ?string`) and `byEntryAuthor: $thread->isByPostAuthor($status->node('account'))`.

`SelfReplyChain`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Pass\ReplyNode;

/** The author's unbroken chain of replies under their own post (#1668). */
final readonly class SelfReplyChain
{
    public const int MAX_REPLIES = 3;

    /**
     * @param list<ReplyNode> $replies the post's direct replies
     *
     * @return list<EntryCommentModel>
     */
    public function of(array $replies): array
    {
        $chain = [];
        $level = $replies;
        while (\count($chain) < self::MAX_REPLIES) {
            $next = self::oldestByAuthor($level);
            if ($next === null) {
                break;
            }
            $chain[] = $next->comment();
            $level = $next->replies;
        }

        return $chain;
    }

    /** @param list<ReplyNode> $nodes */
    private static function oldestByAuthor(array $nodes): ?ReplyNode
    {
        $own = array_values(array_filter($nodes, static fn (ReplyNode $node): bool => $node->byEntryAuthor));
        usort($own, static fn (ReplyNode $left, ReplyNode $right): int => self::sortKey($left) <=> self::sortKey($right));

        return $own[0] ?? null;
    }

    /** @return array{bool, ?\DateTimeImmutable} an undated reply sorts after every dated one */
    private static function sortKey(ReplyNode $node): array
    {
        return [$node->publishedAt === null, $node->publishedAt];
    }
}
```

- [ ] **Step 4:** Rerun Step 2's command and `php bin/phpunit tests/Service/Social`. Expected: PASS.
- [ ] **Step 5:** `git commit -m "feat(#1668): the author's self-reply chain from a reply tree"`

---

### Task 4: Fold a chain onto an entry — `ThreadPostFactory` and `SelfThreadFolder`

**Files:**
- Create: `backend/src/Service/Social/Factory/ThreadPostFactory.php`, `backend/src/Service/Social/SelfThreadFolder.php`
- Test: `backend/tests/Service/Social/SelfThreadFolderTest.php` (new, `DbTestCase`)

**Interfaces:**
- Consumes: Task 1 (`ThreadPost`, `ThreadPostContent`, `LinkCardRepository::appendToThreadPost`, `ThreadPostRepository::byEntryIds`), Task 3 (`EntryCommentModel` list)
- Produces: `ThreadPostFactory::build(Entry $entry, int $position, EntryCommentModel $reply): ThreadPost`; `SelfThreadFolder::fold(Entry $entry, array $chain /* list<EntryCommentModel> */): void` (persists, does not flush)

- [ ] **Step 1: Failing test.**

```php
    public function testEachReplyBecomesAThreadPostWithItsCard(): void
    {
        $entry = $this->entry();
        $this->folder()->fold($entry, [
            self::reply('<p>The rule extends…</p>', null),
            self::reply('', new LinkCardContent('https://www.macrumors.com/2026/10/16/app-store-rules-deepfakes/', 'New App Store Rules', null, null, 'www.macrumors.com')),
        ]);
        $this->entityManager->flush();

        $posts = $this->threadPosts()->byEntryIds([$entry->requireId()])[$entry->requireId()];
        self::assertSame(['<p>The rule extends…</p>', ''], array_map(static fn (ThreadPostModel $post): string => $post->html, $posts));
        self::assertSame('https://www.macrumors.com/2026/10/16/app-store-rules-deepfakes/', $posts[1]->linkCard?->content->url);
        self::assertSame(ShareKind::Link, $entry->getShareKind());
    }

    public function testAnEntryThatSharesSomethingKeepsItsKind(): void
    {
        $entry = $this->entry();
        $entry->setShareKind(ShareKind::Media);
        $this->folder()->fold($entry, [self::reply('', new LinkCardContent('https://a.example/', 'A', null, null, 'a.example'))]);

        self::assertSame(ShareKind::Media, $entry->getShareKind());
    }

    public function testATextOnlyChainLeavesTheKindAlone(): void
    {
        $entry = $this->entry();
        $this->folder()->fold($entry, [self::reply('<p>more</p>', null)]);

        self::assertNull($entry->getShareKind());
    }

    public function testThePublishedTimeIsStoredInUtc(): void
    {
        $entry = $this->entry();
        $this->folder()->fold($entry, [self::reply('<p>x</p>', null, new \DateTimeImmutable('2026-10-17T10:15:31+02:00'))]);
        $this->entityManager->flush();

        self::assertSame('2026-10-17 08:15:31', $this->threadPosts()->byEntryIds([$entry->requireId()])[$entry->requireId()][0]->publishedAt?->format('Y-m-d H:i:s'));
    }
```

`reply(string $html, ?LinkCardContent $card, ?\DateTimeImmutable $at = null)` builds an `EntryCommentModel` with `byEntryAuthor: true`, `url: 'https://bsky.app/profile/a/post/' . md5($html)` and `new CommentEmbedsModel([], $card)`. `folder()` builds the real services from the container (`self::getContainer()->get(SelfThreadFolder::class)`). If the service is private, construct it with the repositories, as other DB tests in `tests/Service/Social` do.

- [ ] **Step 2:** `php bin/phpunit tests/Service/Social/SelfThreadFolderTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement.**

```php
final readonly class ThreadPostFactory
{
    public function build(Entry $entry, int $position, EntryCommentModel $reply): ThreadPost
    {
        return new ThreadPost($entry, $position, new ThreadPostContent(
            $reply->url,
            $reply->publishedAt?->setTimezone(new \DateTimeZone('UTC')),
            $reply->html,
            $reply->embeds->media,
        ));
    }
}
```

```php
final readonly class SelfThreadFolder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ThreadPostFactory $threadPostFactory,
        private LinkCardRepository $linkCards,
    ) {
    }

    /** @param list<EntryCommentModel> $chain */
    public function fold(Entry $entry, array $chain): void
    {
        foreach ($chain as $index => $reply) {
            $this->foldOne($entry, $index + 1, $reply);
        }
        if ($entry->getShareKind() === null && self::sharesALink($chain)) {
            $entry->setShareKind(ShareKind::Link);
        }
    }

    private function foldOne(Entry $entry, int $position, EntryCommentModel $reply): void
    {
        $post = $this->threadPostFactory->build($entry, $position, $reply);
        $this->entityManager->persist($post);
        $card = $reply->embeds->linkCard;
        if ($card !== null) {
            $this->linkCards->appendToThreadPost($post, $card);
        }
    }

    /** @param list<EntryCommentModel> $chain */
    private static function sharesALink(array $chain): bool
    {
        return array_any($chain, static fn (EntryCommentModel $reply): bool => $reply->embeds->linkCard !== null);
    }
}
```

Check the setter's name in `Entry` (`grep -n "function setShareKind" backend/src/Entity/Entry.php`).

- [ ] **Step 4:** Rerun. Expected: PASS. Then `composer stan`, `composer md`.
- [ ] **Step 5:** `git commit -m "feat(#1668): fold an author's reply chain onto its entry"`

---

### Task 5: The pass — `SelfThreadEnricher`, run after a refresh and on completion

**Files:**
- Create: `backend/src/Service/Social/SelfThreadEnricher.php`, `backend/src/Service/Completion/SelfThreadPass/SelfThreadPassInterface.php`
- Modify: `backend/src/Service/Refresh/FeedOutcomePersister.php:131,150`, `backend/src/Service/Completion/EntryCompletion.php`
- Test: `backend/tests/Service/Social/SelfThreadEnricherTest.php` (new, `DbTestCase`), plus updated constructors in the tests that build `EntryCompletion`/`FeedOutcomePersister` by hand (`grep -rln "new EntryCompletion\|new FeedOutcomePersister" backend/tests`)

**Interfaces:**
- Consumes: Tasks 1–4; `ReplyThreadSourceInterface` via the locator `app.social_reply_thread_source` (keys `bluesky`, `mastodon`); `SocialPostAddress::of(Entry)`
- Produces:
  - `interface SelfThreadPassInterface { public function complete(array $queued): void; }`, where `$queued` is `list<ShareEnrichment>` of kind `SelfThread`
  - `SelfThreadEnricher implements SelfThreadPassInterface`, with `enrich(Feed $feed): void` and `complete(array $queued): void`

Behaviour:
- `enrich($feed)` returns early when `!hasPendingForFeed($feed, SelfThread)`. It drops rows queued before `now − ShareEnrichment::APPVIEW_GIVE_UP_AFTER`, then claims up to 25 with `findClaimableForFeed`.
- For each claimed row still held (`stillHeld`):
  - The entry's `SocialPostAddress` is null: delete the row.
  - `publicThreadThrottledForSeconds() > 0`: leave the row unsettled (released).
  - Otherwise ask `inPublic($address)`, then `repliesIn($address, $answer->body)`, then `SelfReplyChain::of`.
    - `SocialNetworkThrottledException`: unsettled.
    - `SocialPostNotFoundException`, `SocialNetworkUnreachableException` or `CommentsUnavailableException`: log a warning and fold an empty chain.
  - Record `$rootSharesLink = $entry->getShareKind() === ShareKind::Link` **before** folding, then fold.
  - Hand on: if the root shares a link, `$row->awaitLinkCards()`, otherwise remove the row.
- After the loop: flush, then `release(unsettled + rows now of kind LinkCards, $claimedAt)`, as `PostEnricher::claimAndSettle` does. No re-indexing: the fold changes none of the fields `EntryIndexer` reads (title, summary, content, feed, date), and thread text is not indexed (spec, Search).
- Guard the whole pass as `PostEnricher::guarded` does: rethrow only when the EntityManager closed.

Test cases (stub the network with `FakeReplyThreadSource` from `tests/Support/`, put into a `ServiceLocator(['bluesky' => fn () => $fake, 'mastodon' => fn () => $fake])` as `SocialRepliesSourceTest` does, and seed entries/rows as `PostEnricherTest::queuedEntry` does, with kind `SelfThread` and a feed whose `classifyAs(SocialNetwork::Bluesky)` is set and an AT-URI guid):

1. `testFoldsTheChainAndDequeues`: the fake `readsReplies([...two author nodes, the second carrying a card...])`. After `enrich($feed)`, `byEntryIds` holds 2 posts, the share enrichment row is gone, and `$fake->calls` contains `inPublic at://…`.
2. `testARootThatSharesALinkMovesOnToLinkCards`: the entry has `ShareKind::Link`, so after the pass its row's kind is `LinkCards` and it is released (`claimedAt` null; read it back with `ReloadsEntities`).
3. `testAThrottledNetworkLeavesTheRowQueued`: `throttlePublicThreadFor(30)`, so no `inPublic` call is made and the row stays `SelfThread`.
4. `testAnUnreachableThreadFoldsNothingAndDequeues`: `failInPublicWith(new SocialNetworkUnreachableException('down'))`, so there are no posts, the row is gone, and the logger holds one warning.
5. `testRowsPastTheGiveUpWindowAreDropped`: a row queued 4 days ago is deleted without a call.

- [ ] **Step 1:** Write the five tests. **Step 2:** run, expect FAIL (class missing).
- [ ] **Step 3: Implement** `SelfThreadEnricher`, with constructor collaborators `EntityManagerInterface`, `ShareEnrichmentRepository`, `#[AutowireLocator('app.social_reply_thread_source', defaultIndexMethod: 'network')] ContainerInterface $threads`, `SelfReplyChain`, `SelfThreadFolder`, `NaiveUtcClock` and `LoggerInterface`. Keep each method one level of abstraction, e.g. `enrich → claimAndSettle → settle(row) → chainOf(entry, thread)`. A per-call `Pass/SelfThreadRound` holding `$claimedAt`, the unsettled rows and the handed-on rows is the house answer if PHPMD or phptramp flag the parameter threading.
- [ ] **Step 4: Wire it.**
  - `FeedOutcomePersister`: after both `$this->postEnricher->enrich($feed);` lines, add `$this->selfThreads->enrich($feed);`. The constructor parameter is `private SelfThreadEnricher $selfThreads`, and Refresh → Social is an existing direction.
  - `EntryCompletion`: add `private SelfThreadPassInterface $selfThreads`, and in `completeQueued()` after the AppView line:

    ```php
    $this->selfThreads->complete(self::ofKind(
        $this->enrichments->findClaimableFor($entries, $this->clock->now()),
        ShareEnrichmentKind::SelfThread,
    ));
    ```

    The re-query matters: rows that the AppView call just handed on (Task 6) aren't in `$queued`. Move the `shareLinkCards->complete` call after it, re-querying the same way, so a fold that hands a row to `LinkCards` gets its cards in the same completion.
  - Symfony aliases the interface to its single implementation by autowiring. If `debug:container SelfThreadPassInterface` shows no alias, add one in `config/services.yaml` next to the `PendingEmbedInterface` alias, if one exists there.
- [ ] **Step 5:** `php bin/phpunit tests/Service/Social tests/Service/Completion tests/Service/Refresh`. Expected: PASS. Run `composer check && composer md`.
- [ ] **Step 6:** `git commit -m "feat(#1668): a queue pass that folds self-reply threads"`

---

### Task 6: Bluesky — hand posts with replies on to the fold

**Files:**
- Modify: `backend/src/Service/Bluesky/PostEnricher.php` (`settle`, `claimAndSettle`, `awaitingLinkCards`)
- Create: `backend/tests/Fixtures/Bluesky/self-thread.json` (the getPosts view of the Techpresso root, see Step 1)
- Test: `backend/tests/Service/Bluesky/PostEnricherTest.php` (append), plus a functional test in `backend/tests/Service/Refresh/RefreshPassRecorderBlueskyTest.php` (append; read it first)

Behaviour inside `settle()`, per post, keeping the current order of effects:

```php
$outcome = $post === null ? FillOutcome::Unfilled : $this->filled($entry, $post);
if ($outcome !== FillOutcome::Unfilled) { $filled[] = $entry; }
if ($outcome === FillOutcome::Unfilled) { $entry->setShareKind(null); }
if ($post !== null && self::hasReplies($post)) { $pending->awaitSelfThread(); continue; }
if ($outcome === FillOutcome::AwaitingLinkCards) { $pending->awaitLinkCards(); continue; }
$this->entityManager->remove($pending);
```

Moving the `Unfilled` share-kind reset above the `continue`s is safe: an `Unfilled` row was removed before and never awaited cards. `hasReplies(JsonNodeModel $post): bool` is `($post->int('replyCount') ?? 0) > 0`. Rename `awaitingLinkCards()` to `handedOn()`, which keeps every row whose kind is no longer `AppView`, so `release()` frees both kinds for their passes at once.

- [ ] **Step 1: Fixture.** Save the real getPosts view of the Techpresso root as the fixture `FakeAppView::knowsFixture('self-thread')` loads. Read `FakeAppView::knowsFixture` for the expected file shape: the existing fixtures are single post views.
  ```bash
  curl -s "https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts?uris=at://did:plc:t3o4nfbk4lq62necw2n35m4f/app.bsky.feed.post/3my2nlat6px2v" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["posts"][0], indent=2))' > backend/tests/Fixtures/Bluesky/self-thread.json
  ```
  Check that it holds `"replyCount": 1` and no `embed`.
- [ ] **Step 2: Failing tests** in `PostEnricherTest`:
  - `testAPostWithRepliesWaitsForItsThread`: `knowsFixture('self-thread')`, a queued entry with that guid. After `enrich`, the row's kind is `SelfThread`, its `claimedAt` is null, and the entry's share kind is null.
  - `testAPostWithoutRepliesIsDequeuedAsBefore`: use an existing fixture whose `replyCount` is 0, or set it to 0 via `knowsPost([...])`. The row is gone, which pins the old path.
  - `testACardedPostWithRepliesStillGetsItsCardFirst`: `knowsPost(<external fixture with replyCount 2>)`. The root card is stored and the row is `SelfThread`.
- [ ] **Step 3:** Run, expect FAIL. **Step 4:** implement as above, then rerun, expect PASS.
- [ ] **Step 5: Functional.** In `RefreshPassRecorderBlueskyTest` (read how it stubs the RSS and the AppView), add a case: the RSS holds the Techpresso item; the AppView answers getPosts with `self-thread.json` and getPostThread with a recorded thread. Record the thread now:
  ```bash
  curl -s "https://public.api.bsky.app/xrpc/app.bsky.feed.getPostThread?uri=at%3A%2F%2Fdid%3Aplc%3At3o4nfbk4lq62necw2n35m4f%2Fapp.bsky.feed.post%2F3my2nlat6px2v&depth=6&parentHeight=0" > backend/tests/Fixtures/Bluesky/self-thread-replies.json
  ```
  After one refresh, the entry's `thread` holds 2 posts, the second's card URL is `https://www.macrumors.com/2026/10/16/app-store-rules-deepfakes/`, and no share enrichment row remains. Read the thread through `ThreadPostRepository::byEntryIds`.
- [ ] **Step 6:** `php bin/phpunit tests/Service/Bluesky tests/Service/Refresh`. Expected: PASS. Run `composer check && composer md`.
- [ ] **Step 7:** `git commit -m "feat(#1668): Bluesky posts with replies go on to the self-thread fold"`

---

### Task 7: Mastodon — queue the fold at ingest

**Files:**
- Create: `backend/src/Service/Share/PendingThread/PendingThreadInterface.php`, `backend/src/Service/Social/MastodonPendingThread.php`
- Modify: `backend/src/Service/Completion/ShareEnrichmentQueue.php` (`queueCreated`)
- Test: `backend/tests/Service/Completion/ShareEnrichmentQueueTest.php` (append; read its setup), `backend/tests/Service/Social/MastodonPendingThreadTest.php` (new)

**Interfaces — Produces:**
- `interface PendingThreadInterface { public function awaitsThread(Entry $entry): bool; }`
- `MastodonPendingThread::awaitsThread`: true when `SocialPostAddress::of($entry)?->network === SocialNetwork::Mastodon`, `MastodonStatusAddress::ofOrNull($address) !== null`, and `!SourceFormat::isSocial($entry->getFeed()->getSourceFormat())`

The dependency goes Social → Share. Share imports nothing from Social, so no cycle. Run `composer stan`: `ServiceModuleCycleRule` would name one.

`queueCreated()` becomes:

```php
if ($this->pendingEmbed->awaitsEmbed($entry)) { persist AppView; return; }
if ($this->pendingThread->awaitsThread($entry)) { persist SelfThread; return; }
if ($entry->getShareKind() !== ShareKind::Link) { return; }
persist LinkCards;
```

A Mastodon link post now gets its cards after the fold, through the hand-off from Task 5.

- [ ] **Step 1: Failing tests.**
  - `MastodonPendingThreadTest`: a Mastodon RSS entry (`https://mastodon.social/@alice/113`) is true; the same on a `mastodon-api` feed is false; a Bluesky entry is false; a Mastodon feed entry whose guid is no status shape (`https://mastodon.social/about`) is false. Build `Feed`/`Entry` in memory; check how `SocialPostAddressTest` builds them (`ls backend/tests/Service/Social/Support`).
  - `ShareEnrichmentQueueTest`: a new Mastodon RSS link post is queued as `SelfThread`, not `LinkCards`; a Mastodon timeline (`mastodon-api`) post is queued as before.
- [ ] **Step 2:** Run, expect FAIL. **Step 3:** implement. **Step 4:** `php bin/phpunit tests/Service/Completion tests/Service/Social`, expect PASS.
- [ ] **Step 5:** `git commit -m "feat(#1668): Mastodon RSS posts queue the self-thread fold"`

---

### Task 8: The replies view skips folded replies

**Files:**
- Create: `backend/src/Service/Social/Support/FoldedReplies.php`
- Modify: `backend/src/Service/Social/SocialRepliesSource.php` (`read`)
- Test: `backend/tests/Service/Social/Support/FoldedRepliesTest.php` (new), `backend/tests/Service/Social/SocialRepliesSourceTest.php` (append)

**Interfaces — Produces:** `FoldedReplies::without(array $nodes /* list<ReplyNode> */, array $urls /* list<string> */): list<ReplyNode>`. A node whose `url` is listed is replaced, in place, by its own (filtered) replies. A static computing helper, so it goes in `Support/`.

`read()` becomes:
`return $this->order->pageOf(FoldedReplies::without($this->threadOn($post->network)->repliesIn($post->address, $answer), $this->threadPosts->urlsOf($entry)));`
with the constructor gaining `private ThreadPostRepository $threadPosts`.

- [ ] **Step 1: Failing tests.**
  - `FoldedRepliesTest`: the tree `[A(folded) → [B(folded) → [C], D]]` with A and B listed returns `[C, D]`. Order is kept: B's replies come where B was, then A's other replies. Nothing listed returns the tree unchanged, the same instances.
  - `SocialRepliesSourceTest`: one case where the entry has a stored `ThreadPost` with the URL of the fake's first reply, and the page omits that reply but keeps its child.
- [ ] **Step 2–4:** red, implement, green: `php bin/phpunit tests/Service/Social`.
- [ ] **Step 5:** `git commit -m "feat(#1668): the replies view leaves out replies folded into the entry"`

---

### Task 9: API — `thread` on every entry row, `position` on every entry card

First: `git fetch origin develop && git merge origin/develop`. If #1666 has landed, `EntryListRowEnrichment`/`EntryListRowEnricher`/`EntryJson` conflict textually. Keep both sides: their `origin`, our `threadPosts`.

**Files:**
- Create: `backend/src/Http/ThreadPostJson.php`
- Modify: `backend/src/Http/LinkCardJson.php`, `backend/src/Http/EntryJson.php` (phpstan types, `completed()`), `backend/src/Repository/EntryListRowEnrichment.php`, `backend/src/Repository/EntryListRowEnricher.php`
- Test: `backend/tests/Http/EntryJsonTest.php` (append), `backend/tests/Repository/EntryListRowEnricherTest.php` (append), and one functional test in `backend/tests/Controller/Api/LinkCardReaderControllerTest.php` (append; read it first)

**Interfaces — Produces:**
- `EntryListRowEnrichment::$linkCards` is now `list<PlacedLinkCardModel>`, and a new last parameter `public array $threadPosts = []` holds `list<ThreadPostModel>`.
- `LinkCardJson::placed(PlacedLinkCardModel $card): array` returns `of($card->content) + ['position' => $card->position]`. `LinkCardJson::of()` stays for comment cards, which aren't stored per entry.
- `ThreadPostJson::of(ThreadPostModel $post)` returns `{url, publishedAt (ATOM|null), html, text (EntrySnippet::from($post->html) ?? ''), media (EntryMedia::toJsonList), linkCard (placed|null)}`.
- `EntryJson::completed()` gains `'thread' => array_map(ThreadPostJson::of(...), $row->enrichment->threadPosts)`, and `linkCards` maps through `LinkCardJson::placed(...)`. Because `listRow` builds on `completed`, both shapes carry it, and so does a completion answer.

- [ ] **Step 1: Failing tests.**
  - `EntryJsonTest`:
    - A row enriched with `linkCards: [new PlacedLinkCardModel(2, $card)]` yields `linkCards[0]['position'] === 2`.
    - A row with one `ThreadPostModel` whose card sits at position 3 yields `thread[0]['linkCard']['position'] === 3` and `thread[0]['text']` equal to the HTML's plain text.
    - A row without a thread yields `thread === []`.
  - `EntryListRowEnricherTest`: an entry with a stored root card and a stored thread post with a card gets `linkCards` of the root only, and `threadPosts` of one with its card.
  - `LinkCardReaderControllerTest`: `GET /api/entries/{id}/link-cards/{position}/reader` for a reply card's position answers `Response::HTTP_OK`. Copy the existing success case and store the card through `appendToThreadPost`. A reply card belongs to the entry, so the subscription check is unchanged.
- [ ] **Step 2:** run, expect FAIL.
- [ ] **Step 3: Implement.** `EntryListRowEnricher`: inject `ThreadPostRepository $threadPosts`; `$linkCards = $this->linkCards->placedByEntryIds($entryIds)`; `$threads = $this->threadPosts->byEntryIds($entryIds)`; pass `$threads[$entryId] ?? []` last. Keep the class PHPMD-clean: if the constructor's parameter count now trips PHPMD, it points at a missing grouping. Bundle the two card-ish lookups (`linkCards`, `threadPosts`) behind one small repository-level reader, `EntryShareReader` (in `src/Repository`), rather than raising a threshold. Update the phpstan `EntryRow`/`CompletedEntryRow` types (`thread: list<ThreadPostRow>`; `LinkCardRow` plus `position: int` as `PlacedLinkCardRow`).
- [ ] **Step 4:** `php bin/phpunit tests/Http tests/Repository tests/Controller/Api`. Expected: PASS. Run `composer check && composer md`.
- [ ] **Step 5:** `git commit -m "feat(#1668): entries carry their self-reply thread and their cards' positions"`

---

### Task 10: Frontend — DTOs and one card lookup by position

**Files:**
- Modify: `frontend/src/app/reader/link-card/link-card.ts`, `frontend/src/app/reader/models.ts` (`EntryDto`, and the completed-entry type, found with `grep -rn "pending" frontend/src/app/reader/state/*.ts | grep -i complet`), `frontend/src/app/reader/shell/reader-shell.component.ts:154-157`, `frontend/src/app/reader/article/link-cards/link-cards.component.{html,ts}`, `frontend/src/app/reader/list/magazine/blocks/entry-link/entry-link.component.{html,ts}`
- Create: `frontend/src/app/reader/link-card/link-card-at.ts`, `frontend/src/app/reader/link-card/link-card-at.spec.ts`, `frontend/src/app/reader/thread-post.ts`
- Test: the two specs above, plus the existing `entry-link.component.spec.ts` and `link-cards.component.spec.ts`
- Fixtures: every test factory that builds an `EntryDto` (`grep -rln "linkCards: \[\]" frontend/src/app`) gains `thread: []`, and its cards gain `position`.

**Interfaces — Produces:**

```ts
// link-card.ts
export interface LinkCardDto { url: string; title: string; description: string | null; imageUrl: string | null; siteName: string; }
/** A card stored on an entry, root or reply; `position` is what the reader endpoint and `?link=` name (#1668). */
export interface PlacedLinkCardDto extends LinkCardDto { position: number; }

// thread-post.ts
export interface ThreadPostDto {
  url: string | null;
  publishedAt: string | null;
  html: string;
  /** Plain text of `html`, for the list. */
  text: string;
  media: EntryMediumDto[];
  linkCard: PlacedLinkCardDto | null;
}

// models.ts, EntryDto
linkCards: PlacedLinkCardDto[];
/** The author's own replies continuing the post, at most three, oldest first (#1668). */
thread: ThreadPostDto[];

// link-card-at.ts
export function linkCardAt(entry: EntryDto, position: number): PlacedLinkCardDto | null {
  return (
    entry.linkCards.find((card) => card.position === position) ??
    entry.thread.map((reply) => reply.linkCard).find((card) => card?.position === position) ??
    null
  );
}
```

- [ ] **Step 1: Failing specs.**
  - `link-card-at.spec.ts`:
    - A root card at position 1 is found by 1.
    - A reply card at position 0 (thread folded first, root cards appended later, the Mastodon order) is found by 0.
    - An unknown position gives null.
  - `entry-link.component.spec.ts`: give the entry's first card `position: 4`, click its open-in-reader button, and expect the router navigation's `link` to be `<id>.4`. Read the spec for how it inspects navigation now. It currently assumes 0.
  - `link-cards.component.spec.ts`: same, with two cards at positions 2 and 5, clicking the second expecting `.5`.
- [ ] **Step 2:** `cd frontend && npx jest src/app/reader/link-card src/app/reader/list/magazine/blocks/entry-link src/app/reader/article/link-cards`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `reader-shell`: `openLinkCard` uses `linkCardAt(entry, ref.position)`.
  - `link-cards.component.html` sends `openInReader(card.position)`.
  - `entry-link` sends `{ entryId, position: shared.position }` (change `openInReaderAlone()` to take the position).
  - Then fix every fixture and type error until `npx tsc -p tsconfig.spec.json --noEmit` and `npx tsc -p tsconfig.app.json --noEmit` are clean.
- [ ] **Step 4:** `npm run check`. Expected: PASS.
- [ ] **Step 5:** `git commit -m "feat(#1668): open a link card in the reader by its stored position"`

---

### Task 11: Frontend — the thread line and the magazine thread block (mockup E)

**Files:**
- Create: `frontend/src/app/shared/thread-line/thread-line.component.{ts,scss}`, `thread-line-post.component.{ts,scss}`, `thread-count.component.{ts,scss}`, `thread-line.spec.ts`; `frontend/src/app/reader/list/magazine/blocks/entry-thread/entry-thread.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/list/magazine/magazine-block.ts`, `magazine-block.component.{html,ts}`, `magazine-slot-fit.ts`, `magazine-block-height.ts` (+ their specs), `docs/design-language.md` (§2 catalog)

**Interfaces — Produces:**
- `<app-thread-line>`: host `role="list"`, a vertical stack of posts.
- `<app-thread-line-post>`: host `role="listitem"`. It draws the dot and, except on the last post (`:host(:last-child)`), the line down to the next dot. The first dot is a stronger tone. Content is projected.
- `<app-thread-count [position]="n" [total]="N">`: the `n/N` pill. Inputs are `input.required<number>()`.
- Block kind `'thread'`, first in `SHARE_KINDS` so a folded entry with a card takes it ahead of `link`: `['thread', 'link', 'media', 'quoting']`.
- `sharesThread(entry) = entry.thread.length > 0`
- `threadHeight(entry: EntryDto): number`

Look (match mockup E, `.superpowers/brainstorm/74425-1792228929/content/self-reply-thread-v2.html`, option E, exactly):
- The kicker line is unchanged, with the source's avatar/favicon from `app-entry-source`.
- Each post sits in a two-column grid: a narrow rail (dot `--space-2` across, line 2px `var(--border-strong)`) and the text.
- The counter pill sits inline after a text post's last word. A card-only post shows the pill above the card.
- A reply's card is `app-link-card layout="block"` under that reply.
- Pills and actions appear once, at the bottom (`app-entry-meta`).
- Use tokens only (`--space-*`, `--border-strong`, `--text-secondary`, `--fs-xs`), with no hex and no ad-hoc px except the 2px rule, which `entry-comments.component.scss` already uses for the same purpose. If Stylelint flags it there, use the token that file's line resolves to.

`entry-thread.component.html`:

```html
<article class="thread" [appOpensEntry]="entry()">
  <app-entry-kicker-line [entry]="entry()" />
  <app-thread-line>
    <app-thread-line-post>
      @if (headline()) {
        <p class="title" [class.post]="isPost()">
          {{ headline() }} <app-thread-count [position]="1" [total]="total()" />
        </p>
      }
      @if (snippet()) {
        <p class="dek">{{ snippet() }}</p>
      }
      @if (entry().linkCards[0]; as card) {
        <app-link-card
          [card]="card"
          layout="block"
          [openableInReader]="true"
          (openInReader)="openInReaderAlone(card.position)"
          (click)="$event.stopPropagation()"
          (keydown.enter)="$event.stopPropagation()"
          (keydown.space)="$event.stopPropagation()"
        />
      }
    </app-thread-line-post>
    @for (reply of entry().thread; track $index; let index = $index) {
      <app-thread-line-post>
        @if (reply.text) {
          <p class="reply">{{ reply.text }} <app-thread-count [position]="index + 2" [total]="total()" /></p>
        } @else {
          <app-thread-count [position]="index + 2" [total]="total()" />
        }
        @if (reply.linkCard; as card) {
          <app-link-card
            [card]="card"
            layout="block"
            [openableInReader]="true"
            (openInReader)="openInReaderAlone(card.position)"
            (click)="$event.stopPropagation()"
            (keydown.enter)="$event.stopPropagation()"
            (keydown.space)="$event.stopPropagation()"
          />
        }
      </app-thread-line-post>
    }
  </app-thread-line>
  <app-entry-meta [entry]="entry()" [tags]="tags()" />
  <app-entry-duplicates [entry]="entry()" />
</article>
```

`EntryThreadComponent extends EntryBlockBase`, with `total = computed(() => this.entry().thread.length + 1)` and `openInReaderAlone(position: number)` as in `EntryLinkComponent`. The SCSS uses `magazine-card.chrome`/`card-states`/`headline`/`dek` exactly as `entry-link.component.scss`, and `.reply` uses the `dek` mixin.

Height (`magazine-block-height.ts`): add `THREAD = { chrome: 82, line: 22.5, charsPerLine: 44, linesMax: 3, cardWithImage: 330, cardBare: 110, gap: 12 }`. `threadHeight` sums chrome + the root headline's lines + each reply's lines (`lineCount(reply.text, …)`), + each card (with or without `imageUrl`), + a gap per post. In `blockHeight`, `kind === 'thread'` returns `threadHeight(entry)`. Exclude `'thread'` from `BLOCK_HEIGHT`'s key type as `'quoting'` is.

- [ ] **Step 1: Failing specs.**
  - `magazine-slot-fit.spec.ts`:
    - An entry with `share: 'link'`, a card and a non-empty `thread` gets `shareKind(entry) === 'thread'`.
    - With `thread: []` it stays `'link'`.
  - `magazine-block-height.spec.ts`: a thread with one text reply is shorter than one with a carded reply, and both are taller than `chrome`.
  - `entry-thread.component.spec.ts`:
    - It renders 3 `app-thread-line-post`s for a root plus 2 replies, with counters `1/3 2/3 3/3`.
    - Clicking the second reply's card's open button (card position 0) navigates with `link=<id>.0`.
    - The root's card isn't rendered when `linkCards` is empty.
  - `thread-line.spec.ts`: the post host has `role="listitem"` and the count renders `2/3`.
- [ ] **Step 2:** `npx jest src/app/shared/thread-line src/app/reader/list/magazine`. Expected: FAIL.
- [ ] **Step 3: Implement** as above. Add `@case ('thread')` to `magazine-block.component.html` and the import to its `.ts`. Add the catalog entry to `docs/design-language.md` §2 for `<app-thread-line>` / `<app-thread-line-post>` / `<app-thread-count>`, in the format of its neighbours: purpose, inputs, usage, and "used by: magazine thread block, rows, article".
- [ ] **Step 4:** `npm run check`. Expected: PASS.
- [ ] **Step 5:** `git commit -m "feat(#1668): a magazine block that draws an author's self-reply thread"`

---

### Task 12: Frontend — rows layout and article view

**Files:**
- Modify: `frontend/src/app/reader/list/entry-row/entry-row.component.{html,ts,scss,spec.ts}`, `frontend/src/app/reader/article/reader-view/reader-view.component.{html,ts}`
- Create: `frontend/src/app/reader/article/thread-replies/thread-replies.component.{ts,html,scss,spec.ts}`

Rows (`entry-row`): after the snippet, `@if (entry().thread.length) { <app-thread-line> … }`. The root's post shows the headline as today, and only replies go on the line. Each reply shows `reply.text` clamped to 2 lines, its `app-thread-count`, and `app-link-card layout="block"` when it has a card, opening with `LinkReaderNavigation.openAlone({ entryId, position: card.position })`. The row's thumbnail stays the root's.

Article (`app-thread-replies [entry]`), placed in `reader-view.component.html` right after the `app-link-cards` block:
- One `app-thread-line` with a post per reply.
- Each post: `<div class="body" [innerHTML]="reply.html">` (sanitized server-side, as comments are), then `app-post-media [media]="reply.media"` when it has media, then `app-link-card layout="article" [openableInReader]="true" (openInReader)="navigation.layer({ entryId: entry().id, position: card.position })"`, then `app-thread-count`.
- Reuse the `.body ::ng-deep` rules from `entry-comments.component.scss` by moving them into a shared partial (`frontend/src/app/reader/_post-body.scss`) that both files `@use`. They're the same HTML, and a third copy would be the DRY trigger.

- [ ] **Step 1: Failing specs.**
  - `entry-row.component.spec.ts`: an entry with two replies renders two thread posts. A reply card's open button navigates with that card's position.
  - `thread-replies.component.spec.ts`:
    - It renders each reply's HTML.
    - A reply card's open button calls `layer` with `{ entryId, position }`.
    - A reply with media renders `app-post-media`.
- [ ] **Step 2:** `npx jest src/app/reader/list/entry-row src/app/reader/article/thread-replies`. Expected: FAIL.
- [ ] **Step 3:** implement. **Step 4:** `npm run check`, expect PASS.
- [ ] **Step 5:** `git commit -m "feat(#1668): self-reply threads in the rows layout and the article"`

---

### Task 13: Smoke, live check, gates, coordination, PR

- [ ] **Step 1: Playwright smoke** `frontend/e2e/self-reply-thread.spec.ts`, modelled on `frontend/e2e/magazine-kicker-one-line.spec.ts`, with its route stubbing. The spec owns its data:
  - Stub the list route with one Techpresso-shaped entry: `thread` of two replies, the second carrying a card at position 0. Stub `/api/entries/<id>/link-cards/0/reader` with a minimal reader answer; copy its shape from the #1664 smokes (`grep -rln "link-cards" frontend/e2e`).
  - Assert:
    - three `app-thread-line-post`s;
    - the counter `3/3`;
    - clicking the card's open-in-reader button puts `link=<id>.0` in the URL;
    - the link layer shows the card's title.

  The stack serves the main checkout, so this can only run when that checkout runs this branch. **Ask Lars** before touching the main checkout (#1666's session works there). Alternatively, run it after this branch is merged into `develop` and the stack is on `develop`. Never run e2e from the worktree (preflight blocks it, #615).
- [ ] **Step 2: Live check** (same precondition): `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`; restart the worker (memory: worker holds stale code). Let a Techpresso refresh ingest a new post, or wait for one. Open `http://localhost:4200/?subscription=1847` and confirm three things: the newest post draws as mockup E, the MacRumors-style card opens in the reader, and the article's replies section no longer repeats the two folded replies. Then scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG")'`.
- [ ] **Step 3: Gates.**
  - Backend: `composer check`, `composer md`, `composer test:parallel`, `composer infection:diff`. If the initial run OOMs, rerun (known flake).
  - Frontend: `npm run check`.
  - Fix escaped mutants by adding assertions, never by lowering `minMsi`.
- [ ] **Step 4: Coordinate with #1666.** `git fetch origin develop && git merge origin/develop`. If #1666's `SavedPostRepository` copy (`INSERT INTO link_card … SELECT … FROM link_card`) is now on `develop`, add `thread_post_id IS NULL` to that copy's `WHERE`, and copy `thread_post` rows with their cards for the saved entry, with a test in `SavedPostRepositoryTest`. Otherwise, leave a note in the PR body for #1666 to do the same.
- [ ] **Step 5: PR.** Push with `git push -u origin feature/1668-self-reply-threads`, then open the PR into `develop`. The body says `Closes #1668`, summarises the design, and links the spec. It names the #1666/#1667 notes and the follow-up "fold #1667 author feeds via the session". Bind it with the ccd_pr tools.
