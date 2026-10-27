# Podcast Transcripts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Read `podcast:transcript`, fetch one transcript per episode through a queue drained after ingest, show it in the article view with timestamps that seek the player, and match it in search on both engines.

**Architecture:** Ingest persists a `pending` `EntryTranscript` row per new entry that declares a transcript. A `TranscriptSweep` (worker message and maintenance tick) claims due rows, fetches them through `GuardedApiClient`, turns VTT/SRT/JSON/HTML into speaker paragraphs, stores paragraphs plus a plain `searchText`, and reindexes. The entry detail endpoint serves the filled transcript (filling a still-pending one on open); the LIKE fallback gains a correlated `EXISTS` over `entry_transcript.search_text`; Meilisearch gains a `transcript` field; the frontend renders a collapsed (or, with no body, open) transcript section whose timestamps seek the episode through `ChapterPlayer`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM (MySQL + SQLite), Meilisearch, Angular 20 (signals, DOM decorators), Jest.

**Spec:** `docs/superpowers/specs/2026-10-27-podcast-transcripts-design.md`

## Global Constraints

- Branch `feature/1809-podcast-transcripts` (exists, holds the spec). Commits `type(#1809): …`, no Claude attribution.
- Every PHP file `declare(strict_types=1)`; classes `final` (`final readonly` for services and models); CLAUDE.md Clean Code rules apply (no abbreviations, no boolean flag params, guard clauses, comments only when a reader would otherwise get the code wrong).
- HTTP statuses as `Response::HTTP_*`, never literals.
- Datetimes naive UTC: use `App\Service\Clock\NaiveUtcClock` for anything persisted.
- Transcript file size limit: **1 MB** (`ResponseSizeLimit::TranscriptFile = 1_000` kB). Stored and indexed in full.
- Type preference: `text/vtt` → `application/json` → `application/x-subrip` / `application/srt` → `text/html`; ties by document order. Unknown types ignored.
- Backoff: a transient failure re-queues after `15 · 2^attempts` minutes; the 5th failed attempt marks the row `failed`.
- Gates before the PR: `composer check`, `composer md` (every touched `src` file PHPMD-clean), `php bin/phpunit`, `docker compose exec php composer test` (MySQL), `composer infection:diff`, migration from empty on both platforms + `doctrine:schema:validate`, `docker compose exec -T frontend npm run check`, `ng build` (template errors), PhpStorm `lint_files` on changed PHP.
- Never run `tests/bootstrap.php` outside the test env; never clear the dev database.

## Deviations from the spec (decided while reading the code)

- **No language tie-break.** The feed's `<language>` is not stored on `Feed`, and the item parser never sees it. Ties go to document order.
- **Restore queues nothing.** Backups carry no transcript declaration, and restore does not go through `EntryIngestor`; a restored episode has no transcript. Accepted for v1.
- **Formats are a `match`, not a keyed locator.** The four readers are pure static helpers (`Support/`), and VTT and SRT share one cue reader; a tagged strategy per format would duplicate it.
- **Paragraph start is `int` seconds** (`startSeconds`), matching `EntryChapter`.
- **Frontend is a DOM decorator** (`decorators/reader-transcript.ts`), as chapters are, not a component.
- **"Body when there is no article"** is implemented as the section rendered *open* when the feed body has no text, collapsed otherwise.
- **MaintenanceTick** gains no tenth constructor parameter (PHPMD `ExcessiveParameterList` fires at 10): link cards and transcripts move behind one `FollowUpFetchSweeps` service and one report model.

Update the spec's matching lines in Task 12's commit.

---

## File map

**Create (backend)**

| File | Responsibility |
|---|---|
| `src/Enum/TranscriptFormat.php` | Format enum, mime mapping, preference rank |
| `src/Enum/TranscriptStatus.php` | `pending` / `filled` / `failed` |
| `src/Entity/TranscriptParagraph.php` | Stored paragraph value (`startSeconds`, `speaker`, `text`) |
| `src/Entity/EntryTranscript.php` | Queue row + store, one per entry, cascade on entry delete |
| `src/Repository/EntryTranscriptRepository.php` | Claim queries, lookup by entry, search texts by entry ids |
| `migrations/Version20261027120000.php` | `entry_transcript` on MySQL and SQLite |
| `src/Service/Parser/Model/ParsedTranscriptModel.php` | The picked declaration |
| `src/Service/Parser/Support/DeclaredTranscripts.php` | Reads `<podcast:transcript>` and picks one |
| `src/Service/Ingest/EntryTranscriptWriter.php` | Persists a pending row per new entry that declared one |
| `src/Service/Transcript/Model/TranscriptCueModel.php` | One timed (or untimed) line before merging |
| `src/Service/Transcript/Model/ReadTranscriptModel.php` | Paragraphs + search text |
| `src/Service/Transcript/Model/TranscriptBudgetModel.php` | Fetch count per sweep |
| `src/Service/Transcript/Model/TranscriptSweepReportModel.php` | `claimed`, `filled` |
| `src/Service/Transcript/Support/TimedCues.php` | VTT + SRT cue reader |
| `src/Service/Transcript/Support/JsonCues.php` | Podcast-namespace JSON reader |
| `src/Service/Transcript/Support/TranscriptParagraphs.php` | Merge cues into paragraphs; build search text |
| `src/Service/Transcript/TranscriptReader.php` | Body + format → `ReadTranscriptModel` |
| `src/Service/Transcript/Exception/TranscriptUnusableException.php` | Permanent failure |
| `src/Service/Transcript/Exception/TranscriptUnreachableException.php` | Transient failure |
| `src/Service/Transcript/TranscriptSource/TranscriptSourceInterface.php` | Fetch seam |
| `src/Service/Transcript/TranscriptSource/GuardedTranscriptSource.php` | Guarded fetch, 1 MB |
| `src/Service/Transcript/TranscriptFiller.php` | Fill / fail / back off one row |
| `src/Service/Transcript/TranscriptSweep.php` | Claim, fill, flush, reindex |
| `src/Service/Transcript/TranscriptOnOpen.php` | Fill a pending row when its entry opens |
| `src/Service/Worker/Message/CompleteQueuedTranscripts.php`, `src/Service/Worker/Handler/CompleteQueuedTranscriptsHandler.php` | Worker firing |
| `src/Service/Maintenance/FollowUpFetchSweeps.php`, `src/Service/Maintenance/Model/FollowUpFetchesReportModel.php` | Link cards + transcripts in the tick |
| `src/Service/Search/TranscriptText/TranscriptTextInterface.php` | Search texts by entry id, implemented by the repository |
| `src/Http/EntryTranscriptJson.php` | Response shape |

**Modify (backend):** `ResponseSizeLimit`, `ParsedMediaBundleModel`, `ItemMediaExtractor`, `EntryIngestor`, `EntryIndexer`, `IndexedEntryModel`, `MeilisearchIndex`, `SearchReindexCommand`, `SearchTermsPredicateBuilder`, `MaintenanceTick`, `MaintenanceSweepsModel`, `MaintenanceTickJson`, `WorkerSchedule`, `EntryJson`, `EntryDetailController`, `tests/Support/EntryIngestors.php`, every test that does `new EntryIndexer(`.

**Modify (frontend):** `reader/models.ts`, `reader/article/content/entry-body.service.ts`, `reader/shell/reader-route-state.service.ts`, `reader/article/content/article-source.service.ts`, `reader/article/article-content/article-content.component.ts`, `reader/article/reader-view/article-body.service.ts`, `reader/article/reader-view/reader-view.component.html`, `public/i18n/en.json`, `public/i18n/de.json`. **Create:** `reader/article/decorators/reader-transcript.ts` (+ spec), `reader/article/reader-view/article-transcript.ts` (+ spec), `reader/article/article-content/article-transcript.scss`.

---

### Task 1: Storage — enums, paragraph value, `EntryTranscript`, repository, migration

**Files:**
- Create: `backend/src/Enum/TranscriptFormat.php`, `backend/src/Enum/TranscriptStatus.php`, `backend/src/Entity/TranscriptParagraph.php`, `backend/src/Entity/EntryTranscript.php`, `backend/src/Repository/EntryTranscriptRepository.php`, `backend/migrations/Version20261027120000.php`
- Test: `backend/tests/Entity/EntryTranscriptTest.php`, `backend/tests/Enum/TranscriptFormatTest.php`, `backend/tests/Repository/EntryTranscriptRepositoryTest.php`

**Interfaces:**
- Produces: `TranscriptFormat::fromMimeType(string): ?self`, `->preference(): int` (lower wins); `TranscriptStatus`; `TranscriptParagraph(?int $startSeconds, ?string $speaker, string $text)` with `isComplete()`, `fromStored()`, `jsonSerialize()`; `EntryTranscript` (ctor `(Entry, string $url, TranscriptFormat, ?string $language)`, `fill(list<TranscriptParagraph>, string)`, `fail()`, `retryAfter(\DateTimeImmutable)`, `isPending/isFilled/isFailed()`, getters); `EntryTranscriptRepository::findClaimable(\DateTimeImmutable $now, int $limit): list<EntryTranscript>`, `claim(list<EntryTranscript>, \DateTimeImmutable $now): list<EntryTranscript>`, `findForEntry(Entry): ?EntryTranscript`, `searchTextsFor(list<int>): array<int, string>` (its interface arrives in Task 6; implement the method now).

- [ ] **Step 1: Write the failing enum and entity tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\TranscriptFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptFormatTest extends TestCase
{
    #[DataProvider('mimeTypes')]
    public function testMapsADeclaredTypeToItsFormat(string $mimeType, ?TranscriptFormat $expected): void
    {
        self::assertSame($expected, TranscriptFormat::fromMimeType($mimeType));
    }

    /** @return iterable<string, array{string, ?TranscriptFormat}> */
    public static function mimeTypes(): iterable
    {
        yield 'vtt' => ['text/vtt', TranscriptFormat::Vtt];
        yield 'vtt with parameters and case' => [' Text/VTT; charset=utf-8', TranscriptFormat::Vtt];
        yield 'json' => ['application/json', TranscriptFormat::Json];
        yield 'subrip' => ['application/x-subrip', TranscriptFormat::Srt];
        yield 'srt' => ['application/srt', TranscriptFormat::Srt];
        yield 'html' => ['text/html', TranscriptFormat::Html];
        yield 'plain text is unknown' => ['text/plain', null];
    }

    public function testPrefersVttThenJsonThenSrtThenHtml(): void
    {
        $ranked = TranscriptFormat::cases();
        usort($ranked, static fn (TranscriptFormat $left, TranscriptFormat $right): int
            => $left->preference() <=> $right->preference());

        self::assertSame(
            [TranscriptFormat::Vtt, TranscriptFormat::Json, TranscriptFormat::Srt, TranscriptFormat::Html],
            $ranked,
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use PHPUnit\Framework\TestCase;

final class EntryTranscriptTest extends TestCase
{
    public function testANewTranscriptIsPendingAndDueAtOnce(): void
    {
        $transcript = $this->transcript();

        self::assertTrue($transcript->isPending());
        self::assertSame([], $transcript->getParagraphs());
        self::assertNull($transcript->getSearchText());
    }

    public function testFillingStoresTheParagraphsAndTheSearchText(): void
    {
        $transcript = $this->transcript();
        $paragraphs = [new TranscriptParagraph(0, 'Holger', 'Hallo.'), new TranscriptParagraph(null, null, 'Ende.')];

        $transcript->fill($paragraphs, "Holger: Hallo.\nEnde.");

        self::assertTrue($transcript->isFilled());
        self::assertEquals($paragraphs, $transcript->getParagraphs());
        self::assertSame("Holger: Hallo.\nEnde.", $transcript->getSearchText());
    }

    public function testATransientFailureBacksOffAndStaysPending(): void
    {
        $transcript = $this->transcript();
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');

        $transcript->retryAfter($now);

        self::assertTrue($transcript->isPending());
        self::assertEquals(new \DateTimeImmutable('2026-10-27 12:30:00'), $transcript->getNextAttemptAt());

        $transcript->retryAfter($now);

        self::assertEquals(new \DateTimeImmutable('2026-10-27 13:00:00'), $transcript->getNextAttemptAt());
    }

    public function testTheFifthFailedAttemptGivesUp(): void
    {
        $transcript = $this->transcript();
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');

        for ($attempt = 1; $attempt < EntryTranscript::MAX_ATTEMPTS; $attempt++) {
            $transcript->retryAfter($now);
            self::assertTrue($transcript->isPending());
        }
        $transcript->retryAfter($now);

        self::assertTrue($transcript->isFailed());
        self::assertNull($transcript->getNextAttemptAt());
    }

    public function testFailingIsFinal(): void
    {
        $transcript = $this->transcript();

        $transcript->fail();

        self::assertTrue($transcript->isFailed());
        self::assertFalse($transcript->isPending());
    }

    private function transcript(): EntryTranscript
    {
        return new EntryTranscript(
            $this->createStub(Entry::class),
            'https://pod.test/ep1.vtt',
            TranscriptFormat::Vtt,
            'de',
        );
    }
}
```

Note: `Entry` is `final`; if `createStub(Entry::class)` fails, build a real `Entry` the way `tests/Entity/EntryTest.php` does (look there first and copy its construction helper).

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Enum/TranscriptFormatTest.php tests/Entity/EntryTranscriptTest.php`
Expected: FAIL — classes `App\Enum\TranscriptFormat` / `App\Entity\EntryTranscript` not found.

- [ ] **Step 3: Write the enums and the paragraph value**

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum TranscriptFormat: string
{
    case Vtt = 'vtt';
    case Json = 'json';
    case Srt = 'srt';
    case Html = 'html';

    public static function fromMimeType(string $mimeType): ?self
    {
        $essence = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($essence) {
            'text/vtt' => self::Vtt,
            'application/json' => self::Json,
            'application/x-subrip', 'application/srt' => self::Srt,
            'text/html' => self::Html,
            default => null,
        };
    }

    public function preference(): int
    {
        return match ($this) {
            self::Vtt => 0,
            self::Json => 1,
            self::Srt => 2,
            self::Html => 3,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum TranscriptStatus: string
{
    case Pending = 'pending';
    case Filled = 'filled';
    case Failed = 'failed';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\IncompleteStoredMediaException;

final readonly class TranscriptParagraph implements \JsonSerializable
{
    public function __construct(public ?int $startSeconds, public ?string $speaker, public string $text)
    {
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @phpstan-assert-if-true array{startSeconds: int|null, speaker: string|null, text: string, ...<mixed>} $stored
     */
    public static function isComplete(array $stored): bool
    {
        $start = $stored['startSeconds'] ?? null;
        $speaker = $stored['speaker'] ?? null;

        return ($start === null || \is_int($start))
            && ($speaker === null || \is_string($speaker))
            && \is_string($stored['text'] ?? null);
    }

    /** @param array<string, mixed> $stored */
    public static function fromStored(array $stored): self
    {
        if (!self::isComplete($stored)) {
            throw new IncompleteStoredMediaException('A stored transcript paragraph needs its text.');
        }

        return new self($stored['startSeconds'] ?? null, $stored['speaker'] ?? null, $stored['text']);
    }

    /** @return array{startSeconds: int|null, speaker: string|null, text: string} */
    public function jsonSerialize(): array
    {
        return ['startSeconds' => $this->startSeconds, 'speaker' => $this->speaker, 'text' => $this->text];
    }
}
```

- [ ] **Step 4: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TranscriptFormat;
use App\Enum\TranscriptStatus;
use App\Repository\EntryTranscriptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An episode's declared transcript: queued at ingest, filled by TranscriptSweep or on open. Owned from this side,
 * never mapped on Entry: an inverse one-to-one is always loaded eagerly, which would pull ~100 KB into every list.
 */
#[ORM\Entity(repositoryClass: EntryTranscriptRepository::class)]
#[ORM\Table(name: 'entry_transcript')]
#[ORM\Index(name: 'idx_entry_transcript_claimable', columns: ['status', 'next_attempt_at'])]
final class EntryTranscript
{
    public const int URL_MAX_LENGTH = 2048;
    public const int LANGUAGE_MAX_LENGTH = 35;
    public const int MAX_ATTEMPTS = 5;
    private const int BACKOFF_BASE_MINUTES = 15;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(type: Types::TEXT)]
    private string $url;

    #[ORM\Column(length: 8, enumType: TranscriptFormat::class)]
    private TranscriptFormat $format;

    #[ORM\Column(length: self::LANGUAGE_MAX_LENGTH, nullable: true)]
    private ?string $language;

    #[ORM\Column(length: 8, enumType: TranscriptStatus::class)]
    private TranscriptStatus $status = TranscriptStatus::Pending;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'next_attempt_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $paragraphs = null;

    #[ORM\Column(name: 'search_text', type: Types::TEXT, nullable: true)]
    private ?string $searchText = null;

    public function __construct(Entry $entry, string $url, TranscriptFormat $format, ?string $language)
    {
        $this->entry = $entry;
        $this->url = $url;
        $this->format = $format;
        $this->language = $language;
    }

    public function requireId(): int
    {
        return $this->id ?? throw new \LogicException('An entry transcript has no id before it is flushed.');
    }

    /** @param list<TranscriptParagraph> $paragraphs */
    public function fill(array $paragraphs, string $searchText): void
    {
        $this->paragraphs = array_map(
            static fn (TranscriptParagraph $paragraph): array => $paragraph->jsonSerialize(),
            $paragraphs,
        );
        $this->searchText = $searchText;
        $this->status = TranscriptStatus::Filled;
        $this->nextAttemptAt = null;
    }

    public function fail(): void
    {
        $this->status = TranscriptStatus::Failed;
        $this->nextAttemptAt = null;
    }

    public function retryAfter(\DateTimeImmutable $now): void
    {
        $this->attempts++;
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->fail();

            return;
        }

        $minutes = self::BACKOFF_BASE_MINUTES * 2 ** $this->attempts;
        $this->nextAttemptAt = $now->modify(\sprintf('+%d minutes', $minutes));
    }

    public function isPending(): bool
    {
        return $this->status === TranscriptStatus::Pending;
    }

    public function isFilled(): bool
    {
        return $this->status === TranscriptStatus::Filled;
    }

    public function isFailed(): bool
    {
        return $this->status === TranscriptStatus::Failed;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getFormat(): TranscriptFormat
    {
        return $this->format;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    /** @return list<TranscriptParagraph> */
    public function getParagraphs(): array
    {
        return StoredList::read(
            $this->paragraphs,
            TranscriptParagraph::isComplete(...),
            TranscriptParagraph::fromStored(...),
        );
    }

    public function getSearchText(): ?string
    {
        return $this->searchText;
    }
}
```

`$now->modify()` can throw `\DateMalformedStringException` under PHPStan; if it flags it, use `$now->add(new \DateInterval(\sprintf('PT%dM', $minutes)))` instead.

- [ ] **Step 5: Run the unit tests**

Run: `cd backend && php bin/phpunit tests/Enum/TranscriptFormatTest.php tests/Entity/EntryTranscriptTest.php`
Expected: PASS.

- [ ] **Step 6: Write the failing repository test**

Copy the setup of `tests/Repository/SavedSearchMembershipSweepRepositoriesTest.php` (kernel test case, `EntityManagerInterface` from the container, a seeded user, feed and entries — reuse whatever helper that test uses to create entries). Cases:

```php
public function testClaimableRowsArePendingAndDueNewestEntryFirst(): void
{
    $now = new \DateTimeImmutable('2026-10-27 12:00:00');
    [$older, $newer, $backedOff, $filled] = $this->seedTranscripts(4);
    $backedOff->retryAfter($now);
    $filled->fill([new TranscriptParagraph(0, null, 'x')], 'x');
    $this->entityManager->flush();

    self::assertSame(
        [$newer->requireId(), $older->requireId()],
        array_map(static fn (EntryTranscript $row): int => $row->requireId(), $this->transcripts->findClaimable($now, 10)),
    );
}

public function testARowIsClaimedOnceUntilItsLeaseRunsOut(): void
{
    $now = new \DateTimeImmutable('2026-10-27 12:00:00');
    [$row] = $this->seedTranscripts(1);

    self::assertCount(1, $this->transcripts->claim([$row], $now));
    self::assertSame([], $this->transcripts->claim([$row], $now));
    self::assertSame([], $this->transcripts->findClaimable($now, 10));
    self::assertCount(1, $this->transcripts->findClaimable($now->modify('+6 minutes'), 10));
}

public function testSearchTextsAreKeyedByEntryIdForFilledRowsOnly(): void
{
    [$filled, $pending] = $this->seedTranscripts(2);
    $filled->fill([new TranscriptParagraph(0, null, 'Hallo')], 'Hallo');
    $this->entityManager->flush();
    $filledEntryId = $filled->getEntry()->requireId();

    self::assertSame(
        [$filledEntryId => 'Hallo'],
        $this->transcripts->searchTextsFor([$filledEntryId, $pending->getEntry()->requireId()]),
    );
}

public function testDeletingTheEntryDeletesItsTranscript(): void
{
    [$row] = $this->seedTranscripts(1);
    $entryId = $row->getEntry()->requireId();
    $this->entityManager->clear();

    $this->entityManager->getConnection()->executeStatement('DELETE FROM entry WHERE id = ?', [$entryId]);

    self::assertSame(0, (int) $this->entityManager->getConnection()
        ->fetchOne('SELECT COUNT(*) FROM entry_transcript'));
}
```

The claim is a bulk DQL `UPDATE`: the managed entity keeps its old `nextAttemptAt` (memory: bulk DQL fools entity assertions), so assert through queries, never through the entity, after a claim. SQLite enforces the cascade only with `PRAGMA foreign_keys = ON`; check how `EntryPrunerTest` proves cascades and do the same.

- [ ] **Step 7: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Enum\TranscriptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EntryTranscript> */
final class EntryTranscriptRepository extends ServiceEntityRepository
{
    /** Outlasts a worker sweep (5 fetches × TranscriptBudgetModel::FETCH_SECONDS) with room to spare. */
    public const string CLAIM_LEASE = 'PT5M';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryTranscript::class);
    }

    /** @return list<EntryTranscript> pending rows due at $now, newest entry first: a row is created with its entry */
    public function findClaimable(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<EntryTranscript> $rows */
        $rows = $this->due($now)
            ->orderBy('transcript.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * A claim pushes nextAttemptAt one lease out by a conditional UPDATE, so of two callers racing for a row exactly
     * one sees it change. A holder outliving its lease may fill a row twice; both write the same transcript.
     *
     * @param list<EntryTranscript> $rows
     *
     * @return list<EntryTranscript> the rows this call now holds
     */
    public function claim(array $rows, \DateTimeImmutable $now): array
    {
        $query = $this->getEntityManager()->createQuery(\sprintf(
            'UPDATE %s transcript SET transcript.nextAttemptAt = :leaseEnd WHERE transcript.id = :id'
            . ' AND transcript.status = :pending'
            . ' AND (transcript.nextAttemptAt IS NULL OR transcript.nextAttemptAt <= :now)',
            EntryTranscript::class,
        ))
            ->setParameter('leaseEnd', $now->add(new \DateInterval(self::CLAIM_LEASE)), Types::DATETIME_IMMUTABLE)
            ->setParameter('pending', TranscriptStatus::Pending)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE);

        $claimed = [];
        foreach ($rows as $row) {
            if ($query->setParameter('id', $row->requireId())->execute() === 1) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function findForEntry(Entry $entry): ?EntryTranscript
    {
        return $this->findOneBy(['entry' => $entry]);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, string> the filled transcripts' search text, by entry id
     */
    public function searchTextsFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int|string, searchText: string}> $rows */
        $rows = $this->createQueryBuilder('transcript')
            ->select('IDENTITY(transcript.entry) AS entryId', 'transcript.searchText AS searchText')
            ->andWhere('transcript.entry IN (:entryIds)')
            ->andWhere('transcript.status = :filled')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('filled', TranscriptStatus::Filled)
            ->getQuery()
            ->getArrayResult();

        $texts = [];
        foreach ($rows as $row) {
            $texts[(int) $row['entryId']] = $row['searchText'];
        }

        return $texts;
    }

    private function due(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('transcript')
            ->andWhere('transcript.status = :pending')
            ->andWhere('transcript.nextAttemptAt IS NULL OR transcript.nextAttemptAt <= :now')
            ->setParameter('pending', TranscriptStatus::Pending)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE);
    }
}
```

`andWhere()` with a bare `OR` is wrapped in parentheses by Doctrine's `Andx`; keep it, but if PHPStan's Doctrine extension complains, write `'(transcript.nextAttemptAt IS NULL OR transcript.nextAttemptAt <= :now)'`.

- [ ] **Step 8: Generate and hand-shape the migration**

Run `cd backend && bin/console doctrine:migrations:diff --no-interaction` against the MySQL Docker DB (`docker compose exec php bin/console doctrine:migrations:diff`) and again natively against SQLite, then merge both outputs into one `migrations/Version20261027120000.php` in the dual-dialect house style of `Version20261003100000.php` (`mysql()` check, `upMySql()`/`upSqlite()`, `downMySql()`/`downSqlite()`, `skipIf($schema->hasTable('entry_transcript'), …)`, `isTransactional(): false`). Keep the generated FK/unique index names exactly as the diff produced them. Description: `'Add entry_transcript: an episode\'s declared transcript, queued at ingest and filled later (#1809).'`. Delete the two generated files.

The MySQL table must come out as `search_text LONGTEXT`, `paragraphs JSON`, `UNIQUE INDEX … (entry_id)`, `INDEX idx_entry_transcript_claimable (status, next_attempt_at)`, `ON DELETE CASCADE`, `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB`.

- [ ] **Step 9: Verify the migration on both platforms**

Run: `cd backend && php bin/phpunit tests/Repository/EntryTranscriptRepositoryTest.php` — Expected: PASS.
Then migrate from empty and validate, both legs (CI does the same):

```bash
cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction --env=test && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate --env=test
```

and for MySQL apply it to the live Docker DB (memory: apply new migrations to the live Docker DB): `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction` then `docker compose exec php bin/console doctrine:schema:validate`. Expected: both `[OK] The database schema is in sync with the mapping files.` Delete `var/migrate-check.db`.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Enum/TranscriptFormat.php backend/src/Enum/TranscriptStatus.php backend/src/Entity/TranscriptParagraph.php backend/src/Entity/EntryTranscript.php backend/src/Repository/EntryTranscriptRepository.php backend/migrations/Version20261027120000.php backend/tests/Enum/TranscriptFormatTest.php backend/tests/Entity/EntryTranscriptTest.php backend/tests/Repository/EntryTranscriptRepositoryTest.php
git commit -m "feat(#1809): an episode's transcript has a row of its own, queued and claimable"
```

---

### Task 2: Parser — `DeclaredTranscripts`

**Files:**
- Create: `backend/src/Service/Parser/Model/ParsedTranscriptModel.php`, `backend/src/Service/Parser/Support/DeclaredTranscripts.php`
- Modify: `backend/src/Service/Parser/Model/ParsedMediaBundleModel.php`, `backend/src/Service/Parser/ItemMediaExtractor.php:38`
- Test: `backend/tests/Service/Parser/Support/DeclaredTranscriptsTest.php`

**Interfaces:**
- Consumes: `TranscriptFormat::fromMimeType()`, `->preference()` (Task 1)
- Produces: `ParsedTranscriptModel(string $url, TranscriptFormat $format, ?string $language)`; `DeclaredTranscripts::of(CoreElement $item): ?ParsedTranscriptModel`; `ParsedMediaBundleModel::$transcript` (`?ParsedTranscriptModel`, default null)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Enum\TranscriptFormat;
use App\Service\Parser\Support\DeclaredTranscripts;
use App\Tests\Support\FeedItemFixtures;
use PHPUnit\Framework\TestCase;

final class DeclaredTranscriptsTest extends TestCase
{
    use FeedItemFixtures;

    public function testAnItemWithoutTranscriptsDeclaresNone(): void
    {
        self::assertNull(DeclaredTranscripts::of($this->rssItem('<title>x</title>')));
    }

    public function testVttWinsOverEveryOtherTypeWhereverItStands(): void
    {
        $transcript = DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/ep.html" type="text/html"/>'
            . '<podcast:transcript url="https://pod.test/ep.json" type="application/json"/>'
            . '<podcast:transcript url=" https://pod.test/ep.vtt " type="text/vtt" language="de" rel="captions"/>',
        ));

        self::assertNotNull($transcript);
        self::assertSame('https://pod.test/ep.vtt', $transcript->url);
        self::assertSame(TranscriptFormat::Vtt, $transcript->format);
        self::assertSame('de', $transcript->language);
    }

    public function testATieGoesToTheFirstDeclared(): void
    {
        $transcript = DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/a.srt" type="application/x-subrip"/>'
            . '<podcast:transcript url="https://pod.test/b.srt" type="application/srt"/>',
        ));

        self::assertSame('https://pod.test/a.srt', $transcript?->url);
    }

    public function testUnknownTypesAndMissingUrlsAreIgnored(): void
    {
        self::assertNull(DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/ep.txt" type="text/plain"/>'
            . '<podcast:transcript url=" " type="text/vtt"/>',
        )));
    }

    public function testAnEmptyLanguageIsNone(): void
    {
        $transcript = DeclaredTranscripts::of($this->rssItem(
            '<podcast:transcript url="https://pod.test/ep.vtt" type="text/vtt" language=""/>',
        ));

        self::assertNull($transcript?->language);
    }
}
```

Check that `FeedItemFixtures::rssItem()` declares the `podcast` namespace (it does for `DeclaredChaptersTest`'s `podcast:chapters`).

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Parser/Support/DeclaredTranscriptsTest.php`
Expected: FAIL — class `DeclaredTranscripts` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Enum\TranscriptFormat;

/** The one transcript an item declared that ingest keeps. */
final readonly class ParsedTranscriptModel
{
    public function __construct(
        public string $url,
        public TranscriptFormat $format,
        public ?string $language,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\EntryTranscript;
use App\Enum\TranscriptFormat;
use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Model\ParsedTranscriptModel;
use App\Service\Parser\Pass\CoreElement;

final class DeclaredTranscripts
{
    public static function of(CoreElement $item): ?ParsedTranscriptModel
    {
        $best = null;
        foreach ($item->in(FeedNamespace::Podcast)->children('transcript') as $element) {
            $candidate = self::candidate($element);
            if ($candidate !== null && ($best === null || $candidate->format->preference() < $best->format->preference())) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function candidate(\DOMElement $element): ?ParsedTranscriptModel
    {
        $url = trim($element->getAttribute('url'));
        $format = TranscriptFormat::fromMimeType($element->getAttribute('type'));
        if ($url === '' || $format === null) {
            return null;
        }

        $language = mb_substr(trim($element->getAttribute('language')), 0, EntryTranscript::LANGUAGE_MAX_LENGTH);

        return new ParsedTranscriptModel($url, $format, $language === '' ? null : $language);
    }

    private function __construct()
    {
    }
}
```

`PersistenceKnowsNoServiceRule` only stops persistence from importing services; a parser reading an entity constant is fine. If `children()` is typed `iterable<\DOMElement>` this compiles as is; check its docblock.

In `ParsedMediaBundleModel` add the last constructor parameter `public ?ParsedTranscriptModel $transcript = null,`. In `ItemMediaExtractor::extract()` return `new ParsedMediaBundleModel($media, $attachments, DeclaredChapters::of($item), DeclaredTranscripts::of($item));` and import it.

- [ ] **Step 4: Run the parser tests**

Run: `cd backend && php bin/phpunit tests/Service/Parser`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Parser backend/tests/Service/Parser/Support/DeclaredTranscriptsTest.php
git commit -m "feat(#1809): an item's best declared transcript is parsed with its media"
```

---

### Task 3: Ingest queues a pending transcript

**Files:**
- Create: `backend/src/Service/Ingest/EntryTranscriptWriter.php`
- Modify: `backend/src/Service/Ingest/EntryIngestor.php` (constructor + after `$this->categoryWriter->attach($newPairs);`), `backend/tests/Support/EntryIngestors.php`
- Test: `backend/tests/Service/Ingest/EntryTranscriptWriterTest.php`, plus one case in the existing `EntryIngestor` integration test (find it: `grep -rln "EntryIngestors::" backend/tests/Service/Ingest`)

**Interfaces:**
- Consumes: `ParsedMediaBundleModel::$transcript`, `EntryTranscript` ctor (Tasks 1–2)
- Produces: `EntryTranscriptWriter::attach(list<array{0: Entry, 1: ParsedEntryModel}> $pairs): void` — persists, never flushes

- [ ] **Step 1: Write the failing test**

A kernel test (`KernelTestCase`) that ingests one RSS item with `<podcast:transcript url="https://pod.test/ep.vtt" type="text/vtt"/>` and one without, through `EntryIngestors::…` (copy how the existing ingestor test builds feed + parsed document), flushes, then asserts:

```php
$rows = $this->entityManager->getRepository(EntryTranscript::class)->findAll();
self::assertCount(1, $rows);
self::assertSame('https://pod.test/ep.vtt', $rows[0]->getUrl());
self::assertTrue($rows[0]->isPending());
```

and a second case: a `url="javascript:alert(1)"` or relative url yields no row (`AbsoluteHttpUrl` rejects it).

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Ingest/EntryTranscriptWriterTest.php`
Expected: FAIL — no rows (or class not found).

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Url\Support\AbsoluteHttpUrl;
use Doctrine\ORM\EntityManagerInterface;

/** Queues the transcript a newly created entry declared; TranscriptSweep fetches it. The caller flushes. */
final readonly class EntryTranscriptWriter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param list<array{0: Entry, 1: ParsedEntryModel}> $pairs */
    public function attach(array $pairs): void
    {
        foreach ($pairs as [$entry, $parsed]) {
            $this->attachOne($entry, $parsed);
        }
    }

    private function attachOne(Entry $entry, ParsedEntryModel $parsed): void
    {
        $declared = $parsed->media->mediaBundle?->transcript;
        $url = AbsoluteHttpUrl::orNullWithin($declared?->url, EntryTranscript::URL_MAX_LENGTH);
        if ($declared === null || $url === null) {
            return;
        }

        $this->entityManager->persist(new EntryTranscript($entry, $url, $declared->format, $declared->language));
    }
}
```

In `EntryIngestor`, add `private EntryTranscriptWriter $transcriptWriter,` as the last constructor parameter and call `$this->transcriptWriter->attach($newPairs);` right after the category writer. In `tests/Support/EntryIngestors.php` pass `new EntryTranscriptWriter($entityManager)` as the new last argument.

- [ ] **Step 4: Run the ingest and refresh suites**

Run: `cd backend && php bin/phpunit tests/Service/Ingest tests/Service/Refresh tests/Service/Subscription`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Ingest backend/tests/Support/EntryIngestors.php backend/tests/Service/Ingest/EntryTranscriptWriterTest.php
git commit -m "feat(#1809): ingest queues the transcript a new episode declares"
```

---

### Task 4: Reading transcript files into paragraphs

**Files:**
- Create: `backend/src/Service/Transcript/Model/TranscriptCueModel.php`, `Model/ReadTranscriptModel.php`, `Support/TimedCues.php`, `Support/JsonCues.php`, `Support/TranscriptParagraphs.php`, `TranscriptReader.php`, `Exception/TranscriptUnusableException.php`, `Exception/TranscriptUnreachableException.php` (all under `backend/src/Service/Transcript/`)
- Test: `backend/tests/Service/Transcript/Support/TimedCuesTest.php`, `JsonCuesTest.php`, `TranscriptParagraphsTest.php`, `backend/tests/Service/Transcript/TranscriptReaderTest.php`
- Fixtures: `backend/tests/Fixtures/Transcript/logbuch.vtt`, `darknet-diaries.vtt`, `lage-der-nation.vtt`, `changelog.html`, `sample.json`, `sample.srt`

**Interfaces:**
- Produces: `TranscriptCueModel(?int $startSeconds, ?string $speaker, string $text)`; `ReadTranscriptModel(list<TranscriptParagraph> $paragraphs, string $searchText)`; `TimedCues::of(string): list<TranscriptCueModel>`; `JsonCues::of(string): list<TranscriptCueModel>` (throws `TranscriptUnusableException`); `TranscriptParagraphs::from(list<TranscriptCueModel>): list<TranscriptParagraph>`, `::searchTextOf(list<TranscriptParagraph>): string`; `TranscriptReader::read(string $body, TranscriptFormat): ReadTranscriptModel` (throws `TranscriptUnusableException`); exceptions with named constructors `TranscriptUnusableException::empty()`, `::malformed(\JsonException)`, `::refused(string $url, \Throwable)`, `::answered(string $url, int $status)`; `TranscriptUnreachableException::from(string $url, \Throwable)`, `::answered(string $url, int $status)`.

- [ ] **Step 1: Capture the fixtures from the real feeds**

Fetch one current episode transcript from each measured feed (find the URLs in each feed's `<podcast:transcript>`: Logbuch Netzpolitik `https://logbuch-netzpolitik.de/feed/m4a`, Darknet Diaries `https://feeds.megaphone.fm/darknetdiaries`, Lage der Nation `https://feeds.lagedernation.org/feeds/ldn-mp3.xml`, Changelog `https://changelog.com/podcast/feed`), each into a new empty scratch directory, and trim each to its header plus the first ~12 cues (HTML: the first ~8 paragraphs). Keep them verbatim otherwise — speaker tags, BOMs, `NOTE` blocks and cue settings included. Write `sample.json` (podcast namespace JSON, 3 segments, two speakers, one consecutive same-speaker pair) and `sample.srt` (3 cues, one with `<i>` markup) by hand. Note in each test which real structures it covers.

- [ ] **Step 2: Write the failing support tests**

```php
final class TimedCuesTest extends TestCase
{
    public function testReadsVttCuesWithTheirVoices(): void
    {
        $cues = TimedCues::of("\u{FEFF}WEBVTT\n\nNOTE made by hand\n\n1\n00:00:01.500 --> 00:00:04.000 align:start\n<v Holger Klein>Hallo und</v>\nwillkommen.\n\n00:01:05.000 --> 00:01:07.000\n<v.loud Philip>Danke &amp; hallo!\n");

        self::assertEquals(
            [
                new TranscriptCueModel(1, 'Holger Klein', 'Hallo und willkommen.'),
                new TranscriptCueModel(65, 'Philip', 'Danke & hallo!'),
            ],
            $cues,
        );
    }

    public function testReadsShortVttTimingsWithoutHours(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(125, null, 'Zwei Minuten.')],
            TimedCues::of("WEBVTT\n\n02:05.000 --> 02:06.000\nZwei Minuten.\n"),
        );
    }

    public function testReadsSrtCuesAndDropsTheirMarkup(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(3661, null, 'Kursiv hier.')],
            TimedCues::of("1\r\n01:01:01,000 --> 01:01:02,000\r\n<i>Kursiv</i> hier.\r\n"),
        );
    }

    public function testACueWithoutTextIsSkipped(): void
    {
        self::assertSame([], TimedCues::of("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\n"));
    }

    public function testTheRealFixturesYieldTimedCues(): void
    {
        foreach (['logbuch.vtt', 'darknet-diaries.vtt', 'lage-der-nation.vtt', 'sample.srt'] as $fixture) {
            $cues = TimedCues::of((string) file_get_contents(__DIR__ . '/../../../Fixtures/Transcript/' . $fixture));

            self::assertNotSame([], $cues, $fixture);
            self::assertNotNull($cues[0]->startSeconds, $fixture);
        }
    }
}
```

```php
final class JsonCuesTest extends TestCase
{
    public function testReadsSegmentsWithSpeakersAndFlooredStarts(): void
    {
        self::assertEquals(
            [new TranscriptCueModel(0, 'Ann', 'Hi.'), new TranscriptCueModel(12, null, 'Yes.')],
            JsonCues::of('{"version":"1.0.0","segments":[{"speaker":"Ann","startTime":0.5,"endTime":2,"body":" Hi. "},'
                . '{"startTime":12.9,"body":"Yes."},{"startTime":13,"body":"  "}]}'),
        );
    }

    public function testMalformedJsonIsUnusable(): void
    {
        $this->expectException(TranscriptUnusableException::class);
        JsonCues::of('{"segments":');
    }

    public function testADocumentWithoutSegmentsHasNoCues(): void
    {
        self::assertSame([], JsonCues::of('{"chapters":[]}'));
    }
}
```

```php
final class TranscriptParagraphsTest extends TestCase
{
    public function testConsecutiveCuesOfOneSpeakerJoinAtTheFirstStart(): void
    {
        self::assertEquals(
            [new TranscriptParagraph(0, 'Ann', 'Hi. How are you?'), new TranscriptParagraph(9, 'Bob', 'Fine.')],
            TranscriptParagraphs::from([
                new TranscriptCueModel(0, 'Ann', 'Hi.'),
                new TranscriptCueModel(4, 'Ann', 'How are you?'),
                new TranscriptCueModel(9, 'Bob', 'Fine.'),
            ]),
        );
    }

    public function testCuesWithoutSpeakersStayTheirOwnParagraphs(): void
    {
        self::assertCount(2, TranscriptParagraphs::from([
            new TranscriptCueModel(0, null, 'One.'),
            new TranscriptCueModel(3, null, 'Two.'),
        ]));
    }

    public function testTheSearchTextNamesEachSpeakerOnce(): void
    {
        self::assertSame(
            "Ann: Hi.\nUntimed.",
            TranscriptParagraphs::searchTextOf([
                new TranscriptParagraph(0, 'Ann', 'Hi.'),
                new TranscriptParagraph(null, null, 'Untimed.'),
            ]),
        );
    }
}
```

- [ ] **Step 3: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Transcript`
Expected: FAIL — classes not found.

- [ ] **Step 4: Implement the models, exceptions and support classes**

```php
final readonly class TranscriptCueModel
{
    public function __construct(public ?int $startSeconds, public ?string $speaker, public string $text)
    {
    }
}
```

```php
final readonly class ReadTranscriptModel
{
    /** @param list<TranscriptParagraph> $paragraphs */
    public function __construct(public array $paragraphs, public string $searchText)
    {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\Exception;

/** The transcript will never be usable: refused, missing, too large or unreadable. Its row is failed for good. */
final class TranscriptUnusableException extends \RuntimeException
{
    public static function empty(): self
    {
        return new self('The transcript holds no text.');
    }

    public static function malformed(\JsonException $exception): self
    {
        return new self('The transcript is not valid JSON.', 0, $exception);
    }

    public static function refused(string $url, \Throwable $exception): self
    {
        return new self(\sprintf('The transcript at %s was refused: %s', $url, $exception->getMessage()), 0, $exception);
    }

    public static function answered(string $url, int $status): self
    {
        return new self(\sprintf('The transcript at %s answered %d.', $url, $status));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\Exception;

/** The transcript host failed for now; the row is retried after a backoff. */
final class TranscriptUnreachableException extends \RuntimeException
{
    public static function from(string $url, \Throwable $exception): self
    {
        return new self(\sprintf('The transcript at %s is unreachable: %s', $url, $exception->getMessage()), 0, $exception);
    }

    public static function answered(string $url, int $status): self
    {
        return new self(\sprintf('The transcript at %s answered %d; retried later.', $url, $status));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Service\Transcript\Model\TranscriptCueModel;

/** WebVTT and SubRip cues: a block's timing line starts it, the lines after it are its text. */
final class TimedCues
{
    private const string TIMING = '/^(?:(\d+):)?(\d{1,2}):(\d{2})[.,]\d{1,3}\s+-->/';
    private const string VOICE = '/^<v(?:\.[^\s>]*)?\s+([^>]+)>/';

    /** @return list<TranscriptCueModel> */
    public static function of(string $body): array
    {
        $cues = [];
        foreach (self::blocks($body) as $lines) {
            $cue = self::cueOf($lines);
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }

    /** @return list<list<string>> */
    private static function blocks(string $body): array
    {
        $normalized = str_replace(["\u{FEFF}", "\r\n", "\r"], ['', "\n", "\n"], $body);
        $blocks = preg_split('/\n{2,}/', trim($normalized)) ?: [];

        return array_map(static fn (string $block): array => explode("\n", $block), $blocks);
    }

    /** @param list<string> $lines */
    private static function cueOf(array $lines): ?TranscriptCueModel
    {
        foreach ($lines as $index => $line) {
            $start = self::startOf($line);
            if ($start !== null) {
                return self::cueAt($start, \array_slice($lines, $index + 1));
            }
        }

        return null;
    }

    private static function startOf(string $line): ?int
    {
        if (preg_match(self::TIMING, trim($line), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3];
    }

    /** @param list<string> $textLines */
    private static function cueAt(int $start, array $textLines): ?TranscriptCueModel
    {
        $raw = trim(implode(' ', $textLines));
        $speaker = preg_match(self::VOICE, $raw, $match) === 1 ? trim($match[1]) : null;
        $text = self::plain($raw);

        return $text === '' ? null : new TranscriptCueModel($start, $speaker === '' ? null : $speaker, $text);
    }

    private static function plain(string $markup): string
    {
        $text = html_entity_decode(strip_tags($markup), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function __construct()
    {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\Model\TranscriptCueModel;

/** The podcast namespace's JSON transcript: `segments[]` of `startTime`, `speaker`, `body`. */
final class JsonCues
{
    /**
     * @return list<TranscriptCueModel>
     *
     * @throws TranscriptUnusableException
     */
    public static function of(string $body): array
    {
        try {
            $document = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw TranscriptUnusableException::malformed($exception);
        }

        $segments = \is_array($document) && \is_array($document['segments'] ?? null) ? $document['segments'] : [];
        $cues = [];
        foreach ($segments as $segment) {
            $cue = \is_array($segment) ? self::cueOf($segment) : null;
            if ($cue !== null) {
                $cues[] = $cue;
            }
        }

        return $cues;
    }

    /** @param array<mixed> $segment */
    private static function cueOf(array $segment): ?TranscriptCueModel
    {
        $text = \is_string($segment['body'] ?? null) ? trim($segment['body']) : '';
        if ($text === '') {
            return null;
        }

        $start = $segment['startTime'] ?? null;
        $speaker = \is_string($segment['speaker'] ?? null) ? trim($segment['speaker']) : '';

        return new TranscriptCueModel(
            \is_int($start) || \is_float($start) ? (int) floor($start) : null,
            $speaker === '' ? null : $speaker,
            $text,
        );
    }

    private function __construct()
    {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\Support;

use App\Entity\TranscriptParagraph;
use App\Service\Transcript\Model\TranscriptCueModel;

final class TranscriptParagraphs
{
    /**
     * A speaker's consecutive cues are one paragraph, starting at the first; cues without a speaker stay apart.
     *
     * @param list<TranscriptCueModel> $cues
     *
     * @return list<TranscriptParagraph>
     */
    public static function from(array $cues): array
    {
        $paragraphs = [];
        $open = null;
        foreach ($cues as $cue) {
            if ($open !== null && $cue->speaker !== null && $cue->speaker === $open->speaker) {
                $open = new TranscriptParagraph($open->startSeconds, $open->speaker, $open->text . ' ' . $cue->text);
                continue;
            }
            if ($open !== null) {
                $paragraphs[] = $open;
            }
            $open = new TranscriptParagraph($cue->startSeconds, $cue->speaker, $cue->text);
        }
        if ($open !== null) {
            $paragraphs[] = $open;
        }

        return $paragraphs;
    }

    /** @param list<TranscriptParagraph> $paragraphs */
    public static function searchTextOf(array $paragraphs): string
    {
        return implode("\n", array_map(
            static fn (TranscriptParagraph $paragraph): string => $paragraph->speaker === null
                ? $paragraph->text
                : $paragraph->speaker . ': ' . $paragraph->text,
            $paragraphs,
        ));
    }

    private function __construct()
    {
    }
}
```

If PHPMD or the "functions do one thing" bar flags `from()`, extract the loop body into a small `Pass/ParagraphRun` per-call object holding `$open` and `$paragraphs`.

- [ ] **Step 5: Write the reader test, then the reader**

```php
final class TranscriptReaderTest extends TestCase
{
    public function testAVttBodyBecomesParagraphsAndSearchText(): void
    {
        $read = $this->reader()->read(
            "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\n<v Ann>Hi.\n\n00:00:01.000 --> 00:00:02.000\n<v Ann>Again.\n",
            TranscriptFormat::Vtt,
        );

        self::assertEquals([new TranscriptParagraph(0, 'Ann', 'Hi. Again.')], $read->paragraphs);
        self::assertSame('Ann: Hi. Again.', $read->searchText);
    }

    public function testAnHtmlBodyIsSanitizedIntoUntimedParagraphs(): void
    {
        $read = $this->reader()->read(
            '<p>First <b>words</b>.</p><script>alert(1)</script><p>Second.</p>',
            TranscriptFormat::Html,
        );

        self::assertEquals(
            [new TranscriptParagraph(null, null, 'First words.'), new TranscriptParagraph(null, null, 'Second.')],
            $read->paragraphs,
        );
    }

    public function testTheChangelogFixtureReadsAsHtml(): void
    {
        $body = (string) file_get_contents(__DIR__ . '/../../Fixtures/Transcript/changelog.html');

        self::assertNotSame([], $this->reader()->read($body, TranscriptFormat::Html)->paragraphs);
    }

    public function testABodyWithoutTextIsUnusable(): void
    {
        $this->expectException(TranscriptUnusableException::class);
        $this->reader()->read("WEBVTT\n\n", TranscriptFormat::Vtt);
    }

    private function reader(): TranscriptReader
    {
        return new TranscriptReader(new EntrySanitizer(new TrailingBlankRemover()));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Enum\TranscriptFormat;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\Support\PlainText;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\Model\ReadTranscriptModel;
use App\Service\Transcript\Model\TranscriptCueModel;
use App\Service\Transcript\Support\JsonCues;
use App\Service\Transcript\Support\TimedCues;
use App\Service\Transcript\Support\TranscriptParagraphs;

final readonly class TranscriptReader
{
    public function __construct(private EntrySanitizer $sanitizer)
    {
    }

    /** @throws TranscriptUnusableException */
    public function read(string $body, TranscriptFormat $format): ReadTranscriptModel
    {
        $paragraphs = TranscriptParagraphs::from($this->cuesOf($body, $format));
        if ($paragraphs === []) {
            throw TranscriptUnusableException::empty();
        }

        return new ReadTranscriptModel($paragraphs, TranscriptParagraphs::searchTextOf($paragraphs));
    }

    /**
     * @return list<TranscriptCueModel>
     *
     * @throws TranscriptUnusableException
     */
    private function cuesOf(string $body, TranscriptFormat $format): array
    {
        return match ($format) {
            TranscriptFormat::Vtt, TranscriptFormat::Srt => TimedCues::of($body),
            TranscriptFormat::Json => JsonCues::of($body),
            TranscriptFormat::Html => $this->htmlCues($body),
        };
    }

    /** @return list<TranscriptCueModel> */
    private function htmlCues(string $body): array
    {
        return array_map(
            static fn (string $line): TranscriptCueModel => new TranscriptCueModel(null, null, $line),
            PlainText::linesFromHtmlBlocks($this->sanitizer->sanitize($body)),
        );
    }
}
```

Check `EntrySanitizer::sanitize()`'s signature and `PlainText::linesFromHtmlBlocks()`'s return type (`list<string>`, blank lines dropped?) before relying on them; filter empty lines if it keeps them. Copy the `EntrySanitizer` construction from an existing test (`grep -rn "new EntrySanitizer(" backend/tests | head -1`).

- [ ] **Step 6: Run the transcript tests**

Run: `cd backend && php bin/phpunit tests/Service/Transcript`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Transcript backend/tests/Service/Transcript backend/tests/Fixtures/Transcript
git commit -m "feat(#1809): VTT, SRT, JSON and HTML transcripts read into speaker paragraphs"
```

---

### Task 5: Guarded fetch and the filler

**Files:**
- Modify: `backend/src/Service/Fetch/Model/ResponseSizeLimit.php` (add `case TranscriptFile = 1_000;` after `EmbedDescription`)
- Create: `backend/src/Service/Transcript/TranscriptSource/TranscriptSourceInterface.php`, `GuardedTranscriptSource.php`, `backend/src/Service/Transcript/TranscriptFiller.php`
- Test: `backend/tests/Service/Transcript/TranscriptSource/GuardedTranscriptSourceTest.php`, `backend/tests/Service/Transcript/TranscriptFillerTest.php`

**Interfaces:**
- Consumes: `TranscriptReader::read()`, exceptions (Task 4); `EntryTranscript::fill/fail/retryAfter` (Task 1)
- Produces: `TranscriptSourceInterface::bodyAt(string $url, float $budgetSeconds): string` (throws both exceptions); `TranscriptFiller::fill(EntryTranscript $transcript, float $budgetSeconds): void` (mutates, never flushes)

- [ ] **Step 1: Write the failing source test**

Copy `GuardedChapterFileSourceTest` (same `client()` helper with `StaticDnsResolver` and `FetchWiring::guardedApiClient`) and adapt:

```php
public function testReturnsTheBodyOfAnAnsweredFile(): void
{
    $source = new GuardedTranscriptSource($this->client(new MockHttpClient(new MockResponse("WEBVTT\n"))));

    self::assertSame("WEBVTT\n", $source->bodyAt('https://cdn.test/ep.vtt', 10.0));
}

#[DataProvider('refusedAnswers')]
public function testANonTransientErrorAnswerIsUnusable(int $status): void
{
    $source = new GuardedTranscriptSource($this->client(new MockHttpClient(new MockResponse('', [
        'http_code' => $status,
        'response_headers' => ['Location' => 'https://elsewhere.test/ep.vtt'],
    ]))));

    $this->expectException(TranscriptUnusableException::class);
    $source->bodyAt('https://cdn.test/ep.vtt', 10.0);
}

/** @return iterable<string, array{int}> */
public static function refusedAnswers(): iterable
{
    yield 'not found' => [Response::HTTP_NOT_FOUND];
    yield 'gone' => [Response::HTTP_GONE];
    yield 'redirect' => [Response::HTTP_MOVED_PERMANENTLY];
}

#[DataProvider('transientAnswers')]
public function testATransientAnswerIsUnreachable(int $status): void { /* as in the chapter test, expecting TranscriptUnreachableException */ }

public function testTheFetchCarriesTheGivenBudget(): void { /* as in the chapter test, asserting timeout and max_duration are 10.0 */ }

public function testAFileAtTheSizeLimitIsRead(): void
{
    $body = str_pad("WEBVTT\n", ResponseSizeLimit::TranscriptFile->bytes(), ' ');
    $source = new GuardedTranscriptSource($this->client(new MockHttpClient(new MockResponse($body))));

    self::assertSame($body, $source->bodyAt('https://cdn.test/ep.vtt', 10.0));
}

public function testAFileBeyondTheSizeLimitIsUnusable(): void { /* bytes() + 1, expecting TranscriptUnusableException */ }

public function testABlockedHostIsUnusable(): void { /* http://127.0.0.1/ep.vtt, expecting TranscriptUnusableException */ }

public function testANetworkFailureIsUnreachable(): void { /* MockResponse('', ['error' => 'Connection reset']) */ }
```

Write every elided body out in full, the same way the chapter test does — the comments above name exactly what each asserts.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Transcript/TranscriptSource`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the source**

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\TranscriptSource;

use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\Exception\TranscriptUnusableException;

interface TranscriptSourceInterface
{
    /**
     * @throws TranscriptUnusableException
     * @throws TranscriptUnreachableException
     */
    public function bodyAt(string $url, float $budgetSeconds): string;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\TranscriptSource;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiBudgetModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\Exception\TranscriptUnusableException;

final readonly class GuardedTranscriptSource implements TranscriptSourceInterface
{
    public function __construct(private GuardedApiClient $api)
    {
    }

    public function bodyAt(string $url, float $budgetSeconds): string
    {
        try {
            $response = $this->api->sendWithin(
                ApiRequestModel::get($url, ['Accept' => '*/*']),
                new ApiBudgetModel($budgetSeconds, $budgetSeconds, ResponseSizeLimit::TranscriptFile),
            );
        } catch (SsrfBlockedException | ResponseTooLargeException $exception) {
            throw TranscriptUnusableException::refused($url, $exception);
        } catch (FetchException $exception) {
            throw TranscriptUnreachableException::from($url, $exception);
        }

        if ($response->isTransientFailure()) {
            throw TranscriptUnreachableException::answered($url, $response->status);
        }
        if (!$response->isSuccessful()) {
            throw TranscriptUnusableException::answered($url, $response->status);
        }

        return $response->body;
    }
}
```

Read `GuardedApiClient::sendWithin()` first. If it forces `Accept: application/json` and ignores the request's header, drop the header argument and note it in the commit message; a VTT host ignoring Accept is the norm.

- [ ] **Step 4: Write the failing filler test**

Use a hand-written `TranscriptSourceInterface` double in the test (an anonymous class returning a body or throwing), a real `TranscriptReader`, `NaiveUtcClock` from a `MockClock` the way other tests build it (`grep -rn "new NaiveUtcClock(" backend/tests | head -1`), and `Psr\Log\NullLogger`:

```php
public function testAReadableBodyFillsTheRow(): void
{
    $transcript = $this->transcript();

    $this->filler(static fn (): string => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo.\n")->fill($transcript, 10.0);

    self::assertTrue($transcript->isFilled());
    self::assertSame('Hallo.', $transcript->getSearchText());
}

public function testAnUnusableAnswerFailsTheRow(): void
{
    $transcript = $this->transcript();

    $this->filler(static fn (): never => throw TranscriptUnusableException::answered('u', Response::HTTP_NOT_FOUND))
        ->fill($transcript, 10.0);

    self::assertTrue($transcript->isFailed());
}

public function testAnUnreadableBodyFailsTheRow(): void
{
    $transcript = $this->transcript();

    $this->filler(static fn (): string => "WEBVTT\n")->fill($transcript, 10.0);

    self::assertTrue($transcript->isFailed());
}

public function testAnUnreachableHostBacksTheRowOff(): void
{
    $transcript = $this->transcript();

    $this->filler(static fn (): never => throw TranscriptUnreachableException::answered('u', Response::HTTP_SERVICE_UNAVAILABLE))
        ->fill($transcript, 10.0);

    self::assertTrue($transcript->isPending());
    self::assertEquals($this->now()->modify('+30 minutes'), $transcript->getNextAttemptAt());
}

public function testTheBudgetReachesTheSource(): void
{
    $seen = null;
    $this->filler(static function (string $url, float $budget) use (&$seen): string {
        $seen = $budget;

        return "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo.\n";
    })->fill($this->transcript(), 3.0);

    self::assertSame(3.0, $seen);
}
```

- [ ] **Step 5: Implement the filler**

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\EntryTranscript;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Transcript\Exception\TranscriptUnusableException;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use Psr\Log\LoggerInterface;

/** Fills one queued transcript, or fails it for good, or backs it off. The caller flushes. */
final readonly class TranscriptFiller
{
    public function __construct(
        private TranscriptSourceInterface $source,
        private TranscriptReader $reader,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function fill(EntryTranscript $transcript, float $budgetSeconds): void
    {
        try {
            $read = $this->reader->read(
                $this->source->bodyAt($transcript->getUrl(), $budgetSeconds),
                $transcript->getFormat(),
            );
        } catch (TranscriptUnusableException $exception) {
            $transcript->fail();
            $this->logger->info('Transcript unusable; not retried.', $this->contextOf($transcript, $exception));

            return;
        } catch (TranscriptUnreachableException $exception) {
            $transcript->retryAfter($this->clock->now());
            $this->logger->info('Transcript unreachable; retried later.', $this->contextOf($transcript, $exception));

            return;
        }

        $transcript->fill($read->paragraphs, $read->searchText);
    }

    /** @return array{url: string, exception: \Throwable} */
    private function contextOf(EntryTranscript $transcript, \Throwable $exception): array
    {
        return ['url' => $transcript->getUrl(), 'exception' => $exception];
    }
}
```

Check `NaiveUtcClock::now()` exists with that name (it is used that way in `ShareEnrichmentQueue`).

- [ ] **Step 6: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/Transcript`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Fetch/Model/ResponseSizeLimit.php backend/src/Service/Transcript backend/tests/Service/Transcript
git commit -m "feat(#1809): a queued transcript is fetched within 1 MB and filled, failed or backed off"
```

---

### Task 6: `EntryIndexer` reads transcript texts; Meilisearch indexes them

**Files:**
- Create: `backend/src/Service/Search/TranscriptText/TranscriptTextInterface.php`, `backend/tests/Support/NoTranscriptTexts.php`
- Modify: `backend/src/Repository/EntryTranscriptRepository.php` (`implements TranscriptTextInterface`), `backend/src/Service/Search/EntryIndexer.php`, `backend/src/Service/Search/Index/Model/IndexedEntryModel.php`, `backend/src/Service/Search/Index/SearchIndexReader/MeilisearchIndex.php` (`SETTINGS` and `documentOf()`), `backend/src/Command/SearchReindexCommand.php`, `backend/src/Repository/EntryRepository.php:99` (comment names the old static), every test with `new EntryIndexer(` (24 sites: `grep -rln "new EntryIndexer(" backend/tests`), every test with `new IndexedEntryModel(`
- Test: `backend/tests/Service/Search/EntryIndexerTest.php`, `backend/tests/Service/Search/Index/SearchIndexReader/MeilisearchIndexTest.php`

**Interfaces:**
- Produces: `TranscriptTextInterface::searchTextsFor(list<int> $entryIds): array<int, string>`; `EntryIndexer::__construct(SearchIndexWriterInterface, TranscriptTextInterface, LoggerInterface)`; `EntryIndexer::toIndexedEntries(list<Entry>): list<IndexedEntryModel>` (now an instance method); `IndexedEntryModel::$transcript` (`?string`, after `$content`)

- [ ] **Step 1: Write the failing tests**

In `EntryIndexerTest` add: indexing an entry whose id the transcript double maps to `'Ann: Hallo'` upserts an `IndexedEntryModel` with `transcript: 'Ann: Hallo'`, and one it does not map carries `transcript: null`. In `MeilisearchIndexTest` add: the pushed settings list `['title', 'summary', 'content', 'transcript', 'feedTitle']` as searchable attributes, and an upserted document carries the `transcript` key.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Search/EntryIndexerTest.php tests/Service/Search/Index`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\TranscriptText;

interface TranscriptTextInterface
{
    /**
     * @param list<int> $entryIds
     *
     * @return array<int, string> the filled transcripts' search text, by entry id
     */
    public function searchTextsFor(array $entryIds): array;
}
```

`EntryIndexer`: add `private readonly TranscriptTextInterface $transcriptTexts,` between `$index` and `$logger`; make `toIndexedEntries()` an instance method that loads texts once per batch:

```php
/**
 * The mapping alone, with no engine call and nothing swallowed: app:search:reindex shares it but must see
 * SearchEngineUnavailableException, so it cannot reuse index().
 *
 * @param list<Entry> $entries
 *
 * @return list<IndexedEntryModel>
 */
public function toIndexedEntries(array $entries): array
{
    $texts = $this->transcriptTexts->searchTextsFor(
        array_map(static fn (Entry $entry): int => $entry->requireId(), $entries),
    );

    return array_map(
        static fn (Entry $entry): IndexedEntryModel => self::toIndexedEntry($entry, $texts[$entry->requireId()] ?? null),
        $entries,
    );
}
```

and `toIndexedEntry(Entry $entry, ?string $transcript)` passes `transcript: $transcript` after `content:`. `index()` calls `$this->toIndexedEntries($entries)`.

`IndexedEntryModel`: add `public ?string $transcript,` after `$content`. `MeilisearchIndex`: `'searchableAttributes' => ['title', 'summary', 'content', 'transcript', 'feedTitle']`, `'transcript' => $entry->transcript` in `documentOf()`, and `transcript: ?string` in its array-shape docblock.

`SearchReindexCommand`: inject `private readonly EntryIndexer $indexer,` (before `$batchSize`) and call `$this->indexer->toIndexedEntries($batch)`. Update the `EntryRepository::entriesAfterId` docblock to say `EntryIndexer::toIndexedEntries()` (drop nothing else).

`EntryTranscriptRepository`: `final class EntryTranscriptRepository extends ServiceEntityRepository implements TranscriptTextInterface`. Symfony autowires the interface to its one implementation; if the container complains, alias it in `config/services.yaml` next to the other consumer-owned interfaces.

`tests/Support/NoTranscriptTexts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Search\TranscriptText\TranscriptTextInterface;

final readonly class NoTranscriptTexts implements TranscriptTextInterface
{
    public function searchTextsFor(array $entryIds): array
    {
        return [];
    }
}
```

Pass `new NoTranscriptTexts()` at every `new EntryIndexer(` test site; add `transcript: null` at every `new IndexedEntryModel(` test site.

- [ ] **Step 4: Run the whole suite** (the constructor change touches many tests)

Run: `cd backend && composer test:parallel`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1809): the search index carries each episode's transcript"
```

---

### Task 7: `TranscriptSweep`, worker firing, maintenance tick

**Files:**
- Create: `backend/src/Service/Transcript/Model/TranscriptBudgetModel.php`, `Model/TranscriptSweepReportModel.php`, `backend/src/Service/Transcript/TranscriptSweep.php`, `backend/src/Service/Worker/Message/CompleteQueuedTranscripts.php`, `backend/src/Service/Worker/Handler/CompleteQueuedTranscriptsHandler.php`, `backend/src/Service/Maintenance/FollowUpFetchSweeps.php`, `backend/src/Service/Maintenance/Model/FollowUpFetchesReportModel.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php` (append last), `backend/src/Service/Maintenance/MaintenanceTick.php`, `backend/src/Service/Maintenance/Model/MaintenanceSweepsModel.php`, `backend/src/Http/MaintenanceTickJson.php`, their tests (`grep -rln "LinkCardSweep\|MaintenanceSweepsModel\|MaintenanceTick(" backend/tests`)
- Test: `backend/tests/Service/Transcript/TranscriptSweepTest.php`, `backend/tests/Service/Transcript/Model/TranscriptBudgetModelTest.php`, `backend/tests/Service/Maintenance/FollowUpFetchSweepsTest.php`

**Interfaces:**
- Consumes: `EntryTranscriptRepository::findClaimable/claim` (Task 1), `TranscriptFiller::fill` (Task 5), `EntryIndexer::index` (Task 6)
- Produces: `TranscriptBudgetModel::fetches(int)`, `::fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches)`, `->isEmpty()`, `public int $fetches`, `const float FETCH_SECONDS = 4.0`; `TranscriptSweep::sweep(TranscriptBudgetModel): TranscriptSweepReportModel(int $claimed, int $filled)` with `none()` and `toLogContext()`; `FollowUpFetchSweeps::sweep(\DateTimeImmutable $deadline): FollowUpFetchesReportModel(LinkCardSweepReportModel $linkCards, TranscriptSweepReportModel $transcripts)` with `none()`; `MaintenanceSweepsModel::$followUpFetches` replaces `$linkCards`

- [ ] **Step 1: Write the failing budget and sweep tests**

Budget: `fetches(-1)` throws `\InvalidArgumentException`; `fittingBefore(now + 9 s, now, 5)` gives 2 fetches (9 / 4.0 floored); `fittingBefore(now + 3 s, now, 5)` is empty; `fittingBefore(now + 60 s, now, 2)` gives 2.

Sweep (kernel test, real repository, a `TranscriptSourceInterface` double set in the container like `EntryChaptersControllerTest` sets `ChapterFileSourceInterface`, and a recording `SearchIndexWriterInterface` double):

```php
public function testFillsTheNewestDueRowsWithinTheBudgetAndReindexesThem(): void
{
    [$older, $newer] = $this->seedPendingTranscripts(2);
    $this->source->answer("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo.\n");

    $report = $this->sweep->sweep(TranscriptBudgetModel::fetches(1));

    self::assertSame(1, $report->claimed);
    self::assertSame(1, $report->filled);
    self::assertSame('filled', $this->statusOf($newer));
    self::assertSame('pending', $this->statusOf($older));
    self::assertSame([$newer->getEntry()->requireId()], $this->indexWriter->upsertedIds());
}

public function testAFailedFetchIsFlushedButNotReindexed(): void
{
    [$row] = $this->seedPendingTranscripts(1);
    $this->source->refuse();

    $report = $this->sweep->sweep(TranscriptBudgetModel::fetches(5));

    self::assertSame(0, $report->filled);
    self::assertSame('failed', $this->statusOf($row));
    self::assertSame([], $this->indexWriter->upsertedIds());
}

public function testAnEmptyBudgetTouchesNothing(): void
{
    $this->seedPendingTranscripts(1);

    self::assertEquals(TranscriptSweepReportModel::none(), $this->sweep->sweep(TranscriptBudgetModel::fetches(0)));
}
```

`statusOf()` reads `SELECT status FROM entry_transcript WHERE id = ?` through the connection — not through the entity.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Transcript`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement budget, report, sweep**

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\Model;

/** How many transcript fetches one pass may spend, each allowed FETCH_SECONDS. */
final readonly class TranscriptBudgetModel
{
    public const float FETCH_SECONDS = 4.0;

    private function __construct(public int $fetches)
    {
    }

    public static function fetches(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A transcript budget cannot be negative.');
        }

        return new self($count);
    }

    /** The whole fetches that end before $deadline, at most $maxFetches. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::fetches(min($maxFetches, (int) floor(max(0, $remaining) / self::FETCH_SECONDS)));
    }

    public function isEmpty(): bool
    {
        return $this->fetches === 0;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript\Model;

final readonly class TranscriptSweepReportModel
{
    public function __construct(public int $claimed, public int $filled)
    {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    /** @return array{claimed: int, filled: int} */
    public function toLogContext(): array
    {
        return ['claimed' => $this->claimed, 'filled' => $this->filled];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Repository\EntryTranscriptRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Search\EntryIndexer;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;
use Doctrine\ORM\EntityManagerInterface;

/** Fetches the newest queued transcripts that are due, then indexes the episodes that gained one. */
final readonly class TranscriptSweep
{
    public function __construct(
        private EntryTranscriptRepository $transcripts,
        private TranscriptFiller $filler,
        private EntityManagerInterface $entityManager,
        private EntryIndexer $indexer,
        private NaiveUtcClock $clock,
    ) {
    }

    public function sweep(TranscriptBudgetModel $budget): TranscriptSweepReportModel
    {
        if ($budget->isEmpty()) {
            return TranscriptSweepReportModel::none();
        }

        $now = $this->clock->now();
        $claimed = $this->transcripts->claim($this->transcripts->findClaimable($now, $budget->fetches), $now);
        foreach ($claimed as $transcript) {
            $this->filler->fill($transcript, TranscriptBudgetModel::FETCH_SECONDS);
        }
        $this->entityManager->flush();

        $filled = array_values(array_filter(
            $claimed,
            static fn (EntryTranscript $transcript): bool => $transcript->isFilled(),
        ));
        $this->indexer->index(array_map(static fn (EntryTranscript $transcript): Entry => $transcript->getEntry(), $filled));

        return new TranscriptSweepReportModel(\count($claimed), \count($filled));
    }
}
```

- [ ] **Step 4: Worker message and handler; schedule**

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

final readonly class CompleteQueuedTranscripts
{
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\TranscriptSweep;
use App\Service\Worker\Message\CompleteQueuedTranscripts;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteQueuedTranscriptsHandler
{
    private const int FETCHES = 5;

    public function __construct(
        private TranscriptSweep $transcriptSweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteQueuedTranscripts $message): void
    {
        $report = $this->transcriptSweep->sweep(TranscriptBudgetModel::fetches(self::FETCHES));
        $this->logger->info('Worker transcript sweep finished.', ['report' => $report->toLogContext()]);
    }
}
```

In `WorkerSchedule::getSchedule()` append **last** (the docblock says why): `->add(RecurringMessage::every('1 minute', new CompleteQueuedTranscripts()))`. Check the messenger routing config (`config/packages/messenger.yaml`) routes `App\Service\Worker\Message\*` as a group; if messages are listed one by one, add this one beside `CompleteQueuedLinkCards`. Copy `CompleteQueuedLinkCardsHandler`'s test into one for the new handler.

- [ ] **Step 5: The tick runs link cards and transcripts through one service**

```php
<?php

declare(strict_types=1);

namespace App\Service\Maintenance\Model;

use App\Service\LinkCard\Model\LinkCardSweepReportModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;

/** The tick's sweeps that fetch what ingest left to come: link cards and transcripts. */
final readonly class FollowUpFetchesReportModel
{
    public function __construct(
        public LinkCardSweepReportModel $linkCards,
        public TranscriptSweepReportModel $transcripts,
    ) {
    }

    public static function none(): self
    {
        return new self(LinkCardSweepReportModel::none(), TranscriptSweepReportModel::none());
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\LinkCard\LinkCardSweep;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Maintenance\Model\FollowUpFetchesReportModel;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\TranscriptSweep;
use Symfony\Component\Clock\ClockInterface;

/** Link cards first, then transcripts, each in what is left before the tick's deadline. */
final readonly class FollowUpFetchSweeps
{
    private const int LINK_CARD_MAX_FETCHES = 2;
    private const int TRANSCRIPT_MAX_FETCHES = 2;

    public function __construct(
        private LinkCardSweep $linkCardSweep,
        private TranscriptSweep $transcriptSweep,
        private ClockInterface $clock,
    ) {
    }

    public function sweep(\DateTimeImmutable $deadline): FollowUpFetchesReportModel
    {
        $linkCards = $this->linkCardSweep->sweep(
            LinkCardBudgetModel::fittingBefore($deadline, $this->clock->now(), self::LINK_CARD_MAX_FETCHES),
        );
        $transcripts = $this->transcriptSweep->sweep(
            TranscriptBudgetModel::fittingBefore($deadline, $this->clock->now(), self::TRANSCRIPT_MAX_FETCHES),
        );

        return new FollowUpFetchesReportModel($linkCards, $transcripts);
    }
}
```

`MaintenanceTick`: replace `private LinkCardSweep $linkCardSweep` with `private FollowUpFetchSweeps $followUpFetchSweeps`, delete `LINK_CARD_MAX_FETCHES`, and in `sweep()` replace the link-card call with `$followUpFetches = $this->followUpFetchSweeps->sweep($deadline);` passed where `$linkCards` was. `MaintenanceSweepsModel`: `public FollowUpFetchesReportModel $followUpFetches` in place of `$linkCards`, and `FollowUpFetchesReportModel::none()` in `skippedAfterAbortedRefresh()`. `MaintenanceTickJson`: keep the `linkCards` key reading `$sweeps->followUpFetches->linkCards`, and add after it:

```php
'transcripts' => self::markedIfSkipped($sweeps, self::transcripts($sweeps->followUpFetches->transcripts)),
```

with

```php
/** @return array{claimed: int, filled: int} */
private static function transcripts(TranscriptSweepReportModel $report): array
{
    return ['claimed' => $report->claimed, 'filled' => $report->filled];
}
```

(and the matching entry in the method's return-shape docblock). Write `FollowUpFetchSweepsTest` with doubles of both sweeps' collaborators, or a kernel test, asserting that a deadline 9 s away gives the transcript sweep 2 fetches after the link cards spent none. Update the existing tick/JSON tests for the new field and the new `transcripts` key.

- [ ] **Step 6: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/Transcript tests/Service/Maintenance tests/Service/Worker tests/Http tests/Controller/MaintenanceControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1809): the worker and the maintenance tick fetch queued transcripts"
```

---

### Task 8: The entry detail serves the transcript

**Files:**
- Create: `backend/src/Service/Transcript/TranscriptOnOpen.php`, `backend/src/Http/EntryTranscriptJson.php`
- Modify: `backend/src/Http/EntryJson.php` (`EntryRow` shape + `detail()`), `backend/src/Controller/Api/EntryDetailController.php`
- Test: `backend/tests/Controller/Api/EntryTranscriptControllerTest.php`, `backend/tests/Service/Transcript/TranscriptOnOpenTest.php` (only if a case is not reachable through the functional test)

**Interfaces:**
- Consumes: `EntryTranscriptRepository::findForEntry`, `TranscriptFiller::fill`, `EntryIndexer::index`
- Produces: `TranscriptOnOpen::serve(Entry): ?EntryTranscript` (filled or null); `EntryTranscriptJson::of(?EntryTranscript): array{language: string|null, paragraphs: list<array{startSeconds: int|null, speaker: string|null, text: string}>}|null`; `EntryJson::detail(EntryListRow, ServedChaptersModel, ?EntryTranscript)`; response key `entry.transcript`

- [ ] **Step 1: Write the failing functional test**

Copy `EntryChaptersControllerTest`'s `auth()`, `seedEntry()` and `entryOf()` helpers; `seedEntry()` gains an optional `?EntryTranscript` seeding step (persist `new EntryTranscript($entry, 'https://pod.test/ep.vtt', TranscriptFormat::Vtt, 'de')`, optionally `fill()`ed or `fail()`ed). Cases:

```php
public function testAFilledTranscriptIsServed(): void
{
    // seed filled with [new TranscriptParagraph(0, 'Ann', 'Hi.')]
    // GET /api/entries/$id
    self::assertSame(
        ['language' => 'de', 'paragraphs' => [['startSeconds' => 0, 'speaker' => 'Ann', 'text' => 'Hi.']]],
        $this->entryOf($client)['transcript'],
    );
}

public function testAnEntryWithoutATranscriptSendsNull(): void { /* no row → 'transcript' === null */ }

public function testAFailedTranscriptSendsNull(): void { /* fail()ed row → null, and the source double expects never() */ }

public function testAPendingTranscriptIsFetchedOnOpenAndStored(): void
{
    // $client->disableReboot(); pending row; container TranscriptSourceInterface double expects once() and
    // returns a VTT body; request twice; second response still carries the paragraphs.
}

public function testAPendingTranscriptThatCannotBeFetchedSendsNullAndStaysPending(): void
{
    // source double throws TranscriptUnreachableException; 'transcript' === null;
    // SELECT status FROM entry_transcript → 'pending'
}
```

Write each body out in full. Check the existing `EntryChaptersControllerTest` still passes with the extra key.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryTranscriptControllerTest.php`
Expected: FAIL — undefined index `transcript`.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Repository\EntryTranscriptRepository;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;

/** The transcript an opened entry shows. Opening fetches one the sweep has not reached yet, within a short budget. */
final readonly class TranscriptOnOpen
{
    private const float BUDGET_SECONDS = 3.0;

    public function __construct(
        private EntryTranscriptRepository $transcripts,
        private TranscriptFiller $filler,
        private EntityManagerInterface $entityManager,
        private EntryIndexer $indexer,
    ) {
    }

    public function serve(Entry $entry): ?EntryTranscript
    {
        $transcript = $this->transcripts->findForEntry($entry);
        if ($transcript !== null && $transcript->isPending()) {
            $this->fillNow($transcript);
        }

        return $transcript?->isFilled() === true ? $transcript : null;
    }

    private function fillNow(EntryTranscript $transcript): void
    {
        $this->filler->fill($transcript, self::BUDGET_SECONDS);
        $this->entityManager->flush();
        if ($transcript->isFilled()) {
            $this->indexer->index([$transcript->getEntry()]);
        }
    }
}
```

A pending row that is still inside a sweep's lease is fetched here too; both write the same transcript (see `EntryTranscriptRepository::claim()`), so this needs no claim.

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryTranscript;
use App\Entity\TranscriptParagraph;

final class EntryTranscriptJson
{
    /**
     * @return array{
     *     language: string|null,
     *     paragraphs: list<array{startSeconds: int|null, speaker: string|null, text: string}>,
     * }|null
     */
    public static function of(?EntryTranscript $transcript): ?array
    {
        if ($transcript === null) {
            return null;
        }

        return [
            'language' => $transcript->getLanguage(),
            'paragraphs' => array_map(
                static fn (TranscriptParagraph $paragraph): array => $paragraph->jsonSerialize(),
                $transcript->getParagraphs(),
            ),
        ];
    }

    private function __construct()
    {
    }
}
```

`EntryJson`: in the `EntryRow` phpstan type add after `chapters?:` the line
`transcript?: array{language: string|null, paragraphs: list<array{startSeconds: int|null, speaker: string|null, text: string}>}|null,`
and change `detail()`:

```php
public function detail(EntryListRow $row, ServedChaptersModel $served, ?EntryTranscript $transcript): array
{
    return $this->listRow($row) + [
        'contentHtml' => $served->contentHtml,
        'chapters' => ChaptersJson::of($served->chapters),
        'transcript' => EntryTranscriptJson::of($transcript),
    ];
}
```

Update the class docblock's first sentence if it enumerates what `detail()` adds. `EntryDetailController`: inject `private TranscriptOnOpen $transcripts,` and pass `$this->transcripts->serve($row->entry)` as `detail()`'s third argument. Find every other `->detail(` caller (`grep -rn -- "->detail(" backend/src backend/tests`) and pass `null` or the served transcript as fits.

- [ ] **Step 4: Run the controller tests**

Run: `cd backend && php bin/phpunit tests/Controller/Api tests/Http`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1809): an opened episode serves its transcript, fetching a queued one on the spot"
```

---

### Task 9: The LIKE fallback matches transcript text

**Files:**
- Modify: `backend/src/Repository/SearchTermsPredicateBuilder.php`
- Test: `backend/tests/Repository/EntrySearchTest.php`, `backend/tests/Repository/DatabaseSavedSearchMatcherTest.php`

**Interfaces:**
- Consumes: `EntryTranscript` mapping (`entry`, `searchText`)
- Produces: unchanged signature `build(QueryBuilder $qb, SearchTermsModel $terms, string $prefix, string $entryAlias = 'e'): string`

- [ ] **Step 1: Write the failing tests**

In `EntrySearchTest` (which drives the LIKE path — check its setup), add an entry whose title and summary lack "Zwiebelring" but whose filled transcript's `searchText` is `"Ann: Ein Zwiebelring, bitte."`, plus a control entry with a *pending* transcript row (no `searchText`). Cases:
- substring `wiebel` finds the transcript entry only;
- whole-word `Zwiebelring` finds it; whole-word `Zwiebel` does not;
- a two-term search `Zwiebelring Ann` (both in the transcript) finds it; `Zwiebelring Nothere` does not;
- a term in the title still matches an entry without any transcript row (the `EXISTS` must not turn the OR into an inner join).

In `DatabaseSavedSearchMatcherTest`, add one case: a saved search for `Zwiebelring` flags a newly ingested entry whose transcript matches.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Repository/EntrySearchTest.php tests/Repository/DatabaseSavedSearchMatcherTest.php`
Expected: FAIL — the transcript entry is not found.

- [ ] **Step 3: Implement**

Replace the class body with a per-term column matcher applied to three places:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\WordBoundaries;
use App\Entity\EntryTranscript;
use App\Service\Search\Model\SearchTermsModel;
use App\Service\Search\Support\LikePattern;
use Doctrine\ORM\QueryBuilder;

/**
 * One search's terms compiled into a predicate over title, summary and transcript text, and bound onto the
 * QueryBuilder it is handed. Needs no repository state of its own.
 */
final readonly class SearchTermsPredicateBuilder
{
    /**
     * The terms as one ANDed expression the caller places, since DatabaseSavedSearchMatcher ORs several of them.
     * $prefix keys the bound parameters, so two searches sharing a word cannot overwrite each other's value.
     */
    public function build(QueryBuilder $qb, SearchTermsModel $terms, string $prefix, string $entryAlias = 'e'): string
    {
        $predicates = [];
        foreach ($terms->terms as $position => $term) {
            $parameter = $prefix . $position;
            $columnMatch = $terms->isWholeWord
                ? $this->wholeWordMatch($qb, $parameter, $term)
                : $this->substringMatch($qb, $parameter, $term);
            $predicates[] = self::anyColumn($columnMatch, $parameter, $entryAlias);
        }

        return '(' . implode(' AND ', $predicates) . ')';
    }

    /**
     * A summary is nullable and an entry may have no transcript row, and NULL LIKE … is never true, so the ORs alone
     * handle both. The transcript is reached by a correlated EXISTS over its unique entry_id, never a join.
     *
     * @param \Closure(string): string $columnMatch
     */
    private static function anyColumn(\Closure $columnMatch, string $parameter, string $entryAlias): string
    {
        $transcript = 'transcript' . $parameter;

        return \sprintf(
            '(%s OR %s OR EXISTS (SELECT %s.id FROM %s %s WHERE %s.entry = %s AND %s))',
            $columnMatch($entryAlias . '.headline.title'),
            $columnMatch($entryAlias . '.summary'),
            $transcript,
            EntryTranscript::class,
            $transcript,
            $transcript,
            $entryAlias,
            $columnMatch($transcript . '.searchText'),
        );
    }

    /** @return \Closure(string): string */
    private function substringMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $qb->setParameter($parameter, LikePattern::containing($term));

        return static fn (string $column): string => \sprintf(
            "%s LIKE :%s ESCAPE '%s'",
            $column,
            $parameter,
            LikePattern::ESCAPE_CHARACTER,
        );
    }

    /**
     * A cheap "%term%" LIKE runs first and rejects most rows before the REPLACE chain. It is sound only while the raw
     * term is a substring of every row the normalized check accepts, so a term with boundary punctuation skips it:
     * "E-Mail" must match "E–Mail", which does not contain it.
     *
     * @return \Closure(string): string
     */
    private function wholeWordMatch(QueryBuilder $qb, string $parameter, string $term): \Closure
    {
        $word = $parameter . 'Word';
        $cheap = WordBoundaries::areIn($term) ? null : $parameter . 'Cheap';

        $qb->setParameter($word, LikePattern::wholeWord($term));
        if ($cheap !== null) {
            $qb->setParameter($cheap, LikePattern::containing($term));
        }

        return static fn (string $column): string => self::wholeWordColumnMatch($column, $cheap, $word);
    }

    private static function wholeWordColumnMatch(string $column, ?string $cheap, string $word): string
    {
        $escape = LikePattern::ESCAPE_CHARACTER;
        $normalized = \sprintf(
            "CONCAT(' ', NORMALIZE_WORD_BOUNDARIES(%s), ' ') LIKE :%s ESCAPE '%s'",
            $column,
            $word,
            $escape,
        );

        if ($cheap === null) {
            return '(' . $normalized . ')';
        }

        return \sprintf("(%s LIKE :%s ESCAPE '%s' AND %s)", $column, $cheap, $escape, $normalized);
    }
}
```

Check the generated SQL once (`$qb->getQuery()->getSQL()` in a scratch test) on both SQLite and MySQL: the subquery must reference the outer entry alias, and the CI SQLite 3.45 parser stack (memory: nested REPLACE overflow) must still cope — a whole-word search with three terms now carries three normalised chains per term instead of two. If SQLite overflows, report back rather than restructuring.

- [ ] **Step 4: Run both legs of the search tests**

Run: `cd backend && php bin/phpunit tests/Repository tests/Service/Search` and `docker compose exec php php bin/phpunit tests/Repository tests/Service/Search`
Expected: PASS on both.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/SearchTermsPredicateBuilder.php backend/tests/Repository
git commit -m "feat(#1809): the database search and saved searches match what an episode said"
```

---

### Task 10: Frontend — the transcript reaches the article body

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/article/content/entry-body.service.ts`, `frontend/src/app/reader/shell/reader-route-state.service.ts:99-103`, `frontend/src/app/reader/article/content/article-source.service.ts`, their specs
- Test: `entry-body.service.spec.ts`, `article-source.service.spec.ts` (find their exact paths with `ls`)

**Interfaces:**
- Produces: `TranscriptParagraphDto { startSeconds: number | null; speaker: string | null; text: string }`, `TranscriptDto { language: string | null; paragraphs: TranscriptParagraphDto[] }`, `EntryDetailDto.transcript: TranscriptDto | null`; `EntryBodyState` ok variant gains `transcript: TranscriptDto | null`; `EntryBodyService.seed(id: number, detail: BodyOfDetail)` where `type BodyOfDetail = Pick<EntryDetailDto, 'contentHtml' | 'chapters' | 'transcript'>`; `ArticleSource.transcript: Signal<TranscriptDto | null>`, `ArticleSource.transcriptIsTheBody: Signal<boolean>`

- [ ] **Step 1: Write the failing specs**

In the body-service spec: a fetched detail with `transcript: { language: 'de', paragraphs: [...] }` lands in the `ok` state; `seed(id, { contentHtml, chapters: null, transcript: null })` gives `transcript: null`. In the article-source spec: `transcript()` is the ok body's transcript (null while loading); `transcriptIsTheBody()` is true for a body of `'<p> </p>'` or `null` and false for `'<p>Show notes</p>'`.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/content`
Expected: FAIL.

- [ ] **Step 3: Implement**

`models.ts`, after `ChapterDto`:

```ts
/** One speaker's turn in an episode transcript; `startSeconds` is null for an untimed (HTML) one (#1809). */
export interface TranscriptParagraphDto {
  startSeconds: number | null;
  speaker: string | null;
  text: string;
}

export interface TranscriptDto {
  language: string | null;
  paragraphs: TranscriptParagraphDto[];
}
```

and `transcript: TranscriptDto | null;` on `EntryDetailDto` (extend its comment: "…its chapters (#1808) and transcript (#1809)").

`entry-body.service.ts`:

```ts
export type BodyOfDetail = Pick<EntryDetailDto, 'contentHtml' | 'chapters' | 'transcript'>;

export type EntryBodyState =
  | { status: 'loading' }
  | { status: 'ok'; html: string | null; chapters: ChapterDto[]; transcript: TranscriptDto | null }
  | { status: 'error' };
```

`seed(id: number, detail: BodyOfDetail)` and `fetch()` both set state through one private function:

```ts
function okBody(detail: BodyOfDetail): EntryBodyState {
  return {
    status: 'ok',
    html: detail.contentHtml,
    chapters: detail.chapters ?? [],
    transcript: detail.transcript,
  };
}
```

`reader-route-state.service.ts`: `this.bodyService.seed(response.entry.id, response.entry);`.

`article-source.service.ts`, beside `chapters`:

```ts
  /** The open episode's transcript, which comes with its feed body (#1809). */
  readonly transcript = computed(() => {
    const body = this.feedBody();
    return body?.status === 'ok' ? body.transcript : null;
  });

  /** With no show notes of its own, the transcript is what there is to read, so it opens. */
  readonly transcriptIsTheBody = computed(() => {
    const body = this.feedBody();
    return body?.status === 'ok' && textOf(body.html) === '';
  });
```

with a module-level helper:

```ts
function textOf(html: string | null): string {
  if (!html) return '';
  return (new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '').trim();
}
```

Fix every spec and test fixture that builds an `EntryDetailDto` or an ok `EntryBodyState` (`grep -rln "chapters:" frontend/src --include='*.spec.ts'`, `frontend/src/app/reader/testing`) by adding `transcript: null`.

- [ ] **Step 4: Run the frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src
git commit -m "feat(#1809): the reader keeps an episode's transcript with its body"
```

---

### Task 11: Frontend — the transcript section and seeking

**Files:**
- Create: `frontend/src/app/reader/article/decorators/reader-transcript.ts` (+ `.spec.ts`), `frontend/src/app/reader/article/reader-view/article-transcript.ts` (+ `.spec.ts`), `frontend/src/app/reader/article/article-content/article-transcript.scss`
- Modify: `frontend/src/app/reader/article/article-content/article-content.component.ts` (inputs + `styleUrls`), `frontend/src/app/reader/article/reader-view/article-body.service.ts` (`ArticleBodyParts`, render, release), `frontend/src/app/reader/article/reader-view/reader-view.component.html` (bind the inputs), `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `TranscriptDto` (Task 10); `ChapterPlayer` (`playFrom(seconds)`, `release()`); `AudioChapterPlayer(audio, track, injector)`; `formatDuration`; `materialIcon`
- Produces: `addTranscript(host: HTMLElement, view: TranscriptView, player: ChapterPlayer | null): HTMLElement | null` with `interface TranscriptView { readonly transcript: TranscriptDto; readonly heading: string; readonly open: boolean }`; class `ArticleTranscript` with `attach(host, view, track: AudioTrack | null): void` and `release(): void`; `ArticleBodyParts.transcript: () => TranscriptDto | null`, `ArticleBodyParts.transcriptIsTheBody: () => boolean`

- [ ] **Step 1: Write the failing decorator spec**

Mirror `reader-chapters.spec.ts`'s setup (a host element, a recording `ChapterPlayer` fake):

```ts
describe('addTranscript', () => {
  const transcript: TranscriptDto = {
    language: 'de',
    paragraphs: [
      { startSeconds: 0, speaker: 'Ann', text: 'Hallo.' },
      { startSeconds: 75, speaker: null, text: 'Weiter.' },
      { startSeconds: null, speaker: null, text: 'Ohne Zeit.' },
    ],
  };

  it('appends a collapsed section after the body', () => {
    const host = bodyWith('<p>Notes</p>');
    const section = addTranscript(host, { transcript, heading: 'Transcript', open: false }, null);
    expect(host.lastElementChild).toBe(section);
    expect(section?.querySelector('details')?.open).toBe(false);
  });

  it('opens when the transcript is the body', () => {
    const section = addTranscript(bodyWith(''), { transcript, heading: 'Transcript', open: true }, null);
    expect(section?.querySelector('details')?.open).toBe(true);
  });

  it('seeks the player from a timestamp', () => {
    const player = recordingPlayer();
    const section = addTranscript(bodyWith(''), { transcript, heading: 'T', open: true }, player);
    const stamps = section!.querySelectorAll<HTMLButtonElement>('.reader-transcript__time');
    stamps[1].click();
    expect(player.playedFrom).toEqual([75]);
    expect(stamps).toHaveLength(2);
  });

  it('shows timestamps as text with no player', () => {
    const section = addTranscript(bodyWith(''), { transcript, heading: 'T', open: true }, null);
    expect(section!.querySelectorAll('button.reader-transcript__time')).toHaveLength(0);
    expect(section!.querySelectorAll('.reader-transcript__time')).toHaveLength(2);
  });

  it('names the speaker and keeps the text plain', () => {
    const section = addTranscript(
      bodyWith(''),
      { transcript: { language: null, paragraphs: [{ startSeconds: 0, speaker: 'Ann', text: '<b>x</b>' }] }, heading: 'T', open: true },
      null,
    );
    expect(section!.querySelector('.reader-transcript__speaker')?.textContent).toBe('Ann');
    expect(section!.querySelector('.reader-transcript__text')?.textContent).toBe('<b>x</b>');
  });

  it('adds nothing for an empty transcript', () => {
    expect(addTranscript(bodyWith(''), { transcript: { language: null, paragraphs: [] }, heading: 'T', open: true }, null)).toBeNull();
  });
});
```

(`bodyWith(html)` makes a `div` with that innerHTML; `recordingPlayer()` returns a `ChapterPlayer` whose `playFrom` pushes onto `playedFrom` and whose `watch`/`release` do nothing.)

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/decorators/reader-transcript.spec.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the decorator**

```ts
import { TranscriptDto, TranscriptParagraphDto } from '../../models';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { formatDuration } from '../../format';
import { materialIcon } from './material-icon';

const SECTION = 'reader-transcript';

export interface TranscriptView {
  readonly transcript: TranscriptDto;
  readonly heading: string;
  readonly open: boolean;
}

/** An episode's transcript under its body; with a player, each timestamp starts it there (#1809). */
export function addTranscript(
  host: HTMLElement,
  { transcript, heading, open }: TranscriptView,
  player: ChapterPlayer | null,
): HTMLElement | null {
  if (transcript.paragraphs.length === 0) return null;
  const summary = document.createElement('summary');
  summary.className = `${SECTION}__toggle`;
  summary.append(materialIcon('chevron_right'), heading);
  const details = document.createElement('details');
  details.open = open;
  details.append(summary, ...transcript.paragraphs.map((paragraph) => paragraphOf(paragraph, player)));
  const section = document.createElement('section');
  section.className = SECTION;
  section.setAttribute('aria-label', heading);
  if (transcript.language) section.lang = transcript.language;
  section.append(details);
  host.append(section);
  return section;
}

function paragraphOf(paragraph: TranscriptParagraphDto, player: ChapterPlayer | null): HTMLParagraphElement {
  const element = document.createElement('p');
  element.className = `${SECTION}__paragraph`;
  if (paragraph.startSeconds !== null) element.append(timeOf(paragraph.startSeconds, player));
  if (paragraph.speaker) {
    const speaker = document.createElement('strong');
    speaker.className = `${SECTION}__speaker`;
    speaker.textContent = paragraph.speaker;
    element.append(speaker);
  }
  const text = document.createElement('span');
  text.className = `${SECTION}__text`;
  text.textContent = paragraph.text;
  element.append(text);
  return element;
}

function timeOf(seconds: number, player: ChapterPlayer | null): HTMLElement {
  const time = document.createElement(player ? 'button' : 'span');
  time.className = `${SECTION}__time`;
  time.textContent = formatDuration(seconds);
  if (player && time instanceof HTMLButtonElement) {
    time.type = 'button';
    time.addEventListener('click', () => player.playFrom(seconds));
  }
  return time;
}
```

Everything goes in through `textContent`: transcript text comes from a third-party file and must never reach `innerHTML`.

- [ ] **Step 4: `ArticleTranscript` owns the player's lifetime**

```ts
import { Injector } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { TranscriptView, addTranscript } from '../decorators/reader-transcript';

/** A rendered body's transcript section and the episode player its timestamps drive. */
export class ArticleTranscript {
  private element: HTMLElement | null = null;
  private player: ChapterPlayer | null = null;

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly injector: Injector,
  ) {}

  attach(host: HTMLElement, view: TranscriptView, track: AudioTrack | null): void {
    this.release();
    this.player = track ? new AudioChapterPlayer(this.audio, track, this.injector) : null;
    this.element = addTranscript(host, view, this.player);
  }

  release(): void {
    this.player?.release();
    this.element?.remove();
    this.player = null;
    this.element = null;
  }
}
```

Spec (`article-transcript.spec.ts`): `attach` twice leaves one section; `release()` removes it and calls the player's `release()`; with a track, clicking a timestamp starts the audio service's track at that second (copy the `AudioPlayerService` fake from `article-chapters.spec.ts`).

- [ ] **Step 5: Wire it into the body**

`ArticleBodyParts` gains `readonly transcript: () => TranscriptDto | null;` and `readonly transcriptIsTheBody: () => boolean;`. In `ArticleBody`: `private readonly transcriptSection = new ArticleTranscript(this.audio, this.injector);`; release it wherever `this.chapters.release()` is called (both the `DestroyRef` callback and the effect's `onCleanup`); read `parts.transcript()` inside the effect next to `chapters()` (wrap it in a `computed` with `equal: (left, right) => left === right` so an unchanged body does not re-decorate), pass it to `render()`, and in `render()` inside `runOutsideAngular` after `attachBodyChapters`:

```ts
      if (transcript) {
        this.transcriptSection.attach(
          host,
          {
            transcript,
            heading: this.i18n.translate('reader.transcript'),
            open: parts.transcriptIsTheBody(),
          },
          track,
        );
      }
```

`ArticleContentComponent`: `readonly transcript = input<TranscriptDto | null>(null);`, `readonly transcriptIsTheBody = input(false);`, pass both into `connect()`, add `'./article-transcript.scss'` to `styleUrls`. `reader-view.component.html` on `<app-article-content>`: `[transcript]="source.transcript()"` and `[transcriptIsTheBody]="source.transcriptIsTheBody()"`.

i18n: `"transcript": "Transcript"` next to `"chapters"` in `en.json`, `"transcript": "Transkript"` in `de.json`.

- [ ] **Step 6: Style it like the chapter toggle**

`article-transcript.scss` — tokens only, no hex, no ad-hoc px (read `docs/design-language.md` and copy the mixin imports at the top of `article-chapters.scss`):

```scss
@use '../../../../styles/mixins/glass-rim';
@use '../../../../styles/mixins/bare-summary';

:host ::ng-deep .reader-transcript {
  @include glass-rim.filled(var(--surface-1));

  margin: var(--space-4) 0;
  border-radius: var(--radius);
}

:host ::ng-deep .reader-transcript__toggle {
  @include bare-summary.marker-hidden;

  display: flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-3);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  cursor: pointer;
}

:host ::ng-deep .reader-transcript__toggle .material-symbols-outlined {
  font-size: var(--icon-md);
}

:host ::ng-deep [open] > .reader-transcript__toggle .material-symbols-outlined {
  transform: rotate(90deg);
}

:host ::ng-deep .reader-transcript__paragraph {
  margin: 0;
  padding: var(--space-1) var(--space-3);
}

:host ::ng-deep .reader-transcript__time {
  margin-inline-end: var(--space-2);
  color: var(--text-secondary);
  font-variant-numeric: tabular-nums;
}

:host ::ng-deep button.reader-transcript__time {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  cursor: pointer;
}

:host ::ng-deep .reader-transcript__speaker {
  margin-inline-end: var(--space-1);
}
```

Use the real `@use` paths `article-chapters.scss` uses; if a mixin name differs, follow that file.

- [ ] **Step 7: Run the frontend gates**

Run: `docker compose exec -T frontend npm run check` then `docker compose exec -T frontend npx ng build`
Expected: PASS (memory: `npm run check` misses template errors; `ng build` catches them).

- [ ] **Step 8: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#1809): the article view shows the transcript, its timestamps seek the episode"
```

---

### Task 12: Full gates, live check, spec touch-up

**Files:**
- Modify: `docs/superpowers/specs/2026-10-27-podcast-transcripts-design.md` (the deviations listed at the top of this plan)

- [ ] **Step 1: Backend gates**

Run, from `backend/`: `composer check`, `composer md`, `composer test:parallel`, `docker compose exec php composer test`, `composer infection:diff`. PhpStorm `lint_files` on every changed PHP file. Expected: all green; every touched `src` file PHPMD-clean. Fix the design PHPMD points at (memory: never tune thresholds).

- [ ] **Step 2: Migration leg**

Repeat Task 1 Step 9 from a fresh database on both platforms. Expected: in sync.

- [ ] **Step 3: Live check against Docker**

Verify the containers run current code first (memory: check the container is current; restart the worker, which holds stale code). Subscribe the dev account to Logbuch Netzpolitik (`https://logbuch-netzpolitik.de/feed/m4a`), then run the sweep through the worker or `curl -X POST` the maintenance tick endpoint the way `MaintenanceControllerTest` does, until `entry_transcript` shows filled rows (`docker compose exec php bin/console dbal:run-sql "SELECT status, COUNT(*) FROM entry_transcript GROUP BY status"`). On http://localhost:4200, open a Logbuch episode: the Transcript toggle sits below the show notes, collapsed; a timestamp starts the episode at that second. Search a word that only the transcript holds: the episode is listed. Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq .`) for deprecations and errors. Take a screenshot.

- [ ] **Step 4: Update the spec**

Edit the spec's decision rows and sections to match this plan's "Deviations" list (no language tie-break; restore queues nothing; `match` over static readers; int `startSeconds`; DOM decorator; open-vs-collapsed rule; `FollowUpFetchSweeps`).

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-10-27-podcast-transcripts-design.md
git commit -m "docs(#1809): the spec follows what the code settled"
```
