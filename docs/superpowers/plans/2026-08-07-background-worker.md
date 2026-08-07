# Background Worker Container Implementation Plan (#311)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A worker container for the Docker stacks that drives #308 recommendation runs in the background (a run completes with the tab closed), sweeps due feeds every 5 minutes, and purges failed-message housekeeping — while the Strato poll-driven path keeps working unchanged and remains the automatic fallback.

**Architecture:** One state machine, two drivers. The worker is a second compose service from the existing PHP image running `messenger:consume` over a **Symfony Scheduler** schedule (`#[AsSchedule('worker')]`): a 10-second sweep finds active runs and calls the same driver-agnostic `RecommendationRunAdvancer::advance()` the poll endpoint uses, touching a DB heartbeat each firing. Client poll ticks defer to a fresh heartbeat (they become pure status reads, flagged `background: true` in the report so the UI switches its copy and slows its cadence); a stale heartbeat means the #308 poll behavior applies untouched. Nothing is ever dispatched by web requests — no messages can accumulate on Strato — and the only Messenger transport is a Doctrine **failure** transport for post-mortem. No broker, no override switch: the worker's presence is the mode.

**Tech Stack:** symfony/messenger + symfony/scheduler + symfony/doctrine-messenger (failure transport only), Symfony Lock (already present — the per-user run lock stays the correctness guarantee), Doctrine entity heartbeat, Docker Compose (dev + prod), Angular 20 signals.

## Global Constraints

- `declare(strict_types=1)` everywhere; PSR-12 (`composer cs:fix`); PHPStan level max (warm cache first: `bin/console cache:warmup`); every touched `src` file **PHPMD-clean**; `ThinControllerRule` (empty allow-list).
- Datetimes are naive UTC; `ClockInterface` is the only time source.
- Migrations: platform-aware (MySQL + SQLite), never executed by the test suite; verify both dialects by hand (CI's migrate-from-empty leg is the only runtime check).
- **The tick stays driver-agnostic.** `RecommendationRunAdvancer::advance(User): RecommendationRunReport` is called verbatim by both drivers; no request/HTTP types may leak into it, and its lock (`'ai-recommendations-' . userId`, TTL 300 s) remains the correctness guarantee. The heartbeat is an **efficiency signal only**.
- **`POST /api/recommendations/runs` and every other web request dispatch nothing.** The scheduler generates its own recurring messages in the worker; Strato's database must never accumulate Messenger rows.
- Scheduler entries at launch: the recommendation sweep (10 s), the feed refresh sweep (5 min, `RefreshRequest::allDue(...)` — the 2026-08-07 decision that supersedes "manual refresh only" for worker-equipped installs; Strato/poll installs stay manual-only), and failed-message purging (daily). **No scheduled recommendation runs** (#308: manual button only).
- Progress transport stays **polling** in both modes — no SSE, no Mercure (native iOS constraint). Token streaming stays out; streamed provider reads are #312.
- Frontend gates: Prettier 100-col, Stylelint (no hex / raw px outside `theme/`), `npm run check`; `en.json`/`de.json` change together (`i18n-dictionaries.spec.ts`).
- Prod hazards found in recon and MUST be respected: `docker/php/entrypoint-prod.sh` currently ends in a hardcoded `exec php-fpm` ignoring `command:` (a naive worker silently runs FPM); it also does `rm -f var/.ready` + `rm -rf var/cache/prod` on the **shared `php-var` volume** at every start (a worker restart would break `wait_for_php_ready` and flush a live FPM's cache). The prod `php` `environment:` block (~25 vars) has no anchor; `restart:` policies exist nowhere in the repo yet.
- `docker compose down` is safe; **never** `docker compose down -v`.
- New container-fetched test services go into `backend/config/services_test.yaml` with `autowire: true, public: true`.
- Commit messages: `type(#311): imperative summary`.

## File Structure

| File | Responsibility |
|---|---|
| `backend/composer.json` (+lock) | symfony/messenger, symfony/scheduler, symfony/doctrine-messenger |
| `backend/config/packages/messenger.yaml` (new, or Flex-generated then edited) | failure transport only, doctrine, auto_setup |
| `backend/src/Entity/WorkerHeartbeat.php` + `Repository/WorkerHeartbeatRepository.php` (new) | named heartbeat row, `touch()` |
| `backend/src/Service/Worker/WorkerPresence.php` (new) | freshness policy (`RECOMMENDATION_SWEEP`, 30 s) |
| `backend/migrations/Version20260807140000.php` (new) | `worker_heartbeat` table, both dialects |
| `backend/src/Service/Recommendation/RecommendationRunReport.php` | `background` flag + `inBackground()` |
| `backend/src/Service/Recommendation/RecommendationPollDriver.php` (new) | poll-side arbitration: defer to a fresh heartbeat |
| `backend/src/Controller/Api/RecommendationRunController.php` | tick/current go through the poll driver |
| `backend/src/Service/Worker/Message/{AdvanceRecommendationRuns,RefreshDueFeeds,PurgeFailedMessages}.php` (new) | empty marker messages |
| `backend/src/Service/Worker/WorkerSchedule.php` (new) | the `worker` schedule: 10 s / 5 min / daily |
| `backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php` (new) | heartbeat + one tick per active run per firing |
| `backend/src/Service/Worker/Handler/RefreshDueFeedsHandler.php` (new) | `RefreshRunner::run(allDue(120))` |
| `backend/src/Service/Worker/Handler/PurgeFailedMessagesHandler.php` (new) | bounded `messenger_messages` |
| `backend/src/Repository/RecommendationRunRepository.php` | `findAllActive()` |
| `frontend/src/app/reader/models.ts`, `recommendations.service.ts`, `reader-shell.component.html/scss`, i18n | `background` field, slow poll cadence, copy switch |
| `docker/php/entrypoint-prod.sh` | dual-mode: args → wait-for-ready + `su-exec www-data "$@"` |
| `docker-compose.prod.yml` | env anchor + `worker` service (`restart: unless-stopped`) |
| `docker-compose.yml` | dev `worker` service |
| `scripts/prod-start.sh`, `scripts/update.sh`, `scripts/lib.sh` | post-migration worker restart + log hints |
| `docs/local-docker.md`, `docs/docker-production.md` | worker documented; §8 promise flipped to delivered |

---

### Task 1: Messenger + Scheduler dependencies and transport config

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`, `backend/symfony.lock` (Flex)
- Create/verify: `backend/config/packages/messenger.yaml`

**Interfaces:**
- Produces: the `messenger:consume` command; the Doctrine `failed` transport; nothing else observable.

- [ ] **Step 1: Install the packages**

```bash
cd backend && composer require symfony/messenger:7.4.* symfony/scheduler:7.4.* symfony/doctrine-messenger:7.4.*
```

Flex writes `config/packages/messenger.yaml`. If Flex touched committed `.env` files, revert those hunks — nothing here needs env vars.

- [ ] **Step 2: Replace the generated messenger.yaml with exactly this**

```yaml
framework:
    messenger:
        # The doctrine transport keeps the broker out of the stack (#311): the
        # recommendation runs table is the real queue, and this transport only
        # holds schedule messages whose handler failed, for post-mortem. Web
        # requests never dispatch anything, so on Strato -- where nothing
        # consumes -- no rows can ever accumulate. auto_setup creates
        # messenger_messages lazily on the first failed delivery, which only
        # the worker container can cause; FPM containers never see the table.
        failure_transport: failed

        transports:
            failed: 'doctrine://default?queue_name=failed'
```

(The scheduler transport `scheduler_worker` needs no entry: consuming it by name auto-provisions it from the `#[AsSchedule('worker')]` provider added in Task 4.)

- [ ] **Step 3: Prove the committed state still boots and passes**

Run: `bin/console cache:warmup && composer check && php bin/phpunit`
Expected: all green; `bin/console debug:messenger` lists the `failed` transport.

- [ ] **Step 4: Commit** (the global gitignore hides composer.lock; `!/composer.lock` re-includes it — verify `git status` shows the lock as modified)

```bash
git add backend/composer.json backend/composer.lock backend/symfony.lock backend/config/packages/messenger.yaml backend/.env* 2>/dev/null || true
git add -A && git commit -m "feat(#311): messenger and scheduler with a doctrine failure transport"
```

---

### Task 2: Heartbeat entity, presence policy, migration

**Files:**
- Create: `backend/src/Entity/WorkerHeartbeat.php`, `backend/src/Repository/WorkerHeartbeatRepository.php`, `backend/src/Service/Worker/WorkerPresence.php`, `backend/migrations/Version20260807140000.php`
- Modify: `backend/config/services_test.yaml` (`WorkerPresence` public)
- Test: `backend/tests/Service/Worker/WorkerPresenceTest.php` (create)

**Interfaces:**
- Produces:
  - `WorkerHeartbeat` (table `worker_heartbeat`): `__construct(string $name, \DateTimeImmutable $touchedAt)`, `getName(): string`, `getTouchedAt(): \DateTimeImmutable`, `touch(\DateTimeImmutable $when): void`. `name` is the `#[ORM\Id]` (`Column(length: 64)`, no generator); `touchedAt` `DATETIME_IMMUTABLE`.
  - `WorkerHeartbeatRepository::touch(string $name, \DateTimeImmutable $when): void` (find-or-create + flush; single writer, no upsert SQL needed) and `findTouchedAt(string $name): ?\DateTimeImmutable`.
  - `WorkerPresence` — `final readonly`, deps `(WorkerHeartbeatRepository $heartbeats, ClockInterface $clock)`; `public const string RECOMMENDATION_SWEEP = 'recommendation-sweep';`, `private const int FRESH_SECONDS = 30;` (three missed 10-second sweeps = dead); `markRecommendationSweep(): void`; `isRecommendationWorkerAlive(): bool` (`touchedAt !== null && now - touchedAt <= 30`).

- [ ] **Step 1: Write the failing test** (`DbTestCase`; drive time by touching with explicit timestamps and injecting Symfony's `MockClock` — fetch `WorkerPresence` from the container but construct a second instance manually with `new WorkerPresence($repository, new MockClock('2026-08-07 12:00:00'))` for the boundary cases; the repository comes from `$this->em->getRepository(WorkerHeartbeat::class)`):

```php
public function testNoHeartbeatMeansNoWorker(): void
{
    self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
}

public function testAFreshHeartbeatMeansAlive(): void
{
    $this->repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, new \DateTimeImmutable('2026-08-07 11:59:40'));

    self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
}

public function testExactlyThirtySecondsOldIsStillAlive(): void
{
    $this->repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, new \DateTimeImmutable('2026-08-07 11:59:30'));

    self::assertTrue($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
}

public function testThirtyOneSecondsOldIsDead(): void
{
    $this->repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, new \DateTimeImmutable('2026-08-07 11:59:29'));

    self::assertFalse($this->presenceAt('2026-08-07 12:00:00')->isRecommendationWorkerAlive());
}

public function testTouchTwiceUpdatesTheOneRow(): void
{
    $this->repository()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:00'));
    $this->repository()->touch('x', new \DateTimeImmutable('2026-08-07 11:00:10'));

    self::assertEquals(new \DateTimeImmutable('2026-08-07 11:00:10'), $this->repository()->findTouchedAt('x'));
}
```

- [ ] **Step 2: Run to verify failure** — `php bin/phpunit tests/Service/Worker/` → FAIL (classes missing).
- [ ] **Step 3: Implement the three classes** as specified in Interfaces. Entity docblock: "One named liveness signal. The worker's sweep touches its row every firing; the poll driver treats a fresh row as 'a worker owns execution'. This is an efficiency signal only — the per-user run lock stays the correctness guarantee (#311)."
- [ ] **Step 4: Write the migration** — `Version20260807140000`, house pattern (`skipIf` table exists, platform branches, `isTransactional(): false`):

MySQL: `CREATE TABLE worker_heartbeat (name VARCHAR(64) NOT NULL, touched_at DATETIME NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB`
SQLite: `CREATE TABLE worker_heartbeat (name VARCHAR(64) NOT NULL, touched_at DATETIME NOT NULL, PRIMARY KEY (name))`
`down()`: `DROP TABLE worker_heartbeat`. Verify both dialects:

```bash
DATABASE_URL="sqlite:///$PWD/var/migration-check.db" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///$PWD/var/migration-check.db" bin/console doctrine:schema:validate && rm var/migration-check.db
docker compose up -d && docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate
```

- [ ] **Step 5: Run to verify pass, lint, commit**

```bash
php bin/phpunit tests/Service/Worker/ && composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#311): worker heartbeat with a thirty-second freshness policy"
```

---

### Task 3: `background` report flag and poll-side arbitration

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunReport.php`, `backend/src/Controller/Api/RecommendationRunController.php`
- Create: `backend/src/Service/Recommendation/RecommendationPollDriver.php`
- Modify: `backend/config/services_test.yaml` (poll driver public)
- Test: `backend/tests/Controller/Api/RecommendationRunControllerTest.php` (extend), `backend/tests/Http/…` none

**Interfaces:**
- Consumes: `WorkerPresence` (Task 2), existing advancer/repository.
- Produces:
  - `RecommendationRunReport` gains `public bool $background` (private constructor's fifth param, default `false`), `inBackground(): self` (same values, `background: true`), and `toArray()` gains the `background` key. **Every existing #308 controller/service test asserting the exact array must add `'background' => false`.**
  - `RecommendationPollDriver` — `final readonly`, deps `(RecommendationRunAdvancer $advancer, RecommendationRunRepository $runs, WorkerPresence $presence)`:

```php
/**
 * The poll driver's side of #311's arbitration: a fresh worker heartbeat
 * means the worker owns execution, so a poll tick becomes a pure status
 * read; a stale one means the #308 poll behaviour applies untouched. Kill
 * the worker mid-run and the next poll tick advances from the checkpoint --
 * the fallback is automatic in both directions, with no config switch.
 */
public function poll(User $user): RecommendationRunReport
{
    if ($this->presence->isRecommendationWorkerAlive()) {
        return $this->current($user);
    }

    return $this->advancer->advance($user);
}

public function current(User $user): RecommendationRunReport
{
    $latest = $this->runs->findLatestForUser($user);
    $report = null === $latest ? RecommendationRunReport::none() : RecommendationRunReport::fromRun($latest);

    return $this->presence->isRecommendationWorkerAlive() ? $report->inBackground() : $report;
}
```

  - Controller: `tick()` calls `$this->pollDriver->poll($user)` (exception mapping unchanged — the advance path still throws); `current()` calls `$this->pollDriver->current($user)`; the controller drops its `RecommendationRunRepository` dependency and gains `RecommendationPollDriver`.

- [ ] **Step 1: Write the failing tests** (extend `RecommendationRunControllerTest`; the heartbeat is made "fresh" by touching it through the container repository with the container clock's now):

```php
public function testTickDefersToAFreshWorkerHeartbeat(): void
{
    [$headers, $user] = $this->authWithReadyAi('defer@example.com');
    $client = /* the created client */;
    $this->startRun($client, $headers);           // POST /api/recommendations/runs
    $this->touchWorkerHeartbeatNow();             // repository()->touch(WorkerPresence::RECOMMENDATION_SWEEP, clock now)

    $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

    $report = json_decode((string) $client->getResponse()->getContent(), true);
    self::assertSame('pending', $report['status']); // still pending: no snapshot happened
    self::assertTrue($report['background']);
    self::assertSame([], $this->stubChat()->calls()); // and no provider call
}

public function testTickAdvancesWhenTheHeartbeatIsStale(): void
{
    // Same setup, heartbeat touched 31+ s in the past (or never) -> the tick
    // snapshots: status running, background false.
}

public function testCurrentReportsBackgroundWhenTheWorkerIsAlive(): void
{
    // GET current with fresh heartbeat -> background true; without -> false.
}
```

Also update every existing assertion of the report array in this file (and any service test comparing `toArray()`) to include `'background' => false`.

- [ ] **Step 2: Run to verify failure** — `php bin/phpunit tests/Controller/Api/RecommendationRunControllerTest.php`.
- [ ] **Step 3: Implement report flag, poll driver, controller rewiring.**
- [ ] **Step 4: Run to verify pass** — that file plus `php bin/phpunit tests/Service/Recommendation/` (report-shape ripples).
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#311): poll ticks defer to a fresh worker heartbeat"
```

---

### Task 4: Schedule provider and marker messages

**Files:**
- Create: `backend/src/Service/Worker/Message/AdvanceRecommendationRuns.php`, `.../Message/RefreshDueFeeds.php`, `.../Message/PurgeFailedMessages.php`, `backend/src/Service/Worker/WorkerSchedule.php`
- Modify: `backend/config/services_test.yaml` (`WorkerSchedule` public)
- Test: `backend/tests/Service/Worker/WorkerScheduleWiringTest.php` (create)

**Interfaces:**
- Produces: three empty `final readonly class` marker messages (no properties — the sweep derives everything from the database, deliberately: a message that carries state could go stale in the failure transport); and:

```php
/**
 * The worker container's whole job description (#311): consume this schedule
 * with `messenger:consume scheduler_worker`. Three entries by decision:
 * the recommendation sweep, the feed refresh sweep (the 2026-08-07 decision
 * that brings scheduled refresh to worker-equipped installs; poll-only
 * installs stay manual), and failure-transport housekeeping. Scheduled
 * recommendation runs stay out (#308: manual button only).
 */
#[AsSchedule('worker')]
final readonly class WorkerSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('10 seconds', new AdvanceRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new RefreshDueFeeds()))
            ->add(RecurringMessage::every('1 day', new PurgeFailedMessages()));
    }
}
```

- [ ] **Step 1: Write the failing wiring test** (mirrors the house `*WiringTest` convention — pin what the container actually wires, so a refactor cannot silently drop a schedule entry):

```php
final class WorkerScheduleWiringTest extends KernelTestCase
{
    public function testTheWorkerScheduleCarriesExactlyTheDecidedEntries(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(WorkerSchedule::class);
        self::assertInstanceOf(WorkerSchedule::class, $provider);

        $messages = $provider->getSchedule()->getRecurringMessages();

        self::assertCount(3, $messages);
        $classes = array_map(
            static fn ($recurring) => $recurring->getMessages()[0]::class,
            $messages,
        );
        self::assertSame(
            [AdvanceRecommendationRuns::class, RefreshDueFeeds::class, PurgeFailedMessages::class],
            $classes,
        );
    }
}
```

(If `getMessages()` differs in this Symfony version, adapt to the actual `RecurringMessage` accessor — `debug:scheduler` shows the wiring; the assertion's intent is fixed: three entries, these three message classes, this order.)

- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement messages + provider; register `WorkerSchedule` in `services_test.yaml`.**
- [ ] **Step 4: Verify pass + the transport exists**

Run: `php bin/phpunit tests/Service/Worker/ && bin/console debug:scheduler`
Expected: tests green; the `worker` schedule lists the three recurring messages.

- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#311): worker schedule with sweep, refresh and housekeeping entries"
```

---

### Task 5: The recommendation sweep handler

**Files:**
- Create: `backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php`
- Modify: `backend/src/Repository/RecommendationRunRepository.php` (`findAllActive()`), `backend/config/services_test.yaml` (handler public)
- Test: `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php` (create)

**Interfaces:**
- Consumes: `RecommendationRunAdvancer::advance(User)`, `WorkerPresence::markRecommendationSweep()`, `RecommendationRun::fail(string, \DateTimeImmutable)`.
- Produces:
  - `RecommendationRunRepository::findAllActive(): array` — `list<RecommendationRun>`, status pending/running, ordered `r.id ASC` (oldest first — fairness across users).
  - The handler — one tick per active run per firing; the 10-second cadence means back-to-back firings while work exists, so no internal loop and no loop-termination proof is needed:

```php
#[AsMessageHandler]
final readonly class AdvanceRecommendationRunsHandler
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private RecommendationRunAdvancer $advancer,
        private WorkerPresence $presence,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AdvanceRecommendationRuns $message): void
    {
        // Touched every firing, work or not: the heartbeat is the liveness
        // signal the poll driver defers to, not a work log.
        $this->presence->markRecommendationSweep();

        foreach ($this->runs->findAllActive() as $run) {
            $this->advanceOne($run);
        }

        // A long-running consumer accumulates managed entities across sweeps;
        // the identity map is per-firing state, not worker state.
        $this->entityManager->clear();
    }

    private function advanceOne(RecommendationRun $run): void
    {
        try {
            $this->advancer->advance($run->getUser());
        } catch (AiNotConfiguredException) {
            // The account lost its provider or model mid-run. The run can
            // never advance again on any driver, so fail it rather than sweep
            // over the same exception every ten seconds forever.
            $run->fail('The AI provider is no longer configured.', $this->clock->now());
            $this->entityManager->flush();
        } catch (ApiKeyUnreadableException) {
            // Same shape: the advancer cannot even build credentials, and no
            // amount of retrying fixes a key only the user can re-enter.
            $run->fail('The stored API key can no longer be read.', $this->clock->now());
            $this->entityManager->flush();
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            // The advancer already counted this against the run's own
            // transport-failure ceiling; the sweep just moves on and the next
            // firing retries. One user's dead provider must not fail the
            // message and starve every other user's run.
            $this->logger->warning('Recommendation sweep: provider call failed.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);
        }
    }
}
```

- [ ] **Step 1: Write the failing tests** (`DbTestCase`; seed via the same helpers `RecommendationRunAdvancerTest` uses — ready AI settings + unread entries; the container's `ChatCompletionClient` is the `StubChatClient`):
  - `testFiringTouchesTheHeartbeatEvenWithNoRuns` — invoke on an empty database → `WorkerPresence::isRecommendationWorkerAlive()` true (use the container presence; the test clock and container clock agree in the test env).
  - `testDrivesARunToCompletionAcrossFirings` — seed a single-batch fixture, start a run via `RecommendationRunStarter`, queue one clean reply; invoke the handler **twice** (snapshot firing, then batch firing) → run completed, items exist.
  - `testProviderFailureIsLoggedAndDoesNotThrow` — `queueFailure(new ProviderUnreachableException('down'))` → `__invoke` completes without throwing; the run is still active; a second seeded user's run (queue a clean reply for it) still got its tick in the same firing.
  - `testUnconfiguredUsersRunIsFailedNotSweptForever` — start a run, then delete the user's `AiProviderSettings` row (+ flush + clear) → after one firing the run's status is failed with the not-configured message.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement handler + `findAllActive()`.**
- [ ] **Step 4: Run to verify pass** — `php bin/phpunit tests/Service/Worker/`.
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#311): sweep handler drives active runs and touches the heartbeat"
```

---

### Task 6: Refresh sweep and failed-message housekeeping handlers

**Files:**
- Create: `backend/src/Service/Worker/Handler/RefreshDueFeedsHandler.php`, `backend/src/Service/Worker/Handler/PurgeFailedMessagesHandler.php`
- Modify: `backend/config/services_test.yaml` (both public)
- Test: `backend/tests/Service/Worker/RefreshDueFeedsHandlerTest.php`, `backend/tests/Service/Worker/PurgeFailedMessagesHandlerTest.php` (create both)

**Interfaces:**
- Consumes: `RefreshRunner::run(RefreshRequest): RefreshReport`, `RefreshRequest::allDue(int $budgetSeconds, bool $prune = true, bool $force = false)`.
- Produces:

```php
#[AsMessageHandler]
final readonly class RefreshDueFeedsHandler
{
    /**
     * Generous compared with the HTTP endpoints' 20-25 s: the worker has no
     * FastCGI window to fit. Feeds the budget skips are picked up by the next
     * five-minute firing, so the cap only bounds one firing's work.
     */
    private const int BUDGET_SECONDS = 120;

    public function __construct(
        private RefreshRunner $refreshRunner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshDueFeeds $message): void
    {
        $report = $this->refreshRunner->run(RefreshRequest::allDue(self::BUDGET_SECONDS));

        // 'busy' is healthy here: a user-driven refresh holds the global lock
        // and is doing the same work; this firing simply yields to it.
        $this->logger->info('Worker refresh sweep finished.', ['report' => $report->toArray()]);
    }
}
```

```php
#[AsMessageHandler]
final readonly class PurgeFailedMessagesHandler
{
    private const int RETENTION_DAYS = 30;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeFailedMessages $message): void
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS));

        try {
            $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = :queue AND created_at < :cutoff',
                ['queue' => 'failed', 'cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (TableNotFoundException) {
            // auto_setup creates the table on the first failed delivery; a
            // stack that never failed a message has nothing to purge.
        }
    }
}
```

(`Connection` is `Doctrine\DBAL\Connection`; `TableNotFoundException` is `Doctrine\DBAL\Exception\TableNotFoundException`.)

- [ ] **Step 1: Write the failing tests:**
  - Refresh: `DbTestCase`; with no feeds seeded, `__invoke` completes without throwing (the runner reports `completed` over zero feeds). One more case: seed one due feed backed by the container's `StubFeedFetcher` success outcome and assert `feed.last_fetched_at` moved — copy the seeding idiom from `tests/Service/Refresh/RefreshRunnerTest.php` rather than inventing one; if that seeding is heavyweight, the zero-feed no-throw case plus the wiring already covered by `RefreshRunnerTest` is acceptable — say so in the test docblock.
  - Purge: `DbTestCase`; `__invoke` on a schema without `messenger_messages` does not throw (the catch branch); then create the table by hand via DBAL (`CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)` — SQLite, the native test engine), insert one `failed` row 31 days old, one `failed` row 1 day old, one `default` row 31 days old → after `__invoke` only the old `failed` row is gone.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement both handlers.**
- [ ] **Step 4: Run to verify pass** — `php bin/phpunit tests/Service/Worker/` then the full `php bin/phpunit`.
- [ ] **Step 5: Lint and commit**

```bash
composer cs:fix && composer check && composer md
git add -A && git commit -m "feat(#311): refresh sweep and failure-transport housekeeping handlers"
```

---

### Task 7: Frontend — background regime

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/recommendations.service.ts`, `frontend/src/app/reader/reader-shell.component.html`, `frontend/src/app/reader/reader-shell.component.scss`, `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/recommendations.service.spec.ts`, `frontend/src/app/reader/reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: the `background` field Task 3 added to every report payload.
- Produces:
  - `models.ts`: `RecommendationRunReport` gains `background: boolean` (required — the backend always sends it; every spec fixture must add it).
  - `recommendations.service.ts`: new const `BACKGROUND_POLL_MS = 4000;` and `stepLater` gains a delay parameter:

```ts
private stepLater(attempts: PollAttempts, delayMs = BACKOFF_MS): void {
  setTimeout(() => this.step(attempts), delayMs);
}
```

In `onReport`, the `pending`/`running` case slows down when the worker owns execution — a deferred tick returns instantly, so the tight recursive loop that matched a minutes-long server call would otherwise hammer the endpoint:

```ts
case 'pending':
case 'running':
  if (r.background) this.stepLater(NO_ATTEMPTS, BACKGROUND_POLL_MS);
  else this.step(NO_ATTEMPTS);
  break;
```

(4 s ≈ 15 requests/min against the `ai_recommendations` limiter's 90 per 5 minutes — comment this next to the const.)
  - Shell for-you bar: under the progress line, a regime hint:

```html
@if (recs.running()) {
  <p role="status" aria-live="polite">
    {{ 'reader.forYouProgress' | transloco: forYouProgress() }}
  </p>
  <p class="hint">
    {{ (recs.report()?.background ? 'reader.forYouBackground' : 'reader.forYouKeepOpen') | transloco }}
  </p>
} @else { … existing run button … }
```

SCSS: `.for-you-bar .hint { margin: 0; color: var(--text-muted); font-size: var(--fs-xs); }`.
  - i18n (both files): `reader.forYouKeepOpen` — EN "Keep the app open while this runs." / DE "Lass die App geöffnet, während dies läuft."; `reader.forYouBackground` — EN "Runs in the background — you can close this tab." / DE "Läuft im Hintergrund — du kannst diesen Tab schließen."

- [ ] **Step 1: Update every spec fixture** that builds a `RecommendationRunReport` to carry `background: false`; run `npx jest src/app/reader` → green again before new behavior.
- [ ] **Step 2: Write the failing specs:**
  - service: with `jest.useFakeTimers()`, a `running` report with `background: true` does **not** issue the next tick synchronously; it fires after `jest.advanceTimersByTime(4000)`; with `background: false` the next tick is immediate (existing behavior pinned).
  - shell: with a running fake whose report has `background: true`, the hint text is the background copy; with `false`, the keep-open copy.
- [ ] **Step 3: Run to verify failure; implement; run to verify pass.**
- [ ] **Step 4: `npm run check`.**
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(#311): background regime slows the poll and switches the copy"
```

---

### Task 8: Prod entrypoint dual-mode and the prod worker service

**Files:**
- Modify: `docker/php/entrypoint-prod.sh`, `docker-compose.prod.yml`

**Interfaces:**
- Produces: an entrypoint that runs the given command when one is passed (worker mode) and keeps today's FPM behavior byte-for-byte when none is; a `worker` service sharing the `php` env block via a YAML anchor.

- [ ] **Step 1: Rework the entrypoint**

`docker/php/entrypoint-prod.sh` — insert the console mode **before** the existing body, leaving the FPM path untouched:

```sh
set -e

if [ "$#" -gt 0 ]; then
  # Console/worker mode (#311). The php-fpm container owns the shared php-var
  # volume's lifecycle -- the var/.ready flag the scripts poll and the
  # var/cache/prod rebuild both belong to it alone, so this path must touch
  # neither: a worker restart that deleted them would break
  # wait_for_php_ready and flush a live FPM's cache. Wait for readiness,
  # drop to www-data, run the given command.
  until [ -f var/.ready ]; do sleep 2; done
  exec su-exec www-data "$@"
fi

rm -f var/.ready
# … existing lines unchanged through …
exec php-fpm
```

- [ ] **Step 2: Add the env anchor and the worker service to `docker-compose.prod.yml`**

Move the entire existing `php.environment:` map (verbatim, comments included) to a top-level extension field above `services:`:

```yaml
# One environment block, two consumers: php-fpm and the worker must see the
# same configuration or they compute different DATABASE_URLs (#311).
x-app-environment: &app-environment
  DATABASE_URL: "${DATABASE_URL:-mysql://${MYSQL_USER:-feedreader}:${MYSQL_PASSWORD:?set it in .env.prod}@mysql:3306/${MYSQL_DATABASE:-feedreader}?serverVersion=8.4&charset=utf8mb4}"
  # … every other line of today's php.environment, moved verbatim …
```

then `php:` uses `environment: *app-environment`, and add:

```yaml
  # The #311 background worker: same image, different command. It drives
  # recommendation runs, sweeps due feeds, and purges the failure transport
  # (see backend/src/Service/Worker/WorkerSchedule.php). If it dies, the app
  # degrades to #308's poll-driven behaviour automatically -- no config.
  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: prod
    command: ["php", "bin/console", "messenger:consume", "scheduler_worker", "--time-limit=3600", "--memory-limit=128M"]
    # The first restart policy in this stack, and the one service that needs
    # it: messenger:consume exits by design at --time-limit, and something
    # must bring it back.
    restart: unless-stopped
    environment: *app-environment
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - php-var:/app/var
      - jwt-keys:/app/config/jwt
    depends_on:
      mysql:
        condition: service_healthy
        # Same as php: a SQLite install runs without the mysql profile.
        required: false
      php:
        # Start-order hint only; the entrypoint's var/.ready wait is the real
        # readiness gate.
        condition: service_started
```

- [ ] **Step 3: Verify the compose file parses and the anchor resolves**

```bash
docker compose -p sfr-config-check -f docker-compose.prod.yml --env-file .env.prod.example config > /dev/null && echo OK
```

Expected: `OK` (if `.env.prod.example` placeholders trip a `:?` guard, run with a scratch env file supplying dummy values — do not weaken any `:?`).

- [ ] **Step 4: Commit**

```bash
git add docker/php/entrypoint-prod.sh docker-compose.prod.yml
git commit -m "feat(#311): prod worker service with a dual-mode entrypoint"
```

---

### Task 9: Dev worker service, scripts, docs

**Files:**
- Modify: `docker-compose.yml`, `scripts/prod-start.sh`, `scripts/update.sh`, `scripts/lib.sh`, `docs/local-docker.md`, `docs/docker-production.md`

- [ ] **Step 1: Add the dev worker to `docker-compose.yml`** (after `php`):

```yaml
  # The #311 background worker. Dev target has no entrypoint, so the command
  # overrides the base image's php-fpm CMD cleanly; the bind mount shares
  # vendor/ and var/ with the php container, so `composer install` there
  # serves this one too. -vv surfaces each schedule firing in the logs.
  worker:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: dev
    command: ["php", "bin/console", "messenger:consume", "scheduler_worker", "-vv", "--time-limit=3600"]
    restart: unless-stopped
    volumes:
      - ./backend:/app
    environment:
      DATABASE_URL: "mysql://feedreader:feedreader@mysql:3306/feedreader?serverVersion=8.4&charset=utf8mb4"
      MAILER_DSN: "smtp://mailpit:1025"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    depends_on:
      mysql:
        condition: service_healthy
```

- [ ] **Step 2: Script touch-ups** — three mechanical edits, each with a one-line reason:
  - `scripts/prod-start.sh`: after the `doctrine:migrations:migrate` line, add `prod_compose restart worker  # it may have started before the schema existed (first install)`; extend the log hint line to `logs -f php web worker`.
  - `scripts/update.sh`: after the dev `doctrine:migrations:migrate` line, add `compose restart worker`; extend its log hint to `logs -f php nginx worker`.
  - `scripts/lib.sh` (`bring_up_stack`): after its migrate line, add `compose restart worker` with the same comment.
- [ ] **Step 3: Docs** —
  - `docs/local-docker.md` §1: add the worker row to the services table ("Worker — recommendation runs in the background, 5-minute feed refresh sweep; `docker compose logs -f worker`") and bump the "Five services" wording to six; §8: rewrite the "Worker / cron container" bullet from a promise to "Delivered in #311 — see the `worker` service; it reuses the php image and the same env injection."
  - `docs/docker-production.md`: in the intro's container enumeration add the worker; under Troubleshooting add a short "The worker" entry: what it does, `docker compose -p simple-feed-reader-prod logs -f worker`, and the degradation note (a dead worker means runs advance only while the app is open — #308 behaviour — and scheduled refresh pauses; feeds still refresh manually).
- [ ] **Step 4: Live smoke in the dev stack** —

```bash
docker compose up -d --build && docker compose logs -f worker
```

Expected within ~30 s: consumer boots, the sweep fires (visible at `-vv`), no exceptions. Then in the app (`npm start` or the docker frontend): with the worker up, start a For-you run and confirm the bar shows the background copy and the run completes with the tab closed (reopen → toast/current shows completed). Stop the worker (`docker compose stop worker`), start another run: the keep-open copy shows and ticks advance the run (#308 behaviour). Restart the worker afterwards.
- [ ] **Step 5: Shellcheck + commit** (CI runs `shellcheck scripts/*.sh` and fails on ANY finding, info-level included)

```bash
shellcheck scripts/*.sh
git add -A && git commit -m "feat(#311): dev worker service, script hooks and docs"
```

---

### Task 10: Full verification sweep

- [ ] **Step 1: Backend gates** — `cd backend && composer cs && composer stan && composer md && php bin/phpunit` → all green.
- [ ] **Step 2: MySQL leg** — `docker compose exec php vendor/bin/phpunit` (the rate-limiter flake documented in memory is order-dependent and pre-existing; rerun the failing file in isolation before treating it as ours) and `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate`.
- [ ] **Step 3: Mutation gate** — `composer infection:diff`; kill escaped mutants by tightening tests, never by lowering `minMsi`.
- [ ] **Step 4: Frontend gate** — `cd frontend && npm run check`.
- [ ] **Step 5: Worker soak** — leave the dev stack's worker running ≥ 10 minutes; `docker compose logs worker | tail -50` shows periodic refresh sweeps and no error spam; `tail -200 backend/var/log/dev.log` for deprecations/swallowed errors (both containers write here — interleaving is expected).
- [ ] **Step 6: Commit anything the sweep shook loose**

```bash
git add -A && git commit -m "test(#311): verification sweep fixes"
```

---

## Self-Review Notes (already applied)

- **Spec coverage (#311 issue):** same image/second service (T8/T9), Messenger+Scheduler on Doctrine with no broker (T1), sweep-not-dispatch (T4/T5 — web requests dispatch nothing), heartbeat + zero-config arbitration with automatic two-way fallback (T2/T3), refresh sweep every 5 min via `allDue` (T6), housekeeping purge (T6), regime field + UI copy switch + polling-only transport (T3/T7), degradation documented (T9). Out of scope honored: no token streaming, no Redis/RabbitMQ, no jobs beyond the three entries, no scheduled recommendation runs.
- **Recon hazards addressed:** entrypoint `exec php-fpm` override (T8 dual-mode), shared `php-var` `.ready`/cache destruction (T8 — console mode touches neither), worker-before-migrations on first install (T8 ready-wait + T9 post-migrate restart + T5's per-run exception isolation), env-block duplication (T8 anchor), first `restart:` policy called out as deliberate (T8/T9), hardcoded service-name log hints (T9).
- **Type consistency:** `RecommendationRunReport::inBackground()` and the `background` array/TS key match across T3/T7; `WorkerPresence::RECOMMENDATION_SWEEP` used by T2/T3/T5; `findAllActive()` defined T5, consumed T5 only; message class names identical in T4's schedule and T5/T6 handlers; consumer name `scheduler_worker` matches `#[AsSchedule('worker')]` in T4/T8/T9.
