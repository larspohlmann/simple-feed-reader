# Admin Queue Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin section, Settings → Admin → Queues, that shows for each background work queue how many items wait and since when the oldest has waited.

**Architecture:** Each queue's repository answers one aggregate query (`COUNT` + `MIN(timestamp)`) and returns a `QueueBacklogModel`. A new `Service/Queue` module's `QueueDepths` service asks all of them and names each answer with a `QueueName`. `GET /api/admin/queues` maps that list through `QueueDepthsJson`; the SPA renders a table and computes the age client-side with the existing `relativeTime()`.

**Tech Stack:** Symfony 7.4 / Doctrine ORM + DBAL (PHP 8.4), Angular 20 standalone + signals, Transloco, Jest.

**Spec:** GitHub issue #1861 plus the decisions taken in the brainstorming session (recorded under Global Constraints).

## Global Constraints

- Queues, in this order, with these wire names:
  1. `failed_messages` — `messenger_messages` rows with `queue_name = 'failed'`; age from `created_at`. The table may not exist (`auto_setup` creates it on the first failure): then `0` and no age.
  2. `recommendation_runs` — status `pending` or `running` (`RunStatus::active()`); age from `created_at`.
  3. `profile_runs` — same rule on `ProfileRun`.
  4. `image_verification` — `Entry` with `image.verifyAttempts IS NOT NULL`; age from **`entry.createdAt`** (decided: no new column; an existing entry re-queued later reads as old as the entry).
  5. `share_enrichment_appview`, 6. `share_enrichment_link_cards`, 7. `share_enrichment_self_thread` — `ShareEnrichment` of that kind with `doneAt IS NULL`; age from `queuedAt`.
  8. `transcripts` — `EntryTranscript` with status `pending` (backed-off rows included); age from **`entry.createdAt`** (exact: a transcript is only queued for the entry the same ingest creates).
  9. `due_feeds` — exactly the refresh's own definition, `FeedRepository::dueQueryBuilder(new DueFeedCriteria($now))`; age from `MIN(nextFetchAt)` (never-fetched feeds count but carry no time).
- **No migration, no new column.**
- Response: `{"queues": [{"queue": "<wire name>", "waiting": <int>, "oldestQueuedAt": "<ATOM>"|null}, ...]}` — the client derives the age; the endpoint stays stateless (native iOS client constraint).
- Datetimes in the DB are naive UTC: an aggregate's `MIN()` string is parsed **in `UTC`** explicitly.
- The page loads on open and has a refresh button. **No polling.**
- Clean Code rules of `CLAUDE.md`, PHPMD-clean on every touched `src` file, `composer check`, `npm run check` + `ng build`, commit format `type(#1861): …`, no Claude attribution.

---

## File Structure

| File | Role |
|---|---|
| `backend/src/Service/Queue/Model/QueueName.php` | enum: the nine queues, value = wire name |
| `backend/src/Service/Queue/Model/QueueBacklogModel.php` | one aggregate answer: `waiting`, `oldestQueuedAt`; parses the raw row |
| `backend/src/Service/Queue/Model/QueueDepthModel.php` | a backlog named with its queue |
| `backend/src/Service/Queue/QueueDepths.php` | asks every repository, in `QueueName` order |
| `backend/src/Repository/{FailedMessage,RecommendationRun,ProfileRun,PendingImageVerification,ShareEnrichment,EntryTranscript,Feed}Repository.php` | one backlog method each |
| `backend/src/Http/Admin/QueueDepthsJson.php` | response shape |
| `backend/src/Controller/Admin/AdminQueueController.php` | `GET /api/admin/queues` |
| `frontend/src/app/admin/admin.models.ts`, `admin-api.ts` | DTO + `queues()` call |
| `frontend/src/app/settings/admin/queues/queues-section.component.{ts,html,scss,spec.ts}` | the section |
| `frontend/src/app/settings/settings-sections.ts`, `settings.routes.ts` | nav entry + lazy route |
| `frontend/public/i18n/{en,de}.json` | labels |

---

### Task 1: The Queue models

**Files:**
- Create: `backend/src/Service/Queue/Model/QueueName.php`
- Create: `backend/src/Service/Queue/Model/QueueBacklogModel.php`
- Create: `backend/src/Service/Queue/Model/QueueDepthModel.php`
- Test: `backend/tests/Service/Queue/Model/QueueBacklogModelTest.php`

**Interfaces:**
- Produces: `QueueName` (string-backed enum, cases below); `QueueBacklogModel::fromAggregate(int|string $waiting, ?string $oldestQueuedAt): self` with public readonly `int $waiting`, `?\DateTimeImmutable $oldestQueuedAt`; `QueueBacklogModel::none(): self`; `QueueBacklogModel->named(QueueName $queue): QueueDepthModel`; `QueueDepthModel` with public readonly `QueueName $queue`, `QueueBacklogModel $backlog`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Queue\Model;

use App\Service\Queue\Model\QueueBacklogModel;
use App\Service\Queue\Model\QueueName;
use PHPUnit\Framework\TestCase;

final class QueueBacklogModelTest extends TestCase
{
    public function testAnAggregateRowReadsItsNaiveTimestampAsUtc(): void
    {
        $backlog = QueueBacklogModel::fromAggregate('3', '2026-10-28 09:15:00');

        self::assertSame(3, $backlog->waiting);
        self::assertSame('2026-10-28T09:15:00+00:00', $backlog->oldestQueuedAt?->format(\DATE_ATOM));
    }

    public function testAnEmptyQueueHasNoOldestTime(): void
    {
        $backlog = QueueBacklogModel::fromAggregate(0, null);

        self::assertSame(0, $backlog->waiting);
        self::assertNull($backlog->oldestQueuedAt);
    }

    public function testNoneIsAnEmptyQueue(): void
    {
        self::assertEquals(QueueBacklogModel::fromAggregate(0, null), QueueBacklogModel::none());
    }

    public function testNamingKeepsTheBacklog(): void
    {
        $backlog = QueueBacklogModel::fromAggregate(2, '2026-10-28 09:15:00');

        $depth = $backlog->named(QueueName::Transcripts);

        self::assertSame(QueueName::Transcripts, $depth->queue);
        self::assertSame($backlog, $depth->backlog);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run (from `backend/`): `php bin/phpunit tests/Service/Queue/Model/QueueBacklogModelTest.php`
Expected: FAIL, `Class "App\Service\Queue\Model\QueueBacklogModel" not found`.

- [ ] **Step 3: Write the models**

`QueueName.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Queue\Model;

enum QueueName: string
{
    case FailedMessages = 'failed_messages';
    case RecommendationRuns = 'recommendation_runs';
    case ProfileRuns = 'profile_runs';
    case ImageVerification = 'image_verification';
    case ShareEnrichmentAppView = 'share_enrichment_appview';
    case ShareEnrichmentLinkCards = 'share_enrichment_link_cards';
    case ShareEnrichmentSelfThread = 'share_enrichment_self_thread';
    case Transcripts = 'transcripts';
    case DueFeeds = 'due_feeds';
}
```

`QueueBacklogModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Queue\Model;

final readonly class QueueBacklogModel
{
    private function __construct(
        public int $waiting,
        public ?\DateTimeImmutable $oldestQueuedAt,
    ) {
    }

    /** A `COUNT`/`MIN` row as either driver returns it; the stored time is naive UTC. */
    public static function fromAggregate(int|string $waiting, ?string $oldestQueuedAt): self
    {
        return new self(
            (int) $waiting,
            $oldestQueuedAt === null ? null : new \DateTimeImmutable($oldestQueuedAt, new \DateTimeZone('UTC')),
        );
    }

    public static function none(): self
    {
        return new self(0, null);
    }

    public function named(QueueName $queue): QueueDepthModel
    {
        return new QueueDepthModel($queue, $this);
    }
}
```

`QueueDepthModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Queue\Model;

final readonly class QueueDepthModel
{
    public function __construct(
        public QueueName $queue,
        public QueueBacklogModel $backlog,
    ) {
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/Queue/Model/QueueBacklogModelTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Queue backend/tests/Service/Queue
git commit -m "feat(#1861): a queue backlog is a count and the time its oldest item was queued"
```

---

### Task 2: One backlog query per queue

**Files:**
- Modify: `backend/src/Repository/FailedMessageRepository.php`
- Modify: `backend/src/Repository/RecommendationRunRepository.php` (reuses private `activeStatusQuery()`)
- Modify: `backend/src/Repository/ProfileRunRepository.php`
- Modify: `backend/src/Repository/PendingImageVerificationRepository.php`
- Modify: `backend/src/Repository/ShareEnrichmentRepository.php`
- Modify: `backend/src/Repository/EntryTranscriptRepository.php`
- Modify: `backend/src/Repository/FeedRepository.php` (reuses private `dueQueryBuilder()`)
- Test: `backend/tests/Repository/FailedMessageRepositoryTest.php` (create), and new test methods in `RecommendationRunRepositoryTest.php`, `ProfileRunRepositoryTest.php`, `PendingImageVerificationTest.php`, `ShareEnrichmentRepositoryTest.php`, `EntryTranscriptRepositoryTest.php`, `FeedRepositoryTest.php`

**Interfaces:**
- Consumes: `QueueBacklogModel::fromAggregate()`, `::none()` (Task 1).
- Produces (all return `QueueBacklogModel`):
  - `FailedMessageRepository::backlog()`
  - `RecommendationRunRepository::activeBacklog()`
  - `ProfileRunRepository::activeBacklog()`
  - `PendingImageVerificationRepository::backlog()`
  - `ShareEnrichmentRepository::backlogOf(ShareEnrichmentKind $kind)`
  - `EntryTranscriptRepository::pendingBacklog()`
  - `FeedRepository::dueBacklog(DueFeedCriteria $criteria)`

Each method reads the aggregate row into the same typed shape, so add this one-line PHPDoc above each `getSingleResult()`: `/** @var array{waiting: int|string, oldest: ?string} $row */`.

- [ ] **Step 1: Write the failing tests**

`tests/Repository/FailedMessageRepositoryTest.php` (new). The failure transport's table only exists after `setup()`; `DbTestCase` gives `$this->entityManager`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\FailedMessageRepository;
use App\Tests\DbTestCase;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

final class FailedMessageRepositoryTest extends DbTestCase
{
    public function testAStackThatNeverFailedAMessageHasAnEmptyBacklog(): void
    {
        $this->entityManager->getConnection()->executeStatement('DROP TABLE IF EXISTS messenger_messages');

        $backlog = $this->repository()->backlog();

        self::assertSame(0, $backlog->waiting);
        self::assertNull($backlog->oldestQueuedAt);
    }

    public function testCountsOnlyTheFailureQueueAndFindsItsOldestMessage(): void
    {
        $this->createTable();
        $this->insert('failed', '2026-10-28 08:00:00');
        $this->insert('failed', '2026-10-28 07:30:00');
        $this->insert('default', '2026-10-28 06:00:00');

        $backlog = $this->repository()->backlog();

        self::assertSame(2, $backlog->waiting);
        self::assertSame('2026-10-28 07:30:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }

    private function createTable(): void
    {
        $transport = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(SetupableTransportInterface::class, $transport);
        $transport->setup();
    }

    private function insert(string $queue, string $createdAt): void
    {
        $this->entityManager->getConnection()->insert('messenger_messages', [
            'body' => 'x',
            'headers' => '[]',
            'queue_name' => $queue,
            'created_at' => $createdAt,
            'available_at' => $createdAt,
        ]);
    }

    private function repository(): FailedMessageRepository
    {
        $repository = self::getContainer()->get(FailedMessageRepository::class);
        self::assertInstanceOf(FailedMessageRepository::class, $repository);

        return $repository;
    }
}
```

If `messenger.transport.failed` is private in the test container, fetch it the way existing tests reach a transport (`grep -rn "messenger.transport" backend/tests`) instead; do not make it public in `src` config.

Add to `RecommendationRunRepositoryTest` (its `persistRun()` stamps `2026-08-07T09:00:00Z`; `SeedsUsers::user()` creates the account):

```php
    public function testTheActiveBacklogCountsPendingAndRunningRuns(): void
    {
        $user = $this->user('backlog@example.com');
        $this->persistRun($user, RunStatus::Pending);
        $this->persistRun($user, RunStatus::Running);
        $this->persistRun($user, RunStatus::Completed);
        $this->persistRun($user, RunStatus::Failed);
        $this->entityManager->flush();

        $backlog = $this->runs()->activeBacklog();

        self::assertSame(2, $backlog->waiting);
        self::assertSame('2026-08-07 09:00:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }
```

Add to `ProfileRunRepositoryTest` (its `running()` stamps `2026-10-03 09:00:00`, `completed()` and `failed()` finish theirs):

```php
    public function testTheActiveBacklogCountsOnlyUnfinishedRuns(): void
    {
        $owner = $this->user('profile-runs-backlog@example.test');
        $this->running($owner, 'a');
        $this->completed($owner, 'b');
        $this->failed($owner);

        $backlog = $this->profileRuns()->activeBacklog();

        self::assertSame(1, $backlog->waiting);
        self::assertSame('2026-10-03 09:00:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }
```

Add to `PendingImageVerificationTest` (its `entry()` creates entries with `createdAt` `2026-09-21 06:00:00`):

```php
    public function testTheBacklogCountsPendingImagesAndDatesThemByTheirEntry(): void
    {
        $feed = $this->feed();
        $this->entry($feed, 'pending')->getImage()->storePending('https://i/pending.jpg', null, null);
        $verified = $this->entry($feed, 'verified');
        $verified->getImage()->storePending('https://i/verified.jpg', null, null);
        $verified->getImage()->recordMeasurement(800, 600, new \DateTimeImmutable('2026-09-21 10:00:00'));
        $this->entityManager->flush();

        $backlog = $this->repository()->backlog();

        self::assertSame(1, $backlog->waiting);
        self::assertSame('2026-09-21 06:00:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }
```

Add to `ShareEnrichmentRepositoryTest` (uses `feed()`, `queued()`, `now()`; `claim(list, now): list` returns the rows it now holds and `markDone(list, claimedAt)` finishes them):

```php
    public function testTheBacklogOfAKindCountsItsUnfinishedRows(): void
    {
        $feed = $this->feed('https://example.test/a.xml');
        $this->queued($feed, 'old', '2026-10-11 09:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($feed, 'new', '2026-10-11 10:00:00', ShareEnrichmentKind::LinkCards);
        $this->queued($feed, 'other-kind', '2026-10-11 08:00:00', ShareEnrichmentKind::AppView);
        $this->entityManager->flush();

        $backlog = $this->repository()->backlogOf(ShareEnrichmentKind::LinkCards);

        self::assertSame(2, $backlog->waiting);
        self::assertSame('2026-10-11 09:00:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }

    public function testAFinishedRowLeavesTheBacklog(): void
    {
        $feed = $this->feed('https://example.test/a.xml');
        $done = $this->queued($feed, 'done', '2026-10-11 09:00:00', ShareEnrichmentKind::LinkCards);
        $this->entityManager->flush();
        $claimed = $this->repository()->claim([$done], $this->now());
        $this->repository()->markDone($claimed, $this->now());

        self::assertSame(0, $this->repository()->backlogOf(ShareEnrichmentKind::LinkCards)->waiting);
    }
```

Add to `EntryTranscriptRepositoryTest` (`pendingTranscripts()` creates entries with `createdAt` `2026-10-27 06:00:00`):

```php
    public function testThePendingBacklogCountsBackedOffRowsAndSkipsSettledOnes(): void
    {
        [$pending, $backedOff, $filled, $failed] = $this->fixtures->pendingTranscripts(4);
        $backedOff->retryAfter(new \DateTimeImmutable('2026-10-27 12:00:00'));
        $filled->fill([new TranscriptParagraph(0, null, 'x')], 'x');
        $failed->fail();
        $this->entityManager->flush();

        $backlog = $this->transcripts->pendingBacklog();

        self::assertSame(2, $backlog->waiting);
        self::assertSame('2026-10-27 06:00:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }
```

Add to `FeedRepositoryTest` (its `feed()` schedules `nextFetchAt`; `goneFeed()` is never due):

```php
    public function testTheDueBacklogCountsDueFeedsAndDatesTheMostOverdue(): void
    {
        $this->feed('https://a.test/feed', $this->now->modify('-3 hours'));
        $this->feed('https://b.test/feed', $this->now->modify('-1 hour'));
        $this->feed('https://never.test/feed', null);
        $this->feed('https://later.test/feed', $this->now->modify('+1 hour'));
        $this->goneFeed('https://gone.test/feed', $this->now->modify('-5 hours'));
        $this->entityManager->flush();

        $backlog = $this->feeds->dueBacklog(new DueFeedCriteria($this->now));

        self::assertSame(3, $backlog->waiting);
        self::assertSame('2026-07-21 09:00:00', $backlog->oldestQueuedAt?->format('Y-m-d H:i:s'));
    }

    public function testTheDueBacklogCountsWhatCountDueCounts(): void
    {
        $this->feed('https://a.test/feed', $this->now->modify('-3 hours'));
        $this->feed('https://never.test/feed', null);
        $this->entityManager->flush();
        $criteria = new DueFeedCriteria($this->now);

        self::assertSame($this->feeds->countDue($criteria), $this->feeds->dueBacklog($criteria)->waiting);
    }
```

If the first test reports a count other than 3, check `restrictToConnectedAccounts()` / `restrictToFetchable()` before touching the assertion: the expected number is whatever `countDue()` says for the same fixtures, which the second test pins.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'Backlog' tests/Repository`
Expected: FAIL, each with `Call to undefined method …::backlog()` / `activeBacklog()` / `backlogOf()` / `pendingBacklog()` / `dueBacklog()`.

- [ ] **Step 3: Implement the seven methods**

`FailedMessageRepository` (add `use App\Service\Queue\Model\QueueBacklogModel;`):

```php
    public function backlog(): QueueBacklogModel
    {
        try {
            /** @var array{waiting: int|string, oldest: ?string} $row */
            $row = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS waiting, MIN(created_at) AS oldest FROM messenger_messages WHERE queue_name = :queue',
                ['queue' => 'failed'],
            );
        } catch (TableNotFoundException) {
            return QueueBacklogModel::none();
        }

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
```

The literal `'failed'` now appears twice in this class: lift it to `private const string FAILURE_QUEUE = 'failed';` and use it in both methods.

`RecommendationRunRepository`:

```php
    public function activeBacklog(): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->activeStatusQuery()
            ->select('COUNT(r.id) AS waiting', 'MIN(r.createdAt) AS oldest')
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
```

`ProfileRunRepository` (read how `findAllActive()`/`hasActiveRun()` filter active runs there and reuse that query, or `RunStatus::active()` the same way):

```php
    public function activeBacklog(): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS waiting', 'MIN(r.createdAt) AS oldest')
            ->andWhere('r.status IN (:active)')->setParameter('active', RunStatus::active())
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
```

If `ProfileRunRepository` already has a private active-status query builder, call it instead of repeating the `andWhere`.

`PendingImageVerificationRepository`:

```php
    public function backlog(): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) AS waiting', 'MIN(e.createdAt) AS oldest')
            ->andWhere('e.image.verifyAttempts IS NOT NULL')
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
```

The `andWhere` now repeats `findPendingImageVerification()`'s: extract `private function pendingQuery(): QueryBuilder` and have both start from it.

`ShareEnrichmentRepository`:

```php
    public function backlogOf(ShareEnrichmentKind $kind): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->createQueryBuilder('s')
            ->select('COUNT(s.id) AS waiting', 'MIN(s.queuedAt) AS oldest')
            ->andWhere('s.kind = :kind')->setParameter('kind', $kind)
            ->andWhere('s.doneAt IS NULL')
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
```

`EntryTranscriptRepository`:

```php
    public function pendingBacklog(): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->createQueryBuilder('t')
            ->select('COUNT(t.id) AS waiting', 'MIN(e.createdAt) AS oldest')
            ->join('t.entry', 'e')
            ->andWhere('t.status = :pending')->setParameter('pending', TranscriptStatus::Pending)
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
```

`FeedRepository`:

```php
    public function dueBacklog(DueFeedCriteria $criteria): QueueBacklogModel
    {
        /** @var array{waiting: int|string, oldest: ?string} $row */
        $row = $this->dueQueryBuilder($criteria)
            ->select('COUNT(f.id) AS waiting', 'MIN(f.fetchSchedule.nextFetchAt) AS oldest')
            ->getQuery()
            ->getSingleResult();

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
```

- [ ] **Step 4: Run them to verify they pass**

Run: `php bin/phpunit --filter 'Backlog' tests/Repository`
Expected: PASS. Then the whole touched files: `php bin/phpunit tests/Repository/FailedMessageRepositoryTest.php tests/Repository/RecommendationRunRepositoryTest.php tests/Repository/ProfileRunRepositoryTest.php tests/Repository/PendingImageVerificationTest.php tests/Repository/ShareEnrichmentRepositoryTest.php tests/Repository/EntryTranscriptRepositoryTest.php tests/Repository/FeedRepositoryTest.php` — PASS.

- [ ] **Step 5: MySQL leg**

Run (repo root): `docker compose exec php php bin/phpunit --filter 'Backlog' tests/Repository`
Expected: PASS. `MIN()` on a datetime returns the same `Y-m-d H:i:s` string on both drivers; a failure here is the place to find out otherwise.

- [ ] **Step 6: PHPMD on the touched repositories**

Run (from `backend/`): `vendor/bin/phpmd src/Repository/FeedRepository.php,src/Repository/ShareEnrichmentRepository.php,src/Repository/RecommendationRunRepository.php,src/Repository/ProfileRunRepository.php,src/Repository/EntryTranscriptRepository.php,src/Repository/PendingImageVerificationRepository.php,src/Repository/FailedMessageRepository.php text phpmd.xml.dist` (use the ruleset `composer md` names in `composer.json`).
Expected: no findings. A pre-existing finding in a touched file is fixed here too (standing rule) — by fixing the design it points at, not the threshold.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Repository backend/tests/Repository
git commit -m "feat(#1861): every work queue's repository reports its backlog"
```

---

### Task 3: `QueueDepths`, the endpoint and its shape

**Files:**
- Create: `backend/src/Service/Queue/QueueDepths.php`
- Create: `backend/src/Http/Admin/QueueDepthsJson.php`
- Create: `backend/src/Controller/Admin/AdminQueueController.php`
- Test: `backend/tests/Controller/Admin/AdminQueueControllerTest.php`

**Interfaces:**
- Consumes: all Task 2 methods; `QueueName`, `QueueDepthModel`, `QueueBacklogModel::named()` (Task 1); `App\Service\Clock\NaiveUtcClock::now()`.
- Produces: `QueueDepths::all(): list<QueueDepthModel>` in `QueueName::cases()` order; `QueueDepthsJson::from(list<QueueDepthModel>): array{queues: list<array{queue: string, waiting: int, oldestQueuedAt: ?string}>}`; route `api_admin_queues` = `GET /api/admin/queues`.

The non-admin `403` is already asserted for every admin route by `AdminRouteGuardTest`; this test covers the shape.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AdminQueueControllerTest extends ApiTestCase
{
    private const string QUEUES = '/api/admin/queues';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    public function testWithoutATokenIsRejected(): void
    {
        $this->client->request('GET', self::QUEUES);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnAdminSeesEveryQueueInOrder(): void
    {
        $this->getAs($this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']));

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'failed_messages',
                'recommendation_runs',
                'profile_runs',
                'image_verification',
                'share_enrichment_appview',
                'share_enrichment_link_cards',
                'share_enrichment_self_thread',
                'transcripts',
                'due_feeds',
            ],
            array_column($this->queues(), 'queue'),
        );
    }

    public function testAQueueReportsItsCountAndOldestTime(): void
    {
        $admin = $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
        $this->entityManager()->persist(new RecommendationRun($admin, new \DateTimeImmutable('2026-10-28 07:00:00')));
        $this->entityManager()->flush();

        $this->getAs($admin);

        $runs = array_values(array_filter(
            $this->queues(),
            static fn (array $queue): bool => $queue['queue'] === 'recommendation_runs',
        ))[0];
        self::assertSame(1, $runs['waiting']);
        self::assertSame('2026-10-28T07:00:00+00:00', $runs['oldestQueuedAt']);
    }

    private function getAs(User $user): void
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $this->client->request(
            'GET',
            self::QUEUES,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $manager->create($user)],
        );
    }

    /** @return list<array{queue: string, waiting: int, oldestQueuedAt: ?string}> */
    private function queues(): array
    {
        /** @var array{queues: list<array{queue: string, waiting: int, oldestQueuedAt: ?string}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['queues'];
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Controller/Admin/AdminQueueControllerTest.php`
Expected: FAIL — the admin requests get `404`.

- [ ] **Step 3: Implement**

`QueueDepths.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Queue;

use App\Enum\ShareEnrichmentKind;
use App\Repository\DueFeedCriteria;
use App\Repository\EntryTranscriptRepository;
use App\Repository\FailedMessageRepository;
use App\Repository\FeedRepository;
use App\Repository\PendingImageVerificationRepository;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Queue\Model\QueueBacklogModel;
use App\Service\Queue\Model\QueueDepthModel;
use App\Service\Queue\Model\QueueName;

final readonly class QueueDepths
{
    public function __construct(
        private FailedMessageRepository $failedMessages,
        private RecommendationRunRepository $recommendationRuns,
        private ProfileRunRepository $profileRuns,
        private PendingImageVerificationRepository $pendingImages,
        private ShareEnrichmentRepository $shareEnrichments,
        private EntryTranscriptRepository $transcripts,
        private FeedRepository $feeds,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return list<QueueDepthModel> */
    public function all(): array
    {
        return array_map(
            fn (QueueName $queue): QueueDepthModel => $this->backlogOf($queue)->named($queue),
            QueueName::cases(),
        );
    }

    private function backlogOf(QueueName $queue): QueueBacklogModel
    {
        return match ($queue) {
            QueueName::FailedMessages => $this->failedMessages->backlog(),
            QueueName::RecommendationRuns => $this->recommendationRuns->activeBacklog(),
            QueueName::ProfileRuns => $this->profileRuns->activeBacklog(),
            QueueName::ImageVerification => $this->pendingImages->backlog(),
            QueueName::ShareEnrichmentAppView => $this->shareEnrichments->backlogOf(ShareEnrichmentKind::AppView),
            QueueName::ShareEnrichmentLinkCards => $this->shareEnrichments->backlogOf(ShareEnrichmentKind::LinkCards),
            QueueName::ShareEnrichmentSelfThread => $this->shareEnrichments->backlogOf(ShareEnrichmentKind::SelfThread),
            QueueName::Transcripts => $this->transcripts->pendingBacklog(),
            QueueName::DueFeeds => $this->feeds->dueBacklog(new DueFeedCriteria($this->clock->now())),
        };
    }
}
```

`QueueDepthsJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Queue\Model\QueueDepthModel;

final readonly class QueueDepthsJson
{
    /**
     * @param list<QueueDepthModel> $depths
     *
     * @return array{queues: list<array{queue: string, waiting: int, oldestQueuedAt: ?string}>}
     */
    public static function from(array $depths): array
    {
        return ['queues' => array_map(self::depth(...), $depths)];
    }

    /** @return array{queue: string, waiting: int, oldestQueuedAt: ?string} */
    private static function depth(QueueDepthModel $depth): array
    {
        return [
            'queue' => $depth->queue->value,
            'waiting' => $depth->backlog->waiting,
            'oldestQueuedAt' => $depth->backlog->oldestQueuedAt?->format(\DATE_ATOM),
        ];
    }
}
```

`AdminQueueController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Admin\QueueDepthsJson;
use App\Service\Queue\QueueDepths;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin/queues')]
final readonly class AdminQueueController
{
    public function __construct(private QueueDepths $depths)
    {
    }

    #[Route('', name: 'api_admin_queues', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(QueueDepthsJson::from($this->depths->all()));
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Controller/Admin/AdminQueueControllerTest.php tests/Controller/Admin/AdminRouteGuardTest.php`
Expected: PASS — the guard test now also covers `GET /api/admin/queues`.

- [ ] **Step 5: Gates**

Run (from `backend/`): `bin/console cache:warmup && composer check && composer md`
Expected: clean. `ServiceRoleRule` must accept `Service/Queue/QueueDepths.php` (stateless root service) and the three `Model/` classes; `ServiceModuleCycleRule` sees `Queue → Clock` only.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Queue backend/src/Http/Admin/QueueDepthsJson.php backend/src/Controller/Admin/AdminQueueController.php backend/tests/Controller/Admin/AdminQueueControllerTest.php
git commit -m "feat(#1861): GET /api/admin/queues reports every work queue's backlog"
```

---

### Task 4: The Queues section

**Files:**
- Modify: `frontend/src/app/admin/admin.models.ts`, `frontend/src/app/admin/admin-api.ts`
- Create: `frontend/src/app/settings/admin/queues/queues-section.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/settings/settings-sections.ts`, `frontend/src/app/settings/settings.routes.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `GET /api/admin/queues` (Task 3); `relativeTime(iso, locale)` from `reader/format.ts`; `LanguageService.lang()`.
- Produces: `QueueDepthDto`, `QueueNameDto`, `AdminApi.queues(): Observable<{ queues: QueueDepthDto[] }>`; route `settings/admin/queues`.

- [ ] **Step 1: Write the failing spec**

`queues-section.component.spec.ts` (mirrors `grafana-section.component.spec.ts` setup):

```ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { QueueDepthDto } from '../../../admin/admin.models';
import { QueuesSectionComponent } from './queues-section.component';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/queues`;

describe('QueuesSectionComponent', () => {
  let http: HttpTestingController;

  function mount(): ComponentFixture<QueuesSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [QueuesSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(QueuesSectionComponent);
    fixture.detectChanges();
    return fixture;
  }

  function respond(queues: QueueDepthDto[]): void {
    http.expectOne(ENDPOINT).flush({ queues });
  }

  afterEach(() => http.verify());

  it('lists every queue with its waiting count', () => {
    const fixture = mount();
    respond([
      { queue: 'transcripts', waiting: 4, oldestQueuedAt: '2026-10-28T07:00:00+00:00' },
      { queue: 'due_feeds', waiting: 0, oldestQueuedAt: null },
    ]);
    fixture.detectChanges();

    const rows = fixture.nativeElement.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0].querySelector('.waiting').textContent.trim()).toBe('4');
    expect(rows[1].querySelector('.oldest').textContent.trim()).toBe('—');
  });

  it('shows the age of the oldest waiting item', () => {
    jest.useFakeTimers({ now: new Date('2026-10-28T10:00:00Z') });
    const fixture = mount();
    respond([{ queue: 'transcripts', waiting: 1, oldestQueuedAt: '2026-10-28T07:00:00+00:00' }]);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.oldest').textContent.trim()).toBe('3 hr. ago');
    jest.useRealTimers();
  });

  it('reloads on refresh', () => {
    const fixture = mount();
    respond([]);
    fixture.nativeElement.querySelector('.refresh button').click();
    respond([{ queue: 'transcripts', waiting: 2, oldestQueuedAt: null }]);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelectorAll('tbody tr')).toHaveLength(1);
  });

  it('shows an error banner when loading fails', () => {
    const fixture = mount();
    http.expectOne(ENDPOINT).flush(null, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-error-banner')).not.toBeNull();
  });
});
```

The `'3 hr. ago'` literal is what `Intl.RelativeTimeFormat('en', {style: 'short'})` prints in Node 22; if jsdom's ICU prints otherwise, assert against `relativeTime('2026-10-28T07:00:00+00:00', 'en', new Date('2026-10-28T10:00:00Z'))` instead of the literal.

- [ ] **Step 2: Run it to verify it fails**

Run (repo root): `docker compose exec -T frontend npx jest src/app/settings/admin/queues`
Expected: FAIL — `Cannot find module './queues-section.component'`.

- [ ] **Step 3: DTO and API call**

`admin.models.ts`, append:

```ts
export type QueueNameDto =
  | 'failed_messages'
  | 'recommendation_runs'
  | 'profile_runs'
  | 'image_verification'
  | 'share_enrichment_appview'
  | 'share_enrichment_link_cards'
  | 'share_enrichment_self_thread'
  | 'transcripts'
  | 'due_feeds';

export interface QueueDepthDto {
  queue: QueueNameDto;
  waiting: number;
  oldestQueuedAt: string | null;
}
```

`admin-api.ts`: import `QueueDepthDto` and add

```ts
  queues(): Observable<{ queues: QueueDepthDto[] }> {
    return this.http.get<{ queues: QueueDepthDto[] }>(`${this.base}/api/admin/queues`);
  }
```

- [ ] **Step 4: The component**

`queues-section.component.ts`:

```ts
import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AdminApi } from '../../../admin/admin-api';
import { QueueDepthDto } from '../../../admin/admin.models';
import { Problem, parseProblem } from '../../../core/problem';
import { LanguageService } from '../../../core/i18n/language.service';
import { relativeTime } from '../../../reader/format';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';

@Component({
  selector: 'app-queues-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsStackComponent,
    SkeletonComponent,
    TranslocoPipe,
  ],
  templateUrl: './queues-section.component.html',
  styleUrl: './queues-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class QueuesSectionComponent {
  private readonly api = inject(AdminApi);
  private readonly language = inject(LanguageService);

  readonly queues = signal<QueueDepthDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.queues().subscribe({
      next: (response) => {
        this.queues.set(response.queues);
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }

  oldest(queue: QueueDepthDto): string {
    return queue.oldestQueuedAt === null
      ? '—'
      : relativeTime(queue.oldestQueuedAt, this.language.lang());
  }
}
```

`queues-section.component.html`:

```html
<app-settings-stack>
  <app-settings-group icon="stacks" [title]="'settings.queues.title' | transloco">
    <div groupActions class="refresh">
      <app-button (click)="load()" [disabled]="loading()">
        {{ 'settings.queues.refresh' | transloco }}
      </app-button>
    </div>

    @if (loading()) {
      <app-skeleton class="state" [label]="'settings.queues.loading' | transloco" [rows]="9" />
    } @else if (error()) {
      <app-error-banner
        class="state"
        [message]="error()!.detail || error()!.title"
        [actionLabel]="'admin.retry' | transloco"
        (action)="load()"
      />
    } @else {
      <table class="queues">
        <thead>
          <tr>
            <th scope="col">{{ 'settings.queues.queue' | transloco }}</th>
            <th scope="col" class="waiting">{{ 'settings.queues.waiting' | transloco }}</th>
            <th scope="col" class="oldest">{{ 'settings.queues.oldest' | transloco }}</th>
          </tr>
        </thead>
        <tbody>
          @for (queue of queues(); track queue.queue) {
            <tr>
              <th scope="row">{{ 'settings.queues.name.' + queue.queue | transloco }}</th>
              <td class="waiting">{{ queue.waiting }}</td>
              <td class="oldest">{{ oldest(queue) }}</td>
            </tr>
          }
        </tbody>
      </table>
    }
  </app-settings-group>
</app-settings-stack>
```

`<app-button>` takes `disabled` as an input and renders a native `<button>`, so a host `(click)` works and the spec's `.refresh button` finds it. Check that the `stacks` icon renders (`IconComponent` uses Material Symbols names); if not, use `pending_actions` in both places.

`queues-section.component.scss` (tokens only, no hex, no px spacing — see `docs/design-language.md`):

```scss
.state {
  margin-bottom: var(--space-4);
}

.queues {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--fs-sm);
}

.queues th,
.queues td {
  padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x);
  border-bottom: 1px solid var(--border);
  text-align: left;
}

.queues thead th {
  color: var(--text-secondary);
  font-weight: normal;
}

.queues .waiting {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.queues .oldest {
  color: var(--text-secondary);
  white-space: nowrap;
}
```

- [ ] **Step 5: Nav entry, route, labels**

`settings-sections.ts`, after the `admin/grafana` entry:

```ts
  { path: 'admin/queues', icon: 'stacks', labelKey: 'settings.queues.title', group: 'admin' },
```

`settings.routes.ts`, next to `admin/grafana`:

```ts
      {
        path: 'admin/queues',
        title: sectionLabelKey('admin/queues'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/queues/queues-section.component').then(
            (module) => module.QueuesSectionComponent,
          ),
      },
```

`en.json`, under `settings` (sibling of `"retention"`):

```json
    "queues": {
      "title": "Queues",
      "refresh": "Refresh",
      "loading": "Loading queues",
      "queue": "Queue",
      "waiting": "Waiting",
      "oldest": "Oldest waiting since",
      "name": {
        "failed_messages": "Failed worker messages",
        "recommendation_runs": "Recommendation runs",
        "profile_runs": "Profile runs",
        "image_verification": "Image verification",
        "share_enrichment_appview": "Bluesky embeds",
        "share_enrichment_link_cards": "Link cards",
        "share_enrichment_self_thread": "Self-reply threads",
        "transcripts": "Transcripts",
        "due_feeds": "Feeds due for refresh"
      }
    },
```

`de.json`, same place:

```json
    "queues": {
      "title": "Warteschlangen",
      "refresh": "Aktualisieren",
      "loading": "Warteschlangen werden geladen",
      "queue": "Warteschlange",
      "waiting": "Wartend",
      "oldest": "Ältestes wartet seit",
      "name": {
        "failed_messages": "Fehlgeschlagene Worker-Nachrichten",
        "recommendation_runs": "Empfehlungsläufe",
        "profile_runs": "Profilläufe",
        "image_verification": "Bildprüfung",
        "share_enrichment_appview": "Bluesky-Einbettungen",
        "share_enrichment_link_cards": "Linkkarten",
        "share_enrichment_self_thread": "Eigene Antwort-Threads",
        "transcripts": "Transkripte",
        "due_feeds": "Fällige Feeds"
      }
    },
```

If a spec enumerates settings sections (`settings-sections.spec.ts`, `settings.routes.spec.ts`, `settings-nav.component.spec.ts`), update its expected list.

- [ ] **Step 6: Run the spec and the gate**

Run (repo root): `docker compose exec -T frontend npx jest src/app/settings`
Expected: PASS.
Run (from `frontend/`): `npm run check && npm run build`
Expected: clean (`ng build` catches template errors `npm run check` misses).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/admin frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#1861): an admin Queues section lists each queue's backlog"
```

---

### Task 5: Full verification

- [ ] **Step 1: Both backend legs**

Run (from `backend/`): `composer test:parallel` and (repo root) `docker compose exec php composer test`
Expected: both green.

- [ ] **Step 2: Mutation gate**

Run (from `backend/`): `composer infection:diff`
Expected: at or above `minMsi`. An escaped mutant in a backlog method usually means a filter the test fixtures don't exercise — add the fixture, don't weaken the gate.

- [ ] **Step 3: Live check on :4200**

Make sure the containers run this branch's code (php recreate + nginx, see the stale-container memories), sign in as the dev admin, open `http://localhost:4200/settings/admin/queues`. Expected: nine rows in the order above, plausible counts (compare `due_feeds` with a `SELECT COUNT(*)` you run by hand), ages rendered, Refresh reloads. Check the phone width too.

- [ ] **Step 4: Dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`
Expected: no deprecation or error from the new route.

- [ ] **Step 5: PR**

Push `feature/1861-admin-queue-dashboard`, open the PR against `develop` with `Closes #1861`, no attribution lines.
