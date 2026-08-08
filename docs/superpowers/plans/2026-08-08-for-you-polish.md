# For-you Polish (#321) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish the for-you recommendation feature: run metadata in the reader chrome (timestamp, count), a real run button below the title, mobile-friendly progress/info, debug score display, a diagnosable debug log, an expert-settings regrouping with a purge button, and lighter run defaults (50 of 500, ~13 calls).

**Architecture:** Backend first: the run report grows a `forYou` summary (item count + generated-at), `recommendation_item` gains a `score` column, a purge endpoint deletes finished runs, the debug log gains timing/error columns, and settings gain `batchCount` plus new defaults. Frontend second: the for-you bar moves into the top of the list scroller via a `TemplateRef` input (scrolls away naturally — decision 1A), the hint moves behind an info icon that opens an overlay panel (2A), scores render as pills (3A), the toast goes borderless (4B), the debug log becomes a summary strip + aligned grid (5A), and the settings card folds tuning fields into a shared disclosure component (6A).

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (MySQL + SQLite), Angular 20 standalone + signals, Transloco, Jest, PHPUnit.

**Design decisions (user-approved, 2026-08-08):** 1A · 2A · 3A · 4B · 5A · 6A. Mockups: claude.ai artifact "For-you polish — UI options (#321)".

## Global Constraints

- Branch: `feature/321-for-you-polish` off `develop`. Check `git status` before branching — concurrent sessions share this checkout.
- Every PHP file: `declare(strict_types=1)`; PSR-12 (`composer cs:fix`); PHPStan level max (warm cache: `bin/console cache:warmup`); **every touched src file PHPMD-clean** (`composer md`), not merely free of new findings.
- Controllers stay thin (`ThinControllerRule`): no private methods that carry responsibility; response assembly goes to `src/Http/*Json.php`.
- House style: `final readonly class` + constructor promotion; errors are typed exceptions in `Service/*/Exception/`; no boolean flag parameters.
- Datetimes are naive UTC — normalise before persisting; the Kernel pins UTC.
- New endpoints follow the native-iOS checklist (docs/architecture.md §6): bearer, stateless, JSON in, `application/problem+json` out.
- Frontend: standalone components + signals; component styles in sibling `.scss` (never inline); no hex colours / no raw `px` spacing outside `src/app/theme/`; Prettier 100-col; run `npm run check` from `frontend/`.
- Every new UI string gets a key in **both** `frontend/public/i18n/en.json` and `de.json`.
- Migrations: entity attributes first, then `docker compose exec php bin/console doctrine:migrations:diff`; the suite never executes migrations, so verify with the from-empty migrate + `doctrine:schema:validate` on **both** SQLite and MySQL before the PR.
- Tests: `php bin/phpunit` (SQLite) **and** `docker compose exec php vendor/bin/phpunit` (MySQL) before the PR. Mutation gate: `composer infection:diff`. After a bulk DQL DELETE, `$em->clear()` before asserting rows are gone.
- Commit small, one commit per green step, message prefix `feat(#321):` / `refactor(#321):` / `fix(#321):`.

---

### Task 0: Branch

- [ ] **Step 1:** `git status` — expect a clean tree (only this plan file may be new). If another session left edits, stop and ask.
- [ ] **Step 2:**

```bash
git checkout -b feature/321-for-you-polish develop
git add docs/superpowers/plans/2026-08-08-for-you-polish.md
git commit -m "docs(#321): add the for-you polish implementation plan"
```

---

### Task 1: Run report carries a for-you summary (item count + generated-at)

**Files:**
- Modify: `backend/src/Repository/RecommendationItemRepository.php`
- Modify: `backend/src/Repository/RecommendationRunRepository.php`
- Create: `backend/src/Service/Recommendation/RecommendationForYouSummary.php`
- Create: `backend/src/Service/Recommendation/RecommendationForYouSummaryProvider.php`
- Create: `backend/src/Http/RecommendationRunStatusJson.php`
- Modify: `backend/src/Controller/Api/RecommendationRunController.php`
- Test: `backend/tests/Service/Recommendation/RecommendationForYouSummaryProviderTest.php`, `backend/tests/Controller/Api/RecommendationRunControllerTest.php` (extend the existing controller test)

**Interfaces:**
- Consumes: `RecommendationItemRepository::listForYou` semantics (completed runs, deduped across runs, subscribed feeds only), `RecommendationRunRepository::findLatestForUser`.
- Produces: `RecommendationItemRepository::countForYou(int $userId): int`; `RecommendationRunRepository::newestCompletedAt(User $user): ?\DateTimeImmutable`; `RecommendationForYouSummaryProvider::forUser(User $user): RecommendationForYouSummary`; `RecommendationRunStatusJson::report(RecommendationRunReport $report, RecommendationForYouSummary $summary): array` — the wire shape every `/api/recommendations/runs*` action now returns: the report's fields plus `'forYou' => ['itemCount' => int, 'generatedAt' => ?string]`.

Why a separate summary instead of fields on the report: the report describes the **latest run** (which may be a failed one), while the header timestamp and the sidebar count describe the **surviving list** — the newest *completed* run and the deduped item count. Two different sources; keep them apart.

- [ ] **Step 1: Write the failing tests.** In `RecommendationForYouSummaryProviderTest` (integration test, mirror the fixture style of the existing `backend/tests/Repository/RecommendationItemRepositoryTest.php`): create a user with two completed runs recommending an overlapping entry and one failed run afterwards; assert `forUser()` returns the deduped count (not the raw item count) and the completedAt of the newest **completed** run (not the failed run's). Assert a user with no runs gets `itemCount === 0` and `generatedAt === null`. In the controller test: `GET /api/recommendations/runs/current` response contains `forYou.itemCount` and `forYou.generatedAt`.
- [ ] **Step 2: Run them** — expect failures on the missing classes/methods.
- [ ] **Step 3: Implement.**

`countForYou` reuses the exact WHERE set of `rowQueryBuilder` — extract the shared criteria so the two cannot drift:

```php
// RecommendationItemRepository
public function countForYou(int $userId): int
{
    $count = $this->applyForYouCriteria($this->createQueryBuilder('i')->select('COUNT(i.id)'), $userId)
        ->getQuery()
        ->getSingleScalarResult();

    return (int) $count;
}

/** The for-you feed's row set: completed runs of this user, entries still
 *  subscribed, deduped to their newest run. Shared by the pager and the count
 *  so the sidebar number can never disagree with the list. */
private function applyForYouCriteria(QueryBuilder $qb, int $userId): QueryBuilder
{
    return $qb
        ->join('i.run', 'r')
        ->join('i.entry', 'e')
        ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
        ->andWhere('r.user = :user')
        ->andWhere('r.status = :completed')
        ->andWhere($this->notDedupedByNewerRunDql())
        ->setParameter('user', $userId)
        ->setParameter('completed', RecommendationRun::STATUS_COMPLETED);
}
```

Refactor `rowQueryBuilder` to call `applyForYouCriteria` and keep only its extra selects/joins (`addSelect('e')`, the `leftJoin` on feed and entry state, the scalar selects). Run the existing repository tests to prove the refactor is behaviour-neutral.

```php
// RecommendationRunRepository — next to findLatestForUser, same style
public function newestCompletedAt(User $user): ?\DateTimeImmutable
{
    /** @var RecommendationRun|null $run */
    $run = $this->createQueryBuilder('r')
        ->where('r.user = :user')
        ->andWhere('r.status = :completed')
        ->setParameter('user', $user)
        ->setParameter('completed', RecommendationRun::STATUS_COMPLETED)
        ->orderBy('r.id', 'DESC')
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();

    return $run?->getCompletedAt();
}
```

```php
// Service/Recommendation/RecommendationForYouSummary.php
final readonly class RecommendationForYouSummary
{
    public function __construct(
        public int $itemCount,
        public ?\DateTimeImmutable $generatedAt,
    ) {
    }
}
```

```php
// Service/Recommendation/RecommendationForYouSummaryProvider.php
final readonly class RecommendationForYouSummaryProvider
{
    public function __construct(
        private RecommendationItemRepository $items,
        private RecommendationRunRepository $runs,
    ) {
    }

    public function forUser(User $user): RecommendationForYouSummary
    {
        return new RecommendationForYouSummary(
            $this->items->countForYou((int) $user->getId()),
            $this->runs->newestCompletedAt($user),
        );
    }
}
```

```php
// Http/RecommendationRunStatusJson.php
final class RecommendationRunStatusJson
{
    /** @return array<string, mixed> */
    public static function report(RecommendationRunReport $report, RecommendationForYouSummary $summary): array
    {
        return $report->toArray() + [
            'forYou' => [
                'itemCount' => $summary->itemCount,
                'generatedAt' => $summary->generatedAt?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    private function __construct()
    {
    }
}
```

Check how `SubscriptionJson` formats `lastFetchedAt` first; if it uses a different format than `ATOM`, use that one — the frontend parses both through `relativeTime`, but consistency wins.

Controller: inject `RecommendationForYouSummaryProvider $forYouSummaries`; every `new JsonResponse($report->toArray())` becomes `new JsonResponse(RecommendationRunStatusJson::report($report, $this->forYouSummaries->forUser($user)))`. No private helpers.

- [ ] **Step 4: Run** `php bin/phpunit` — green, including the untouched pager tests.
- [ ] **Step 5: Commit** `feat(#321): expose for-you item count and generated-at on the run status responses`

---

### Task 2: Score column on recommendation items, exposed while debug is on

**Files:**
- Modify: `backend/src/Entity/RecommendationItem.php`
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (via `doctrine:migrations:diff`)
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php:547`
- Modify: `backend/src/Repository/RecommendationItemRepository.php` (`hydrateRow`), `backend/src/Repository/RecommendationFeedRow.php`
- Modify: `backend/src/Http/RecommendationFeedJson.php`, `backend/src/Controller/Api/EntryController.php:78-82`
- Test: extend `backend/tests/Http/RecommendationFeedJsonTest.php` (or create), the advancer's completion test, and the entries-endpoint functional test for `view=for-you`

**Interfaces:**
- Consumes: `$pick['score']` already present at the persist site (`RecommendationRunAdvancer.php:547`); `RecommendationSettingsResolver::forUser(User): EffectiveRecommendationSettings` with `->debugEnabled`.
- Produces: `RecommendationItem::getScore(): ?int`; `RecommendationFeedRow::$score: ?int`; `RecommendationFeedJson::page(array $rows, ?string $nextCursor, bool $withScores): array` — entries gain `recommendationScore` (int|null) **only** when `$withScores` is true.

- [ ] **Step 1: Failing tests.** (a) Advancer completion test: after a run completes, the persisted `RecommendationItem` rows carry the pick's score. (b) `RecommendationFeedJson::page($rows, null, true)` includes `recommendationScore`; with `false` the key is absent. (c) Functional: `GET /api/entries?view=for-you` includes `recommendationScore` for a user with `debugEnabled = true` and omits it when the setting is off.
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement.**

Entity: add after `$reason`:

```php
    /** The model's 0-100 score for this pick. Null only on rows written
     *  before the column existed (#321). */
    #[ORM\Column(nullable: true)]
    private ?int $score;
```

Constructor gains `?int $score` as the last parameter; add `getScore(): ?int`. Advancer:

```php
$this->entityManager->persist(
    new RecommendationItem($run, $entryReference, $position, $pick['reason'], $pick['score']),
);
```

`RecommendationFeedRow` gains `public ?int $score` (promoted, after `position`); `hydrateRow` passes `$item->getScore()`.

`RecommendationFeedJson` — no boolean flag *parameter smell* here: split the mapper instead, per house style:

```php
public static function page(array $rows, ?string $nextCursor): array          // unchanged shape
public static function pageWithScores(array $rows, ?string $nextCursor): array // adds recommendationScore
```

Both delegate to a private `entries(array $rows, bool $withScores)` — private statics on an Http mapper are fine (the rule binds controllers). `EntryController`: inject `RecommendationSettingsResolver $recommendationSettings`; the for-you branch becomes:

```php
if ($view === 'for-you') {
    $page = $this->recommendationFeedPager->page((int) $user->getId(), $cursor, $limit);

    return new JsonResponse(
        $this->recommendationSettings->forUser($user)->debugEnabled
            ? RecommendationFeedJson::pageWithScores($page->rows, $page->nextCursor)
            : RecommendationFeedJson::page($page->rows, $page->nextCursor),
    );
}
```

Migration: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction` first (be current), then `doctrine:migrations:diff`; prune the diff to the one `recommendation_item` change if it picked up strays.

- [ ] **Step 4: Run** both suite legs; then from-empty migrate + `doctrine:schema:validate` on both dialects.
- [ ] **Step 5: Commit** `feat(#321): persist the model's score per recommendation and expose it while debug is on`

---

### Task 3: Purge endpoint — `DELETE /api/recommendations/runs`

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationRunPurger.php`
- Create: `backend/src/Service/Recommendation/Exception/RecommendationRunActiveException.php`
- Create: `backend/src/Exception/RecommendationRunActiveApiException.php` (mirror `AiNotConfiguredApiException`'s pattern; HTTP 409, problem type `recommendation_run_active`)
- Modify: `backend/src/Repository/RecommendationItemRepository.php`, `backend/src/Repository/RecommendationRunRepository.php` (delete helpers), `backend/src/Controller/Api/RecommendationRunController.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunPurgerTest.php`, controller functional test

**Interfaces:**
- Consumes: `RecommendationRunLogRepository::deleteForUser(User)` (exists), `RecommendationRunRepository::findLatestForUser(User)`, Task 1's `RecommendationRunStatusJson` + `RecommendationForYouSummaryProvider`.
- Produces: `RecommendationRunPurger::purge(User $user): void` (throws `RecommendationRunActiveException` when the latest run is pending/running); `RecommendationItemRepository::deleteForUser(User $user): void`; `RecommendationRunRepository::deleteForUser(User $user): void`.

Delete children explicitly (logs → items → runs) instead of leaning on the DB-level cascades — portable across both suite dialects, and the order is then part of the code, not the schema. Copy the two-step select-ids-then-DELETE shape from `RecommendationRunLogRepository::deleteForUser` for the items; runs delete directly by user.

- [ ] **Step 1: Failing tests.** Purger test: seed a user with a completed run (items + logs) and a second user with their own run; `purge(userA)` removes A's runs, items and logs and leaves B untouched — **`$em->clear()` before every "row is gone / still there" assertion** (bulk DQL fools the identity map). Seed a pending run → expect `RecommendationRunActiveException`. Functional test: `DELETE /api/recommendations/runs` returns 200 with `status: "none"` and `forYou.itemCount: 0`; with a running run it returns 409 `application/problem+json`.
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement.**

```php
final readonly class RecommendationRunPurger
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private RecommendationRunLogRepository $logs,
        private RecommendationItemRepository $items,
    ) {
    }

    /** @throws RecommendationRunActiveException while a run is pending or running */
    public function purge(User $user): void
    {
        $latest = $this->runs->findLatestForUser($user);
        $active = [RecommendationRun::STATUS_PENDING, RecommendationRun::STATUS_RUNNING];
        if (null !== $latest && \in_array($latest->getStatus(), $active, true)) {
            throw new RecommendationRunActiveException();
        }

        $this->logs->deleteForUser($user);
        $this->items->deleteForUser($user);
        $this->runs->deleteForUser($user);
    }
}
```

Controller action (no limiter — a cheap authenticated DB write, like `current` is a cheap read):

```php
#[Route('', name: 'api_recommendations_purge', methods: ['DELETE'])]
public function purge(#[CurrentUser] User $user): JsonResponse
{
    try {
        $this->purger->purge($user);
    } catch (RecommendationRunActiveException $e) {
        throw new RecommendationRunActiveApiException($e);
    }

    return new JsonResponse(
        RecommendationRunStatusJson::report(RecommendationRunReport::none(), $this->forYouSummaries->forUser($user)),
    );
}
```

- [ ] **Step 4: Run both suite legs — green.**
- [ ] **Step 5: Commit** `feat(#321): add a purge endpoint that clears the for-you list`

---

### Task 4: New defaults (50 of 500) and batch cap 45

**Files:**
- Modify: `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php:18-19`, `backend/src/Service/Recommendation/RecommendationPromptBuilder.php:31`
- Test: existing `EffectiveRecommendationSettings`/resolver tests and `RecommendationPromptBuilderTest` pack tests

**Interfaces:**
- Produces: `DEFAULT_CANDIDATE_POOL_SIZE = 500`, `DEFAULT_PICKS_LIMIT = 50`, `MAXIMUM_BATCH_SIZE = 45`. Later tasks and the frontend hints assume these values.

- [ ] **Step 1: Failing tests.** Adjust the resolver-default expectations to 500/50. Add a pack test: 500 one-line candidates under a huge budget produce 12 batches (11 × 45 + 1 × 5) — with the dedup call that is the 13 total calls the user asked for.
- [ ] **Step 2: Run — the default assertions fail against the old constants.**
- [ ] **Step 3: Implement.** Change the three constants. Extend the `MAXIMUM_BATCH_SIZE` comment (keep the #308 story, it explains the ceiling's existence):

```php
     * ... See #308.
     *
     * Raised 40 → 45 in #321 so the default 500-candidate pool packs into 12
     * batch calls (13 with dedup) instead of 26. Still a fraction of the 339
     * that broke the timeout.
```

Note in the PR text: defaults only apply to accounts **without** a saved settings row — a stored row keeps its own values (resolver reads the row wholesale). Lars: re-save or purge the row to adopt the new defaults.

- [ ] **Step 4: Run** `php bin/phpunit` — green.
- [ ] **Step 5: Commit** `feat(#321): default to 50 picks from 500 candidates in 13 provider calls`

---### Task 5: `batchCount` expert setting (backend)

**Files:**
- Modify: `backend/src/Entity/RecommendationSettings.php`, `backend/src/Service/Recommendation/RecommendationSettingsValues.php`, `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`, `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php`, `backend/src/Service/Recommendation/RecommendationSettingsResolver.php`, `backend/src/Http/RecommendationSettingsJson.php`, `backend/src/Service/Recommendation/RecommendationPromptBuilder.php`
- Create: migration via diff (`user_recommendation_settings.batch_count INT NULL`)
- Test: settings round-trip test (GET/PUT), `RecommendationPromptBuilderTest`

**Interfaces:**
- Produces: nullable `batchCount` end to end — entity column, `RecommendationSettingsValues::$batchCount`, DTO field `#[Assert\Range(min: 1, max: 100)] public ?int $batchCount`, `EffectiveRecommendationSettings::$batchCount` (default `null` = automatic), wire key `batchCount` on GET/PUT `/api/me/ai/recommendations`.
- Packing: when `batchCount` is set, the per-batch cap becomes `max(1, ceil(candidateCount / batchCount))` and **replaces** `MAXIMUM_BATCH_SIZE`; the token-budget split (`overBudget`) still applies, so an oversized explicit batch still splits before it overflows the context window.

Declare every layer exactly the way `contextWindow` is declared in the same file — it is the existing nullable-with-fallback field and the resolver already shows the pattern.

- [ ] **Step 1: Failing tests.** (a) PUT with `batchCount: 5` echoes `batchCount: 5`; PUT with `null` echoes `null`. (b) Pack test: 500 candidates, `batchCount = 12`, huge budget → 12 batches of ≤ 42. (c) Pack test: explicit `batchCount = 1` with a small context window still splits on budget (more than one batch comes back).
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement.** In `packBatches`, replace both `MAXIMUM_BATCH_SIZE` reads:

```php
$cap = $this->batchCap(\count($candidates), $settings);
$responseReserve = $cap * self::TOKENS_PER_PICK;
// ...
$atCapacity = \count($current) >= $cap;
```

```php
/** The explicit batch-count override wins over the #308 size ceiling: it is
 *  an expert setting, and the token budget below still protects the context
 *  window. Null means automatic packing under MAXIMUM_BATCH_SIZE. */
private function batchCap(int $candidateCount, EffectiveRecommendationSettings $settings): int
{
    if (null === $settings->batchCount) {
        return self::MAXIMUM_BATCH_SIZE;
    }

    return max(1, (int) ceil($candidateCount / $settings->batchCount));
}
```

Then entity + values + DTO + resolver + JSON, migration diff, and update the frontend mirror types **in Task 8** (not here — backend task stays backend).

- [ ] **Step 4: Run both suite legs + from-empty migrations + schema:validate.**
- [ ] **Step 5: Commit** `feat(#321): let the user pin the number of recommendation batches`

---

### Task 6: Debug log diagnosis data (timestamps, duration, transport error, run summary)

**Files:**
- Modify: `backend/src/Entity/RecommendationRunLog.php`, `backend/src/Entity/RecommendationRun.php` (add `getAttempts()`, `getTransportFailures()` getters), `backend/src/Service/Recommendation/RecommendationCallRecorder.php`, `backend/src/Service/Recommendation/RecordedCall.php`, `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (`callProvider`), `backend/src/Repository/RecommendationRunLogRepository.php` (`listForUser`), `backend/src/Http/RecommendationDebugLogJson.php`, `backend/src/Controller/Api/RecommendationDebugLogController.php`
- Create: migration (hand-adjusted diff — see below)
- Test: `backend/tests/Service/Recommendation/RecordedCallTest.php` + recorder/advancer tests + debug-log controller test (extend the #309 fixtures — they are shared, see `5255cd4`)

**Interfaces:**
- Produces, per log row on the wire: `createdAt` (ISO string), `finishedAt` (ISO string|null — null while streaming), `errorDetail` (string|null — the transport exception message).
- Produces, on the list response: `run` — `null` when the user never ran, else `{status, error, attempts, maxAttempts, transportFailures, maxTransportFailures, createdAt, completedAt}` from the latest run.
- Entity: constructor gains `\DateTimeImmutable $createdAt` (last parameter, passed by the recorder from its clock); `finish()` gains `\DateTimeImmutable $finishedAt`.
- `RecordedCall::abortAfterTransportFailure(?string $errorDetail)` — the advancer passes `$e->getMessage()` in **both** catch arms of `callProvider`.

Migration note: the log table is transient by design (every run start wipes it), so the migration may `DELETE FROM recommendation_run_log` first and then add `created_at DATETIME NOT NULL`, `finished_at DATETIME DEFAULT NULL`, `error_detail LONGTEXT DEFAULT NULL` — no backfill gymnastics. Generate the diff from the entity, then prepend the DELETE. (#309 removed a dead `updated_at` here; these columns are written *and* read — say so in the migration's description.)

- [ ] **Step 1: Failing tests.** (a) `RecordedCall::finishUsable` writes `finished_at`; `abortAfterTransportFailure('cURL error 28')` writes `finished_at` + `error_detail` + the transport verdict (use the existing DBAL-level test fixture style of `RecordedCallTest`). (b) Recorder test: a persisted log row has `createdAt` from the clock. (c) Controller test: the list response carries `run.status` / `run.attempts` / per-entry `createdAt`, `finishedAt`, `errorDetail`.
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement.** Entity fields:

```php
    /** When the request went out. */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the call settled (any verdict). Null while streaming. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** The transport exception's message, for transport-failed calls only. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorDetail = null;
```

`RecordedCall` (naive UTC strings for DBAL, matching the stored convention):

```php
public function abortAfterTransportFailure(?string $errorDetail): void
{
    $this->resetLiveness();
    if (null === $this->logId) {
        return;
    }
    $this->connection->update('recommendation_run_log', [
        'verdict' => RecommendationRunLog::VERDICT_TRANSPORT_FAILED,
        'wire_bytes' => $this->wireBytes,
        'finished_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        'error_detail' => $errorDetail,
    ], ['id' => $this->logId]);
}
```

`finish()` adds the same `finished_at` key. `listForUser` adds `l.createdAt AS createdAt`, `l.finishedAt AS finishedAt`, `l.errorDetail AS errorDetail` (format datetimes to ATOM strings in the mapping loop, same place the LENGTH casts live). `RecommendationDebugLogJson::list(array $rows, array $streamingTextById, ?RecommendationRun $run)` adds the `run` key; the controller passes `$this->runs->findLatestForUser($user)`.

- [ ] **Step 4: Both suite legs + migration verification. Also scan `backend/var/log/dev.log`** after a manual run — this task touches the live streaming path.
- [ ] **Step 5: Commit** `feat(#321): record call timing and transport errors in the recommendation debug log`

---

### Task 7: Shared disclosure component (frontend)

**Files:**
- Create: `frontend/src/app/shared/disclosure/disclosure.component.ts` / `.html` / `.scss` / `.spec.ts`
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.html:14-20` (+ its `.scss`), `frontend/src/app/settings/recommendation-debug-log.component.html:2-5` (+ its `.scss`)
- Modify: `docs/design-language.md` (add to the shared catalog)

**Interfaces:**
- Produces: `<app-disclosure [label]="…">projected content</app-disclosure>` — a native `<details>/<summary>` wrapper; `label = input.required<string>()`. Content is lazy only visually (native details), no signals.

This is the DRY move: `recommendation-settings-card` and `recommendation-debug-log` are the app's only two `<details>` and Task 8 adds the third occurrence.

- [ ] **Step 1: Failing test.** Jest: renders the label in `<summary>`, projects content, toggles `[open]` via native click.
- [ ] **Step 2: Run — fails (component missing).**
- [ ] **Step 3: Implement.** Consolidate the two existing `<details>` style blocks (`recommendation-settings-card.component.scss:23-42`, `recommendation-debug-log.component.scss:6-15`) into `disclosure.component.scss`; delete them at the origins; replace both usages. Keep each host's *content* styles (the `pre.fixed`, the log list) where they are.
- [ ] **Step 4:** `npm run check` + `npm test` — green; open the settings page against the Docker stack and verify both disclosures still look right.
- [ ] **Step 5: Commit** `refactor(#321): extract the shared disclosure component from the two details patterns`

---

### Task 8: Settings card — expert section, batch count, purge button (decision 6A)

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings.service.ts` (types + `purge()`), `frontend/src/app/settings/recommendation-settings-card.component.ts` / `.html` / `.scss` / `.spec.ts`
- Modify: `frontend/src/app/reader/reader-api.ts` (`purgeRecommendations()`), `frontend/src/app/reader/recommendations.service.ts` (`refreshStatus()` — see Interfaces)
- Modify: `frontend/public/i18n/en.json` + `de.json`

**Interfaces:**
- Consumes: Task 5's `batchCount` wire field; Task 3's `DELETE /api/recommendations/runs` returning the Task 1 status shape; `<app-disclosure>` from Task 7; confirm pattern: copy the danger-zone confirm flow from `ai-section.component.html:77-84` / its component.
- Produces: `RecommendationSettingsState`/`SaveRecommendationSettings` gain `batchCount: number | null`; `ReaderApi.purgeRecommendations(): Observable<RecommendationRunReport>`; `RecommendationsService.refreshStatus(): void` (fetches `/current` and sets `report` — Task 9's sidebar count updates through it).

Layout per the approved mock (6A): **visible** — guidance textarea, Reset, Save, error banner, "Saved."; **inside `<app-disclosure label="Expert settings">`** — the six numeric fields in a two-column grid (`favoritesCap`, `keptCap`, `viewedCap`, `candidatePoolSize`, `picksLimit`, `batchCount`), then `contextWindow` with its source hint, the fixed-prompt disclosure (nested is fine — native details nest cleanly), and the debug toggle; **below, a danger zone** — explanation line + "Clear recommendations" (`variant="danger-outline"`, confirm dialog, then `svc.purge()` → on success `recs.refreshStatus()` and a `settings.ai.recommendations.purged` inline confirmation like "Saved.").

New i18n keys under `settings.ai.recommendations` (EN / DE):
- `expertTitle`: "Expert settings" / "Experteneinstellungen"
- `batchCount`: "Batches (empty = automatic)" / "Batches (leer = automatisch)"
- `purge`: "Clear recommendations" / "Empfehlungen leeren"
- `purgeExplain`: "Removes every recommended post from “For you”. A new run rebuilds the list." / "Entfernt alle empfohlenen Beiträge aus „Für dich“. Ein neuer Lauf baut die Liste neu auf."
- `purgeConfirm`: "Clear all recommendations?" / "Alle Empfehlungen leeren?"
- `purged`: "Recommendations cleared." / "Empfehlungen geleert."

- [ ] **Step 1: Failing tests.** Extend the card spec: save() sends `batchCount` (number and null round-trip); purge flow calls the API after confirm and shows the `purged` line; the numeric fields render inside the disclosure (query by label).
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement.** Grid SCSS (tokens only):

```scss
.expert-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3) var(--space-4);
}

@media (width <= 480px) {
  /* match the app's existing narrow breakpoint variable if one exists in
     theme/_breakpoints.scss — use it instead of a literal */
  .expert-grid {
    grid-template-columns: 1fr;
  }
}
```

Check `frontend/src/app/theme/_breakpoints.scss` and use its mixin/variable — media-query literals fail Stylelint.

- [ ] **Step 4:** `npm run check` + `npm test`; visual check against the Docker stack (settings → AI), light and dark.
- [ ] **Step 5: Commit** `feat(#321): fold recommendation tuning into expert settings and add the purge button`

---

### Task 9: Reader chrome — sidebar count, header timestamp, score pills (decisions 3A + settled items)

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (report + entry types), `frontend/src/app/reader/recommendations.service.ts`, `frontend/src/app/reader/sidebar/sidebar.component.html:62-73`, `frontend/src/app/reader/reader-shell.component.ts:208-214` + `.html:102/:144`, `frontend/src/app/reader/entry-list/entry-list.component.ts:103-131` (+ the `.scss` rule that hides `.last-refreshed` on narrow screens), `frontend/src/app/reader/entry-row/entry-row.component.html:18-20` + `.scss`
- Test: specs of recommendations service, entry-list, entry-row

**Interfaces:**
- Consumes: Task 1's wire shape (`forYou.itemCount` / `forYou.generatedAt`), Task 2's `recommendationScore`.
- Produces: `RecommendationRunReport` gains `forYou: { itemCount: number; generatedAt: string | null }`; `EntryDto` gains `recommendationScore?: number | null`; `RecommendationsService.forYouCount` / `generatedAt` computeds; shell computed `listLastRefreshed` replaces `selectedFeedLastFetched` at the two `[lastRefreshed]` bindings.

- [ ] **Step 1: Failing tests.** (a) Service spec: `resume()` with a `completed` report stores it (today it returns early — the count/timestamp need it at boot) and does **not** set `running`; the computeds read the summary. (b) Entry-list spec: `lastRefreshedLabel` renders for `kind === 'for-you'` with an ISO input. (c) Entry-row spec: a score renders as a pill, no pill when `recommendationScore` is absent.
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement.**

`resume()` — set the report before the status guard, keep everything else:

```ts
next: (r) => {
  this.report.set(r); // even a finished run carries the for-you summary the sidebar needs
  if (r.status !== 'pending' && r.status !== 'running') return;
  this.running.set(true);
  this.failure.set(null);
  this.step(NO_ATTEMPTS);
},
```

```ts
readonly forYouCount = computed(() => this.report()?.forYou.itemCount ?? 0);
readonly generatedAt = computed(() => this.report()?.forYou.generatedAt ?? null);

/** Re-read /current outside a poll loop — after a purge, or any time a
 *  consumer changed the list behind the report's back. */
refreshStatus(): void {
  this.api.currentRecommendations().subscribe({
    next: (r) => this.report.set(r),
    error: () => {
      // Status refresh is best-effort, same posture as resume().
    },
  });
}
```

Sidebar (it already injects `recs` for the pulse):

```html
<span>{{ 'reader.forYou' | transloco }}</span>
@if (recs.forYouCount() > 0) {
  <span class="count">{{ recs.forYouCount() }}</span>
}
```

Shell — replace `selectedFeedLastFetched` (keep the name change honest at both template bindings):

```ts
/** What the list header's "Last refreshed" hint shows: a feed's fetch time,
 *  or the for-you list's generation time. Null everywhere else. */
readonly listLastRefreshed = computed(() => {
  const s = this.selection();
  if (s.kind === 'for-you') return this.recs.generatedAt();
  if (s.kind !== 'subscription') return null;
  return this.subs.subscriptions().find((x) => x.id === s.id)?.lastFetchedAt ?? null;
});
```

Entry-list label gate: `if ((kind !== 'subscription' && kind !== 'for-you') || !iso) return null;` — and remove the wide-only hiding of `.last-refreshed` (find the media rule in `entry-list.component.scss`; the user wants the timestamp visible on the phone, per the approved mock). Check the narrow header still fits — the collapsed header state must not clip it; if it crowds, keep it hidden **only** in the `.collapsed` state, not per breakpoint.

Entry-row (3A):

```html
@if (entry().recommendationReason) {
  <p class="reason">
    @if (entry().recommendationScore != null) {
      <span class="score">{{ entry().recommendationScore }}</span>
    }
    {{ entry().recommendationReason }}
  </p>
}
```

```scss
.reason .score {
  display: inline-block;
  margin-right: var(--space-1);
  padding: 0 var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: var(--fs-xs);
  font-style: normal;
  font-weight: 700;
}
```

- [ ] **Step 4:** `npm run check` + `npm test`; boot the stack, confirm the sidebar count appears without visiting the for-you view first (that is what the `resume()` change buys).
- [ ] **Step 5: Commit** `feat(#321): show the for-you count, generation time and debug scores in the reader`

---

### Task 10: For-you block into the list, real button, info overlay (decisions 1A + 2A)

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` / `.html` (new `topBlock` TemplateRef input, outlets), `frontend/src/app/reader/reader-shell.component.html:181-209` / `.ts` / `.scss:169-229`
- Create: `frontend/src/app/reader/for-you-info-dialog.component.ts` / `.html` / `.scss` / `.spec.ts`
- Modify: `frontend/public/i18n/en.json` + `de.json`
- Test: entry-list spec (topBlock renders), shell spec if present, dialog spec

**Interfaces:**
- Consumes: `RecommendationsService` signals; `<app-button>` (`variant="primary" size="sm"`); `<app-overlay-panel>` + CDK `Dialog` with `panelClass: 'app-dialog'` (docs/design-language.md §Overlay conventions); the shared bytes→KB helper from #309 (grep for the rounding util shared by the debug log and the old for-you bar — reuse, do not duplicate).
- Produces: `EntryListComponent.topBlock = input<TemplateRef<unknown> | null>(null)`, rendered at the top of the scrolling content in **all three** content branches (empty state, magazine `.rows`, list `.rows`) — only one branch is live at a time; `ForYouInfoDialogComponent` (standalone, injects `RecommendationsService` + `DialogRef`).

Behaviour to preserve, with its new home:
- The idle **failure line** (`role="alert"`) stays next to the button in the block.
- The running **progress line** keeps `role="status" aria-live="polite"`.
- `reader.forYouKeepOpen` / `reader.forYouBackground` and the streamed-KB line move **into the dialog** (2A). The inline `__streamed` span dies.
- The **hairline** stays pinned: absolutely positioned at the app-bar seam of `.list`, so a running run stays visible while the block is scrolled away (approved mock 1A).

- [ ] **Step 1: Failing tests.** Entry-list spec: a provided `topBlock` template renders above the rows and above the empty state; none of the existing scroll/pull specs regress. Dialog spec: shows keep-open vs background line depending on `workerOwnsRun`, shows the KB line only when `streamedChars > 0`.
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement.**

Entry-list: import `NgTemplateOutlet`, add the input, and in the template add at the top of the empty-state branch and of both `.rows` scrollers:

```html
@if (topBlock(); as block) {
  <ng-container [ngTemplateOutlet]="block" />
}
```

Shell template — the `#forYouBar` template becomes `#forYouTop` (bound as `[topBlock]="forYouTop"` on both `<app-entry-list>` instances; the `<ng-container [ngTemplateOutlet]="forYouBar" />` lines and the `--app-bar-h: 0px` sibling override die):

```html
<ng-template #forYouTop>
  @if (selection().kind === 'for-you') {
    <div class="for-you-top">
      @if (recs.running()) {
        <p role="status" aria-live="polite">
          {{ 'reader.forYouProgress' | transloco: forYouProgress() }}
        </p>
        <button
          type="button"
          class="info"
          (click)="openForYouInfo()"
          [attr.aria-label]="'reader.forYouInfoOpen' | transloco"
        >
          <app-icon name="info" size="sm" />
        </button>
      } @else {
        <app-button variant="primary" size="sm" (click)="recs.start()">
          {{ 'reader.forYouRun' | transloco }}
        </app-button>
        <p class="cap">{{ 'reader.forYouRunHint' | transloco }}</p>
        @if (forYouFailureMessageKey(); as failureKey) {
          <p role="alert">{{ failureKey | transloco }}</p>
        }
      }
    </div>
  }
</ng-template>
```

The hairline moves out of the template into both `.list` sections directly (kept out of the scroller):

```html
@if (selection().kind === 'for-you') {
  <app-progress-hairline [active]="recs.running()" [value]="recs.progress()" />
}
```

Shell SCSS — delete `.for-you-bar` (`:197-229`), the `.for-you-bar ~ app-entry-list` override (`:174-184`) and the `.list .for-you-bar` flex rule; add:

```scss
/* The 2px run hairline floats at the app-bar seam instead of living in flow:
   the run block scrolls away with the list (#321), so this is the one signal
   of a running run that stays put. Zero layout impact by design. */
.list {
  position: relative;
}

.list app-progress-hairline {
  position: absolute;
  z-index: 3;
  top: var(--app-bar-h, var(--bar-h));
  right: 0;
  left: 0;
}

.for-you-top {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1) var(--space-2);
  align-items: center;
  padding: var(--space-2) var(--row-pad-comfy-x) var(--space-3);
  color: var(--text-secondary);
  font-size: var(--fs-sm);

  p {
    margin: 0;
  }

  .cap {
    flex-basis: 100%;
    color: var(--text-muted);
    font-size: var(--fs-xs);
  }

  .info {
    padding: var(--space-1);
    border: 0;
    background: none;
    color: var(--text-muted);
    cursor: pointer;
  }
}
```

(The template lives in the shell, so shell styles reach the projected nodes — emulated encapsulation scopes by declaring component.)

Dialog component (open with the app's standard `Dialog` + `panelClass: 'app-dialog'`; copy the open call shape from an existing dialog user, e.g. the confirm-dialog callers):

```ts
// src/app/reader/for-you-info-dialog.component.ts
@Component({
  selector: 'app-for-you-info-dialog',
  imports: [OverlayPanelComponent, ButtonComponent, TranslocoPipe],
  templateUrl: './for-you-info-dialog.component.html',
  styleUrl: './for-you-info-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForYouInfoDialogComponent {
  private readonly ref = inject(DialogRef);
  readonly recs = inject(RecommendationsService);

  close(): void {
    this.ref.close();
  }
}
```

```html
<app-overlay-panel [heading]="'reader.forYouInfoTitle' | transloco">
  <p>{{ 'reader.forYouInfoWhat' | transloco }}</p>
  <p>
    {{ (recs.workerOwnsRun() ? 'reader.forYouBackground' : 'reader.forYouKeepOpen') | transloco }}
  </p>
  @if (streamedKb(); as kb) {
    <p class="muted">{{ 'reader.forYouStreamed' | transloco: { kb } }}</p>
  }
  <div footer>
    <app-button (click)="close()">{{ 'common.close' | transloco }}</app-button>
  </div>
</app-overlay-panel>
```

`streamedKb` reuses the shared KB-rounding helper (the shell's `forYouStreamedKb` computed at `reader-shell.component.ts:155-161` moves here; delete it from the shell). Check `overlay-panel`'s actual footer slot name in `overlay-panel.component.html` (`[footer]` attribute selector per design-language §347-387) and match it.

New i18n keys under `reader` (EN / DE):
- `forYouRunHint`: "Ranks your unread posts and keeps the best." / "Bewertet deine ungelesenen Beiträge und behält die besten."
- `forYouInfoOpen`: "About this run" / "Über diesen Lauf"
- `forYouInfoTitle`: "About this run" / "Über diesen Lauf"
- `forYouInfoWhat`: "The AI compares your unread posts with your reading history and keeps the best matches." / "Die KI vergleicht deine ungelesenen Beiträge mit deinem Leseverlauf und behält die besten Treffer."

- [ ] **Step 4:** `npm run check` + `npm test`. Then the real render (this is a layout change — screenshot it, do not reason from code): Docker stack up, mobile viewport, verify (a) button sits below the "For you" title, (b) block scrolls away with the list, (c) hairline stays during a run, (d) info dialog opens/closes, (e) empty for-you state still shows the button, (f) split-pane wide layout unbroken.
- [ ] **Step 5: Commit** `feat(#321): move the for-you controls into the list top and the hints behind an info overlay`

---

### Task 11: Toast — borderless with elevation (decision 4B)

**Files:**
- Modify: `frontend/src/app/shared/toast/toast.component.scss:8`
- Test: visual verification (no Jest assertion on box-shadow)

- [ ] **Step 1: Reproduce first** (standing rule: reproduce visual bugs on the real render). Stack up, trigger the completion toast (finish a run, or temporarily call `toast.show` from a spec harness page), screenshot light + dark. Confirm what reads as the green frame (expected: `--border-strong` against `--surface-2` beside the teal action label).
- [ ] **Step 2: Implement.** Replace the border with elevation:

```scss
.toast {
  /* ... */
  border: none;
  box-shadow: 0 10px 32px rgb(0 0 0 / 22%);
}
```

Matches the overlay panel's shadow-only treatment (`overlay-panel.component.scss:35`). Check dark mode contrast: `--surface-2` (#242424) on `--surface-0` (#161616) with the shadow must still separate — if it floats too weakly, deepen the shadow alpha, do not re-add a border.
- [ ] **Step 3: Verify** on the real render, both themes; screenshot after.
- [ ] **Step 4:** `npm run check`.
- [ ] **Step 5: Commit** `fix(#321): drop the toast border that read as a green frame`

---

### Task 12: Debug log redesign — summary strip + aligned rows (decision 5A)

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`DebugLogEntry` + new `DebugLogRunSummary`), `frontend/src/app/reader/reader-api.ts` (`debugLog()` return type), `frontend/src/app/settings/recommendation-debug-log.component.ts` / `.html` / `.scss` / `.spec.ts`
- Modify: `frontend/public/i18n/en.json` + `de.json`

**Interfaces:**
- Consumes: Task 6's wire shape (`run` summary + per-entry `createdAt` / `finishedAt` / `errorDetail`), `<app-disclosure>` from Task 7 (panel shell stays a disclosure).
- Produces: `DebugLogEntry` gains `createdAt: string; finishedAt: string | null; errorDetail: string | null`; new `DebugLogRunSummary { status, error, attempts, maxAttempts, transportFailures, maxTransportFailures, createdAt, completedAt }`; `debugLog(): Observable<{ run: DebugLogRunSummary | null; entries: DebugLogEntry[] }>`.

Layout per the approved mock (5A):
- **Summary strip** above the rows: status chip (`--bg-success/--success` for completed, `--bg-danger/--danger` for failed, neutral otherwise), the run error text when present, `attempts n/m · transport n/m`, `HH:MM → HH:MM` from `createdAt`/`completedAt`.
- **Row grid**: `time | call | verdict chip | req/resp KB | expander` — CSS grid, `font-variant-numeric: tabular-nums` on the numeric columns, verdicts as chips (replacing the coloured text at `recommendation-debug-log.component.scss:47-61`).
- A `transport-failed` row renders its `errorDetail` as a full-width danger-coloured line under the row.
- Per-row duration in the expanded body (`finishedAt − createdAt`, seconds); the streaming row keeps its live `<pre>` exactly as today.
- Keep: lazy detail fetch, Copy buttons, 2 s poll while running, self-hiding when empty. Times format with the existing shared date/format helpers — **not** `DatePipe` (unusable with runtime Transloco switching).

New i18n keys under `settings.ai.recommendations` (EN / DE):
- `debugRunSummary`: "attempts {{a}}/{{am}} · transport {{t}}/{{tm}}" / "Versuche {{a}}/{{am}} · Transport {{t}}/{{tm}}"
- `debugDuration`: "{{s}} s" / "{{s}} s"

- [ ] **Step 1: Failing tests.** Extend the component spec (reuse the #309 shared fixtures, extend them with the new fields): renders the summary strip from `run`; renders `errorDetail` on a transport-failed row; a completed run shows no error line.
- [ ] **Step 2: Run — expect failures.**
- [ ] **Step 3: Implement** per the mock; keep the component's polling/expansion logic untouched except the new `run` signal.
- [ ] **Step 4:** `npm run check` + `npm test`; visual check with a real debug run (settings → AI, debug on, start a run from the reader), both themes, narrow width (the grid must not force page-level horizontal scroll — wrap the grid in its own `overflow-x: auto` if the phone needs it).
- [ ] **Step 5: Commit** `feat(#321): redesign the debug log with a run summary and aligned call rows`

---

### Task 13: Final verification and PR

- [ ] **Step 1: Backend gates.** From `backend/`: `bin/console cache:warmup && composer check && composer md`, `php bin/phpunit`, `docker compose exec php vendor/bin/phpunit` (the MySQL leg has known order-dependent limiter flakes — rerun a failing limiter test in isolation before blaming this branch), `composer infection:diff`.
- [ ] **Step 2: Migration leg locally.** From-empty migrate + `doctrine:schema:validate` on SQLite and MySQL (three migrations land in this branch: item score, settings batch_count, run-log diagnosis columns).
- [ ] **Step 3: Frontend gates.** From `frontend/`: `npm run check`. Optionally `npm run e2e` against the stack (no existing spec touches for-you, verified 2026-08-08 — still run the suite to catch collateral).
- [ ] **Step 4: PhpStorm inspections** (`mcp__phpstorm__lint_files`) on every changed PHP file — block on ERROR and WARNING.
- [ ] **Step 5: Scan `backend/var/log/dev.log`** after a full manual pass: run start → progress → completion toast → purge.
- [ ] **Step 6: PR** to `develop`, body `Closes #321`, listing the user-approved design decisions (1A 2A 3A 4B 5A 6A) and the defaults note from Task 4 (existing settings rows keep their stored values). After the merge, verify #321 closed itself.

---

## Self-review notes

- **Spec coverage:** ticket items 1→Task 1+9, 2→1+9, 3→10, 4→10 (1A: block scrolls away), 5→10 (2A), 6→2+9 (3A), 7→11 (4B), 8→12 (5A), 9 (diagnosis data)→6+12, 10 (expert settings)→7+8 (6A), 11 (purge)→3+8, 12 (defaults)→4, 13 (batch cap)→4, 14 (batch count setting)→5+8.
- **Sequencing:** backend tasks 1–6 are independent of the frontend and land first; Task 7 (disclosure) must precede 8 and 12; Task 9 depends on 1+2; Task 8 depends on 3+5+7.
- **Known judgement calls recorded:** summary lives beside the report, not in it (failed-latest-run case); purge deletes explicitly instead of via DB cascade (SQLite portability); the run-log migration wipes transient rows to add a NOT NULL `created_at`; `batchCount` override beats the #308 cap deliberately (expert setting, token budget still guards).
