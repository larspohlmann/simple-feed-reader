# Recommendation Look-back Window Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the AI recommendation pool a reader-facing look-back window of 1–7 days (default 2), and turn the existing `candidatePoolSize` into the hard article cap inside that window.

**Architecture:** `lookbackDays` joins the per-user recommendation settings chain like any other cap. `RecommendationRunAdvancer::snapshotTick()` converts it into a `since` instant against the injected clock and hands it, with the pool size and the shuffle seed, to `RecommendationCandidateLoader::load()` inside a new `CandidatePoolRequest` value object. The loader adds one `e.effectiveDate >= :since` predicate. Nothing is stored on the run: the snapshot already freezes entry ids, so a resumed run keeps its original window for free.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM, Angular 20 signals, Transloco, PHPUnit, Jest.

Spec: [docs/superpowers/specs/2026-08-14-recommendation-lookback-window-design.md](../specs/2026-08-14-recommendation-lookback-window-design.md)
Issue: [#386](https://github.com/larspohlmann/simple-feed-reader/issues/386)
Branch: `feature/386-recommendation-lookback-window` (already created off `develop`)

## Global Constraints

- The window is **rolling**: `since = now − N × 24 h`, computed in UTC at snapshot time. Never a calendar boundary.
- Range **1–7**, default **2**. `DEFAULT_LOOKBACK_DAYS = 2` on `EffectiveRecommendationSettings` is the single source of that number on the backend.
- No "unlimited" option, no grandfathering of existing accounts, no per-user migration data beyond the column default.
- `lookbackDays` is a required `int` on the wire in both directions — not nullable, because there is no "automatic" meaning to express.
- The translation key `settings.ai.recommendations.candidatePool` **keeps its name**; only its English and German text changes ("Maximum articles" / "Maximal Artikel").
- The cap input stays inside the Expert settings disclosure. The look-back select sits outside it.
- Datetimes are stored as naive UTC. `ClockInterface` yields UTC; do not convert, and do not introduce any other time source.
- Every `src` file touched must be PHPMD-clean, PSR-12, `declare(strict_types=1)`, PHPStan level max. Controllers gain no private helpers.
- German copy uses the formal, existing tone of `de.json`; no new hex colours or raw `px` in any `.scss`.

---

### Task 1: Settings chain, migration and API surface

Adds `lookbackDays` end to end on the backend as data only — nothing reads it yet. The pool query is untouched, so the suite must stay green with no behavioural change.

**Files:**
- Modify: `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php`
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsValues.php`
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsResolver.php`
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsWriter.php`
- Modify: `backend/src/Entity/RecommendationSettings.php`
- Modify: `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`
- Modify: `backend/src/Http/RecommendationSettingsJson.php`
- Create: `backend/migrations/Version20260814150000.php`
- Test: `backend/tests/Service/Recommendation/RecommendationSettingsRoundTripTest.php`
- Modify (call sites of `new RecommendationSettingsValues(`): `backend/tests/Support/RecommendationRunFixtures.php:130`, `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php:65`, `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php:1678` and `:1776`, `backend/tests/Service/Recommendation/ForYouSweepTest.php:62`, `backend/tests/Service/Recommendation/DueRecommendationRunFinderTest.php:48`, `backend/tests/Service/Worker/StartDueRecommendationRunsHandlerTest.php:44`, `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php:644`
- Test: `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php` (`fullPayloadJson()` at line 53, the state assertions at lines 115 and 166, the bounds provider at line 236)

**Interfaces:**
- Produces: `EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS` (`int`, value `2`); `EffectiveRecommendationSettings->lookbackDays` (`int`); `RecommendationSettingsValues->lookbackDays` (`int`, required constructor parameter placed immediately after `candidatePoolSize`); JSON field `lookbackDays` in `RecommendationSettingsJson::state()` and in `SaveRecommendationSettingsRequest`.

`lookbackDays` is a **required** parameter, not one with a default. Both carriers document themselves as 1:1 mirrors of the settings row; a default would let a caller silently persist a value it never chose. Every call site uses named arguments, so inserting it mid-list is safe.

- [ ] **Step 1: Write the failing round-trip test**

In `backend/tests/Service/Recommendation/RecommendationSettingsRoundTripTest.php`, change the `values()` helper to take the look-back and add a test. The helper becomes:

```php
    private function values(?int $autoGenerateIntervalHours, int $lookbackDays = EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            lookbackDays: $lookbackDays,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            autoGenerateIntervalHours: $autoGenerateIntervalHours,
        );
    }
```

and the new test:

```php
    public function testTheLookbackWindowPersistsAndResolves(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $resolver);

        $user = new User('lookback-roundtrip@example.com', new \DateTimeImmutable());
        $em->persist($user);
        $em->flush();

        // No row at all resolves to the default, not to zero.
        self::assertSame(2, $resolver->forUser($user)->lookbackDays);

        $writer->save($user, $this->values(null, 5));

        self::assertSame(5, $resolver->forUser($user)->lookbackDays);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit --filter testTheLookbackWindowPersistsAndResolves`
Expected: FAIL — `RecommendationSettingsValues::__construct()` has no `lookbackDays` argument.

- [ ] **Step 3: Add the field to the two carriers**

In `EffectiveRecommendationSettings.php`, next to the other defaults and after `candidatePoolSize`:

```php
    public const int DEFAULT_LOOKBACK_DAYS = 2;
```

```php
        public int $candidatePoolSize,
        /** How many days back the candidate pool reaches, counted as N x 24 h from the snapshot instant. */
        public int $lookbackDays,
        public int $picksLimit,
```

In `RecommendationSettingsValues.php`, the same insertion after `candidatePoolSize`:

```php
        public int $candidatePoolSize,
        public int $lookbackDays,
        public int $picksLimit,
```

- [ ] **Step 4: Resolve and write it**

In `RecommendationSettingsResolver::forUser()`, after the `candidatePoolSize` line:

```php
            lookbackDays: $row?->values()->lookbackDays
                ?? EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
```

In `RecommendationSettingsWriter::withNormalisedGuidance()`, after `candidatePoolSize:`:

```php
            lookbackDays: $values->lookbackDays,
```

- [ ] **Step 5: Persist it on the entity**

In `backend/src/Entity/RecommendationSettings.php`, after the `candidatePoolSize` property:

```php
    /**
     * How many days back a run's candidate pool reaches (#386). The cap in
     * candidatePoolSize applies inside this window.
     */
    #[ORM\Column(options: ['default' => EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS])]
    private int $lookbackDays = EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS;
```

and the matching lines in `update()` and `values()`:

```php
        $this->lookbackDays = $values->lookbackDays;
```

```php
            lookbackDays: $this->lookbackDays,
```

- [ ] **Step 6: Add the wire fields**

In `SaveRecommendationSettingsRequest`, after `candidatePoolSize`:

```php
        #[Assert\Range(min: 1, max: 7)]
        public int $lookbackDays,
```

and in its `values()`:

```php
            lookbackDays: $this->lookbackDays,
```

In `RecommendationSettingsJson::state()`, after `'candidatePoolSize'`:

```php
            'lookbackDays' => $effective->lookbackDays,
```

- [ ] **Step 7: Fix every other construction site**

Add `lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,` after the `candidatePoolSize:` argument in each of these, importing `EffectiveRecommendationSettings` where it is not already imported. Where the surrounding code copies a current row (`$current->…`), use `lookbackDays: $current->lookbackDays,` instead:

- `backend/tests/Support/RecommendationRunFixtures.php:130`
- `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php:65`
- `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php:1678` and `:1776` (both copy `$current`)
- `backend/tests/Service/Recommendation/ForYouSweepTest.php:62`
- `backend/tests/Service/Recommendation/DueRecommendationRunFinderTest.php:48`
- `backend/tests/Service/Worker/StartDueRecommendationRunsHandlerTest.php:44`
- `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php:644`

Run `grep -rn "new RecommendationSettingsValues(" backend/src backend/tests` and confirm every hit compiles.

- [ ] **Step 8: Cover the endpoint**

In `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php`, add `'lookbackDays' => 3,` to `fullPayloadJson()` after `'candidatePoolSize'`, assert the field in the state payload next to the existing `candidatePoolSize` assertions at lines 115 and 166:

```php
        self::assertSame(2, $payload['lookbackDays']);
```

(3 rather than 2 after a save, where the assertion follows a PUT of `fullPayloadJson()`), and add both bounds to the provider — unlike the maxima the docblock calls cosmetic, 8 is a real ceiling here, because a window nobody can reach past is the point of the setting:

```php
        yield 'lookbackDays below its floor of 1' => ['lookbackDays', 0];
        yield 'lookbackDays above its ceiling of 7' => ['lookbackDays', 8];
```

Extend that provider's docblock with one sentence saying why the look-back maximum is swept while the other maxima are not.

- [ ] **Step 9: Write the migration**

Create `backend/migrations/Version20260814150000.php`, copying the platform-aware shape of `Version20260814140000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Recommendation\EffectiveRecommendationSettings;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_recommendation_settings.lookback_days (#386): how many days back a
 * run's candidate pool reaches. Existing rows take the same default as a
 * missing row, so the setting means one thing everywhere.
 *
 * PLATFORM-AWARE DDL for the reason Version20260814140000 records: DDL
 * diffed on one platform does not parse on the other, and the suite cannot
 * catch it because tests build their schema from ORM metadata, not this
 * chain.
 */
final class Version20260814150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_recommendation_settings.lookback_days, default 2 (#386)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $settings = $schema->getTable('user_recommendation_settings');

        if ($settings->hasColumn('lookback_days')) {
            return;
        }

        $default = EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS;

        $this->addSql($mysql
            ? \sprintf('ALTER TABLE user_recommendation_settings ADD lookback_days INT DEFAULT %d NOT NULL', $default)
            : \sprintf('ALTER TABLE user_recommendation_settings ADD COLUMN lookback_days INTEGER DEFAULT %d NOT NULL', $default));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $settings = $schema->getTable('user_recommendation_settings');

        if (!$settings->hasColumn('lookback_days')) {
            return;
        }

        $this->addSql($mysql
            ? 'ALTER TABLE user_recommendation_settings DROP lookback_days'
            : 'ALTER TABLE user_recommendation_settings DROP COLUMN lookback_days');
    }
}
```

- [ ] **Step 10: Run the round-trip test and the recommendation suite**

Run: `cd backend && php bin/phpunit --filter testTheLookbackWindowPersistsAndResolves`
Expected: PASS

Run: `cd backend && php bin/phpunit tests/Service/Recommendation tests/Http tests/Controller/Api`
Expected: PASS. A 422 in a settings-controller test means that test's PUT payload still lacks `lookbackDays` — add it there.

- [ ] **Step 11: Verify the migration in a scratch database**

Never against the dev database.

```bash
cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-386.db" php bin/console doctrine:migrations:migrate --no-interaction -e dev && DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-386.db" php bin/console doctrine:schema:validate -e dev
```

Expected: the chain applies from empty and the mapping validates. Delete `backend/var/scratch-386.db` afterwards.

- [ ] **Step 12: Lint and commit**

```bash
cd backend && composer cs && composer stan && composer md
```

```bash
git add backend/src backend/tests backend/migrations
git commit -m "feat(#386): carry lookbackDays through the recommendation settings chain"
```

---

### Task 2: The window in the query

Makes the setting do something: a value object, one predicate, and the snapshot wiring. This is where the behaviour changes.

**Files:**
- Create: `backend/src/Service/Recommendation/CandidatePoolRequest.php`
- Modify: `backend/src/Service/Recommendation/RecommendationCandidateLoader.php`
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (`snapshotTick()`, around line 224)
- Test: `backend/tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`
- Modify: `backend/tests/Support/RecommendationRunFixtures.php` (`entry()`, line 147)
- Modify: `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php:612` and `:634`
- Modify: `backend/tests/Controller/Api/RecommendationRunControllerTest.php:96-119` (`seedOneCandidateEntry`)

**Interfaces:**
- Consumes: `EffectiveRecommendationSettings->lookbackDays` and `DEFAULT_LOOKBACK_DAYS` from Task 1.
- Produces: `final readonly class CandidatePoolRequest` with promoted public properties `\DateTimeImmutable $since`, `int $poolSize`, `int $orderSeed`; `RecommendationCandidateLoader::load(int $userId, CandidatePoolRequest $request): array` replacing `load(int $userId, int $poolSize, int $orderSeed)`; `RecommendationRunFixtures::entry(Feed $feed, string $guid, int $minutesAgo): Entry` replacing the absolute-date third parameter.

The fixture signature changes on purpose. Its callers seed entries dated `2026-07-…`, which a 2-day window excludes — every snapshot test would silently find an empty pool. Making the parameter relative to now removes the trap instead of papering over it once.

- [ ] **Step 1: Write the failing loader tests**

In `RecommendationCandidateLoaderTest`, add the request helper and three tests:

```php
    private function poolRequest(
        int $poolSize = 100,
        int $orderSeed = 1,
        string $since = '2000-01-01T00:00:00Z',
    ): CandidatePoolRequest {
        return new CandidatePoolRequest(
            since: new \DateTimeImmutable($since),
            poolSize: $poolSize,
            orderSeed: $orderSeed,
        );
    }

    public function testAnEntryOlderThanTheWindowIsExcluded(): void
    {
        $this->entry('too-old', '2026-07-09T23:59:59Z');
        $inside = $this->entry('inside', '2026-07-11T00:00:00Z');

        $lines = $this->loader()->load(
            $this->userId(),
            $this->poolRequest(since: '2026-07-10T00:00:00Z'),
        );

        self::assertSame([$inside->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testAnEntryExactlyOnTheWindowBoundaryIsIncluded(): void
    {
        $onBoundary = $this->entry('on-boundary', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load(
            $this->userId(),
            $this->poolRequest(since: '2026-07-10T00:00:00Z'),
        );

        // `>=`, not `>`: the boundary instant belongs to the window.
        self::assertSame([$onBoundary->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testThePoolSizeStillCapsTheCandidatesInsideTheWindow(): void
    {
        $this->entry('older-inside', '2026-07-11T00:00:00Z');
        $this->entry('newer-inside', '2026-07-12T00:00:00Z');
        $this->entry('outside', '2026-07-09T00:00:00Z');

        $lines = $this->loader()->load(
            $this->userId(),
            $this->poolRequest(poolSize: 1, since: '2026-07-10T00:00:00Z'),
        );

        // The cap selects the newest inside the window, never reaching past it.
        self::assertSame(['newer-inside'], array_map(static fn ($l) => $l->title, $lines));
    }
```

Add `use App\Service\Recommendation\CandidatePoolRequest;` to the imports, and rewrite the existing `load()` calls in this file:

- `load($this->userId(), 100, 1)` → `load($this->userId(), $this->poolRequest())`
- `load($this->userId(), 100, 4242)` → `load($this->userId(), $this->poolRequest(orderSeed: 4242))`
- `load($this->userId(), 2, 1)` → `load($this->userId(), $this->poolRequest(poolSize: 2))`

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`
Expected: FAIL — `CandidatePoolRequest` does not exist.

- [ ] **Step 3: Add the value object**

Create `backend/src/Service/Recommendation/CandidatePoolRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What bounds one run's candidate pool: how far back it reaches, how many
 * entries it may hold, and the seed that shuffles it. The three travel
 * together because they describe one selection, and the caller — not the
 * loader — decides them: the loader stays clock-free and settings-free, so a
 * test can pin an exact boundary instead of arranging a clock.
 *
 * `since` is an absolute instant, already resolved from the reader's
 * lookbackDays against the snapshot clock, so nothing downstream has to know
 * what "2 days" meant at that moment.
 */
final readonly class CandidatePoolRequest
{
    public function __construct(
        public \DateTimeImmutable $since,
        public int $poolSize,
        public int $orderSeed,
    ) {
    }
}
```

- [ ] **Step 4: Apply the window in the loader**

In `RecommendationCandidateLoader`, replace the `load()` signature and the three values it used to take as scalars:

```php
    /**
     * Unread candidates, excluding anything the reader has already favorited,
     * kept, or viewed, in feeds the reader subscribes to, and no older than
     * the request's window (#386). The newest $request->poolSize of those are
     * selected, then returned in a randomized order seeded by
     * $request->orderSeed, so batches sample the pool rather than cluster by
     * recency (#344). The same seed always produces the same order.
     *
     * @return list<PromptLine>
     */
    public function load(int $userId, CandidatePoolRequest $request): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere(UnreadDql::predicate())
```

Immediately after the existing interaction-flag `andWhere(...)` block, add:

```php
            // The window is the reader's own look-back setting, already
            // resolved to an instant by the caller. Inclusive: an entry
            // stamped exactly at the boundary is inside the window.
            ->andWhere('e.effectiveDate >= :since')
```

and change the tail of the chain to read from the request:

```php
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($request->poolSize)
            ->setParameter('since', $request->since)
            ->setParameter('readFalse', false, Types::BOOLEAN)
            ->setParameter('notInteracted', false, Types::BOOLEAN);
```

Then use the seed from the request:

```php
        $shuffled = (new Randomizer(new Mt19937($request->orderSeed)))->shuffleArray($lines);
```

- [ ] **Step 5: Run the loader tests**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`
Expected: PASS

- [ ] **Step 6: Write the failing advancer test**

In `RecommendationRunAdvancerTest`, add:

```php
    public function testSnapshotExcludesCandidatesOlderThanTheLookbackWindow(): void
    {
        $this->seedReadyAiSettings($this->user);
        // Default window is 2 days: 30 minutes ago is inside, 5 days ago is not.
        $inside = $this->entry('inside-window', 30);
        $this->entry('outside-window', 60 * 24 * 5);
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $this->advancer()->advance($this->user);

        $this->em->clear();
        $persisted = $this->em->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame([[$inside->getId()]], $persisted->getCandidateBatches());
    }

    public function testSnapshotWithEveryCandidateOutsideTheWindowCompletesEmpty(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->entry('long-gone', 60 * 24 * 30);
        $this->starter()->start($this->user);

        $report = $this->advancer()->advance($this->user);

        // Not a failure: an empty window freezes an empty plan, exactly like
        // an account with no unread entries at all.
        self::assertSame('completed', $report->status);
        self::assertSame(0, $report->batchesTotal);
    }
```

- [ ] **Step 7: Run it and watch it fail**

Run: `cd backend && php bin/phpunit --filter testSnapshotExcludesCandidatesOlderThanTheLookbackWindow`
Expected: FAIL — `RecommendationRunFixtures::entry()` still takes a date string, and the advancer still calls the old loader signature.

- [ ] **Step 8: Make the fixture window-relative**

In `backend/tests/Support/RecommendationRunFixtures.php`:

```php
    /**
     * A candidate entry stamped $minutesAgo before now. Relative on purpose:
     * the recommendation pool has a look-back window (#386), so an absolute
     * date in a fixture silently ages out of it and leaves the run with
     * nothing to snapshot.
     */
    public function entry(Feed $feed, string $guid, int $minutesAgo): Entry
    {
        $effectiveDate = new \DateTimeImmutable(\sprintf('-%d minutes', $minutesAgo));
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('-1 year'),
            $effectiveDate,
        );
        $entry->setPublishedAt($effectiveDate);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
```

Update its callers, keeping the newest-last ordering the tests rely on:

- `RecommendationRunAdvancerTest:85`, `:596`, `:1060`, `:1668` — `$this->entry('entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i))` becomes `$this->entry('entry-' . $i, 60 - $i)`
- `RecommendationRunAdvancerTest:1649` and `:1708` — `sprintf('2026-07-10T%02d:%02d:00Z', intdiv($i, 60), $i % 60)` becomes `1440 - $i` (still one distinct minute per entry, all inside the window)
- `AdvanceRecommendationRunsHandlerTest:612` and `:634` — the same `sprintf('2026-07-%02dT00:00:00Z', 10 + $i)` becomes `60 - $i`
- `RecommendationRunControllerTest:96-119` — replace both `new \DateTimeImmutable('2026-07-01T00:00:00Z')` uses for the entry with `new \DateTimeImmutable('-1 hour')`; leave the subscription's own date alone

- [ ] **Step 9: Wire the window into the snapshot**

In `RecommendationRunAdvancer::snapshotTick()`, replace the `load()` call:

```php
        $userId = $this->requireUserId($user);
        $effectiveSettings = $this->settingsResolver->forUser($user);
        $now = $this->clock->now();
        $candidates = $this->candidateLoader->load($userId, new CandidatePoolRequest(
            since: $now->sub(new \DateInterval(\sprintf('P%dD', $effectiveSettings->lookbackDays))),
            poolSize: $effectiveSettings->candidatePoolSize,
            orderSeed: (int) $now->getTimestamp(),
        ));
```

The clock is UTC and entries are stored as naive UTC, so `since` needs no conversion.

- [ ] **Step 10: Run the recommendation and worker suites**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Controller/Api`
Expected: PASS

- [ ] **Step 11: Lint and commit**

```bash
cd backend && composer cs && composer stan && composer md && composer tramp
```

```bash
git add backend/src backend/tests
git commit -m "feat(#386): bound the recommendation candidate pool by a look-back window"
```

---

### Task 3: The settings card

Puts the dropdown in front of the reader and renames the cap.

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings.service.ts`
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.ts`
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/recommendation-settings-card.component.spec.ts`
- Modify: `frontend/src/app/settings/ai-section.component.spec.ts:55` (its `STATE` literal needs the new field)

**Interfaces:**
- Consumes: JSON field `lookbackDays` (`number`, 1–7) from Task 1.
- Produces: `RecommendationSettingsState.lookbackDays: number`, `SaveRecommendationSettings.lookbackDays: number`, component signal `lookbackDays`, handler `setLookbackDays(event: Event): void`, `lookbackOptions` of seven `{ value: number; key: string }`.

- [ ] **Step 1: Write the failing specs**

In `recommendation-settings-card.component.spec.ts`, add `lookbackDays: 2,` to the `STATE` literal, change the expert-grid expectation from `'Candidate pool size'` to `'Maximum articles'`, add `lookbackDays: 2,` to the expected PUT body in the existing full-body test, and add:

```js
  it('renders the look-back select outside the expert disclosure', () => {
    const fixture = mount({ ...STATE, lookbackDays: 5 });

    const select = fixture.nativeElement.querySelector(
      '[data-testid="lookback-days"]',
    ) as HTMLSelectElement | null;
    expect(select).not.toBeNull();
    expect(select!.closest('app-disclosure')).toBeNull();
    expect(fixture.componentInstance.lookbackDays()).toBe(5);
  });

  it('sends the chosen look-back window on save', () => {
    const fixture = mount();

    const select = fixture.nativeElement.querySelector(
      '[data-testid="lookback-days"]',
    ) as HTMLSelectElement;
    select.value = '7';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    fixture.componentInstance.save();

    const request = http.expectOne('/api/me/ai/recommendations');
    expect(request.request.body.lookbackDays).toBe(7);
    request.flush({ ...STATE, lookbackDays: 7 });
  });
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd frontend && npx jest recommendation-settings-card`
Expected: FAIL — `lookbackDays` is not on the state type and the select does not exist.

- [ ] **Step 3: Extend the service types**

In `recommendation-settings.service.ts`, add to `RecommendationSettingsState` after `candidatePoolSize`:

```ts
  /** How many days back the candidate pool reaches; 1-7, default 2 (#386). */
  readonly lookbackDays: number;
```

and to `SaveRecommendationSettings` after `candidatePoolSize` (its doc comment now says eleven writable fields):

```ts
  readonly lookbackDays: number;
```

- [ ] **Step 4: Extend the component**

In `recommendation-settings-card.component.ts`, add the signal next to the others:

```ts
  readonly lookbackDays = linkedSignal<number>(
    () => this.svc.state()?.lookbackDays ?? 2,
  );
```

the options next to `intervalOptions`:

```ts
  /** The seven look-back choices, one per day (#386). */
  readonly lookbackOptions: readonly { readonly value: number; readonly key: string }[] = [
    { value: 1, key: 'settings.ai.recommendations.lookback1' },
    { value: 2, key: 'settings.ai.recommendations.lookback2' },
    { value: 3, key: 'settings.ai.recommendations.lookback3' },
    { value: 4, key: 'settings.ai.recommendations.lookback4' },
    { value: 5, key: 'settings.ai.recommendations.lookback5' },
    { value: 6, key: 'settings.ai.recommendations.lookback6' },
    { value: 7, key: 'settings.ai.recommendations.lookback7' },
  ];
```

the handler next to `setAutoGenerate`:

```ts
  setLookbackDays(event: Event): void {
    this.lookbackDays.set(+(event.target as HTMLSelectElement).value);
  }
```

and `lookbackDays: this.lookbackDays(),` in the `save()` payload after `candidatePoolSize`.

Correct the class docstring: the sentence about "the six numeric tuning fields" now reads "The six numeric tuning fields, the context window and the fixed prompt fold into one 'Expert settings' disclosure (#321 decision 6A); the auto-generate cadence and the look-back window (#386) stay outside it, because they are the two choices an ordinary account does make."

- [ ] **Step 5: Add the select to the template**

In `recommendation-settings-card.component.html`, directly after the closing `</app-field>` of the auto-generate field and before the `@if (!workerAlive())` block:

```html
  <app-field [label]="'settings.ai.recommendations.lookback' | transloco">
    <select data-testid="lookback-days" (change)="setLookbackDays($event)">
      @for (opt of lookbackOptions; track opt.value) {
        <option [value]="opt.value" [selected]="lookbackDays() === opt.value">
          {{ opt.key | transloco }}
        </option>
      }
    </select>
  </app-field>
```

- [ ] **Step 6: Add the strings**

In `frontend/public/i18n/en.json`, inside `settings.ai.recommendations`, after the `autoGenerateNoWorker` entry:

```json
    "lookback": "Look back",
    "lookback1": "Last 24 hours",
    "lookback2": "Last 2 days",
    "lookback3": "Last 3 days",
    "lookback4": "Last 4 days",
    "lookback5": "Last 5 days",
    "lookback6": "Last 6 days",
    "lookback7": "Last 7 days",
```

and change `"candidatePool"` to `"Maximum articles"`.

In `frontend/public/i18n/de.json`, the same keys:

```json
    "lookback": "Zeitraum",
    "lookback1": "Letzte 24 Stunden",
    "lookback2": "Letzte 2 Tage",
    "lookback3": "Letzte 3 Tage",
    "lookback4": "Letzte 4 Tage",
    "lookback5": "Letzte 5 Tage",
    "lookback6": "Letzte 6 Tage",
    "lookback7": "Letzte 7 Tage",
```

and change `"candidatePool"` to `"Maximal Artikel"`.

- [ ] **Step 7: Fix the other spec's state literal**

In `frontend/src/app/settings/ai-section.component.spec.ts:55`, add `lookbackDays: 2,` after `candidatePoolSize: 400,`.

- [ ] **Step 8: Run the frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS — ESLint, Prettier, Stylelint and Jest all clean.

- [ ] **Step 9: Commit**

```bash
git add frontend/src frontend/public
git commit -m "feat(#386): add the look-back select and rename the article cap"
```

---

### Task 4: Full verification and pull request

**Files:** none changed unless a gate finds something.

- [ ] **Step 1: Backend gates, native leg**

```bash
cd backend && composer check && composer md && php bin/phpunit
```

Expected: PASS. `composer check` runs cs, stan and tramp; a red tramp gate may come from phptramp's own `develop` tip — check `composer show larspohlmann/phptramp` before hunting in application code.

- [ ] **Step 2: Backend suite, MySQL leg**

```bash
docker compose up -d && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php vendor/bin/phpunit
```

Expected: PASS. Order-dependent rate-limiter failures in the full MySQL run are a known open flake (#298), not this branch's regression — confirm any failure passes in isolation before treating it as yours.

- [ ] **Step 3: MySQL migration from empty**

Migrate the chain from an empty **scratch** MySQL schema, then validate:

```bash
docker compose exec php bin/console doctrine:schema:validate
```

Expected: mapping and database in sync. Never point this at the dev database.

- [ ] **Step 4: Mutation gate on the changed files**

```bash
cd backend && composer infection:diff
```

Expected: MSI at or above `minMsi` in `infection.json5`. Escaped mutants on the `>=` boundary or on `lookbackDays` mean the boundary test is not pinning what it claims — fix the test, never the threshold.

- [ ] **Step 5: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` over every changed PHP file. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 6: Scan the dev log**

Read `backend/var/log/dev.log` for deprecations or swallowed errors raised by this branch.

- [ ] **Step 7: Open the pull request**

```bash
git push -u origin feature/386-recommendation-lookback-window
gh pr create --base develop --title "feat(#386): recommendation look-back window" --body "Closes #386"
```

The body must say `Closes #386` so the merge into `develop` closes the issue. Do not merge and do not tag a deploy without an explicit go-ahead.

---

## Self-Review

**Spec coverage:** settings chain and migration → Task 1; `CandidatePoolRequest`, the loader predicate and the snapshot wiring → Task 2; API shape → Task 1 (both directions); frontend, i18n and the rename → Task 3; the spec's test list → Tasks 1–3, its verification list → Task 4. The spec's "out of scope" items appear in no task.

**Placeholder scan:** every step carries the actual code or the actual command. No "handle edge cases", no "similar to Task N".

**Type consistency:** `CandidatePoolRequest(since, poolSize, orderSeed)` is constructed identically in Task 2's loader test helper and in `snapshotTick()`. `lookbackDays` is the name in PHP, in JSON, in the TS interfaces and in the component signal. `RecommendationRunFixtures::entry()`'s third parameter is `int $minutesAgo` at its definition and at all eight call sites listed.

**Known trap this plan defuses:** the existing fixtures date candidates to `2026-07-…`. Without Task 2 Step 8 the new default window would empty every snapshot test's pool, and the failures would look like the loader is broken rather than the fixtures being stale.
