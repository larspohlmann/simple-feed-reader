# Link-Card Drain Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A cron-facing endpoint that drains the link-card queue for one time window, so an install without the worker keeps up when more cards are queued than the tick's single pass fetches.

**Architecture:** A new service `LinkCardDrain` runs the existing `LinkCardSweep` pass after pass until a pass cards nothing or the window ends, then reports what it carded and what is still pending. `MaintenanceController` gets a third token-guarded action that calls it. Nothing about the tick, the worker or the queue itself changes; the sweep's row claims make the drain safe next to both.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM, PHPUnit 12.

**Spec:** GitHub issue [#2354](https://github.com/larspohlmann/simple-feed-reader/issues/2354). Measurements behind it: 84 cards per hour through the tick (7 per pass, 5-minute cron) against about 170 queued per hour since 2026-11-18.

## Global Constraints

- Work in the worktree `.claude/worktrees/2354-link-card-drain` on `feature/2354-link-card-drain-endpoint`. Never switch the main checkout.
- Commit messages: `type(#2354): summary`, no attribution lines.
- `CLAUDE.md` PHP rules apply in full: `final readonly` services, no comment unless a reader would get the code wrong without it, queries live in `src/Repository/`, the controller only delegates, `Response::HTTP_*` constants in tests.
- No new environment variable: the window is the existing `%maintenance_tick_seconds%` (`MAINTENANCE_TICK_SECONDS`, 150 on Strato, 25 by default).
- No change to `LinkCardSweep`, `ShareLinkCards`, `LinkCardsTickSweep` or the worker handler.
- Every `src` file touched is PHPMD-clean; no PHPStan baseline, ignore or rule change.

## Review Focus

1. A page that fails or times out: the row is still marked done (one attempt each), so the drain moves on instead of fetching the same row again. Test in Task 2.
2. A window shorter than one page fetch (8 s): the drain fetches nothing and still answers with the pending count. Test in Task 2.
3. A window that ends mid-queue: no fetch starts that cannot end before the deadline; the rest stays pending for the next call. Test in Task 2.
4. A row another worker holds (the tick's pass running at the same time): not fetched twice, not spun on, counted as pending. Test in Task 2.
5. A call without the maintenance token, or with `GET`: refused like the other maintenance actions, before any fetch. Tests in Task 3.

## File Structure

| File | Responsibility |
|---|---|
| `backend/src/Repository/ShareEnrichmentRepository.php` (modify) | Counts the link-card rows still to be attempted |
| `backend/src/Service/Content/LinkCard/Model/LinkCardDrainReportModel.php` (create) | What one drain carded and what is left |
| `backend/src/Service/Content/LinkCard/LinkCardDrain.php` (create) | Runs sweep passes until the queue or the window ends |
| `backend/config/services.yaml` (modify) | Wires the window onto `LinkCardDrain` |
| `backend/src/Http/LinkCardDrainJson.php` (create) | Response shape of the drain report |
| `backend/src/Controller/MaintenanceController.php` (modify) | `POST /maintenance/link-cards/drain` |
| `deploy/strato/README.md` (modify) | The second cron line |

---

### Task 1: Count the pending link-card rows

**Files:**
- Modify: `backend/src/Repository/ShareEnrichmentRepository.php`
- Test: `backend/tests/Repository/ShareEnrichmentRepositoryTest.php`

**Interfaces:**
- Produces: `ShareEnrichmentRepository::countPendingLinkCards(): int` — rows of kind `ShareEnrichmentKind::LinkCards` with `doneAt IS NULL`, whether a worker holds them or not.

- [ ] **Step 1: Write the failing test**

Add `testCountsTheLinkCardRowsStillToBeAttempted` to `ShareEnrichmentRepositoryTest`. Persist, with the helpers the class already uses: two link-card rows still pending, one of them claimed just now through `claim()`; one link-card row marked done through `markDone()`; one pending row of kind `ShareEnrichmentKind::AppView`.

```php
self::assertSame(2, $this->enrichments()->countPendingLinkCards());
```

(Use whatever accessor the class already has for the repository in place of `$this->enrichments()`.)

- [ ] **Step 2: Run it to see it fail**

Run from `backend/`: `php bin/phpunit tests/Repository/ShareEnrichmentRepositoryTest.php --filter testCountsTheLinkCardRowsStillToBeAttempted`
Expected: FAIL, `Call to undefined method …::countPendingLinkCards()`.

- [ ] **Step 3: Implement `countPendingLinkCards(): int`**

A `COUNT(pending.id)` QueryBuilder query on the repository's own alias `pending`, filtered by kind and `doneAt IS NULL`. It does not go through `claimable()`: held rows count.

- [ ] **Step 4: Run the test class**

Run: `php bin/phpunit tests/Repository/ShareEnrichmentRepositoryTest.php`
Expected: PASS, all tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/ShareEnrichmentRepository.php backend/tests/Repository/ShareEnrichmentRepositoryTest.php
git commit -m "feat(#2354): count the link-card rows still to be attempted"
```

---

### Task 2: The drain service

**Files:**
- Create: `backend/src/Service/Content/LinkCard/Model/LinkCardDrainReportModel.php`
- Create: `backend/src/Service/Content/LinkCard/LinkCardDrain.php`
- Modify: `backend/config/services.yaml` (next to the `MaintenanceTick` block that sets `$tickWindowSeconds`)
- Test: `backend/tests/Service/Content/LinkCard/LinkCardDrainTest.php`

**Interfaces:**
- Consumes: `ShareEnrichmentRepository::countPendingLinkCards(): int` (Task 1); `LinkCardSweep::sweep(LinkCardBudgetModel): LinkCardSweepReportModel`; `LinkCardBudgetModel::fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches)`; `LinkCardBudgetModel::largestPass(): int` (7).
- Produces:
  - `final readonly class LinkCardDrainReportModel { public function __construct(public int $completed, public int $pending) }`
  - `final readonly class LinkCardDrain` with constructor `(LinkCardSweep $sweep, ShareEnrichmentRepository $enrichments, Psr\Clock\ClockInterface $clock, int $windowSeconds)` and `public function drain(): LinkCardDrainReportModel`.

- [ ] **Step 1: Write the failing tests**

`LinkCardDrainTest extends DbTestCase`, set up like `LinkCardSweepTest`: `MockClock('2026-10-11 12:00:00', 'UTC')`, a `MockHttpClient`, the sweep from `LinkCardSweepWiring::build(...)`, posts queued through `QueuedLinkPosts::persist(...)` (copy `LinkCardSweepTest::queuedPost()`; a third copy would be the refactor point, this is the second). Build the drain as `new LinkCardDrain($sweep, PostEnrichers::enrichments($this->entityManager), $this->clock, $windowSeconds)`.

```php
public function testCardsTheWholeQueueWhenItHoldsMoreThanOnePass(): void
{
    // 9 queued posts, ShareLinkCardsWiring::titledPages(), window 150
    self::assertEquals(new LinkCardDrainReportModel(9, 0), $drain->drain());
    self::assertSame(9, $this->client->getRequestsCount());
}

public function testAnEmptyQueueFetchesNothing(): void
{
    // nothing queued, window 150
    self::assertEquals(new LinkCardDrainReportModel(0, 0), $drain->drain());
    self::assertSame(0, $this->client->getRequestsCount());
}

public function testAWindowShorterThanOneFetchLeavesTheQueuePending(): void
{
    // 1 queued post, window 7 (PageHeadFetcher::MAX_DURATION_SECONDS is 8.0)
    self::assertEquals(new LinkCardDrainReportModel(0, 1), $drain->drain());
    self::assertSame(0, $this->client->getRequestsCount());
}

public function testStartsNoFetchThatCannotEndBeforeTheWindowDoes(): void
{
    // 9 queued posts, window 20, a MockHttpClient whose callback calls $this->clock->sleep(8)
    // before answering the titled page: two fetches fit, the third would end at 24 s
    self::assertEquals(new LinkCardDrainReportModel(2, 7), $drain->drain());
    self::assertSame(2, $this->client->getRequestsCount());
}

public function testAPageThatFailsIsNotFetchedAgain(): void
{
    // 8 queued posts, window 150, a MockHttpClient answering every page with HTTP 500
    self::assertEquals(new LinkCardDrainReportModel(8, 0), $drain->drain());
    self::assertSame(8, $this->client->getRequestsCount());
}

public function testARowAnotherWorkerHoldsStaysPendingUnfetched(): void
{
    // 1 queued post whose row was claimed at $this->clock->now() through ShareEnrichmentRepository::claim()
    self::assertEquals(new LinkCardDrainReportModel(0, 1), $drain->drain());
    self::assertSame(0, $this->client->getRequestsCount());
}
```

If `testAPageThatFailsIsNotFetchedAgain` shows more than 8 requests because a failed page is retried inside one attempt, assert on the report and on `countPendingLinkCards() === 0` instead of the request count, and say so in the task report.

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Content/LinkCard/LinkCardDrainTest.php`
Expected: FAIL, class `LinkCardDrain` not found.

- [ ] **Step 3: Implement the model and the service**

`drain()` fixes its deadline once, as `MaintenanceTick::run()` does (`$this->clock->now()->modify('+N seconds')`), then loops:

```php
do {
    $pass = $this->sweep->sweep(LinkCardBudgetModel::fittingBefore(
        $deadline,
        $this->clock->now(),
        LinkCardBudgetModel::largestPass(),
    ));
    $completed += $pass->completed;
} while ($pass->completed > 0);
```

A pass that cards nothing ends the drain, whatever the reason: empty queue, spent window, or rows other workers hold. The report's `pending` is `countPendingLinkCards()` read after the loop. Extract the loop into a private method if `drain()` stops reading as one sentence.

Wire the window in `backend/config/services.yaml`:

```yaml
    App\Service\Content\LinkCard\LinkCardDrain:
        arguments:
            $windowSeconds: '%maintenance_tick_seconds%'
```

- [ ] **Step 4: Run the tests and the role rule**

Run: `php bin/phpunit tests/Service/Content/LinkCard/LinkCardDrainTest.php`
Expected: PASS, 6 tests.
Run: `bin/console cache:warmup && composer stan`
Expected: no errors (`ServiceRoleRule` accepts a service in the module root and a model in `Model/`).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Content/LinkCard/LinkCardDrain.php backend/src/Service/Content/LinkCard/Model/LinkCardDrainReportModel.php backend/config/services.yaml backend/tests/Service/Content/LinkCard/LinkCardDrainTest.php
git commit -m "feat(#2354): drain the link-card queue pass after pass within one window"
```

---

### Task 3: The maintenance endpoint

**Files:**
- Create: `backend/src/Http/LinkCardDrainJson.php`
- Modify: `backend/src/Controller/MaintenanceController.php`
- Modify: `deploy/strato/README.md` (the "Notes" bullet that describes the external cron)
- Test: `backend/tests/Http/LinkCardDrainJsonTest.php`
- Test: `backend/tests/Controller/MaintenanceControllerTest.php`

**Interfaces:**
- Consumes: `LinkCardDrain::drain(): LinkCardDrainReportModel` (Task 2).
- Produces:
  - `LinkCardDrainJson::report(LinkCardDrainReportModel $report): array` returning `array{completed: int, pending: int}`; a static mapper shaped like `App\Http\ForYouSweepReportJson`.
  - Route `maintenance_link_cards_drain`: `POST /maintenance/link-cards/drain`, action `MaintenanceController::drainLinkCards(Request $request): JsonResponse`.

- [ ] **Step 1: Write the failing tests**

`LinkCardDrainJsonTest` (plain `TestCase`, like `ForYouSweepReportJsonTest`):

```php
self::assertSame(
    ['completed' => 12, 'pending' => 340],
    LinkCardDrainJson::report(new LinkCardDrainReportModel(12, 340)),
);
```

In `MaintenanceControllerTest`, mirroring the three recommendation-sweep tests:

```php
public function testLinkCardDrainRejectsMissingToken(): void
{
    // POST /maintenance/link-cards/drain without a token
    self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    self::assertSame(['error' => 'forbidden'], /* decoded body */);
}

public function testLinkCardDrainGetMethodIsNotAllowed(): void
{
    // GET /maintenance/link-cards/drain?token=test-maintenance-token
    self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
}

public function testLinkCardDrainRunsWithValidToken(): void
{
    // POST with HTTP_X_MAINTENANCE_TOKEN => 'test-maintenance-token'; the test queues nothing
    self::assertResponseIsSuccessful();
    self::assertResponseHeaderSame('Content-Type', 'application/json');
    self::assertSame(['completed', 'pending'], array_keys($payload));
    self::assertIsInt($payload['completed']);
    self::assertIsInt($payload['pending']);
}
```

Shape, not counts, for the same reason `testRecommendationSweepRunsWithValidToken` gives: the shared test database may hold other classes' rows.

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Http/LinkCardDrainJsonTest.php tests/Controller/MaintenanceControllerTest.php --filter 'LinkCardDrain'`
Expected: FAIL, class `LinkCardDrainJson` not found and 404 on the route.

- [ ] **Step 3: Implement the mapper and the action**

The action is the token guard followed by `new JsonResponse(LinkCardDrainJson::report($this->linkCardDrain->drain()))`, the shape of `sweepRecommendations()`. Its docblock is one line saying what the tick does not do: the tick fetches one pass of link cards, this spends a whole window on them.

- [ ] **Step 4: Document the cron line**

In `deploy/strato/README.md`, extend the "Notes" bullet on the external cron: an install that queues more link cards than the tick's one pass fetches (about 84 per hour at a 5-minute cron) adds a second cron line calling `POST /maintenance/link-cards/drain` with the same header, offset from the tick, with a `curl --max-time` above `MAINTENANCE_TICK_SECONDS`; the answer's `pending` shows whether the queue is shrinking.

- [ ] **Step 5: Run the tests and the gates**

Run from `backend/`:
- `php bin/phpunit tests/Http/LinkCardDrainJsonTest.php tests/Controller/MaintenanceControllerTest.php` — PASS
- `composer check` — clean
- `composer md` — clean for every touched `src` file
- `php bin/phpunit` — PASS (SQLite leg)

- [ ] **Step 6: Commit**

```bash
git add backend/src/Http/LinkCardDrainJson.php backend/src/Controller/MaintenanceController.php backend/tests/Http/LinkCardDrainJsonTest.php backend/tests/Controller/MaintenanceControllerTest.php deploy/strato/README.md
git commit -m "feat(#2354): POST /maintenance/link-cards/drain spends one window on the link-card queue"
```

---

### Task 4: Branch gates and pull request

- [ ] **Step 1:** `composer infection:diff` from `backend/`; kill or justify every escaped mutant on the changed lines.
- [ ] **Step 2:** Scan today's dev log for deprecations and swallowed errors (`ls -t backend/var/log/dev-*.log | head -1`).
- [ ] **Step 3:** Run `/simplify` on the branch, apply its fixes, re-run `composer check`, `composer md` and `php bin/phpunit`.
- [ ] **Step 4:** MySQL leg. It needs the Docker stack, which serves the main checkout: run `docker compose exec php composer test:parallel` only once the main checkout is free and holds this branch. If it is not, say so in the PR body and leave the leg to CI ("Backend mysql").
- [ ] **Step 5:** Push and open the PR against `develop` with `Closes #2354`; watch `gh pr checks` in the background. Merging waits for Lars.

---

### After the merge (each step needs Lars's go)

These are operations, not code, and none happens without an explicit yes:

1. **Deploy** a `v1.9.5-dev.N` tag to Strato.
2. **Smoke** the endpoint once by hand from ganesh.local (`curl --max-time 200 -X POST -H "X-Maintenance-Token: …" https://lars-pohlmann.de/reader/maintenance/link-cards/drain`) and read `completed` and `pending`.
3. **Cron on ganesh.local:** `~/.local/bin/sfr-link-card-drain.sh`, a copy of `sfr-maintenance-tick.sh` with the URL changed and its own log file, and the crontab line `4-59/5 * * * * /Users/lars/.local/bin/sfr-link-card-drain.sh` (two minutes after the tick's `2-59/5`, never on `:00`). Back the crontab up first.
4. **Verify** after an hour with a read-only query: open `link_cards` rows and the age of the oldest should both have fallen.
