# Single Maintenance Tick Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `POST /maintenance/tick`, one token-guarded endpoint that runs the feed refresh and the recommendation sweep in a single call, so a worker-less install drives everything from one cron line.

**Architecture:** A thin controller action delegates to a new `MaintenanceTick` service. The service runs both existing pieces in order — `RefreshRunner` first (its work commits), then `ForYouSweep` — and merges their reports into a `MaintenanceTickReport`. No extra guard: both halves already report their own failures as status rather than throwing. The two granular endpoints stay unchanged.

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit (WebTestCase for the route, DbTestCase container integration for the service, plain unit test for the DTO).

Spec: [docs/superpowers/specs/2026-08-10-maintenance-tick-endpoint-design.md](../specs/2026-08-10-maintenance-tick-endpoint-design.md). Issue #346.

## Global Constraints

- `declare(strict_types=1);` in every PHP file.
- Clean Code house style: `final readonly class` with constructor promotion, guard clauses, names reveal intent, no boolean flag parameters.
- Controllers stay thin (`ThinControllerRule`): the action checks the token, calls one service, returns a response. No orchestration in the controller.
- Every touched `src` file must be PHPMD-clean (`composer md`), PSR-12 clean (`composer cs`), and PHPStan level-max clean (`composer stan`) before commit.
- The maintenance token in the test environment is `test-maintenance-token` (from `.env.test`). The header is `X-Maintenance-Token`; the query fallback is `?token=`.
- Refresh budget stays 20 seconds, matching the existing `/maintenance/refresh` action.

---

### Task 1: `MaintenanceTickReport` DTO

Holds the two half-reports as already-serialised arrays and exposes them under stable keys.

**Files:**
- Create: `backend/src/Service/Maintenance/MaintenanceTickReport.php`
- Test: `backend/tests/Service/Maintenance/MaintenanceTickReportTest.php`

**Interfaces:**
- Produces: `MaintenanceTickReport::__construct(array $refresh, array $recommendations)` where each is `array<string,mixed>`; `MaintenanceTickReport::toArray(): array{refresh: array<string,mixed>, recommendations: array<string,mixed>}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Service\Maintenance\MaintenanceTickReport;
use PHPUnit\Framework\TestCase;

final class MaintenanceTickReportTest extends TestCase
{
    public function testExposesBothHalvesUnderStableKeys(): void
    {
        $report = new MaintenanceTickReport(
            ['status' => 'completed', 'remaining' => 0],
            ['startedRuns' => 1, 'advancedRuns' => 2, 'activeRuns' => 3],
        );

        self::assertSame(
            [
                'refresh' => ['status' => 'completed', 'remaining' => 0],
                'recommendations' => ['startedRuns' => 1, 'advancedRuns' => 2, 'activeRuns' => 3],
            ],
            $report->toArray(),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Maintenance/MaintenanceTickReportTest.php`
Expected: FAIL — class `MaintenanceTickReport` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

/**
 * The outcome of one maintenance tick (#346): the feed-refresh report and the
 * For You sweep report, each already serialised, merged under stable keys. A
 * half that failed carries `['error' => <message>]` in its place; see
 * MaintenanceTick.
 */
final readonly class MaintenanceTickReport
{
    /**
     * @param array<string,mixed> $refresh
     * @param array<string,mixed> $recommendations
     */
    public function __construct(
        public array $refresh,
        public array $recommendations,
    ) {
    }

    /**
     * @return array{refresh: array<string,mixed>, recommendations: array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'refresh' => $this->refresh,
            'recommendations' => $this->recommendations,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Maintenance/MaintenanceTickReportTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Maintenance/MaintenanceTickReport.php backend/tests/Service/Maintenance/MaintenanceTickReportTest.php
git commit -m "feat(#346): MaintenanceTickReport merges both halves under stable keys"
```

---

### Task 2: `MaintenanceTick` service

Runs both halves in order and returns a merged `MaintenanceTickReport`. No extra try/catch: both halves are near-non-throwing (refresh returns `status: "aborted"` on a database error; the sweep catches per-run failures internally), and refresh runs first so its work commits before the sweep. A genuinely unexpected exception is left to bubble to Symfony's 500 handler.

**Files:**
- Create: `backend/src/Service/Maintenance/MaintenanceTick.php`
- Test: `backend/tests/Service/Maintenance/MaintenanceTickTest.php`

**Interfaces:**
- Consumes: `RefreshRunner::run(RefreshRequest): RefreshReport` with `RefreshRequest::allDue(int $budgetSeconds): RefreshRequest` and `RefreshReport::toArray(): array`; `ForYouSweep::sweepOnce(): ForYouSweepReport` with `ForYouSweepReport::toArray(): array`.
- Produces: `MaintenanceTick::run(): MaintenanceTickReport`.

- [ ] **Step 1: Write the failing test**

The service depends on the concrete `final` `RefreshRunner` and `ForYouSweep`. This suite does NOT mock final classes (no `dg/bypass-finals`); it tests through the real container, exactly as `ForYouSweepTest` does (it extends `DbTestCase` and pulls the service with `self::getContainer()->get(...)`). Follow that pattern. With no feeds due and no active runs in a fresh test database, `run()` still produces a well-formed report — that is what this test asserts.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Service\Maintenance\MaintenanceTick;
use App\Tests\DbTestCase;

final class MaintenanceTickTest extends DbTestCase
{
    public function testRunProducesAReportCarryingBothHalves(): void
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);

        $report = $tick->run()->toArray();

        // The refresh half always carries a status; the recommendations half
        // always carries the three sweep counts. Exact values are not asserted:
        // the shared test database may hold rows from other classes, so this
        // proves the shape, not a fixed count.
        self::assertArrayHasKey('status', $report['refresh']);
        self::assertIsInt($report['recommendations']['startedRuns']);
        self::assertIsInt($report['recommendations']['advancedRuns']);
        self::assertIsInt($report['recommendations']['activeRuns']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Maintenance/MaintenanceTickTest.php`
Expected: FAIL — class `MaintenanceTick` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Recommendation\ForYouSweep;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;

/**
 * One maintenance tick (#346): refresh all due feeds, then start due
 * recommendation runs and advance each active run one step. Refresh runs first
 * so its work commits before the sweep, and both halves are near-non-throwing
 * on their own -- refresh returns `status: "aborted"` on a database error, and
 * the sweep catches per-run failures internally -- so this class needs no guard
 * of its own: each half's status lives in its own report. This is the
 * worker-less install's single cron entry point; the granular /maintenance
 * routes stay for a caller that wants one job only.
 */
final readonly class MaintenanceTick
{
    private const int REFRESH_BUDGET_SECONDS = 20;

    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        $recommendations = $this->forYouSweep->sweepOnce();

        return new MaintenanceTickReport($refresh->toArray(), $recommendations->toArray());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Maintenance/MaintenanceTickTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Maintenance/MaintenanceTick.php backend/tests/Service/Maintenance/MaintenanceTickTest.php
git commit -m "feat(#346): MaintenanceTick runs refresh then recommendations, merged"
```

---

### Task 3: `/maintenance/tick` route

A new thin action on `MaintenanceController` that guards the token and returns the tick report.

**Files:**
- Modify: `backend/src/Controller/MaintenanceController.php`
- Test: `backend/tests/Controller/MaintenanceControllerTest.php`

**Interfaces:**
- Consumes: `MaintenanceTick::run(): MaintenanceTickReport` and `MaintenanceTickReport::toArray()`.

- [ ] **Step 1: Write the failing tests**

Add these methods to `MaintenanceControllerTest`:

```php
    public function testTickRejectsMissingToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/tick');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            ['error' => 'forbidden'],
            json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testTickGetMethodIsNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/maintenance/tick?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(405);
    }

    public function testTickReturnsBothHalvesWithValidToken(): void
    {
        $client = self::createClient();
        $feed = $this->feedFor($client, 'https://maint.example.com/feed');

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));
        $stub->willReturn(
            'https://maint.example.com',
            FetchResponse::fetched('https://maint.example.com', false, '<html lang="en"></html>', null, null),
        );
        self::getContainer()->set(BatchFeedFetcherInterface::class, $stub);

        $client->request('POST', '/maintenance/tick', server: [
            'HTTP_X_MAINTENANCE_TOKEN' => 'test-maintenance-token',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('refresh', $payload);
        self::assertArrayHasKey('recommendations', $payload);
        /** @var array<string, mixed> $refresh */
        $refresh = $payload['refresh'];
        self::assertSame('completed', $refresh['status']);
        /** @var array<string, mixed> $recommendations */
        $recommendations = $payload['recommendations'];
        self::assertIsInt($recommendations['activeRuns']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Controller/MaintenanceControllerTest.php --filter Tick`
Expected: FAIL — `/maintenance/tick` returns 404 (route absent), so the success and 405 assertions fail.

- [ ] **Step 3: Add the dependency and the action**

In `MaintenanceController`, add the `MaintenanceTick` import and constructor dependency, then the action. The constructor becomes:

```php
    public function __construct(
        private MaintenanceTokenGuard $tokenGuard,
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private MaintenanceTick $maintenanceTick,
    ) {
    }
```

Add the import near the other `use` lines:

```php
use App\Service\Maintenance\MaintenanceTick;
```

Add the action after `sweepRecommendations`:

```php
    /**
     * One call that runs both maintenance halves — refresh all due feeds, then
     * start due recommendation runs and advance each active run one step — so a
     * worker-less install drives everything from a single cron line (#346). It
     * always answers 200 when the tick ran; each half carries its own status in
     * the body, and a failed half carries an error marker. The granular
     * /maintenance/refresh keeps its 409/500 status mapping for a caller that
     * pings refresh alone.
     */
    #[Route('/maintenance/tick', name: 'maintenance_tick', methods: ['POST'])]
    public function tick(Request $request): JsonResponse
    {
        if (!$this->tokenGuard->isAuthorized($request)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->maintenanceTick->run()->toArray());
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Controller/MaintenanceControllerTest.php`
Expected: PASS (all methods, old and new).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/MaintenanceController.php backend/tests/Controller/MaintenanceControllerTest.php
git commit -m "feat(#346): POST /maintenance/tick runs refresh and recommendations in one call"
```

---

### Task 4: Docs and full quality gates

Document the single cron line and prove the whole change is gate-clean.

**Files:**
- Modify: `docs/for-you-scheduling.md`

- [ ] **Step 1: Update the scheduling doc**

In `docs/for-you-scheduling.md`, under "Without a worker (external cron)", add a paragraph and example after the existing sweep example. Keep the existing granular section; add:

```markdown
## One call for everything

To drive both jobs from a single cron line, ping the combined endpoint instead
of the two separate ones:

    POST /maintenance/tick
    Header: X-Maintenance-Token: <MAINTENANCE_TOKEN>

It refreshes all due feeds, then starts due recommendation runs and advances
each active run one step, and returns both reports:

    { "refresh": { "status": "completed", ... },
      "recommendations": { "startedRuns": n, "advancedRuns": m, "activeRuns": k } }

It always answers `200` when the tick ran; read each half's own status in the
body. The granular `/maintenance/refresh` and `/maintenance/recommendations/sweep`
routes stay available for a caller that wants one job only.

Example cron line (every minute for the sweep cadence; the refresh half only
touches feeds that are already due):

    * * * * * curl -fsS -X POST "https://YOUR_HOST/maintenance/tick" -H "X-Maintenance-Token: $MAINTENANCE_TOKEN"
```

- [ ] **Step 2: Run the backend quality gates**

Run each and confirm clean:

```bash
cd backend && composer cs && composer stan && composer md
```

Expected: no findings. If PHPStan complains about a cold cache, run `php bin/console cache:warmup` first. Fix any finding in the touched files before continuing (do not add a baseline or a suppression).

- [ ] **Step 3: Run the PhpStorm inspections on the changed PHP**

Use `mcp__phpstorm__lint_files` on the three PHP files (`MaintenanceTickReport.php`, `MaintenanceTick.php`, `MaintenanceController.php`) plus the two test files. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 4: Run the full backend suite**

Run: `cd backend && php bin/phpunit`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add docs/for-you-scheduling.md
git commit -m "docs(#346): document the single /maintenance/tick cron line"
```

---

## Self-Review

**Spec coverage:**
- New `POST /maintenance/tick`, token-guarded → Task 3.
- Runs both halves in one call → Task 2 (`MaintenanceTick`).
- Order refresh-then-recommendations → Task 2 (`run()` order: refresh's work commits before the sweep).
- Isolation without a guard → Task 2: both halves are near-non-throwing, so a refresh failure surfaces as `status: "aborted"` in its own report and the sweep still runs.
- Combined report shape `{refresh, recommendations}` → Task 1 + Task 3 test.
- Always 200 when the tick ran; Symfony 500 only on an unexpected exception → Task 3 action returns 200 with the merged report; the granular route keeps its 409/500 mapping.
- Granular routes unchanged → Task 3 only adds a method; existing tests still run in Task 3 Step 4.
- 20-second refresh budget kept → Task 2 `REFRESH_BUDGET_SECONDS`.
- Docs updated → Task 4.
- Tests: DTO unit test, service integration test, route functional test → Tasks 1–3.

**Placeholder scan:** No TBD/TODO; every code step carries full code.

**Type consistency:** `MaintenanceTick::run(): MaintenanceTickReport`; `MaintenanceTickReport::__construct(array, array)` and `toArray()` used identically in Tasks 1–3. `RefreshRequest::allDue(int)`, `RefreshReport::toArray()`, and `ForYouSweep::sweepOnce(): ForYouSweepReport` match the real signatures read from source.

**Test doubles:** none. The suite does not mock `final` classes (no `dg/bypass-finals`); `MaintenanceTickTest` extends `DbTestCase` and pulls the real service from the container, the same pattern `ForYouSweepTest` uses. The DTO test (Task 1) needs no container.
