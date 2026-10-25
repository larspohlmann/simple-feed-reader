# Daily Arrival Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** /settings/about shows new articles per day (7-day average + 30-day chart), backed by a per-subscription
daily history table that survives entry pruning and also feeds the reading chart.

**Architecture:** A new `Service/SubscriptionHistory` module. `SubscriptionHistoryRecorder` writes one
`subscription_day` row per subscription per completed UTC day, called by `RefreshHousekeeping` right before
`EntryPruner`. `SubscriptionActivityCounter` builds the 30-day series per user from stored rows, live counts for days
still inside retention, and `null` for days nobody can know. `GET /api/reading/activity` returns both series; the
About page draws two charts through one extracted `DayChartComponent`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM, PHPUnit 12; Angular 20 standalone + signals, Jest, Transloco.

**Spec:** `docs/superpowers/specs/2026-10-25-arrival-statistics-design.md` — read it first.

## Global Constraints

- Issue #1783, branch `feature/1783-arrival-statistics` (already created, spec committed). Commits are
  `type(#1783): …`. No Claude attribution anywhere.
- Day boundary: UTC calendar day, written `'Y-m-d'`. Datetimes are stored as **naive UTC**; a Doctrine-hydrated
  `\DateTimeImmutable` carries the UTC wall clock in PHP's default zone, so its UTC day is `->format('Y-m-d')` —
  **never** `setTimezone()` it first (Strato workers run non-UTC).
- Window: the 30 UTC days ending today (`today − 29 … today`). Completed days are `today − 29 … today − 1`.
- Arrivals count from the day **after** the subscription's `createdAt` day; reads count from that day itself.
- Retention horizon: a day `D` is live-countable iff `D ≥ today − (N − 1)`, N = `socialDays` when
  `feed.network IS NOT NULL`, else `entryDays` (`RetentionSettings::current()`).
- CLAUDE.md applies in full: `final readonly`, intent-revealing names, no abbreviations, guard clauses, queries only
  in `src/Repository`, no `@param`/`@return` that repeats the signature, comments only when a reader would get the
  code wrong without them, PHPMD-clean touched files, HTTP statuses as `Response::HTTP_*`.
- Frontend: standalone components + signals, styles in sibling `.scss`, tokens only (no hex, no px spacing), Prettier
  100 columns, both `en.json` and `de.json`.
- Run frontend tests inside the container: `docker compose exec -T frontend npm test -- <pattern>`. Never run two
  Jest processes at once.

## File Map

Backend, created:
- `backend/src/Entity/SubscriptionDay.php` — the history row.
- `backend/migrations/Version20261025120000.php` — creates `subscription_day`.
- `backend/src/Repository/SubscriptionDayRepository.php` — stored rows since a day.
- `backend/src/Repository/SubscriptionActivityRepository.php` — tracked subscriptions, arrival and view instants.
- `backend/src/Service/SubscriptionHistory/Support/UtcDay.php` — day-string arithmetic.
- `backend/src/Service/SubscriptionHistory/Model/{DayCountModel,DayCountsModel,TrackedSubscriptionModel,DayWindowModel,RetentionHorizonModel,ActivityDayModel,SubscriptionActivityModel}.php`
- `backend/src/Service/SubscriptionHistory/Pass/{DayTally,DaySources}.php`
- `backend/src/Service/SubscriptionHistory/{RetentionHorizon,LiveDayCounter,SubscriptionHistoryRecorder,SubscriptionActivityCounter}.php`
- Tests mirroring each under `backend/tests/…`.

Backend, modified:
- `backend/src/Service/Refresh/RefreshHousekeeping.php`, `backend/tests/Support/RefreshRunners.php`,
  `backend/tests/Service/Refresh/RefreshHousekeepingTest.php`
- `backend/src/Service/Reading/ReadingActivityCounter.php`, `…/Model/ReadingActivityModel.php`,
  `backend/src/Http/ReadingActivityJson.php`, `backend/src/Controller/Api/ReadingActivityController.php`,
  `backend/tests/Controller/Api/ReadingActivityControllerTest.php`

Backend, deleted (Task 6): `Service/Reading/Model/ReadingWindowModel.php` + its test,
`EntryStateRepository::viewedAtSince()` + `tests/Repository/ViewedAtSinceTest.php`.

Frontend:
- Create `frontend/src/app/settings/about/day-chart.ts` (+ `.spec.ts`), replacing `reading-chart.ts` (+ spec).
- Create `frontend/src/app/settings/about/day-chart.component.{ts,html,scss,spec.ts}`.
- Modify `about-section.component.{ts,html,scss,spec.ts}`, `settings.models.ts`, `settings-api.ts`,
  `settings-api.spec.ts`, `frontend/public/i18n/{en,de}.json`.

---

### Task 1: The history row — entity, migration, stored-row query, count models

**Files:**
- Create: `backend/src/Entity/SubscriptionDay.php`
- Create: `backend/migrations/Version20261025120000.php`
- Create: `backend/src/Repository/SubscriptionDayRepository.php`
- Create: `backend/src/Service/SubscriptionHistory/Support/UtcDay.php`
- Create: `backend/src/Service/SubscriptionHistory/Model/DayCountModel.php`
- Create: `backend/src/Service/SubscriptionHistory/Model/DayCountsModel.php`
- Test: `backend/tests/Repository/SubscriptionDayRepositoryTest.php`
- Test: `backend/tests/Service/SubscriptionHistory/Support/UtcDayTest.php`

**Interfaces:**
- Produces:
  - `SubscriptionDay::__construct(Subscription $subscription, \DateTimeImmutable $day, int $arrived, int $readCount)`
  - `UtcDay::of(\DateTimeImmutable $instant): string`, `UtcDay::start(string $day): \DateTimeImmutable`,
    `UtcDay::shift(string $day, int $days): string`
  - `DayCountModel(int $arrived, int $read)`, `DayCountModel::none()`, `->plus(DayCountModel): DayCountModel`
  - `DayCountsModel(array<int, array<string, DayCountModel>> $bySubscription)`, `->has(int, string): bool`,
    `->of(int, string): DayCountModel` (zero when absent)
  - `SubscriptionDayRepository::since(list<int> $subscriptionIds, string $oldestDay): DayCountsModel`

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/SubscriptionHistory/Support/UtcDayTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Support;

use App\Service\SubscriptionHistory\Support\UtcDay;
use PHPUnit\Framework\TestCase;

final class UtcDayTest extends TestCase
{
    public function testTheDayIsTheStoredWallClockDateWhateverTheDefaultZone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            self::assertSame('2026-10-24', UtcDay::of(new \DateTimeImmutable('2026-10-24 23:30:00')));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testStartIsMidnightOfThatDay(): void
    {
        self::assertSame('2026-10-24 00:00:00', UtcDay::start('2026-10-24')->format('Y-m-d H:i:s'));
    }

    public function testShiftCrossesMonthEnds(): void
    {
        self::assertSame('2026-09-30', UtcDay::shift('2026-10-01', -1));
        self::assertSame('2026-11-01', UtcDay::shift('2026-10-31', 1));
    }
}
```

`backend/tests/Repository/SubscriptionDayRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Repository\SubscriptionDayRepository;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Tests\DbTestCase;

final class SubscriptionDayRepositoryTest extends DbTestCase
{
    private function repository(): SubscriptionDayRepository
    {
        $repository = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $repository);

        return $repository;
    }

    private function subscription(string $email): Subscription
    {
        $when = new \DateTimeImmutable('2026-09-01 00:00:00');
        $user = new User($email, $when);
        $feed = new Feed('https://example.com/' . $email . '.xml');
        $subscription = new Subscription($user, $feed, $when);
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function day(Subscription $subscription, string $day, int $arrived, int $read): void
    {
        $this->entityManager->persist(new SubscriptionDay($subscription, UtcDay::start($day), $arrived, $read));
    }

    public function testReturnsRowsOnOrAfterTheOldestDayForTheAskedSubscriptionsOnly(): void
    {
        $mine = $this->subscription('mine@example.com');
        $other = $this->subscription('other@example.com');
        $this->day($mine, '2026-10-09', 1, 1);
        $this->day($mine, '2026-10-10', 4, 2);
        $this->day($other, '2026-10-10', 9, 9);
        $this->entityManager->flush();

        $stored = $this->repository()->since([$mine->requireId()], '2026-10-10');

        self::assertFalse($stored->has($mine->requireId(), '2026-10-09'));
        self::assertTrue($stored->has($mine->requireId(), '2026-10-10'));
        self::assertSame(4, $stored->of($mine->requireId(), '2026-10-10')->arrived);
        self::assertSame(2, $stored->of($mine->requireId(), '2026-10-10')->read);
        self::assertFalse($stored->has($other->requireId(), '2026-10-10'));
    }

    public function testAStoredZeroIsPresentNotAbsent(): void
    {
        $mine = $this->subscription('zero@example.com');
        $this->day($mine, '2026-10-10', 0, 0);
        $this->entityManager->flush();

        self::assertTrue($this->repository()->since([$mine->requireId()], '2026-10-01')->has($mine->requireId(), '2026-10-10'));
    }

    public function testNoSubscriptionsReadsNothing(): void
    {
        self::assertFalse($this->repository()->since([], '2026-10-01')->has(1, '2026-10-10'));
    }

    public function testDeletingTheSubscriptionDeletesItsHistory(): void
    {
        $mine = $this->subscription('cascade@example.com');
        $this->day($mine, '2026-10-10', 3, 0);
        $this->entityManager->flush();
        $subscriptionId = $mine->requireId();

        $this->entityManager->getConnection()->executeStatement('DELETE FROM subscription WHERE id = ?', [$subscriptionId]);

        self::assertFalse($this->repository()->since([$subscriptionId], '2026-10-01')->has($subscriptionId, '2026-10-10'));
    }
}
```

If SQLite's foreign keys are off in the test connection the cascade test fails; check how other cascade tests do it
(`grep -rn "ON DELETE CASCADE\|onDelete" backend/tests | head`) and follow that pattern rather than deleting the test.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Repository/SubscriptionDayRepositoryTest.php tests/Service/SubscriptionHistory/Support/UtcDayTest.php`
Expected: errors, classes `SubscriptionDay` / `UtcDay` not found.

- [ ] **Step 3: Implement**

`backend/src/Service/SubscriptionHistory/Support/UtcDay.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Support;

/** UTC calendar days as 'Y-m-d' strings, which compare correctly as strings. */
final class UtcDay
{
    /** The instant must hold a UTC wall clock (naive UTC from Doctrine, or NaiveUtcClock); it is not converted. */
    public static function of(\DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d');
    }

    public static function start(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day . ' 00:00:00');
    }

    public static function shift(string $day, int $days): string
    {
        return self::start($day)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    private function __construct()
    {
    }
}
```

`backend/src/Service/SubscriptionHistory/Model/DayCountModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

/** One subscription's (or one account's) new and read articles on one day. */
final readonly class DayCountModel
{
    public function __construct(public int $arrived, public int $read)
    {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function plus(self $other): self
    {
        return new self($this->arrived + $other->arrived, $this->read + $other->read);
    }
}
```

`backend/src/Service/SubscriptionHistory/Model/DayCountsModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class DayCountsModel
{
    /**
     * @param array<int, array<string, DayCountModel>> $bySubscription subscription id => 'Y-m-d' => counts
     */
    public function __construct(private array $bySubscription)
    {
    }

    public function has(int $subscriptionId, string $day): bool
    {
        return isset($this->bySubscription[$subscriptionId][$day]);
    }

    public function of(int $subscriptionId, string $day): DayCountModel
    {
        return $this->bySubscription[$subscriptionId][$day] ?? DayCountModel::none();
    }
}
```

`backend/src/Entity/SubscriptionDay.php` (property style follows `Subscription.php`):

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionDayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One subscription's counts for one completed UTC day; written once, and outlives the entries it counted. */
#[ORM\Entity(repositoryClass: SubscriptionDayRepository::class)]
#[ORM\Table(name: 'subscription_day')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_day', columns: ['subscription_id', 'day'])]
final class SubscriptionDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subscription $subscription;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column]
    private int $arrived;

    #[ORM\Column]
    private int $readCount;

    public function __construct(Subscription $subscription, \DateTimeImmutable $day, int $arrived, int $readCount)
    {
        $this->subscription = $subscription;
        $this->day = $day;
        $this->arrived = $arrived;
        $this->readCount = $readCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getArrived(): int
    {
        return $this->arrived;
    }

    public function getReadCount(): int
    {
        return $this->readCount;
    }
}
```

`backend/src/Repository/SubscriptionDayRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionDay;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Support\UtcDay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionDay>
 */
final class SubscriptionDayRepository extends ServiceEntityRepository
{
    private const int ID_CHUNK_SIZE = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionDay::class);
    }

    /**
     * @param list<int> $subscriptionIds
     */
    public function since(array $subscriptionIds, string $oldestDay): DayCountsModel
    {
        $bySubscription = [];
        foreach (array_chunk($subscriptionIds, self::ID_CHUNK_SIZE) as $chunk) {
            foreach ($this->rowsSince($chunk, $oldestDay) as $row) {
                $bySubscription[(int) $row['subscriptionId']][UtcDay::of($row['day'])] =
                    new DayCountModel((int) $row['arrived'], (int) $row['readCount']);
            }
        }

        return new DayCountsModel($bySubscription);
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<array{subscriptionId: int|string, day: \DateTimeImmutable, arrived: int|string, readCount: int|string}>
     */
    private function rowsSince(array $subscriptionIds, string $oldestDay): array
    {
        /** @var list<array{subscriptionId: int|string, day: \DateTimeImmutable, arrived: int|string, readCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('history')
            ->select(
                'IDENTITY(history.subscription) AS subscriptionId',
                'history.day AS day',
                'history.arrived AS arrived',
                'history.readCount AS readCount',
            )
            ->andWhere('IDENTITY(history.subscription) IN (:subscriptionIds)')
            ->andWhere('history.day >= :oldestDay')
            ->setParameter('subscriptionIds', $subscriptionIds)
            ->setParameter('oldestDay', UtcDay::start($oldestDay), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
```

Migration: generate the DDL, don't hand-write index names. Natively (SQLite):
`cd backend && bin/console doctrine:migrations:diff --no-interaction` and, in Docker (MySQL):
`docker compose exec php bin/console doctrine:migrations:diff --no-interaction`. Take the `CREATE TABLE` (+ any
`CREATE INDEX`) statements from each generated file, delete both generated files, and write
`backend/migrations/Version20261025120000.php` in the shape of `Version20261023120000.php` (platform branch,
`abortIfUnsupportedPlatform()`, `isTransactional(): false`), with:
- description `'Create subscription_day: per-subscription daily new and read counts (#1783)'`,
- `up()`: `$this->skipIf($schema->hasTable('subscription_day'), 'subscription_day already exists.');` then the
  MySQL or SQLite statements,
- `down()`: `skipIf(!$schema->hasTable(...))`, then `DROP TABLE subscription_day`.
The MySQL table must carry the FK `ON DELETE CASCADE` and the unique `uniq_subscription_day (subscription_id, day)`;
the SQLite one the same via `CONSTRAINT … REFERENCES subscription (id) ON DELETE CASCADE`. If the diff includes
unrelated statements, the schema had drifted before this branch: keep only `subscription_day` lines.

- [ ] **Step 4: Run tests and the migration check**

Run: `cd backend && php bin/phpunit tests/Repository/SubscriptionDayRepositoryTest.php tests/Service/SubscriptionHistory/Support/UtcDayTest.php`
Expected: PASS.

Then verify the migration from empty on SQLite (use a throwaway file, never the dev database):
`cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" bin/console doctrine:schema:validate; rm -f var/migrate-check.db`
Expected: `[OK] The database schema is in sync with the mapping files.` The MySQL leg runs in Task 8.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/SubscriptionDay.php backend/migrations/Version20261025120000.php backend/src/Repository/SubscriptionDayRepository.php backend/src/Service/SubscriptionHistory backend/tests/Repository/SubscriptionDayRepositoryTest.php backend/tests/Service/SubscriptionHistory
git commit -m "feat(#1783): subscription_day history table"
```

---

### Task 2: Activity queries — tracked subscriptions, arrivals, views

**Files:**
- Create: `backend/src/Service/SubscriptionHistory/Model/TrackedSubscriptionModel.php`
- Create: `backend/src/Repository/SubscriptionActivityRepository.php`
- Test: `backend/tests/Repository/SubscriptionActivityRepositoryTest.php`

**Interfaces:**
- Consumes: `UtcDay` (Task 1).
- Produces:
  - `TrackedSubscriptionModel(int $subscriptionId, string $createdDay, bool $isSocial)`
  - `SubscriptionActivityRepository::trackedSubscriptions(): list<TrackedSubscriptionModel>` (every user)
  - `SubscriptionActivityRepository::trackedSubscriptionsOf(int $userId): list<TrackedSubscriptionModel>`
  - `SubscriptionActivityRepository::arrivalsSince(list<int> $subscriptionIds, \DateTimeImmutable $since): list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>`
    — every entry of each subscription's feed with `createdAt >= $since`, one row per subscription.
  - `SubscriptionActivityRepository::viewsSince(list<int> $subscriptionIds, \DateTimeImmutable $since): list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>`
    — the subscription owner's `viewedAt >= $since` on that feed's entries.

- [ ] **Step 1: Write the failing test**

`backend/tests/Repository/SubscriptionActivityRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionActivityRepository;
use App\Tests\DbTestCase;

final class SubscriptionActivityRepositoryTest extends DbTestCase
{
    private function repository(): SubscriptionActivityRepository
    {
        $repository = self::getContainer()->get(SubscriptionActivityRepository::class);
        self::assertInstanceOf(SubscriptionActivityRepository::class, $repository);

        return $repository;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-09-01 00:00:00'));
        $this->entityManager->persist($user);

        return $user;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed, string $at): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable($at));
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function entry(Feed $feed, string $guid, string $createdAt): Entry
    {
        $created = new \DateTimeImmutable($createdAt);
        $entry = new Entry($feed, $guid, null, $guid, $created, $created);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * @param list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}> $rows
     *
     * @return list<string>
     */
    private function flatten(array $rows): array
    {
        $flat = array_map(
            static fn (array $row): string => $row['subscriptionId'] . '@' . $row['happenedAt']->format('Y-m-d H:i'),
            $rows,
        );
        sort($flat);

        return $flat;
    }

    public function testTracksEachSubscriptionWithItsCreatedDayAndWhetherItIsSocial(): void
    {
        $user = $this->user('tracked@example.com');
        $social = $this->feed('https://bsky.example/home');
        $social->classifyAs(SocialNetwork::Bluesky);
        $plain = $this->subscribe($user, $this->feed('https://example.com/a.xml'), '2026-10-03 23:59:00');
        $timeline = $this->subscribe($user, $social, '2026-10-04 00:00:00');
        $this->subscribe($this->user('someone@example.com'), $this->feed('https://example.com/b.xml'), '2026-10-05 08:00:00');
        $this->entityManager->flush();

        $mine = $this->repository()->trackedSubscriptionsOf($user->requireId());
        usort($mine, static fn ($left, $right): int => $left->subscriptionId <=> $right->subscriptionId);

        self::assertCount(2, $mine);
        self::assertSame([$plain->requireId(), '2026-10-03', false], [$mine[0]->subscriptionId, $mine[0]->createdDay, $mine[0]->isSocial]);
        self::assertSame([$timeline->requireId(), '2026-10-04', true], [$mine[1]->subscriptionId, $mine[1]->createdDay, $mine[1]->isSocial]);
        self::assertCount(3, $this->repository()->trackedSubscriptions());
    }

    public function testArrivalsAreTheFeedsEntriesSinceTheBoundPerSubscription(): void
    {
        $feed = $this->feed('https://example.com/shared.xml');
        $first = $this->subscribe($this->user('one@example.com'), $feed, '2026-10-01 00:00:00');
        $second = $this->subscribe($this->user('two@example.com'), $feed, '2026-10-01 00:00:00');
        $this->entry($feed, 'old', '2026-10-09 23:59:59');
        $this->entry($feed, 'new', '2026-10-10 00:00:00');
        $this->entityManager->flush();

        $rows = $this->repository()->arrivalsSince([$first->requireId()], new \DateTimeImmutable('2026-10-10 00:00:00'));

        self::assertSame([$first->requireId() . '@2026-10-10 00:00'], $this->flatten($rows));
        self::assertNotContains($second->requireId(), array_column($rows, 'subscriptionId'));
    }

    public function testViewsAreTheOwnersOpensOfThatFeedSinceTheBound(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $feed = $this->feed('https://example.com/read.xml');
        $mine = $this->subscribe($owner, $feed, '2026-10-01 00:00:00');
        $this->subscribe($stranger, $feed, '2026-10-01 00:00:00');
        $entry = $this->entry($feed, 'a', '2026-10-01 00:00:00');
        $ownState = new EntryState($owner, $entry);
        $ownState->markViewed(new \DateTimeImmutable('2026-10-11 09:00:00'));
        $strangerState = new EntryState($stranger, $entry);
        $strangerState->markViewed(new \DateTimeImmutable('2026-10-11 10:00:00'));
        $this->entityManager->persist($ownState);
        $this->entityManager->persist($strangerState);
        $this->entityManager->flush();

        $rows = $this->repository()->viewsSince([$mine->requireId()], new \DateTimeImmutable('2026-10-10 00:00:00'));

        self::assertSame([$mine->requireId() . '@2026-10-11 09:00'], $this->flatten($rows));
    }

    public function testNoSubscriptionIdsQueriesNothing(): void
    {
        self::assertSame([], $this->repository()->arrivalsSince([], new \DateTimeImmutable('2026-10-10')));
        self::assertSame([], $this->repository()->viewsSince([], new \DateTimeImmutable('2026-10-10')));
    }
}
```

If `SubscriptionActivityRepository` is not public in the test container, fetch it the way
`RetentionRepository` tests do (`grep -rn "RetentionRepository" backend/tests | head`) or construct it with
`new SubscriptionActivityRepository($this->entityManager)`.

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/SubscriptionActivityRepositoryTest.php`
Expected: error, class not found.

- [ ] **Step 3: Implement**

`backend/src/Service/SubscriptionHistory/Model/TrackedSubscriptionModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class TrackedSubscriptionModel
{
    public function __construct(
        public int $subscriptionId,
        public string $createdDay,
        public bool $isSocial,
    ) {
    }
}
```

`backend/src/Repository/SubscriptionActivityRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Support\UtcDay;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** What the daily history counts: subscriptions, their feeds' arrivals, their owners' opens. */
final readonly class SubscriptionActivityRepository
{
    private const int ID_CHUNK_SIZE = 500;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<TrackedSubscriptionModel> */
    public function trackedSubscriptions(): array
    {
        return $this->tracked($this->trackedQuery());
    }

    /** @return list<TrackedSubscriptionModel> */
    public function trackedSubscriptionsOf(int $userId): array
    {
        return $this->tracked(
            $this->trackedQuery()->andWhere('IDENTITY(subscription.user) = :user')->setParameter('user', $userId),
        );
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>
     */
    public function arrivalsSince(array $subscriptionIds, \DateTimeImmutable $since): array
    {
        return $this->inChunks($subscriptionIds, fn (array $chunk): QueryBuilder => $this->entityManager
            ->createQueryBuilder()
            ->select('subscription.id AS subscriptionId', 'entry.createdAt AS happenedAt')
            ->from(Entry::class, 'entry')
            ->join(Subscription::class, 'subscription', 'WITH', 'subscription.feed = entry.feed')
            ->andWhere('subscription.id IN (:subscriptionIds)')
            ->andWhere('entry.createdAt >= :since')
            ->setParameter('subscriptionIds', $chunk)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE));
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>
     */
    public function viewsSince(array $subscriptionIds, \DateTimeImmutable $since): array
    {
        return $this->inChunks($subscriptionIds, fn (array $chunk): QueryBuilder => $this->entityManager
            ->createQueryBuilder()
            ->select('subscription.id AS subscriptionId', 'state.viewedAt AS happenedAt')
            ->from(EntryState::class, 'state')
            ->join('state.entry', 'entry')
            ->join(
                Subscription::class,
                'subscription',
                'WITH',
                'subscription.feed = entry.feed AND subscription.user = state.user',
            )
            ->andWhere('subscription.id IN (:subscriptionIds)')
            ->andWhere('state.viewedAt >= :since')
            ->setParameter('subscriptionIds', $chunk)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE));
    }

    private function trackedQuery(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select(
                'subscription.id AS subscriptionId',
                'subscription.createdAt AS createdAt',
                'CASE WHEN feed.network IS NULL THEN 0 ELSE 1 END AS isSocial',
            )
            ->from(Subscription::class, 'subscription')
            ->join('subscription.feed', 'feed');
    }

    /** @return list<TrackedSubscriptionModel> */
    private function tracked(QueryBuilder $query): array
    {
        /** @var list<array{subscriptionId: int|string, createdAt: \DateTimeImmutable, isSocial: int|string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): TrackedSubscriptionModel => new TrackedSubscriptionModel(
                (int) $row['subscriptionId'],
                UtcDay::of($row['createdAt']),
                (int) $row['isSocial'] === 1,
            ),
            $rows,
        );
    }

    /**
     * @param list<int>                         $subscriptionIds
     * @param callable(list<int>): QueryBuilder $queryFor
     *
     * @return list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>
     */
    private function inChunks(array $subscriptionIds, callable $queryFor): array
    {
        $instants = [];
        foreach (array_chunk($subscriptionIds, self::ID_CHUNK_SIZE) as $chunk) {
            /** @var list<array{subscriptionId: int|string, happenedAt: \DateTimeImmutable}> $rows */
            $rows = $queryFor($chunk)->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $instants[] = ['subscriptionId' => (int) $row['subscriptionId'], 'happenedAt' => $row['happenedAt']];
            }
        }

        return $instants;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd backend && php bin/phpunit tests/Repository/SubscriptionActivityRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/SubscriptionActivityRepository.php backend/src/Service/SubscriptionHistory/Model/TrackedSubscriptionModel.php backend/tests/Repository/SubscriptionActivityRepositoryTest.php
git commit -m "feat(#1783): subscription activity queries"
```

---

### Task 3: Day window, retention horizon, live counts, day sources

**Files:**
- Create: `backend/src/Service/SubscriptionHistory/Model/DayWindowModel.php`
- Create: `backend/src/Service/SubscriptionHistory/Model/RetentionHorizonModel.php`
- Create: `backend/src/Service/SubscriptionHistory/RetentionHorizon.php`
- Create: `backend/src/Service/SubscriptionHistory/Pass/DayTally.php`
- Create: `backend/src/Service/SubscriptionHistory/Pass/DaySources.php`
- Create: `backend/src/Service/SubscriptionHistory/LiveDayCounter.php`
- Test: `backend/tests/Service/SubscriptionHistory/Model/DayWindowModelTest.php`
- Test: `backend/tests/Service/SubscriptionHistory/Pass/DaySourcesTest.php`
- Test: `backend/tests/Service/SubscriptionHistory/LiveDayCounterTest.php`

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces:
  - `DayWindowModel::endingOn(string $today, int $dayCount): self`; public `list<string> $dates` (oldest first,
    today last); `->oldest(): string`, `->today(): string`, `->completedDates(): list<string>`,
    `->lastCompleted(int $count): list<string>`
  - `RetentionHorizonModel(string $oldestFeedDay, string $oldestSocialDay)`,
    `->isCountable(TrackedSubscriptionModel, string $day): bool`
  - `RetentionHorizon::asOf(string $today): RetentionHorizonModel`
  - `DaySources(DayCountsModel $stored, RetentionHorizonModel $horizon)`: `contributes(sub, day): bool`,
    `isKnown(sub, day): bool`, `needsLive(sub, day): bool`,
    `oldestLiveDay(list<TrackedSubscriptionModel>, list<string> $dates): ?string`,
    `countFor(sub, day, DayCountsModel $live): DayCountModel`
  - `LiveDayCounter::since(list<TrackedSubscriptionModel> $subscriptions, string $oldestDay): DayCountsModel` —
    arrivals on or before a subscription's `createdDay` are dropped; reads are not.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/SubscriptionHistory/Model/DayWindowModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Model\DayWindowModel;
use PHPUnit\Framework\TestCase;

final class DayWindowModelTest extends TestCase
{
    public function testListsTheDaysEndingTodayOldestFirst(): void
    {
        $window = DayWindowModel::endingOn('2026-10-02', 4);

        self::assertSame(['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'], $window->dates);
        self::assertSame('2026-09-29', $window->oldest());
        self::assertSame('2026-10-02', $window->today());
        self::assertSame(['2026-09-29', '2026-09-30', '2026-10-01'], $window->completedDates());
        self::assertSame(['2026-09-30', '2026-10-01'], $window->lastCompleted(2));
    }
}
```

`backend/tests/Service/SubscriptionHistory/Pass/DaySourcesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\RetentionHorizonModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use PHPUnit\Framework\TestCase;

final class DaySourcesTest extends TestCase
{
    private const string OLDEST_FEED_DAY = '2026-07-01';
    private const string OLDEST_SOCIAL_DAY = '2026-10-19';

    private function sources(DayCountsModel $stored): DaySources
    {
        return new DaySources($stored, new RetentionHorizonModel(self::OLDEST_FEED_DAY, self::OLDEST_SOCIAL_DAY));
    }

    public function testASocialDayBeforeItsHorizonIsUnknownUnlessStored(): void
    {
        $social = new TrackedSubscriptionModel(1, '2026-09-01', true);
        $stored = new DayCountsModel([1 => ['2026-10-17' => new DayCountModel(5, 1)]]);

        self::assertTrue($this->sources($stored)->isKnown($social, '2026-10-17'));
        self::assertFalse($this->sources($stored)->isKnown($social, '2026-10-18'));
        self::assertTrue($this->sources($stored)->isKnown($social, self::OLDEST_SOCIAL_DAY));
    }

    public function testAPlainFeedUsesTheLongerHorizon(): void
    {
        $plain = new TrackedSubscriptionModel(2, '2026-09-01', false);

        self::assertTrue($this->sources(new DayCountsModel([]))->isKnown($plain, '2026-10-01'));
    }

    public function testDaysBeforeTheSubscriptionDoNotContribute(): void
    {
        $late = new TrackedSubscriptionModel(3, '2026-10-20', false);

        self::assertFalse($this->sources(new DayCountsModel([]))->contributes($late, '2026-10-19'));
        self::assertTrue($this->sources(new DayCountsModel([]))->contributes($late, '2026-10-20'));
        self::assertFalse($this->sources(new DayCountsModel([]))->needsLive($late, '2026-10-19'));
    }

    public function testAStoredDayNeedsNoLiveCountAndWins(): void
    {
        $plain = new TrackedSubscriptionModel(4, '2026-09-01', false);
        $stored = new DayCountsModel([4 => ['2026-10-10' => new DayCountModel(7, 2)]]);
        $live = new DayCountsModel([4 => ['2026-10-10' => new DayCountModel(1, 1)]]);

        self::assertFalse($this->sources($stored)->needsLive($plain, '2026-10-10'));
        self::assertSame(7, $this->sources($stored)->countFor($plain, '2026-10-10', $live)->arrived);
        self::assertSame(1, $this->sources($stored)->countFor($plain, '2026-10-11', new DayCountsModel([4 => ['2026-10-11' => new DayCountModel(1, 0)]]))->arrived);
    }

    public function testOldestLiveDayIsTheEarliestDayAnySubscriptionMustCountLive(): void
    {
        $plain = new TrackedSubscriptionModel(5, '2026-10-12', false);
        $social = new TrackedSubscriptionModel(6, '2026-09-01', true);
        $stored = new DayCountsModel([5 => ['2026-10-12' => new DayCountModel(0, 0)]]);
        $dates = ['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-19', '2026-10-20'];

        self::assertSame('2026-10-13', $this->sources($stored)->oldestLiveDay([$plain, $social], $dates));
        self::assertNull($this->sources($stored)->oldestLiveDay([], $dates));
    }
}
```

`backend/tests/Service/SubscriptionHistory/LiveDayCounterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionActivityRepository;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Tests\DbTestCase;

final class LiveDayCounterTest extends DbTestCase
{
    public function testBucketsArrivalsAndReadsByUtcDayAndSkipsTheSubscribeDaysArrivals(): void
    {
        $user = new User('live@example.com', new \DateTimeImmutable('2026-09-01 00:00:00'));
        $feed = new Feed('https://example.com/live.xml');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-10 15:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        $backlog = $this->entry($feed, 'backlog', '2026-10-10 15:00:05');
        $this->entry($feed, 'late-evening', '2026-10-11 23:59:59');
        $this->entry($feed, 'next-day', '2026-10-12 00:00:00');
        $state = new EntryState($user, $backlog);
        $state->markViewed(new \DateTimeImmutable('2026-10-10 16:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
        $tracked = new TrackedSubscriptionModel($subscription->requireId(), '2026-10-10', false);

        $counts = new LiveDayCounter(new SubscriptionActivityRepository($this->entityManager))
            ->since([$tracked], '2026-10-10');

        $subscriptionId = $subscription->requireId();
        self::assertSame(0, $counts->of($subscriptionId, '2026-10-10')->arrived);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-10')->read);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-11')->arrived);
        self::assertSame(1, $counts->of($subscriptionId, '2026-10-12')->arrived);
    }

    public function testNoSubscriptionsCountsNothing(): void
    {
        $counts = new LiveDayCounter(new SubscriptionActivityRepository($this->entityManager))->since([], '2026-10-10');

        self::assertFalse($counts->has(1, '2026-10-10'));
    }

    private function entry(Feed $feed, string $guid, string $createdAt): Entry
    {
        $created = new \DateTimeImmutable($createdAt);
        $entry = new Entry($feed, $guid, null, $guid, $created, $created);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/SubscriptionHistory`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`backend/src/Service/SubscriptionHistory/Model/DayWindowModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Support\UtcDay;

final readonly class DayWindowModel
{
    /**
     * @param list<string> $dates oldest first, today last
     */
    private function __construct(public array $dates)
    {
    }

    public static function endingOn(string $today, int $dayCount): self
    {
        $dates = [];
        for ($offset = $dayCount - 1; $offset >= 0; --$offset) {
            $dates[] = UtcDay::shift($today, -$offset);
        }

        return new self($dates);
    }

    public function oldest(): string
    {
        return $this->dates[0];
    }

    public function today(): string
    {
        return $this->dates[\count($this->dates) - 1];
    }

    /** @return list<string> */
    public function completedDates(): array
    {
        return \array_slice($this->dates, 0, -1);
    }

    /** @return list<string> */
    public function lastCompleted(int $count): array
    {
        return \array_slice($this->completedDates(), -$count);
    }
}
```

`backend/src/Service/SubscriptionHistory/Model/RetentionHorizonModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

/** The oldest day whose entries the age pass has not touched yet: older days may be partly pruned. */
final readonly class RetentionHorizonModel
{
    public function __construct(private string $oldestFeedDay, private string $oldestSocialDay)
    {
    }

    public function isCountable(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $day >= ($subscription->isSocial ? $this->oldestSocialDay : $this->oldestFeedDay);
    }
}
```

`backend/src/Service/SubscriptionHistory/RetentionHorizon.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Service\Retention\RetentionSettings;
use App\Service\SubscriptionHistory\Model\RetentionHorizonModel;
use App\Service\SubscriptionHistory\Support\UtcDay;

/** The age pass deletes entries older than N days from now, so day today − N is partial and today − (N − 1) whole. */
final readonly class RetentionHorizon
{
    public function __construct(private RetentionSettings $settings)
    {
    }

    public function asOf(string $today): RetentionHorizonModel
    {
        $windows = $this->settings->current();

        return new RetentionHorizonModel(
            UtcDay::shift($today, 1 - $windows->entryDays),
            UtcDay::shift($today, 1 - $windows->socialDays),
        );
    }
}
```

`backend/src/Service/SubscriptionHistory/Pass/DayTally.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;

final class DayTally
{
    /** @var array<int, array<string, array{arrived: int, read: int}>> */
    private array $tallies = [];

    public function addArrival(int $subscriptionId, string $day): void
    {
        $this->tallies[$subscriptionId][$day]['arrived'] = ($this->tallies[$subscriptionId][$day]['arrived'] ?? 0) + 1;
    }

    public function addRead(int $subscriptionId, string $day): void
    {
        $this->tallies[$subscriptionId][$day]['read'] = ($this->tallies[$subscriptionId][$day]['read'] ?? 0) + 1;
    }

    public function counts(): DayCountsModel
    {
        return new DayCountsModel(array_map(
            static fn (array $days): array => array_map(
                static fn (array $tally): DayCountModel => new DayCountModel($tally['arrived'] ?? 0, $tally['read'] ?? 0),
                $days,
            ),
            $this->tallies,
        ));
    }
}
```

(Adjust the inner array shape annotation to `array{arrived?: int, read?: int}` if PHPStan asks for it.)

`backend/src/Service/SubscriptionHistory/Pass/DaySources.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Pass;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\RetentionHorizonModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;

/** Where a subscription's day comes from: its stored row, a live count while the entries last, or nowhere. */
final readonly class DaySources
{
    public function __construct(private DayCountsModel $stored, private RetentionHorizonModel $horizon)
    {
    }

    public function contributes(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $day >= $subscription->createdDay;
    }

    public function isKnown(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $this->stored->has($subscription->subscriptionId, $day) || $this->horizon->isCountable($subscription, $day);
    }

    public function needsLive(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $this->contributes($subscription, $day)
            && !$this->stored->has($subscription->subscriptionId, $day)
            && $this->horizon->isCountable($subscription, $day);
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     * @param list<string>                   $dates oldest first
     */
    public function oldestLiveDay(array $subscriptions, array $dates): ?string
    {
        foreach ($dates as $day) {
            foreach ($subscriptions as $subscription) {
                if ($this->needsLive($subscription, $day)) {
                    return $day;
                }
            }
        }

        return null;
    }

    public function countFor(TrackedSubscriptionModel $subscription, string $day, DayCountsModel $live): DayCountModel
    {
        if ($this->stored->has($subscription->subscriptionId, $day)) {
            return $this->stored->of($subscription->subscriptionId, $day);
        }

        return $live->of($subscription->subscriptionId, $day);
    }
}
```

`backend/src/Service/SubscriptionHistory/LiveDayCounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Repository\SubscriptionActivityRepository;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DayTally;
use App\Service\SubscriptionHistory\Support\UtcDay;

/** Counts days straight from entries and entry states, which only works while retention still holds them. */
final readonly class LiveDayCounter
{
    public function __construct(private SubscriptionActivityRepository $activity)
    {
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     */
    public function since(array $subscriptions, string $oldestDay): DayCountsModel
    {
        $createdDays = [];
        foreach ($subscriptions as $subscription) {
            $createdDays[$subscription->subscriptionId] = $subscription->createdDay;
        }
        $subscriptionIds = array_keys($createdDays);
        $since = UtcDay::start($oldestDay);
        $tally = new DayTally();

        foreach ($this->activity->arrivalsSince($subscriptionIds, $since) as $arrival) {
            $day = UtcDay::of($arrival['happenedAt']);
            if ($day > $createdDays[$arrival['subscriptionId']]) {
                $tally->addArrival($arrival['subscriptionId'], $day);
            }
        }
        foreach ($this->activity->viewsSince($subscriptionIds, $since) as $view) {
            $tally->addRead($view['subscriptionId'], UtcDay::of($view['happenedAt']));
        }

        return $tally->counts();
    }
}
```

If PHPMD flags `since()` (it shouldn't at this size), extract `tallyArrivals()` / `tallyReads()`.

- [ ] **Step 4: Run to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/SubscriptionHistory`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/SubscriptionHistory backend/tests/Service/SubscriptionHistory
git commit -m "feat(#1783): live day counts, retention horizon and day sources"
```

---

### Task 4: Recorder, wired before the pruner

**Files:**
- Create: `backend/src/Service/SubscriptionHistory/SubscriptionHistoryRecorder.php`
- Modify: `backend/src/Service/Refresh/RefreshHousekeeping.php`
- Modify: `backend/tests/Support/RefreshRunners.php:169-181`
- Modify: `backend/tests/Service/Refresh/RefreshHousekeepingTest.php`
- Test: `backend/tests/Service/SubscriptionHistory/SubscriptionHistoryRecorderTest.php`

**Interfaces:**
- Consumes: Tasks 1–3, `NaiveUtcClock`, `EntityManagerInterface`.
- Produces: `SubscriptionHistoryRecorder::recordCompletedDays(): int` (rows written). `RefreshHousekeeping` gains a
  constructor parameter `SubscriptionHistoryRecorder $history` after `$pruner`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/SubscriptionHistory/SubscriptionHistoryRecorderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Retention\RetentionSettings;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\RetentionHorizon;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

/** Now is 2026-10-25 12:00 UTC; default retention, so plain feeds reach back 90 days and social ones to 2026-10-19. */
final class SubscriptionHistoryRecorderTest extends DbTestCase
{
    private function recorder(): SubscriptionHistoryRecorder
    {
        $settings = self::getContainer()->get(RetentionSettings::class);
        self::assertInstanceOf(RetentionSettings::class, $settings);
        $activity = new SubscriptionActivityRepository($this->entityManager);

        return new SubscriptionHistoryRecorder(
            $activity,
            $this->storedDays(),
            new LiveDayCounter($activity),
            new RetentionHorizon($settings),
            new NaiveUtcClock(new MockClock('2026-10-25 12:00:00', 'UTC')),
            $this->entityManager,
        );
    }

    private function storedDays(): SubscriptionDayRepository
    {
        $repository = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $repository);

        return $repository;
    }

    private function subscription(string $email, string $subscribedAt, ?SocialNetwork $network = null): Subscription
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01 00:00:00'));
        $feed = new Feed('https://example.com/' . $email . '.xml');
        $feed->classifyAs($network);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable($subscribedAt));
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function arrival(Subscription $subscription, string $guid, string $createdAt): void
    {
        $created = new \DateTimeImmutable($createdAt);
        $this->entityManager->persist(new Entry($subscription->getFeed(), $guid, null, $guid, $created, $created));
    }

    /** @return list<string> */
    private function recordedDays(Subscription $subscription): array
    {
        $days = array_map(
            static fn (SubscriptionDay $row): string => UtcDay::of($row->getDay()),
            $this->storedDays()->findBy(['subscription' => $subscription]),
        );
        sort($days);

        return $days;
    }

    public function testRecordsEveryCompletedDayFromTheSubscribeDayWithTheSubscribeDayAtZeroArrivals(): void
    {
        $subscription = $this->subscription('fresh@example.com', '2026-10-22 08:00:00');
        $this->arrival($subscription, 'backlog', '2026-10-22 08:00:01');
        $this->arrival($subscription, 'first-real', '2026-10-23 10:00:00');
        $this->entityManager->flush();

        $written = $this->recorder()->recordCompletedDays();

        self::assertSame(3, $written);
        self::assertSame(['2026-10-22', '2026-10-23', '2026-10-24'], $this->recordedDays($subscription));
        $stored = $this->storedDays()->since([$subscription->requireId()], '2026-10-22');
        self::assertSame(0, $stored->of($subscription->requireId(), '2026-10-22')->arrived);
        self::assertSame(1, $stored->of($subscription->requireId(), '2026-10-23')->arrived);
    }

    public function testBackfillsAtMostTheThirtyDayWindowForAnOldPlainFeed(): void
    {
        $subscription = $this->subscription('old@example.com', '2026-01-01 00:00:00');
        $this->entityManager->flush();

        $this->recorder()->recordCompletedDays();

        $days = $this->recordedDays($subscription);
        self::assertCount(29, $days);
        self::assertSame('2026-09-26', $days[0]);
        self::assertSame('2026-10-24', $days[28]);
    }

    public function testASocialFeedIsRecordedOnlyBackToItsRetentionHorizon(): void
    {
        $subscription = $this->subscription('timeline@example.com', '2026-01-01 00:00:00', SocialNetwork::Bluesky);
        $this->entityManager->flush();

        $this->recorder()->recordCompletedDays();

        self::assertSame(
            ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'],
            $this->recordedDays($subscription),
        );
    }

    public function testAStoredDayIsNeverRewritten(): void
    {
        $subscription = $this->subscription('stored@example.com', '2026-10-20 00:00:00');
        $this->entityManager->persist(new SubscriptionDay($subscription, UtcDay::start('2026-10-23'), 42, 7));
        $this->arrival($subscription, 'live', '2026-10-23 10:00:00');
        $this->entityManager->flush();

        $this->recorder()->recordCompletedDays();
        $secondRun = $this->recorder()->recordCompletedDays();

        self::assertSame(0, $secondRun);
        self::assertSame(42, $this->storedDays()->since([$subscription->requireId()], '2026-10-23')->of($subscription->requireId(), '2026-10-23')->arrived);
    }
}
```

In `backend/tests/Service/Refresh/RefreshHousekeepingTest.php`, add (and pass the recorder in `housekeeping()`,
with `EntryPruner`'s 5th argument `20` so the cap pass bites):

```php
    public function testAPruningRequestRecordsTheDayBeforeTheCapPassPrunesIt(): void
    {
        $user = new User('cap@example.com', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $feed = new Feed('https://cap.example.com/rss');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-15 00:00:00'));
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        for ($index = 0; $index < 25; ++$index) {
            $created = new \DateTimeImmutable(sprintf('2026-07-20 10:%02d:00', $index));
            $this->entityManager->persist(new Entry($feed, 'cap-' . $index, null, 'cap-' . $index, $created, $created));
        }
        $this->entityManager->flush();

        $pruned = $this->housekeeping()->pruneEntries(RefreshRequestModel::allDue(30));

        self::assertSame(5, $pruned);
        $repository = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $repository);
        self::assertSame(25, $repository->since([$subscription->requireId()], '2026-07-20')->of($subscription->requireId(), '2026-07-20')->arrived);
    }

    public function testAUserRequestRecordsNothing(): void
    {
        self::assertSame(0, $this->housekeeping()->pruneEntries(RefreshRequestModel::forUser(1, 30)));
        self::assertSame([], $this->entityManager->getRepository(SubscriptionDay::class)->findAll());
    }
```

The housekeeping clock is `2026-07-21 12:00:00 UTC`, so 2026-07-20 is a completed, countable day.

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/SubscriptionHistory/SubscriptionHistoryRecorderTest.php tests/Service/Refresh/RefreshHousekeepingTest.php`
Expected: errors (recorder class missing).

- [ ] **Step 3: Implement**

`backend/src/Service/SubscriptionHistory/SubscriptionHistoryRecorder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\DayWindowModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use App\Service\SubscriptionHistory\Support\UtcDay;
use Doctrine\ORM\EntityManagerInterface;

/** Must run before the pruner in the same pass: a day is recorded while every entry it counts still exists. */
final readonly class SubscriptionHistoryRecorder
{
    public const int WINDOW_DAYS = 30;

    public function __construct(
        private SubscriptionActivityRepository $activity,
        private SubscriptionDayRepository $storedDays,
        private LiveDayCounter $liveCounter,
        private RetentionHorizon $horizon,
        private NaiveUtcClock $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function recordCompletedDays(): int
    {
        $window = DayWindowModel::endingOn(UtcDay::of($this->clock->now()), self::WINDOW_DAYS);
        $subscriptions = $this->activity->trackedSubscriptions();
        $sources = new DaySources(
            $this->storedDays->since(array_map(static fn (TrackedSubscriptionModel $subscription): int => $subscription->subscriptionId, $subscriptions), $window->oldest()),
            $this->horizon->asOf($window->today()),
        );
        $oldestLiveDay = $sources->oldestLiveDay($subscriptions, $window->completedDates());
        if ($oldestLiveDay === null) {
            return 0;
        }

        $live = $this->liveCounter->since($subscriptions, $oldestLiveDay);
        $written = 0;
        foreach ($subscriptions as $subscription) {
            $written += $this->recordSubscription($subscription, $window, $sources, $live);
        }
        $this->entityManager->flush();

        return $written;
    }

    private function recordSubscription(
        TrackedSubscriptionModel $subscription,
        DayWindowModel $window,
        DaySources $sources,
        DayCountsModel $live,
    ): int {
        $written = 0;
        foreach ($window->completedDates() as $day) {
            if (!$sources->needsLive($subscription, $day)) {
                continue;
            }
            $counts = $live->of($subscription->subscriptionId, $day);
            $this->entityManager->persist(new SubscriptionDay(
                $this->entityManager->getReference(Subscription::class, $subscription->subscriptionId),
                UtcDay::start($day),
                $counts->arrived,
                $counts->read,
            ));
            ++$written;
        }

        return $written;
    }
}
```

`recordSubscription()` takes four parameters, which CLAUDE.md calls a lot: if review or PHPMD objects, fold
`$window`, `$sources` and `$live` into a `Pass/RecordingPass` object that holds them as fields (the phptramp remedy) —
decide on what the gates say, not preemptively. Extract the id `array_map` into a private `idsOf()` if the line
exceeds the PSR-12 length limit.

`backend/src/Service/Refresh/RefreshHousekeeping.php` — add the dependency and record first:

```php
    public function __construct(
        private OrphanedFeedReclaimer $orphanedFeeds,
        private EntryPruner $pruner,
        private SubscriptionHistoryRecorder $history,
        private LoggerInterface $logger,
    ) {
    }
```

```php
    public function pruneEntries(RefreshRequestModel $request): int
    {
        if (!$request->prune) {
            return 0;
        }

        $recorded = $this->history->recordCompletedDays();
        if ($recorded > 0) {
            $this->logger->info('Recorded subscription history days', ['count' => $recorded]);
        }

        return $this->pruner->prune();
    }
```

(with `use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;`). Update both test constructions of
`RefreshHousekeeping` (`tests/Support/RefreshRunners.php`, `RefreshHousekeepingTest::housekeeping()`) to pass a
recorder built as in the recorder test, using that file's own clock (`$this->clock` /
`new MockClock('2026-07-21 12:00:00', 'UTC')`). `RefreshHousekeepingTest::testNothingToReclaimLogsNothing` stays
valid because it calls `reclaimOrphanedFeeds()` only.

- [ ] **Step 4: Run to verify they pass, then the refresh suite**

Run: `cd backend && php bin/phpunit tests/Service/SubscriptionHistory tests/Service/Refresh`
Expected: PASS. Then `php bin/phpunit --filter RefreshRunner` — PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/SubscriptionHistory backend/src/Service/Refresh/RefreshHousekeeping.php backend/tests/Support/RefreshRunners.php backend/tests/Service/Refresh/RefreshHousekeepingTest.php backend/tests/Service/SubscriptionHistory
git commit -m "feat(#1783): record subscription history before pruning"
```

---

### Task 5: The 30-day series with unknown days and the average

**Files:**
- Create: `backend/src/Service/SubscriptionHistory/Model/ActivityDayModel.php`
- Create: `backend/src/Service/SubscriptionHistory/Model/SubscriptionActivityModel.php`
- Create: `backend/src/Service/SubscriptionHistory/SubscriptionActivityCounter.php`
- Test: `backend/tests/Service/SubscriptionHistory/Model/SubscriptionActivityModelTest.php`
- Test: `backend/tests/Service/SubscriptionHistory/SubscriptionActivityCounterTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  - `ActivityDayModel::known(string $date, DayCountModel $counts)`, `::unknown(string $date)`; `public string $date`;
    `->arrived(): ?int`, `->read(): ?int`
  - `SubscriptionActivityModel(list<ActivityDayModel> $days)`; `->arrivedDailyAverage(): ?float` (mean of known
    `arrived` over the 7 days before the last one), `->readTotal(): int` (sum of known reads)
  - `SubscriptionActivityCounter::lastDays(User $user): SubscriptionActivityModel` — 30 days, today last.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/SubscriptionHistory/Model/SubscriptionActivityModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;
use PHPUnit\Framework\TestCase;

final class SubscriptionActivityModelTest extends TestCase
{
    /** @param list<int|null> $arrivals oldest first, today last */
    private function activity(array $arrivals): SubscriptionActivityModel
    {
        $days = [];
        foreach ($arrivals as $index => $arrived) {
            $date = sprintf('2026-10-%02d', $index + 1);
            $days[] = $arrived === null
                ? ActivityDayModel::unknown($date)
                : ActivityDayModel::known($date, new DayCountModel($arrived, 1));
        }

        return new SubscriptionActivityModel($days);
    }

    public function testAveragesTheSevenCompletedDaysAndLeavesTodayOut(): void
    {
        self::assertSame(4.0, $this->activity([100, 1, 2, 3, 4, 5, 6, 7, 999])->arrivedDailyAverage());
    }

    public function testAveragesOnlyTheKnownDaysOfThatWeek(): void
    {
        self::assertSame(5.0, $this->activity([null, null, null, null, null, 4, 6, 0])->arrivedDailyAverage());
    }

    public function testNoKnownDayInTheWeekHasNoAverage(): void
    {
        self::assertNull($this->activity([null, null, null, null, null, null, null, 3])->arrivedDailyAverage());
    }

    public function testTheReadTotalSkipsUnknownDays(): void
    {
        self::assertSame(2, $this->activity([null, 5, 5])->readTotal());
    }
}
```

`backend/tests/Service/SubscriptionHistory/SubscriptionActivityCounterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionDay;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Retention\RetentionSettings;
use App\Service\SubscriptionHistory\LiveDayCounter;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\RetentionHorizon;
use App\Service\SubscriptionHistory\SubscriptionActivityCounter;
use App\Service\SubscriptionHistory\Support\UtcDay;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

/** Now is 2026-10-25 12:00 UTC: the window runs 2026-09-26 … 2026-10-25, social feeds are live back to 2026-10-19. */
final class SubscriptionActivityCounterTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('counter@example.com', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->entityManager->persist($this->user);
    }

    private function counter(): SubscriptionActivityCounter
    {
        $settings = self::getContainer()->get(RetentionSettings::class);
        self::assertInstanceOf(RetentionSettings::class, $settings);
        $storedDays = $this->entityManager->getRepository(SubscriptionDay::class);
        self::assertInstanceOf(SubscriptionDayRepository::class, $storedDays);
        $activity = new SubscriptionActivityRepository($this->entityManager);

        return new SubscriptionActivityCounter(
            $activity,
            $storedDays,
            new LiveDayCounter($activity),
            new RetentionHorizon($settings),
            new NaiveUtcClock(new MockClock('2026-10-25 12:00:00', 'UTC')),
        );
    }

    private function subscribe(string $url, string $at, ?SocialNetwork $network = null): Subscription
    {
        $feed = new Feed($url);
        $feed->classifyAs($network);
        $subscription = new Subscription($this->user, $feed, new \DateTimeImmutable($at));
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function arrival(Subscription $subscription, string $guid, string $createdAt): void
    {
        $created = new \DateTimeImmutable($createdAt);
        $this->entityManager->persist(new Entry($subscription->getFeed(), $guid, null, $guid, $created, $created));
    }

    /** @return array<string, ActivityDayModel> */
    private function byDate(): array
    {
        $days = [];
        foreach ($this->counter()->lastDays($this->user)->days as $day) {
            $days[$day->date] = $day;
        }

        return $days;
    }

    public function testThirtyDaysEndingTodayAndTodayIsLive(): void
    {
        $plain = $this->subscribe('https://example.com/a.xml', '2026-09-01 00:00:00');
        $this->arrival($plain, 'today', '2026-10-25 09:00:00');
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertCount(30, $days);
        self::assertSame('2026-09-26', array_key_first($days));
        self::assertSame(1, $days['2026-10-25']->arrived());
    }

    public function testStoredRowsWinAndLiveFillsTheRest(): void
    {
        $plain = $this->subscribe('https://example.com/b.xml', '2026-09-01 00:00:00');
        $this->entityManager->persist(new SubscriptionDay($plain, UtcDay::start('2026-10-20'), 40, 3));
        $this->arrival($plain, 'pruned-later', '2026-10-20 10:00:00');
        $this->arrival($plain, 'live', '2026-10-21 10:00:00');
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertSame(40, $days['2026-10-20']->arrived());
        self::assertSame(3, $days['2026-10-20']->read());
        self::assertSame(1, $days['2026-10-21']->arrived());
    }

    public function testASocialSubscriptionMakesOlderUnstoredDaysUnknownForTheWholeAccount(): void
    {
        $plain = $this->subscribe('https://example.com/c.xml', '2026-09-01 00:00:00');
        $this->subscribe('https://bsky.example/home', '2026-09-01 00:00:00', SocialNetwork::Bluesky);
        $this->arrival($plain, 'hidden', '2026-10-10 10:00:00');
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertNull($days['2026-10-10']->arrived());
        self::assertNull($days['2026-10-18']->read());
        self::assertSame(0, $days['2026-10-19']->arrived());
    }

    public function testASubscriptionCreatedMidWindowDoesNotMakeEarlierDaysUnknown(): void
    {
        $this->subscribe('https://example.com/d.xml', '2026-09-01 00:00:00');
        $this->subscribe('https://bsky.example/late', '2026-10-22 00:00:00', SocialNetwork::Bluesky);
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertSame(0, $days['2026-10-05']->arrived());
    }

    public function testAnAccountWithoutSubscriptionsHasThirtyKnownZeroDays(): void
    {
        $this->entityManager->flush();

        $days = $this->byDate();

        self::assertCount(30, $days);
        self::assertSame([0], array_values(array_unique(array_map(static fn (ActivityDayModel $day): ?int => $day->arrived(), $days))));
    }
}
```

Note the third test: with a social subscription created on 2026-09-01 and no stored rows, days before 2026-10-19 are
unknown — the spec's "graph starts at ~7 days" behaviour.

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/SubscriptionHistory/Model/SubscriptionActivityModelTest.php tests/Service/SubscriptionHistory/SubscriptionActivityCounterTest.php`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`backend/src/Service/SubscriptionHistory/Model/ActivityDayModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

/** One day of an account's series; no counts when any subscription's day can no longer be known. */
final readonly class ActivityDayModel
{
    private function __construct(public string $date, private ?DayCountModel $counts)
    {
    }

    public static function known(string $date, DayCountModel $counts): self
    {
        return new self($date, $counts);
    }

    public static function unknown(string $date): self
    {
        return new self($date, null);
    }

    public function arrived(): ?int
    {
        return $this->counts?->arrived;
    }

    public function read(): ?int
    {
        return $this->counts?->read;
    }
}
```

`backend/src/Service/SubscriptionHistory/Model/SubscriptionActivityModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class SubscriptionActivityModel
{
    private const int AVERAGE_DAYS = 7;

    /**
     * @param list<ActivityDayModel> $days oldest first, today last
     */
    public function __construct(public array $days)
    {
    }

    /** Today is partial, so the week is the seven days before it. */
    public function arrivedDailyAverage(): ?float
    {
        $known = array_values(array_filter(
            array_map(
                static fn (ActivityDayModel $day): ?int => $day->arrived(),
                \array_slice($this->days, -(self::AVERAGE_DAYS + 1), self::AVERAGE_DAYS),
            ),
            static fn (?int $arrived): bool => $arrived !== null,
        ));

        return $known === [] ? null : array_sum($known) / \count($known);
    }

    public function readTotal(): int
    {
        return array_sum(array_map(static fn (ActivityDayModel $day): int => $day->read() ?? 0, $this->days));
    }
}
```

`backend/src/Service/SubscriptionHistory/SubscriptionActivityCounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Entity\User;
use App\Repository\SubscriptionActivityRepository;
use App\Repository\SubscriptionDayRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Model\DayWindowModel;
use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Pass\DaySources;
use App\Service\SubscriptionHistory\Support\UtcDay;

final readonly class SubscriptionActivityCounter
{
    public function __construct(
        private SubscriptionActivityRepository $activity,
        private SubscriptionDayRepository $storedDays,
        private LiveDayCounter $liveCounter,
        private RetentionHorizon $horizon,
        private NaiveUtcClock $clock,
    ) {
    }

    public function lastDays(User $user): SubscriptionActivityModel
    {
        $window = DayWindowModel::endingOn(UtcDay::of($this->clock->now()), SubscriptionHistoryRecorder::WINDOW_DAYS);
        $subscriptions = $this->activity->trackedSubscriptionsOf($user->requireId());
        $sources = new DaySources(
            $this->storedDays->since(array_map(static fn (TrackedSubscriptionModel $subscription): int => $subscription->subscriptionId, $subscriptions), $window->oldest()),
            $this->horizon->asOf($window->today()),
        );
        $live = $this->liveCounter->since(
            $subscriptions,
            $sources->oldestLiveDay($subscriptions, $window->dates) ?? $window->today(),
        );

        return new SubscriptionActivityModel(array_map(
            fn (string $date): ActivityDayModel => $this->dayOf($date, $subscriptions, $sources, $live),
            $window->dates,
        ));
    }

    /**
     * @param list<TrackedSubscriptionModel> $subscriptions
     */
    private function dayOf(string $date, array $subscriptions, DaySources $sources, DayCountsModel $live): ActivityDayModel
    {
        $total = DayCountModel::none();
        foreach ($subscriptions as $subscription) {
            if (!$sources->contributes($subscription, $date)) {
                continue;
            }
            if (!$sources->isKnown($subscription, $date)) {
                return ActivityDayModel::unknown($date);
            }
            $total = $total->plus($sources->countFor($subscription, $date, $live));
        }

        return ActivityDayModel::known($date, $total);
    }
}
```

The `idsOf` mapping now appears in the recorder and here: move it to a static `TrackedSubscriptionModel::idsOf(list<self>): list<int>`
and use it in both (DRY). If `dayOf()`'s four parameters draw a gate finding, apply the same Pass-object remedy as in
Task 4.

- [ ] **Step 4: Run to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/SubscriptionHistory`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/SubscriptionHistory backend/tests/Service/SubscriptionHistory
git commit -m "feat(#1783): per-account 30-day arrival and read series"
```

---

### Task 6: API — the reading endpoint serves both series

**Files:**
- Modify: `backend/src/Service/Reading/ReadingActivityCounter.php`
- Modify: `backend/src/Service/Reading/Model/ReadingActivityModel.php`
- Modify: `backend/src/Http/ReadingActivityJson.php`
- Modify: `backend/src/Controller/Api/ReadingActivityController.php`
- Modify: `backend/tests/Controller/Api/ReadingActivityControllerTest.php`
- Delete: `backend/src/Service/Reading/Model/ReadingWindowModel.php`, `backend/tests/Service/Reading/Model/ReadingWindowModelTest.php`
- Modify: `backend/src/Repository/EntryStateRepository.php` (delete `viewedAtSince()`), delete `backend/tests/Repository/ViewedAtSinceTest.php`

**Interfaces:**
- Consumes: `SubscriptionActivityCounter::lastDays(User)`, `SubscriptionActivityModel` (Task 5).
- Produces: `GET /api/reading/activity` →
  `{days: list<{date: string, arrived: int|null, read: int|null}>, total: int, arrivedDailyAverage: float|null, topFeedsByRead: list<{feedId: int, readCount: int}>}`.

- [ ] **Step 1: Rewrite the controller test first**

In `backend/tests/Controller/Api/ReadingActivityControllerTest.php`:
- Drop every `?tz=UTC` from request URLs, and delete any test whose subject is the `tz` parameter itself (an
  unknown zone falling back, a non-UTC zone shifting a day): the endpoint no longer takes one.
- `testAnAccountWithNoReadsGetsThirtyZeroDays`: the days' shape is now `{date, arrived, read}`; assert
  `array_column($days, 'read')` and `array_column($days, 'arrived')` are all `0`, `total` is `0`,
  `arrivedDailyAverage` is `0` (int `0` or float `0.0` — JSON decodes `0.0` as `0` unless the encoder keeps
  `.0`; assert with `assertEquals(0, …)`), and `topFeedsByRead` is `[]`.
- `testCountsOpensPerDayWithinTheWindowAndDropsOlderOnes`: read `'read'` instead of `'count'`; the window is now
  UTC days, so key on `gmdate('Y-m-d', …)` of the noon-UTC instants it already uses. Reads older than 30 days
  remain absent from `total`.
- Add:

```php
    public function testCountsNewArticlesPerUtcDayAndAveragesTheLastSevenCompletedDays(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reading-activity-arrivals@example.test');

        $feed = new Feed('https://example.com/arrivals.xml');
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, new \DateTimeImmutable('-60 days')));
        $noonUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->setTime(12, 0);
        foreach ([1, 1, 3] as $index => $daysAgo) {
            $created = new \DateTimeImmutable($noonUtc->modify(sprintf('-%d days', $daysAgo))->format('Y-m-d H:i:s'));
            $this->entityManager()->persist(new Entry($feed, 'arrival-' . $index, null, 'arrival-' . $index, $created, $created));
        }
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var list<array{date: string, arrived: int|null, read: int|null}> $days */
        $days = $payload['days'];
        $arrivedByDate = array_column($days, 'arrived', 'date');
        self::assertSame(2, $arrivedByDate[$noonUtc->modify('-1 day')->format('Y-m-d')]);
        self::assertSame(1, $arrivedByDate[$noonUtc->modify('-3 days')->format('Y-m-d')]);
        self::assertEqualsWithDelta(3 / 7, $payload['arrivedDailyAverage'], 0.051);
    }
```

The average is rounded to one decimal by the mapper (0.4), hence the delta. The `new \DateTimeImmutable(…->format())`
re-wrap strips the UTC zone so the stored wall clock is the UTC one whatever PHP's default zone is.

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/ReadingActivityControllerTest.php`
Expected: FAIL (`count` key / missing `arrived`).

- [ ] **Step 3: Implement**

`backend/src/Service/Reading/Model/ReadingActivityModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;

final readonly class ReadingActivityModel
{
    /**
     * @param list<array{feedId: int, readCount: int}> $topFeedsByRead busiest feed first
     */
    public function __construct(
        public SubscriptionActivityModel $daily,
        public array $topFeedsByRead,
    ) {
    }
}
```

`backend/src/Service/Reading/ReadingActivityCounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Reading\Model\ReadingActivityModel;
use App\Service\SubscriptionHistory\SubscriptionActivityCounter;

final readonly class ReadingActivityCounter
{
    private const int TOP_FEEDS = 5;

    public function __construct(
        private SubscriptionActivityCounter $daily,
        private EntryStateRepository $states,
    ) {
    }

    public function activity(User $user): ReadingActivityModel
    {
        return new ReadingActivityModel(
            $this->daily->lastDays($user),
            $this->states->readCountsByFeed($user->requireId(), self::TOP_FEEDS),
        );
    }
}
```

`backend/src/Http/ReadingActivityJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reading\Model\ReadingActivityModel;
use App\Service\SubscriptionHistory\Model\ActivityDayModel;

/**
 * The About page's two 30-day charts, one entry per UTC day, oldest first. `null` marks a day that can no longer be
 * known (history not yet recorded and the entries already pruned); the client draws it as a gap.
 *
 * @phpstan-type ReadingActivityPayload array{
 *     days: list<array{date: string, arrived: int|null, read: int|null}>,
 *     total: int,
 *     arrivedDailyAverage: float|null,
 *     topFeedsByRead: list<array{feedId: int, readCount: int}>,
 * }
 */
final class ReadingActivityJson
{
    /** @return ReadingActivityPayload */
    public static function of(ReadingActivityModel $activity): array
    {
        $average = $activity->daily->arrivedDailyAverage();

        return [
            'days' => array_map(
                static fn (ActivityDayModel $day): array => [
                    'date' => $day->date,
                    'arrived' => $day->arrived(),
                    'read' => $day->read(),
                ],
                $activity->daily->days,
            ),
            'total' => $activity->daily->readTotal(),
            'arrivedDailyAverage' => $average === null ? null : round($average, 1),
            'topFeedsByRead' => $activity->topFeedsByRead,
        ];
    }

    private function __construct()
    {
    }
}
```

`backend/src/Controller/Api/ReadingActivityController.php` — drop `Request` and `ViewerTimeZoneModel`:

```php
/**
 * Daily new and read counts for the last thirty UTC days. No rate limiter: bounded queries, scoped to the current
 * user, with no id in the route to forge.
 */
#[Route('/api/reading')]
final readonly class ReadingActivityController
{
    public function __construct(private ReadingActivityCounter $activity)
    {
    }

    #[Route('/activity', name: 'api_reading_activity', methods: ['GET'])]
    public function activity(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(ReadingActivityJson::of($this->activity->activity($user)));
    }
}
```

Delete `ReadingWindowModel.php`, `ReadingWindowModelTest.php`, `EntryStateRepository::viewedAtSince()` and
`ViewedAtSinceTest.php`. Confirm nothing else uses them:
`grep -rn "ReadingWindowModel\|viewedAtSince" backend/src backend/tests` → no output. Check
`backend/tests/PhpStan/data/service-module-boundary-fixtures.php` and `ServiceModuleBoundaryRuleTest.php` reference
`ViewerTimeZoneModel` only (it stays; Recommendation uses it).

- [ ] **Step 4: Run tests and the static gates**

Run: `cd backend && php bin/phpunit tests/Controller/Api/ReadingActivityControllerTest.php tests/Service/SubscriptionHistory tests/Repository`
Expected: PASS.
Run: `cd backend && bin/console cache:warmup && composer check && composer md`
Expected: clean. Fix findings in the design, not with ignores (CLAUDE.md). `ServiceModuleCycleRule` must stay
green: SubscriptionHistory depends on Retention and Clock only.

- [ ] **Step 5: Commit**

```bash
git add -A backend/src backend/tests
git commit -m "feat(#1783): reading activity serves arrivals and reads per UTC day"
```

---

### Task 7: Frontend — two charts and the average

**Files:**
- Modify: `frontend/src/app/settings/settings.models.ts:134-155`
- Modify: `frontend/src/app/settings/settings-api.ts:80-88`, `frontend/src/app/settings/settings-api.spec.ts`
- Create: `frontend/src/app/settings/about/day-chart.ts`, `day-chart.spec.ts` (replace `reading-chart.ts` and
  `reading-chart.spec.ts` — `git mv` then edit)
- Create: `frontend/src/app/settings/about/day-chart.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/settings/about/about-section.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`settings.reading`)

**Interfaces:**
- Consumes: the Task 6 payload.
- Produces:
  - `ReadingDay { date: string; arrived: number | null; read: number | null }`;
    `ReadingActivity { days: ReadingDay[]; total: number; arrivedDailyAverage: number | null; topFeedsByRead: FeedReadCount[] }`
  - `SettingsApi.readingActivity(): Observable<ReadingActivity>` (no argument)
  - `ChartDay { date: string; count: number | null }`, `buildDayChart(days: ChartDay[], width: number, height: number): DayChart`,
    `hasActivity(days: ChartDay[]): boolean`
  - `<app-day-chart [days]="ChartDay[]" />`

- [ ] **Step 1: Write the failing chart tests**

`git mv frontend/src/app/settings/about/reading-chart.ts frontend/src/app/settings/about/day-chart.ts` and the same
for the spec. In `day-chart.spec.ts`, change the import to `buildDayChart, hasActivity, ChartDay` from `./day-chart`,
the helper to `function days(...counts: (number | null)[]): ChartDay[]`, every `buildReadingChart` call to
`buildDayChart`, and add:

```ts
  it('breaks the line and the area around an unknown day', () => {
    const chart = buildDayChart(days(1, 2, null, 3, 4), 300, 100);

    expect(chart.line.match(/M/g)).toHaveLength(2);
    expect(chart.area.match(/Z/g)).toHaveLength(2);
  });

  it('keeps a hover column for an unknown day with no count', () => {
    const chart = buildDayChart(days(null, 2), 300, 100);

    expect(chart.columns[0]).toMatchObject({ date: '2026-09-01', count: null });
  });

  it('ignores unknown days for the scale and the peak', () => {
    const chart = buildDayChart(days(null, 2, 4), 300, 100);

    expect(chart.peak?.xPercent).toBe(100);
  });

  it('only counts known non-zero days as activity', () => {
    expect(hasActivity(days(null, 0))).toBe(false);
    expect(hasActivity(days(null, 1))).toBe(true);
  });
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T frontend npm test -- day-chart.spec`
Expected: FAIL (`buildDayChart` / `hasActivity` not exported).

- [ ] **Step 3: Implement `day-chart.ts`**

Replace the file body (keep its style of small pure functions):

```ts
/** One day of a 30-day chart; `count` is null when the day can no longer be known. */
export interface ChartDay {
  date: string;
  count: number | null;
}

/** The SVG geometry of a 30-day area chart (#896, #1783). `line` and `area` are paths in the viewBox coordinate
 *  space, one subpath per run of known days; markers and tooltips are placed by the percentage fields on `peak` and
 *  `columns`, so a round dot stays round however the SVG is stretched. */
export interface DayChart {
  width: number;
  height: number;
  line: string;
  area: string;
  peak: ChartPoint | null;
  columns: ChartColumn[];
}

/** A point on the line as percentages of the chart box, for an HTML overlay. */
export interface ChartPoint {
  xPercent: number;
  yPercent: number;
}

/** One day's hover/focus target: the band it occupies, the point on the line above it, and its datum. */
export interface ChartColumn extends ChartPoint {
  date: string;
  count: number | null;
  leftPercent: number;
  widthPercent: number;
}

interface PlottedDay {
  day: ChartDay;
  x: number;
  y: number;
  xPercent: number;
  yPercent: number;
}

const PAD_TOP = 8;
const PAD_BOTTOM = 4;

export function hasActivity(days: ChartDay[]): boolean {
  return days.some((day) => (day.count ?? 0) > 0);
}

export function buildDayChart(days: ChartDay[], width: number, height: number): DayChart {
  const baseline = height - PAD_BOTTOM;
  const plot = baseline - PAD_TOP;
  const max = Math.max(1, ...days.map((day) => day.count ?? 0));

  const points = days.map((day, index): PlottedDay => {
    const x = xFor(index, days.length, width);
    const y = baseline - ((day.count ?? 0) / max) * plot;

    return { day, x, y, xPercent: percent(x, width), yPercent: percent(y, height) };
  });
  const runs = knownRuns(points);

  return {
    width,
    height,
    line: runs.map(linePath).join(' '),
    area: runs.map((run) => areaPath(run, baseline)).join(' '),
    peak: hasActivity(days) ? peakOf(points) : null,
    columns: columnsFor(points, width),
  };
}

function knownRuns(points: PlottedDay[]): PlottedDay[][] {
  const runs: PlottedDay[][] = [];
  let current: PlottedDay[] = [];
  for (const point of points) {
    if (point.day.count === null) {
      current = [];
      continue;
    }
    if (current.length === 0) {
      runs.push(current);
    }
    current.push(point);
  }

  return runs;
}

function linePath(run: PlottedDay[]): string {
  return run.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x} ${round(point.y)}`).join(' ');
}

function areaPath(run: PlottedDay[], baseline: number): string {
  const first = run[0];
  const last = run[run.length - 1];
  const line = run.map((point) => `L${point.x} ${round(point.y)}`).join(' ');

  return `M${first.x} ${baseline} ${line} L${last.x} ${baseline} Z`;
}

function peakOf(points: PlottedDay[]): ChartPoint {
  const peak = points.reduce((best, point) =>
    (point.day.count ?? -1) > (best.day.count ?? -1) ? point : best,
  );

  return { xPercent: peak.xPercent, yPercent: peak.yPercent };
}

function xFor(index: number, count: number, width: number): number {
  return count <= 1 ? width / 2 : round((index / (count - 1)) * width);
}

function columnsFor(points: PlottedDay[], width: number): ChartColumn[] {
  const bandWidth = points.length === 0 ? width : width / points.length;

  return points.map((point, index) => ({
    date: point.day.date,
    count: point.day.count,
    xPercent: point.xPercent,
    yPercent: point.yPercent,
    leftPercent: percent(index * bandWidth, width),
    widthPercent: percent(bandWidth, width),
  }));
}

function percent(value: number, extent: number): number {
  return round((value / extent) * 100);
}

function round(value: number): number {
  return Math.round(value * 100) / 100;
}
```

Behaviour change to check against the old spec: the area used to close at `L${width}` (the chart's right edge);
it now closes under its run's last point, which is the same x for a fully known window. Remove the old spec's
`hasData` assertions (the field is gone; `hasActivity` replaces it) and keep `'M0 96'` for the area start.

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec -T frontend npm test -- day-chart.spec`
Expected: PASS.

- [ ] **Step 5: Extract `DayChartComponent`**

`frontend/src/app/settings/about/day-chart.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { formatDayInMonth } from '../../reader/format';
import { ChartColumn, ChartDay, buildDayChart } from './day-chart';

const CHART_WIDTH = 300;
const CHART_HEIGHT = 96;

/** A 30-day area chart with one hover/focus target per day; unknown days are gaps. */
@Component({
  selector: 'app-day-chart',
  imports: [TranslocoPipe],
  templateUrl: './day-chart.component.html',
  styleUrl: './day-chart.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DayChartComponent {
  private readonly language = inject(LanguageService);
  private readonly hovered = signal<number | null>(null);

  readonly days = input.required<ChartDay[]>();

  readonly chart = computed(() => buildDayChart(this.days(), CHART_WIDTH, CHART_HEIGHT));

  /** The hovered day, or the busiest day at rest so the chart always names one number. */
  readonly active = computed(() => {
    const index = this.hovered();
    const columns = this.chart().columns;

    return index === null ? this.busiestColumn(columns) : (columns[index] ?? null);
  });

  hover(index: number): void {
    this.hovered.set(index);
  }

  clearHover(): void {
    this.hovered.set(null);
  }

  dayLabel(iso: string): string {
    // A UTC calendar day: anchored to local midnight so a negative-offset browser keeps the same date.
    return formatDayInMonth(`${iso}T00:00:00`, this.language.lang());
  }

  private busiestColumn(columns: ChartColumn[]): ChartColumn | null {
    return columns.reduce<ChartColumn | null>(
      (best, column) => (best === null || (column.count ?? -1) > (best.count ?? -1) ? column : best),
      null,
    );
  }
}
```

`day-chart.component.html` — the `<figure class="chart">…</figure>` block moved verbatim from
`about-section.component.html`, with `c` → `chart()`, `activeDate` → `dayLabel`, and the unknown day handled:

```html
@let plot = chart();
<figure class="chart" (pointerleave)="clearHover()">
  <svg
    class="chart-svg"
    [attr.viewBox]="'0 0 ' + plot.width + ' ' + plot.height"
    preserveAspectRatio="none"
    aria-hidden="true"
  >
    <path class="chart-area" [attr.d]="plot.area" />
    <path class="chart-line" [attr.d]="plot.line" vector-effect="non-scaling-stroke" />
  </svg>

  @if (active(); as point) {
    @if (point.count !== null) {
      <span class="chart-marker" [style.left.%]="point.xPercent" [style.top.%]="point.yPercent"></span>
    }
    <figcaption class="chart-tip" [style.left.%]="point.xPercent">
      @if (point.count === null) {
        {{ 'settings.reading.chartUnknown' | transloco }}
      } @else {
        <strong>{{ point.count }}</strong>
      }
      {{ dayLabel(point.date) }}
    </figcaption>
  }

  <div class="chart-cols">
    @for (col of plot.columns; track col.date; let i = $index) {
      <button
        type="button"
        class="chart-col"
        [style.left.%]="col.leftPercent"
        [style.width.%]="col.widthPercent"
        [attr.aria-label]="(col.count ?? ('settings.reading.chartUnknown' | transloco)) + ' · ' + dayLabel(col.date)"
        (pointerenter)="hover(i)"
        (pointerdown)="hover(i)"
        (focus)="hover(i)"
        (blur)="clearHover()"
      ></button>
    }
  </div>
</figure>
```

(`let i` is the template idiom already in use; `AbbreviatedNameRule` covers PHP only.)

`day-chart.component.scss`: move the `// --- Reading chart ---` section (`.chart` through `.chart-col`, currently
`about-section.component.scss` lines ~82–165) here verbatim, add `@use '../../theme/glass-rim';` at the top (for
`.chart-tip`), and delete that section from `about-section.component.scss`. Add `:host { display: block; }`.

`day-chart.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { DayChartComponent } from './day-chart.component';
import { ChartDay } from './day-chart';

describe('DayChartComponent', () => {
  function mount(days: ChartDay[]) {
    TestBed.configureTestingModule({
      imports: [DayChartComponent],
      providers: [provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(DayChartComponent);
    fixture.componentRef.setInput('days', days);
    fixture.detectChanges();
    return fixture;
  }

  it('draws one hover column per day and names the busiest day at rest', () => {
    const fixture = mount([
      { date: '2026-09-01', count: 3 },
      { date: '2026-09-02', count: 5 },
    ]);

    expect(fixture.nativeElement.querySelectorAll('.chart-col')).toHaveLength(2);
    expect(fixture.nativeElement.querySelector('.chart-tip strong')?.textContent).toBe('5');
  });

  it('says a hovered unknown day has no data and draws no marker on it', () => {
    const fixture = mount([
      { date: '2026-09-01', count: null },
      { date: '2026-09-02', count: 5 },
    ]);
    fixture.componentInstance.hover(0);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.chart-marker')).toBeNull();
    expect(fixture.nativeElement.querySelector('.chart-tip')?.textContent).toContain('No data');
  });
});
```

Check `provideTranslocoTesting` loads the real `en.json` (it does for the about spec's `'Not enough reading
history yet'` assertion); if it doesn't, assert on the translation key instead.

- [ ] **Step 6: Models, API, i18n**

`settings.models.ts`:

```ts
/** One UTC day of the About page's charts; null when the day can no longer be known. */
export interface ReadingDay {
  date: string;
  arrived: number | null;
  read: number | null;
}
```

```ts
export interface ReadingActivity {
  days: ReadingDay[];
  total: number;
  arrivedDailyAverage: number | null;
  topFeedsByRead: FeedReadCount[];
}
```

`settings-api.ts`:

```ts
  /** The last thirty UTC days of new and read articles for the About page's charts. */
  readingActivity(): Observable<ReadingActivity> {
    return this.http.get<ReadingActivity>(`${this.base}/api/reading/activity`);
  }
```

Update `settings-api.spec.ts`'s `readingActivity` test: no `tz` param, request URL exactly
`/api/reading/activity`.

`en.json` → `settings.reading`, after `"favorites"`:

```json
      "arrivalsTitle": "New articles",
      "arrivalsAverage": "per day over the last 7 days",
      "arrivalsEmpty": "No new articles yet",
      "chartUnknown": "No data",
```

`de.json`:

```json
      "arrivalsTitle": "Neue Artikel",
      "arrivalsAverage": "pro Tag in den letzten 7 Tagen",
      "arrivalsEmpty": "Noch keine neuen Artikel",
      "chartUnknown": "Keine Daten",
```

- [ ] **Step 7: About page — write the failing component tests**

In `about-section.component.spec.ts`:
- `NO_ACTIVITY` becomes `{ days: [], total: 0, arrivedDailyAverage: null, topFeedsByRead: [] }`.
- Every fixture day `{ date, count: n }` becomes `{ date, arrived: 0, read: n }`; every `activity` object gets
  `arrivedDailyAverage: null`.
- The existing chart tests now query inside the reading block: give the reading `<section>` the class
  `block reading` and the arrivals one `block arrivals`, and change `.chart-line` / `.chart-col` selectors to
  `.reading .chart-line` / `.reading .chart-col`.
- Add:

```ts
  it('shows the seven-day average of new articles with one decimal', () => {
    const fixture = mount(null, {
      activity: {
        days: [{ date: '2026-09-01', arrived: 4, read: 0 }],
        total: 0,
        arrivedDailyAverage: 41.6,
        topFeedsByRead: [],
      },
    });

    expect(fixture.nativeElement.querySelector('.arrivals .average-value')?.textContent).toContain('41.6');
    expect(text(fixture)).toContain('per day over the last 7 days');
  });

  it('shows a dash when no day of the week is known', () => {
    const fixture = mount(null, {
      activity: {
        days: [{ date: '2026-09-01', arrived: null, read: null }],
        total: 0,
        arrivedDailyAverage: null,
        topFeedsByRead: [],
      },
    });

    expect(fixture.nativeElement.querySelector('.arrivals .average-value')?.textContent).toContain('—');
  });

  it('draws the new-articles chart from the arrived counts', () => {
    const fixture = mount(null, {
      activity: {
        days: [
          { date: '2026-09-01', arrived: null, read: 0 },
          { date: '2026-09-02', arrived: 6, read: 0 },
        ],
        total: 0,
        arrivedDailyAverage: 6,
        topFeedsByRead: [],
      },
    });

    expect(fixture.nativeElement.querySelectorAll('.arrivals .chart-col')).toHaveLength(2);
    expect(fixture.nativeElement.querySelector('.reading .chart')).toBeNull();
  });
```

Run: `docker compose exec -T frontend npm test -- about-section`
Expected: FAIL.

- [ ] **Step 8: About page — implement**

`about-section.component.ts`:
- Imports: drop `ReadingColumn, buildReadingChart` and `formatDayInMonth`; add
  `import { ChartDay, hasActivity } from './day-chart';` and `DayChartComponent` (also in `imports: [...]`).
- Delete `timeZone`, `hovered`, `CHART_WIDTH`, `CHART_HEIGHT`, `chart`, `active`, `hover()`, `clearHover()`,
  `activeDate()`, `busiestColumn()`.
- Add:

```ts
  readonly arrivedDays = computed<ChartDay[]>(() =>
    (this.activity()?.days ?? []).map((day) => ({ date: day.date, count: day.arrived })),
  );

  readonly readDays = computed<ChartDay[]>(() =>
    (this.activity()?.days ?? []).map((day) => ({ date: day.date, count: day.read })),
  );

  readonly hasArrivals = computed(() => hasActivity(this.arrivedDays()));
  readonly hasReads = computed(() => hasActivity(this.readDays()));

  /** One decimal, in the page's language; a dash when no day of the last week is known. */
  readonly arrivedAverage = computed(() => {
    const average = this.activity()?.arrivedDailyAverage ?? null;

    return average === null
      ? '—'
      : new Intl.NumberFormat(this.language.lang(), { maximumFractionDigits: 1 }).format(average);
  });
```

- `ngOnInit()`: `this.api.readingActivity()` with no argument; the error fallback becomes
  `{ days: [], total: 0, arrivedDailyAverage: null, topFeedsByRead: [] }`.

`about-section.component.html` — replace the reading chart `<section>` with two sections:

```html
    <section class="block arrivals">
      <div class="block-head">
        <span class="block-title">{{ 'settings.reading.arrivalsTitle' | transloco }}</span>
        <span class="block-sub">{{ 'settings.reading.chartWindow' | transloco }}</span>
      </div>

      @if (readingPending()) {
        <div class="block-empty"><app-spinner /></div>
      } @else {
        <p class="average">
          <span class="average-value">≈ {{ arrivedAverage() }}</span>
          <span class="average-label">{{ 'settings.reading.arrivalsAverage' | transloco }}</span>
        </p>
        @if (hasArrivals()) {
          <app-day-chart [days]="arrivedDays()" />
        } @else {
          <p class="block-empty">{{ 'settings.reading.arrivalsEmpty' | transloco }}</p>
        }
      }
    </section>

    <section class="block reading">
      <div class="block-head">
        <span class="block-title">{{ 'settings.reading.chartTitle' | transloco }}</span>
        <span class="block-sub">{{ 'settings.reading.chartWindow' | transloco }}</span>
      </div>

      @if (readingPending()) {
        <div class="block-empty"><app-spinner /></div>
      } @else if (hasReads()) {
        <app-day-chart [days]="readDays()" />
      } @else {
        <p class="block-empty">{{ 'settings.reading.chartEmpty' | transloco }}</p>
      }
    </section>
```

When the average is `null`, render `—` without the `≈`: use
`@if (arrivedAverage() === '—') { — } @else { ≈ {{ arrivedAverage() }} }` inside `.average-value`.

`about-section.component.scss` — add, using the tile figure's tokens (read `.tile-value` / `.tile-label` in the same
file and mirror their font-size/weight/colour variables):

```scss
.average {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  margin: 0;
}

.average-value {
  font-size: var(--fs-xl);
  font-weight: 600;
  color: var(--text-primary);
  font-variant-numeric: tabular-nums;
}

.average-label {
  font-size: var(--fs-xs);
  color: var(--text-secondary);
}
```

Use only tokens that exist (`grep -n "\-\-fs-" frontend/src/styles* frontend/src/app/theme -r | head`).

- [ ] **Step 9: Run the frontend gate**

Run: `docker compose exec -T frontend npm test -- settings` → PASS.
Run: `cd frontend && npm run check && npm run build` → clean (`ng build` catches template errors `check` misses).

- [ ] **Step 10: Commit**

```bash
git add -A frontend
git commit -m "feat(#1783): new-articles average and chart on the About page"
```

---

### Task 8: Verification

- [ ] **Step 1: Both backend legs, migration from empty, gates**

```bash
cd backend && composer test:parallel
docker compose exec php composer test
cd backend && bin/console cache:warmup && composer check && composer md && composer infection:diff
```

All green. MySQL migration from empty on a scratch database (never `down -v`, never the dev database): follow the CI
migrate leg in `.github/workflows/` (`grep -n "migrations:migrate" .github/workflows/*.yml`) — run the same commands
against a throwaway dbname via `DATABASE_URL`, then `doctrine:schema:validate`. Then apply to the live Docker dev DB:
`docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`.

- [ ] **Step 2: PhpStorm inspections**

`mcp__phpstorm__lint_files` on every changed PHP file: no ERROR or WARNING.

- [ ] **Step 3: Live check**

Make sure the containers run this branch's code (recreate `php`/`worker` if the DI container is stale; restart
nginx after a `php` recreate). Trigger a maintenance refresh so history is recorded:
`docker compose exec php bin/console app:refresh-feeds` (check `--help` for the exact command name; `--no-prune`
must **not** be passed). Then open `http://localhost:4200/settings/about` in the built-in browser (mobile viewport if
the UA is bot-blocked): the "New articles" block shows `≈ N per day` and a chart; hovering a day shows its count;
an account with social subscriptions shows gaps before the social horizon if history was empty. Scan today's dev
log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq -c 'select(.level_name != "DEBUG")'`.

- [ ] **Step 4: Push and PR**

```bash
git push -u origin feature/1783-arrival-statistics
gh pr create --base develop --title "feat(#1783): daily new-article statistics on the About page" --body "Closes #1783 …"
```

Body: what it adds, the reading chart's switch to UTC days, the unknown-day gaps, the migration. No attribution.
