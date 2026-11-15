# Resolve shortened link-card URLs for Trending (#2236) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `shared_link` row whose URL has the short-link shape is queued at insert, followed once in the
background to where it lands, and stored there cleaned with its hash. Trending's article key, URL links and site count
read that landing, falling back to the URL as posted. The card keeps showing the posted URL.

**Architecture:**
- `Foundation/Url/Support/ShortLinkShape` decides candidacy, and `SharedLinkWriter` queues candidates as `pending`.
- The new module `Content/LinkResolution` holds `LinkLanding` (HEAD, then GET, through `RedirectFollower`) and
  `LinkResolutionSweep` (a budgeted drain), with three models.
- The worker message and the maintenance tick sweep run the drain.
- Trending changes two SQL `COALESCE`s.
- A one-off `app:shared-links:resolve` backfills the existing rows.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM 3 / DBAL 4, Symfony HttpClient (`MockHttpClient` in tests),
PHPUnit 12, Infection.

**Spec:** `docs/superpowers/specs/2026-11-15-2236-resolve-shorteners-design.md`, the binding authority. Read its
Design, Decisions D1–D15 and Research R1–R4 before Task 1.

## Revisions in implementation

The spec's D6 (controller ruling: a transient failure gets two more attempts, an hour apart) and D16 (the resolution is
its own table) change these tasks; the spec is the authority.
- **Task 2:** `shared_link_resolution` (entity `SharedLinkResolution`, repository `SharedLinkResolutionRepository`)
  instead of three `shared_link` columns; `SharedLinkWriter::linksFor()` queues through `queueByUrlHashes()`;
  `NewSharedLinkModel` and `SharedLink` are unchanged.
- **Task 3:** `LinkLandingModel::retryable()` (resolution `Pending`) for a transport failure or a `429`/`5xx` at the
  posted URL; `testBothFailingIsUnresolved` became `testAConnectionFailureIsRetryable`.
- **Task 4:** `pendingAt($now, $limit)` returns `PendingLinkModel`s; `settle()` and `defer()` are conditional on the
  attempts read; the report gains `deferred`.
- **Tasks 5 and 7:** the tick JSON, the worker log and the backfill line carry `deferred`; the backfill's repository
  methods are `SharedLinkResolutionRepository::unqueuedUrlsAfterId()` and `queue()`.
- **Task 6:** both Trending reads `LEFT JOIN shared_link_resolution landing`.

## Global Constraints

- **Binding rulings:**
  - generic resolution, with no host list and no value from the current news window;
  - the landing is stored once per `shared_link` row (`resolution`, `resolved_url`, `resolved_url_hash`), never per card;
  - the card, its preview and every JSON mapper keep `shared_link.url`;
  - a known row is never resolved twice;
  - the backfill is a console command, deleted in a follow-up commit after the dev and Strato runs.
- **Exact values (spec D1, D4, D5):**
  - candidate = a path that is exactly one segment, after trimming `/`, containing neither `.` nor `-`;
  - per attempt: `RedirectBudget(5, 3.0)`, option `timeout` 3.0, `buffer` false, headers `User-Agent` (the
    `$userAgent` bind) and `Accept: text/html,*/*;q=0.8`;
  - `LinkLanding::MAX_SECONDS = 6.0`;
  - 5 links per worker and tick pass, 50 per backfill pass;
  - the tick sweep is `#[AsTaggedItem(index: 'linkResolutions', priority: 65)]`;
  - the worker schedule entry is `every('1 minute')`, appended last.
- **No request on any write path.** Writers only set `pending`.
- **API:** no `Http/*Json` mapper for clients changes. Only the admin `/maintenance/tick` gains a `linkResolutions` key.
- **Dev DB:** read only. Tests never touch MySQL dev. Measuring uses only `feedreader_2236_*` scratch databases
  (create and drop them as root in the mysql container). `feedreader` and `feedreader_2237_uncleaned` are never written.
- **Tests make no real request:** `MockHttpClient` and a fixed `DnsResolverInterface`, as in
  `tests/Service/Outbound/Image/GuardedImageProbe/GuardedImageProbeTest.php` (`FetchWiring::redirectFollower`).
- **House rules (CLAUDE.md):**
  - `final readonly` by default; one role per folder; queries only in `src/Repository`;
  - at most three parameters, and no boolean flags;
  - no comment unless a reader would get the code wrong;
  - `Response::HTTP_*` for statuses;
  - `#[DataProvider]`, and matchers on `$this`;
  - no PHPStan ignores, docblocks or `tests/PhpStan` edits;
  - every touched `src` file is PHPMD-clean: `rm -rf ~/.pdepend`, then
    `HOME=$(mktemp -d) vendor/bin/phpmd analyze <files> --no-progress`. If the commit hook denies new classes, report
    BLOCKED with its message;
  - `SharedLink` reaches 14 fields. Add nothing else to it.
- **French and Spanish first-class** in every measurement.
- Commit format `type(#2236): lower-case imperative summary`, with no Claude attribution. Work on the main checkout,
  branch `feature/2236-resolve-shorteners`. Never push. Commands run in the foreground. No background jobs, no polling,
  no subagents.

## Review Focus

1. **A landing behind an error status still counts** (D3): a `301` to a URL that answers `403` is `resolved` to that
   URL, with no GET fallback. Task 3, `testARedirectToABlockedArticleResolves`.
2. **A private or reserved hop is never followed** (D11): a `Location` resolving to `10.0.0.1` ends `unresolved`, and
   no request is made to it. Task 3, `testAHopToAPrivateAddressIsUnresolved`.
3. **The hash, not the host, decides.** A redirect that only adds tracking parameters (`n.pr/x` →
   `n.pr/x?utm_medium=social`) is `unchanged`. A slash-only redirect changes the hash (`UrlNormalizer` keeps the
   path verbatim), so it is `resolved`. Task 3: `testARedirectToATrackingVariantIsUnchanged` and
   `testASlashOnlyRedirectResolves`.
4. **A concurrent or repeated settle writes once**: a row settled `resolved` is not rewritten by a later settle with
   another landing. Task 4, `testASettledRowIsNotSettledAgain`.
5. **The backfill never queues a non-candidate or a settled row, and a second run is a no-op.** Task 7,
   `testASecondRunQueuesNothing` and `testOnlyUnqueuedCandidatesAreQueued`.

---

### Task 1: The short-link shape

**Files:**
- Create: `backend/src/Service/Foundation/Url/Support/ShortLinkShape.php`
- Test: `backend/tests/Service/Foundation/Url/Support/ShortLinkShapeTest.php`

**Interfaces:**
- Produces: `final class ShortLinkShape { public static function fits(string $url): bool; private function __construct() }`.

- [ ] **Step 1: Failing test.** `#[DataProvider]` `testFits(string $url, bool $fits)` with:
  - true: `https://n.pr/4kcyxuV`, `https://trib.al/X9b8h3C`, `https://bit.ly/4iQI7RJ/` (trailing slash),
    `https://taz.de/!6227406`, `https://youtu.be/IBJ4QotdjPI?t=12`, `https://www.youtube.com/watch?v=abc`;
  - false: `https://example.com/` (none), `https://example.com` (none),
    `https://news.netasgard.com/a/35438417fdec504f` (two),
    `https://stadt-bremerhaven.de/capy-reader-schlanker-open-source` (hyphen),
    `https://atlasflux.saynete.com/atlas_des_flux.htm` (dot), `not a url`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Foundation/Url/Support/ShortLinkShapeTest.php`. Expected: FAIL (the
  class is missing).
- [ ] **Step 3:** Implement with `parse_url($url, PHP_URL_PATH)`; a non-string path is false.
- [ ] **Step 4:** Run again. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2236): recognise the short-link url shape`.

### Task 2: Schema and queuing at insert

**Files:**
- Create: `backend/src/Enum/LinkResolution.php`, `backend/migrations/Version20261115220000.php`
- Modify: `backend/src/Entity/SharedLink.php`, `backend/src/Service/Content/LinkCard/Model/NewSharedLinkModel.php`,
  `backend/src/Service/Content/LinkCard/SharedLinkWriter.php` (`newLink`),
  `backend/src/Repository/SharedLinkRepository.php` (`insertMissing` column list, `rowOf`)
- Test: `backend/tests/Service/Content/LinkCard/SharedLinkWriterTest.php`,
  `backend/tests/Migrations/Version20261115220000Test.php`

**Interfaces:**
- Consumes: `ShortLinkShape::fits()` (Task 1).
- Produces:
  - `enum LinkResolution: string { case Pending = 'pending'; case Resolved = 'resolved'; case Unchanged = 'unchanged';
    case Unresolved = 'unresolved'; }`;
  - `SharedLink`: columns `resolution` (length 10, nullable, `enumType`), `resolved_url` (`LinkCardContent::URL_MAX`,
    nullable), `resolved_url_hash` (64, nullable); `#[ORM\Index(name: 'idx_shared_link_resolution', columns:
    ['resolution'])]`; `getResolution(): ?LinkResolution`; no setter;
  - `NewSharedLinkModel` gains a fifth promoted property, `public ?LinkResolution $resolution`.

- [ ] **Step 1: Failing tests** in `SharedLinkWriterTest` (the kernel test that already exists):
  - `testAShortLinkIsQueued`: `linkFor()` of a card at `https://n.pr/4kcyxuV` yields a row whose `getResolution()` is
    `Pending`;
  - `testAnArticleUrlIsNotQueued`: `https://example.com/2026/11/some-story` gives null;
  - `testAKnownRowKeepsItsResolution`: insert a short-link row, then set it to `unchanged` with a DBAL update. A second
    `linkFor()` of the same URL returns the row still `Unchanged`.
- [ ] **Step 2: Failing migration test.** `Version20261115220000Test`, following `SharedLinkMigrationsTest`'s setup:
  on SQLite, after the migration the three columns and the index exist, the `entry_search` triggers are still there,
  and an existing row reads null in all three columns.
- [ ] **Step 3:** Run both tests. Expected: FAIL.
- [ ] **Step 4:** Implement.
  - The entity and the enum.
  - The migration per spec *Schema*, platform-aware like `Version20261115140000`, with `isTransactional(): false`.
    MySQL: one `ALTER TABLE shared_link ADD resolution VARCHAR(10) DEFAULT NULL, ADD resolved_url VARCHAR(2048)
    DEFAULT NULL, ADD resolved_url_hash VARCHAR(64) DEFAULT NULL, ADD INDEX idx_shared_link_resolution (resolution)`.
    SQLite: three `ADD COLUMN` and a `CREATE INDEX`. `down()` reverses both.
  - `newLink()` passes `ShortLinkShape::fits($card->url) ? LinkResolution::Pending : null`.
  - `rowOf()` appends `$link->resolution?->value`, and the insert lists `resolution`.
- [ ] **Step 5:** Run them again. Then, with `DATABASE_URL=sqlite:///%kernel.project_dir%/var/mig-2236.db`, run
  `bin/console doctrine:migrations:migrate -n` and then `bin/console doctrine:schema:validate`. Expected: PASS, and the
  schema in sync. Delete `var/mig-2236.db`.
- [ ] **Step 6:** Commit `feat(#2236): queue short-link shared links for resolution`.

### Task 3: `LinkLanding`

**Files:**
- Create: `backend/src/Service/Content/LinkResolution/LinkLanding.php`,
  `backend/src/Service/Content/LinkResolution/Model/LinkLandingModel.php`
- Test: `backend/tests/Service/Content/LinkResolution/LinkLandingTest.php`

**Interfaces:**
- Consumes: `RedirectFollower::followHead()` and `follow()`, `RedirectBudget`, `LandedResponse`,
  `RedirectChainException`, and `UrlNormalizer::withoutTracking()` and `hash()`.
- Produces:
  - `final readonly class LinkLandingModel(LinkResolution $resolution, ?string $url, ?string $urlHash)` with
    `static resolved(string $url, string $urlHash)`, `static unchanged()` and `static unresolved()`;
  - `final readonly class LinkLanding { public const float MAX_SECONDS = 6.0; __construct(RedirectFollower $redirects,
    UrlNormalizer $urlNormalizer, string $userAgent); public function of(string $url): LinkLandingModel }`.

- [ ] **Step 1: Failing tests.** Build `LinkLanding` over `FetchWiring::redirectFollower(MockHttpClient, …)` with a
  public-IP resolver, as `GuardedImageProbeTest::probe()` does. Record each request's method and URL.
  - `testAShortLinkResolvesToItsCleanedLanding`: HEAD `https://n.pr/x` answers `301` with `Location:
    https://www.npr.org/2026/11/09/a?utm_source=bsky.app`, which answers `200`. Expect `resolved` with URL
    `https://www.npr.org/2026/11/09/a`, the hash `UrlNormalizer::hash()` of that, and only HEAD requests.
  - `testARedirectToABlockedArticleResolves`: `301`, then `403`. Expect `resolved`, and no GET.
  - `testASameSiteLongFormResolves`: `https://taz.de/!6227406` → `https://taz.de/Zehn-Jahre/!6227406/`. Expect
    `resolved`.
  - `testARedirectToATrackingVariantIsUnchanged`: `https://n.pr/x` → `https://n.pr/x?utm_medium=social`. Expect
    `unchanged`.
  - `testASlashOnlyRedirectResolves`: `https://t.example/abc` → `https://t.example/abc/`. Expect `resolved`.
  - `testNoRedirectIsUnchanged`: HEAD `200`. Expect `unchanged`, with one request.
  - `testARefusedHeadFallsBackToGet`: HEAD `429` at the same URL; GET `301` → `200`. Expect `resolved`, and requests
    HEAD, GET, GET.
  - `testAFailedHeadFallsBackToGet`: HEAD throws a `TransportException`; GET redirects. Expect `resolved`.
  - `testBothFailingIsUnresolved`: HEAD and GET both throw. Expect `unresolved`.
  - `testALoopIsUnresolved`: six `301`s. Expect `unresolved`.
  - `testAHopToAPrivateAddressIsUnresolved`: the resolver maps `internal.example` to `10.0.0.1`; `Location:
    https://internal.example/`. Expect `unresolved`, and no request to `internal.example`.
  - `testTheRequestCarriesTheBudgetAndAgent`: the first request's options hold `timeout` 3.0, the agent and the
    `Accept` header.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Content/LinkResolution/LinkLandingTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement per spec *Resolving one URL*. The GET fallback fires only when HEAD threw, or when HEAD
  landed on the start URL with a status outside 2xx. Cancel every landed response. Compare hashes of the cleaned
  landing and of the posted URL.
- [ ] **Step 4:** Run again. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2236): follow a short link to where it lands`.

### Task 4: Repository, budget, report and the sweep

**Files:**
- Create: `backend/src/Service/Content/LinkResolution/LinkResolutionSweep.php`,
  `backend/src/Service/Content/LinkResolution/Model/LinkResolutionBudgetModel.php`,
  `backend/src/Service/Content/LinkResolution/Model/LinkResolutionReportModel.php`
- Modify: `backend/src/Repository/SharedLinkRepository.php`
- Test: `backend/tests/Repository/SharedLinkRepositoryTest.php`,
  `backend/tests/Service/Content/LinkResolution/LinkResolutionSweepTest.php`,
  `backend/tests/Service/Content/LinkResolution/Model/LinkResolutionBudgetModelTest.php`

**Interfaces:**
- Consumes: `LinkLanding::of()`, `LinkLanding::MAX_SECONDS`, `LinkLandingModel` (Task 3), `LinkResolution` (Task 2).
- Produces:
  - `SharedLinkRepository::pendingResolutions(int $limit): array<int, string>`: URL by id, `resolution = 'pending'`,
    in id order;
  - `SharedLinkRepository::settleResolution(int $linkId, LinkLandingModel $landing): void`: one DBAL `UPDATE … WHERE
    id = ? AND resolution = 'pending'`;
  - `LinkResolutionBudgetModel`: `static links(int $count)` (negative throws `\InvalidArgumentException`), `static
    fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxLinks)`, `public int $links`,
    `fitsAfter(float $elapsedSeconds): bool`, `isEmpty(): bool`;
  - `LinkResolutionReportModel(int $considered, int $resolved, int $unchanged, int $unresolved, float $seconds)`, with
    `static none()`, `counted(LinkLandingModel $landing, float $seconds): self`, `plus(self $other): self` and
    `toLogContext(): array{considered: int, resolved: int, unchanged: int, unresolved: int, seconds: float}`;
  - `LinkResolutionSweep(SharedLinkRepository $sharedLinks, LinkLanding $landing, NaiveUtcClock $clock)::
    resolvePending(LinkResolutionBudgetModel $budget): LinkResolutionReportModel`.

  Add no interface for `LinkLanding`: the sweep test drives a real one over `MockHttpClient`, as in Task 3.

- [ ] **Step 1: Failing repository tests.**
  - `testPendingResolutionsAreReadInIdOrder`: three rows (pending, null, pending). The two pending URLs come back,
    lowest id first; the limit is honoured.
  - `testASettledRowStoresItsLanding`: settle `resolved(url, hash)`. The entity re-read gives `Resolved`, and DBAL
    reads `resolved_url` and `resolved_url_hash`.
  - `testASettledRowIsNotSettledAgain`: settle `resolved(A)`, then `resolved(B)`. A stays.
- [ ] **Step 2: Failing budget tests.** `links(5)->fitsAfter(0.0)` is true; `links(1)->fitsAfter(0.1)` is false
  (6.0 + 0.1 > 6.0); `fittingBefore(now + 13 s, now, 5)->links === 2`; `fittingBefore(now + 1 s, now, 5)->isEmpty()`.
- [ ] **Step 3: Failing sweep tests.**
  - `testPendingRowsAreSettled`: two pending rows with mocked chains (one redirect, one `200`). The report reads
    considered 2, resolved 1, unchanged 1, and both rows have left `pending`.
  - `testTheBudgetCapsThePass`: three pending rows and `links(2)`. Two are considered and one stays pending.
  - `testAnEmptyBudgetMakesNoRequest`: `links(0)` returns `none()` and the mock sees nothing.
- [ ] **Step 4:** Run them. Expected: FAIL.
- [ ] **Step 5:** Implement. The sweep times each resolution with the clock, stops before a resolution once
  `fitsAfter(elapsed)` is false, and settles after each one, so an interrupted pass loses nothing.
- [ ] **Step 6:** Run again. Expected: PASS.
- [ ] **Step 7:** Commit `feat(#2236): drain queued link resolutions within a budget`.

### Task 5: Run the drain in the worker and the maintenance tick

**Files:**
- Create: `backend/src/Service/Operations/Worker/Message/ResolvePendingLinks.php`,
  `backend/src/Service/Operations/Worker/Handler/ResolvePendingLinksHandler.php`,
  `backend/src/Service/Operations/Maintenance/MaintenanceSweep/LinkResolutionsTickSweep.php`,
  `backend/src/Http/SweepReportJson/LinkResolutionSweepJson.php`
- Modify: `backend/src/Service/Operations/Worker/WorkerSchedule.php` (append last)
- Test: `backend/tests/Service/Operations/Worker/ResolvePendingLinksHandlerTest.php`,
  `backend/tests/Service/Operations/Worker/WorkerScheduleWiringTest.php`, `backend/tests/Http/MaintenanceTickJsonTest.php`,
  `backend/tests/Service/Operations/Maintenance/MaintenanceTickTest.php`, plus `tests/Support/MaintenanceTickOverrides.php`
  if the tick test needs the new sweep stubbed

**Interfaces:**
- Consumes: `LinkResolutionSweep::resolvePending()`, `LinkResolutionBudgetModel`, `LinkResolutionReportModel`
  (Task 4).
- Produces: message `ResolvePendingLinks` (property-less, like `CompleteQueuedLinkCards`); a handler with `private
  const int LINKS = 5`, which logs `'Worker link resolution finished.'` with `['report' => toLogContext()]`; the tick
  sweep with index `linkResolutions`; JSON figures `considered`, `resolved`, `unchanged`, `unresolved` (ints).

- [ ] **Step 1: Failing tests.**
  - The handler test mirrors `CompleteQueuedLinkCardsHandlerTest`: one pending row, and the handler settles it and
    logs the message.
  - `WorkerScheduleWiringTest` expects `ResolvePendingLinks` as the **last** entry, every minute.
  - `MaintenanceTickJsonTest`: `'linkResolutions' => new LinkResolutionReportModel(31, 32, 33, 34, 1.5)` maps to
    `['considered' => 31, 'resolved' => 32, 'unchanged' => 33, 'unresolved' => 34]`; the idle report carries
    `skipped` like its neighbours.
  - `MaintenanceTickTest`: the `linkResolutions` sweep runs after `linkCards` (priority order).
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement, mirroring `CompleteQueuedLinkCardsHandler`, `LinkCardsTickSweep` (with
  `fittingBefore($deadline, $this->clock->now(), 5)`) and `LinkCardSweepJson`.
- [ ] **Step 4:** Run again, plus `php bin/console debug:container --tag=app.maintenance_sweep` (dev). Expected: PASS,
  and `linkResolutions` listed.
- [ ] **Step 5:** Commit `feat(#2236): resolve queued links in the worker and the maintenance tick`.

### Task 6: Trending reads the landing

**Files:**
- Modify: `backend/src/Repository/TrendingEntryRepository.php` (`chunkAfter`),
  `backend/src/Repository/TrendingStoryRepository.php` (`shownEntriesByStory`)
- Test: `backend/tests/Repository/TrendingEntryRepositoryTest.php`, `backend/tests/Repository/TrendingStoryRepositoryTest.php`,
  `backend/tests/Service/Curation/Trending/TrendingGroupingTest.php`

**Interfaces:**
- Consumes: the `resolved_url` and `resolved_url_hash` columns (Task 2).
- Produces: `card_url` = `COALESCE(link.resolved_url, link.url)` and `card_url_hash` =
  `COALESCE(link.resolved_url_hash, link.url_hash)`; the shown read's `url_hash` =
  `COALESCE(link.resolved_url_hash, link.url_hash)`. No PHP class in `Service/Curation/Trending` changes.

- [ ] **Step 1: Failing tests.** Fixture: a post whose card's link is `https://n.pr/x`, settled `resolved` to
  `https://www.npr.org/2026/11/09/a` with its hash (DBAL update); and an article-feed entry at that URL.
  - `TrendingEntryRepositoryTest::testACardReadsItsResolvedUrl`: the window card has the resolved `urlHash`, `url` and
    `site === 'npr.org'`.
  - `testAnUnresolvedCardReadsItsPostedUrl`: an `unresolved` row gives the posted URL and hash.
  - `TrendingGroupingTest::testAResolvedShortLinkSharesTheOutletsUrl`: the two entries come out in one group, with no
    headline overlap needed (give them unrelated titles).
  - `TrendingStoryRepositoryTest::testAShownPostIsKeyedByItsResolvedHash`: the shown model's `linkHashOf()` for the
    post is the resolved hash.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Change the two SQL strings.
- [ ] **Step 4:** Run them again, then `php bin/phpunit tests/Service/Curation/Trending tests/Repository`. Expected:
  PASS.
- [ ] **Step 5:** Commit `feat(#2236): key trending cards by their resolved link`.

### Task 7: One-off backfill

**Files:**
- Create: `backend/src/Command/ResolveSharedLinksCommand.php`,
  `backend/src/Service/Operations/LinkResolutionBackfill/SharedLinkResolutionBackfill.php`,
  `backend/src/Service/Operations/LinkResolutionBackfill/Model/LinkResolutionBackfillReportModel.php`
- Modify: `backend/src/Repository/SharedLinkRepository.php`
- Test: `backend/tests/Command/ResolveSharedLinksCommandTest.php`,
  `backend/tests/Service/Operations/LinkResolutionBackfill/SharedLinkResolutionBackfillTest.php`

**Interfaces:**
- Consumes: `ShortLinkShape::fits()`, `LinkResolutionSweep::resolvePending()`, `LinkResolutionBudgetModel::links(50)`
  and `LinkResolutionReportModel::plus()`.
- Produces:
  - `SharedLinkRepository::unqueuedUrlsAfterId(int $afterId, int $limit): array<int, string>` (`resolution IS NULL`,
    in id order);
  - `SharedLinkRepository::queueResolutions(list<int> $linkIds): void` (`UPDATE … SET resolution = 'pending' WHERE id
    IN (…) AND resolution IS NULL`);
  - `LinkResolutionBackfillReportModel(int $queued, LinkResolutionReportModel $resolutions)`;
  - `SharedLinkResolutionBackfill::count(): int` and `run(): LinkResolutionBackfillReportModel`, with batches of 500;
  - `app:shared-links:resolve`, described as "Queue and resolve every existing short-link shared link (one-off,
    #2236)", with `--dry-run`. Its output line is `Queued %d; resolved %d, unchanged %d, unresolved %d in %.1f s (%.2f
    s a link)`.

- [ ] **Step 1: Failing tests.** Use the kernel and a mocked HTTP client.
  - `testOnlyUnqueuedCandidatesAreQueued`: rows `https://n.pr/a` (null), `https://example.com/2026/a-story` (null) and
    `https://n.pr/b` (already `unchanged`). `count()` is 1, and `run()` queues 1 and settles it.
  - `testASecondRunQueuesNothing`: a second `run()` reports queued 0 and considered 0.
  - `testALeftoverPendingRowIsDrained`: a row already `pending` is settled by `run()`, though not counted as queued.
  - The command test: `--dry-run` writes nothing and prints the count; the real run prints the line.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement per spec *Backfill*. The drain loops `resolvePending(links(50))` until a pass considers
  0, and sums the reports.
- [ ] **Step 4:** Run again. Expected: PASS.
- [ ] **Step 5:** Commit `feat(#2236): add the one-off shared-link resolution backfill`.

### Task 8: Measure (A = develop 17edfbfeb, B = HEAD)

**Files:** none in the repository. Scratch goes in `scratchpad/2236/final/`. The research scripts are in
`scratchpad/2236/`, and the kit is in `scratchpad/measure-kit/` (`simp-explain-at.php`, `compare.py`); the #2235
language filter is `scratchpad/2235/run.sh`, and `scratchpad/2229/stories3.sql` and `langpairs.py` are the
who-is-shown and pair scripts.

- [ ] **Step 1: Snapshot.** One `mysqldump` of `feedreader`, restored into `feedreader_2236_a` and
  `feedreader_2236_b`. The `entry` and `shared_link` counts must be equal in both.
- [ ] **Step 2: B migrate and backfill.**
  - `doctrine:migrations:migrate` on B, timed; `doctrine:schema:validate` in sync.
  - `app:shared-links:resolve --dry-run` (expected about 1,130), then the run under `/usr/bin/time -l`, then a second
    run (expected queued 0).
  - Record queued, resolved, unchanged and unresolved; mean and p95 seconds a link (add a per-link timing to a throwaway
    copy if the report is not enough); and peak RSS.
  - List the unresolved rows by posted host, to spot `429`s.
  - If the run would exceed 10 minutes, split it: interrupt-safe, it resumes on rerun.
- [ ] **Step 3: Value.** On B, count the week rows whose `resolved_url_hash` equals an article feed's `entry.url_hash`
  (R2 predicts about a quarter of the resolved rows), and the week posts now sharing a URL hash with an outlet entry
  in the window. Split both by post language.
- [ ] **Step 4: Trending A/B.**
  - A is a scratch backend at 17edfbfeb (`git archive`, `vendor/` copied) against A; B is HEAD against B. Pin one
    time for both.
  - Run day and week, and the French-only and Spanish-only day and week, under `/usr/bin/time -l`; the headers must
    show equal entry counts.
  - Report groups and pairs, new / lost / net, per language pair (de–de, en–en, fr–fr, es–es, mixed or none).
  - Trace every changed group to a resolved row, or name its cause. A change with no named cause is a finding.
- [ ] **Step 5: Site count, duplicate rows, who is shown.**
  - Rebuild both snapshots at the pin.
  - List the stories whose distinct `article_site` count changed, and those crossing the two-sites rule.
  - For user 2, list the stories whose card has fewer article rows on B, checked by eye.
  - Give user 2's day and week top 15, A → B, by language.
- [ ] **Step 6: Eye check.** Mark 30 resolved rows right or wrong: at least 10 same-site, and every row a French or
  Spanish post carries. Also give every unresolved row's host and cause.
- [ ] **Step 7: SQLite and MySQL from empty.** Migrate from empty on both; the six FTS5 triggers are present on SQLite,
  and the schema validates. On MySQL, run `down` and then `up` again.
- [ ] **Step 8:** Drop `feedreader_2236_a` and `feedreader_2236_b`. Fill the spec's *Outcome* with every number and
  verdict above. Commit `docs(#2236): record the measured outcome`.

### Task 9: Gates

- [ ] **Step 1:** `rm -rf ~/.pdepend && composer md`, then
  `HOME=$(mktemp -d) vendor/bin/phpmd analyze <every touched src file> --no-progress`. Expected: no finding.
- [ ] **Step 2:** `composer check`. Expected: clean. If phptramp flags a chain, check
  `composer show larspohlmann/phptramp` first.
- [ ] **Step 3:** `composer test:parallel` and `docker compose exec -T php composer test:parallel`, at the same time.
  Expected: green.
- [ ] **Step 4:** `composer infection:diff`. Expected: at or above `minMsi`. Kill escaped mutants with cases.
- [ ] **Step 5:** PhpStorm inspections on the changed PHP. Expected: no ERROR or WARNING.
- [ ] **Step 6:** Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) for deprecations from the new
  code.
- [ ] **Step 7:** Write the PR notes in the ledger:
  - `Closes #2236`;
  - after the deploy, on dev Docker and on Strato: `app:shared-links:resolve --dry-run`, then the run;
  - check the worker's `Worker link resolution finished.` lines;
  - the follow-up commit deletes `ResolveSharedLinksCommand`, `Operations/LinkResolutionBackfill`, and
    `SharedLinkRepository::unqueuedUrlsAfterId` and `queueResolutions`.

  Do not open the PR or push.
