# Split Oversized Orchestrators: RefreshRunner, EntryIngestor, CatalogImporter, RestoreEntryLoader, BulkSubscriber (#1166) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1166 in three PRs, with no change in behaviour: identical refresh reports, identical ingested entries, identical catalog import results, identical restore results and identical bulk-subscribe results.
- **PR A** (Tasks A0–A5, `Refs #1166`): `RefreshRunner` drops from 14 collaborators to 9 and loses its `ExcessiveParameterList` suppression. `RefreshTally` is `public private(set)`. The tally-to-report unpacking moves into a per-pass `RefreshPass`. The `FeedBodyParser` locator stops leaking `ContainerExceptionInterface`. The four tests that assemble a `RefreshRunner` by hand share `tests/Support/RefreshRunners` (P4). `FeedFetcherInterface::fetch()` loses its two dead parameters (carry-forward).
- **PR B** (Tasks B0–B5, `Refs #1166`): `EntryIngestor::ingest()` hands entry construction to `IngestedEntryFactory` and image storage to `EntryImageWriter`. `CatalogImporter::import()` hands its indexes and counts to a per-import `CatalogImportPass`.
- **PR C** (Tasks C0–C3, `Closes #1166`): `RestoreEntryLoader` takes one `RestoreDestination` (the user and the feed targets) in the constructor, and `begin()` goes. `BulkSubscribeState` is replaced by a per-batch `BulkSubscribeBatch` with behaviour, plus `BulkSubscribePositions`.

**Architecture:**
- **Refresh (PR A).**
  - `RefreshRunner` keeps what a run is: take the lock, sweep orphans, find the due feeds, fetch them, record each outcome, look up favicons, report. It delegates the rest to three new services:
    - `FeedOutcomePersister::persist(Feed, FetchOutcome, \DateTimeImmutable $now): FeedRefreshResult` stores one feed's outcome and flushes it. It owns the four fetch-failure policies and the persistence-abort policy. It is today's `applyOutcome()` + `persistOutcome()` + `applyPermanentRedirect()`, split so that no method mixes parsing, bookkeeping and catch policy.
    - `MissingFaviconResolver::resolveFor(list<Feed>)` is today's `resolveMissingFavicons()`.
    - `RefreshHousekeeping` holds the maintenance-only work that is gated on `RefreshRequest::$prune`: `reclaimOrphanedFeeds()` and `pruneEntries()`.
  - `RefreshPass` is the per-run collaborator, following the `PageUrls` pattern. It holds the due feeds (indexed by id), the `BudgetedFeedQueue` and the `RefreshTally`, and it builds the three reports (`abortedDuringOutcomes()`, `abortedAfterOutcomes()`, `finished()`). `resolveFaviconsAndReport()` goes from five parameters to three, and the tally is unpacked in one place.
  - `FeedBodyParser::resolve()` turns a locator failure into a `\LogicException` (a wiring defect), so nothing above it declares `@throws ContainerExceptionInterface`.
- **Ingest (PR B).** `EntryIngestor::ingest()` maps every parsed item to an `IncomingEntry` (the item plus its guid hash and url hash, hashed once), dedupes on those, and asks `IngestedEntryFactory::create(Feed, IncomingEntry, FeedIngestContext): Entry` for each new row. The factory owns the `mb_substr` column limits (title 1024, author 255, url 2048), the snippet, the sanitiser and the media. `EntryImageWriter` owns "trusted at ingest, else pending", which both the factory and `fillMissingImages()` use.
- **Catalog (PR B).** `CatalogImporter::import()` opens a transaction, builds a `CatalogImportPass` from the rows it found, applies the document, removes the unmentioned rows in `Replace` mode, flushes, and returns `$pass->result`. The pass holds the four indexes as fields, and the category upsert is extracted like the feed upsert already was.
- **Backup (PR C).** `RestoreEntryLoaderFactory::create()` builds one `RestoreDestination` (the `User` and the `RestoreFeedTargets`) and passes it to the constructor beside the six services (P1). The two nullable fields and the two `LogicException('begin() must run…')` guards go.
- **Bulk subscribe (PR C).** `BulkSubscriber::subscribeAll()` opens a `BulkSubscribeBatch` (user, room under the cap, `BulkSubscribePositions`, the result) and passes only the batch and the item to its helpers. The batch answers "seen this URL?", "full?" and "which tag is this name?", and it records the result.

**Tech Stack:** PHP 8.4 (asymmetric visibility `public private(set)`), Symfony 7.4 (autowiring, keyed service locator), Doctrine ORM 3, PHPUnit 12 (attributes), PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection.

**Spec:**
- GitHub issue #1166 (`gh issue view 1166`): the six bullets (RefreshRunner, EntryIngestor, CatalogImporter, RestoreEntryLoader, BulkSubscriber/BulkSubscribeState, RefreshTally).
- Carry-forward (#1165 → #1166): "`FeedFetcherInterface::fetch` dead `$etag`/`$lastModified` params; add a RefreshRunner-level test pinning unconditional 304 → `fetched('')` → `recordFailure`". A1 pins it, A5 removes the parameters.
- Carry-forward (#1170 → #1166): "#1170 edits RestoreEntryLoader imports and RefreshRunner*Test ctor lines". #1170 merged before `d3196d49`, so this plan was written against its result. Nothing more to do.
- CLAUDE.md, "PHP code style — Clean Code is mandatory".

## Reconcile notes (6f88765d)

Reconciled on 2026-09-28 against `6f88765d` (origin/develop after #1182 PR B, #1162's four PRs and #1163's three). The drift itself is in "Reconciled at 6f88765d". Decisions the rulings did not cover:
- **D-reconcile-1:** P1's value is named `RestoreDestination`, not `RestoreTargets`. `RestoreTargets` differs from the existing `RestoreFeedTargets` by one word, and "destination" says what the value is: the account and the feeds a restore writes into. No class of that name exists at `6f88765d`.
- **D-reconcile-2:** `RestoreEntryLoader` unpacks the destination into its `$targets` and `$user` fields in the constructor instead of keeping the value. `$user` is re-acquired after every `clear()`, so a kept `$destination->user` would sit stale beside the live reference.
- **D-reconcile-3:** `RestoreDestination` carries `/** @noinspection AutowireWrongClass Built with new, never autowired */` on its `User` parameter, where #1182 B7 put it (`SubscribeOutcome`, `TickContext`). The Global Constraints line now names that placement instead of #1158's.
- **D-reconcile-4:** `RefreshRunners` is an immutable builder: `fromContainer()`, four named withers for the seams the sites actually swap (`flushingThrough`, `lockingWith`, `markingChangesOn`, `indexingInto`), and `build($feedFetcher, $homepageFetcher)`. A boolean or nullable-knob factory was the alternative that CLAUDE.md rules out.
- **D-reconcile-5:** `MaintenanceTickTest` moves from the container's `FeedBodyParser` to the helper's hand-built locator. `FeedBodyParserWiringTest` proves both route identically, and the test's RSS body parses the same either way. The `services_test.yaml` comment (A4 Step 8) therefore names `FeedOutcomePersisterTest` as the container parser's other reader.
- **D-reconcile-6:** the helper builds one `EntryIndexer` for the persister and the pruner, as the container does. `RefreshRunnerTest` built two over one writer. `EntryIndexer::forget()` never touches the memoised `configure()`, so every writer assertion reads the same.
- **D-reconcile-7:** the preflight diffs (A0 Step 3, B0 Step 2, C0 Step 2) and the reviewers' `git show` baselines start from `6f88765d`, not `d3196d49`. `RefreshRunner`, `EntryIngestor` and `BulkSubscriber` are byte-identical at both, but `src/Service/Ingest` is not (#1182 B9 moved `RedditEntryRule`'s `Discussion` import), which made B0's "empty `--stat`" false.

## Execution rulings (PR A)

- **D5' (planner, supersedes D5 for A3, B5 and C3):** no `public private(set)`. pdepend 2.16.2, the latest stable release behind `composer md`, cannot parse asymmetric visibility and aborts the whole sweep. Following the #183/#219/#220 standing decision (rewrite the syntax; no dev-pin, no exclude), the fields are `private` with same-named read methods and no `get` prefix (`fetched()` … `isAborted()`, `faviconEligibleFeeds()`; `result()` in B5 and C3). `record()` stays the only writer. If a codesize rule trips, the fix is tell-don't-ask, never a suppression.
- **A2:** `persistOutcome()`'s docblock is deleted whole. A lone `@throws \DateMalformedStringException` made PHPStan read `applyOutcome()`'s DBAL catch as dead. The wiring `LogicException` passes `previous: $e` instead of a `0` code literal, which leaves no equivalent code mutant (fd6e84c7).
- **A3:** an added `testFaviconFlushOrmExceptionAbortsTheRunWithoutThrowing` kills the escaped `| ORMException` arm of `resolveFaviconsAndReport()`'s catch.
- **A3:** `RefreshTally` reads its abort flag as `isAborted()`. An `is` prefix on a bool reads as allowed under D5'.
- **A4:** the gates forced three edits to plan code. `FeedOutcomePersister::record()` carries no `@throws` docblock (the A2 dead-catch case). `RefreshRunners::bodyParser()` has no `instanceof` guard (PHPStan: always true). `MissingFaviconResolverTest`'s HTML format string carries `/** @lang TEXT */`.
- **A4:** five killing tests were added to `FeedOutcomePersisterTest`:
  - the gone and failed paths reload the feed and assert its stored status and error;
  - a 304 is stored as a success with no new entries;
  - an `OptimisticLockException` flush aborts;
  - a redirect target is measured in characters.
- **A4:** `recordSuccess($feed, 0)` → `-1` in `storeNotModified()` is an equivalent mutant: `FeedScheduler::recordSuccess()` only tests `> 0`, and the line moved unchanged from `RefreshRunner`. It was reported to the planner, with no ignore added.
- **Final review and /simplify:**
  - `RefreshPass` builds its own `BudgetedFeedQueue` (`__construct(list<Feed>, ClockInterface, int $deadline)`), so the feed list is handed over once.
  - The abort tests share a generalised `FlushFailingEntityManager` (which flush fails, and what it throws) and a new `tests/Support/DuplicateKeyViolation`.
  - `tests/Support/FeedSchedulers` builds the test `FeedScheduler`. PR B's B1 moves `SubscriptionServiceTest` and `FirstFetchRecorderTest` onto it.
  - The change-marker pin merged into `testEntityManagerFailureAbortsRunWithoutCascading`.
  - `EntryIndexer`'s docblock names `FeedOutcomePersister`.

## Status

| Task | State |
|---|---|
| A0: Preflight (#1182, #1162, #1163 merged) | ⬜ |
| A1: Pin the refresh reports, the cooldown and the not-modified handling | ⬜ |
| A2: `FeedBodyParser` stops leaking the container exceptions | ⬜ |
| A3: `RefreshTally` is `public private(set)`; `RefreshPass` builds the reports | ⬜ |
| A4: `FeedOutcomePersister`, `MissingFaviconResolver`, `RefreshHousekeeping`; the suppression goes; the runner tests share `RefreshRunners` | ⬜ |
| A5: `FeedFetcherInterface::fetch()` loses its dead parameters | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: Pin the feed metadata limits; the ingest tests share `EntryIngestors` | ⬜ |
| B2: `EntryImageWriter` | ⬜ |
| B3: `IncomingEntry` and `IngestedEntryFactory`; `EntryIngestor` is `final readonly` | ⬜ |
| B4: Pin the catalog import's update, lock and move paths | ⬜ |
| B5: `CatalogImportPass` | ⬜ |
| C0: Preflight (PR B merged) | ⬜ |
| C1: `RestoreEntryLoader` takes one `RestoreDestination` in the constructor | ⬜ |
| C2: Pin the bulk-subscribe positions, the cap and the tag-name limit | ⬜ |
| C3: `BulkSubscribeBatch` and `BulkSubscribePositions` replace `BulkSubscribeState` | ⬜ |

## Scope

| Issue bullet or carry-forward item | Task |
|---|---|
| RefreshRunner: 14 collaborators and `@SuppressWarnings("PHPMD.ExcessiveParameterList")` | A4 (9 collaborators, suppression gone) |
| RefreshRunner: `persistOutcome()` mixes parse, ingest, header bookkeeping, flush, index and four catch policies | A4 (`FeedOutcomePersister`: `persist()` holds the abort policy, `record()` the fetch policies, `storeFetched()` the bookkeeping, flush and index) |
| RefreshRunner: `$tally` unpacked field by field into `RefreshReport::aborted/finished` twice | A3 (`RefreshPass`) |
| RefreshRunner: `@throws ContainerExceptionInterface` leaks the `FeedBodyParser` locator | A2 |
| RefreshRunner: the class comment says "thirteen" collaborators | A4 (the docblock is rewritten, with no count) |
| RefreshTally: public mutable fields written only by `record()` | A3 (`public private(set)`) |
| EntryIngestor: `ingest()` mixes dedup bookkeeping, entity construction with inline `mb_substr` truncations, and persistence | B2, B3 |
| CatalogImporter: a 70-line closure; inline three-branch category upsert; `removeUnmentioned()` takes 4 arrays plus the result | B5 |
| RestoreEntryLoader: temporal coupling through `begin()` | C1 |
| BulkSubscriber: `$user`, `$state`, `$result` threaded through private helpers; `BulkSubscribeState` a bag of public mutable arrays | C3 |
| Carry-forward #1165: dead `$etag`/`$lastModified` on `FeedFetcherInterface::fetch()` | A5 |
| Carry-forward #1165: RefreshRunner-level pin of "unconditional 304 → empty fetch → failure" | A1 |
| Carry-forward #1170: RestoreEntryLoader imports and RefreshRunner*Test ctor lines | Already in `d3196d49`; nothing to do |
| Planner ruling P4: four hand-built `RefreshRunner` assemblies, three hand-built `FeedBodyParser` locators | A4 (`tests/Support/RefreshRunners`) |

### Characterization tests (they must stay green, unmodified except where a task says so)

- **Refresh:** `tests/Service/Refresh/RefreshRunnerTest.php` (all 41 tests, notably `testRefreshesDueFeedsAndReports`, `testEntityManagerFailureAbortsRunWithoutCascading`, `testFaviconFlushFailureIsReportedAsAbortedNotThrown`, `testForeignKeyViolationFromAVanishedFeedAbortsTheRunWithoutThrowing`, `testBudgetExhaustionSkipsFeedsThatWereNeverStarted`, `testThrottledFeedIsNotCountedAsRemaining`, `testSingleFeedRunReportsNothingRemaining`, `testAllDueRunPrunesOldEntries`, `testUserScopedRunDoesNotPrune`, `testIndexesFetchedEntriesWithTheirRealIdsAfterFlush`, `testARefreshSinksAnArticleTheFeedServedBeforeTheLastFetch`, `testRefreshBackfillsTheImageOntoAnAlreadyStoredEntryThatLacksOne`, the four permanent-redirect tests, the three change-marker tests), `RefreshRunnerConcurrentFetchTest`, `RefreshRunnerOrphanSweepTest`, `TrackedRefreshRunnerTest`, `RefreshReportTest`, `FeedBodyParserWiringTest`, `tests/Service/Maintenance/MaintenanceTickTest.php`, `tests/Controller/Api/RefreshControllerTest.php`, `tests/Controller/MaintenanceControllerTest.php`, `tests/Service/Worker/RefreshDueFeedsHandlerTest.php`, `tests/Command/RefreshFeedsCommandTest.php`, `tests/Service/Fetch/HttpFeedFetcherTest.php`, `tests/Service/Fetch/ConcurrentFeedFetcherTest.php`, `tests/Http/RefreshReportJsonTest.php` and `tests/Http/MaintenanceTickJsonTest.php` (the wire, from #1182 B3).
- **Ingest:** `tests/Service/Ingest/EntryIngestorTest.php` (notably `testOverlongFieldsAreTruncatedToColumnLimits`, the image and media tests, the dedup tests), `EntryIngestorCategoriesTest`, `tests/Service/FillMissingImagesTest.php`, `tests/Service/Ingest/Platform/PlatformEntryRulesWiringTest.php`, `tests/Service/Subscription/FirstFetchRecorderTest.php`, `SubscriptionServiceTest`.
- **Catalog:** `tests/Service/Catalog/CatalogImporterTest.php`, `tests/Command/ImportCatalogCommandTest.php`, `tests/Controller/Admin/AdminCatalogImportControllerTest.php`.
- **Backup:** `tests/Service/Backup/RestoreEntryLoaderTest.php`, `EntryPartRestorerTest`, `AccountRestorerTest`, `GoldenBackupRestoreTest`, `EntryMediaBackupRoundTripTest`, `RestoreLoadPassTest`.
- **Bulk subscribe:** `tests/Service/Subscription/BulkSubscriberTest.php`, `tests/Service/Opml/OpmlImporterTest.php`, `tests/Service/Catalog/CatalogSubscriberTest.php`, `tests/Controller/Api/OnboardingControllerTest.php`, `tests/Controller/Api/OpmlControllerTest.php`.

Behaviour these suites did not pin is pinned first, in the task before the refactor (A1, B1, B4, C2), and in the refactor task's own new tests where the pin needs the new class.

## Design decisions

- **D1: `RefreshRunner` keeps 9 collaborators.** PHPMD's `ExcessiveParameterList` fires at 10. `FeedOutcomePersister` absorbs the EntityManager, body parser, ingestor, scheduler and indexer, and `MissingFaviconResolver` absorbs the favicon resolver. `RefreshHousekeeping` groups the two collaborators that only a pruning run uses (`OrphanedFeedReclaimer`, `EntryPruner`); both are gated on the same `RefreshRequest::$prune` flag, so they belong together. The logger stays, because the runner logs the favicon abort. The class docblock that defended "thirteen collaborators" goes.
- **D2: `FeedOutcomePersister::persist()` builds the `FeedIngestContext` and calls a `record()` that calls the ingestor directly.** Two constraints set this shape:
  - PHP: a flush inside a `catch` block cannot be caught by a sibling `catch` of the same `try`. The persistence-abort catch therefore wraps a second method, which is what `applyOutcome()` wrapping `persistOutcome()` did.
  - phptramp: the ingest context must not be forwarded through more than two methods, or PR B's `ingest() → IngestedEntryFactory::create()` hop makes the chain four long. Parsing and ingesting stay in `record()`, and `storeFetched(Feed, FetchResponse, list<Entry>)` takes the created entries, not the context. Chains: `$now` has 2 hops (`RefreshRunner::persistOutcomes`, `FeedOutcomePersister::persist`); the context has 1 in PR A and 2 in PR B.
- **D3: the two aborted reports stay distinct.** An abort while outcomes are being persisted leaves `count(feeds) - processed` remaining; an abort in the favicon phase leaves `queue->skippedCount()` remaining. They are equal whenever the fetcher yields one outcome per ticket (its contract), but the plan keeps both formulas exactly as they are, and `RefreshPassTest` pins each one in a case where they differ.
- **D4: `RefreshPass` is `final readonly` with a public `RefreshTally $tally`.** The tally is the mutable part (`public private(set)`, D5), and the pass is the per-run collaborator that owns it, the way `PageUrls` owns its URL.
- **D5: `public private(set)`** for `RefreshTally`, `CatalogImportPass::$result` and `BulkSubscribeBatch::$result`. The codebase has no earlier use; PHP 8.4 supports it, and the issue names it.
- **D6: a wiring defect in `FeedBodyParser` is a `\LogicException`,** like `HttpFeedFetcher`'s "the fetcher returned no outcome". It cannot happen in a correctly built container (`xml` is always registered, `FeedBodyParserWiringTest` proves it), so it gets no typed domain exception and no HTTP mapping.
- **D7: `IncomingEntry` hashes each item once.** Today `ingest()` hashes every item's guid and url twice (once to seed the deduplicator, once in the loop). The values are identical, so behaviour does not change. `fillMissingImages()` keeps its own `guidHashesOf()`: it reads the raw parse, without platform rules, as today.
- **D8: `EntryIngestor` keeps its own `SITE_URL_MAX = 2048`,** and `IngestedEntryFactory` its own `URL_MAX = 2048`. They bound different columns (`feed.site_url`, `entry.url`) that happen to have the same length.
- **D9: sets keyed by a natural key map to the row that put the key there** (`CatalogImportPass::$mentionedFeeds`, `$mentionedCategories`, the locked-feed map; `BulkSubscribeBatch::$subscriptionsByUrl`), not to `true`. A literal `true` read only through `isset()` is an equivalent `TrueValue` mutant, and `infection.json5` already carries one such ignore. The plan adds no ignore (Global Constraints).
- **D10: `SubscriptionLimitResolver::resolve()` is called once per batch, not once per item.** It is a pure read of `User::getMaxSubscriptions()` with a constant fallback, and a user's cap cannot change mid-batch, so every result stays the same. The batch keeps the room left (`limit - existing`) and decrements it, which is `existing >= limit` rewritten.
- **D11: `RestoreEntryLoader` takes 7 constructor parameters:** the 6 services and one `RestoreDestination` (P1). The destination is a `final readonly` value in `Service/Backup`, next to `RestoreFeedTargets`, holding the `User` and the `RestoreFeedTargets`. `RestoreEntryLoaderFactory` builds it and the loader with `new`; the loader is a per-request worker that nothing else builds.
- **D12: comments.** Every comment this plan writes is at the bar: default none, at most three lines, and only where a future reader would otherwise get the code wrong. A docblock the plan edits is brought to three lines or fewer, and a docblock the change makes false is edited. A docblock the plan does not edit stays as it is, even in a file shown in full: `EntryIngestor` (B3) keeps its class, `FEED_DESCRIPTION_MAX`, `ingest()` and `fillMissingImages()` docblocks verbatim.
- **D13: `tests/Support/EntryIngestors`** builds the `EntryIngestor` that eight test files assembled by hand. A4 creates it for `RefreshRunners` (which replaces the four runner builders, D14) and the new persister test; B1 moves the other four sites onto it. After that, B2 and B3 change one file when the ingestor's constructor changes.
- **D14: `tests/Support/RefreshRunners`** (P4) builds the `RefreshRunner` that `RefreshRunnerTest`, `RefreshRunnerConcurrentFetchTest`, `RefreshRunnerOrphanSweepTest` and `MaintenanceTickTest` assembled by hand, over the hand-built `FeedBodyParser` locator the first three copied. It is an immutable builder: `fromContainer($container, $em, $clock)`, the withers `flushingThrough()`, `lockingWith()`, `markingChangesOn()` and `indexingInto()`, and `build($feedFetcher, $homepageFetcher)`. Those are exactly the seams the four sites swap; everything else is fixed.

## Planner rulings (2026-09-27), applied at the 2026-09-28 reconcile

- **P1 ACCEPTED WITH A CONDITION:** the constructor may carry the collaborators, but the per-restore data (targets and user) arrives as ONE value, e.g. `RestoreTargets` holding the user and the target maps. The factory builds it. No two loose per-request parameters beside the services. *Applied:* D11, Task C1 (`RestoreDestination`, D-reconcile-1 and -2).
- **P2 ACCEPTED:** the two aborted-report `remaining` formulas stay separate. *Applied:* D3, Task A3.
- **P3 ACCEPTED:** the cap is read once per batch. *Applied:* D10, Task C3.
- **P4 OVERRULED:** A4 already rewrites all four hand-built `RefreshRunner` assemblies, and four is past the third occurrence. Add a `tests/Support/RefreshRunners` helper in A4 (next to `EntryIngestors`) that builds the runner and the body-parser setup. The four sites and the three body-parser copies use it. Show it in full. *Applied:* D14, Task A4 Step 7.
- **P5 ACCEPTED:** delete the stale "see RefreshRunner" clause only if #1162 left it in. *Applied:* #1162 did not leave it in (the advancer is now `src/Service/Recommendation/Run/RecommendationRunAdvancer.php` and names no `RefreshRunner`), so A4 has no Step 10 and touches no `Service/Recommendation` file.
- D2 (the ingest context passes through two methods at most) and D9 (key maps hold the row, not `true`) are ACCEPTED.

## Not in scope

- `RefreshReport`'s positional factories (`finished()` takes 8 ints, `aborted()` 6). #1182 B3's tests call them, and `RefreshPass` is now their only production caller.
- `DueFeedCriteria`'s 6-parameter constructor (a repository value, not named by the issue).
- `EntryDeduplicator`'s `array<string, true>` sets (not touched, so not mutated).
- The long comments in `FirstFetchRecorder`, `EntryIndexer`, `MaintenanceTick`, `Feed` and `RefreshJson` that mention `RefreshRunner`. They stay true (the refresh still indexes once per feed, still resolves favicons, still owns `BATCH_LIMIT`), except the one line in `FirstFetchRecorder` that A4 edits.
- Any change to `Service/Reader`, `Service/Reading` or `Service/Recommendation`.

## Wire changes

None. Every response body, status code and header stays byte-identical:
- `RefreshReport` is not edited. Its JSON (`RefreshReportJson`, `RefreshJson`) and its log map (`toLogContext()`) are built from the same nine fields, which `RefreshPass` fills with exactly the values the runner passed before. `RefreshControllerTest`, `MaintenanceControllerTest`, `RefreshReportJsonTest` and `MaintenanceTickJsonTest` pin the shapes.
- `CatalogImportResult`, `BulkSubscribeResult` and the restore counts are unchanged types built from the same increments. `AdminCatalogJson::importResult()`, `OpmlJson` and `OnboardingJson` are not touched.
- No frontend file changes, so `npm run check` is not a gate.

## Reconciled at 6f88765d

Written at `d3196d49`; reconciled on 2026-09-28 against `6f88765d`, where #1182 PR B (#1194), #1162 (#1195, #1196, #1197, #1200) and #1163 (#1201, #1203, #1204) have merged. Every before-block in this plan was matched verbatim against `6f88765d`. `src/Service/Refresh/RefreshRunner.php`, `RefreshTally.php`, `FeedBodyParser.php`, `src/Service/Ingest/EntryIngestor.php`, `EntryMediaAssembler.php`, `src/Service/Catalog/CatalogImporter.php`, `src/Service/Backup/RestoreEntryLoader.php`, `RestoreEntryLoaderFactory.php`, `RestoreFeedTargets.php`, `src/Service/Subscription/BulkSubscriber.php`, `BulkSubscribeState.php`, `src/Service/Fetch/FeedFetcherInterface.php`, `HttpFeedFetcher.php`, `ResponseClassifier.php` and every test this plan anchors on except the first three rows below are byte-identical to `d3196d49`. Locate every edit by its text, never by a line number.

| File | State at `6f88765d` | What this plan does with it |
|---|---|---|
| `tests/Service/Maintenance/MaintenanceTickTest.php` | #1182 B3 (b75e108d): both tests end in typed assertions (`$report->refresh->isAborted()`, `MaintenanceSweeps::skippedAfterAbortedRefresh()`, `new LokiSpoolReport(0, 0)`), with the `LokiSpoolReport` and `MaintenanceSweeps` imports, and the abort test's docblock is rewritten (it no longer names the runner's EntityManager parameter). #1162 (5dc4bb49, c65894da): `use App\Service\Recommendation\Run\ForYouSweep;`, imports sorted. The `RefreshRunner` assembly is unchanged. | A4 Step 7 replaces the assembly with `RefreshRunners` and removes the imports it leaves unused. The docblock is left alone: #1182 already made it true. |
| `tests/Service/Ingest/EntryIngestorTest.php` | #1182 B9: `use App\Entity\Discussion;`, imports sorted. `ingestorWith()` unchanged. | B1 removes eight imports (by pattern, so the order does not matter), adds one, rewrites `ingestorWith()` and adds two tests. |
| `tests/Service/Subscription/SubscriptionServiceTest.php` | #1182 B9: `use App\Service\Discovery\ScrapeFallback;`, the two `Subscription\Exception` imports sorted. The `new EntryIngestor(…)` argument is unchanged. | B1 removes eight imports, adds one and replaces the `new EntryIngestor(…)` argument. |
| `src/Service/Refresh/RefreshReport.php` | #1182 B3: `toArray()` is `toLogContext()`, same body; `busy()`, `finished()`, `aborted()` unchanged. | Not touched. `RefreshPass` calls `finished()` and `aborted()`. |
| `src/Http/RefreshReportJson.php`, `src/Http/MaintenanceTickJson.php` and their tests | Created by #1182 B3. | Not touched; their tests are characterization for PR A. |
| `src/Service/Refresh/RefreshRunProgress.php`, `RefreshRunStore.php`, `src/Http/RefreshJson.php` | #1182 B4: `RefreshRunProgress::toArray()` is gone; the store writes the array itself. | Not touched. |
| `config/services_test.yaml` | #1162 renamed the `App\Service\Recommendation\…` entries and added `Run\TickPhases`. The `FeedBodyParser` block is unchanged. | A4 Step 8 rewrites the comment above `App\Service\Refresh\FeedBodyParser:`. |
| `src/Service/Recommendation/RecommendationRunAdvancer.php` | Moved by #1162 to `src/Service/Recommendation/Run/RecommendationRunAdvancer.php` and cut to 154 lines; no "see RefreshRunner for the same shape" clause remains (`git grep -n "RefreshRunner" 6f88765d -- src/Service/Recommendation` prints nothing). | Nothing: P5's condition is not met, so A4's Step 10 is gone. |
| `src/Service/Ingest/Platform/RedditEntryRule.php` | #1182 B9: `use App\Entity\Discussion;`. | Not touched. It is why B0 Step 2 diffs from `6f88765d` (D-reconcile-7). |
| `src/Service/Reader/**`, `src/Service/Reading/**`, `src/Service/Html/HtmlDocumentParser.php`, `src/Service/Tag/TagOrdering.php` | #1163: reading state moved to `Service/Reading` (`ReadScope`), `ExactSetGuard` to `Service/Tag`, `HtmlDocumentParser::parse()` throws a typed exception, and `ArticleExtractorInterface::extract()` takes `EntryHints`. | Not touched. None of it reaches `Service/Ingest`, `Service/Refresh` or `Service/Scraper`: `ScrapedBodyParser` still calls `HtmlItemExtractor::extract(string $html, string $baseUrl)`, and `ArticleExtractor` still calls `$this->fetcher->fetch($url)` on its `HtmlPageFetcher` (A5 Step 1's six lines hold). |

Nothing here names a class #1182 B9, #1162 or #1163 moved: the new code imports no `Discussion`, `ScrapeFallback`, `SealedSecret`, `ForYouSweep` or `Service/Reading` value, and `BulkSubscriber` uses neither `FeedMove` (formerly `TagMove`) nor `ExactSetGuard`. The re-check is Task A0 Step 3.

## Global Constraints

- **Paths and commands are relative to `backend/`**, except the preflight steps marked "from the repository root" and `docs/…`.
- **Behaviour does not change.** Same refresh reports (every one of the nine fields, in every branch), same ingested entries (the `mb_substr` lengths: entry title 1024, author 255, url 2048; feed title 512, site url 2048, description 4000; snippet 500), same catalog import results, same restore results, same bulk-subscribe results. No wire change.
- **Clean Code (CLAUDE.md) is mandatory:**
  - Names reveal intent.
  - No boolean flag parameters.
  - Three parameters at most (constructors of services, of per-pass objects and of the `RefreshRunners` builder aside; see D1, D11, D14).
  - Guard clauses over nesting.
  - `final readonly` by default. `final class` only for the mutable per-pass objects: `RefreshTally`, `CatalogImportPass`, `BulkSubscribeBatch`, `BulkSubscribePositions`.
  - Queries live in `src/Repository`. This plan adds no query.
  - Typed exceptions live in `Service/*/Exception` (D6 explains the one `\LogicException`).
- **Comments (D12):** default none, at most three lines. No `@param`/`@return` that repeats the signature, unless PHPStan needs the array shape.
- **PSR-12 line length: 120 columns.** Every line in this plan fits.
- **Tests:** PHPUnit 12 attributes; per-field `assertSame`, never `assertEquals` on values; persisted ids through `requireId()` (`EntityIdCoercionRule` covers `tests/`); the existing helpers (`DbTestCase`, `SeedsUsers`, `ReloadsEntities`, `RecordingLogger`, `RecordingContentChangeMarker`, `StubFeedFetcher`, `NoEgressProxy`, `TtlRecordingLockFactory`, `MembershipSweepFactory`) and the new `EntryIngestors` (D13) and `RefreshRunners` (D14).
- **Every touched `src` file is PHPMD-clean** under `composer md`. Fix the design, never the threshold. After A4, `RefreshRunner` carries no suppression.
- **phptramp:** no chain of 4+ hops across 2+ classes forwards an unread parameter (D2). Run `composer tramp` in every task that changes a signature.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`.
- **PhpStorm inspections** on every changed PHP file (`mcp__phpstorm__lint_files`): ERROR and WARNING block. A Symfony-plugin `AutowireWrongClass` false positive on a value or per-pass object built with `new` (`RefreshPass`, `IncomingEntry`, `CatalogImportPass`, `BulkSubscribeBatch`, `BulkSubscribePositions`) gets `/** @noinspection AutowireWrongClass Built with new, never autowired */` on the flagged constructor parameter (or on the constructor), where #1182 B7 put it in `SubscribeOutcome` and `TickContext`. `RestoreDestination` carries it from the start, on its `User` parameter (D-reconcile-3).
- **Test-first where an API changes; pin-first where behaviour must not.** A pin passes on develop and must keep passing: the step that first runs it says "Expected: PASS (pin)". That is intended.
- **Every new test gets a deletion check.** Break the production line it covers, run the test, watch it fail, then restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report.
- **Infection.** An escaped mutant on a line this PR touched gets a killing test in the task that owns the line. A provably equivalent mutant is removed by rewriting the line, or reported to the planner. Never add an `ignore`, never lower `minMsi`.
- **Gates for every task:** the task's tests, `composer check` (run `bin/console cache:warmup` first if the dev cache is cold), `composer md`, and the PhpStorm inspections.
- **Commits:** `refactor(#1166): <lower-case summary>`, one per task, no attribution lines. The plan copy is committed the same way.
- **Branches, each cut from `origin/develop` after the previous PR merges:**
  - PR A: `refactor/1166-refresh-runner-split`
  - PR B: `refactor/1166-ingest-and-catalog-split`
  - PR C: `refactor/1166-restore-and-bulk-subscribe`
- **PR bodies.** PR A and PR B say `Refs #1166`. Neither their bodies, their prose nor any commit message on either branch may contain "close", "closes", "closed", "fix", "fixes", "fixed", "resolve", "resolves" or "resolved" in any form (this plan's commit messages avoid them; `MissingFaviconResolver` and `resolveFor` do not match the word-boundary check in the Finishing steps). PR C's body says `Closes #1166`.
- **The checkout is shared.** Run `git status --short` and `git branch --show-current` before any `switch`, `reset` or `stash`; another session may be mid-edit. Work in place, with no worktrees.

---

# PR A — The refresh runner splits

### Task A0: Preflight (#1182, #1162, #1163 merged)

**Files:** `docs/superpowers/plans/2026-09-27-1166-split-oversized-orchestrators.md` (the plan copy). No code changes.

- [ ] **Step 1: Confirm the queue ahead has landed and the checkout is free (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1182 --json state --jq .state
gh issue view 1162 --json state --jq .state
gh issue view 1163 --json state --jq .state
gh issue view 1166 --json state --jq .state
```
Expected:
- A clean tree, or only another session's files, which you leave alone.
- `CLOSED`, `CLOSED`, `CLOSED`, then `OPEN`.

If any of the three is still open, stop and report: this plan is queued behind them.

- [ ] **Step 2: Cut the branch and commit the plan copy (from the repository root)**

```bash
git switch -c refactor/1166-refresh-runner-split origin/develop
mkdir -p docs/superpowers/plans
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1166-draft.md docs/superpowers/plans/2026-09-27-1166-split-oversized-orchestrators.md
git add docs/superpowers/plans/2026-09-27-1166-split-oversized-orchestrators.md
git commit -m "refactor(#1166): add the implementation plan"
```

- [ ] **Step 3: Re-check the reconcile (from `backend/`)**

The plan was reconciled at `6f88765d` ("Reconciled at 6f88765d"). Confirm nothing it anchors on moved since:
```bash
git diff --stat 6f88765d origin/develop -- \
  src/Service/Refresh src/Service/Ingest src/Service/Catalog/CatalogImporter.php \
  src/Service/Backup/RestoreEntryLoader.php src/Service/Backup/RestoreEntryLoaderFactory.php \
  src/Service/Backup/RestoreFeedTargets.php src/Service/Subscription/BulkSubscriber.php \
  src/Service/Subscription/BulkSubscribeState.php src/Service/Subscription/FirstFetchRecorder.php \
  src/Service/Fetch/FeedFetcherInterface.php src/Service/Fetch/HttpFeedFetcher.php \
  src/Service/Fetch/ResponseClassifier.php config/services_test.yaml tests/Service/Refresh tests/Service/Ingest \
  tests/Service/Catalog/CatalogImporterTest.php tests/Service/Backup/RestoreEntryLoaderTest.php \
  tests/Service/Subscription tests/Service/Maintenance/MaintenanceTickTest.php tests/Support \
  tests/Service/Fetch/HttpFeedFetcherTest.php
git grep -n -E "RestoreDestination|RefreshRunners" origin/develop -- src tests
```
Expected:
- The `--stat` prints nothing.
- The grep prints nothing: neither name this plan introduces exists yet.

A file in the `--stat` is fine when its change is an import or rename that no step of this plan anchors on. If any before-block in this plan no longer matches develop, stop and report: the plan has to be reconciled again.

---

### Task A1: Pin the refresh reports, the cooldown and the not-modified handling

**Files:**
- Create: `tests/Service/Refresh/RefreshTallyTest.php`
- Modify: `tests/Service/Refresh/RefreshRunnerTest.php` (three new tests, two assertions in `testFaviconFlushFailureIsReportedAsAbortedNotThrown`)
- Modify: `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php` (two new tests, one import)

**Interfaces:** none; every test here passes on develop.

What these pin that nothing pinned before:
- an abort mid-run carries `notModified` and `throttled` into the report, and `remaining` is "not processed", not "not started";
- entries committed before an abort still move the change marker (#720: the check sits before the abort branch);
- the favicon-phase abort reports `skippedForBudget` 0 and `remaining` 0 for a run that started every feed;
- a forced refresh honours the 5-minute cooldown on both sides of the boundary (the runner builds `DueFeedCriteria`, and A4 moves that code);
- through the real fetch engine, a 304 to an unconditional request is an empty fetch that fails the parse (carry-forward #1165), while a 304 to a conditional request is `notModified`;
- `RefreshTally::record()` itself.

- [ ] **Step 1: Write `RefreshTallyTest`**

`tests/Service/Refresh/RefreshTallyTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\FeedOutcome;
use App\Service\Refresh\FeedRefreshResult;
use App\Service\Refresh\RefreshTally;
use PHPUnit\Framework\TestCase;

final class RefreshTallyTest extends TestCase
{
    public function testAFreshTallyCountsNothing(): void
    {
        $tally = new RefreshTally();

        self::assertSame(0, $tally->fetched);
        self::assertSame(0, $tally->notModified);
        self::assertSame(0, $tally->failed);
        self::assertSame(0, $tally->throttled);
        self::assertSame(0, $tally->processed);
        self::assertSame(0, $tally->entriesCreated);
        self::assertFalse($tally->aborted);
        self::assertSame([], $tally->faviconEligibleFeeds);
    }

    public function testEachOutcomeLandsInItsOwnCount(): void
    {
        $tally = new RefreshTally();

        $tally->record(FeedRefreshResult::fetched(3), new Feed('https://a.example.com/feed'));
        $tally->record(FeedRefreshResult::of(FeedOutcome::NotModified), new Feed('https://b.example.com/feed'));
        $tally->record(FeedRefreshResult::of(FeedOutcome::Failed), new Feed('https://c.example.com/feed'));
        $tally->record(FeedRefreshResult::of(FeedOutcome::Throttled), new Feed('https://d.example.com/feed'));

        self::assertSame(1, $tally->fetched);
        self::assertSame(1, $tally->notModified);
        self::assertSame(1, $tally->failed);
        self::assertSame(1, $tally->throttled);
        self::assertSame(4, $tally->processed);
        self::assertSame(3, $tally->entriesCreated);
        self::assertFalse($tally->aborted);
    }

    public function testAnAbortedFeedCountsAsFailedButNotAsProcessed(): void
    {
        $tally = new RefreshTally();
        $fetched = new Feed('https://a.example.com/feed');

        $tally->record(FeedRefreshResult::fetched(2), $fetched);
        $tally->record(FeedRefreshResult::of(FeedOutcome::Aborted), new Feed('https://b.example.com/feed'));

        self::assertSame(1, $tally->failed);
        self::assertSame(1, $tally->processed);
        self::assertSame(2, $tally->entriesCreated);
        self::assertTrue($tally->aborted);
        self::assertSame([$fetched], $tally->faviconEligibleFeeds);
    }

    public function testOnlyFeedsThatBroughtContentAreFaviconEligible(): void
    {
        $tally = new RefreshTally();
        $fetched = new Feed('https://a.example.com/feed');
        $unchanged = new Feed('https://b.example.com/feed');

        $tally->record(FeedRefreshResult::fetched(0), $fetched);
        $tally->record(FeedRefreshResult::of(FeedOutcome::Failed), new Feed('https://c.example.com/feed'));
        $tally->record(FeedRefreshResult::of(FeedOutcome::NotModified), $unchanged);
        $tally->record(FeedRefreshResult::of(FeedOutcome::Throttled), new Feed('https://d.example.com/feed'));

        self::assertSame([$fetched, $unchanged], $tally->faviconEligibleFeeds);
    }
}
```

- [ ] **Step 2: Add the runner pins to `RefreshRunnerTest`**

In `tests/Service/Refresh/RefreshRunnerTest.php`, inside `testFaviconFlushFailureIsReportedAsAbortedNotThrown()`, replace:
```php
        self::assertSame(1, $report->fetched);
        self::assertSame(0, $report->pruned);
    }
```
with:
```php
        self::assertSame(1, $report->fetched);
        self::assertSame(0, $report->pruned);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(0, $report->remaining);
    }
```

Directly before `public function testLockIsReleasedAfterAnAbortedRun(): void`, add:
```php
    public function testAnAbortCarriesEveryCountTheRunReachedAndLeavesTheUnprocessedRemaining(): void
    {
        $unchanged = $this->dueFeed('https://one.example.com/feed');
        $rationed = $this->dueFeed('https://two.example.com/feed');
        $failing = $this->dueFeed('https://three.example.com/feed');
        $untouched = $this->dueFeed('https://four.example.com/feed');
        $this->em->flush();

        // Concurrency 1 fixes the order, so the third flush is the fetched feed's.
        $this->fetcher = new StubFeedFetcher($this->clock, concurrency: 1);
        $this->fetcher->willReturn(
            $unchanged->getUrl(),
            FetchResponse::notModified($unchanged->getUrl(), false, null, null),
        );
        $this->fetcher->willThrow($rationed->getUrl(), new FeedThrottledException('HTTP 429', 60));
        $this->fetcher->willReturn(
            $failing->getUrl(),
            FetchResponse::fetched($failing->getUrl(), false, $this->rss('F', 'f-1'), null, null),
        );
        $this->fetcher->willReturn(
            $untouched->getUrl(),
            FetchResponse::notModified($untouched->getUrl(), false, null, null),
        );

        $flushes = 0;
        $failingEm = $this->createStub(EntityManagerInterface::class);
        $failingEm->method('flush')->willReturnCallback(function () use (&$flushes): void {
            $flushes++;
            if ($flushes === 3) {
                throw new UniqueConstraintViolationException(
                    new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
                    },
                    null,
                );
            }
            $this->em->flush();
        });

        $report = $this->runner($failingEm)->run(RefreshRequest::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertSame(4, $report->total);
        self::assertSame(0, $report->fetched);
        self::assertSame(1, $report->notModified);
        self::assertSame(1, $report->failed);
        self::assertSame(1, $report->throttled);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(2, $report->remaining);
        self::assertSame(0, $report->pruned);
        self::assertNotContains($untouched->getUrl(), $this->fetcher->fetchedUrls);
    }

    public function testEntriesCommittedBeforeAnAbortStillMoveTheChangeMarker(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $this->em->flush();
        $this->fetcher = new StubFeedFetcher($this->clock, concurrency: 1);
        $this->fetcher->willReturn(
            $first->getUrl(),
            FetchResponse::fetched($first->getUrl(), false, $this->rss('A', 'a-1'), null, null),
        );
        $this->fetcher->willReturn(
            $second->getUrl(),
            FetchResponse::fetched($second->getUrl(), false, $this->rss('B', 'b-1'), null, null),
        );
        $flushes = 0;
        $failingEm = $this->createStub(EntityManagerInterface::class);
        $failingEm->method('flush')->willReturnCallback(function () use (&$flushes): void {
            $flushes++;
            if ($flushes === 2) {
                throw new UniqueConstraintViolationException(
                    new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
                    },
                    null,
                );
            }
            $this->em->flush();
        });

        $report = $this->runner($failingEm)->run(RefreshRequest::allDue(300));

        self::assertSame('aborted', $report->status);
        self::assertSame(1, $this->changeMarker->marks);
    }

    public function testAForcedRefreshSkipsAFeedFetchedWithinTheCooldown(): void
    {
        $recent = $this->dueFeed('https://recent.example.com/feed');
        $recent->recordSuccessfulFetch($this->clock->now()->modify('-4 minutes'), 60);
        $stale = $this->dueFeed('https://stale.example.com/feed');
        $stale->recordSuccessfulFetch($this->clock->now()->modify('-6 minutes'), 60);
        $this->em->flush();
        $this->fetcher->willReturn($stale->getUrl(), FetchResponse::notModified($stale->getUrl(), false, null, null));

        $report = $this->runner()->run(RefreshRequest::forUser($this->subscriber->requireId(), 60));

        self::assertSame([$stale->getUrl()], $this->fetcher->fetchedUrls);
        self::assertSame(1, $report->total);
        self::assertSame(1, $report->notModified);
    }

```

- [ ] **Step 3: Add the not-modified pins to `RefreshRunnerConcurrentFetchTest`**

In `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`, add `use App\Enum\FeedStatus;` directly after `use App\Entity\User;`.

Directly before `public function testTheBudgetGateStopsTheRealConcurrentEngineMidBatch(): void` (keep its docblock above it), insert, right above that docblock's `/**`:
```php
    /**
     * A 304 answering a request that carried no validator confirms nothing, so the engine turns it into an empty
     * fetch, and the empty body fails the parse like any other unreadable document (#1165).
     */
    public function testANotModifiedToAnUnconditionalRequestIsRecordedAsAFailure(): void
    {
        $feed = $this->dueFeed('https://one.example.com/feed');
        $this->em->flush();

        $fetcher = $this->concurrentFetcher(new MockHttpClient(new MockResponse('', ['http_code' => 304])));
        $report = $this->runner($fetcher)->run(RefreshRequest::allDue(300));

        self::assertSame(0, $report->notModified);
        self::assertSame(1, $report->failed);
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
    }

    public function testANotModifiedToAConditionalRequestKeepsTheFeedHealthy(): void
    {
        $feed = $this->dueFeed('https://one.example.com/feed');
        $feed->recordCacheValidators('"v1"', null);
        $this->em->flush();

        $fetcher = $this->concurrentFetcher(new MockHttpClient(new MockResponse('', ['http_code' => 304])));
        $report = $this->runner($fetcher)->run(RefreshRequest::allDue(300));

        self::assertSame(1, $report->notModified);
        self::assertSame(0, $report->failed);
        self::assertSame(FeedStatus::Active, $feed->getStatus());
    }

```

- [ ] **Step 4: Run the pins**

Run: `php bin/phpunit tests/Service/Refresh/RefreshTallyTest.php tests/Service/Refresh/RefreshRunnerTest.php tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`
Expected: PASS (pin). Every new test passes on develop.

- [ ] **Step 5: Deletion checks**

One at a time, restoring each by hand with the Edit tool before the next:
1. In `src/Service/Refresh/RefreshRunner.php`, change `\count($feeds) - $tally->processed,` to `$queue->skippedCount(),`. Expected: `testAnAbortCarriesEveryCountTheRunReachedAndLeavesTheUnprocessedRemaining` fails on `remaining` (0 instead of 2).
2. In the same file, change `$tally->throttled,` in the first `RefreshReport::aborted(` call to `0,`. Expected: the same test fails on `throttled`.
3. Change `? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))` to `? $now->modify(sprintf('-%d minutes', 3))`. Expected: `testAForcedRefreshSkipsAFeedFetchedWithinTheCooldown` fails (both feeds fetched).
4. In `src/Service/Fetch/ResponseClassifier.php`, change `if (!$ticket->isConditional()) {` to `if (false) {`. Expected: `testANotModifiedToAnUnconditionalRequestIsRecordedAsAFailure` fails (`notModified` 1).
5. In `src/Service/Refresh/RefreshTally.php`, change `$this->faviconEligibleFeeds[] = $feed;` to `$this->faviconEligibleFeeds[] = new Feed('https://x.example.com/feed');`. Expected: two `RefreshTallyTest` tests fail.
6. In `src/Service/Refresh/RefreshRunner.php`, move the `if ($tally->entriesCreated > 0) { … }` block (with its comment) below the `if ($tally->aborted) { … }` block. Expected: `testEntriesCommittedBeforeAnAbortStillMoveTheChangeMarker` fails (0 marks).

Paste each failing output and the green re-run into the task report.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the three test files.
```bash
git add tests/Service/Refresh/RefreshTallyTest.php tests/Service/Refresh/RefreshRunnerTest.php tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php
git commit -m "refactor(#1166): pin the refresh report counts, the cooldown and the not-modified handling"
```

---

### Task A2: `FeedBodyParser` stops leaking the container exceptions

**Files:**
- Modify: `src/Service/Refresh/FeedBodyParser.php` (rewritten in full)
- Modify: `src/Service/Refresh/RefreshRunner.php` (the `Psr\Container` imports and `@throws` lines go)
- Create: `tests/Service/Refresh/FeedBodyParserTest.php`

**Interfaces:**
- Produces: `FeedBodyParser::parse(Feed $feed, string $body): ParsedFeed`, `@throws FeedParseException` only. A locator failure is a `\LogicException` with the message `No feed body parser is wired for "<format>".`

- [ ] **Step 1: Write the failing test**

`tests/Service/Refresh/FeedBodyParserTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\FeedBodyParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class FeedBodyParserTest extends TestCase
{
    public function testALocatorWithoutTheXmlParserIsAWiringError(): void
    {
        $parser = new FeedBodyParser(new ServiceLocator([]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No feed body parser is wired for "xml".');

        $parser->parse(new Feed('https://example.com/feed'), '<rss/>');
    }
}
```

- [ ] **Step 2: Run it to watch it fail**

Run: `php bin/phpunit tests/Service/Refresh/FeedBodyParserTest.php`
Expected: FAIL. The locator's `ServiceNotFoundException` is already a `\LogicException` subclass, so the message assertion is what fails: the actual message is Symfony's "Service "xml" not found…".

- [ ] **Step 3: Rewrite `FeedBodyParser`**

`src/Service/Refresh/FeedBodyParser.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\ParsedFeed;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Dispatches a feed's body to the parser owning its sourceFormat, through the app.feed_body_parser keyed locator
 * (see FeedBodyParserInterface for the extension contract).
 */
final readonly class FeedBodyParser
{
    public function __construct(
        #[AutowireLocator('app.feed_body_parser', defaultIndexMethod: 'format')]
        private ContainerInterface $parsers,
    ) {
    }

    /** @throws FeedParseException */
    public function parse(Feed $feed, string $body): ParsedFeed
    {
        $format = $feed->getSourceFormat();
        if ($this->parsers->has($format)) {
            return $this->resolve($format)->parse($body, $feed);
        }

        // A row whose format has no parser here (a newer deployment's, or a removed strategy's) is read as xml,
        // which is what every row meant before the seam existed.
        try {
            return $this->resolve(SourceFormat::XML)->parse($body, $feed);
        } catch (FeedParseException $e) {
            throw new FeedParseException(
                sprintf('No parser for source format "%s"; tried xml: %s', $format, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function resolve(string $format): FeedBodyParserInterface
    {
        try {
            $parser = $this->parsers->get($format);
        } catch (ContainerExceptionInterface $e) {
            throw new \LogicException(sprintf('No feed body parser is wired for "%s".', $format), 0, $e);
        }
        \assert($parser instanceof FeedBodyParserInterface);

        return $parser;
    }
}
```

- [ ] **Step 4: Drop the container exceptions from `RefreshRunner`**

```bash
perl -0pi -e 's/^     \* \@throws (?:ContainerExceptionInterface|NotFoundExceptionInterface)\n//mg; s/^use Psr\\Container\\(?:ContainerExceptionInterface|NotFoundExceptionInterface);\n//mg' src/Service/Refresh/RefreshRunner.php
git grep -n "Container" -- src/Service/Refresh/RefreshRunner.php
```
Expected: the grep prints nothing.

- [ ] **Step 5: Run the tests**

Run: `php bin/phpunit tests/Service/Refresh`
Expected: PASS.

- [ ] **Step 6: Deletion check**

In `resolve()`, replace the `try { … } catch (ContainerExceptionInterface $e) { … }` with the bare `$parser = $this->parsers->get($format);`. Expected: `FeedBodyParserTest` fails on the message. Restore by hand.

- [ ] **Step 7: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on `src/Service/Refresh/FeedBodyParser.php`, `src/Service/Refresh/RefreshRunner.php` and the new test. Expected: no "Unhandled exception" for the container exceptions anywhere in `src/Service/Refresh`.
```bash
git add src/Service/Refresh/FeedBodyParser.php src/Service/Refresh/RefreshRunner.php tests/Service/Refresh/FeedBodyParserTest.php
git commit -m "refactor(#1166): the feed body parser reports a missing parser as a wiring error, not a container exception"
```

---

### Task A3: `RefreshTally` is `public private(set)`; `RefreshPass` builds the reports

**Files:**
- Create: `src/Service/Refresh/RefreshPass.php`
- Modify: `src/Service/Refresh/RefreshTally.php` (rewritten in full)
- Modify: `src/Service/Refresh/RefreshRunner.php` (`refresh()`'s middle, `resolveFaviconsAndReport()`, `processOutcomes()`, `countRemaining()`)
- Create: `tests/Service/Refresh/RefreshPassTest.php`

**Interfaces:**
- Consumes: `BudgetedFeedQueue::tickets()`, `::startedFeedIds()`, `::skippedCount()`; `RefreshReport::aborted(int $total, int $fetched, int $notModified, int $failed, int $throttled, int $remaining)` and `::finished(int $total, int $fetched, int $notModified, int $failed, int $throttled, int $skippedForBudget, int $remaining, int $pruned)`, both unchanged.
- Produces (A4 relies on these):
  - `App\Service\Refresh\RefreshPass::__construct(list<Feed> $feeds, BudgetedFeedQueue $queue)`, `public RefreshTally $tally`.
  - `RefreshPass::tickets(): \Generator<int, FetchTicket, mixed, void>`, `::feed(int|string $feedId): Feed`, `::startedFeedIds(): list<int>`.
  - `RefreshPass::abortedDuringOutcomes(): RefreshReport` (remaining = feeds − processed), `::abortedAfterOutcomes(): RefreshReport` (remaining = never started), `::finished(int $remaining, int $pruned): RefreshReport`.
  - `RefreshTally`'s eight fields are `public private(set)`; `record(FeedRefreshResult, Feed): void` unchanged.

- [ ] **Step 1: Write the failing test**

`tests/Service/Refresh/RefreshPassTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\BudgetedFeedQueue;
use App\Service\Refresh\FeedOutcome;
use App\Service\Refresh\FeedRefreshResult;
use App\Service\Refresh\RefreshPass;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class RefreshPassTest extends DbTestCase
{
    /** Below BudgetedFeedQueue's 10-second margin: only the first feed ever starts. */
    private const int ONE_FEED_BUDGET = 5;

    private const int WHOLE_BATCH_BUDGET = 300;

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
    }

    public function testFindsEachFeedByItsId(): void
    {
        [$one, $two] = $this->feeds(2);
        $pass = $this->pass([$one, $two], self::WHOLE_BATCH_BUDGET);

        self::assertSame($two, $pass->feed($two->requireId()));
        self::assertSame($one, $pass->feed($one->requireId()));
    }

    public function testStartedFeedIdsAreTheOnesTheBudgetLetThrough(): void
    {
        [$one, $two] = $this->feeds(2);
        $pass = $this->pass([$one, $two], self::ONE_FEED_BUDGET);

        self::assertCount(1, iterator_to_array($pass->tickets()));
        self::assertSame([$one->requireId()], $pass->startedFeedIds());
    }

    public function testAnAbortDuringOutcomesLeavesEveryUnprocessedFeedRemaining(): void
    {
        [$one, $two, $three, $four] = $this->feeds(4);
        $pass = $this->pass([$one, $two, $three, $four], self::WHOLE_BATCH_BUDGET);
        iterator_to_array($pass->tickets());
        $pass->tally->record(FeedRefreshResult::fetched(2), $one);
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::NotModified), $two);
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Throttled), $three);
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Aborted), $four);

        $report = $pass->abortedDuringOutcomes();

        self::assertSame('aborted', $report->status);
        self::assertSame(4, $report->total);
        self::assertSame(1, $report->fetched);
        self::assertSame(1, $report->notModified);
        self::assertSame(1, $report->failed);
        self::assertSame(1, $report->throttled);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(1, $report->remaining);
        self::assertSame(0, $report->pruned);
    }

    public function testAnAbortAfterOutcomesLeavesTheFeedsTheBudgetNeverStartedRemaining(): void
    {
        [$one, $two, $three] = $this->feeds(3);
        $pass = $this->pass([$one, $two, $three], self::ONE_FEED_BUDGET);
        iterator_to_array($pass->tickets());
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Throttled), $one);

        $report = $pass->abortedAfterOutcomes();

        self::assertSame('aborted', $report->status);
        self::assertSame(3, $report->total);
        self::assertSame(0, $report->fetched);
        self::assertSame(0, $report->notModified);
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->throttled);
        self::assertSame(0, $report->skippedForBudget);
        self::assertSame(2, $report->remaining);
        self::assertSame(0, $report->pruned);
    }

    public function testTheTwoAbortsCountRemainingDifferentlyWhenAStartedFeedWasNeverRecorded(): void
    {
        [$one, $two, $three] = $this->feeds(3);
        $pass = $this->pass([$one, $two, $three], self::ONE_FEED_BUDGET);
        iterator_to_array($pass->tickets());

        self::assertSame(3, $pass->abortedDuringOutcomes()->remaining);
        self::assertSame(2, $pass->abortedAfterOutcomes()->remaining);
    }

    public function testAFinishedPassCarriesTheTallyTheBudgetSkipsAndTheCallersCounts(): void
    {
        [$one, $two, $three] = $this->feeds(3);
        $pass = $this->pass([$one, $two, $three], self::ONE_FEED_BUDGET);
        iterator_to_array($pass->tickets());
        $pass->tally->record(FeedRefreshResult::of(FeedOutcome::Failed), $one);

        $report = $pass->finished(4, 7);

        self::assertSame('partial', $report->status);
        self::assertSame(3, $report->total);
        self::assertSame(0, $report->fetched);
        self::assertSame(0, $report->notModified);
        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->throttled);
        self::assertSame(2, $report->skippedForBudget);
        self::assertSame(4, $report->remaining);
        self::assertSame(7, $report->pruned);
    }

    /**
     * @return list<Feed>
     */
    private function feeds(int $count): array
    {
        $feeds = [];
        for ($index = 1; $index <= $count; ++$index) {
            $feed = new Feed(sprintf('https://feed%d.example.com/rss', $index));
            $this->em->persist($feed);
            $feeds[] = $feed;
        }
        $this->em->flush();

        return $feeds;
    }

    /**
     * @param list<Feed> $feeds
     */
    private function pass(array $feeds, int $budgetSeconds): RefreshPass
    {
        return new RefreshPass(
            $feeds,
            new BudgetedFeedQueue($feeds, $this->clock, $this->clock->now()->getTimestamp() + $budgetSeconds),
        );
    }
}
```

- [ ] **Step 2: Run it to watch it fail**

Run: `php bin/phpunit tests/Service/Refresh/RefreshPassTest.php`
Expected: FAIL with `Class "App\Service\Refresh\RefreshPass" not found`.

- [ ] **Step 3: Write `RefreshPass`**

`src/Service/Refresh/RefreshPass.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FetchTicket;

/** One run's due feeds, its budget queue and its tally, and the report they add up to. */
final readonly class RefreshPass
{
    public RefreshTally $tally;

    /** @var array<int, Feed> */
    private array $feedsById;

    /**
     * @param list<Feed> $feeds
     */
    public function __construct(
        private array $feeds,
        private BudgetedFeedQueue $queue,
    ) {
        $feedsById = [];
        foreach ($feeds as $feed) {
            $feedsById[$feed->requireId()] = $feed;
        }
        $this->feedsById = $feedsById;
        $this->tally = new RefreshTally();
    }

    /** @return \Generator<int, FetchTicket, mixed, void> */
    public function tickets(): \Generator
    {
        return $this->queue->tickets();
    }

    public function feed(int|string $feedId): Feed
    {
        return $this->feedsById[$feedId];
    }

    /** @return list<int> */
    public function startedFeedIds(): array
    {
        return $this->queue->startedFeedIds();
    }

    /** Persistence failed while outcomes were stored: every feed not yet processed, the failing one too, is due. */
    public function abortedDuringOutcomes(): RefreshReport
    {
        return $this->aborted(\count($this->feeds) - $this->tally->processed);
    }

    public function abortedAfterOutcomes(): RefreshReport
    {
        return $this->aborted($this->queue->skippedCount());
    }

    public function finished(int $remaining, int $pruned): RefreshReport
    {
        return RefreshReport::finished(
            \count($this->feeds),
            $this->tally->fetched,
            $this->tally->notModified,
            $this->tally->failed,
            $this->tally->throttled,
            $this->queue->skippedCount(),
            $remaining,
            $pruned,
        );
    }

    private function aborted(int $remaining): RefreshReport
    {
        return RefreshReport::aborted(
            \count($this->feeds),
            $this->tally->fetched,
            $this->tally->notModified,
            $this->tally->failed,
            $this->tally->throttled,
            $remaining,
        );
    }
}
```

- [ ] **Step 4: Rewrite `RefreshTally`**

`src/Service/Refresh/RefreshTally.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;

/** Running counts for one refresh pass; only record() moves them. */
final class RefreshTally
{
    public private(set) int $fetched = 0;
    public private(set) int $notModified = 0;
    public private(set) int $failed = 0;
    public private(set) int $throttled = 0;
    public private(set) int $processed = 0;
    public private(set) int $entriesCreated = 0;
    public private(set) bool $aborted = false;

    /** @var list<Feed> not "processed": a failed or throttled feed has nothing new to show an icon beside */
    public private(set) array $faviconEligibleFeeds = [];

    public function record(FeedRefreshResult $result, Feed $feed): void
    {
        $outcome = $result->outcome;
        // An aborted feed's flush rolled back, so it is still due: it counts as failed, never as processed.
        if (FeedOutcome::Aborted === $outcome) {
            $this->failed++;
            $this->aborted = true;

            return;
        }

        $this->processed++;
        $this->entriesCreated += $result->entriesCreated;

        match ($outcome) {
            FeedOutcome::Fetched => $this->fetched++,
            FeedOutcome::NotModified => $this->notModified++,
            FeedOutcome::Failed => $this->failed++,
            FeedOutcome::Throttled => $this->throttled++,
        };

        if ($outcome->broughtContent()) {
            $this->faviconEligibleFeeds[] = $feed;
        }
    }
}
```

- [ ] **Step 5: The runner builds a `RefreshPass`**

In `src/Service/Refresh/RefreshRunner.php`, inside `refresh()`, replace:
```php
        $queue = new BudgetedFeedQueue($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds);
        $tally = $this->processOutcomes($feeds, $queue, $now);
```
with:
```php
        $pass = new RefreshPass(
            $feeds,
            new BudgetedFeedQueue($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds),
        );
        $this->processOutcomes($pass, $now);
```

Replace:
```php
        if ($tally->entriesCreated > 0) {
            $this->changeMarker->markChanged();
        }

        if ($tally->aborted) {
            // The EntityManager is likely closed: no favicons, no countDue, no
            // prune. Everything unprocessed stays due for the next run.
            return RefreshReport::aborted(
                \count($feeds),
                $tally->fetched,
                $tally->notModified,
                $tally->failed,
                $tally->throttled,
                \count($feeds) - $tally->processed,
            );
        }

        return $this->resolveFaviconsAndReport($request, $feeds, $tally, $queue, $criteria);
```
with:
```php
        if ($pass->tally->entriesCreated > 0) {
            $this->changeMarker->markChanged();
        }

        if ($pass->tally->aborted) {
            // The EntityManager is likely closed: no favicons, no countDue, no prune.
            return $pass->abortedDuringOutcomes();
        }

        return $this->resolveFaviconsAndReport($request, $pass, $criteria);
```

Replace the method `resolveFaviconsAndReport()` together with its docblock (from the `/**` above `private function resolveFaviconsAndReport(` to the method's closing `}`) with:
```php
    /** @throws \DateMalformedStringException */
    private function resolveFaviconsAndReport(
        RefreshRequest $request,
        RefreshPass $pass,
        DueFeedCriteria $criteria,
    ): RefreshReport {
        try {
            $this->resolveMissingFavicons($pass->tally->faviconEligibleFeeds);
        } catch (UniqueConstraintViolationException | ORMException $e) {
            $this->logger->error(
                'Refresh aborted: persistence failed while resolving favicons',
                ['exception' => $e],
            );

            return $pass->abortedAfterOutcomes();
        }

        return $pass->finished(
            $this->countRemaining($criteria, $pass),
            $request->prune ? $this->pruner->prune() : 0,
        );
    }
```

Replace the method `processOutcomes()` together with its docblock (from the `/**` above `private function processOutcomes(` to the method's closing `}`) with:
```php
    /** @throws \DateMalformedStringException */
    private function processOutcomes(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        foreach ($this->fetcher->fetchAll($pass->tickets()) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($this->applyOutcome($feed, $outcome, $now), $feed);
            if ($pass->tally->aborted) {
                break;
            }
        }
    }
```

Replace the method `countRemaining()` together with its docblock (from the `/**` above `private function countRemaining(` to the method's closing `}`) with:
```php
    // Started feeds are excluded by id: a 429 writes no fetch time (#290), so the due query alone would count a
    // rationed feed as remaining forever and keep the client polling (#302).
    private function countRemaining(DueFeedCriteria $criteria, RefreshPass $pass): int
    {
        return $this->feedRepository->countDue($criteria->excluding($pass->startedFeedIds()));
    }
```

Then check the leftovers:
```bash
git grep -n '\$tally\|\$queue\|\$byId' -- src/Service/Refresh/RefreshRunner.php
```
Expected: nothing. (`FeedOutcome::Aborted === $result->outcome` is gone: the tally's `aborted` flag is set by exactly that case.)

- [ ] **Step 6: Run the tests**

Run: `php bin/phpunit tests/Service/Refresh tests/Service/Maintenance/MaintenanceTickTest.php tests/Controller/Api/RefreshControllerTest.php tests/Controller/MaintenanceControllerTest.php`
Expected: PASS, including A1's pins.

- [ ] **Step 7: Deletion checks**

One at a time, restoring each by hand:
1. In `RefreshPass::abortedDuringOutcomes()`, return `$this->aborted($this->queue->skippedCount());`. Expected: `testAnAbortDuringOutcomesLeavesEveryUnprocessedFeedRemaining`, `testTheTwoAbortsCountRemainingDifferently…` and A1's `testAnAbortCarriesEveryCount…` fail.
2. In `RefreshPass::finished()`, pass `0` instead of `$this->queue->skippedCount()`. Expected: `testAFinishedPassCarries…` and `RefreshRunnerTest::testBudgetExhaustionSkipsFeedsThatWereNeverStarted` fail.
3. In `RefreshPass::feed()`, return `array_values($this->feedsById)[0]`. Expected: `testFindsEachFeedByItsId` fails.

- [ ] **Step 8: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the three `src` files and the test. PHPStan must accept `public private(set)`; the runner still carries its suppression until A4.
```bash
git add src/Service/Refresh/RefreshPass.php src/Service/Refresh/RefreshTally.php src/Service/Refresh/RefreshRunner.php tests/Service/Refresh/RefreshPassTest.php
git commit -m "refactor(#1166): a refresh pass holds the feeds, the queue and the tally and builds the report"
```

---

### Task A4: `FeedOutcomePersister`, `MissingFaviconResolver`, `RefreshHousekeeping`; the suppression goes; the runner tests share `RefreshRunners`

**Files:**
- Create: `src/Service/Refresh/FeedOutcomePersister.php`, `src/Service/Refresh/MissingFaviconResolver.php`, `src/Service/Refresh/RefreshHousekeeping.php`
- Modify: `src/Service/Refresh/RefreshRunner.php` (rewritten in full)
- Modify: `src/Service/Subscription/FirstFetchRecorder.php` (one comment), `config/services_test.yaml` (one comment)
- Create: `tests/Support/EntryIngestors.php`, `tests/Support/RefreshRunners.php`
- Create: `tests/Service/Refresh/FeedOutcomePersisterTest.php`, `tests/Service/Refresh/MissingFaviconResolverTest.php`, `tests/Service/Refresh/RefreshHousekeepingTest.php`
- Modify: `tests/Service/Refresh/RefreshRunnerTest.php` (`runner()`; `indexer()` and `bodyParser()` go; imports; one comment), `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php` and `tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php` (`runner()`; `indexer()` and the `$lockFactory` field go; imports), `tests/Service/Maintenance/MaintenanceTickTest.php` (the runner assembly, imports)

**Interfaces:**
- Consumes: `RefreshPass` and `RefreshTally` (A3); `FeedBodyParser::parse()` (A2); `EntryIngestor::ingest(Feed, ParsedFeed, FeedIngestContext): list<Entry>` and `::fillMissingImages(Feed, ParsedFeed): int`; `FeedScheduler::recordSuccess/recordThrottled/recordFailure/recordGone`; `FaviconResolverInterface::resolveAll(array<int, string>): array<int, string|null>`; `OrphanedFeedReclaimer::reclaimAll(): int`; `EntryPruner::prune(): int`.
- Produces:
  - `App\Service\Refresh\FeedOutcomePersister::__construct(EntityManagerInterface $em, FeedRepository $feedRepository, FeedBodyParser $bodyParser, EntryIngestor $ingestor, FeedScheduler $scheduler, EntryIndexer $indexer, LoggerInterface $logger)`, `::persist(Feed $feed, FetchOutcome $outcome, \DateTimeImmutable $now): FeedRefreshResult`.
  - `App\Service\Refresh\MissingFaviconResolver::__construct(FaviconResolverInterface $faviconResolver, EntityManagerInterface $em)`, `::resolveFor(list<Feed> $feeds): void`.
  - `App\Service\Refresh\RefreshHousekeeping::__construct(OrphanedFeedReclaimer $orphanedFeeds, EntryPruner $pruner, LoggerInterface $logger)`, `::reclaimOrphanedFeeds(RefreshRequest): void`, `::pruneEntries(RefreshRequest): int`.
  - `RefreshRunner::__construct(FeedRepository $feedRepository, BatchFeedFetcherInterface $fetcher, FeedOutcomePersister $outcomePersister, MissingFaviconResolver $missingFavicons, RefreshHousekeeping $housekeeping, LockFactory $lockFactory, ClockInterface $clock, LoggerInterface $logger, ContentChangeMarkerInterface $changeMarker)`; `run()` unchanged.
  - `App\Tests\Support\EntryIngestors::build(EntityManagerInterface $em, Psr\Clock\ClockInterface $clock): EntryIngestor` and `::withPlatformRules(EntityManagerInterface $em, ClockInterface $clock, PlatformEntryRules $platformRules): EntryIngestor`. B1–B3 rely on both.
  - `App\Tests\Support\RefreshRunners::fromContainer(Psr\Container\ContainerInterface $container, EntityManagerInterface $em, Symfony\Component\Clock\ClockInterface $clock): RefreshRunners`; the withers `flushingThrough(EntityManagerInterface)`, `lockingWith(LockFactory)`, `markingChangesOn(ContentChangeMarkerInterface)`, `indexingInto(SearchIndexWriter)`, each returning a new `RefreshRunners`; `build(BatchFeedFetcherInterface $feedFetcher, BatchFeedFetcherInterface $homepageFetcher): RefreshRunner` (D14).

- [ ] **Step 1: Add the shared ingestor assembly**

`tests/Support/EntryIngestors.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Category;
use App\Entity\Entry;
use App\Repository\CategoryRepository;
use App\Repository\EntryRepository;
use App\Service\Category\CategoryNormalizer;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\EntryCategoryWriter;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\Platform\PlatformEntryRules;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** An EntryIngestor over the EntityManager's real repositories: the one assembly the ingest and refresh tests share. */
final class EntryIngestors
{
    public static function build(EntityManagerInterface $em, ClockInterface $clock): EntryIngestor
    {
        return self::withPlatformRules($em, $clock, new PlatformEntryRules([]));
    }

    public static function withPlatformRules(
        EntityManagerInterface $em,
        ClockInterface $clock,
        PlatformEntryRules $platformRules,
    ): EntryIngestor {
        /** @var EntryRepository $entryRepository */
        $entryRepository = $em->getRepository(Entry::class);
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $em->getRepository(Category::class);

        return new EntryIngestor(
            $em,
            $entryRepository,
            new EntrySanitizer(),
            new UrlNormalizer(),
            new EntryCategoryWriter($em, $categoryRepository, new CategoryNormalizer()),
            new NaiveUtcClock($clock),
            $platformRules,
        );
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Service/Refresh/FeedOutcomePersisterTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\FeedScheduler;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\Fetch\HostThrottle;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcome;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Search\EntryIndexer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\RecordingLogger;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/** The per-feed policies in isolation; RefreshRunnerTest drives the same code through whole runs. */
final class FeedOutcomePersisterTest extends DbTestCase
{
    private MockClock $clock;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->logger = new RecordingLogger();
    }

    public function testAFetchedDocumentReportsTheEntriesItCreatedAndLogsNothing(): void
    {
        $feed = $this->feed('https://one.example.com/feed');

        $result = $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::fetched($feed->getUrl(), false, $this->rss(), '"e1"', null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        self::assertSame('"e1"', $feed->getEtag());
        self::assertSame([], $this->logger->records);
    }

    public function testAThrottledFetchIsLoggedAtInfo(): void
    {
        $feed = $this->feed('https://one.example.com/feed');

        $result = $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::failed(new FeedThrottledException('HTTP 429', 90)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Throttled, $result->outcome);
        self::assertSame(0, $result->entriesCreated);
        $this->assertLoggedOnce('info', 'Feed rate limited: {url}', ['url' => $feed->getUrl()]);
    }

    public function testAGoneFeedIsLoggedAsAWarning(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $gone = new FeedGoneException('HTTP 410 Gone');

        $result = $this->persister($this->em)->persist($feed, FetchOutcome::failed($gone), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        $this->assertLoggedOnce('warning', 'Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $gone]);
    }

    public function testAFailedFetchIsLoggedAsAWarning(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $unreachable = new FeedUnreachableException('connection refused');

        $result = $this->persister($this->em)->persist($feed, FetchOutcome::failed($unreachable), $this->clock->now());

        self::assertSame(FeedOutcome::Failed, $result->outcome);
        $this->assertLoggedOnce(
            'warning',
            'Feed refresh failed: {url}',
            ['url' => $feed->getUrl(), 'exception' => $unreachable],
        );
    }

    public function testAFailedFlushAbortsAndIsLoggedAsAnError(): void
    {
        $feed = $this->feed('https://one.example.com/feed');
        $duplicate = new UniqueConstraintViolationException(
            new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
            },
            null,
        );
        $failingEm = $this->createStub(EntityManagerInterface::class);
        $failingEm->method('flush')->willThrowException($duplicate);

        $result = $this->persister($failingEm)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified($feed->getUrl(), false, null, null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Aborted, $result->outcome);
        $this->assertLoggedOnce(
            'error',
            'Refresh aborted: persistence failed for {url}',
            ['url' => $feed->getUrl(), 'exception' => $duplicate],
        );
    }

    public function testATemporaryRedirectLeavesTheFeedWhereItIs(): void
    {
        $feed = $this->feed('https://old.example.com/feed');

        $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified('https://new.example.com/feed', false, null, null)),
            $this->clock->now(),
        );

        self::assertSame('https://old.example.com/feed', $feed->getUrl());
    }

    public function testAPermanentRedirectTargetOfExactlyTheColumnLengthIsAdopted(): void
    {
        $feed = $this->feed('https://old.example.com/feed');
        $target = 'https://new.example.com/' . str_repeat('p', 726);
        self::assertSame(750, mb_strlen($target));

        $this->persister($this->em)->persist(
            $feed,
            FetchOutcome::succeeded(FetchResponse::notModified($target, true, null, null)),
            $this->clock->now(),
        );

        self::assertSame($target, $feed->getUrl());
    }

    private function persister(EntityManagerInterface $em): FeedOutcomePersister
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedOutcomePersister(
            $em,
            $feedRepository,
            $bodyParser,
            EntryIngestors::build($this->em, $this->clock),
            new FeedScheduler($this->clock, new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock)),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            $this->logger,
        );
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertLoggedOnce(string $level, string $message, array $context): void
    {
        self::assertCount(1, $this->logger->records);
        self::assertSame($level, $this->logger->records[0]['level']);
        self::assertSame($message, $this->logger->records[0]['message']);
        self::assertSame($context, $this->logger->records[0]['context']);
    }

    private function rss(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>T</title>'
            . '<item><title>One</title><link>https://one.example.com/1</link><guid>one-1</guid></item>'
            . '<item><title>Two</title><link>https://one.example.com/2</link><guid>one-2</guid></item>'
            . '</channel></rss>';
    }
}
```

`tests/Service/Refresh/MissingFaviconResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FetchResponse;
use App\Service\Refresh\MissingFaviconResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

final class MissingFaviconResolverTest extends DbTestCase
{
    use ReloadsEntities;

    private StubFeedFetcher $homepages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homepages = new StubFeedFetcher();
    }

    public function testEachFeedWithoutAnIconGetsTheOneItsSiteAdvertises(): void
    {
        $blog = new Feed('https://feeds.example.net/blog');
        $blog->setSiteUrl('https://blog.example.com/');
        $plain = new Feed('https://plain.example.com/feed');
        $known = new Feed('https://known.example.com/feed');
        $known->setFaviconUrl('https://known.example.com/known.png');
        $this->persistAll($blog, $plain, $known);
        $this->homepageAdvertises('https://blog.example.com', '/blog.png');
        $this->homepageAdvertises('https://plain.example.com', '/plain.png');

        $this->resolver($this->em)->resolveFor([$blog, $plain, $known]);

        self::assertSame(['https://blog.example.com', 'https://plain.example.com'], $this->homepages->fetchedUrls);
        self::assertSame('https://blog.example.com/blog.png', $this->reload($blog)->getFaviconUrl());
        self::assertSame('https://plain.example.com/plain.png', $this->reload($plain)->getFaviconUrl());
        self::assertSame('https://known.example.com/known.png', $this->reload($known)->getFaviconUrl());
    }

    public function testFeedsThatAlreadyHaveAnIconCostNoFetchAndNoFlush(): void
    {
        $known = new Feed('https://known.example.com/feed');
        $known->setFaviconUrl('https://known.example.com/known.png');
        $this->persistAll($known);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->resolver($em)->resolveFor([$known]);

        self::assertSame([], $this->homepages->fetchedUrls);
    }

    private function resolver(EntityManagerInterface $em): MissingFaviconResolver
    {
        return new MissingFaviconResolver(new FaviconResolver($this->homepages, new NullLogger()), $em);
    }

    private function persistAll(Feed ...$feeds): void
    {
        foreach ($feeds as $feed) {
            $this->em->persist($feed);
        }
        $this->em->flush();
    }

    private function homepageAdvertises(string $origin, string $iconPath): void
    {
        $this->homepages->willReturn($origin, FetchResponse::fetched(
            $origin . '/',
            false,
            sprintf('<link rel="icon" href="%s">', $iconPath),
            null,
            null,
        ));
    }
}
```

`tests/Service/Refresh/RefreshHousekeepingTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Refresh\RefreshRequest;
use App\Service\Retention\EntryPruner;
use App\Service\Search\EntryIndexer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\RecordingLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/** Pruning itself is pinned by RefreshRunnerTest's two prune tests, against a whole run. */
final class RefreshHousekeepingTest extends DbTestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
    }

    public function testAPruningRequestReclaimsOrphanedFeedsAndLogsHowMany(): void
    {
        $orphanId = $this->orphanedFeed();

        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequest::allDue(30));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Feed::class)->find($orphanId));
        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertSame('Reclaimed orphaned feeds', $this->logger->records[0]['message']);
        self::assertSame(['count' => 1], $this->logger->records[0]['context']);
    }

    public function testAUserRequestLeavesOrphanedFeedsAlone(): void
    {
        $orphanId = $this->orphanedFeed();

        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequest::forUser(1, 30));

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Feed::class)->find($orphanId));
        self::assertSame([], $this->logger->records);
    }

    public function testNothingToReclaimLogsNothing(): void
    {
        $this->housekeeping()->reclaimOrphanedFeeds(RefreshRequest::allDue(30));

        self::assertSame([], $this->logger->records);
    }

    private function housekeeping(): RefreshHousekeeping
    {
        return new RefreshHousekeeping(
            new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),
            new EntryPruner(
                new RetentionRepository($this->em, new RowIds($this->em)),
                new MockClock('2026-07-21 12:00:00', 'UTC'),
                new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            ),
            $this->logger,
        );
    }

    private function orphanedFeed(): int
    {
        $feed = new Feed('https://orphan.example.com/rss');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed->requireId();
    }
}
```

- [ ] **Step 3: Run them to watch them fail**

Run: `php bin/phpunit tests/Service/Refresh/FeedOutcomePersisterTest.php tests/Service/Refresh/MissingFaviconResolverTest.php tests/Service/Refresh/RefreshHousekeepingTest.php`
Expected: FAIL with `Class "App\Service\Refresh\FeedOutcomePersister" not found` (and the same for the other two).

- [ ] **Step 4: Write the three services**

`src/Service/Refresh/FeedOutcomePersister.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\FeedScheduler;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FetchOutcome;
use App\Service\Fetch\FetchResponse;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Search\EntryIndexer;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;

/** Stores one feed's fetch outcome and flushes it, so a budget exit never loses what an earlier feed committed. */
final readonly class FeedOutcomePersister
{
    private const int URL_MAX = 750;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedRepository $feedRepository,
        private FeedBodyParser $bodyParser,
        private EntryIngestor $ingestor,
        private FeedScheduler $scheduler,
        private EntryIndexer $indexer,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function persist(Feed $feed, FetchOutcome $outcome, \DateTimeImmutable $now): FeedRefreshResult
    {
        // Read before recordSuccess() stamps the new lastSuccessfulFetchAt (#384).
        $context = new FeedIngestContext($now, $feed->getLastSuccessfulFetchAt());

        try {
            return $this->record($feed, $outcome, $context);
        } catch (UniqueConstraintViolationException | ForeignKeyConstraintViolationException | ORMException $e) {
            // A failed flush closes the EntityManager, so the run must stop here. The FK case is a feed whose
            // last subscriber left mid-run and whose row was reclaimed under the fetch (#246).
            $this->logger->error(
                'Refresh aborted: persistence failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $e],
            );

            return FeedRefreshResult::of(FeedOutcome::Aborted);
        }
    }

    /** @throws \DateMalformedStringException */
    private function record(Feed $feed, FetchOutcome $outcome, FeedIngestContext $context): FeedRefreshResult
    {
        try {
            $response = $outcome->responseOrThrow();
            if ($response->notModified) {
                return $this->storeNotModified($feed, $response);
            }

            $parsed = $this->bodyParser->parse($feed, $response->modifiedBody());
            $createdEntries = $this->ingestor->ingest($feed, $parsed, $context);
            // A backfilled image is not new content, so it stays out of the count (#148).
            $this->ingestor->fillMissingImages($feed, $parsed);

            return $this->storeFetched($feed, $response, $createdEntries);
        } catch (FeedThrottledException $e) {
            return $this->recordThrottled($feed, $e);
        } catch (FeedGoneException $e) {
            return $this->recordGone($feed, $e);
        } catch (FetchException | FeedParseException $e) {
            return $this->recordFailure($feed, $e);
        }
    }

    /** @throws \DateMalformedStringException */
    private function storeNotModified(Feed $feed, FetchResponse $response): FeedRefreshResult
    {
        // A moved feed can answer 304 at its new address; without this the redirect chain is re-walked every time.
        $this->applyPermanentRedirect($feed, $response);
        $this->scheduler->recordSuccess($feed, 0);
        $this->em->flush();

        return FeedRefreshResult::of(FeedOutcome::NotModified);
    }

    /**
     * @param list<Entry> $createdEntries
     *
     * @throws \DateMalformedStringException
     */
    private function storeFetched(Feed $feed, FetchResponse $response, array $createdEntries): FeedRefreshResult
    {
        $feed->recordCacheValidators($response->etag, $response->lastModified);
        $this->applyPermanentRedirect($feed, $response);
        $this->scheduler->recordSuccess($feed, \count($createdEntries));
        $this->em->flush();
        // Only the flush assigns ids, so indexing has to follow it (#432).
        $this->indexer->index($createdEntries);

        return FeedRefreshResult::fetched(\count($createdEntries));
    }

    /** @throws \DateMalformedStringException */
    private function recordThrottled(Feed $feed, FeedThrottledException $throttled): FeedRefreshResult
    {
        $this->scheduler->recordThrottled($feed, $throttled->retryAfterSeconds);
        $this->em->flush();
        $this->logger->info('Feed rate limited: {url}', ['url' => $feed->getUrl()]);

        return FeedRefreshResult::of(FeedOutcome::Throttled);
    }

    private function recordGone(Feed $feed, FeedGoneException $gone): FeedRefreshResult
    {
        $this->scheduler->recordGone($feed, $gone->getMessage());
        $this->em->flush();
        $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $gone]);

        return FeedRefreshResult::of(FeedOutcome::Failed);
    }

    /** @throws \DateMalformedStringException */
    private function recordFailure(Feed $feed, FetchException|FeedParseException $failure): FeedRefreshResult
    {
        $this->scheduler->recordFailure($feed, $failure->getMessage());
        $this->em->flush();
        $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $failure]);

        return FeedRefreshResult::of(FeedOutcome::Failed);
    }

    private function applyPermanentRedirect(Feed $feed, FetchResponse $response): void
    {
        if (!$response->permanentRedirect || $response->finalUrl === $feed->getUrl()) {
            return;
        }
        // A truncated URL is a broken URL, so an over-long target is declined rather than shortened.
        if (mb_strlen($response->finalUrl) > self::URL_MAX) {
            return;
        }
        // The url column is unique: a target another feed already claims leaves this feed where it is.
        if ($this->feedRepository->findOneBy(['url' => $response->finalUrl]) !== null) {
            return;
        }
        $feed->setUrl($response->finalUrl);
    }
}
```

`src/Service/Refresh/MissingFaviconResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FaviconResolverInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Looks up a favicon for each given feed that has none yet, fetching every homepage in one concurrent batch. */
final readonly class MissingFaviconResolver
{
    public function __construct(
        private FaviconResolverInterface $faviconResolver,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<Feed> $feeds
     */
    public function resolveFor(array $feeds): void
    {
        $baseUrls = self::baseUrlsOfFeedsWithoutIcon($feeds);
        if ([] === $baseUrls) {
            return;
        }

        $icons = $this->faviconResolver->resolveAll($baseUrls);
        foreach ($feeds as $feed) {
            $icon = $icons[$feed->requireId()] ?? null;
            if (null !== $icon) {
                $feed->setFaviconUrl($icon);
            }
        }

        $this->em->flush();
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return array<int, string>
     */
    private static function baseUrlsOfFeedsWithoutIcon(array $feeds): array
    {
        $baseUrls = [];
        foreach ($feeds as $feed) {
            if (null === $feed->getFaviconUrl()) {
                $baseUrls[$feed->requireId()] = $feed->getSiteUrl() ?? $feed->getUrl();
            }
        }

        return $baseUrls;
    }
}
```

`src/Service/Refresh/RefreshHousekeeping.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Service\OrphanedFeedReclaimer;
use App\Service\Retention\EntryPruner;
use Psr\Log\LoggerInterface;

/** The work only a pruning (maintenance) refresh does; a user-triggered refresh skips it to stay fast. */
final readonly class RefreshHousekeeping
{
    public function __construct(
        private OrphanedFeedReclaimer $orphanedFeeds,
        private EntryPruner $pruner,
        private LoggerInterface $logger,
    ) {
    }

    /** Runs before the due query: a feed nobody subscribes to must not cost the run an HTTP request. */
    public function reclaimOrphanedFeeds(RefreshRequest $request): void
    {
        if (!$request->prune) {
            return;
        }

        $reclaimed = $this->orphanedFeeds->reclaimAll();
        if ($reclaimed > 0) {
            $this->logger->info('Reclaimed orphaned feeds', ['count' => $reclaimed]);
        }
    }

    public function pruneEntries(RefreshRequest $request): int
    {
        return $request->prune ? $this->pruner->prune() : 0;
    }
}
```

- [ ] **Step 5: Run the three new tests**

Run: `php bin/phpunit tests/Service/Refresh/FeedOutcomePersisterTest.php tests/Service/Refresh/MissingFaviconResolverTest.php tests/Service/Refresh/RefreshHousekeepingTest.php`
Expected: PASS.

- [ ] **Step 6: Rewrite `RefreshRunner`**

`src/Service/Refresh/RefreshRunner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Service\Fetch\BatchFeedFetcherInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The one refresh behind the CLI, the maintenance endpoint and the user endpoint: lock-guarded and budget-bound.
 * Feeds are fetched concurrently, but each outcome is persisted and flushed serially as it lands.
 */
final readonly class RefreshRunner implements RefreshRunnerInterface
{
    private const string LOCK_NAME = 'feed-refresh';
    private const float LOCK_TTL_SECONDS = 60.0;
    private const int BATCH_LIMIT = 50;
    private const int COOLDOWN_MINUTES = 5;

    public function __construct(
        private FeedRepository $feedRepository,
        private BatchFeedFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private MissingFaviconResolver $missingFavicons,
        private RefreshHousekeeping $housekeeping,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ContentChangeMarkerInterface $changeMarker,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function run(RefreshRequest $request): RefreshReport
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return RefreshReport::busy();
        }

        try {
            return $this->refresh($request);
        } finally {
            $lock->release();
        }
    }

    /** @throws \DateMalformedStringException */
    private function refresh(RefreshRequest $request): RefreshReport
    {
        $this->housekeeping->reclaimOrphanedFeeds($request);

        $now = $this->clock->now();
        $criteria = $this->dueCriteria($request, $now);
        $feeds = $this->feedRepository->findDue($criteria, self::BATCH_LIMIT);
        // The deadline gates when a fetch may start, not finish: a request's own 20 s max_duration can overrun it.
        $pass = new RefreshPass(
            $feeds,
            new BudgetedFeedQueue($feeds, $this->clock, $now->getTimestamp() + $request->budgetSeconds),
        );
        $this->persistOutcomes($pass, $now);

        // Before the abort branch: what an aborted run created before the failure is already committed (#720).
        if ($pass->tally->entriesCreated > 0) {
            $this->changeMarker->markChanged();
        }

        if ($pass->tally->aborted) {
            // The EntityManager is likely closed: no favicons, no countDue, no prune.
            return $pass->abortedDuringOutcomes();
        }

        return $this->resolveFaviconsAndReport($request, $pass, $criteria);
    }

    /** @throws \DateMalformedStringException */
    private function dueCriteria(RefreshRequest $request, \DateTimeImmutable $now): DueFeedCriteria
    {
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        return new DueFeedCriteria(
            $now,
            $request->userId,
            $request->feedId,
            $request->tagId,
            $request->force,
            $cooldownCutoff,
        );
    }

    /** @throws \DateMalformedStringException */
    private function persistOutcomes(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        foreach ($this->fetcher->fetchAll($pass->tickets()) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($this->outcomePersister->persist($feed, $outcome, $now), $feed);
            if ($pass->tally->aborted) {
                break;
            }
        }
    }

    private function resolveFaviconsAndReport(
        RefreshRequest $request,
        RefreshPass $pass,
        DueFeedCriteria $criteria,
    ): RefreshReport {
        try {
            $this->missingFavicons->resolveFor($pass->tally->faviconEligibleFeeds);
        } catch (UniqueConstraintViolationException | ORMException $e) {
            $this->logger->error(
                'Refresh aborted: persistence failed while resolving favicons',
                ['exception' => $e],
            );

            return $pass->abortedAfterOutcomes();
        }

        return $pass->finished(
            $this->countRemaining($criteria, $pass),
            $this->housekeeping->pruneEntries($request),
        );
    }

    // Started feeds are excluded by id: a 429 writes no fetch time (#290), so the due query alone would count a
    // rationed feed as remaining forever and keep the client polling (#302).
    private function countRemaining(DueFeedCriteria $criteria, RefreshPass $pass): int
    {
        return $this->feedRepository->countDue($criteria->excluding($pass->startedFeedIds()));
    }
}
```

`countRemaining()` is still evaluated before `pruneEntries()` (arguments run left to right), as `countRemaining()` ran before `prune()` before.

- [ ] **Step 7: `RefreshRunners`, and the four tests build the runner through it (P4, D14)**

`tests/Support/RefreshRunners.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Repository\OrphanedFeedRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\FeedScheduler;
use App\Service\Fetch\BatchFeedFetcherInterface;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\HostThrottle;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Parser\Atom03Parser;
use App\Service\Parser\Atom10Parser;
use App\Service\Parser\FeedParser;
use App\Service\Parser\FeedParserFactory;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use App\Service\Refresh\ContentChangeMarkerInterface;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\MissingFaviconResolver;
use App\Service\Refresh\RefreshHousekeeping;
use App\Service\Refresh\RefreshRunner;
use App\Service\Refresh\ScrapedBodyParser;
use App\Service\Refresh\XmlBodyParser;
use App\Service\Retention\EntryPruner;
use App\Service\Scraper\HtmlItemExtractor;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\SearchIndexWriter;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** A RefreshRunner over the EntityManager's real repositories: the one assembly the refresh and tick tests share. */
final readonly class RefreshRunners
{
    private function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private FeedBodyParser $bodyParser,
        private EntityManagerInterface $flushingEm,
        private LockFactory $lockFactory,
        private ContentChangeMarkerInterface $changeMarker,
        private SearchIndexWriter $indexWriter,
    ) {
    }

    public static function fromContainer(
        ContainerInterface $container,
        EntityManagerInterface $em,
        ClockInterface $clock,
    ): self {
        return new self(
            $em,
            $clock,
            self::bodyParser($container),
            $em,
            new LockFactory(new InMemoryStore()),
            new RecordingContentChangeMarker(),
            new RecordingSearchIndexWriter(),
        );
    }

    /** Only the outcome and favicon flushes go through this EntityManager; the repositories keep the real one. */
    public function flushingThrough(EntityManagerInterface $flushingEm): self
    {
        return new self(
            $this->em,
            $this->clock,
            $this->bodyParser,
            $flushingEm,
            $this->lockFactory,
            $this->changeMarker,
            $this->indexWriter,
        );
    }

    public function lockingWith(LockFactory $lockFactory): self
    {
        return new self(
            $this->em,
            $this->clock,
            $this->bodyParser,
            $this->flushingEm,
            $lockFactory,
            $this->changeMarker,
            $this->indexWriter,
        );
    }

    public function markingChangesOn(ContentChangeMarkerInterface $changeMarker): self
    {
        return new self(
            $this->em,
            $this->clock,
            $this->bodyParser,
            $this->flushingEm,
            $this->lockFactory,
            $changeMarker,
            $this->indexWriter,
        );
    }

    public function indexingInto(SearchIndexWriter $indexWriter): self
    {
        return new self(
            $this->em,
            $this->clock,
            $this->bodyParser,
            $this->flushingEm,
            $this->lockFactory,
            $this->changeMarker,
            $indexWriter,
        );
    }

    public function build(
        BatchFeedFetcherInterface $feedFetcher,
        BatchFeedFetcherInterface $homepageFetcher,
    ): RefreshRunner {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        $indexer = new EntryIndexer($this->indexWriter, new NullLogger());

        return new RefreshRunner(
            $feedRepository,
            $feedFetcher,
            new FeedOutcomePersister(
                $this->flushingEm,
                $feedRepository,
                $this->bodyParser,
                EntryIngestors::build($this->em, $this->clock),
                new FeedScheduler(
                    $this->clock,
                    new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock),
                ),
                $indexer,
                new NullLogger(),
            ),
            new MissingFaviconResolver(
                new FaviconResolver($homepageFetcher, new NullLogger()),
                $this->flushingEm,
            ),
            new RefreshHousekeeping(
                new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),
                new EntryPruner(
                    new RetentionRepository($this->em, new RowIds($this->em)),
                    $this->clock,
                    $indexer,
                ),
                new NullLogger(),
            ),
            $this->lockFactory,
            $this->clock,
            new NullLogger(),
            $this->changeMarker,
        );
    }

    /** The keys the container's tagged locator carries; FeedBodyParserWiringTest proves the container routes alike. */
    private static function bodyParser(ContainerInterface $container): FeedBodyParser
    {
        $extractor = $container->get(HtmlItemExtractor::class);
        if (!$extractor instanceof HtmlItemExtractor) {
            throw new \LogicException(HtmlItemExtractor::class . ' is not in the test container.');
        }

        return new FeedBodyParser(new ServiceLocator([
            XmlBodyParser::format() => static fn (): XmlBodyParser => new XmlBodyParser(
                new FeedParser(new FeedParserFactory([
                    new Rss2Parser(),
                    new Atom10Parser(),
                    new Atom03Parser(),
                    new Rss1Parser(),
                ])),
            ),
            ScrapedBodyParser::format() => static fn (): ScrapedBodyParser => new ScrapedBodyParser($extractor),
        ]));
    }
}
```
The single `EntryIndexer` feeds both the persister and the pruner, as in the container (D-reconcile-6).

**`tests/Service/Refresh/RefreshRunnerTest.php`.**
- Replace the method `runner()` (from `    private function runner(?EntityManagerInterface $runnerEm = null): RefreshRunner` to its closing `    }`) with:
```php
    private function runner(?EntityManagerInterface $runnerEm = null): RefreshRunner
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->em, $this->clock)
            ->flushingThrough($runnerEm ?? $this->em)
            ->lockingWith($this->lockFactory)
            ->markingChangesOn($this->changeMarker)
            ->indexingInto($this->indexWriter)
            ->build($this->fetcher, $this->faviconFetcher);
    }
```
- Delete the method `indexer()` and the blank line after it:
```php
    private function indexer(): EntryIndexer
    {
        return new EntryIndexer($this->indexWriter, new NullLogger());
    }

```
- Delete the method `bodyParser()` with its docblock (from the `/**` whose first line reads `     * Hand-built locator with the same keys the container's tagged one carries` to the closing `    }` of `    private function bodyParser(): FeedBodyParser`) and the blank line after it. `RefreshRunners::bodyParser()` is that locator now.
- In `testRefreshBackfillsTheImageOntoAnAlreadyStoredEntryThatLacksOne()`, replace:
```php
        // A functional guard on the #148 wiring: FillMissingImagesTest exercises
        // the ingestor method directly, which cannot prove the refresh path
        // actually calls it inside persistOutcome's unit of work. Pre-store an
        // imageless entry, then serve the same guid carrying a media image.
```
with:
```php
        // A functional guard on the #148 wiring: FillMissingImagesTest calls the ingestor directly, which cannot
        // prove the refresh calls it inside FeedOutcomePersister's unit of work. Pre-store an imageless entry,
        // then serve the same guid carrying a media image.
```
- Imports. `FeedScheduler`, `EntryIngestor`, `OrphanedFeedReclaimer` and `EntryPruner` go too: after this step they appear only in comments.
```bash
perl -ni -e 'print unless /^use App\\(?:Entity\\Category|Repository\\(?:EntryRepository|FeedRepository|OrphanedFeedRepository|RetentionRepository|RowIds)|Service\\Category\\CategoryNormalizer|Service\\Clock\\NaiveUtcClock|Service\\FeedScheduler|Service\\Fetch\\(?:FaviconResolver|HostThrottle)|Service\\Ingest\\(?:EntryCategoryWriter|EntryIngestor|Platform\\PlatformEntryRules)|Service\\OrphanedFeedReclaimer|Service\\Parser\\(?:Atom03Parser|Atom10Parser|FeedParser|FeedParserFactory|Rss1Parser|Rss2Parser)|Service\\Refresh\\(?:FeedBodyParser|ScrapedBodyParser|XmlBodyParser)|Service\\Retention\\EntryPruner|Service\\Sanitize\\EntrySanitizer|Service\\Scraper\\HtmlItemExtractor|Service\\Search\\EntryIndexer|Service\\Url\\UrlNormalizer);$/ || /^use (?:Psr\\Log\\NullLogger|Symfony\\Component\\Cache\\Adapter\\ArrayAdapter|Symfony\\Component\\DependencyInjection\\ServiceLocator);$/' tests/Service/Refresh/RefreshRunnerTest.php
perl -pi -e 's/^(use App\\Tests\\Support\\StubFeedFetcher;)$/use App\\Tests\\Support\\RefreshRunners;\n$1/' tests/Service/Refresh/RefreshRunnerTest.php
```

**`tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`.**
- Replace the method `runner()` (from `    private function runner(ConcurrentFeedFetcher $fetcher): RefreshRunner` to its closing `    }`) with:
```php
    private function runner(ConcurrentFeedFetcher $fetcher): RefreshRunner
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->em, $this->clock)
            ->build($fetcher, $this->faviconFetcher);
    }
```

**`tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php`.**
- Replace the method `runner()` (from `    private function runner(): RefreshRunner` to its closing `    }`) with:
```php
    private function runner(): RefreshRunner
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->em, $this->clock)
            ->build($this->fetcher, $this->faviconFetcher);
    }
```

**Both of those files.**
- Delete the method `indexer()` and the blank line after it:
```php
    private function indexer(): EntryIndexer
    {
        return new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger());
    }

```
- The `$lockFactory` field and its `setUp()` line go (the helper's default lock factory is the same `LockFactory(new InMemoryStore())`), and so do the imports the old assembly needed:
```bash
perl -ni -e 'print unless /^use App\\(?:Tests\\Support\\RecordingContentChangeMarker|Entity\\(?:Category|Entry)|Repository\\(?:EntryRepository|FeedRepository|OrphanedFeedRepository|RetentionRepository|RowIds)|Service\\Category\\CategoryNormalizer|Service\\Clock\\NaiveUtcClock|Service\\FeedScheduler|Service\\Fetch\\(?:FaviconResolver|HostThrottle)|Service\\Ingest\\(?:EntryCategoryWriter|EntryIngestor|Platform\\PlatformEntryRules)|Service\\OrphanedFeedReclaimer|Service\\Parser\\(?:Atom03Parser|Atom10Parser|FeedParser|FeedParserFactory|Rss1Parser|Rss2Parser)|Service\\Refresh\\(?:FeedBodyParser|ScrapedBodyParser|XmlBodyParser)|Service\\Retention\\EntryPruner|Service\\Sanitize\\EntrySanitizer|Service\\Scraper\\HtmlItemExtractor|Service\\Search\\EntryIndexer|Service\\Url\\UrlNormalizer|Tests\\Service\\Search\\RecordingSearchIndexWriter);$/ || /^use (?:Psr\\Log\\NullLogger|Symfony\\Component\\Cache\\Adapter\\ArrayAdapter|Symfony\\Component\\DependencyInjection\\ServiceLocator|Symfony\\Component\\Lock\\LockFactory|Symfony\\Component\\Lock\\Store\\InMemoryStore);$/ || /^    private LockFactory \$lockFactory;$/ || /^        \$this->lockFactory = new LockFactory\(new InMemoryStore\(\)\);$/' tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php
perl -pi -e 's/^(use App\\Tests\\Support\\StubFeedFetcher;)$/use App\\Tests\\Support\\RefreshRunners;\n$1/' tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php
```

**`tests/Service/Maintenance/MaintenanceTickTest.php`.** In `testSkipsTheRecommendationSweepWhenRefreshAborts()`, replace:
```php
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);

        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        $indexer = new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger());
        $refreshRunner = new RefreshRunner(
            $feedRepository,
            $failingEm,
            $fetcher,
            $bodyParser,
            new EntryIngestor(
                $this->em,
                $entryRepository,
                new EntrySanitizer(),
                new UrlNormalizer(),
                new EntryCategoryWriter(
                    $this->em,
                    $this->em->getRepository(Category::class),
                    new CategoryNormalizer(),
                ),
                new NaiveUtcClock($clock),
                new PlatformEntryRules([]),
            ),
            new FaviconResolver($fetcher, new NullLogger()),
            new FeedScheduler($clock, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
            new EntryPruner(new RetentionRepository($this->em, new RowIds($this->em)), $clock, $indexer),
            new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),
            $indexer,
            new LockFactory(new InMemoryStore()),
            $clock,
            new NullLogger(),
            new RecordingContentChangeMarker(),
        );
```
with:
```php
        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->em, $clock)
            ->flushingThrough($failingEm)
            ->build($fetcher, $fetcher);
```
The test's docblock stays: #1182 B3 already rewrote it, and it names no runner parameter. The body parser is now the helper's locator instead of the container's; `FeedBodyParserWiringTest` proves they route alike (D-reconcile-5). Imports (`NaiveUtcClock` and `NullLogger` stay: `ImageVerifier` and `SendDueDigests` still use them):
```bash
perl -ni -e 'print unless /^use App\\(?:Entity\\(?:Category|Entry)|Repository\\(?:EntryRepository|FeedRepository|OrphanedFeedRepository|RetentionRepository|RowIds)|Service\\Category\\CategoryNormalizer|Service\\FeedScheduler|Service\\Fetch\\(?:FaviconResolver|HostThrottle)|Service\\Ingest\\(?:EntryCategoryWriter|EntryIngestor|Platform\\PlatformEntryRules)|Service\\OrphanedFeedReclaimer|Service\\Refresh\\(?:FeedBodyParser|RefreshRunner)|Service\\Retention\\EntryPruner|Service\\Sanitize\\EntrySanitizer|Service\\Search\\EntryIndexer|Service\\Url\\UrlNormalizer|Tests\\Service\\Search\\RecordingSearchIndexWriter|Tests\\Support\\RecordingContentChangeMarker);$/ || /^use Symfony\\Component\\(?:Cache\\Adapter\\ArrayAdapter|Lock\\LockFactory|Lock\\Store\\InMemoryStore);$/' tests/Service/Maintenance/MaintenanceTickTest.php
perl -pi -e 's/^(use App\\Tests\\Support\\RecordingSavedSearchMatcher;)$/$1\nuse App\\Tests\\Support\\RefreshRunners;/' tests/Service/Maintenance/MaintenanceTickTest.php
```

Then check that nothing still assembles a runner or a locator by hand:
```bash
git grep -n -E "new (RefreshRunner|FeedBodyParser|EntryIngestor)\(" -- tests
git grep -c "RefreshRunners::fromContainer" -- tests
```
Expected:
- The first grep lists `tests/Support/RefreshRunners.php` (`new RefreshRunner(` and `new FeedBodyParser(`), `tests/Support/EntryIngestors.php`, A2's `tests/Service/Refresh/FeedBodyParserTest.php`, and the four ingest-side `new EntryIngestor(` sites B1 moves (`EntryIngestorTest`, `EntryIngestorCategoriesTest`, `FirstFetchRecorderTest`, `SubscriptionServiceTest`). Nothing under `tests/Service/Refresh` except `FeedBodyParserTest`, and nothing under `tests/Service/Maintenance`.
- The second prints `1` for each of `RefreshRunnerTest.php`, `RefreshRunnerConcurrentFetchTest.php`, `RefreshRunnerOrphanSweepTest.php` and `MaintenanceTickTest.php`.

- [ ] **Step 8: Bring the two stale comments outside the tests up to date**

`src/Service/Subscription/FirstFetchRecorder.php`, replace:
```php
        // See RefreshRunner's identical ordering: an id only exists after this
        // flush, so indexing has to happen after it, not before.
```
with:
```php
        // See FeedOutcomePersister's identical ordering: an id only exists after this
        // flush, so indexing has to happen after it, not before.
```

`config/services_test.yaml`, replace:
```yaml
    # FeedBodyParserWiringTest fetches this to prove the app.feed_body_parser
    # keyed locator actually collects both format parsers — an empty locator
    # builds fine and every refresh would then take the xml fallback, which
    # looks exactly like a working deployment until a scraped feed errors.
    # RefreshRunner is its only injection point, so without this entry the
    # compiler would inline it away and the test container could not fetch
    # it. Same `autowire` caveat as above.
    App\Service\Refresh\FeedBodyParser:
```
with:
```yaml
    # FeedBodyParserWiringTest proves the keyed locator collects both format parsers, and FeedOutcomePersisterTest
    # builds the persister around it. That persister is its only injection point, so without this entry the
    # compiler would inline it away. Same `autowire` caveat as above.
    App\Service\Refresh\FeedBodyParser:
```

- [ ] **Step 9: Run the refresh suites**

```bash
bin/console cache:clear && bin/console lint:container
php bin/phpunit tests/Service/Refresh tests/Service/Maintenance tests/Controller/Api/RefreshControllerTest.php tests/Controller/MaintenanceControllerTest.php tests/Service/Worker/RefreshDueFeedsHandlerTest.php tests/Command/RefreshFeedsCommandTest.php tests/Service/Subscription/FirstFetchRecorderTest.php
```
Expected: the container lints clean (every new service autowires), and every test passes, A1's pins included.

- [ ] **Step 10: Deletion checks**

One at a time, restoring each by hand:
1. `FeedOutcomePersister::record()`: delete `$this->ingestor->fillMissingImages($feed, $parsed);`. Expected: `RefreshRunnerTest::testRefreshBackfillsTheImageOntoAnAlreadyStoredEntryThatLacksOne` fails.
2. `FeedOutcomePersister::storeFetched()`: move `$this->indexer->index($createdEntries);` above `$this->em->flush();`. Expected: `testIndexesFetchedEntriesWithTheirRealIdsAfterFlush` fails.
3. `FeedOutcomePersister::persist()`: build the context as `new FeedIngestContext($now, null)`. Expected: `testARefreshSinksAnArticleTheFeedServedBeforeTheLastFetch` fails.
4. `FeedOutcomePersister::applyPermanentRedirect()`: `>` to `>=`. Expected: `testAPermanentRedirectTargetOfExactlyTheColumnLengthIsAdopted` fails.
5. `MissingFaviconResolver::baseUrlsOfFeedsWithoutIcon()`: use `$feed->getUrl()` alone. Expected: `testEachFeedWithoutAnIconGetsTheOneItsSiteAdvertises` fails.
6. `RefreshHousekeeping::reclaimOrphanedFeeds()`: `> 0` to `>= 0`. Expected: `testNothingToReclaimLogsNothing` fails.
7. `RefreshRunner::refresh()`: move the `markChanged()` block below the abort branch. Expected: A1's `testEntriesCommittedBeforeAnAbortStillMoveTheChangeMarker` fails.
8. `RefreshRunners::flushingThrough()`: pass `$this->flushingEm` instead of `$flushingEm`. Expected: `RefreshRunnerTest::testEntityManagerFailureAbortsRunWithoutCascading` and `MaintenanceTickTest::testSkipsTheRecommendationSweepWhenRefreshAborts` fail: the helper would no longer hand the failing EntityManager to the runner.

- [ ] **Step 11: Gates and commit**

```bash
composer check
composer md
git grep -n "SuppressWarnings" -- src/Service/Refresh
```
Expected: green; `composer md` reports nothing for `src/Service/Refresh`; the grep prints nothing; `composer tramp` (part of `composer check`) reports no chain of 3 or more hops through `FeedOutcomePersister`. Then the PhpStorm inspections on every changed PHP file.
```bash
git add src/Service/Refresh src/Service/Subscription/FirstFetchRecorder.php config/services_test.yaml tests/Support/EntryIngestors.php tests/Support/RefreshRunners.php tests/Service/Refresh tests/Service/Maintenance/MaintenanceTickTest.php
git commit -m "refactor(#1166): the refresh runner delegates to an outcome persister, a favicon lookup and the housekeeping, and loses its parameter-list suppression"
```

---

### Task A5: `FeedFetcherInterface::fetch()` loses its dead parameters

**Files:**
- Modify: `src/Service/Fetch/FeedFetcherInterface.php`, `src/Service/Fetch/HttpFeedFetcher.php`
- Modify: `tests/Support/StubFeedFetcher.php`
- Modify: `tests/Service/Fetch/HttpFeedFetcherTest.php` (`testSendsConditionalGetHeaders` goes)

**Interfaces:**
- Produces: `FeedFetcherInterface::fetch(string $url): FetchResponse`. No production caller passed `$etag` or `$lastModified` (`git grep -n '\->fetch(' -- src` shows only one-argument calls); conditional GET belongs to the batch engine's `FetchTicket`, pinned by `ConcurrentFeedFetcherTest::testSendsConditionalGetHeaders` and A1's two not-modified pins.

- [ ] **Step 1: Confirm nothing passes the parameters**

```bash
git grep -n 'fetcher->fetch(' -- src
git grep -n "testSendsConditionalGetHeaders" -- tests/Service/Fetch
```
Expected:
- Six lines, each passing a single argument: `Comments/CommentsLoader.php` (`$feedUrl`), `Discovery/FeedDiscovery.php` (`$url`), `Discovery/SubstackProfileFeed.php` (`sprintf(self::PUBLIC_PROFILE_API, $handle)`), `Discovery/WordPressRestProbe.php` (`$postsUrl`), `Preview/FeedPreviewService.php` (`$url`), and `Reader/ArticleExtractor.php` (`$url`, whose fetcher is `HtmlPageFetcher`, another interface).
- One line in `ConcurrentFeedFetcherTest.php` and one in `HttpFeedFetcherTest.php`.

- [ ] **Step 2: Remove the single-URL conditional test**

In `tests/Service/Fetch/HttpFeedFetcherTest.php`, delete the whole method `testSendsConditionalGetHeaders()` (from `    public function testSendsConditionalGetHeaders(): void` to its closing `    }` and the blank line after it). The engine-level test of the same name stays.

- [ ] **Step 3: Narrow the interface and its two implementations**

`src/Service/Fetch/FeedFetcherInterface.php`, replace:
```php
    /**
     * Fetch a feed URL with SSRF protection and conditional-GET support.
     *
     * @throws FetchException
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse;
```
with:
```php
    /**
     * Fetch a URL with SSRF protection, unconditionally.
     *
     * @throws FetchException
     */
    public function fetch(string $url): FetchResponse;
```

`src/Service/Fetch/HttpFeedFetcher.php`, replace:
```php
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        foreach ($this->fetcher->fetchAll([new FetchTicket($url, $etag, $lastModified)]) as $outcome) {
```
with:
```php
    public function fetch(string $url): FetchResponse
    {
        foreach ($this->fetcher->fetchAll([new FetchTicket($url)]) as $outcome) {
```

`tests/Support/StubFeedFetcher.php`, replace:
```php
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        foreach ($this->fetchAll([new FetchTicket($url, $etag, $lastModified)]) as $outcome) {
```
with:
```php
    public function fetch(string $url): FetchResponse
    {
        foreach ($this->fetchAll([new FetchTicket($url)]) as $outcome) {
```

- [ ] **Step 4: Run the fetch and caller suites**

Run: `php bin/phpunit tests/Service/Fetch tests/Service/Discovery tests/Service/Preview tests/Service/Comments tests/Controller/Api/FeedPreviewControllerTest.php tests/Controller/Api/EntryCommentsControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the four files. PHPStan flags any caller still passing three arguments.
```bash
git add src/Service/Fetch/FeedFetcherInterface.php src/Service/Fetch/HttpFeedFetcher.php tests/Support/StubFeedFetcher.php tests/Service/Fetch/HttpFeedFetcherTest.php
git commit -m "refactor(#1166): the single-url fetch drops the validators no caller passes"
```

---

### Finishing PR A

- [ ] **Step 1: The gates on the whole branch**

```bash
composer check
composer md
php bin/phpunit
docker compose exec php composer test
composer infection:diff
```
Expected: all green.
- Before the MySQL leg, check that the containers are current (memory "Check the container is current"); `docker compose exec php bin/console cache:clear` if the compiled container predates this branch.
- An escaped mutant on a touched line gets a killing test in the task that owns the line (Global Constraints, Infection).
- Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no deprecation or error from a file this PR touched.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'`. ERROR and WARNING block.

- [ ] **Step 3: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`. It runs its angle reviewers (reuse, simplification, efficiency, altitude) as parallel agents. Apply only fixes that keep every gate green and undo no decision in this plan (D1–D3, D13, D14, P2, P4). Re-run Step 1's gates if anything changed, and commit as `refactor(#1166): simplify pass`.

- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Reports byte for byte.** For each branch (busy, aborted while outcomes are stored, aborted in the favicon phase, finished), compare every `RefreshReport` argument against `git show 6f88765d:backend/src/Service/Refresh/RefreshRunner.php`. `remaining` in the two aborts must keep their different formulas (D3).
2. **Per-feed order.** The ingest context is built before `recordSuccess()`. The fetched path runs parse → ingest → fillMissingImages → validators → redirect → recordSuccess → flush → index; the 304 path runs redirect → recordSuccess → flush. A redirect is never adopted on a parse failure.
3. **Abort semantics.** A flush that throws inside a fetch-failure branch still ends in `Aborted`, the loop stops at the first abort, nothing touches the EntityManager after it, and `countRemaining()` still runs before `pruneEntries()`.
4. **Suppression and size.** `RefreshRunner` has 9 constructor parameters and no `@SuppressWarnings`; `composer md` is clean for `src/Service/Refresh`.
5. **phptramp.** No chain of 3 or more hops through `FeedOutcomePersister`, `RefreshPass` or `RefreshHousekeeping` (D2).
6. **Container exceptions.** `git grep -n "ContainerExceptionInterface\|NotFoundExceptionInterface" -- src/Service/Refresh` lists only `FeedBodyParser.php`'s import and its catch.
7. **Test assemblies.** Each of the four sites gets from `RefreshRunners` the runner it built by hand before: the same feed fetcher and homepage fetcher, the failing EntityManager for flushes only (reads and the ingestor stay on the real one), and `RefreshRunnerTest`'s own lock factory, change marker and index writer (D14).
8. **Comment bar** on every new or rewritten file (D12).
9. **No closing keyword** for #1166 in any commit message on this branch.

Fix each finding it rates Important or above in its own commit (`refactor(#1166): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin refactor/1166-refresh-runner-split
git log origin/develop..HEAD --format=%B | grep -inE '\b(close|fix|resolve)[sd]?\b|\b(closed|fixed|fixes|resolves|resolved|closes)\b' && echo 'STOP: closing keyword in a commit' || true
gh pr create --base develop --title "refactor(#1166): the refresh runner splits into an outcome persister, a refresh pass and the housekeeping" --body "$(cat <<'BODY'
Refs #1166 (PR A of three).

- `RefreshRunner` goes from 14 collaborators to 9 and drops its `ExcessiveParameterList` suppression. Each feed's outcome goes to `FeedOutcomePersister` (the fetch-failure and persistence-abort policies, the header bookkeeping, the flush and the index), favicon lookups to `MissingFaviconResolver`, and the pruning-only work to `RefreshHousekeeping`.
- `RefreshPass` holds a run's feeds, budget queue and tally and builds its report, so the tally is unpacked in one place. `RefreshTally`'s fields are `public private(set)`.
- `FeedBodyParser` reports a missing parser as a wiring error, so the container exception types no longer appear in the refresh's signatures.
- `FeedFetcherInterface::fetch()` drops the `$etag`/`$lastModified` parameters that no caller passed (carried over from #1165). A new pin runs an unconditional 304 through the real engine.
- The four tests that built a `RefreshRunner` by hand, and the three copies of the body-parser locator, now share `tests/Support/RefreshRunners` (with `tests/Support/EntryIngestors` for the ingestor).

No behaviour change and no wire change. Every refresh report field is pinned in every branch before the split.
BODY
)"
gh pr view --json body --jq .body | grep -inE '\b(close|fix|resolve)[sd]?\b|\b(closed|fixed|fixes|resolves|resolved|closes)\b' && echo 'STOP: closing keyword in the body' || true
```
Expected: neither `STOP` line prints.

- [ ] **Step 6: Merge when green**

Watch the checks with the Monitor tool, as one command with no loop: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`: it merges immediately. On a failure:
- read the failing job (`gh run view --log-failed`),
- if only the tramp step fails, run `composer show larspohlmann/phptramp` first: CI runs phptramp's `develop` tip,
- fix on the branch, push, and watch again.

- [ ] **Step 7: Verify the issue stayed open**

Run: `gh issue view 1166 --json state --jq .state`. Expected: `OPEN`. If it closed, reopen it and report: a closing keyword slipped in.

---

# PR B — Ingest and catalog import split

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR A merged and cut the branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --head refactor/1166-refresh-runner-split --json number,mergedAt
gh issue view 1166 --json state --jq .state
git switch -c refactor/1166-ingest-and-catalog-split origin/develop
```
Expected: one merged PR, the issue `OPEN`, the new branch checked out.

- [ ] **Step 2: Confirm the starting state (from `backend/`)**

```bash
git diff --stat 6f88765d origin/develop -- src/Service/Ingest src/Service/Catalog/CatalogImporter.php tests/Service/Catalog/CatalogImporterTest.php tests/Service/Ingest tests/Service/Subscription/FirstFetchRecorderTest.php tests/Service/Subscription/SubscriptionServiceTest.php
git grep -c "EntryIngestors::build" -- tests
git grep -n "new EntryIngestor(" -- tests
```
Expected:
- The `--stat` is empty: PR A did not touch `src/Service/Ingest`, the catalog importer or these tests, and nothing merged since the reconcile did (D-reconcile-7).
- Two files with a count of `1` each: `tests/Support/RefreshRunners.php` (which builds the ingestor for all four runner sites) and `tests/Service/Refresh/FeedOutcomePersisterTest.php`.
- The `new EntryIngestor(` grep lists `tests/Support/EntryIngestors.php`, `tests/Service/Ingest/EntryIngestorTest.php`, `tests/Service/Ingest/EntryIngestorCategoriesTest.php`, `tests/Service/Subscription/FirstFetchRecorderTest.php` and `tests/Service/Subscription/SubscriptionServiceTest.php`. B1 moves the last four onto the helper.

---

### Task B1: Pin the feed metadata limits; the ingest tests share `EntryIngestors`

**Files:**
- Modify: `tests/Service/Ingest/EntryIngestorTest.php` (two new tests, `ingestorWith()`, imports)
- Modify: `tests/Service/Ingest/EntryIngestorCategoriesTest.php` (`setUp()`, imports)
- Modify: `tests/Service/Subscription/FirstFetchRecorderTest.php` (`setUp()`, imports)
- Modify: `tests/Service/Subscription/SubscriptionServiceTest.php` (`service()`, imports)

**Interfaces:**
- Consumes: `EntryIngestors::build()` and `::withPlatformRules()` (A4).

What the new tests pin: the three feed-metadata limits with their exact content (not only the length, so an off-by-one offset is caught), and a url-less, author-less item stored with both `null`. `testOverlongFieldsAreTruncatedToColumnLimits` already pins the entry limits by length; B3's `IngestedEntryFactoryTest` pins them by content.

- [ ] **Step 1: Add the pins**

In `tests/Service/Ingest/EntryIngestorTest.php`, directly before `public function testPersistsTheFeedSuppliedImage(): void`, add:
```php
    public function testOverlongFeedMetadataIsCutToItsColumns(): void
    {
        $feed = $this->feed();
        $parsed = new ParsedFeed(
            'A' . str_repeat('T', 899),
            'https://example.com/' . str_repeat('s', 3000),
            'D' . str_repeat('d', 4999),
            null,
            [],
        );

        $this->ingestor->ingest($feed, $parsed, self::context());

        self::assertSame('A' . str_repeat('T', 511), $feed->getTitle());
        self::assertSame('https://example.com/' . str_repeat('s', 2028), $feed->getSiteUrl());
        self::assertSame('D' . str_repeat('d', 3999), $feed->getDescription());
    }

    public function testAnItemWithoutUrlOrAuthorIsStoredWithBothNull(): void
    {
        $entry = $this->ingestOne(new ParsedEntry('no-url', null, 'Title', null, null, '<p>body</p>', null));

        self::assertNull($entry->getUrl());
        self::assertNull($entry->getUrlHash());
        self::assertNull($entry->getAuthor());
    }

```

- [ ] **Step 2: Run the pins**

Run: `php bin/phpunit tests/Service/Ingest/EntryIngestorTest.php`
Expected: PASS (pin).

- [ ] **Step 3: Move the four hand-built ingestors onto `EntryIngestors`**

**`tests/Service/Ingest/EntryIngestorTest.php`**, replace:
```php
    private function ingestorWith(PlatformEntryRules $rules): EntryIngestor
    {
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $this->em->getRepository(Category::class);

        return new EntryIngestor(
            $this->em,
            $entryRepository,
            new EntrySanitizer(),
            new UrlNormalizer(),
            new EntryCategoryWriter($this->em, $categoryRepository, new CategoryNormalizer()),
            new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')),
            $rules,
        );
    }
```
with:
```php
    private function ingestorWith(PlatformEntryRules $rules): EntryIngestor
    {
        return EntryIngestors::withPlatformRules($this->em, new MockClock('2026-09-21 12:00:00'), $rules);
    }
```
```bash
perl -ni -e 'print unless /^use App\\(?:Entity\\Category|Repository\\CategoryRepository|Repository\\EntryRepository|Service\\Category\\CategoryNormalizer|Service\\Ingest\\EntryCategoryWriter|Service\\Clock\\NaiveUtcClock|Service\\Sanitize\\EntrySanitizer|Service\\Url\\UrlNormalizer);$/' tests/Service/Ingest/EntryIngestorTest.php
perl -pi -e 's/^(use App\\Tests\\DbTestCase;)$/$1\nuse App\\Tests\\Support\\EntryIngestors;/' tests/Service/Ingest/EntryIngestorTest.php
```

**`tests/Service/Ingest/EntryIngestorCategoriesTest.php`**, in `setUp()`, replace:
```php
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $this->em->getRepository(Category::class);
        $this->ingestor = new EntryIngestor(
            $this->em,
            $entryRepository,
            new EntrySanitizer(),
            new UrlNormalizer(),
            new EntryCategoryWriter($this->em, $categoryRepository, new CategoryNormalizer()),
            new NaiveUtcClock(new MockClock('2026-09-21 12:00:00')),
            new PlatformEntryRules([]),
        );
```
with:
```php
        $this->ingestor = EntryIngestors::build($this->em, new MockClock('2026-09-21 12:00:00'));
```
```bash
perl -ni -e 'print unless /^use App\\(?:Entity\\Category|Entity\\Entry|Repository\\CategoryRepository|Repository\\EntryRepository|Service\\Category\\CategoryNormalizer|Service\\Clock\\NaiveUtcClock|Service\\Ingest\\EntryCategoryWriter|Service\\Ingest\\Platform\\PlatformEntryRules|Service\\Sanitize\\EntrySanitizer|Service\\Url\\UrlNormalizer);$/' tests/Service/Ingest/EntryIngestorCategoriesTest.php
perl -pi -e 's/^(use App\\Tests\\DbTestCase;)$/$1\nuse App\\Tests\\Support\\EntryIngestors;/' tests/Service/Ingest/EntryIngestorCategoriesTest.php
```

**`tests/Service/Subscription/FirstFetchRecorderTest.php`**, in `setUp()`, replace:
```php
            new EntryIngestor(
                $this->em,
                $this->em->getRepository(Entry::class),
                new EntrySanitizer(),
                new UrlNormalizer(),
                new EntryCategoryWriter(
                    $this->em,
                    $this->em->getRepository(Category::class),
                    new CategoryNormalizer(),
                ),
                new NaiveUtcClock($clock),
                new PlatformEntryRules([]),
            ),
```
with:
```php
            EntryIngestors::build($this->em, $clock),
```
```bash
perl -ni -e 'print unless /^use App\\(?:Entity\\Category|Service\\Category\\CategoryNormalizer|Service\\Clock\\NaiveUtcClock|Service\\Ingest\\EntryCategoryWriter|Service\\Ingest\\EntryIngestor|Service\\Ingest\\Platform\\PlatformEntryRules|Service\\Sanitize\\EntrySanitizer|Service\\Url\\UrlNormalizer);$/' tests/Service/Subscription/FirstFetchRecorderTest.php
perl -pi -e 's/^(use App\\Tests\\Service\\Search\\RecordingSearchIndexWriter;)$/$1\nuse App\\Tests\\Support\\EntryIngestors;/' tests/Service/Subscription/FirstFetchRecorderTest.php
```

**`tests/Service/Subscription/SubscriptionServiceTest.php`**, in `service()`, replace:
```php
                new EntryIngestor(
                    $this->em,
                    $this->em->getRepository(Entry::class),
                    new EntrySanitizer(),
                    new UrlNormalizer(),
                    new EntryCategoryWriter(
                        $this->em,
                        $this->em->getRepository(Category::class),
                        new CategoryNormalizer(),
                    ),
                    new NaiveUtcClock($clock),
                    new PlatformEntryRules([]),
                ),
```
with:
```php
                EntryIngestors::build($this->em, $clock),
```
```bash
perl -ni -e 'print unless /^use App\\(?:Entity\\Category|Service\\Category\\CategoryNormalizer|Service\\Clock\\NaiveUtcClock|Service\\Ingest\\EntryCategoryWriter|Service\\Ingest\\EntryIngestor|Service\\Ingest\\Platform\\PlatformEntryRules|Service\\Sanitize\\EntrySanitizer|Service\\Url\\UrlNormalizer);$/' tests/Service/Subscription/SubscriptionServiceTest.php
perl -pi -e 's/^(use App\\Tests\\Service\\Search\\RecordingSearchIndexWriter;)$/$1\nuse App\\Tests\\Support\\EntryIngestors;/' tests/Service/Subscription/SubscriptionServiceTest.php
```

Then:
```bash
git grep -n "new EntryIngestor(" -- tests
```
Expected: only `tests/Support/EntryIngestors.php`.

- [ ] **Step 4: Run the four suites**

Run: `php bin/phpunit tests/Service/Ingest tests/Service/Subscription/FirstFetchRecorderTest.php tests/Service/Subscription/SubscriptionServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion check**

In `src/Service/Ingest/EntryIngestor.php` `updateFeedMetadata()`, change `mb_substr($parsed->siteUrl, 0, self::URL_MAX)` to `mb_substr($parsed->siteUrl, 1, self::URL_MAX)`. Expected: `testOverlongFeedMetadataIsCutToItsColumns` fails. Restore by hand.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the four test files (an unused import is a WARNING: remove it by hand if a perl pattern missed one).
```bash
git add tests/Service/Ingest/EntryIngestorTest.php tests/Service/Ingest/EntryIngestorCategoriesTest.php tests/Service/Subscription/FirstFetchRecorderTest.php tests/Service/Subscription/SubscriptionServiceTest.php
git commit -m "refactor(#1166): pin the feed metadata limits and build every test ingestor through one helper"
```

---

### Task B2: `EntryImageWriter`

**Files:**
- Create: `src/Service/Ingest/EntryImageWriter.php`
- Create: `tests/Service/Ingest/EntryImageWriterTest.php`
- Modify: `src/Service/Ingest/EntryIngestor.php` (constructor, two call sites, three methods go, imports, one docblock line)
- Modify: `src/Service/Ingest/EntryMediaAssembler.php` (one docblock line)
- Modify: `tests/Support/EntryIngestors.php` (the ingestor's image argument)

**Interfaces:**
- Produces: `App\Service\Ingest\EntryImageWriter::__construct(NaiveUtcClock $clock)`, `::write(Entry $entry, DeclaredImage $image): bool` (was `EntryIngestor::storeImage()`), `::writeOrMarkNone(Entry $entry, ?DeclaredImage $image): void` (was `applyImage()`).
- `EntryIngestor::__construct(EntityManagerInterface, EntryRepository, EntrySanitizer, UrlNormalizer, EntryCategoryWriter, EntryImageWriter $imageWriter, PlatformEntryRules)`: the `NaiveUtcClock` slot takes the writer. B3 reorders this.

- [ ] **Step 1: Write the failing test**

`tests/Service/Ingest/EntryImageWriterTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\DeclaredImage;
use App\Service\Ingest\EntryImageWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class EntryImageWriterTest extends TestCase
{
    public function testANativeHttpsImageWithBothDimensionsIsStoredVerified(): void
    {
        $entry = $this->entry();

        $stored = $this->writer()->write($entry, new DeclaredImage('https://img.example.com/a.jpg', 800, 600));

        self::assertTrue($stored);
        self::assertSame('https://img.example.com/a.jpg', $entry->getImage()->getUrl());
        self::assertSame(800, $entry->getImage()->getWidth());
        self::assertSame(600, $entry->getImage()->getHeight());
        self::assertSame('2026-09-21 12:00:00', $entry->getImage()->getCheckedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAnUpgradedHttpImageIsStoredPendingVerification(): void
    {
        $entry = $this->entry();

        $stored = $this->writer()->write($entry, new DeclaredImage('http://img.example.com/a.jpg', 800, 600));

        self::assertTrue($stored);
        self::assertSame('https://img.example.com/a.jpg', $entry->getImage()->getUrl());
        self::assertNull($entry->getImage()->getCheckedAt());
    }

    public function testAnImageWithoutAStorableUrlLeavesTheEntryAsItWas(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://img.example.com/old.jpg', null, null);

        $stored = $this->writer()->write($entry, new DeclaredImage('/relative.jpg', 800, 600));

        self::assertFalse($stored);
        self::assertSame('https://img.example.com/old.jpg', $entry->getImage()->getUrl());
    }

    public function testNoDeclaredImageMarksTheEntryAsHavingNone(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://img.example.com/old.jpg', 10, 10);

        $this->writer()->writeOrMarkNone($entry, null);

        self::assertNull($entry->getImage()->getUrl());
        self::assertTrue($entry->getImage()->isMissing());
    }

    public function testAnUnstorableDeclaredImageAlsoMarksTheEntryAsHavingNone(): void
    {
        $entry = $this->entry();
        $entry->getImage()->storePending('https://img.example.com/old.jpg', 10, 10);

        $this->writer()->writeOrMarkNone($entry, new DeclaredImage('/relative.jpg', 800, 600));

        self::assertNull($entry->getImage()->getUrl());
        self::assertTrue($entry->getImage()->isMissing());
    }

    public function testAStorableDeclaredImageIsWritten(): void
    {
        $entry = $this->entry();

        $this->writer()->writeOrMarkNone($entry, new DeclaredImage('https://img.example.com/a.jpg', 800, 600));

        self::assertSame('https://img.example.com/a.jpg', $entry->getImage()->getUrl());
    }

    private function writer(): EntryImageWriter
    {
        return new EntryImageWriter(new NaiveUtcClock(new MockClock('2026-09-21 12:00:00', 'UTC')));
    }

    private function entry(): Entry
    {
        $at = new \DateTimeImmutable('2026-09-21 12:00:00');

        return new Entry(new Feed('https://example.com/feed'), 'g-1', 'https://example.com/1', 'Title', $at, $at);
    }
}
```

- [ ] **Step 2: Run it to watch it fail**

Run: `php bin/phpunit tests/Service/Ingest/EntryImageWriterTest.php`
Expected: FAIL with `Class "App\Service\Ingest\EntryImageWriter" not found`.

- [ ] **Step 3: Write `EntryImageWriter`**

`src/Service/Ingest/EntryImageWriter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Image\DeclaredImage;
use App\Service\Url\HttpsImageUrl;

/** Stores a feed-declared image on an entry: trusted at ingest when natively https and fully sized, else pending. */
final readonly class EntryImageWriter
{
    public function __construct(private NaiveUtcClock $clock)
    {
    }

    /** Whether the image had a URL worth storing; one without leaves the entry as it was. */
    public function write(Entry $entry, DeclaredImage $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        if (self::trustedAtIngest($image)) {
            $entry->getImage()->storeVerified($url, $image->width, $image->height, $this->clock->now());
        } else {
            $entry->getImage()->storePending($url, $image->width, $image->height);
        }

        return true;
    }

    public function writeOrMarkNone(Entry $entry, ?DeclaredImage $image): void
    {
        if ($image === null || !$this->write($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }

    private static function trustedAtIngest(DeclaredImage $image): bool
    {
        return $image->width !== null
            && $image->height !== null
            && !$image->declaresBeacon()
            && HttpsImageUrl::isNativeHttps($image->url);
    }
}
```

- [ ] **Step 4: The ingestor uses it**

In `src/Service/Ingest/EntryIngestor.php`:
- Replace `        private readonly NaiveUtcClock $clock,` with `        private readonly EntryImageWriter $imageWriter,`.
- Replace `            $this->applyImage($entry, $parsedEntry->media->image);` with `            $this->imageWriter->writeOrMarkNone($entry, $parsedEntry->media->image);`.
- Replace `            if ($this->storeImage($entry, $image)) {` with `            if ($this->imageWriter->write($entry, $image)) {`.
- Replace `    /** The lead passes the same https-upgrading gate applyImage uses, so media[0] stays the persisted lead. */` with `    /** The lead passes EntryImageWriter::write's https-upgrading gate too, so media[0] stays the stored lead. */`.
- Delete the three methods `applyImage()`, `storeImage()` and `trustedAtIngest()`, that is this block and the blank line after it:
```php
    private function applyImage(Entry $entry, ?DeclaredImage $image): void
    {
        if ($image === null || !$this->storeImage($entry, $image)) {
            $entry->getImage()->storePending(null, null, null);
        }
    }

    private function storeImage(Entry $entry, DeclaredImage $image): bool
    {
        $url = HttpsImageUrl::orNullUpgrading($image->url);
        if ($url === null) {
            return false;
        }
        if (self::trustedAtIngest($image)) {
            $entry->getImage()->storeVerified($url, $image->width, $image->height, $this->clock->now());
        } else {
            $entry->getImage()->storePending($url, $image->width, $image->height);
        }

        return true;
    }

    private static function trustedAtIngest(DeclaredImage $image): bool
    {
        return $image->width !== null
            && $image->height !== null
            && !$image->declaresBeacon()
            && HttpsImageUrl::isNativeHttps($image->url);
    }
```
- Delete the imports `use App\Service\Clock\NaiveUtcClock;`, `use App\Service\Image\DeclaredImage;` and `use App\Service\Url\HttpsImageUrl;`.

In `src/Service/Ingest/EntryMediaAssembler.php`, replace:
```php
 * passes the same https-upgrading gate `EntryIngestor::storeImage` uses, so
```
with:
```php
 * passes the same https-upgrading gate `EntryImageWriter::write` uses, so
```

In `tests/Support/EntryIngestors.php`, replace `            new NaiveUtcClock($clock),` with `            new EntryImageWriter(new NaiveUtcClock($clock)),`, and add `use App\Service\Ingest\EntryImageWriter;` directly after `use App\Service\Ingest\EntryCategoryWriter;`.

- [ ] **Step 5: Run the ingest suites**

Run: `php bin/phpunit tests/Service/Ingest tests/Service/FillMissingImagesTest.php tests/Service/Refresh tests/Service/Subscription/FirstFetchRecorderTest.php`
Expected: PASS, including every image test in `EntryIngestorTest` and `testRefreshBackfillsTheImageOntoAnAlreadyStoredEntryThatLacksOne`.

- [ ] **Step 6: Deletion check**

In `EntryImageWriter::trustedAtIngest()`, drop the `&& !$image->declaresBeacon()` line. Expected: `EntryIngestorTest::testANativeHttpsDeclaredBeaconStaysPending` fails. Restore by hand.

- [ ] **Step 7: Gates and commit**

Run: `bin/console cache:clear && bin/console lint:container && composer check && composer md`, then the PhpStorm inspections on the changed files.
```bash
git add src/Service/Ingest/EntryImageWriter.php src/Service/Ingest/EntryIngestor.php src/Service/Ingest/EntryMediaAssembler.php tests/Service/Ingest/EntryImageWriterTest.php tests/Support/EntryIngestors.php
git commit -m "refactor(#1166): an entry image writer owns trusted-at-ingest versus pending"
```

---

### Task B3: `IncomingEntry` and `IngestedEntryFactory`; `EntryIngestor` is `final readonly`

**Files:**
- Create: `src/Service/Ingest/IncomingEntry.php`, `src/Service/Ingest/IngestedEntryFactory.php`
- Modify: `src/Service/Ingest/EntryIngestor.php` (rewritten in full; the docblocks it keeps are verbatim)
- Modify: `tests/Support/EntryIngestors.php` (the ingestor assembly)
- Create: `tests/Service/Ingest/IngestedEntryFactoryTest.php`

**Interfaces:**
- Consumes: `EntryImageWriter` (B2); `EntryEffectiveDate::for(?\DateTimeImmutable, FeedIngestContext)`, `EntrySnippet::from(?string)`, `EntryMediaAssembler::assemble()`, `EntryDeduplicator`, all unchanged.
- Produces:
  - `App\Service\Ingest\IncomingEntry::__construct(ParsedEntry $parsed, string $guidHash, ?string $urlHash)`, three public readonly fields.
  - `App\Service\Ingest\IngestedEntryFactory::__construct(EntrySanitizer $sanitizer, EntryImageWriter $imageWriter)`, `::create(Feed $feed, IncomingEntry $incoming, FeedIngestContext $context): Entry`. It persists nothing.
  - `EntryIngestor::__construct(EntityManagerInterface $em, EntryRepository $entryRepository, UrlNormalizer $urlNormalizer, EntryCategoryWriter $categoryWriter, PlatformEntryRules $platformRules, IngestedEntryFactory $entryFactory, EntryImageWriter $imageWriter)`. `ingest()` and `fillMissingImages()` keep their signatures.

- [ ] **Step 1: Write the failing test**

`tests/Service/Ingest/IngestedEntryFactoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Feed;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Ingest\IncomingEntry;
use App\Service\Ingest\IngestedEntryFactory;
use App\Service\Parser\ParsedEntry;
use App\Service\Sanitize\EntrySanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class IngestedEntryFactoryTest extends TestCase
{
    public function testBuildsTheRowFromTheItemCutToItsColumns(): void
    {
        $feed = new Feed('https://example.com/feed');
        $parsed = new ParsedEntry(
            guid: 'g-1',
            url: 'https://example.com/' . str_repeat('u', 3000),
            title: 'T' . str_repeat('t', 2000),
            author: 'A' . str_repeat('a', 500),
            summary: '<p>A &amp; B summary</p>',
            contentHtml: '<p>Body</p><script>evil()</script>',
            publishedAt: new \DateTimeImmutable('2026-09-20 08:00:00'),
        );

        $entry = $this->factory()->create(
            $feed,
            new IncomingEntry($parsed, hash('sha256', 'g-1'), 'url-hash'),
            self::context(),
        );

        self::assertSame($feed, $entry->getFeed());
        self::assertSame('g-1', $entry->getGuid());
        self::assertSame('https://example.com/' . str_repeat('u', 2028), $entry->getUrl());
        self::assertSame('url-hash', $entry->getUrlHash());
        self::assertSame('T' . str_repeat('t', 1023), $entry->getTitle());
        self::assertSame('A' . str_repeat('a', 254), $entry->getAuthor());
        self::assertSame('A & B summary', $entry->getSummary());
        self::assertStringNotContainsString('script', (string) $entry->getContentHtml());
        self::assertSame('2026-09-20 08:00:00', $entry->getPublishedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-21 12:00:00', $entry->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-20 08:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
        self::assertTrue($entry->getImage()->isMissing());
    }

    public function testAnItemWithoutUrlOrAuthorKeepsBothNullAndSummarisesItsBody(): void
    {
        $parsed = new ParsedEntry('g-2', null, 'Title', null, null, '<p>Only a body</p>', null);

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntry($parsed, hash('sha256', 'g-2'), null),
            self::context(),
        );

        self::assertNull($entry->getUrl());
        self::assertNull($entry->getUrlHash());
        self::assertNull($entry->getAuthor());
        self::assertSame('Only a body', $entry->getSummary());
        self::assertNull($entry->getPublishedAt());
        self::assertSame('2026-09-21 12:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    private function factory(): IngestedEntryFactory
    {
        return new IngestedEntryFactory(
            new EntrySanitizer(),
            new EntryImageWriter(new NaiveUtcClock(new MockClock('2026-09-21 12:00:00', 'UTC'))),
        );
    }

    private static function context(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable('2026-09-21 12:00:00'), null);
    }
}
```
- [ ] **Step 2: Run it to watch it fail**

Run: `php bin/phpunit tests/Service/Ingest/IngestedEntryFactoryTest.php`
Expected: FAIL with `Class "App\Service\Ingest\IncomingEntry" not found`.

- [ ] **Step 3: Write the value and the factory**

`src/Service/Ingest/IncomingEntry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Service\Parser\ParsedEntry;

/** A parsed item with the two identities that dedup and the stored row both use, hashed once. */
final readonly class IncomingEntry
{
    public function __construct(
        public ParsedEntry $parsed,
        public string $guidHash,
        public ?string $urlHash,
    ) {
    }
}
```

`src/Service/Ingest/IngestedEntryFactory.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedMediaBundle;
use App\Service\Sanitize\EntrySanitizer;

/** Builds the Entry row for an incoming item, cut to its column limits. It persists nothing. */
final readonly class IngestedEntryFactory
{
    private const int TITLE_MAX = 1024;
    private const int AUTHOR_MAX = 255;
    private const int URL_MAX = 2048;

    public function __construct(
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
    ) {
    }

    public function create(Feed $feed, IncomingEntry $incoming, FeedIngestContext $context): Entry
    {
        $parsed = $incoming->parsed;
        $entry = new Entry(
            $feed,
            $parsed->guid,
            self::cut($parsed->url, self::URL_MAX),
            mb_substr($parsed->title, 0, self::TITLE_MAX),
            $context->fetchedAt,
            EntryEffectiveDate::for($parsed->publishedAt, $context),
            $incoming->urlHash,
        );
        $entry->setAuthor(self::cut($parsed->author, self::AUTHOR_MAX));
        $entry->setSummary(EntrySnippet::from($parsed->summary ?? $parsed->contentHtml));
        $entry->setContentHtml($this->sanitizer->sanitize($parsed->contentHtml));
        $entry->setPublishedAt($parsed->publishedAt);
        $entry->setDiscussion($parsed->discussion);
        $this->imageWriter->writeOrMarkNone($entry, $parsed->media->image);
        self::attachMedia($entry, $parsed);

        return $entry;
    }

    private static function cut(?string $value, int $maxLength): ?string
    {
        return null === $value ? null : mb_substr($value, 0, $maxLength);
    }

    /** The lead passes EntryImageWriter::write's https-upgrading gate too, so media[0] stays the stored lead. */
    private static function attachMedia(Entry $entry, ParsedEntry $parsed): void
    {
        $bundle = $parsed->media->mediaBundle ?? new ParsedMediaBundle();
        $assembled = EntryMediaAssembler::assemble($parsed->media->image, $bundle->media, $bundle->attachments);
        $entry->setMedia($assembled->media, $assembled->attachments);
    }
}
```

- [ ] **Step 4: Run the factory test**

Run: `php bin/phpunit tests/Service/Ingest/IngestedEntryFactoryTest.php`
Expected: PASS.

- [ ] **Step 5: Rewrite `EntryIngestor`**

`src/Service/Ingest/EntryIngestor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Ingest\Platform\PlatformEntryRules;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Url\UrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a ParsedFeed into persisted Entry rows: dedupes against the feed's
 * existing entries on stable URL (falling back to GUID hash), sanitizes
 * content, truncates to column limits, and refreshes feed metadata. Caller
 * flushes.
 */
final readonly class EntryIngestor
{
    private const int FEED_TITLE_MAX = 512;
    private const int SITE_URL_MAX = 2048;

    /**
     * feed.description is a TEXT column, so nothing but this bounds it. It is
     * reduced to plain text on every read of the sidebar bootstrap — once per
     * subscription, for the whole library — so a feed that ships its About
     * page as a <description> would tax every page load forever. Generous
     * enough that no real feed notices: the longest in a 111-feed library is
     * 617 characters.
     */
    private const int FEED_DESCRIPTION_MAX = 4000;

    public function __construct(
        private EntityManagerInterface $em,
        private EntryRepository $entryRepository,
        private UrlNormalizer $urlNormalizer,
        private EntryCategoryWriter $categoryWriter,
        private PlatformEntryRules $platformRules,
        private IngestedEntryFactory $entryFactory,
        private EntryImageWriter $imageWriter,
    ) {
    }

    /**
     * @param FeedIngestContext $context the run instant shared by every entry
     *        this call ingests, and the feed's previous fetch — together they
     *        decide where each entry lands in the list (see EntryEffectiveDate)
     *
     * @return list<Entry> the entries created, in the order the caller can
     *         later index them — each one has no id until the caller flushes
     */
    public function ingest(Feed $feed, ParsedFeed $parsed, FeedIngestContext $context): array
    {
        $this->updateFeedMetadata($feed, $parsed);

        if ($parsed->entries === []) {
            return [];
        }

        $incoming = array_map(
            fn (ParsedEntry $entry): IncomingEntry => $this->incoming($this->platformRules->apply($entry)),
            $parsed->entries,
        );
        $deduplicator = $this->deduplicatorFor($feed, $incoming);

        $created = [];
        $newPairs = [];
        foreach ($incoming as $candidate) {
            if ($deduplicator->isDuplicate($candidate->guidHash, $candidate->urlHash)) {
                continue;
            }
            $deduplicator->remember($candidate->guidHash, $candidate->urlHash);

            $entry = $this->entryFactory->create($feed, $candidate, $context);
            $this->em->persist($entry);
            $created[] = $entry;
            $newPairs[] = [$entry, $candidate->parsed];
        }

        $this->categoryWriter->attach($newPairs);

        return $created;
    }

    /**
     * Fill in the image on entries ingested before the feed's image was
     * persisted (#148), matching by guid hash against a fresh parse.
     *
     * Only entries that never had a judged image are touched — a feed that
     * later drops or downgrades its images must never erase what we have. The
     * archive this can reach is bounded by what the feed still serves (15–50
     * items against thousands stored), so this is opportunistic repair, not a
     * migration. Caller flushes. Returns the number updated.
     */
    public function fillMissingImages(Feed $feed, ParsedFeed $parsed): int
    {
        if ($parsed->entries === []) {
            return 0;
        }

        $hashes = $this->guidHashesOf($parsed->entries);
        $existing = $this->entryRepository->findByFeedIndexedByGuidHash($feed, $hashes);

        $updated = 0;
        foreach ($parsed->entries as $parsedEntry) {
            $image = $parsedEntry->media->image;
            if ($image === null) {
                continue;
            }
            $entry = $existing[self::guidHash($parsedEntry->guid)] ?? null;
            if ($entry === null || !$entry->getImage()->isMissing()) {
                continue;
            }
            if ($this->imageWriter->write($entry, $image)) {
                $updated++;
            }
        }

        return $updated;
    }

    private function updateFeedMetadata(Feed $feed, ParsedFeed $parsed): void
    {
        if ($parsed->title !== null) {
            $feed->setTitle(mb_substr($parsed->title, 0, self::FEED_TITLE_MAX));
        }
        if ($parsed->siteUrl !== null) {
            $feed->setSiteUrl(mb_substr($parsed->siteUrl, 0, self::SITE_URL_MAX));
        }
        if ($parsed->description !== null) {
            $feed->setDescription(mb_substr($parsed->description, 0, self::FEED_DESCRIPTION_MAX));
        }
        // Guarded like the fields above: a feed that stops sending its <image>
        // on one fetch must not erase the logo the reader already shows.
        // FeedImageExtractor has already applied the scheme and length rules,
        // so no truncation belongs here.
        if ($parsed->imageUrl !== null) {
            $feed->setImageUrl($parsed->imageUrl);
        }
    }

    private function incoming(ParsedEntry $entry): IncomingEntry
    {
        return new IncomingEntry($entry, self::guidHash($entry->guid), $this->urlNormalizer->hash($entry->url));
    }

    /**
     * @param list<IncomingEntry> $incoming
     */
    private function deduplicatorFor(Feed $feed, array $incoming): EntryDeduplicator
    {
        return new EntryDeduplicator(
            $this->entryRepository->existingGuidHashesForFeed(
                $feed->requireId(),
                array_map(static fn (IncomingEntry $entry): string => $entry->guidHash, $incoming),
            ),
            $this->entryRepository->findExistingUrlHashes($feed, self::urlHashesOf($incoming)),
        );
    }

    /**
     * @param list<ParsedEntry> $entries
     *
     * @return list<string>
     */
    private function guidHashesOf(array $entries): array
    {
        return array_map(static fn (ParsedEntry $entry): string => self::guidHash($entry->guid), $entries);
    }

    /**
     * @param list<IncomingEntry> $incoming
     *
     * @return list<string> the url hashes of the entries that have one: a url-less item dedupes on GUID alone
     */
    private static function urlHashesOf(array $incoming): array
    {
        $hashes = [];
        foreach ($incoming as $entry) {
            if ($entry->urlHash !== null) {
                $hashes[] = $entry->urlHash;
            }
        }

        return $hashes;
    }

    private static function guidHash(string $guid): string
    {
        return hash('sha256', $guid);
    }
}
```

Notes on what moved and what did not:
- The old `ingest()` hashed each item twice; `incoming()` hashes once (D7). The repository calls run in the same order (`existingGuidHashesForFeed` first).
- The entry column limits and `applyMedia()` moved to `IngestedEntryFactory`; `applyImage()`/`storeImage()` moved to `EntryImageWriter` in B2.
- `fillMissingImages()` still reads the raw parse, without platform rules.
- The docblocks above are the unedited originals (D12).

- [ ] **Step 6: Re-assemble the test ingestor**

In `tests/Support/EntryIngestors.php`, replace the `withPlatformRules()` body from `        /** @var EntryRepository $entryRepository */` to the closing `        );` of `return new EntryIngestor(` with:
```php
        /** @var EntryRepository $entryRepository */
        $entryRepository = $em->getRepository(Entry::class);
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $em->getRepository(Category::class);
        $imageWriter = new EntryImageWriter(new NaiveUtcClock($clock));

        return new EntryIngestor(
            $em,
            $entryRepository,
            new UrlNormalizer(),
            new EntryCategoryWriter($em, $categoryRepository, new CategoryNormalizer()),
            $platformRules,
            new IngestedEntryFactory(new EntrySanitizer(), $imageWriter),
            $imageWriter,
        );
```
Add `use App\Service\Ingest\IngestedEntryFactory;` directly after `use App\Service\Ingest\EntryIngestor;`.

- [ ] **Step 7: Run the ingest and refresh suites**

```bash
bin/console cache:clear && bin/console lint:container
php bin/phpunit tests/Service/Ingest tests/Service/FillMissingImagesTest.php tests/Service/Refresh tests/Service/Subscription tests/Service/Maintenance
```
Expected: PASS.

- [ ] **Step 8: Deletion checks**

One at a time, restoring each by hand:
1. `IngestedEntryFactory::create()`: `mb_substr($parsed->title, 1, self::TITLE_MAX)`. Expected: `testBuildsTheRowFromTheItemCutToItsColumns` fails.
2. `EntryIngestor::ingest()`: delete `$deduplicator->remember($candidate->guidHash, $candidate->urlHash);`. Expected: `EntryIngestorTest::testTwoItemsWithTheSameUrlInOneBatchCreateOnlyOneRow` fails.
3. `EntryIngestor::incoming()`: pass `null` as the url hash. Expected: `EntryIngestorTest::testARevisedGuidWithTheSameUrlDoesNotCreateASecondRow` fails.
4. `IngestedEntryFactory::create()`: delete `$entry->setDiscussion($parsed->discussion);`. Expected: `EntryIngestorTest::testStoresTheParsedDiscussion` fails.

- [ ] **Step 9: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the changed files. `composer tramp` must report no chain of 3 or more hops through `EntryIngestor::ingest()` (D2: `FeedOutcomePersister::record` → `ingest` → `create` is 2).
```bash
git add src/Service/Ingest tests/Service/Ingest/IngestedEntryFactoryTest.php tests/Support/EntryIngestors.php
git commit -m "refactor(#1166): the ingestor hashes each item once and hands row construction to an entry factory"
```

---

### Task B4: Pin the catalog import's update, lock and move paths

**Files:**
- Modify: `tests/Service/Catalog/CatalogImporterTest.php` (four new tests, one helper, one assertion in `testReplaceKeepsACategoryThatStillHoldsALockedFeed`)

**Interfaces:** none; every test here passes on develop.

What these pin that nothing pinned before: the update branch of the category upsert (name, icon, colour, position, the `categoriesUpdated` count); a locked category keeps its row while its feeds are still imported; a feed the document lists under another category moves there; the feed details (`siteUrl`, `description`, `sourceFormat`, position) written on update; and the `lockedSkipped` count in `Replace` mode, both for a locked feed the document still lists (counted once) and for an unlisted locked feed plus the category it holds (counted twice).

- [ ] **Step 1: Add the pins**

In `tests/Service/Catalog/CatalogImporterTest.php`, inside `testReplaceKeepsACategoryThatStillHoldsALockedFeed()`, replace:
```php
        self::assertSame(0, $result->categoriesRemoved);

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(CatalogFeed::class)->findAll());
        self::assertCount(2, $this->em()->getRepository(CatalogCategory::class)->findAll());
```
with:
```php
        self::assertSame(0, $result->categoriesRemoved);
        self::assertSame(2, $result->lockedSkipped);

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(CatalogFeed::class)->findAll());
        self::assertCount(2, $this->em()->getRepository(CatalogCategory::class)->findAll());
```

Directly before `public function testPositionsFollowDocumentOrder(): void`, add:
```php
    public function testAnUpdateRewritesTheCategoryAndTheFeedFromTheDocument(): void
    {
        $this->importer()->import($this->parsed(
            '<outline text="Technology" key="technology" icon="memory" color="#3b82f6">'
            . '<outline type="rss" text="Filler" xmlUrl="https://filler.example.com/rss.xml"/>'
            . '<outline type="rss" text="Old title" xmlUrl="https://moved.example.com/rss.xml"/>'
            . '</outline>',
        ), CatalogImportMode::Merge);

        $result = $this->importer()->import($this->parsed(
            '<outline text="Science" key="science" icon="science" color="#10b981"></outline>'
            . '<outline text="Tech and Gadgets" key="technology" icon="devices" color="#ef4444">'
            . '<outline type="rss" text="New title" xmlUrl="https://moved.example.com/rss.xml"'
            . ' htmlUrl="https://moved.example.com/" description="A description" sourceFormat="scraped"/>'
            . '</outline>',
        ), CatalogImportMode::Merge);

        self::assertSame(1, $result->categoriesCreated);
        self::assertSame(1, $result->categoriesUpdated);
        self::assertSame(0, $result->feedsCreated);
        self::assertSame(1, $result->feedsUpdated);
        self::assertSame(0, $result->lockedSkipped);

        $this->em()->clear();
        $category = $this->em()->getRepository(CatalogCategory::class)->findOneBy(['key' => 'technology']);
        self::assertNotNull($category);
        self::assertSame('Tech and Gadgets', $category->getName());
        self::assertSame('devices', $category->getIcon());
        self::assertSame('#ef4444', $category->getColor());
        self::assertSame(1, $category->getPosition());
        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://moved.example.com/rss.xml',
        ]);
        self::assertNotNull($feed);
        self::assertSame('New title', $feed->getTitle());
        self::assertSame('technology', $feed->getCategory()->getKey());
        self::assertSame('https://moved.example.com/', $feed->getSiteUrl());
        self::assertSame('A description', $feed->getDescription());
        self::assertSame('scraped', $feed->getSourceFormat());
        self::assertSame(0, $feed->getPosition());
    }

    public function testAFeedListedUnderAnotherCategoryMovesThere(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Wanderer', 'url' => 'https://wanderer.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $result = $this->importer()->import(
            $this->document(
                [['title' => 'Wanderer', 'url' => 'https://wanderer.example.com/rss.xml']],
                'science',
                'Science',
            ),
            CatalogImportMode::Merge,
        );

        self::assertSame(1, $result->categoriesCreated);
        self::assertSame(1, $result->feedsUpdated);

        $this->em()->clear();
        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://wanderer.example.com/rss.xml',
        ]);
        self::assertNotNull($feed);
        self::assertSame('science', $feed->getCategory()->getKey());
    }

    public function testALockedCategoryKeepsItsRowWhileItsFeedsAreStillImported(): void
    {
        $this->importer()->import($this->document([]), CatalogImportMode::Merge);
        $category = $this->em()->getRepository(CatalogCategory::class)->findOneBy(['key' => 'technology']);
        self::assertNotNull($category);
        $category->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import(
            $this->document(
                [['title' => 'Inside', 'url' => 'https://inside.example.com/rss.xml']],
                'technology',
                'Renamed',
            ),
            CatalogImportMode::Merge,
        );

        self::assertSame(1, $result->lockedSkipped);
        self::assertSame(0, $result->categoriesUpdated);
        self::assertSame(1, $result->feedsCreated);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(CatalogCategory::class)->findOneBy(['key' => 'technology']);
        self::assertNotNull($reloaded);
        self::assertSame('Technology', $reloaded->getName());
        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://inside.example.com/rss.xml',
        ]);
        self::assertNotNull($feed);
        self::assertSame('technology', $feed->getCategory()->getKey());
    }

    public function testReplaceCountsALockedFeedTheDocumentStillListsOnce(): void
    {
        $document = $this->document([['title' => 'Mine', 'url' => 'https://mine.example.com/rss.xml']]);
        $this->importer()->import($document, CatalogImportMode::Merge);
        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Mine']);
        self::assertNotNull($feed);
        $feed->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import($document, CatalogImportMode::Replace);

        self::assertSame(1, $result->lockedSkipped);
        self::assertSame(0, $result->feedsUpdated);
        self::assertSame(0, $result->feedsRemoved);
        self::assertSame(1, $result->categoriesUpdated);
        self::assertSame(0, $result->categoriesRemoved);
    }

```
Directly before `private function twoCategoryDocumentInReverseAlphabeticalOrder(): ParsedCatalog`, keeping that method's docblock above it, insert (above the docblock's `/**`):
```php
    private function parsed(string $categoryOutlines): ParsedCatalog
    {
        $parser = self::getContainer()->get(CatalogDocument::class);
        self::assertInstanceOf(CatalogDocument::class, $parser);

        return $parser->parse(
            '<opml version="2.0"><head><title>t</title></head><body>' . $categoryOutlines . '</body></opml>',
        );
    }

```

- [ ] **Step 2: Run the pins**

Run: `php bin/phpunit tests/Service/Catalog/CatalogImporterTest.php`
Expected: PASS (pin).

- [ ] **Step 3: Deletion checks**

One at a time in `src/Service/Catalog/CatalogImporter.php`, restoring each by hand:
1. Delete `$category->setIcon($documentCategory->icon);`. Expected: `testAnUpdateRewritesTheCategoryAndTheFeedFromTheDocument` fails.
2. Delete `$feed->setCategory($category);`. Expected: `testAFeedListedUnderAnotherCategoryMovesThere` fails.
3. In `removeUnmentioned()`, replace the `if (!isset($keptFeedUrls[$url])) { … }` guard with its body, so every locked feed counts. Expected: `testReplaceCountsALockedFeedTheDocumentStillListsOnce` fails (2 instead of 1).

- [ ] **Step 4: Gates and commit**

Run: `composer check`, then the PhpStorm inspections on the test file.
```bash
git add tests/Service/Catalog/CatalogImporterTest.php
git commit -m "refactor(#1166): pin the catalog import's update, lock and move paths"
```

---

### Task B5: `CatalogImportPass`

**Files:**
- Create: `src/Service/Catalog/CatalogImportPass.php`
- Modify: `src/Service/Catalog/CatalogImporter.php` (rewritten in full)
- Create: `tests/Service/Catalog/CatalogImportPassTest.php`

**Interfaces:**
- Produces: `App\Service\Catalog\CatalogImportPass::__construct(EntityManagerInterface $em, array<CatalogCategory> $categories, array<CatalogFeed> $feeds)`, `public private(set) CatalogImportResult $result`, `::apply(ParsedCatalog $document): void`, `::removeUnmentioned(): void`.
- `CatalogImporter::import(ParsedCatalog, CatalogImportMode): CatalogImportResult` is unchanged.

- [ ] **Step 1: Write the failing test**

`tests/Service/Catalog/CatalogImportPassTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogDocumentCategory;
use App\Service\Catalog\CatalogDocumentFeed;
use App\Service\Catalog\CatalogImportPass;
use App\Service\Catalog\ParsedCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** CatalogImporterTest drives the same pass against the database; this one pins what it persists and removes. */
final class CatalogImportPassTest extends TestCase
{
    public function testANewDocumentPersistsEveryRowAndCountsIt(): void
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $row) use (&$persisted): void {
            $persisted[] = $row;
        });
        $em->expects(self::never())->method('remove');
        $pass = new CatalogImportPass($em, [], []);

        $pass->apply(new ParsedCatalog([
            new CatalogDocumentCategory('tech', 'Tech', 'memory', '#3b82f6', [
                new CatalogDocumentFeed('One', 'https://one.example.com/rss.xml', null, null, 'xml'),
            ]),
        ]));

        self::assertCount(2, $persisted);
        [$category, $feed] = $persisted;
        self::assertInstanceOf(CatalogCategory::class, $category);
        self::assertInstanceOf(CatalogFeed::class, $feed);
        self::assertSame($category, $feed->getCategory());
        self::assertSame(1, $pass->result->categoriesCreated);
        self::assertSame(1, $pass->result->feedsCreated);
        self::assertSame(0, $pass->result->categoriesUpdated);
        self::assertSame(0, $pass->result->feedsUpdated);
    }

    public function testRemovingUnmentionedRowsSparesLockedFeedsAndTheCategoriesHoldingThem(): void
    {
        $kept = new CatalogCategory('kept', 'Kept', 'memory', '#3b82f6');
        $holding = new CatalogCategory('holding', 'Holding', 'memory', '#3b82f6');
        $dropped = new CatalogCategory('dropped', 'Dropped', 'memory', '#3b82f6');
        $lockedFeed = new CatalogFeed($holding, 'Locked', 'https://locked.example.com/rss.xml');
        $lockedFeed->setLocked(true);
        $staleFeed = new CatalogFeed($kept, 'Stale', 'https://stale.example.com/rss.xml');
        $removed = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $row) use (&$removed): void {
            $removed[] = $row;
        });
        $pass = new CatalogImportPass($em, [$kept, $holding, $dropped], [$lockedFeed, $staleFeed]);
        $pass->apply(new ParsedCatalog([new CatalogDocumentCategory('kept', 'Kept', 'memory', '#3b82f6', [])]));

        $pass->removeUnmentioned();

        self::assertSame([$staleFeed, $dropped], $removed);
        self::assertSame(1, $pass->result->feedsRemoved);
        self::assertSame(1, $pass->result->categoriesRemoved);
        self::assertSame(2, $pass->result->lockedSkipped);
        self::assertSame(1, $pass->result->categoriesUpdated);
    }
}
```

- [ ] **Step 2: Run it to watch it fail**

Run: `php bin/phpunit tests/Service/Catalog/CatalogImportPassTest.php`
Expected: FAIL with `Class "App\Service\Catalog\CatalogImportPass" not found`.

- [ ] **Step 3: Write `CatalogImportPass`**

`src/Service/Catalog/CatalogImportPass.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use Doctrine\ORM\EntityManagerInterface;

/** One import's working state: the rows found by natural key, what the document mentioned, and the counts so far. */
final class CatalogImportPass
{
    public private(set) CatalogImportResult $result;

    /** @var array<string, CatalogCategory> */
    private array $categoriesByKey = [];

    /** @var array<string, CatalogFeed> */
    private array $feedsByUrl = [];

    /** @var array<string, CatalogDocumentCategory> */
    private array $mentionedCategories = [];

    /** @var array<string, CatalogDocumentFeed> */
    private array $mentionedFeeds = [];

    /**
     * @param array<CatalogCategory> $categories
     * @param array<CatalogFeed>     $feeds
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        array $categories,
        array $feeds,
    ) {
        $this->result = new CatalogImportResult();
        foreach ($categories as $category) {
            $this->categoriesByKey[$category->getKey()] = $category;
        }
        foreach ($feeds as $feed) {
            $this->feedsByUrl[$feed->getUrl()] = $feed;
        }
    }

    public function apply(ParsedCatalog $document): void
    {
        foreach ($document->categories as $position => $documentCategory) {
            $category = $this->applyCategory($documentCategory, $position);
            foreach ($documentCategory->feeds as $feedPosition => $documentFeed) {
                $this->applyFeed($documentFeed, $category, $feedPosition);
            }
        }
    }

    public function removeUnmentioned(): void
    {
        $this->removeUnmentionedCategories($this->removeUnmentionedFeeds());
    }

    private function applyCategory(CatalogDocumentCategory $documentCategory, int $position): CatalogCategory
    {
        $this->mentionedCategories[$documentCategory->key] = $documentCategory;
        $existing = $this->categoriesByKey[$documentCategory->key] ?? null;
        if (null === $existing) {
            return $this->createCategory($documentCategory, $position);
        }
        if ($existing->isLocked()) {
            // Locking protects the category row, not the catalog membership underneath it.
            $this->result = $this->result->with(lockedSkipped: 1);

            return $existing;
        }

        $existing->setName($documentCategory->name);
        $existing->setIcon($documentCategory->icon);
        $existing->setColor($documentCategory->color);
        $existing->setPosition($position);
        $this->result = $this->result->with(categoriesUpdated: 1);

        return $existing;
    }

    private function createCategory(CatalogDocumentCategory $documentCategory, int $position): CatalogCategory
    {
        $category = new CatalogCategory(
            $documentCategory->key,
            $documentCategory->name,
            $documentCategory->icon,
            $documentCategory->color,
        );
        $category->setPosition($position);
        $this->em->persist($category);
        $this->result = $this->result->with(categoriesCreated: 1);

        return $category;
    }

    private function applyFeed(CatalogDocumentFeed $documentFeed, CatalogCategory $category, int $position): void
    {
        $this->mentionedFeeds[$documentFeed->url] = $documentFeed;
        $existing = $this->feedsByUrl[$documentFeed->url] ?? null;
        if (null !== $existing && $existing->isLocked()) {
            $this->result = $this->result->with(lockedSkipped: 1);

            return;
        }

        $feed = null === $existing
            ? $this->createFeed($documentFeed, $category)
            : $this->updateFeed($existing, $documentFeed, $category);
        $feed->setSiteUrl($documentFeed->siteUrl);
        $feed->setDescription($documentFeed->description);
        $feed->setSourceFormat($documentFeed->sourceFormat);
        $feed->setPosition($position);
    }

    private function createFeed(CatalogDocumentFeed $documentFeed, CatalogCategory $category): CatalogFeed
    {
        $feed = new CatalogFeed($category, $documentFeed->title, $documentFeed->url);
        $this->em->persist($feed);
        $this->result = $this->result->with(feedsCreated: 1);

        return $feed;
    }

    /** Matched on URL, so the row and its cached favicon survive the re-import. */
    private function updateFeed(
        CatalogFeed $feed,
        CatalogDocumentFeed $documentFeed,
        CatalogCategory $category,
    ): CatalogFeed {
        $feed->setTitle($documentFeed->title);
        $feed->setCategory($category);
        $this->result = $this->result->with(feedsUpdated: 1);

        return $feed;
    }

    /**
     * @return array<string, CatalogFeed> the locked feeds by category key, whose categories must stay: removing one
     *                                    would cascade to its locked feed
     */
    private function removeUnmentionedFeeds(): array
    {
        $lockedFeedsByCategoryKey = [];
        foreach ($this->feedsByUrl as $feed) {
            if ($feed->isLocked()) {
                $lockedFeedsByCategoryKey[$feed->getCategory()->getKey()] = $feed;
            }
            $this->removeFeedUnlessMentioned($feed);
        }

        return $lockedFeedsByCategoryKey;
    }

    private function removeFeedUnlessMentioned(CatalogFeed $feed): void
    {
        if (isset($this->mentionedFeeds[$feed->getUrl()])) {
            return;
        }
        if ($feed->isLocked()) {
            $this->result = $this->result->with(lockedSkipped: 1);

            return;
        }

        $this->em->remove($feed);
        $this->result = $this->result->with(feedsRemoved: 1);
    }

    /**
     * @param array<string, CatalogFeed> $lockedFeedsByCategoryKey
     */
    private function removeUnmentionedCategories(array $lockedFeedsByCategoryKey): void
    {
        foreach ($this->categoriesByKey as $category) {
            $key = $category->getKey();
            if (isset($this->mentionedCategories[$key])) {
                continue;
            }
            if (isset($lockedFeedsByCategoryKey[$key]) || $category->isLocked()) {
                $this->result = $this->result->with(lockedSkipped: 1);

                continue;
            }
            // Its remaining feeds go with it through ON DELETE CASCADE; every locked feed kept its category above.
            $this->em->remove($category);
            $this->result = $this->result->with(categoriesRemoved: 1);
        }
    }
}
```

- [ ] **Step 4: Rewrite `CatalogImporter`**

`src/Service/Catalog/CatalogImporter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a validated catalog document in one transaction, matching rows by natural key (category key, feed URL) so
 * a surviving feed keeps its cached favicon. Locked rows belong to the admin: never overwritten, never removed.
 */
final readonly class CatalogImporter
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogFeedRepository $feeds,
        private EntityManagerInterface $em,
    ) {
    }

    public function import(ParsedCatalog $document, CatalogImportMode $mode): CatalogImportResult
    {
        return $this->em->wrapInTransaction(function () use ($document, $mode): CatalogImportResult {
            $pass = new CatalogImportPass($this->em, $this->categories->findAllOrdered(), $this->feeds->findAll());
            $pass->apply($document);
            if (CatalogImportMode::Replace === $mode) {
                $pass->removeUnmentioned();
            }

            $this->em->flush();

            return $pass->result;
        });
    }
}
```
The repositories are read in the old order (categories, then feeds). The removal order is the old one too: every unmentioned feed first, then every unmentioned category.

- [ ] **Step 5: Run the catalog suites**

Run: `php bin/phpunit tests/Service/Catalog tests/Command/ImportCatalogCommandTest.php tests/Controller/Admin/AdminCatalogImportControllerTest.php`
Expected: PASS, B4's pins included.

- [ ] **Step 6: Deletion checks**

One at a time, restoring each by hand:
1. `CatalogImportPass::applyFeed()`: delete `$feed->setSourceFormat($documentFeed->sourceFormat);`. Expected: `CatalogImporterTest::testAnUpdateRewritesTheCategoryAndTheFeedFromTheDocument` fails.
2. `removeUnmentionedCategories()`: drop `isset($lockedFeedsByCategoryKey[$key]) || `. Expected: `CatalogImporterTest::testReplaceKeepsACategoryThatStillHoldsALockedFeed` and `CatalogImportPassTest::testRemovingUnmentionedRows…` fail.
3. `applyCategory()`: move `$this->mentionedCategories[…] = $documentCategory;` into the `null === $existing` branch only. Expected: `CatalogImportPassTest::testRemovingUnmentionedRows…` fails (the kept category is removed).

- [ ] **Step 7: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the three files. `composer md` must be clean for both catalog files.
```bash
git add src/Service/Catalog/CatalogImportPass.php src/Service/Catalog/CatalogImporter.php tests/Service/Catalog/CatalogImportPassTest.php
git commit -m "refactor(#1166): a catalog import pass holds the indexes and the counts; the category upsert is its own method"
```

---

### Finishing PR B

- [ ] **Step 1: The gates on the whole branch**

```bash
composer check
composer md
php bin/phpunit
docker compose exec php composer test
composer infection:diff
```
Expected: all green.
- Before the MySQL leg, check that the containers are current; `docker compose exec php bin/console cache:clear` if the compiled container predates this branch.
- An escaped mutant on a touched line gets a killing test in the task that owns the line. In particular: every `mb_substr` limit in `IngestedEntryFactory` and `EntryIngestor::updateFeedMetadata()`, and every `->with(…: 1)` in `CatalogImportPass`, must be killed by a content or count assertion.
- Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: nothing from a file this PR touched.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'`. ERROR and WARNING block.

- [ ] **Step 3: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`. It runs its angle reviewers (reuse, simplification, efficiency, altitude) as parallel agents. Apply only fixes that keep every gate green and undo no decision in this plan (D2, D7–D9, D12, D13). Re-run Step 1's gates if anything changed, and commit as `refactor(#1166): simplify pass`.

- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Identical entries.** For one parsed item, `IngestedEntryFactory::create()` sets the same fields in the same order with the same values as `git show 6f88765d:backend/src/Service/Ingest/EntryIngestor.php` did: url 2048, title 1024, author 255, the snippet, the sanitiser, `publishedAt`, the discussion, the image (trusted or pending), the media. The dedup keys, the platform rules (applied before hashing), the category attach pairs and the returned order are unchanged. `fillMissingImages()` still reads the raw parse.
2. **Identical catalog results.** Every counter in `CatalogImportResult` moves exactly where it did before, for Merge and Replace, locked and unlocked rows; removals still run feeds first, then categories; one transaction, one flush.
3. **Autowiring.** `bin/console lint:container` is clean and `EntryIngestor` gets its `IngestedEntryFactory` and `EntryImageWriter` from the container.
4. **phptramp.** No chain of 3 or more hops through `EntryIngestor::ingest()` (D2).
5. **Comment bar** (D12): new files are at the bar; the kept `EntryIngestor` docblocks are verbatim.
6. **No closing keyword** for #1166 in any commit message on this branch.

Fix each finding it rates Important or above in its own commit (`refactor(#1166): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin refactor/1166-ingest-and-catalog-split
git log origin/develop..HEAD --format=%B | grep -inE '\b(close|fix|resolve)[sd]?\b|\b(closed|fixed|fixes|resolves|resolved|closes)\b' && echo 'STOP: closing keyword in a commit' || true
gh pr create --base develop --title "refactor(#1166): entry construction and the catalog import get their own collaborators" --body "$(cat <<'BODY'
Refs #1166 (PR B of three).

- `EntryIngestor::ingest()` now dedupes and persists; `IngestedEntryFactory` builds each row (the column limits, snippet, sanitiser and media), and `EntryImageWriter` owns "trusted at ingest, else pending" for both new rows and the image backfill. `IncomingEntry` carries each item's guid and url hash, computed once instead of twice.
- `CatalogImporter::import()` opens the transaction and hands the work to a per-import `CatalogImportPass`, which holds the indexes and the counts as fields and upserts categories the way it already upserted feeds.
- The four ingest and subscription tests that still assembled an `EntryIngestor` by hand now share `tests/Support/EntryIngestors`, as the refresh tests have since PR A.

No behaviour change and no wire change. The feed metadata limits, the category update path, locked categories, moved feeds and the Replace-mode lock counts are pinned before the split.
BODY
)"
gh pr view --json body --jq .body | grep -inE '\b(close|fix|resolve)[sd]?\b|\b(closed|fixed|fixes|resolves|resolved|closes)\b' && echo 'STOP: closing keyword in the body' || true
```
Expected: neither `STOP` line prints.

- [ ] **Step 6: Merge when green**

Watch the checks with the Monitor tool, as one command with no loop: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`. On a failure, read `gh run view --log-failed`; if only the tramp step fails, run `composer show larspohlmann/phptramp` first; fix on the branch, push, and watch again.

- [ ] **Step 7: Verify the issue stayed open**

Run: `gh issue view 1166 --json state --jq .state`. Expected: `OPEN`. If it closed, reopen it and report.

---

# PR C — Restore loader and bulk subscribe

### Task C0: Preflight (PR B merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR B merged and cut the branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --head refactor/1166-ingest-and-catalog-split --json number,mergedAt
gh issue view 1166 --json state --jq .state
git switch -c refactor/1166-restore-and-bulk-subscribe origin/develop
```
Expected: one merged PR, the issue `OPEN`, the new branch checked out.

- [ ] **Step 2: Confirm the starting state (from `backend/`)**

```bash
git diff --stat 6f88765d origin/develop -- src/Service/Backup/RestoreEntryLoader.php src/Service/Backup/RestoreEntryLoaderFactory.php src/Service/Backup/RestoreFeedTargets.php src/Service/Subscription/BulkSubscriber.php src/Service/Subscription/BulkSubscribeState.php tests/Service/Backup/RestoreEntryLoaderTest.php tests/Service/Subscription/BulkSubscriberTest.php
git grep -n "BulkSubscribeState" -- src tests
git grep -n "RestoreDestination" -- src tests
```
Expected: the `--stat` is empty; the first grep lists only `src/Service/Subscription/BulkSubscribeState.php` and `src/Service/Subscription/BulkSubscriber.php`; the second prints nothing.

---

### Task C1: `RestoreEntryLoader` takes one `RestoreDestination` in the constructor

**Files:**
- Create: `src/Service/Backup/RestoreDestination.php`
- Modify: `src/Service/Backup/RestoreEntryLoader.php` (two fields lose their `null`, the constructor, `begin()` and two guards go, three call sites)
- Modify: `src/Service/Backup/RestoreEntryLoaderFactory.php` (`create()`)
- Modify: `tests/Service/Backup/RestoreEntryLoaderTest.php`

**Interfaces:**
- Produces: `App\Service\Backup\RestoreDestination::__construct(User $user, RestoreFeedTargets $feeds)`, `final readonly`, with `public User $user` and `public RestoreFeedTargets $feeds` (P1, D11, D-reconcile-1).
- Produces: `RestoreEntryLoader::__construct(EntityManagerInterface $em, EntryRepository $entries, EntryStateRepository $entryStates, EntryBatchInserter $inserter, EntryIndexer $indexer, ClockInterface $clock, RestoreDestination $destination)`. `begin()` is gone. The loader copies the destination into its `$targets` and `$user` fields in the constructor, because `$user` is re-acquired after every `clear()` (D-reconcile-2).
- `RestoreEntryLoaderFactory::create(User $user, array<string, int> $feedIdsByUrl): RestoreEntryLoader` is unchanged; it builds the destination.

- [ ] **Step 1: Write the failing test**

In `tests/Service/Backup/RestoreEntryLoaderTest.php`:
- Add `use App\Service\Backup\RestoreDestination;` directly after `use App\Service\Backup\Dto\EntryLine;`.
- In both tests, replace:
```php
        $loader = $this->loader($entries);
        $loader->begin($this->targets($entries), $this->user());
```
with:
```php
        $loader = $this->loader($entries);
```
- In `testAReadBackThatMissesARowItJustWroteIsALogicError()`, replace:
```php
        $this->expectException(\LogicException::class);
        $loader->finish();
```
with:
```php
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An entry this restore just wrote cannot be read back.');
        $loader->finish();
```
- Replace the method `loader()` (from `    private function loader(EntryRepository $entries): RestoreEntryLoader` to its closing `    }`) with:
```php
    private function loader(EntryRepository $entries): RestoreEntryLoader
    {
        return new RestoreEntryLoader(
            $this->createStub(EntityManagerInterface::class),
            $entries,
            $this->createStub(EntryStateRepository::class),
            new EntryBatchInserter($this->createStub(Connection::class), new UrlNormalizer()),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            new MockClock('2026-08-01 00:00:00', 'UTC'),
            new RestoreDestination($this->user(), $this->targets($entries)),
        );
    }
```

- [ ] **Step 2: Run it to watch it fail**

Run: `php bin/phpunit tests/Service/Backup/RestoreEntryLoaderTest.php`
Expected: FAIL. Both tests error with `Class "App\Service\Backup\RestoreDestination" not found`.

- [ ] **Step 3: Implement**

`src/Service/Backup/RestoreDestination.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;

final readonly class RestoreDestination
{
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public User $user,
        public RestoreFeedTargets $feeds,
    ) {
    }
}
```

In `src/Service/Backup/RestoreEntryLoader.php`:
- Replace:
```php
    private ?RestoreFeedTargets $targets = null;

    private ?User $user = null;
```
with:
```php
    private readonly RestoreFeedTargets $targets;

    private User $user;
```
- Replace:
```php
        private readonly EntryIndexer $indexer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function begin(RestoreFeedTargets $targets, User $user): void
    {
        $this->targets = $targets;
        $this->user = $user;
    }
```
with:
```php
        private readonly EntryIndexer $indexer,
        private readonly ClockInterface $clock,
        RestoreDestination $destination,
    ) {
        $this->targets = $destination->feeds;
        $this->user = $destination->user;
    }
```
- Replace:
```php
    private function target(string $feedUrl): RestoreFeedTarget
    {
        return $this->targetsOrThrow()->for($feedUrl);
    }

    private function targetsOrThrow(): RestoreFeedTargets
    {
        return $this->targets ?? throw new \LogicException('begin() must run before entries are loaded.');
    }
```
with:
```php
    private function target(string $feedUrl): RestoreFeedTarget
    {
        return $this->targets->for($feedUrl);
    }
```
- Replace `        $state = new EntryState($this->userReference(), $entry);` with `        $state = new EntryState($this->user, $entry);`.
- Replace both occurrences of `        $userId = $this->userReference()->requireId();` (in `writeHeldStates()` and `flushStates()`) with `        $userId = $this->user->requireId();`.
- Delete the method `userReference()` and the blank line above it:
```php

    private function userReference(): User
    {
        return $this->user ?? throw new \LogicException('begin() must run before entries are loaded.');
    }
```

The class docblock stays verbatim: every field is still per-run working state, and `$user` is still the reference `flushStates()` re-acquires after each `clear()`.

In `src/Service/Backup/RestoreEntryLoaderFactory.php`, replace the body of `create()`:
```php
        $loader = new RestoreEntryLoader(
            $this->em,
            $this->entries,
            $this->entryStates,
            $this->inserter,
            $this->indexer,
            $this->clock,
        );
        $loader->begin(
            new RestoreFeedTargets($user->requireId(), $feedIdsByUrl, $this->feeds, $this->entries),
            $user,
        );

        return $loader;
```
with:
```php
        return new RestoreEntryLoader(
            $this->em,
            $this->entries,
            $this->entryStates,
            $this->inserter,
            $this->indexer,
            $this->clock,
            new RestoreDestination(
                $user,
                new RestoreFeedTargets($user->requireId(), $feedIdsByUrl, $this->feeds, $this->entries),
            ),
        );
```

Then:
```bash
git grep -n -E "begin\(\)|->begin\(|userReference|targetsOrThrow" -- src/Service/Backup tests/Service/Backup
git grep -n "new RestoreDestination(" -- src tests
```
Expected: the first grep prints nothing; the second lists `src/Service/Backup/RestoreEntryLoaderFactory.php` and `tests/Service/Backup/RestoreEntryLoaderTest.php`, one line each.

- [ ] **Step 4: Run the backup suites**

Run: `php bin/phpunit tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

One at a time, restoring each by hand:
1. In `RestoreEntryLoaderFactory::create()`, pass `[]` instead of `$feedIdsByUrl`. Expected: `EntryPartRestorerTest` and `AccountRestorerTest` fail with "The inspection pass never resolved feed".
2. In `RestoreEntryLoader::__construct()`, delete `        $this->user = $destination->user;`. Expected: `EntryPartRestorerTest` fails with `Typed property App\Service\Backup\RestoreEntryLoader::$user must not be accessed before initialization` (its part carries entry states).

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the four files.
```bash
git add src/Service/Backup/RestoreDestination.php src/Service/Backup/RestoreEntryLoader.php src/Service/Backup/RestoreEntryLoaderFactory.php tests/Service/Backup/RestoreEntryLoaderTest.php
git commit -m "refactor(#1166): the restore entry loader takes its destination in the constructor, and begin() goes"
```

---

### Task C2: Pin the bulk-subscribe positions, the cap and the tag-name limit

**Files:**
- Modify: `tests/Service/Subscription/BulkSubscriberTest.php` (three tests, two helpers, one import)

**Interfaces:** none; every test here passes on develop.

What these pin that nothing pinned before: the subscription, tag and feed-in-tag positions continue after the user's committed rows (and a tag created in the batch numbers its feeds from 0); the cap counts subscriptions the same batch already made; a tag name is cut to 100 characters and matched case-insensitively within the batch.

- [ ] **Step 1: Add the pins**

In `tests/Service/Subscription/BulkSubscriberTest.php`, add `use App\Entity\User;` directly after `use App\Entity\Tag;`. After the method `testRejectsAnUnusableUrlWithoutAbortingTheBatch()` (the last method of the class), add:
```php

    public function testPositionsContinueAfterWhatTheUserAlreadyHas(): void
    {
        $user = $this->user('positions@example.com');
        $existingFeed = new Feed('https://existing.example.com/rss.xml');
        $existingSubscription = new Subscription($user, $existingFeed, new \DateTimeImmutable('2026-07-01 00:00:00'));
        $existingSubscription->setPosition(4);
        $existingTag = new Tag($user, 'Existing');
        $existingTag->setPosition(2);
        $existingSubscription->addTag($existingTag, 6);
        $this->em()->persist($existingFeed);
        $this->em()->persist($existingTag);
        $this->em()->persist($existingSubscription);
        $this->em()->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://one.example.com/rss.xml', 'One', 'Existing', null),
            new BulkSubscribeItem('https://two.example.com/rss.xml', 'Two', 'Fresh', null),
            new BulkSubscribeItem('https://three.example.com/rss.xml', 'Three', 'fresh', null),
        ]);

        self::assertSame(3, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        self::assertSame('Fresh', $result->tagsCreated[0]->getName());
        self::assertSame(3, $result->tagsCreated[0]->getPosition());
        $one = $this->subscriptionTo($user, 'https://one.example.com/rss.xml');
        $two = $this->subscriptionTo($user, 'https://two.example.com/rss.xml');
        $three = $this->subscriptionTo($user, 'https://three.example.com/rss.xml');
        self::assertSame(5, $one->getPosition());
        self::assertSame(6, $two->getPosition());
        self::assertSame(7, $three->getPosition());
        self::assertSame(7, $this->positionIn($one, 'Existing'));
        self::assertSame(0, $this->positionIn($two, 'Fresh'));
        self::assertSame(1, $this->positionIn($three, 'Fresh'));
    }

    public function testTheCapCountsTheSubscriptionsThisBatchAlreadyMade(): void
    {
        $user = $this->user('cap@example.com');
        $user->setMaxSubscriptions(2);
        $existingFeed = new Feed('https://existing.example.com/rss.xml');
        $this->em()->persist($existingFeed);
        $this->em()->persist(new Subscription($user, $existingFeed, new \DateTimeImmutable('2026-07-01 00:00:00')));
        $this->em()->flush();

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://first.example.com/rss.xml', 'First', null, null),
            new BulkSubscribeItem('https://second.example.com/rss.xml', 'Second', null, null),
        ]);

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
        self::assertNull(
            $this->em()->getRepository(Feed::class)->findOneBy(['url' => 'https://second.example.com/rss.xml']),
        );
    }

    public function testAnOverlongTagNameIsCutToTheColumnAndMatchedCaseInsensitively(): void
    {
        $user = $this->user('long-tag@example.com');

        $result = $this->subscriber()->subscribeAll($user, [
            new BulkSubscribeItem('https://long.example.com/rss.xml', 'Long', 'L' . str_repeat('t', 119), null),
            new BulkSubscribeItem('https://longer.example.com/rss.xml', 'Longer', 'l' . str_repeat('T', 119), null),
        ]);

        self::assertSame(2, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        self::assertSame('L' . str_repeat('t', 99), $result->tagsCreated[0]->getName());
    }

    private function subscriptionTo(User $user, string $feedUrl): Subscription
    {
        $feed = $this->em()->getRepository(Feed::class)->findOneBy(['url' => $feedUrl]);
        self::assertNotNull($feed);
        $subscription = $this->em()->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $feed]);
        self::assertNotNull($subscription);

        return $subscription;
    }

    private function positionIn(Subscription $subscription, string $tagName): int
    {
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag()->getName() === $tagName) {
                return $join->getPosition();
            }
        }

        self::fail(sprintf('The subscription is not in the tag "%s".', $tagName));
    }
```

- [ ] **Step 2: Run the pins**

Run: `php bin/phpunit tests/Service/Subscription/BulkSubscriberTest.php`
Expected: PASS (pin).

- [ ] **Step 3: Deletion checks**

One at a time in `src/Service/Subscription/BulkSubscriber.php`, restoring each by hand:
1. `$subscription->setPosition($state->nextSubscriptionPosition++);` → `$subscription->setPosition(0);`. Expected: `testPositionsContinueAfterWhatTheUserAlreadyHas` fails.
2. Delete `$state->tagCache[$key] = $tag;`. Expected: `testPositionsContinue…` (two `Fresh` tags) and `testAnOverlongTagName…` fail.
3. Delete `++$state->existing;`. Expected: `testTheCapCountsTheSubscriptionsThisBatchAlreadyMade` fails.
4. `mb_substr($item->tagName, 0, self::MAX_TAG_NAME)` → `mb_substr($item->tagName, 1, self::MAX_TAG_NAME)`. Expected: `testAnOverlongTagName…` fails.

- [ ] **Step 4: Gates and commit**

Run: `composer check`, then the PhpStorm inspections on the test file.
```bash
git add tests/Service/Subscription/BulkSubscriberTest.php
git commit -m "refactor(#1166): pin the bulk-subscribe positions, the cap and the tag-name limit"
```

---

### Task C3: `BulkSubscribeBatch` and `BulkSubscribePositions` replace `BulkSubscribeState`

**Files:**
- Create: `src/Service/Subscription/BulkSubscribeBatch.php`, `src/Service/Subscription/BulkSubscribePositions.php`
- Delete: `src/Service/Subscription/BulkSubscribeState.php`
- Modify: `src/Service/Subscription/BulkSubscriber.php` (rewritten in full)
- Create: `tests/Service/Subscription/BulkSubscribeBatchTest.php`, `tests/Service/Subscription/BulkSubscribePositionsTest.php`

**Interfaces:**
- Produces:
  - `App\Service\Subscription\BulkSubscribePositions::__construct(int $nextSubscriptionPosition, int $nextTagPosition)`, `::takeSubscriptionPosition(): int`, `::takeTagPosition(): int`, `::takeFeedPositionIn(Tag $tag, \Closure(): int $firstFreePosition): int` (asks `$firstFreePosition` once per tag).
  - `App\Service\Subscription\BulkSubscribeBatch::__construct(User $user, int $room, BulkSubscribePositions $positions)`, `public readonly User $user`, `public readonly BulkSubscribePositions $positions`, `public private(set) BulkSubscribeResult $result`; `hasSubscribed(string $url): bool`, `isFull(): bool`, `tagNamed(string $name): ?Tag`, `rememberTag(string $name, Tag $tag): void`, `countInvalid()`, `countAlreadySubscribed()`, `countOverLimit()`, `recordSubscribed(string $url, Subscription $subscription, list<Tag> $tagsCreated): void`.
  - `BulkSubscriber::subscribeAll(User $user, iterable<BulkSubscribeItem> $items): BulkSubscribeResult` is unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Subscription/BulkSubscribePositionsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscribePositions;
use PHPUnit\Framework\TestCase;

final class BulkSubscribePositionsTest extends TestCase
{
    public function testHandsOutConsecutivePositionsFromTheSeeds(): void
    {
        $positions = new BulkSubscribePositions(5, 3);

        self::assertSame(5, $positions->takeSubscriptionPosition());
        self::assertSame(6, $positions->takeSubscriptionPosition());
        self::assertSame(3, $positions->takeTagPosition());
        self::assertSame(4, $positions->takeTagPosition());
    }

    public function testAsksForATagsFirstFreePositionOnlyOnce(): void
    {
        $positions = new BulkSubscribePositions(0, 0);
        $tag = $this->tag('Tag');
        $asked = 0;
        $firstFree = static function () use (&$asked): int {
            ++$asked;

            return 7;
        };

        self::assertSame(7, $positions->takeFeedPositionIn($tag, $firstFree));
        self::assertSame(8, $positions->takeFeedPositionIn($tag, $firstFree));
        self::assertSame(1, $asked);
    }

    public function testNumbersEachTagOnItsOwn(): void
    {
        $positions = new BulkSubscribePositions(0, 0);
        $first = $this->tag('First');
        $second = $this->tag('Second');

        self::assertSame(0, $positions->takeFeedPositionIn($first, static fn (): int => 0));
        self::assertSame(4, $positions->takeFeedPositionIn($second, static fn (): int => 4));
        self::assertSame(1, $positions->takeFeedPositionIn($first, static fn (): int => 0));
    }

    private function tag(string $name): Tag
    {
        return new Tag(new User('positions@example.com', new \DateTimeImmutable('2026-07-01 00:00:00')), $name);
    }
}
```

`tests/Service/Subscription/BulkSubscribeBatchTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscribeBatch;
use App\Service\Subscription\BulkSubscribePositions;
use PHPUnit\Framework\TestCase;

final class BulkSubscribeBatchTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('batch@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
    }

    public function testEachSkipIsCountedInItsOwnField(): void
    {
        $batch = $this->batch(5);

        $batch->countInvalid();
        $batch->countAlreadySubscribed();
        $batch->countAlreadySubscribed();
        $batch->countOverLimit();

        self::assertSame(1, $batch->result->invalid);
        self::assertSame(2, $batch->result->alreadySubscribed);
        self::assertSame(1, $batch->result->skippedOverLimit);
        self::assertSame(0, $batch->result->imported);
    }

    public function testASubscriptionIsRememberedByUrlAndTakesRoom(): void
    {
        $batch = $this->batch(1);
        $tag = new Tag($this->user, 'Tag');
        $url = 'https://a.example.com/rss.xml';

        self::assertFalse($batch->isFull());
        $batch->recordSubscribed($url, $this->subscription($url), [$tag]);

        self::assertTrue($batch->hasSubscribed($url));
        self::assertFalse($batch->hasSubscribed('https://b.example.com/rss.xml'));
        self::assertTrue($batch->isFull());
        self::assertSame(1, $batch->result->imported);
        self::assertSame([$tag], $batch->result->tagsCreated);
    }

    public function testNoRoomLeftIsFull(): void
    {
        self::assertTrue($this->batch(0)->isFull());
        self::assertTrue($this->batch(-1)->isFull());
    }

    public function testATagIsFoundByItsNameInAnyCase(): void
    {
        $batch = $this->batch(5);
        $tag = new Tag($this->user, 'Technology');

        $batch->rememberTag('Technology', $tag);

        self::assertSame($tag, $batch->tagNamed('TECHNOLOGY'));
        self::assertNull($batch->tagNamed('Science'));
    }

    private function batch(int $room): BulkSubscribeBatch
    {
        return new BulkSubscribeBatch($this->user, $room, new BulkSubscribePositions(0, 0));
    }

    private function subscription(string $url): Subscription
    {
        return new Subscription($this->user, new Feed($url), new \DateTimeImmutable('2026-07-01 00:00:00'));
    }
}
```

- [ ] **Step 2: Run them to watch them fail**

Run: `php bin/phpunit tests/Service/Subscription/BulkSubscribePositionsTest.php tests/Service/Subscription/BulkSubscribeBatchTest.php`
Expected: FAIL with `Class "App\Service\Subscription\BulkSubscribePositions" not found`.

- [ ] **Step 3: Write the two per-batch classes**

`src/Service/Subscription/BulkSubscribePositions.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;

/** Where a batch's next rows go: nothing flushes until the end, so MAX(position) cannot see this batch's rows. */
final class BulkSubscribePositions
{
    /** @var array<int, int> keyed by spl_object_id(tag): a tag created in this batch has no id yet */
    private array $nextFeedPositionByTag = [];

    public function __construct(
        private int $nextSubscriptionPosition,
        private int $nextTagPosition,
    ) {
    }

    public function takeSubscriptionPosition(): int
    {
        return $this->nextSubscriptionPosition++;
    }

    public function takeTagPosition(): int
    {
        return $this->nextTagPosition++;
    }

    /**
     * @param \Closure(): int $firstFreePosition asked once per tag, on the tag's first use in this batch
     */
    public function takeFeedPositionIn(Tag $tag, \Closure $firstFreePosition): int
    {
        $key = spl_object_id($tag);
        $this->nextFeedPositionByTag[$key] ??= $firstFreePosition();

        return $this->nextFeedPositionByTag[$key]++;
    }
}
```

`src/Service/Subscription/BulkSubscribeBatch.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;

/** One subscribeAll() call: what it subscribed and tagged so far, the room left under the cap, and its counts. */
final class BulkSubscribeBatch
{
    public private(set) BulkSubscribeResult $result;

    /** @var array<string, Subscription> keyed by the item's feed URL: a URL listed twice subscribes once */
    private array $subscriptionsByUrl = [];

    /** @var array<string, Tag> keyed by lowercased name: items naming one tag share one unflushed row */
    private array $tagsByName = [];

    public function __construct(
        public readonly User $user,
        private int $room,
        public readonly BulkSubscribePositions $positions,
    ) {
        $this->result = new BulkSubscribeResult();
    }

    public function hasSubscribed(string $url): bool
    {
        return isset($this->subscriptionsByUrl[$url]);
    }

    public function isFull(): bool
    {
        return $this->room <= 0;
    }

    public function tagNamed(string $name): ?Tag
    {
        return $this->tagsByName[mb_strtolower($name)] ?? null;
    }

    public function rememberTag(string $name, Tag $tag): void
    {
        $this->tagsByName[mb_strtolower($name)] = $tag;
    }

    public function countInvalid(): void
    {
        $this->result = $this->result->with(invalid: 1);
    }

    public function countAlreadySubscribed(): void
    {
        $this->result = $this->result->with(alreadySubscribed: 1);
    }

    public function countOverLimit(): void
    {
        $this->result = $this->result->with(skippedOverLimit: 1);
    }

    /**
     * @param list<Tag> $tagsCreated
     */
    public function recordSubscribed(string $url, Subscription $subscription, array $tagsCreated): void
    {
        $this->subscriptionsByUrl[$url] = $subscription;
        --$this->room;
        $this->result = $this->result->with(imported: 1, tagsCreated: $tagsCreated);
    }
}
```

- [ ] **Step 4: Run the two unit tests**

Run: `php bin/phpunit tests/Service/Subscription/BulkSubscribePositionsTest.php tests/Service/Subscription/BulkSubscribeBatchTest.php`
Expected: PASS.

- [ ] **Step 5: Rewrite `BulkSubscriber` and delete the state bag**

`src/Service/Subscription/BulkSubscriber.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Subscribes a batch of feeds in one unit of work without fetching anything, for OPML import and the onboarding
 * catalog alike. Nothing flushes until the end, so a BulkSubscribeBatch stands in for the rows this batch created.
 */
final readonly class BulkSubscriber
{
    private const int MAX_TAG_NAME = 100;

    /** Feed.url is VARCHAR(750): a longer URL counts as invalid instead of failing the whole batch's flush. */
    private const int MAX_FEED_URL = 750;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedRepository $feeds,
        private SubscriptionRepository $subscriptions,
        private SubscriptionTagRepository $subscriptionTags,
        private TagRepository $tags,
        private ClockInterface $clock,
        private SubscriptionLimitResolver $subscriptionLimits,
    ) {
    }

    /**
     * @param iterable<BulkSubscribeItem> $items
     */
    public function subscribeAll(User $user, iterable $items): BulkSubscribeResult
    {
        $batch = $this->open($user);
        foreach ($items as $item) {
            $this->subscribeOne($batch, $item);
        }

        $this->em->flush();

        return $batch->result;
    }

    private function open(User $user): BulkSubscribeBatch
    {
        $userId = $user->requireId();

        return new BulkSubscribeBatch(
            $user,
            $this->subscriptionLimits->resolve($user) - $this->subscriptions->countForUser($userId),
            new BulkSubscribePositions(
                $this->subscriptions->nextPositionForUser($userId),
                $this->tags->nextPositionForUser($userId),
            ),
        );
    }

    private function subscribeOne(BulkSubscribeBatch $batch, BulkSubscribeItem $item): void
    {
        $url = $item->feedUrl;
        if (!$this->isSubscribableUrl($url)) {
            $batch->countInvalid();

            return;
        }
        if ($batch->hasSubscribed($url)) {
            $batch->countAlreadySubscribed();

            return;
        }

        // Looked up, not created yet: an item over the cap must not leave an orphan Feed row behind.
        $feed = $this->feeds->findOneBy(['url' => $url]);
        if ($this->isSubscribedTo($batch->user, $feed)) {
            $batch->countAlreadySubscribed();

            return;
        }
        if ($batch->isFull()) {
            $batch->countOverLimit();

            return;
        }

        $subscription = $this->persistSubscription($batch, $feed ?? $this->persistNewFeed($item));
        $batch->recordSubscribed($url, $subscription, $this->attachTag($batch, $subscription, $item));
    }

    private function isSubscribedTo(User $user, ?Feed $feed): bool
    {
        return null !== $feed && $this->subscriptions->existsForUserAndFeed($user->requireId(), $feed->requireId());
    }

    private function persistNewFeed(BulkSubscribeItem $item): Feed
    {
        $feed = new Feed($item->feedUrl);
        $feed->setSourceFormat($item->sourceFormat);
        // Seeded for the sidebar before the first fetch; only on creation, since a shared row is not ours to retitle.
        $feed->setTitle($item->feedTitle);
        $feed->scheduleNextFetchAt($this->clock->now());
        $this->em->persist($feed);

        return $feed;
    }

    private function persistSubscription(BulkSubscribeBatch $batch, Feed $feed): Subscription
    {
        $subscription = new Subscription($batch->user, $feed, $this->clock->now());
        $subscription->setPosition($batch->positions->takeSubscriptionPosition());
        $this->em->persist($subscription);

        return $subscription;
    }

    /**
     * @return list<Tag> the tag if this call brought it into being, else empty
     */
    private function attachTag(BulkSubscribeBatch $batch, Subscription $subscription, BulkSubscribeItem $item): array
    {
        if (null === $item->tagName) {
            return [];
        }

        $name = mb_substr($item->tagName, 0, self::MAX_TAG_NAME);
        $existing = $batch->tagNamed($name) ?? $this->tags->findOneByNameForUser($batch->user->requireId(), $name);
        $tag = $existing ?? $this->persistNewTag($batch, $name, $item->tagStyle);
        $batch->rememberTag($name, $tag);
        $this->joinTag($batch->positions, $subscription, $tag);

        return null === $existing ? [$tag] : [];
    }

    private function persistNewTag(BulkSubscribeBatch $batch, string $name, ?TagStyle $style): Tag
    {
        $tag = new Tag($batch->user, $name);
        $tag->setColor($style?->color);
        $tag->setIcon($style?->icon);
        $tag->setPosition($batch->positions->takeTagPosition());
        $this->em->persist($tag);

        return $tag;
    }

    private function joinTag(BulkSubscribePositions $positions, Subscription $subscription, Tag $tag): void
    {
        // A tag created in this batch starts at 0; an existing one appends past its committed feeds.
        $subscription->addTag($tag, $positions->takeFeedPositionIn(
            $tag,
            fn (): int => null === $tag->getId() ? 0 : $this->subscriptionTags->nextPositionForTag($tag),
        ));
    }

    private function isSubscribableUrl(string $url): bool
    {
        if (mb_strlen($url) > self::MAX_FEED_URL) {
            return false;
        }
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && \is_string($host) && '' !== $host;
    }
}
```
```bash
git rm src/Service/Subscription/BulkSubscribeState.php
git grep -n "BulkSubscribeState" -- src tests docs
```
Expected: the grep prints nothing (a plan under `docs/superpowers/plans/` that mentions it is history, not code; leave it).

What stays identical: the order of the checks (invalid URL, seen in this batch, already subscribed in the database, over the cap), the lookup-before-create, the two `clock->now()` calls (new feed, then subscription), the tag lookup order (this batch first, then the database), the position arithmetic, and the one flush. `SubscriptionLimitResolver::resolve()` now runs once per batch (D10).

- [ ] **Step 6: Run the bulk-subscribe suites**

Run: `php bin/phpunit tests/Service/Subscription tests/Service/Opml tests/Service/Catalog/CatalogSubscriberTest.php tests/Controller/Api/OnboardingControllerTest.php tests/Controller/Api/OpmlControllerTest.php`
Expected: PASS, C2's pins included.

- [ ] **Step 7: Deletion checks**

One at a time, restoring each by hand:
1. `BulkSubscribeBatch::isFull()`: `<=` to `<`. Expected: `testNoRoomLeftIsFull` fails.
2. `BulkSubscribePositions::takeFeedPositionIn()`: `??=` to `=`. Expected: `testAsksForATagsFirstFreePositionOnlyOnce` and `BulkSubscriberTest::testPositionsContinueAfterWhatTheUserAlreadyHas` fail.
3. `BulkSubscriber::open()`: pass `$this->subscriptionLimits->resolve($user)` as the room. Expected: `testTheCapCountsTheSubscriptionsThisBatchAlreadyMade` and `OpmlImporterTest::testSkippedOverLimitCreatesNoOrphanFeeds` fail.
4. `BulkSubscriber::attachTag()`: delete `$batch->rememberTag($name, $tag);`. Expected: `testAnOverlongTagNameIsCutToTheColumnAndMatchedCaseInsensitively` fails.

- [ ] **Step 8: Gates and commit**

Run: `bin/console cache:clear && bin/console lint:container && composer check && composer md`, then the PhpStorm inspections on the changed files.
```bash
git add src/Service/Subscription/BulkSubscriber.php src/Service/Subscription/BulkSubscribeBatch.php src/Service/Subscription/BulkSubscribePositions.php tests/Service/Subscription/BulkSubscribeBatchTest.php tests/Service/Subscription/BulkSubscribePositionsTest.php
git commit -m "refactor(#1166): a per-batch bulk-subscribe object with behaviour replaces the bag of public arrays"
```

---

### Finishing PR C

- [ ] **Step 1: The gates on the whole branch**

```bash
composer check
composer md
php bin/phpunit
docker compose exec php composer test
composer infection:diff
```
Expected: all green.
- Before the MySQL leg, check that the containers are current; `docker compose exec php bin/console cache:clear` if the compiled container predates this branch.
- An escaped mutant on a touched line gets a killing test in the task that owns the line.
- Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: nothing from a file this PR touched.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'`. ERROR and WARNING block.

- [ ] **Step 3: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`. It runs its angle reviewers (reuse, simplification, efficiency, altitude) as parallel agents. Apply only fixes that keep every gate green and undo no decision in this plan (D9–D11, P1, P3). Re-run Step 1's gates if anything changed, and commit as `refactor(#1166): simplify pass`.

- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Identical restore.** `RestoreEntryLoader` reads the same targets, starts from the same `User` and re-acquires the same user reference after every `clear()`; `RestoreEntryLoaderFactory::create()` builds `RestoreFeedTargets` with the same arguments and wraps it with the user in one `RestoreDestination` (P1); no `begin()`, no nullable field, no `LogicException('begin() …')` remains.
2. **Identical bulk-subscribe results.** For any item list, the four counters, `tagsCreated` (and its order), the subscription, tag and feed-in-tag positions, the Feed rows created and the one flush match `git show 6f88765d:backend/src/Service/Subscription/BulkSubscriber.php`. The cap: `limit - existing` decremented per import equals `existing >= limit` checked per item (D10).
3. **The whole issue.** Walk `gh issue view 1166`'s six bullets against develop plus this branch: each is done, or listed under "Not in scope" with a reason.
4. **Comment bar** (D12).
5. **The PR body says `Closes #1166`**, and nothing else in it or in this branch's commits carries a different closing keyword.

Fix each finding it rates Important or above in its own commit (`refactor(#1166): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin refactor/1166-restore-and-bulk-subscribe
gh pr create --base develop --title "refactor(#1166): the restore loader loses begin(), bulk subscribe gets a per-batch object" --body "$(cat <<'BODY'
Closes #1166 (PR C of three; PR A and PR B are merged).

- `RestoreEntryLoader` takes one `RestoreDestination` (the `User` and the `RestoreFeedTargets`) in the constructor, so `begin()`, the two nullable fields and the two "begin() must run" guards are gone. `RestoreEntryLoaderFactory`, its only caller, builds the destination.
- `BulkSubscriber` opens a `BulkSubscribeBatch` per call (user, room under the cap, `BulkSubscribePositions`, the result) and passes only the batch and the item to its helpers. `BulkSubscribeState`, a bag of public mutable arrays, is gone.
- The subscription cap is read once per batch instead of once per item; the user's cap cannot change mid-batch, so every result is the same.

No behaviour change and no wire change. The bulk-subscribe positions, the cap within one batch and the tag-name limit are pinned before the split.
BODY
)"
```

- [ ] **Step 6: Merge when green**

Watch the checks with the Monitor tool, as one command with no loop: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`. On a failure, read `gh run view --log-failed`; if only the tramp step fails, run `composer show larspohlmann/phptramp` first; fix on the branch, push, and watch again.

- [ ] **Step 7: Verify the issue closed**

Run: `gh issue view 1166 --json state --jq .state`. Expected: `CLOSED` (the merge into `develop`, the default branch, closes it). If it is still open, report it; do not close it by hand.
