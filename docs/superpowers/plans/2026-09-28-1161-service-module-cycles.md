# Break the Dependency Cycles Between Service Modules (#1161) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1161 in two PRs, with no change in behaviour.
- **PR A** (Tasks A0–A4 with A1b, `Refs #1161`): every cycle between `Service/*` modules is broken, and the two services loose in the `Service` root get a module. Three moves are scripted and byte-identical; one rename (A1b) follows the first move in its own commit; one small extraction (`Url\UrlOrigin`) is test-first.
- **PR B** (Tasks B0–B3, `Closes #1161`): a PHPStan collector and a `CollectedDataNode` rule fail the build on a new module cycle and name its path. A second, per-file rule keeps out the two dependencies #1163 removed on purpose. `docs/architecture.md` gains §9, and `CLAUDE.md` names both rules.

**Architecture:**
- **A module** is the first path segment under `src/Service` (`Recommendation` with `Prompt`, `Run`, `Feed` and `Settings` is one module). A dependency is any `App\Service\…` name a module's code mentions: an import, a class name in code, or a class name in a string. Comments are not code.
- **The cycles at `e9953e96`** (computed from the `use App\Service\…` lines; see "Module graph at e9953e96"; the same three as at `566ee103`):
  1. A nine-module cycle, `Catalog`, `Category`, `Discovery`, `Image`, `Ingest`, `Opml`, `Parser`, `Scraper`, `Subscription`. Its only edge back into `Catalog` is `Image → Catalog`, from `ImageVerifier`'s use of the favicon fetcher. **A1** moves the fetcher, its interface, `FetchedFavicon` and the two `Favicon*Exception`s to `Service/Image`. **A1b** then drops the `Catalog` prefix: `FaviconFetcher`, `FaviconFetcherInterface` (D-P2).
  2. `Recommendation ↔ Worker`. **A2** moves `WorkerPresence`, `SweepStreamHeartbeat` and `RecommendationDriverKind` (who is driving recommendation runs) to `Service/Recommendation/Run`. `Worker` then depends on `Recommendation` and nothing points back.
  3. `Fetch ↔ Url` (new since the issue was filed): `Url\FeedWebsite` calls `Fetch\UrlResolver::origin()`, and `Fetch` uses `Url\AbsoluteHttpUrl`. **A3** extracts the origin into `Url\UrlOrigin`; `UrlResolver::resolve()` stays in `Fetch`, because it throws `Fetch`'s `FeedUnreachableException`.
  4. `Fetch ↔ Proxy` and `Grafana ↔ Profiling` are gone (#1159). #1163 and #1166 created no new cycle.
- **The loose services.** **A4** moves `FeedScheduler` and `OrphanedFeedReclaimer` from the `Service` root into a new `Service/Feed` module (the feed's lifecycle: when it is fetched next, and when it is deleted). `Refresh`, `Subscription` and `Account` depend on `Feed`; `Feed` depends on `Fetch` only.
- **The cycle rule (PR B).** `ServiceModuleDependencyCollector` (a PHPStan `Collector<FileNode, …>`) reuses `ClassNameReferences` to record `[module, other module, line]` for every `App\Service` name a Service file mentions. `ServiceModuleCycleRule` (a `Rule<CollectedDataNode>`) builds a `ServiceModuleGraph` and reports each cycle once: the shortest cycle through the first module in name order that lies on one, at the line that closes it. The message names the path (`Alpha -> Beta -> Gamma -> Alpha`).
- **The boundary rule (PR B).** `ServiceModuleBoundaryRule` (a `Rule<FileNode>`, shaped like `DomainKnowsNoHttpRule`) rejects `App\Service\Search` in `App\Service\Reader` and `App\Service\Reader` in `App\Service\Recommendation`. It reuses `ClassNameReferences` and `ForbiddenReference` and keeps its remedies in its own `REMEDIES` const map (the #1182 B8 shape, D-P3). #1163 removed both edges (both are absent at `e9953e96`); neither closes a cycle, so only an explicit rule keeps them out (carry-forward).

**Tech Stack:** PHP 8.4, Symfony 7.4 (autowiring; `config/services.yaml`, `config/services_test.yaml`), PHPStan 2.2 (`Collector`, `CollectedDataNode`, `RuleTestCase::getCollectors()`), nikic/php-parser `NodeFinder`, PHPUnit 12, PHPMD codesize, phptramp, Infection.

**Spec:**
- GitHub issue #1161 (`gh issue view 1161`): the 9-module cycle, `Recommendation ↔ Worker`, the resolved `Fetch ↔ Proxy`/`Grafana ↔ Profiling`, the two loose root services, and the acceptance check.
- Carry-forward, folded from #1169 (see "Scope"):
  - "no rule stops the Fetch<->Proxy and Grafana<->Profiling cycles (closed by #1159) from coming back. Consider a namespace-cycle PHPStan rule." → B1.
  - "a namespace-dependency rule to keep Reader -> Search (and Recommendation -> Reader) from coming back after #1163." → B2.
- CLAUDE.md, "PHP code style — Clean Code is mandatory", and `docs/architecture.md` §7–§8.

## Reconcile notes (e9953e96)

Reconciled against `origin/develop` at `e9953e96` (#1163: PRs #1201, #1203, #1204; #1166: PRs #1205, #1206, #1207; nothing else landed after `566ee103`).
- **D-reconcile-1:** no new cycle: the three strongly connected sets of `566ee103` are unchanged, because #1163 only swapped `Reader → Search` for `Reading → Search` and dropped `Recommendation → Reader` and `Tag → Reader`, and #1166 added no module edge.
- **D-reconcile-2:** edge counts corrected to what A0's own command prints (120 at `566ee103`, not 118; 118 at `e9953e96`; 115 after PR A), because the draft's count was off by two.
- **D-reconcile-3:** A4's "Modify" list is the ten files that name either class at `e9953e96`, because #1166's `tests/Support/FeedSchedulers` now builds the scheduler for `FeedOutcomePersisterTest`, `FirstFetchRecorderTest` and the runner tests.
- **D-reconcile-4:** A3 Step 1's grep is scoped to `src/Service` and `tests/Service` with `\(`, because it also matched `CorsListener::originOf`, `AttributeMediaSource::originsByKind` and a PhpStan fixture.
- **D-reconcile-5:** new Task A1b renames only `CatalogFaviconFetcher`, its interface and its test, because `FetchedFavicon` and the two exceptions carry no `Catalog` prefix and no `services*.yaml` entry names the autowired fetcher.
- **D-reconcile-6:** A1b's rewrite also covers two `src/Service/Ai` comments that name `CatalogFaviconFetcher`, because the deletion-proof grep must print nothing over `src`, `tests` and `config`.
- **D-reconcile-7:** B2's `BOUNDARIES` is renamed `REMEDIES` and gains three deletion checks (Reader entry, lookalike module, lookalike reference), because D-P3 asks for the #1182 B8 shape and two fixture pairs had no check.
- **D-reconcile-8:** B1's acyclic fixture swaps its lookalike string and controller detour for an `App\Command\Gamma` namespace, because no single edit could make the old two fail, while deleting the collector's namespace guard now fails the test.
- **D-reconcile-9:** B1 gains checks for the first-site rule and the namespace guard, A3 gets one per test and row, and `UrlResolverTest` drops its bare-host row, because the `'/'` default and the `=== ''` fallback cover for each other.
- **D-reconcile-10:** B1 Step 9's report site stays `AdvanceRecommendationRunsHandler.php:8`, because that file is unchanged at `e9953e96` and A2 rewrites the import in place.

## Status

| Task | State |
|---|---|
| A0: Branch, plan copy, move script and the baseline graph | ⬜ |
| A1: The favicon fetcher moves to `Service/Image` | ⬜ |
| A1b: The moved favicon fetcher drops its `Catalog` prefix | ⬜ |
| A2: The recommendation driver liveness moves to `Service/Recommendation/Run` | ⬜ |
| A3: `Url\UrlOrigin` owns a URL's origin | ⬜ |
| A4: `FeedScheduler` and `OrphanedFeedReclaimer` move to `Service/Feed` | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: `ServiceModuleCycleRule` | ⬜ |
| B2: `ServiceModuleBoundaryRule` | ⬜ |
| B3: `docs/architecture.md` §9 and `CLAUDE.md` | ⬜ |

## Scope

| Issue bullet or carry-forward item | Task |
|---|---|
| 1: the 9-module cycle closed by `Image/ImageVerifier` → `Catalog\CatalogFaviconFetcherInterface` and two `Catalog` exceptions | A1 |
| 1: `Mail/Digest/DigestImageEmbedder` uses the same fetcher | A1 (its imports follow the move; `Mail → Catalog` becomes `Mail → Image`) |
| Planner ruling D-P2: the fetcher, once in `Image`, loses its `Catalog` prefix | A1b |
| 2: `Recommendation ↔ Worker` | A2 |
| 3: `Fetch ↔ Proxy`, `Grafana ↔ Profiling` | Already gone at `566ee103` and at `e9953e96` (#1159); B1's rule keeps them gone |
| Not in the issue: `Fetch ↔ Url` (`Url\FeedWebsite` → `Fetch\UrlResolver::origin()`) | A3 |
| "Also": `Service/FeedScheduler.php` and `Service/OrphanedFeedReclaimer.php` need a module | A4 |
| Acceptance: a check that fails on a new `Service/*` cycle | B1 |
| Carry-forward (#1159 → #1169): a namespace-cycle rule for `Fetch ↔ Proxy`/`Grafana ↔ Profiling` | B1 |
| Carry-forward (#1163 → #1169): a namespace-dependency rule for `Reader → Search` and `Recommendation → Reader` | B2 |

## Module graph at e9953e96

Computed from every `use App\Service\…` line under `backend/src/Service` with A0 Step 4's command (118 distinct module edges at `e9953e96`, 120 at `566ee103`; a file in the `Service` root counts under its own class name). A grep over every mention adds two edges, `Ai → RateLimit` and `Search → Mail`, but both are docblock text (`ProviderRateLimitedException.php:9`, `SearchEngineCapability.php:14`), which PHPStan never sees. `src/Service` holds no class name in a string, no group import and no inline `\App\Service\…` name at `e9953e96`, so the `use` lines are the whole graph. The strongly connected sets were computed with Tarjan's algorithm over that edge list, not only with `tsort` (which names one cycle per set it trips over).

| Strongly connected set | The edge(s) that close it | Evidence at `e9953e96` |
|---|---|---|
| `Catalog`, `Category`, `Discovery`, `Image`, `Ingest`, `Opml`, `Parser`, `Scraper`, `Subscription` | `Image → Catalog`, the only edge into `Catalog` from inside the set (`Mail → Catalog` is outside it) | `src/Service/Image/ImageVerifier.php:8-10` |
| `Recommendation`, `Worker` | `Recommendation → Worker` | `src/Service/Recommendation/Run/ForYouSweep.php:10-12`, `RecommendationDrainSpawner.php:8`, `RecommendationPollDriver.php:9`; back: `src/Service/Worker/WorkerRunSweep.php:13-14`, `Handler/StartDueRecommendationRunsHandler.php:7` |
| `Fetch`, `Url` | `Url → Fetch` | `src/Service/Url/FeedWebsite.php:7`; back: `src/Service/Fetch/PageUrls.php:8`, `UrlResolver.php:8` |

The same three sets as at `566ee103`; every evidence line is unchanged. The edge list differs from `566ee103` in four edges, all from #1163: `Reader → Search`, `Recommendation → Reader` and `Tag → Reader` are gone, and `Reading → Search` is new (`Reading/SearchMarkReadService.php:10`). `Reading → Recommendation` (`ReadingWindow`, `ReadingActivityCounter` use `Recommendation\Feed\ViewerTimeZone`) predates both issues, and nothing in `Recommendation` names `Reading`.

Loose in the root: `FeedScheduler` (uses `Fetch\HostThrottle`; used by `Refresh\FeedOutcomePersister`, `Subscription\FirstFetchRecorder`) and `OrphanedFeedReclaimer` (no Service dependency; used by `Refresh\RefreshHousekeeping`, `Subscription\SubscriptionService`, `Account\AccountDeleter`). `Refresh\RefreshRunner` names neither since #1166.

With A1–A4 applied to `e9953e96`, the graph has no cycle and 115 edges. Removed: `Image → Catalog`, `Mail → Catalog`, `Recommendation → Worker`, `Url → Fetch` and the six edges to or from the two root classes. Added: `Image → Fetch`, `Catalog → Image`, `Mail → Image`, `Feed → Fetch`, and `Account`, `Refresh` and `Subscription → Feed`. A1b renames classes inside `Image` and adds no edge.

## Design decisions

- **D1: a module is the first segment under `App\Service`,** so `Recommendation\{Prompt,Run,Feed,Settings}` are one module and a reference inside a module is not an edge. In the rule, a class loose in the `Service` root is a module of its own, named by its class. That is sharper than one pseudo-module for the whole root: two loose classes that never use each other cannot fake a cycle through "the root". The collector reads a root class's name from its file name (PSR-4). After A4 the root is empty.
- **D2: the rule counts what `ClassNameReferences` counts:** imports (group imports included), class names in code, and class names in strings. Docblocks are not nodes, so a class named only in a comment is no dependency, and a `use` kept only for a docblock type is one. `ClassNameReferences` and `ForbiddenReference` are reused unchanged.
- **D3: the favicon fetcher goes to `Service/Image`, not `Service/Fetch`.** It is an image download: an image content-type allow-list, an image size cap, `Favicon*` exceptions that `ImageVerifier` turns into keep/drop decisions, and a second consumer that embeds digest images. `Fetch` is the transport it is built on (`FailoverRequestSender`, `UrlGuard`, `ResponseHeader`, `UrlResolver`), so `Image → Fetch` points down. In `Image` the `Catalog` prefix misleads, so A1b drops it right after the move, in its own commit (D-P2).
- **D4: the liveness classes move; no interfaces are added.** `WorkerPresence` answers "is anybody driving recommendation runs" and "does this install run a persistent recommendation worker". `RecommendationDriverKind` names the regimes that drive them. `SweepStreamHeartbeat` keeps a recommendation sweep's liveness fresh, and its sibling `TickLockKeepalive` already lives in `Recommendation\Run`. All three describe recommendation runs, so `Recommendation` owns them. The issue's alternative, interfaces in `Recommendation` implemented in `Worker`, would add two single-implementation interfaces and two `services.yaml` aliases, and it would still have to move `RecommendationDriverKind`, because both interfaces' signatures take it. See D-P1.
- **D5: `Fetch ↔ Url` is broken by extraction.** `UrlResolver::origin()` and its private `originOf()` become `Url\UrlOrigin::of()` and `::fromParts()`, with the same bodies. `UrlResolver::resolve()` stays in `Fetch`, because it throws `Fetch\Exception\FeedUnreachableException` and its callers catch that. Moving `FeedWebsite` into `Fetch` instead would put a presentation rule in the transport, and moving `AbsoluteHttpUrl` would put a URL predicate there. A3 is the only PR A task that changes code rather than moving it, so it is test-first and adds the missing `UrlResolverTest`.
- **D6: the root services get a new module, `Service/Feed`.** Splitting them (`FeedScheduler` to `Refresh`, `OrphanedFeedReclaimer` to `Subscription`) creates `Refresh ↔ Subscription`: `Subscription\FirstFetchRecorder` needs the scheduler and `Refresh` needs the reclaimer. Putting both in `Refresh` makes `Subscription` and `Account` depend on the refresh module for an unsubscribe policy. Both classes act on a `Feed`'s lifecycle and on nothing else, so they share a home. See D-P4 for the name.
- **D7: the moves are scripted with #1162's move script, copied verbatim** into `var/refactor-1161/` (not committed). It `git mv`s each file, rewrites its namespace, rewrites every old FQCN in `src`, `tests` and `config` (never `tests/PhpStan`), drops imports that became same-namespace, and adds imports for bare names that left their namespace. A moved class keeps every code line; a comment-stripped comparison proves it. Comments in moved files stay as they are (#1162 D6): the moved files go on the #1171 comment-sweep list (see "Not in scope"). Import blocks the script rewrote in place are not re-sorted (#1162 precedent).
- **D8: one error per cycle, at a stable place.** The graph visits modules in name order, looks for the shortest cycle through each one no earlier cycle covered (breadth-first, dependencies in name order), and reports it at the first place, by file path and then line, where the last module on the path names the first. The same tree always gives the same errors. Two rotations of one cycle are never both reported.
- **D9: module names are compared as written.** A lower-case class name in a string (`'app\service\mail\…'`) would become a separate node with no outgoing edge. That can hide a cycle but never invent one. `src/Service` holds no class-name string at `566ee103` or at `e9953e96`.
- **D10: the boundary rule is per file and lists only the two edges #1163 removed.** It reports each offending line (the `DomainKnowsNoHttpRule` shape), so the error sits where the import is. A new entry needs an issue that removed that edge on purpose.
- **D11: `tests/PhpStan` is outside PHPMD, phptramp and Infection** (`src` only), and it is held to the same standards anyway (CLAUDE.md, "Tests are production code").
- **D12: comments.** A new file carries a comment only where a reader would otherwise get it wrong: the collector's module definition, the rule's report site, the boundary rule's reason for existing. Each is three lines or fewer.

## Planner rulings (2026-09-28), applied at the e9953e96 reconcile

- **D-P1 ACCEPTED:** move the three classes (A2). Rechecked at `e9953e96`: `WorkerPresence`, `SweepStreamHeartbeat` and `RecommendationDriverKind` are named by the same 25 files as at `566ee103` (Recommendation `Run` code, `RecommendationDrainCommand`, `RecommendationSettingsController`, `Worker` itself, `config/services.yaml:213`, `config/services_test.yaml:32` and `:488`, and their tests; `OpenAiCompatibleChatClientTest`, `ProviderTimeoutsTest`, `docs/architecture.md:183` and `docs/local-docker.md:82` only by short name in prose). Neither #1163 nor #1166 touched `Service/Worker` or `Recommendation/Run`. They describe the recommendation driver, so `Recommendation/Run` owns them.
- **D-P2 OVERRULED:** rename on arrival, in a separate commit right after A1's pure move, so the move stays provable by a comment-stripped diff. Applied as Task A1b, with this table (checked at `e9953e96`; only these names carry the prefix):

  | Before (after A1) | After A1b |
  |---|---|
  | `App\Service\Image\CatalogFaviconFetcherInterface` (`src/Service/Image/CatalogFaviconFetcherInterface.php`) | `App\Service\Image\FaviconFetcherInterface` (`src/Service/Image/FaviconFetcherInterface.php`) |
  | `App\Service\Image\CatalogFaviconFetcher` (`src/Service/Image/CatalogFaviconFetcher.php`) | `App\Service\Image\FaviconFetcher` (`src/Service/Image/FaviconFetcher.php`) |
  | `App\Tests\Service\Image\CatalogFaviconFetcherTest` (`tests/Service/Image/CatalogFaviconFetcherTest.php`) | `App\Tests\Service\Image\FaviconFetcherTest` (`tests/Service/Image/FaviconFetcherTest.php`) |

  Unchanged, because they carry no `Catalog` prefix: `Image\FetchedFavicon`, `Image\Exception\FaviconRejectedException`, `Image\Exception\FaviconUnavailableException` (there is no `CatalogFavicon*Exception`), and `tests/Support/StubFaviconFetcher`. Unchanged, because `Catalog` owns them: `Catalog\CatalogFavicon`, `CatalogFaviconSource`, `CatalogFaviconWarmer`, `MonogramFavicon`, `Command\WarmCatalogFaviconsCommand`, `Http\CatalogFaviconResponse`, `Repository\CatalogFaviconDueCriteria` and their tests. No `services*.yaml` entry names the fetcher: it and its interface alias are autowired, so their service ids follow the FQCN and nothing in `config` changes. No chip.
- **D-P3 ACCEPTED, with a constraint:** there is no shared collector pass. B2's boundary rule reuses `ClassNameReferences` / `ForbiddenReference` and declares its own `REMEDIES` map (the #1182 B8 shape: the remedy looked up by `$reference->matchedRule`), with no matching logic of its own. B2's code does exactly that. The WATCH item closes.
- **D-P4 ACCEPTED:** `Service/Feed` (A4).

## Planner decisions needed

All four are RULED; see "Planner rulings (2026-09-28), applied at the e9953e96 reconcile".
- **D-P1:** RULED (accepted), A2.
- **D-P2:** RULED (overruled): the rename is Task A1b.
- **D-P3:** RULED (accepted with the `REMEDIES` constraint), B2.
- **D-P4:** RULED (accepted), A4.

## Not in scope

- Renaming `FetchedFavicon` and the two `Favicon*Exception`s to image-generic names (D-P2 renames only the `Catalog` prefix away).
- A rule that the `Service` root stays empty. A loose class counts as its own module (D1), so a cycle through it is still caught.
- Comment trimming in the moved files, for #1171's sweep: `Image/{FaviconFetcher,FaviconFetcherInterface,FetchedFavicon}.php`, `Image/Exception/Favicon{Rejected,Unavailable}Exception.php`, `Recommendation/Run/{WorkerPresence,SweepStreamHeartbeat,RecommendationDriverKind}.php`, `Feed/{FeedScheduler,OrphanedFeedReclaimer}.php` and their moved tests.
- `tests/Service/FillMissingImagesTest.php`, which sits in the `tests/Service` root with no `src` counterpart (it tests `Ingest\EntryIngestor::fillMissingImages()`). #1166 uses it as a characterization test. Carry it to #1169's tests sweep.
- The other #1169 carry-forward items, among them `OwnedSubscriptions` owner-first and a `UserScopedMethodOwnerFirstRule` (a different rule), the home of `ViewerTimeZone`, the home of the shared module enums, and the `self::once()` sweep.
- Merging the class-reference rules behind one collector (D-P3).

## Wire changes

None. No controller, response, request DTO, route, message or stored value changes:
- The moved classes keep their code, so `ImageVerifier`, the digest embedder, the favicon warmer, the recommendation sweeps, the poll driver, the drain spawner, the settings card's worker flag, the refresh scheduler and the orphan reclaim behave exactly as before.
- Only container service ids change (a service id is its FQCN; A1b's renames change the fetcher's and its interface alias's ids, all autowired). No messenger message moves (`Worker\Message\*` stays, and every message is an empty class), and no moved class is cached or serialised.
- No frontend file changes, so `npm run check` is not a gate.

## Reconciled at e9953e96

#1163 (PRs #1201, #1203, #1204) and #1166 (PRs #1205, #1206, #1207) have merged; `origin/develop` is at `e9953e96`, and no other commit landed after `566ee103`. Every row below was read at `e9953e96` (`git show`, `git grep`, `git diff 566ee103 e9953e96`). Every PR A move is scripted over whatever files name the moved class, so the files those issues added are picked up by the script; the "Files" lists below name them.

**Cycles.** #1163 removed `Reader → Search` (`SearchMarkReadService` is in `Reading`), `Recommendation → Reader` (`ForYouMarkReadService` is in `Reading`) and `Tag → Reader` (`ExactSetGuard` is in `Tag`), and added `Reading → Search`. `Search` depends only on `Text`, so no cycle appeared. #1166 added `Refresh/{FeedOutcomePersister,MissingFaviconResolver,RefreshHousekeeping,RefreshPass}`, `Ingest/{EntryImageWriter,IncomingEntry,IngestedEntryFactory}`, `Catalog/CatalogImportPass`, `Backup/RestoreDestination` and `Subscription/{BulkSubscribeBatch,BulkSubscribePositions}` and added no module edge. The strongly connected sets are the three of `566ee103` (see "Module graph at e9953e96").

| File | What #1163 / #1166 did | State at `e9953e96` | What this plan does with it |
|---|---|---|---|
| `config/services.yaml` | #1163 added the `ReaderBodyCleaner` `$steps` block | `$workerLiveness: '@App\Service\Worker\SweepStreamHeartbeat'` at line 213; no entry names the favicon fetcher or the two root services | A2's script rewrites that one id |
| `config/services_test.yaml` | #1166 rewrote the comment above `App\Service\Refresh\FeedBodyParser:` | `App\Service\Worker\WorkerPresence:` (line 32) and `App\Service\Worker\SweepStreamHeartbeat:` (line 488) public overrides | A2's script rewrites those two ids |
| `CLAUDE.md` | #1163 added `Reading` to the `backend/src/Service/**` Layout row | The "Shared values have one home." bullet ends at line 109 (`` `PersistenceKnowsNoServiceRule`). ``); the `EntityIdCoercionRule` bullet ends at line 161 | B3 inserts one bullet after each |
| `docs/architecture.md` | Neither | Last sections `## 7.` and `## 8.`; §8 names `RecommendationDriverKind` by short name only | B3 appends §9 |
| `src/Service/Refresh/RefreshRunner.php` | #1166 split it | Names neither `FeedScheduler` nor `OrphanedFeedReclaimer` | Not touched |
| `src/Service/Refresh/FeedOutcomePersister.php` | Created by #1166 | `use App\Service\FeedScheduler;` (line 10) | A4's script rewrites the import |
| `src/Service/Refresh/RefreshHousekeeping.php` | Created by #1166 | `use App\Service\OrphanedFeedReclaimer;` (line 7) | A4's script rewrites the import |
| `src/Service/Refresh/MissingFaviconResolver.php` | Created by #1166 | Uses `Fetch\FaviconResolverInterface`, not the favicon fetcher A1 moves | Not touched |
| `src/Service/Refresh/RefreshPass.php` | Created by #1166 | Names no moved class | Not touched |
| `src/Service/Subscription/FirstFetchRecorder.php` | #1166 edited one comment | `use App\Service\FeedScheduler;` (line 9) | A4's script rewrites the import |
| `tests/Support/FeedSchedulers.php` | Created by #1166 | `use App\Service\FeedScheduler;` (line 7); builds it for every refresh and subscription test | A4's script rewrites the import |
| `tests/Support/RefreshRunners.php` | Created by #1166 | `use App\Service\OrphanedFeedReclaimer;` (line 14); gets its `FeedScheduler` from `FeedSchedulers::build()` | A4's script rewrites the import |
| `tests/Support/TagJoins.php` | Created by #1166 | Names no moved class | Not touched |
| `tests/Service/Refresh/RefreshHousekeepingTest.php` | Created by #1166 | `use App\Service\OrphanedFeedReclaimer;` (line 11) | A4's script rewrites the import |
| `tests/Service/Refresh/FeedOutcomePersisterTest.php`, `RefreshRunnerTest.php`, `RefreshRunnerConcurrentFetchTest.php`, `RefreshRunnerOrphanSweepTest.php`, `tests/Service/Maintenance/MaintenanceTickTest.php`, `tests/Service/Subscription/FirstFetchRecorderTest.php` | #1166 moved their assembly onto `RefreshRunners` / `FeedSchedulers` | Import neither class (`RefreshRunnerTest` names both in two comments, by short name) | Not touched |
| `tests/Service/Subscription/SubscriptionServiceTest.php`, `UnsubscribeAllTest.php` | #1166 rewrote `SubscriptionServiceTest`'s `service()` | `use App\Service\OrphanedFeedReclaimer;` (lines 21 and 14) | A4's script rewrites the imports |
| `src/Service/Reading/SearchMarkReadService.php`, `src/Service/Reading/ForYouMarkReadService.php` | #1163 moved them from `Reader` and `Recommendation/Feed` | `Reader` names no `Search` class; `Recommendation` names no `Reader` class | Not touched. B0 re-checks both; B2's rule depends on it |
| `src/Service/Tag/ExactSetGuard.php` | #1163 moved it from `Reader` | `Tag → Reader` is gone | Not touched |
| `src/Service/Reader/ArticleExtractorInterface.php` | #1163 rewrote it | `namespace App\Service\Reader;` at line 5, no import | B2 Step 6 inserts and deletes one line in it |

`git diff --stat 566ee103 e9953e96` is empty for `Service/Image`, `Service/Catalog/CatalogFavicon*`, `Service/Mail`, `Service/Worker`, `Service/Url`, `Fetch/UrlResolver.php`, `Fetch/PageUrls.php`, `Service/Account`, the two root services and their tests, `tests/Service/{Image,Url,Worker,Recommendation}`, `src/Command`, `tests/PhpStan`, `phpstan.dist.neon` and `docs/architecture.md`. In `Service/Recommendation` only `Feed/ForYouMarkReadService.php` left. Every other anchor in this plan is therefore as it was at `566ee103`.

## Global Constraints

- **Paths and commands are relative to `backend/`**, except steps marked "from the repository root" and `docs/…` and `CLAUDE.md`.
- **Behaviour does not change** (see "Wire changes"). A1, A2 and A4 are pure moves: every code line of a moved class is byte-identical, and a non-moved file changes only in `use` lines, `namespace` lines and old-to-new FQCN rewrites. A1b is a pure rename: every changed line differs only in `CatalogFaviconFetcher` becoming `FaviconFetcher`. A3 changes `UrlResolver`, `PageUrls` and `FeedWebsite` only as shown.
- **No `public private(set)` and no unparenthesised `new Foo()->bar()`:** pdepend 2.16.2 (`composer md`) cannot parse either. No code in this plan uses them.
- **Today's folder and naming conventions.** The #1202 `Model/`, `Factory/` and `Pass/` role folders are not applied here.
- **Clean Code (CLAUDE.md) is mandatory:** names reveal intent; `final readonly` by default (`UrlOrigin` is a `final class` of static helpers, like `AbsoluteHttpUrl` and `FeedWebsite`); three parameters at most; no boolean flag parameters; guard clauses; no `new` on a collaborator inside a method.
- **Comments (D12):** default none, at most three lines. No `@param`/`@return` that repeats the signature, unless PHPStan needs the array shape. Comments in moved files stay as they are (D7).
- **PSR-12 line length: 120 columns.** Every code line in this plan fits.
- **Tests:** PHPUnit 12 attributes; `assertSame`, never `assertEquals` on values; persisted ids through `requireId()`.
- **Every touched `src` file is PHPMD-clean** under `composer md`. A pure move changes no metric PHPMD measures; if `composer md` reports a moved file, stop and report (develop was clean, so the move script changed code).
- **phptramp:** A3 adds no parameter chain. Run `composer tramp` (part of `composer check`) in every task.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`. `bin/console cache:warmup` before `composer stan` if the dev cache is cold, and `bin/console cache:clear` after a move (service ids change).
- **PhpStorm inspections** on every changed PHP file (`mcp__phpstorm__lint_files`): ERROR and WARNING block. An "unused import" WARNING in a file the script touched: delete that import and note it.
- **Test-first where code changes (A3, B1, B2); pin-first where it must not.** A pin passes before the change and must keep passing: the step that first runs it says "Expected: PASS (pin)".
- **Every new test gets a deletion check.** Break the line it covers, run the test, watch it fail, then restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report. Each check names one edit and the FAIL it must produce; a check whose test still passes, or fails for another reason than the one named, is a finding for the planner, not a pass. A pin that expects `null`, `0`, `''`, `false` or no error at all needs a check that makes the code return something else.
- **Each task implementer runs every deletion check and quotes its FAIL output in the task report; the reviewer re-runs at least one.**
- **Infection.** An escaped mutant on a line this PR touched gets a killing test in the task that owns the line. A provably equivalent mutant is removed by rewriting the line, or reported to the planner. Never add an `ignore`, never lower `minMsi`. A renamed file is not in `infection:diff`'s `--git-diff-filter=AM`.
- **Gates for every task:** the task's tests, `composer check`, `composer md`, `bin/console lint:container`, and the PhpStorm inspections.
- **Commits:** `refactor(#1161): <lower-case summary>`, one per task, no attribution lines. The plan copy is committed the same way.
- **Branches, each cut from `origin/develop` after the previous PR merges:**
  - PR A: `refactor/1161-service-module-cycles`
  - PR B: `refactor/1161-service-module-rules`
- **PR bodies.** PR A says `Refs #1161`. Neither its body, its prose nor any commit message on its branch (A1b's included) may contain "close", "closes", "closed", "fix", "fixes", "fixed", "resolve", "resolves" or "resolved" in any form. (`UrlResolver`, `FaviconResolverInterface` and "fixture" do not match the word-boundary check in the Finishing steps.) PR B's body says `Closes #1161`.
- **The checkout is shared.** Run `git status --short` and `git branch --show-current` before any `switch`, `reset` or `stash`; another session may be mid-edit. Work in place, with no worktrees.

---

# PR A — Break the cycles; the loose services get a module

### Task A0: Branch, plan copy, move script and the baseline graph

**Files:**
- Create: `docs/superpowers/plans/2026-09-28-1161-service-module-cycles.md` (the plan copy)
- Create (not committed; `var/` is ignored): `var/refactor-1161/move-classes.php`

- [ ] **Step 1: Confirm the queue ahead has landed and the checkout is free (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1163 --json state --jq .state
gh issue view 1166 --json state --jq .state
gh issue view 1161 --json state --jq .state
```
Expected:
- A clean tree, or only another session's files, which you leave alone.
- `CLOSED`, `CLOSED`, then `OPEN`.

If #1163 or #1166 is still open, stop and report: this plan is queued behind them.

- [ ] **Step 2: Cut the branch and commit the plan copy (from the repository root)**

```bash
git switch -c refactor/1161-service-module-cycles origin/develop
mkdir -p docs/superpowers/plans
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1161-draft.md docs/superpowers/plans/2026-09-28-1161-service-module-cycles.md
git add docs/superpowers/plans/2026-09-28-1161-service-module-cycles.md
git commit -m "refactor(#1161): add the implementation plan"
```

- [ ] **Step 3: Write the move script**

This is #1162's `var/refactor-1162/move-classes.php`, unchanged except the usage line. `var/refactor-1161/move-classes.php`:
```php
<?php

declare(strict_types=1);

// php var/refactor-1161/move-classes.php var/refactor-1161/<map>.php
// The map returns array<string, string>: old FQCN => new FQCN, for src and test classes alike.

const TYPE_TAGS = '@(?:phpstan-|psalm-)?(?:param|return|var|throws|extends|implements|use|mixin'
    . '|template(?:-covariant|-contravariant)?|assert(?:-if-true|-if-false)?|property(?:-read|-write)?'
    . '|method|self-out|this-out|require-extends|require-implements)\b';

function pathOf(string $class): string
{
    $relative = str_starts_with($class, 'App\\Tests\\')
        ? 'tests/' . substr($class, strlen('App\\Tests\\'))
        : 'src/' . substr($class, strlen('App\\'));

    return str_replace('\\', '/', $relative) . '.php';
}

function namespaceOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? '' : substr($class, 0, $separator);
}

function shortNameOf(string $class): string
{
    $separator = strrpos($class, '\\');

    return false === $separator ? $class : substr($class, $separator + 1);
}

function run(string $command): void
{
    passthru($command, $status);
    if (0 !== $status) {
        fwrite(STDERR, "Failed: {$command}\n");
        exit(1);
    }
}

/** @return list<string> tracked files, never a PHPStan rule or fixture: their class names are test data */
function trackedFiles(string ...$pathspecs): array
{
    $output = (string) shell_exec('git ls-files -- ' . implode(' ', array_map('escapeshellarg', $pathspecs)));

    return array_values(array_filter(
        explode("\n", $output),
        static fn (string $file): bool => '' !== $file && !str_starts_with($file, 'tests/PhpStan/'),
    ));
}

/** @return list<string> */
function phpFiles(): array
{
    return array_values(array_filter(
        trackedFiles('src', 'tests'),
        static fn (string $file): bool => str_ends_with($file, '.php'),
    ));
}

function declaredNamespace(string $code): string
{
    return 1 === preg_match('/^namespace ([^;]+);$/m', $code, $match) ? $match[1] : '';
}

/** @return array<string, true> the short names a file imports, aliases included */
function importedNames(string $code): array
{
    preg_match_all('/^use (?!function |const )([\w\\\\]+)(?: as (\w+))?;$/m', $code, $matches, PREG_SET_ORDER);
    $names = [];
    foreach ($matches as $match) {
        $names[$match[2] ?? shortNameOf($match[1])] = true;
    }

    return $names;
}

/** @param list<mixed> $tokens */
function significantNeighbour(array $tokens, int $index, int $step): mixed
{
    for ($position = $index + $step; isset($tokens[$position]); $position += $step) {
        $token = $tokens[$position];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $token;
    }

    return null;
}

/** @param list<mixed> $tokens */
function isClassPosition(array $tokens, int $index): bool
{
    $previous = significantNeighbour($tokens, $index, -1);
    $next = significantNeighbour($tokens, $index, 1);
    $previousId = is_array($previous) ? $previous[0] : $previous;
    $memberOrDeclaration = [
        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST,
        T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO,
    ];
    if (in_array($previousId, $memberOrDeclaration, true)) {
        return false;
    }
    if (T_CASE === $previousId) {
        return is_array($next) && T_DOUBLE_COLON === $next[0];
    }

    return ':' !== $next;
}

function typeExpressionAfter(string $text, int $offset): string
{
    $length = strlen($text);
    while ($offset < $length && ctype_space($text[$offset])) {
        ++$offset;
    }
    $depth = 0;
    $type = '';
    for (; $offset < $length; ++$offset) {
        $char = $text[$offset];
        if (0 === $depth && ctype_space($char)) {
            if (!str_ends_with($type, ':')) {
                break;
            }

            continue;
        }
        $depth += match ($char) {
            '<', '{', '(', '[' => 1,
            '>', '}', ')', ']' => -1,
            default => 0,
        };
        $type .= $char;
    }

    return $type;
}

/** @return list<string> */
function identifiersIn(string $type): array
{
    preg_match_all('/(?<![\w\\\\$-])[A-Za-z_]\w*(?![\w\\\\-])(?!\s*\??:(?!:))/', $type, $matches);

    return $matches[0];
}

/** @return list<string> class names a docblock uses as a type, an imported type's source, or a @see target */
function docblockNames(string $docblock): array
{
    $text = (string) preg_replace(['~^\s*/\*\*~', '~\*/\s*$~', '~^\s*\*~m'], ' ', $docblock);
    $names = [];
    preg_match_all('/' . TYPE_TAGS . '/', $text, $tags, PREG_OFFSET_CAPTURE);
    foreach ($tags[0] as [$tag, $offset]) {
        array_push($names, ...identifiersIn(typeExpressionAfter($text, $offset + strlen($tag))));
    }
    preg_match_all('/@(?:phpstan-|psalm-)?import-type\s+\w+\s+from\s+([A-Za-z_]\w*)(?![\w\\\\])/', $text, $imports);
    preg_match_all('/(?:\{@see|@see|@uses)\s+([A-Za-z_]\w*)(?![\w\\\\])/', $text, $sees);

    return [...$names, ...$imports[1], ...$sees[1]];
}

/** @return array<string, true> bare names the code and its docblock types use as class names */
function referencedNames(string $code): array
{
    $tokens = token_get_all($code);
    $names = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        if (T_DOC_COMMENT === $token[0]) {
            foreach (docblockNames($token[1]) as $name) {
                $names[$name] = true;
            }

            continue;
        }
        if (T_STRING === $token[0] && isClassPosition($tokens, $index)) {
            $names[$token[1]] = true;
        }
    }

    return $names;
}

function removeImport(string $code, string $class): string
{
    $trimmed = str_replace("use {$class};\n", '', $code);
    if ($trimmed === $code) {
        return $code;
    }

    return (string) preg_replace_callback(
        '/^(namespace [^;]+;\n)\n\n+/m',
        static fn (array $match): string => $match[1] . "\n",
        $trimmed,
    );
}

function addImport(string $code, string $class): string
{
    $line = "use {$class};\n";
    if (str_contains($code, "\n{$line}")) {
        return $code;
    }
    preg_match_all(
        '/^use (?!function |const )([\w\\\\]+)(?: as \w+)?;\n/m',
        $code,
        $imports,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    if ([] === $imports) {
        $namespaceEnd = (int) strpos($code, ";\n", (int) strpos($code, "\nnamespace ")) + 2;

        return substr_replace($code, "\n" . $line, $namespaceEnd, 0);
    }
    foreach ($imports as $import) {
        if (strcasecmp($import[1][0], $class) > 0) {
            return substr_replace($code, $line, $import[0][1], 0);
        }
    }
    $last = $imports[array_key_last($imports)];

    return substr_replace($code, $line, $last[0][1] + strlen($last[0][0]), 0);
}

/** @var array<string, string> $moves */
$moves = require $argv[1];
$oldNamespaces = array_flip(array_map('namespaceOf', array_keys($moves)));

// Where every class of an affected namespace lives once the moves are done.
$destinations = [];
foreach (phpFiles() as $file) {
    $namespace = declaredNamespace((string) file_get_contents($file));
    if (isset($oldNamespaces[$namespace])) {
        $class = $namespace . '\\' . basename($file, '.php');
        $destinations[$namespace][basename($file, '.php')] = $moves[$class] ?? $class;
    }
}

// 1. Before anything moves: which bare names each file will have to import.
$importsFor = [];
foreach (phpFiles() as $file) {
    $code = (string) file_get_contents($file);
    $namespace = declaredNamespace($code);
    if (!isset($oldNamespaces[$namespace])) {
        continue;
    }
    $class = $namespace . '\\' . basename($file, '.php');
    $destination = $moves[$class] ?? $class;
    $imported = importedNames($code);
    foreach (array_keys(referencedNames($code)) as $name) {
        $target = $destinations[$namespace][(string) $name] ?? null;
        if (null === $target || isset($imported[$name]) || $target === $destination) {
            continue;
        }
        if (namespaceOf($target) !== namespaceOf($destination)) {
            $importsFor[pathOf($destination)][$target] = true;
        }
    }
}

// 2. Each file moves, and its namespace line with it.
foreach ($moves as $old => $new) {
    $to = pathOf($new);
    if (!is_dir(dirname($to))) {
        mkdir(dirname($to), 0o775, true);
    }
    run(sprintf('git mv %s %s', escapeshellarg(pathOf($old)), escapeshellarg($to)));
    file_put_contents($to, str_replace(
        'namespace ' . namespaceOf($old) . ";\n",
        'namespace ' . namespaceOf($new) . ";\n",
        (string) file_get_contents($to),
    ));
}

// 3. Every spelling of every old name, in code, strings, docblocks and config, becomes the new one.
$patterns = [];
foreach ($moves as $old => $new) {
    $patterns['/(?<!\w)' . preg_quote($old, '/') . '(?!\w)/'] = $new;
    $patterns['/(?<!\w)' . preg_quote(str_replace('\\', '\\\\', $old), '/') . '(?!\w)/']
        = str_replace('\\', '\\\\', $new);
}
$textFiles = trackedFiles('src', 'tests', 'config', 'phpstan.dist.neon', 'infection.json5', 'phptramp.dist.json');
foreach ($textFiles as $file) {
    $code = (string) file_get_contents($file);
    $rewritten = $code;
    foreach ($patterns as $pattern => $replacement) {
        $rewritten = (string) preg_replace_callback($pattern, static fn (): string => $replacement, $rewritten);
    }
    if ($rewritten !== $code) {
        file_put_contents($file, $rewritten);
    }
}

// 4. An import of a class that now shares the file's namespace goes.
foreach (phpFiles() as $file) {
    $code = (string) file_get_contents($file);
    $namespace = declaredNamespace($code);
    $trimmed = $code;
    foreach ($moves as $new) {
        if (namespaceOf($new) === $namespace) {
            $trimmed = removeImport($trimmed, $new);
        }
    }
    if ($trimmed !== $code) {
        file_put_contents($file, $trimmed);
    }
}

// 5. A bare name that left the file's namespace gets an import.
$added = 0;
foreach ($importsFor as $file => $classes) {
    $code = (string) file_get_contents($file);
    foreach (array_keys($classes) as $class) {
        $code = addImport($code, (string) $class);
        ++$added;
    }
    file_put_contents($file, $code);
}

printf("Moved %d classes; added %d imports in %d files.\n", count($moves), $added, count($importsFor));
```

- [ ] **Step 4: Record the baseline module graph**

```bash
git grep -E '^use App\\Service\\' origin/develop -- src/Service \
  | sed -E 's#^(origin/develop:)?(backend/)?##; s#^src/Service/([^/:]+)(/[^:]*)?:use App\\Service\\([A-Za-z0-9_]+).*#\1 \3#; s#^([A-Za-z0-9_]+)\.php #\1 #' \
  | awk '$1 != $2' | sort -u > var/refactor-1161/module-edges-before.txt
wc -l < var/refactor-1161/module-edges-before.txt
tsort var/refactor-1161/module-edges-before.txt > /dev/null 2> var/refactor-1161/cycles-before.txt
cat var/refactor-1161/cycles-before.txt
```
Expected:
- `118` (the count at `e9953e96`; a later develop commit may shift it, which is fine as long as the cycles below are unchanged).
- Four `tsort: cycle in data` blocks that name only these modules: `Recommendation` and `Worker`; `Category`, `Parser`, `Image`, `Catalog`, `Opml`, `Subscription`, `Ingest`; `Catalog`, `Opml`, `Subscription`, `Discovery`, `Parser`, `Image`; `Fetch` and `Url` (the output at `e9953e96`). This proves the check sees the cycles PR A removes.

A block that names any other module is a cycle this plan does not know about: stop and report it, with the output.

---

### Task A1: The favicon fetcher moves to `Service/Image`

A pure move. It removes `Image → Catalog`, the only edge back into `Catalog` inside the nine-module cycle, and `Mail → Catalog`. It adds `Image → Fetch` and `Catalog → Image`; neither closes a cycle.

**Files:**
- Create (not committed): `var/refactor-1161/favicon-fetcher-map.php`
- Move: `src/Service/Catalog/{CatalogFaviconFetcher,CatalogFaviconFetcherInterface,FetchedFavicon}.php` → `src/Service/Image/`
- Move: `src/Service/Catalog/Exception/{FaviconRejectedException,FaviconUnavailableException}.php` → `src/Service/Image/Exception/`
- Move: `tests/Service/Catalog/CatalogFaviconFetcherTest.php` → `tests/Service/Image/`
- Modify (by the script, names and imports only): `src/Service/Image/ImageVerifier.php`, `src/Service/Mail/Digest/DigestImageEmbedder.php`, `src/Service/Catalog/CatalogFaviconWarmer.php`, `tests/Support/StubFaviconFetcher.php`, `tests/Command/WarmCatalogFaviconsCommandTest.php`, `tests/Controller/Admin/AdminCatalogControllerTest.php`, `tests/Service/Catalog/CatalogFaviconWarmerTest.php`, `tests/Service/Image/ImageVerifierTest.php`, `tests/Service/Image/ImageVerificationSweepTest.php`, `tests/Service/Mail/Digest/DigestImageEmbedderTest.php`

**Interfaces:**
- Produces: `App\Service\Image\CatalogFaviconFetcher`, `App\Service\Image\CatalogFaviconFetcherInterface::download(string $iconUrl): FetchedFavicon`, `App\Service\Image\FetchedFavicon`, `App\Service\Image\Exception\FaviconRejectedException`, `App\Service\Image\Exception\FaviconUnavailableException`, code unchanged. No class in `src/Service/Image` names `App\Service\Catalog`. A1b renames the first two and the moved test in the next commit.

- [ ] **Step 1: Verify the anchors**

```bash
git ls-files src/Service/Catalog | grep -E 'Favicon(Fetcher|FetcherInterface)\.php|FetchedFavicon|Exception/Favicon'
git grep -n -E 'App\\Service\\Catalog' -- src/Service/Image src/Service/Mail
git grep -n -w -E 'CatalogFaviconFetcherInterface|FetchedFavicon|CatalogFaviconFetcher' -- src/Service/Catalog/CatalogFaviconWarmer.php
ls src/Service/Image
```
Expected:
- Five files: `CatalogFaviconFetcher.php`, `CatalogFaviconFetcherInterface.php`, `FetchedFavicon.php`, `Exception/FaviconRejectedException.php`, `Exception/FaviconUnavailableException.php`.
- `ImageVerifier.php:8-10` (the interface and both exceptions) and `Mail/Digest/DigestImageEmbedder.php:7-8` (the interface and `FaviconUnavailableException`), nothing else.
- One line: the warmer's constructor parameter `private CatalogFaviconFetcherInterface $fetcher,` (a bare name the script must import).
- `DeclaredImage.php`, `ImageDimensions.php`, `ImageVerificationReport.php`, `ImageVerificationSweep.php`, `ImageVerifier.php`, `ImageVerifyOutcome.php`, and no `Exception` directory.

If any differs, stop and report.

- [ ] **Step 2: Write the map**

`var/refactor-1161/favicon-fetcher-map.php`:
```php
<?php

declare(strict_types=1);

$moves = [];
foreach (['CatalogFaviconFetcher', 'CatalogFaviconFetcherInterface', 'FetchedFavicon'] as $class) {
    $moves['App\\Service\\Catalog\\' . $class] = 'App\\Service\\Image\\' . $class;
}
foreach (['FaviconRejectedException', 'FaviconUnavailableException'] as $exception) {
    $moves['App\\Service\\Catalog\\Exception\\' . $exception] = 'App\\Service\\Image\\Exception\\' . $exception;
}
$moves['App\\Tests\\Service\\Catalog\\CatalogFaviconFetcherTest']
    = 'App\\Tests\\Service\\Image\\CatalogFaviconFetcherTest';

return $moves;
```

- [ ] **Step 3: Run it**

```bash
git status --short
php var/refactor-1161/move-classes.php var/refactor-1161/favicon-fetcher-map.php
```
Expected: the first command prints nothing (a clean tree, so the move commit holds only the move). Then 6 `git mv` runs without error and `Moved 6 classes; added 1 imports in 1 files.` (the warmer's `use App\Service\Image\CatalogFaviconFetcherInterface;`). Any other count: read `git diff` for the extra imports and record them in the task report.

- [ ] **Step 4: Verify the move is complete, code-identical and one-directional**

```bash
git grep -n -E 'Service\\Catalog\\(CatalogFaviconFetcher|FetchedFavicon|Exception\\Favicon)' -- src tests config
git grep -n 'App\\Service\\Catalog' -- src/Service/Image src/Service/Mail
ls src/Service/Image src/Service/Image/Exception tests/Service/Image
strip() { grep -vE '^[[:space:]]*(\*|/\*\*|//|namespace |use App\\|$)'; }
for pair in \
  src/Service/Catalog/CatalogFaviconFetcher.php=src/Service/Image/CatalogFaviconFetcher.php \
  src/Service/Catalog/CatalogFaviconFetcherInterface.php=src/Service/Image/CatalogFaviconFetcherInterface.php \
  src/Service/Catalog/FetchedFavicon.php=src/Service/Image/FetchedFavicon.php \
  src/Service/Catalog/Exception/FaviconRejectedException.php=src/Service/Image/Exception/FaviconRejectedException.php \
  src/Service/Catalog/Exception/FaviconUnavailableException.php=src/Service/Image/Exception/FaviconUnavailableException.php \
  tests/Service/Catalog/CatalogFaviconFetcherTest.php=tests/Service/Image/CatalogFaviconFetcherTest.php; do
  diff <(git show "HEAD:backend/${pair%%=*}" | strip) <(strip < "${pair##*=}") || echo "CODE CHANGED: ${pair##*=}"
done
git diff -U0 HEAD -- src tests config | grep -E '^[+-]' | grep -vE '^(\+\+\+|---) ' \
  | grep -vE '^[+-]$|^[+-](use |namespace )|CatalogFaviconFetcher|FetchedFavicon|Favicon(Rejected|Unavailable)Exception'
git status --short -- tests/PhpStan
```
Expected:
- The two greps print nothing.
- `Image` lists its six classes plus the three moved ones and `Exception`; `Image/Exception` lists the two exceptions; `tests/Service/Image` lists `CatalogFaviconFetcherTest.php` beside the existing four tests.
- The comparison loop prints nothing: only namespace, import, comment and blank lines differ.
- The diff filter prints nothing: every other changed line is an import, a namespace or a line naming a moved class.
- `tests/PhpStan` is untouched.

- [ ] **Step 5: Gates**

```bash
bin/console cache:clear && bin/console cache:warmup
bin/console lint:container
php bin/phpunit tests/Service/Image tests/Service/Catalog tests/Service/Mail/Digest tests/Command/WarmCatalogFaviconsCommandTest.php tests/Controller/Admin/AdminCatalogControllerTest.php
composer check
composer md
```
Expected: `lint:container` reports the container is valid; the tests PASS; `composer check` and `composer md` are clean. A PHPStan `Class App\Service\Catalog\X not found` (or `…\Image\X not found`) names a bare reference the script missed: add the import by hand and note it. Then PhpStorm `lint_files` on every file `git status --short` lists as renamed or modified.

- [ ] **Step 6: Commit**

```bash
git add -A src tests config
git status --short | grep -v '^[RM] ' || true
git commit -m "refactor(#1161): the favicon fetcher moves from Service/Catalog to Service/Image"
```
Expected: the `grep -v` prints nothing (only renames and modifications are staged).

---

### Task A1b: The moved favicon fetcher drops its `Catalog` prefix

A pure rename, in its own commit so A1 stays a provable move (ruling D-P2). In `Image` the fetcher also serves `Mail/Digest`, so `Catalog` in its name misleads. The rename table is in "Planner rulings": `CatalogFaviconFetcherInterface` → `FaviconFetcherInterface`, `CatalogFaviconFetcher` → `FaviconFetcher`, `CatalogFaviconFetcherTest` → `FaviconFetcherTest`. `FetchedFavicon` and the two `Favicon*Exception`s carry no `Catalog` prefix and keep their names; `Catalog`'s own `CatalogFavicon`, `CatalogFaviconSource`, `CatalogFaviconWarmer` and `MonogramFavicon` keep theirs.

**Files:**
- Rename: `src/Service/Image/CatalogFaviconFetcherInterface.php` → `src/Service/Image/FaviconFetcherInterface.php`
- Rename: `src/Service/Image/CatalogFaviconFetcher.php` → `src/Service/Image/FaviconFetcher.php`
- Rename: `tests/Service/Image/CatalogFaviconFetcherTest.php` → `tests/Service/Image/FaviconFetcherTest.php`
- Modify (names only): `src/Service/Ai/Completion/OpenAiCompatibleChatClient.php` and `src/Service/Ai/OpenAiCompatibleCatalog.php` (one comment each), `src/Service/Catalog/CatalogFaviconWarmer.php`, `src/Service/Image/ImageVerifier.php`, `src/Service/Mail/Digest/DigestImageEmbedder.php`, `tests/Command/WarmCatalogFaviconsCommandTest.php`, `tests/Controller/Admin/AdminCatalogControllerTest.php`, `tests/Service/Catalog/CatalogFaviconWarmerTest.php`, `tests/Service/Mail/Digest/DigestImageEmbedderTest.php`, `tests/Support/StubFaviconFetcher.php`
- `config/services.yaml`, `config/services_test.yaml`: no change. Neither names the fetcher; it and its interface alias are autowired, so their service ids follow the new FQCNs.

**Interfaces:**
- Produces: `App\Service\Image\FaviconFetcher implements FaviconFetcherInterface`, `App\Service\Image\FaviconFetcherInterface::download(string $iconUrl): FetchedFavicon`, code otherwise unchanged. No file in `src`, `tests` or `config` names `CatalogFaviconFetcher`.

- [ ] **Step 1: Verify the anchors**

```bash
git ls-files src/Service/Image tests/Service/Image | grep -F 'CatalogFaviconFetcher'
git grep -c -w -E 'CatalogFaviconFetcher(Interface|Test)?' -- src tests config
git grep -n -w -E 'FaviconFetcher(Interface|Test)?' -- src tests config
git grep -n -E 'FaviconFetcher' -- config
```
Expected:
- Three files: `src/Service/Image/CatalogFaviconFetcher.php`, `src/Service/Image/CatalogFaviconFetcherInterface.php`, `tests/Service/Image/CatalogFaviconFetcherTest.php`.
- Thirteen files, 48 lines: `src/Service/Ai/Completion/OpenAiCompatibleChatClient.php:1`, `src/Service/Ai/OpenAiCompatibleCatalog.php:1`, `src/Service/Catalog/CatalogFaviconWarmer.php:2`, `src/Service/Image/CatalogFaviconFetcher.php:1`, `src/Service/Image/CatalogFaviconFetcherInterface.php:1`, `src/Service/Image/ImageVerifier.php:1`, `src/Service/Mail/Digest/DigestImageEmbedder.php:2`, `tests/Command/WarmCatalogFaviconsCommandTest.php:8`, `tests/Controller/Admin/AdminCatalogControllerTest.php:5`, `tests/Service/Catalog/CatalogFaviconWarmerTest.php:7`, `tests/Service/Image/CatalogFaviconFetcherTest.php:6`, `tests/Service/Mail/Digest/DigestImageEmbedderTest.php:11`, `tests/Support/StubFaviconFetcher.php:2`.
- Nothing: no `FaviconFetcher`, `FaviconFetcherInterface` or `FaviconFetcherTest` exists yet, so the rename clashes with nothing.
- Nothing: `config` names no fetcher.

If any differs, stop and report.

- [ ] **Step 2: Rename**

```bash
git status --short
git mv src/Service/Image/CatalogFaviconFetcherInterface.php src/Service/Image/FaviconFetcherInterface.php
git mv src/Service/Image/CatalogFaviconFetcher.php src/Service/Image/FaviconFetcher.php
git mv tests/Service/Image/CatalogFaviconFetcherTest.php tests/Service/Image/FaviconFetcherTest.php
git grep -l -w -E 'CatalogFaviconFetcher(Interface|Test)?' -- src tests config \
  | xargs perl -pi -e 's/\bCatalogFaviconFetcher(?=(?:Interface|Test)?\b)/FaviconFetcher/g'
```
Expected: a clean tree first. The `perl` rewrite covers the class and interface declarations, the test class, every import and FQCN, every short name in code, `::class` and `::MAX_BYTES`, and the two `Ai` comments. Import blocks it rewrote in place are not re-sorted (D7).

- [ ] **Step 3: Verify the rename is complete and changes nothing else**

```bash
git grep -n -E 'CatalogFaviconFetcher' -- src tests config
git grep -c -w -E 'FaviconFetcher(Interface|Test)?' -- src tests config
git grep -n -E '^(final readonly class|interface|final class) ' -- src/Service/Image/FaviconFetcher.php src/Service/Image/FaviconFetcherInterface.php tests/Service/Image/FaviconFetcherTest.php
git ls-files src/Service/Catalog | grep -i favicon
diff <(git diff -U0 -M HEAD -- src tests config | grep -E '^-[^-]' | sed -E 's/^-//; s/CatalogFaviconFetcher/FaviconFetcher/g') \
     <(git diff -U0 -M HEAD -- src tests config | grep -E '^\+[^+]' | sed -E 's/^\+//')
git diff -M HEAD --name-status -- src tests config
```
Expected:
- The first grep prints nothing: the deletion proof. No `CatalogFaviconFetcher` is left in `src`, `tests` or `config`.
- The second prints the same thirteen files and counts as Step 1's second command, with `FaviconFetcher.php`, `FaviconFetcherInterface.php` and `FaviconFetcherTest.php` in place of the three old paths (48 lines). This is the positive control: the first grep's silence means the names were rewritten, not lost.
- `final readonly class FaviconFetcher implements FaviconFetcherInterface`, `interface FaviconFetcherInterface`, `final class FaviconFetcherTest extends TestCase`.
- `CatalogFavicon.php`, `CatalogFaviconSource.php`, `CatalogFaviconWarmer.php`, `MonogramFavicon.php`: `Catalog` keeps its own classes.
- The `diff` prints nothing: every removed line comes back with only `CatalogFaviconFetcher` turned into `FaviconFetcher`.
- Three `R` lines (the three renames, similarity below 100% because their names changed) and ten `M` lines, nothing else.

- [ ] **Step 4: Gates**

```bash
bin/console cache:clear && bin/console cache:warmup
bin/console lint:container
php bin/phpunit tests/Service/Image tests/Service/Catalog tests/Service/Mail/Digest tests/Command/WarmCatalogFaviconsCommandTest.php tests/Controller/Admin/AdminCatalogControllerTest.php
composer check
composer md
```
Expected: the container is valid (the autowired alias `FaviconFetcherInterface` resolves to `FaviconFetcher`); the tests PASS, `WarmCatalogFaviconsCommandTest` and `AdminCatalogControllerTest` included (they replace the service by `FaviconFetcher::class`); `composer check` and `composer md` are clean. Then PhpStorm `lint_files` on the thirteen files.

- [ ] **Step 5: Commit**

```bash
git add -A src tests config
git status --short | grep -v '^[RM] ' || true
git commit -m "refactor(#1161): the favicon fetcher in Service/Image drops its Catalog prefix"
```
Expected: the `grep -v` prints nothing.

---

### Task A2: The recommendation driver liveness moves to `Service/Recommendation/Run`

A pure move. It removes `Recommendation → Worker`; `Worker → Recommendation` stays and gains three imports.

**Files:**
- Create (not committed): `var/refactor-1161/driver-liveness-map.php`
- Move: `src/Service/Worker/{WorkerPresence,SweepStreamHeartbeat,RecommendationDriverKind}.php` → `src/Service/Recommendation/Run/`
- Move: `tests/Service/Worker/{WorkerPresenceTest,SweepStreamHeartbeatTest}.php` → `tests/Service/Recommendation/Run/`
- Modify (by the script, names and imports only): `config/services.yaml` (`$workerLiveness`), `config/services_test.yaml` (two public overrides), `src/Service/Worker/WorkerRunSweep.php` (three added imports), `src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php`, `src/Service/Recommendation/Run/{ForYouSweep,RecommendationDrainSpawner,RecommendationPollDriver}.php` (imports dropped, now same-namespace), `src/Command/RecommendationDrainCommand.php`, `src/Controller/Api/RecommendationSettingsController.php`, and the tests that import a moved class: `tests/Command/RecommendationDrainCommandTest.php`, `tests/Controller/Api/RecommendationRunControllerTest.php`, `tests/Controller/Api/RecommendationSettingsControllerTest.php`, `tests/EventListener/RecommendationDrainOnTerminateListenerTest.php`, `tests/Service/Ai/Completion/CompletionStreamHeartbeatWiringTest.php`, `tests/Service/Recommendation/Run/ForYouSweepTest.php`, `tests/Service/Recommendation/Run/RecommendationDrainSpawnerTest.php`, `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`, `tests/Service/Worker/WorkerRunSweepTest.php`

**Interfaces:**
- Produces: `App\Service\Recommendation\Run\WorkerPresence`, `App\Service\Recommendation\Run\SweepStreamHeartbeat`, `App\Service\Recommendation\Run\RecommendationDriverKind`, code unchanged. No class in `src/Service/Recommendation` names `App\Service\Worker`.

- [ ] **Step 1: Verify the anchors**

```bash
git ls-files src/Service/Worker tests/Service/Worker
git grep -n 'App\\Service\\Worker' -- src/Service/Recommendation
git grep -n -w -E 'WorkerPresence|SweepStreamHeartbeat|RecommendationDriverKind' -- src/Service/Worker/WorkerRunSweep.php | grep -v ':[0-9]*: *\*'
ls src/Service/Recommendation/Run | grep -E '^(WorkerPresence|SweepStreamHeartbeat|RecommendationDriverKind)\.php$'
```
Expected:
- `src/Service/Worker`: `Handler/` (6 handlers), `Message/` (6 messages), `RecommendationDriverKind.php`, `SweepStreamHeartbeat.php`, `WorkerPresence.php`, `WorkerRunSweep.php`, `WorkerSchedule.php`; `tests/Service/Worker`: 10 tests including `SweepStreamHeartbeatTest.php` and `WorkerPresenceTest.php`.
- Five lines: `Run/ForYouSweep.php` (the three moved classes), `Run/RecommendationDrainSpawner.php` and `Run/RecommendationPollDriver.php` (`WorkerPresence`).
- `WorkerRunSweep.php`'s constructor parameters `WorkerPresence $presence` and `SweepStreamHeartbeat $heartbeat` and `sweep(RecommendationDriverKind $kind)`: the bare names the script must import.
- The last command prints nothing: no name clash in `Run`.

If any differs, stop and report.

- [ ] **Step 2: Write the map**

`var/refactor-1161/driver-liveness-map.php`:
```php
<?php

declare(strict_types=1);

$moves = [];
foreach (['WorkerPresence', 'SweepStreamHeartbeat', 'RecommendationDriverKind'] as $class) {
    $moves['App\\Service\\Worker\\' . $class] = 'App\\Service\\Recommendation\\Run\\' . $class;
}
foreach (['WorkerPresenceTest', 'SweepStreamHeartbeatTest'] as $test) {
    $moves['App\\Tests\\Service\\Worker\\' . $test] = 'App\\Tests\\Service\\Recommendation\\Run\\' . $test;
}

return $moves;
```

- [ ] **Step 3: Run it**

```bash
git status --short
php var/refactor-1161/move-classes.php var/refactor-1161/driver-liveness-map.php
```
Expected: a clean tree first; then 5 `git mv` runs and `Moved 5 classes; added 3 imports in 1 files.` (`WorkerRunSweep`). Any other count: read `git diff` for the extra imports and record them.

- [ ] **Step 4: Verify the move is complete, code-identical and one-directional**

```bash
git grep -n 'App\\Service\\Worker' -- src/Service/Recommendation
git grep -n -E 'Service\\Worker\\(WorkerPresence|SweepStreamHeartbeat|RecommendationDriverKind)' -- src tests config
git grep -c -E 'Recommendation\\Run\\(SweepStreamHeartbeat|WorkerPresence)' -- config/services.yaml config/services_test.yaml
strip() { grep -vE '^[[:space:]]*(\*|/\*\*|//|namespace |use App\\|$)'; }
for pair in \
  src/Service/Worker/WorkerPresence.php=src/Service/Recommendation/Run/WorkerPresence.php \
  src/Service/Worker/SweepStreamHeartbeat.php=src/Service/Recommendation/Run/SweepStreamHeartbeat.php \
  src/Service/Worker/RecommendationDriverKind.php=src/Service/Recommendation/Run/RecommendationDriverKind.php \
  tests/Service/Worker/WorkerPresenceTest.php=tests/Service/Recommendation/Run/WorkerPresenceTest.php \
  tests/Service/Worker/SweepStreamHeartbeatTest.php=tests/Service/Recommendation/Run/SweepStreamHeartbeatTest.php; do
  diff <(git show "HEAD:backend/${pair%%=*}" | strip) <(strip < "${pair##*=}") || echo "CODE CHANGED: ${pair##*=}"
done
git diff -U0 HEAD -- src tests config | grep -E '^[+-]' | grep -vE '^(\+\+\+|---) ' \
  | grep -vE '^[+-]$|^[+-](use |namespace )|WorkerPresence|SweepStreamHeartbeat|RecommendationDriverKind'
git status --short -- tests/PhpStan
```
Expected:
- The first two greps print nothing.
- `config/services.yaml:1` (the `$workerLiveness` argument) and `config/services_test.yaml:2` (the two public overrides).
- The comparison loop prints nothing.
- The diff filter prints nothing.
- `tests/PhpStan` is untouched.

- [ ] **Step 5: Gates**

```bash
bin/console cache:clear && bin/console cache:warmup
bin/console lint:container
php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Service/Ai tests/Command/RecommendationDrainCommandTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/Api/RecommendationSettingsControllerTest.php tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
composer check
composer md
```
Expected: the container is valid; the tests PASS, `CompletionStreamHeartbeatWiringTest` included (it proves the composite still fans out to the moved `SweepStreamHeartbeat` instance); `composer check` and `composer md` are clean. Then PhpStorm `lint_files` on every renamed or modified file.

- [ ] **Step 6: Commit**

```bash
git add -A src tests config
git status --short | grep -v '^[RM] ' || true
git commit -m "refactor(#1161): the recommendation driver liveness moves from Service/Worker to Service/Recommendation/Run"
```
Expected: the `grep -v` prints nothing.

---

### Task A3: `Url\UrlOrigin` owns a URL's origin

Removes `Url → Fetch`. `UrlResolver::origin()` and its private `originOf()` become `UrlOrigin::of()` and `UrlOrigin::fromParts()`, with the same bodies; `UrlResolver::resolve()` stays (D5).

**Files:**
- Create: `src/Service/Url/UrlOrigin.php`
- Create: `tests/Service/Url/UrlOriginTest.php`, `tests/Service/Fetch/UrlResolverTest.php`
- Modify: `src/Service/Fetch/UrlResolver.php` (rewritten in full), `src/Service/Fetch/PageUrls.php` (one import, one line), `src/Service/Url/FeedWebsite.php` (one import goes, one line)

**Interfaces:**
- Produces: `App\Service\Url\UrlOrigin::of(string $url): ?string` (scheme, host and port without a trailing slash; `null` when the URL names no scheme or no host, or does not parse) and `UrlOrigin::fromParts(string $scheme, string $host, ?int $port): string`.
- Keeps: `App\Service\Fetch\UrlResolver::resolve(string $baseUrl, string $location): string`, throwing `FeedUnreachableException` when the base names no host. `UrlResolver::origin()` is gone.

- [ ] **Step 1: Verify the anchors**

```bash
git grep -n -E 'UrlResolver::origin\(|function origin\(|function originOf\(' -- src/Service tests/Service
git grep -n 'App\\Service\\Fetch' -- src/Service/Url
ls tests/Service/Url tests/Service/Fetch | grep -E 'UrlOrigin|UrlResolver'
```
Expected:
- Five lines, as at `e9953e96`: `src/Service/Fetch/PageUrls.php:28` (`public function origin(): ?string`) and `:30` (`return UrlResolver::origin($this->pageUrl);`); `src/Service/Fetch/UrlResolver.php:22` (`public static function origin(string $url): ?string`) and `:58` (`private static function originOf(string $scheme, string $host, ?int $port): string`); `src/Service/Url/FeedWebsite.php:78` (`$origin = UrlResolver::origin($feedUrl);`). Nothing in `tests/Service`. (`CorsListener::originOf()` and a PhpStan fixture's `origin()` are outside the searched paths and unrelated.)
- One line: `src/Service/Url/FeedWebsite.php:7:use App\Service\Fetch\UrlResolver;`.
- Nothing: neither test exists.

If any differs, stop and report.

- [ ] **Step 2: Write the tests**

`tests/Service/Url/UrlOriginTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Url;

use App\Service\Url\UrlOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlOriginTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'path, query and fragment dropped' => ['https://example.org/a/b?c=d#e', 'https://example.org'];
        yield 'port kept' => ['http://example.org:8080/a', 'http://example.org:8080'];
        yield 'bare host' => ['https://example.org', 'https://example.org'];
        yield 'site-relative' => ['/a/b', null];
        yield 'protocol-relative' => ['//example.org/a', null];
        yield 'scheme without a host' => ['mailto:someone@example.org', null];
        yield 'unparseable' => ['http:///example.org', null];
    }

    #[DataProvider('urls')]
    public function testOfIsTheSchemeHostAndPort(string $url, ?string $expected): void
    {
        self::assertSame($expected, UrlOrigin::of($url));
    }

    public function testFromPartsAddsThePortOnlyWhenThereIsOne(): void
    {
        self::assertSame('https://example.org', UrlOrigin::fromParts('https', 'example.org', null));
        self::assertSame('https://example.org:8443', UrlOrigin::fromParts('https', 'example.org', 8443));
    }
}
```

`tests/Service/Fetch/UrlResolverTest.php` (a pin: `resolve()` keeps its behaviour through the extraction):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlResolverTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function references(): iterable
    {
        yield 'absolute stays' => ['https://example.org/a/b', 'https://other.example/x', 'https://other.example/x'];
        yield 'protocol-relative' => ['https://example.org/a/b', '//cdn.example/x', 'https://cdn.example/x'];
        yield 'site-relative keeps the port' => ['http://example.org:8080/a/b', '/x', 'http://example.org:8080/x'];
        yield 'path-relative keeps the directory' => ['https://example.org/a/b', 'x', 'https://example.org/a/x'];
    }

    #[DataProvider('references')]
    public function testResolvesAReferenceAgainstItsBase(string $base, string $reference, string $expected): void
    {
        self::assertSame($expected, UrlResolver::resolve($base, $reference));
    }

    public function testABaseWithoutAHostCannotResolveARelativeReference(): void
    {
        $this->expectException(FeedUnreachableException::class);

        UrlResolver::resolve('/a/b', 'x');
    }
}
```

- [ ] **Step 3: Run them**

Run: `php bin/phpunit tests/Service/Url/UrlOriginTest.php tests/Service/Fetch/UrlResolverTest.php`
Expected: `UrlResolverTest` PASS (pin: 5 tests, the four data rows and the exception test); `UrlOriginTest` errors with `Class "App\Service\Url\UrlOrigin" not found`.

- [ ] **Step 4: Add `UrlOrigin`**

`src/Service/Url/UrlOrigin.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Url;

/** The one definition of a URL's origin, so no caller re-assembles one and forgets the port. */
final class UrlOrigin
{
    public static function of(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return self::fromParts($parts['scheme'], $parts['host'], $parts['port'] ?? null);
    }

    public static function fromParts(string $scheme, string $host, ?int $port): string
    {
        return $scheme . '://' . $host . (null === $port ? '' : ':' . $port);
    }
}
```

- [ ] **Step 5: `UrlResolver` keeps only `resolve()`**

`src/Service/Fetch/UrlResolver.php` (rewritten in full; the class docblock and `resolve()` are unchanged except the one origin line):
```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Url\AbsoluteHttpUrl;
use App\Service\Url\UrlOrigin;

/**
 * Resolves a reference against the URL it was found in — a Location header
 * against the URL that produced it, a page's own links against the page.
 */
final class UrlResolver
{
    public static function resolve(string $baseUrl, string $location): string
    {
        if (AbsoluteHttpUrl::matches($location)) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new FeedUnreachableException(sprintf('Cannot resolve redirect target "%s"', $location));
        }

        $origin = UrlOrigin::fromParts($parts['scheme'], $parts['host'], $parts['port'] ?? null);

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($directory === '' ? '/' : $directory) . $location;
    }
}
```

- [ ] **Step 6: The two callers**

`src/Service/Fetch/PageUrls.php`:
```diff
 use App\Service\Url\AbsoluteHttpUrl;
+use App\Service\Url\UrlOrigin;
```
```diff
     public function origin(): ?string
     {
-        return UrlResolver::origin($this->pageUrl);
+        return UrlOrigin::of($this->pageUrl);
     }
```
`resolve()` in the same file keeps calling `UrlResolver::resolve()`, and the class docblock ("UrlResolver stays the algorithm…") stays true.

`src/Service/Url/FeedWebsite.php`:
```diff
 namespace App\Service\Url;
 
-use App\Service\Fetch\UrlResolver;
-
 /**
  * Where to send a reader who asks to visit a feed's website.
```
```diff
-        $origin = UrlResolver::origin($feedUrl);
+        $origin = UrlOrigin::of($feedUrl);
```

- [ ] **Step 7: Run the tests**

```bash
php bin/phpunit tests/Service/Url tests/Service/Fetch tests/Service/Discovery tests/Service/Scraper tests/Service/Reader/Media/Teaser/TeaserPlayerScannerTest.php tests/Service/Image tests/Http/SubscriptionJsonTest.php tests/Http/SubscriptionJsonListTest.php
git grep -n 'App\\Service\\Fetch' -- src/Service/Url
git grep -n 'UrlResolver::origin' -- src tests
```
Expected: PASS (`FeedWebsiteTest`, `PageUrlsTest`, `RedirectFollowerTest`, `ResponseClassifierTest`, the image fetcher tests and the subscription JSON tests unchanged); both greps print nothing.

- [ ] **Step 8: Deletion checks**

One edit at a time, made and restored with the Edit tool; after each, run the named test file. Every test and every data row has at least one check below, and each null-expecting row is also failed by a check that makes `of()` return something else (check 4).

`UrlOriginTest` (run `php bin/phpunit tests/Service/Url/UrlOriginTest.php`):
1. In `UrlOrigin::fromParts()`, replace `(null === $port ? '' : ':' . $port)` with `''`. Expected: FAIL on `port kept` and on `testFromPartsAddsThePortOnlyWhenThereIsOne`, each with `Failed asserting that two strings are identical.` and the port missing from the actual value (`'http://example.org'`, `'https://example.org'`).
2. In `UrlOrigin::fromParts()`, replace `(null === $port ? '' : ':' . $port)` with `':' . $port`. Expected: FAIL on `path, query and fragment dropped`, `bare host` and the first assertion of `testFromPartsAddsThePortOnlyWhenThereIsOne`, each with `Failed asserting that two strings are identical.` and a trailing colon in the actual value (`'https://example.org:'`).
3. In `UrlOrigin::of()`, replace `return self::fromParts($parts['scheme'], $parts['host'], $parts['port'] ?? null);` with `return null;`. Expected: FAIL on `path, query and fragment dropped`, `port kept` and `bare host`, with `Failed asserting that null is identical to 'https://example.org'.` (and `'http://example.org:8080'`).
4. In `UrlOrigin::of()`, replace `return null;` with `return '';`. Expected: FAIL on `site-relative`, `protocol-relative`, `scheme without a host` and `unparseable`, each with `Failed asserting that '' is identical to null.`
5. In `UrlOrigin::of()`, replace `!isset($parts['scheme'], $parts['host'])` with `!isset($parts['scheme'])`. Expected: ERROR on `scheme without a host` only, `TypeError: App\Service\Url\UrlOrigin::fromParts(): Argument #2 ($host) must be of type string, null given`.
6. In `UrlOrigin::of()`, replace `!isset($parts['scheme'], $parts['host'])` with `!isset($parts['host'])`. Expected: ERROR on `protocol-relative` only, `TypeError: App\Service\Url\UrlOrigin::fromParts(): Argument #1 ($scheme) must be of type string, null given`.

`UrlResolverTest` (run `php bin/phpunit tests/Service/Fetch/UrlResolverTest.php`):
7. In `UrlResolver::resolve()`, replace `UrlOrigin::fromParts($parts['scheme'], $parts['host'], $parts['port'] ?? null)` with `UrlOrigin::fromParts($parts['scheme'], $parts['host'], null)`. Expected: FAIL on `site-relative keeps the port` only, `Failed asserting that two strings are identical.`, actual `'http://example.org/x'`.
8. In `UrlResolver::resolve()`, delete the three lines from `if (AbsoluteHttpUrl::matches($location)) {` to its closing `}`. Expected: FAIL on `absolute stays` only, actual `'https://example.org/a/https://other.example/x'`.
9. In `UrlResolver::resolve()`, delete the three lines from `if (str_starts_with($location, '//')) {` to its closing `}`. Expected: FAIL on `protocol-relative` only, actual `'https://example.org//cdn.example/x'`.
10. In `UrlResolver::resolve()`, replace `$directory = substr($path, 0, (int) strrpos($path, '/') + 1);` with `$directory = '';`. Expected: FAIL on `path-relative keeps the directory` only, actual `'https://example.org/x'`.
11. In `UrlResolver::resolve()`, replace `throw new FeedUnreachableException(` with `throw new \RuntimeException(`. Expected: FAIL on `testABaseWithoutAHostCannotResolveARelativeReference` only, `Failed asserting that exception of type "RuntimeException" matches expected exception "App\Service\Fetch\Exception\FeedUnreachableException".`

Paste the eleven failing outputs into the task report, then run both files once more. Expected: PASS.

- [ ] **Step 9: Gates**

```bash
composer check
composer md
composer infection -- --filter=src/Service/Url/UrlOrigin.php,src/Service/Fetch/UrlResolver.php
```
Expected: clean, and every `UrlOrigin` mutant killed, with one possible exception: `Coalesce` on `$parts['port'] ?? null` (in `UrlOrigin::of()` and in `resolve()`). Swapping the operands (`null ?? $parts['port']`) returns the same value; its only effect is an undefined-key warning on a URL without a port, and the suite's `failOnWarning` may or may not count that as a kill. The same mutant existed on the old `UrlResolver` lines. If it escapes, report it to the planner as equivalent; do not add an ignore. Then PhpStorm `lint_files` on the six files.

- [ ] **Step 10: Commit**

```bash
git add src/Service/Url/UrlOrigin.php src/Service/Fetch/UrlResolver.php src/Service/Fetch/PageUrls.php src/Service/Url/FeedWebsite.php tests/Service/Url/UrlOriginTest.php tests/Service/Fetch/UrlResolverTest.php
git commit -m "refactor(#1161): a url's origin moves from Fetch's UrlResolver to Url\\UrlOrigin"
```

---

### Task A4: `FeedScheduler` and `OrphanedFeedReclaimer` move to `Service/Feed`

A pure move out of the `Service` root (D6). `Refresh`, `Subscription` and `Account` depend on `Feed`; `Feed` depends on `Fetch` (`HostThrottle`) only.

**Files:**
- Create (not committed): `var/refactor-1161/feed-lifecycle-map.php`
- Move: `src/Service/{FeedScheduler,OrphanedFeedReclaimer}.php` → `src/Service/Feed/`
- Move: `tests/Service/{FeedSchedulerTest,OrphanedFeedReclaimerTest}.php` → `tests/Service/Feed/`
- Modify (by the script, imports only): every file that names either class by FQCN, at `e9953e96`: `src/Service/Account/AccountDeleter.php` (line 12), `src/Service/Refresh/FeedOutcomePersister.php` (line 10), `src/Service/Refresh/RefreshHousekeeping.php` (line 7), `src/Service/Subscription/FirstFetchRecorder.php` (line 9), `src/Service/Subscription/SubscriptionService.php` (line 13), `tests/Service/Refresh/RefreshHousekeepingTest.php` (line 11), `tests/Service/Subscription/SubscriptionServiceTest.php` (line 21), `tests/Service/Subscription/UnsubscribeAllTest.php` (line 14), `tests/Support/FeedSchedulers.php` (line 7) and `tests/Support/RefreshRunners.php` (line 14). `FeedOutcomePersisterTest`, `FirstFetchRecorderTest`, the three `RefreshRunner*Test`s and `MaintenanceTickTest` get the scheduler through `FeedSchedulers` or `RefreshRunners` and name neither class, so the script leaves them alone.

**Interfaces:**
- Produces: `App\Service\Feed\FeedScheduler` (`recordSuccess`, `recordThrottled`, `recordFailure`, `recordGone`, …) and `App\Service\Feed\OrphanedFeedReclaimer` (`reclaim(int $feedId): bool`, `reclaimAll(): int`), code unchanged. `src/Service` holds no `.php` file directly.

- [ ] **Step 1: Verify the anchors**

```bash
find src/Service -maxdepth 1 -name '*.php'
find tests/Service -maxdepth 1 -name '*.php'
git grep -l -w -E 'App\\Service\\(FeedScheduler|OrphanedFeedReclaimer)' -- src tests config
ls src/Service/Feed tests/Service/Feed 2>&1
```
Expected:
- `src/Service/FeedScheduler.php` and `src/Service/OrphanedFeedReclaimer.php`.
- `tests/Service/FeedSchedulerTest.php`, `tests/Service/FillMissingImagesTest.php` (stays; see "Not in scope") and `tests/Service/OrphanedFeedReclaimerTest.php`.
- Twelve files: the ten the "Files" list names under "Modify", plus `tests/Service/FeedSchedulerTest.php` and `tests/Service/OrphanedFeedReclaimerTest.php` (they move).
- Both directories do not exist.

If a root file other than the two exists, stop and report.

- [ ] **Step 2: Write the map**

`var/refactor-1161/feed-lifecycle-map.php`:
```php
<?php

declare(strict_types=1);

$moves = [];
foreach (['FeedScheduler', 'OrphanedFeedReclaimer'] as $class) {
    $moves['App\\Service\\' . $class] = 'App\\Service\\Feed\\' . $class;
    $moves['App\\Tests\\Service\\' . $class . 'Test'] = 'App\\Tests\\Service\\Feed\\' . $class . 'Test';
}

return $moves;
```

- [ ] **Step 3: Run it**

```bash
git status --short
php var/refactor-1161/move-classes.php var/refactor-1161/feed-lifecycle-map.php
```
Expected: a clean tree first; then 4 `git mv` runs and `Moved 4 classes; added 0 imports in 0 files.` (neither class names the other, and nothing in the root namespace names them bare).

- [ ] **Step 4: Verify the move is complete and code-identical**

```bash
git grep -n -w -E 'App\\Service\\(FeedScheduler|OrphanedFeedReclaimer)' -- src tests config
find src/Service -maxdepth 1 -name '*.php'
find tests/Service -maxdepth 1 -name '*.php'
git grep -n -E '^use App\\Service\\' -- src/Service/Feed
strip() { grep -vE '^[[:space:]]*(\*|/\*\*|//|namespace |use App\\|$)'; }
for pair in \
  src/Service/FeedScheduler.php=src/Service/Feed/FeedScheduler.php \
  src/Service/OrphanedFeedReclaimer.php=src/Service/Feed/OrphanedFeedReclaimer.php \
  tests/Service/FeedSchedulerTest.php=tests/Service/Feed/FeedSchedulerTest.php \
  tests/Service/OrphanedFeedReclaimerTest.php=tests/Service/Feed/OrphanedFeedReclaimerTest.php; do
  diff <(git show "HEAD:backend/${pair%%=*}" | strip) <(strip < "${pair##*=}") || echo "CODE CHANGED: ${pair##*=}"
done
git diff -U0 HEAD -- src tests config | grep -E '^[+-]' | grep -vE '^(\+\+\+|---) ' \
  | grep -vE '^[+-]$|^[+-](use |namespace )|FeedScheduler|OrphanedFeedReclaimer'
```
Expected:
- The first grep prints nothing.
- No `.php` file directly under `src/Service`; only `tests/Service/FillMissingImagesTest.php` directly under `tests/Service`.
- One line: `src/Service/Feed/FeedScheduler.php:…:use App\Service\Fetch\HostThrottle;`.
- The comparison loop and the diff filter print nothing.

- [ ] **Step 5: Gates**

```bash
bin/console cache:clear && bin/console cache:warmup
bin/console lint:container
php bin/phpunit tests/Service/Feed tests/Service/Refresh tests/Service/Subscription tests/Service/Account tests/Service/Maintenance tests/Service/Worker/RefreshDueFeedsHandlerTest.php tests/Command/RefreshFeedsCommandTest.php
composer check
composer md
```
Expected: the container is valid; PASS; clean. Then PhpStorm `lint_files` on every renamed or modified file.

- [ ] **Step 6: Commit**

```bash
git add -A src tests config
git status --short | grep -v '^[RM] ' || true
git commit -m "refactor(#1161): the feed scheduler and the orphaned-feed reclaimer move into Service/Feed"
```
Expected: the `grep -v` prints nothing.

---

### Finishing PR A

- [ ] **Step 1: The module graph has no cycle**

```bash
git grep -E '^use App\\Service\\' -- src/Service \
  | sed -E 's#^(origin/develop:)?(backend/)?##; s#^src/Service/([^/:]+)(/[^:]*)?:use App\\Service\\([A-Za-z0-9_]+).*#\1 \3#; s#^([A-Za-z0-9_]+)\.php #\1 #' \
  | awk '$1 != $2' | sort -u > var/refactor-1161/module-edges-after.txt
wc -l < var/refactor-1161/module-edges-after.txt
tsort var/refactor-1161/module-edges-after.txt > /dev/null 2> var/refactor-1161/cycles-after.txt
cat var/refactor-1161/cycles-after.txt
grep -E '^(Image Catalog|Mail Catalog|Recommendation Worker|Url Fetch)$' var/refactor-1161/module-edges-after.txt
grep -E '^(Feed|Refresh|Subscription|Account) Feed$|^Feed ' var/refactor-1161/module-edges-after.txt
grep -E '^(Catalog Image|Image Fetch|Mail Image|Worker Recommendation)$' var/refactor-1161/module-edges-after.txt
```
Expected:
- `115` (118 at `e9953e96`, less ten edges, plus seven; see "Module graph at e9953e96"). A different count on a develop that moved since is fine if the rest holds.
- `cat` prints nothing: `tsort` found no cycle.
- The first `grep` prints nothing: the four closing edges are gone.
- The second `grep` prints `Account Feed`, `Feed Fetch`, `Refresh Feed` and `Subscription Feed`.
- The third prints all four lines: the positive control, proving the edge file was built from the real tree and the first `grep`'s silence is not an empty file's.

- [ ] **Step 2: The gates on the whole branch**

```bash
composer check
composer md
php bin/phpunit
docker compose exec php bin/console cache:clear
docker compose exec php composer test
composer infection:diff
```
Expected: all green.
- Before the MySQL leg, check that the containers are current (memory "Check the container is current"). The `cache:clear` is required: service ids changed, and the dev container would otherwise serve a compiled container that names the old classes.
- Restart the dev worker (`docker compose restart worker`): the daemon holds the old `App\Service\Worker\WorkerPresence`.
- `infection:diff` mutates `UrlOrigin`, the touched `UrlResolver` line and the two touched caller lines; the renamed files are not in its `AM` filter. An escaped mutant is handled as Task A3 Step 9 says.
- Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no deprecation or error from a file this PR touched.

- [ ] **Step 3: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'`. ERROR and WARNING block.

- [ ] **Step 4: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`. It runs four angle reviewers (reuse, simplification, efficiency, altitude) as parallel agents. Apply only fixes that keep every gate green and undo no decision in this plan (D1–D7, D-P1–D-P4). In particular, a moved file's code and comments stay as they are (D7), and A1b's names stay. Re-run Step 2's gates if anything changed, and commit as `refactor(#1161): simplify pass`.

- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Pure moves.** For A1, A2 and A4, the comment-stripped comparison loops print nothing, and every other changed line is an import, a namespace or a rewritten FQCN. Do not rely on `git diff -M --diff-filter=R` alone. For A1b, `git show -M -U0 "$(git log --format=%h -1 --grep='drops its Catalog prefix')"` changes nothing but `CatalogFaviconFetcher` to `FaviconFetcher`.
2. **No stale name.** `git grep -n -E 'Service\\Catalog\\(CatalogFaviconFetcher|FetchedFavicon|Exception\\Favicon)|Service\\Worker\\(WorkerPresence|SweepStreamHeartbeat|RecommendationDriverKind)|App\\Service\\(FeedScheduler|OrphanedFeedReclaimer)[^A-Za-z]|CatalogFaviconFetcher' -- src tests config` prints nothing, and `git grep -c -w -E 'FaviconFetcher(Interface)?' -- src` is non-zero. Living docs name these classes only by short name.
3. **`UrlOrigin`.** `of()` returns exactly what `UrlResolver::origin()` returned for every input (compare the two bodies), and `resolve()` is unchanged apart from the one origin line.
4. **Container.** `CompositeCompletionStreamHeartbeat`'s `$workerLiveness` points at the moved `SweepStreamHeartbeat`. The test-public overrides moved with their classes. `CompletionStreamHeartbeatWiringTest` passes. `lint:container` is clean.
5. **Graph.** Step 1's check prints no cycle, and `src/Service` holds no loose `.php` file.
6. **Nothing serialised.** No moved class is a messenger message or a cached value, so no queued or cached payload names an old class.
7. **No closing keyword** for #1161 in any commit message on this branch.

Fix each finding it rates Important or above in its own commit (`refactor(#1161): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR**

```bash
git push -u origin refactor/1161-service-module-cycles
git log origin/develop..HEAD --format=%B | grep -inE '\b(close|fix|resolve)[sd]?\b|\b(closed|fixed|fixes|resolves|resolved|closes)\b' && echo 'STOP: closing keyword in a commit' || true
gh pr create --base develop --title "refactor(#1161): the Service modules lose their dependency cycles" --body "$(cat <<'BODY'
Refs #1161 (PR A of two).

- The favicon fetcher (`CatalogFaviconFetcher`, its interface, `FetchedFavicon` and the two `Favicon*Exception`s) moves from `Service/Catalog` to `Service/Image`. `Image → Catalog` was the only edge back into `Catalog` in a nine-module cycle, and the digest embedder no longer reaches into `Catalog` for an image download. A separate commit then renames the fetcher and its interface to `FaviconFetcher` and `FaviconFetcherInterface`: in `Image` the `Catalog` prefix only misled.
- `WorkerPresence`, `SweepStreamHeartbeat` and `RecommendationDriverKind` move from `Service/Worker` to `Service/Recommendation/Run`. They describe who drives recommendation runs, and `Worker` now depends on `Recommendation` with nothing pointing back.
- A URL's origin moves from `Fetch\UrlResolver` to the new `Url\UrlOrigin`, which removes `Url → Fetch`. `UrlResolver::resolve()` stays in `Fetch`, and it now has its own test.
- `FeedScheduler` and `OrphanedFeedReclaimer` leave the `Service` root for a new `Service/Feed` module.

Three of the four are scripted moves with every code line unchanged (checked by a comment-stripped comparison), and the rename changes nothing but the name. No behaviour change and no wire change. PR B adds the PHPStan rule that keeps the module graph acyclic.
BODY
)"
gh pr view --json body --jq .body | grep -inE '\b(close|fix|resolve)[sd]?\b|\b(closed|fixed|fixes|resolves|resolved|closes)\b' && echo 'STOP: closing keyword in the body' || true
```
Expected: neither `STOP` line prints.

- [ ] **Step 7: Merge when green**

Watch the checks with the Monitor tool, as one command with no loop: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`: it merges immediately. On a failure:
- read the failing job (`gh run view --log-failed`),
- if only the tramp step fails, run `composer show larspohlmann/phptramp` first: CI runs phptramp's `develop` tip,
- fix on the branch, push, and watch again.

- [ ] **Step 8: Verify the issue stayed open**

Run: `gh issue view 1161 --json state --jq .state`. Expected: `OPEN`. If it closed, reopen it and report: a closing keyword slipped in.

---

# PR B — The rules that keep the graph acyclic

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR A merged and cut the branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --head refactor/1161-service-module-cycles --json number,mergedAt
gh issue view 1161 --json state --jq .state
git switch -c refactor/1161-service-module-rules origin/develop
```
Expected: one merged PR, the issue `OPEN`, the new branch checked out.

- [ ] **Step 2: Confirm the starting state (from `backend/`)**

```bash
find src/Service -maxdepth 1 -name '*.php'
git grep -E '^use App\\Service\\' -- src/Service \
  | sed -E 's#^(origin/develop:)?(backend/)?##; s#^src/Service/([^/:]+)(/[^:]*)?:use App\\Service\\([A-Za-z0-9_]+).*#\1 \3#; s#^([A-Za-z0-9_]+)\.php #\1 #' \
  | awk '$1 != $2' | sort -u > var/refactor-1161/module-edges-b0.txt
tsort var/refactor-1161/module-edges-b0.txt > /dev/null 2> var/refactor-1161/cycles-b0.txt
cat var/refactor-1161/cycles-b0.txt
git grep -n 'App\\Service\\Search' -- src/Service/Reader
git grep -n 'App\\Service\\Reader' -- src/Service/Recommendation
git grep -n -E 'public function (namespacesIn|forbiddenIn)|public static function isInAnyOf' -- tests/PhpStan/ClassNameReferences.php
git grep -n 'public function __construct' -- tests/PhpStan/ForbiddenReference.php
git grep -n -E 'phpstan\.collector|CollectedDataNode' -- tests phpstan.dist.neon
tail -n 4 phpstan.dist.neon
vendor/bin/phpstan --version
grep -n '^## ' ../docs/architecture.md | tail -n 2
```
Expected:
- No loose root file, and `cat` prints nothing: develop has no module cycle.
- The `Reader → Search` and `Recommendation → Reader` greps print nothing (#1163 removed both). If either prints, stop and report: B2's rule would fail on develop.
- `ClassNameReferences` has `public function namespacesIn(FileNode $file): array`, `public function forbiddenIn(Namespace_ $namespace): array` and `public static function isInAnyOf(string $name, array $namespaces): bool`. `ForbiddenReference` has `public function __construct(public string $name, public int $line, public string $matchedRule)`.
- No collector registered yet. `phpstan.dist.neon` ends with the `ControllerMutatesNoEntityThroughStaticCallableRule` service and its `phpstan.rules.rule` tag.
- PHPStan `2.x`.
- The last two sections are `## 7. Where database access lives` and `## 8. Where shared values live`. If #1163 or #1166 added a `## 9.`, stop and report: B3's section number must change.

---

### Task B1: `ServiceModuleCycleRule`

**Files:**
- Create: `tests/PhpStan/ServiceModuleDependencyCollector.php`, `tests/PhpStan/ServiceModuleGraph.php`, `tests/PhpStan/ServiceModuleCycle.php`, `tests/PhpStan/ServiceModuleCycleRule.php`
- Create: `tests/PhpStan/ServiceModuleCycleRuleTest.php`
- Create: `tests/PhpStan/data/service-module-cycle/{two-module-cycle,three-module-cycle,acyclic,LooseService}.php`
- Modify: `phpstan.dist.neon` (two services)

**Interfaces:**
- Produces:
  - `App\Tests\PhpStan\ServiceModuleDependencyCollector implements Collector<FileNode, list<array{string, string, int}>>`: `__construct(NodeFinder $finder)`; `processNode()` returns `[module, other module, line]` for every `App\Service` name a file in `App\Service` mentions, or `null` when there is none.
  - `App\Tests\PhpStan\ServiceModuleGraph::fromCollected(array<string, list<list<array{string, string, int}>>>): self`, `->cycles(): list<ServiceModuleCycle>`.
  - `App\Tests\PhpStan\ServiceModuleCycle(list<string> $modules, string $closedInFile, int $closedOnLine)`.
  - `App\Tests\PhpStan\ServiceModuleCycleRule implements Rule<CollectedDataNode>`, identifier `simpleFeedReader.serviceModuleCycle`, message `Service modules must not depend on each other in a cycle: <A -> B -> A>. Move the class that closes it into the module that owns it, or let the lower module own an interface the higher one implements (docs/architecture.md §9).`

- [ ] **Step 1: Write the fixtures**

`tests/PhpStan/data/service-module-cycle/two-module-cycle.php` (`Alpha → Beta` at 11 and 15; `Beta → Alpha` at 26, which closes the cycle; line 10 is inside `Alpha`, line 9 outside `App\Service`):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    use App\Entity\Feed;
    use App\Service\Alpha\Parts\AlphaPart;
    use App\Service\Beta\BetaThing;

    final class AlphaThing
    {
        public function __construct(public BetaThing $beta, public AlphaPart $part, public Feed $feed)
        {
        }
    }
}

namespace App\Service\Beta {
    final class BetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}
```

`tests/PhpStan/data/service-module-cycle/three-module-cycle.php` (`Alpha → Beta` 9, `Alpha → Epsilon` 10, `Beta → Gamma` 21, `Gamma → Alpha` 36 through a string, which closes the cycle; `Delta → Alpha` 42 depends on the cycle without being on it):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    use App\Service\Beta\BetaThing;
    use App\Service\Epsilon\EpsilonThing;

    final class AlphaThing
    {
        public function __construct(public BetaThing $beta, public EpsilonThing $epsilon)
        {
        }
    }
}

namespace App\Service\Beta {
    use App\Service\Gamma\GammaThing;

    final class BetaThing
    {
        public function __construct(public GammaThing $gamma)
        {
        }
    }
}

namespace App\Service\Gamma {
    final class GammaThing
    {
        public function alpha(): string
        {
            return 'App\Service\Alpha\AlphaThing';
        }
    }
}

namespace App\Service\Delta {
    use App\Service\Alpha\AlphaThing;

    final class DependsOnTheCycle
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}

namespace App\Service\Epsilon {
    final class EpsilonThing
    {
    }
}
```

`tests/PhpStan/data/service-module-cycle/acyclic.php` (a diamond `Alpha → Beta → Gamma`, `Alpha → Gamma`; `Alpha → Alpha` at 9 is inside one module; `App\Command\Gamma` names `Alpha` at 39 and 43, which is no Service edge, because only a namespace under `App\Service` belongs to a module. `App\Command\` is exactly as long as `App\Service\`, so if the collector stopped checking the namespace, `moduleOf()` would read the command as `Gamma` and close `Alpha → Gamma → Alpha`; B1 Step 7's check 7 relies on that):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    use App\Service\Alpha\Parts\AlphaPart;
    use App\Service\Beta\BetaThing;
    use App\Service\Gamma\GammaThing;

    final class AlphaThing
    {
        public function __construct(public AlphaPart $part, public BetaThing $beta, public GammaThing $gamma)
        {
        }
    }
}

namespace App\Service\Beta {
    use App\Service\Gamma\GammaThing;

    final class BetaThing
    {
        public function __construct(public GammaThing $gamma)
        {
        }
    }
}

namespace App\Service\Gamma {
    final class GammaThing
    {
    }
}

namespace App\Command\Gamma {
    use App\Service\Alpha\AlphaThing;

    final class GammaCommand
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}
```

`tests/PhpStan/data/service-module-cycle/LooseService.php` (named after its root class, as PSR-4 names a real one: `LooseService → Alpha` at 9 closes the cycle, `Alpha → LooseService` at 20):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service {
    use App\Service\Alpha\AlphaThing;

    final class LooseService
    {
        public function __construct(public AlphaThing $alpha)
        {
        }
    }
}

namespace App\Service\Alpha {
    use App\Service\LooseService;

    final class AlphaThing
    {
        public function __construct(public LooseService $loose)
        {
        }
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/PhpStan/ServiceModuleCycleRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ServiceModuleCycleRule> */
final class ServiceModuleCycleRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ServiceModuleCycleRule();
    }

    protected function getCollectors(): array
    {
        return [new ServiceModuleDependencyCollector(new NodeFinder())];
    }

    public function testATwoModuleCycleIsReportedOnceWhereItCloses(): void
    {
        $this->analyse([self::fixture('two-module-cycle')], [[self::message('Alpha -> Beta -> Alpha'), 26]]);
    }

    public function testAThreeModuleCycleNamesEveryModuleOnItsPath(): void
    {
        $this->analyse(
            [self::fixture('three-module-cycle')],
            [[self::message('Alpha -> Beta -> Gamma -> Alpha'), 36]],
        );
    }

    public function testAnAcyclicGraphIsClean(): void
    {
        $this->analyse([self::fixture('acyclic')], []);
    }

    public function testAClassLooseInTheServiceRootIsAModuleOfItsOwn(): void
    {
        $this->analyse([self::fixture('LooseService')], [[self::message('Alpha -> LooseService -> Alpha'), 9]]);
    }

    private static function fixture(string $name): string
    {
        return __DIR__ . '/data/service-module-cycle/' . $name . '.php';
    }

    private static function message(string $cycle): string
    {
        return sprintf(
            'Service modules must not depend on each other in a cycle: %s. '
            . 'Move the class that closes it into the module that owns it, '
            . 'or let the lower module own an interface the higher one implements (docs/architecture.md §9).',
            $cycle,
        );
    }
}
```

- [ ] **Step 3: Run it**

Run: `php bin/phpunit tests/PhpStan/ServiceModuleCycleRuleTest.php`
Expected: errors with `Class "App\Tests\PhpStan\ServiceModuleCycleRule" not found`.

- [ ] **Step 4: The collector**

`tests/PhpStan/ServiceModuleDependencyCollector.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * [its module, the module it names, line] for every App\Service name a Service file mentions. A module is the first
 * segment under App\Service; a class loose in the root is a module of its own, named by its file (PSR-4).
 *
 * @implements Collector<FileNode, list<array{string, string, int}>>
 */
final readonly class ServiceModuleDependencyCollector implements Collector
{
    private const string SERVICE_NAMESPACE = 'App\\Service\\';

    private ClassNameReferences $references;

    public function __construct(NodeFinder $finder)
    {
        $this->references = new ClassNameReferences($finder, [self::SERVICE_NAMESPACE]);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /** @return list<array{string, string, int}>|null */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $fileClassName = basename($scope->getFile(), '.php');
        $dependencies = [];
        foreach ($this->references->namespacesIn($node) as $namespace) {
            $dependencies = [...$dependencies, ...$this->dependenciesOf($namespace, $fileClassName)];
        }

        return [] === $dependencies ? null : $dependencies;
    }

    /** @return list<array{string, string, int}> */
    private function dependenciesOf(Namespace_ $namespace, string $fileClassName): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!ClassNameReferences::isInAnyOf($namespaceName, [self::SERVICE_NAMESPACE])) {
            return [];
        }

        $module = self::moduleOf($namespaceName . '\\' . $fileClassName);
        $dependencies = [];
        foreach ($this->references->forbiddenIn($namespace) as $reference) {
            $dependency = self::moduleOf($reference->name);
            if ('' !== $dependency && $dependency !== $module) {
                $dependencies[] = [$module, $dependency, $reference->line];
            }
        }

        return $dependencies;
    }

    private static function moduleOf(string $className): string
    {
        return explode('\\', substr($className, \strlen(self::SERVICE_NAMESPACE)))[0];
    }
}
```

`moduleOf()` returns `''` for the bare `App\Service` namespace (an alias import of the root, or an interpolated string that stops at the separator). That names no module, so the loop skips it.

- [ ] **Step 5: The graph, the cycle and the rule**

`tests/PhpStan/ServiceModuleCycle.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

final readonly class ServiceModuleCycle
{
    /** @param list<string> $modules round the cycle, the first module again at the end */
    public function __construct(public array $modules, public string $closedInFile, public int $closedOnLine)
    {
    }
}
```

`tests/PhpStan/ServiceModuleGraph.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

final readonly class ServiceModuleGraph
{
    /** @param array<string, array<string, array{file: string, line: int}>> $sites [module][dependency] => first site */
    private function __construct(private array $sites)
    {
    }

    /** @param array<string, list<list<array{string, string, int}>>> $collected file => the collector's findings */
    public static function fromCollected(array $collected): self
    {
        ksort($collected);
        $sites = [];
        foreach ($collected as $file => $findings) {
            foreach (array_merge(...$findings) as [$module, $dependency, $line]) {
                $sites[$module][$dependency] ??= ['file' => $file, 'line' => $line];
            }
        }

        return new self($sites);
    }

    /** @return list<ServiceModuleCycle> the shortest cycle through each module no earlier cycle passed through */
    public function cycles(): array
    {
        $modules = array_keys($this->sites);
        sort($modules);
        $cycles = [];
        $covered = [];
        foreach ($modules as $module) {
            if (isset($covered[$module])) {
                continue;
            }
            $cycle = $this->shortestCycleThrough($module);
            if (null !== $cycle) {
                $cycles[] = $cycle;
                $covered += array_fill_keys($cycle->modules, true);
            }
        }

        return $cycles;
    }

    private function shortestCycleThrough(string $start): ?ServiceModuleCycle
    {
        $cameFrom = [];
        $queue = [$start];
        for ($next = 0; $next < \count($queue); ++$next) {
            $module = $queue[$next];
            $dependencies = $this->dependenciesOf($module);
            if (\in_array($start, $dependencies, true)) {
                return $this->cycleClosedBy($module, $start, $cameFrom);
            }
            foreach (array_diff($dependencies, [$start, ...array_keys($cameFrom)]) as $unseen) {
                $cameFrom[$unseen] = $module;
                $queue[] = $unseen;
            }
        }

        return null;
    }

    /** @param array<string, string> $cameFrom */
    private function cycleClosedBy(string $last, string $start, array $cameFrom): ServiceModuleCycle
    {
        $site = $this->sites[$last][$start];

        return new ServiceModuleCycle(
            [...self::pathBack($last, $start, $cameFrom), $start],
            $site['file'],
            $site['line'],
        );
    }

    /**
     * @param array<string, string> $cameFrom
     *
     * @return list<string>
     */
    private static function pathBack(string $module, string $start, array $cameFrom): array
    {
        $path = [$module];
        while ($module !== $start) {
            $module = $cameFrom[$module];
            array_unshift($path, $module);
        }

        return $path;
    }

    /** @return list<string> */
    private function dependenciesOf(string $module): array
    {
        $dependencies = array_keys($this->sites[$module] ?? []);
        sort($dependencies);

        return $dependencies;
    }
}
```

`tests/PhpStan/ServiceModuleCycleRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Service modules depend on each other without a cycle (docs/architecture.md §9). Each cycle is reported once, where
 * its last module names its first, the first being the earliest module by name that lies on a cycle.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class ServiceModuleCycleRule implements Rule
{
    private const string MESSAGE = 'Service modules must not depend on each other in a cycle: %s. '
        . 'Move the class that closes it into the module that owns it, '
        . 'or let the lower module own an interface the higher one implements (docs/architecture.md §9).';

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $graph = ServiceModuleGraph::fromCollected($node->get(ServiceModuleDependencyCollector::class));

        return array_map(self::error(...), $graph->cycles());
    }

    private static function error(ServiceModuleCycle $cycle): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(self::MESSAGE, implode(' -> ', $cycle->modules)))
            ->identifier('simpleFeedReader.serviceModuleCycle')
            ->file($cycle->closedInFile)
            ->line($cycle->closedOnLine)
            ->build();
    }
}
```

- [ ] **Step 6: Run the test**

Run: `php bin/phpunit tests/PhpStan/ServiceModuleCycleRuleTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Deletion checks**

Each break is made with the Edit tool and restored with it. Run `php bin/phpunit tests/PhpStan/ServiceModuleCycleRuleTest.php` after each break. Every FAIL below is PHPStan's `RuleTestCase` comparison, `Failed asserting that two strings are identical.`, with a diff whose `-` lines are the expected `<line>: <message>` entries and whose `+` lines are the actual ones; the expected entries name what the diff must show.
1. **Two-module test.** In `ServiceModuleGraph::shortestCycleThrough()`, replace `if (\in_array($start, $dependencies, true)) {` with `if (false) {`. Expected: `testATwoModuleCycleIsReportedOnceWhereItCloses` FAILS with the expected `26: … Alpha -> Beta -> Alpha. …` entry and no actual error (the three-module and root tests fail the same way). Restore.
2. **Three-module test.** In `ServiceModuleGraph::pathBack()`, replace `while ($module !== $start) {` with `if ($module !== $start) {`. Expected: `testAThreeModuleCycleNamesEveryModuleOnItsPath` FAILS with `36: … Beta -> Gamma -> Alpha. …` in place of `36: … Alpha -> Beta -> Gamma -> Alpha. …`; the other three tests PASS (their paths are two modules long). Restore.
3. **Acyclic test, the same-module skip.** In `ServiceModuleDependencyCollector::dependenciesOf()`, replace `if ('' !== $dependency && $dependency !== $module) {` with `if ('' !== $dependency) {`. Expected: `testAnAcyclicGraphIsClean` FAILS with an actual `9: … Alpha -> Alpha. …` where none was expected. (The two-module test fails too, through its line-10 `AlphaPart`; the three-module and root tests PASS.) Restore.
4. **Root test.** In `ServiceModuleDependencyCollector::processNode()`, replace `$fileClassName = basename($scope->getFile(), '.php');` with `$fileClassName = '';`. Expected: only `testAClassLooseInTheServiceRootIsAModuleOfItsOwn` FAILS, with the expected `9: … Alpha -> LooseService -> Alpha. …` entry and no actual error (the root class's module becomes `''`, so nothing points back at it). Restore.
5. **Rotation dedup.** In `ServiceModuleGraph::cycles()`, delete the line `$covered += array_fill_keys($cycle->modules, true);`. Expected: the three-module test FAILS with two extra actual entries, `9: … Beta -> Gamma -> Alpha -> Beta. …` and `21: … Gamma -> Alpha -> Beta -> Gamma. …`; the two-module test gains `11: … Beta -> Alpha -> Beta. …` and the root test `20: … LooseService -> Alpha -> LooseService. …`. Restore.
6. **First site wins.** In `ServiceModuleGraph::fromCollected()`, replace `$sites[$module][$dependency] ??= ['file' => $file, 'line' => $line];` with `$sites[$module][$dependency] = ['file' => $file, 'line' => $line];`. Expected: only the root test FAILS, with the cycle reported at `13:` (the constructor's `AlphaThing`) instead of the expected `9:` (the import). Restore.
7. **Acyclic test, the namespace guard.** In `ServiceModuleDependencyCollector::dependenciesOf()`, delete the guard from `if (!ClassNameReferences::isInAnyOf($namespaceName, [self::SERVICE_NAMESPACE])) {` through its closing `}` and the blank line after it. Expected: only `testAnAcyclicGraphIsClean` FAILS, with an actual `39: … Alpha -> Gamma -> Alpha. …` (the `App\Command\Gamma` namespace read as module `Gamma`). Restore.

Paste the seven failing outputs into the task report, then run the test once more. Expected: PASS, 4 tests.

- [ ] **Step 8: Register the collector and the rule**

`phpstan.dist.neon`, append after the last service (`App\Tests\PhpStan\ControllerMutatesNoEntityThroughStaticCallableRule` and its tag):
```neon
    -
        class: App\Tests\PhpStan\ServiceModuleDependencyCollector
        tags:
            - phpstan.collector
    -
        class: App\Tests\PhpStan\ServiceModuleCycleRule
        tags:
            - phpstan.rules.rule
```

- [ ] **Step 9: The real tree is clean, and a real cycle is caught**

```bash
bin/console cache:warmup
composer stan
```
Expected: `[OK] No errors`. PR A left no cycle.

Then break it. In `src/Service/Recommendation/Run/ForYouSweep.php`, insert the line `use App\Service\Worker\WorkerRunSweep;` directly after `namespace App\Service\Recommendation\Run;` and its blank line, and run `composer stan`. Expected: exactly one error, `Service modules must not depend on each other in a cycle: Recommendation -> Worker -> Recommendation. …`, reported in `src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php` at its `use App\Service\Recommendation\Run\RecommendationDriverKind;` line (line 8: at `e9953e96` it is `use App\Service\Worker\RecommendationDriverKind;`, and A2's script rewrites it in place), because that is the first place, by path, where `Worker` names `Recommendation` (`Handler/AdvanceRecommendationRunsHandler.php` sorts before `Handler/StartDueRecommendationRunsHandler.php` and `WorkerRunSweep.php`). Delete the inserted line with the Edit tool, run `composer stan` again, and expect `[OK] No errors`. Paste both outputs into the task report.

- [ ] **Step 10: Gates**

```bash
composer check
php bin/phpunit tests/PhpStan
```
Expected: clean, and every PhpStan rule test PASSES. Then PhpStorm `lint_files` on the four new classes and the test (not the fixtures, which break rules on purpose).

- [ ] **Step 11: Commit**

```bash
git add tests/PhpStan/ServiceModuleDependencyCollector.php tests/PhpStan/ServiceModuleGraph.php tests/PhpStan/ServiceModuleCycle.php tests/PhpStan/ServiceModuleCycleRule.php tests/PhpStan/ServiceModuleCycleRuleTest.php tests/PhpStan/data/service-module-cycle phpstan.dist.neon
git commit -m "refactor(#1161): ServiceModuleCycleRule fails the build on a dependency cycle between service modules"
```

---

### Task B2: `ServiceModuleBoundaryRule`

Folded carry-forward (#1163 → #1169). `Reader → Search` and `Recommendation → Reader` close no cycle, so B1's rule would let them back in.

**Files:**
- Create: `tests/PhpStan/ServiceModuleBoundaryRule.php`, `tests/PhpStan/ServiceModuleBoundaryRuleTest.php`, `tests/PhpStan/data/service-module-boundary-fixtures.php`
- Modify: `phpstan.dist.neon` (one service)

**Interfaces:**
- Produces: `App\Tests\PhpStan\ServiceModuleBoundaryRule implements Rule<FileNode>`, `__construct(NodeFinder $finder)`, identifier `simpleFeedReader.serviceModuleBoundary`, message `Service module boundary: <namespace> references <name>. <reason>`.

- [ ] **Step 1: Write the fixture**

`tests/PhpStan/data/service-module-boundary-fixtures.php` (reported: 9 and 13 in `Reader`, 24 in `Recommendation`; not reported: `Reading` may use `Search` (30, 34), `ReaderAudit` is not `Reader` (41, 45), and `Recommendation` may name `ReaderAudit` (52, 56). each "not reported" pair has a Step 5 edit that makes it reported):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleBoundaryRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Reader\Fixtures {
    use App\Service\Search\SearchTerms;

    final class ReaderSearches
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\Recommendation\Run\Fixtures {
    final class RecommendationReads
    {
        public function extractor(): string
        {
            return \App\Service\Reader\ArticleExtractor::class;
        }
    }
}

namespace App\Service\Reading\Fixtures {
    use App\Service\Search\SearchTerms;

    final class ReadingMaySearch
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\ReaderAudit\Fixtures {
    use App\Service\Search\SearchTerms;

    final class TheAuditIsNotTheReader
    {
        public function __construct(public SearchTerms $terms)
        {
        }
    }
}

namespace App\Service\Recommendation\Feed\Fixtures {
    use App\Service\ReaderAudit\ReaderAuditRunner;

    final class RecommendationMayNameALookalike
    {
        public function __construct(public ReaderAuditRunner $runner)
        {
        }
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/PhpStan/ServiceModuleBoundaryRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ServiceModuleBoundaryRule> */
final class ServiceModuleBoundaryRuleTest extends RuleTestCase
{
    private const string READER = 'App\Service\Reader\Fixtures';
    private const string RECOMMENDATION = 'App\Service\Recommendation\Run\Fixtures';
    private const string SEARCH_TERMS = 'App\Service\Search\SearchTerms';
    private const string EXTRACTOR = 'App\Service\Reader\ArticleExtractor';
    private const string SEARCH_IN_READER_REMEDY
        = 'Reading state, and the search it needs, lives in Service/Reading (#1163).';
    private const string READER_IN_RECOMMENDATION_REMEDY
        = 'Mark-read goes through Service/Reading, not the article extractor (#1163).';

    protected function getRule(): Rule
    {
        return new ServiceModuleBoundaryRule(new NodeFinder());
    }

    public function testItReportsOnlyTheDependenciesRemovedOnPurpose(): void
    {
        $this->analyse(
            [__DIR__ . '/data/service-module-boundary-fixtures.php'],
            [
                [self::message(self::READER, self::SEARCH_TERMS, self::SEARCH_IN_READER_REMEDY), 9],
                [self::message(self::READER, self::SEARCH_TERMS, self::SEARCH_IN_READER_REMEDY), 13],
                [self::message(self::RECOMMENDATION, self::EXTRACTOR, self::READER_IN_RECOMMENDATION_REMEDY), 24],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference, string $remedy): string
    {
        return sprintf('Service module boundary: %s references %s. %s', $namespaceName, $reference, $remedy);
    }
}
```

Run: `php bin/phpunit tests/PhpStan/ServiceModuleBoundaryRuleTest.php`
Expected: errors with `Class "App\Tests\PhpStan\ServiceModuleBoundaryRule" not found`.

- [ ] **Step 3: The rule**

`tests/PhpStan/ServiceModuleBoundaryRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Module dependencies removed on purpose that close no cycle, so ServiceModuleCycleRule would let them back in
 * (docs/architecture.md §9).
 *
 * @implements Rule<FileNode>
 */
final readonly class ServiceModuleBoundaryRule implements Rule
{
    private const array REMEDIES = [
        'App\\Service\\Reader\\' => [
            'App\\Service\\Search\\' => 'Reading state, and the search it needs, lives in Service/Reading (#1163).',
        ],
        'App\\Service\\Recommendation\\' => [
            'App\\Service\\Reader\\' => 'Mark-read goes through Service/Reading, not the article extractor (#1163).',
        ],
    ];

    /** @var array<string, ClassNameReferences> */
    private array $referencesByModule;

    public function __construct(NodeFinder $finder)
    {
        $referencesByModule = [];
        foreach (self::REMEDIES as $module => $remedies) {
            $referencesByModule[$module] = new ClassNameReferences($finder, array_keys($remedies));
        }
        $this->referencesByModule = $referencesByModule;
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->referencesByModule as $module => $references) {
            foreach ($references->namespacesIn($node) as $namespace) {
                $errors = [...$errors, ...self::errorsIn($module, $namespace, $references)];
            }
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private static function errorsIn(string $module, Namespace_ $namespace, ClassNameReferences $references): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!ClassNameReferences::isInAnyOf($namespaceName, [$module])) {
            return [];
        }

        return array_map(
            static fn (ForbiddenReference $reference): IdentifierRuleError
                => self::error($module, $namespaceName, $reference),
            $references->forbiddenIn($namespace),
        );
    }

    private static function error(
        string $module,
        string $namespaceName,
        ForbiddenReference $reference,
    ): IdentifierRuleError {
        return RuleErrorBuilder::message(sprintf(
            'Service module boundary: %s references %s. %s',
            $namespaceName,
            $reference->name,
            self::REMEDIES[$module][$reference->matchedRule],
        ))
            ->identifier('simpleFeedReader.serviceModuleBoundary')
            ->line($reference->line)
            ->build();
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php bin/phpunit tests/PhpStan/ServiceModuleBoundaryRuleTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

Run `php bin/phpunit tests/PhpStan/ServiceModuleBoundaryRuleTest.php` after each break, then restore it with the Edit tool. Each FAIL is `RuleTestCase`'s `Failed asserting that two strings are identical.`, with the diff named below.
1. **The module guard.** In `errorsIn()`, delete the guard `if (!ClassNameReferences::isInAnyOf($namespaceName, [$module])) {` through its closing `}` and the blank line after it. Expected: FAIL, with four extra actual entries, `30:`, `34:`, `41:` and `45: Service module boundary: … references App\Service\Search\SearchTerms. …` (`Reading` and `ReaderAudit` checked as though they were `Reader`). Restore.
2. **The Recommendation entry.** In `REMEDIES`, delete the `'App\\Service\\Recommendation\\' => [ … ],` entry (three lines). Expected: FAIL, with the expected `24: Service module boundary: App\Service\Recommendation\Run\Fixtures references App\Service\Reader\ArticleExtractor. …` entry missing from the actual errors. Restore.
3. **The Reader entry.** In `REMEDIES`, delete the `'App\\Service\\Reader\\' => [ … ],` entry (three lines). Expected: FAIL, with the expected `9:` and `13: Service module boundary: App\Service\Reader\Fixtures references App\Service\Search\SearchTerms. …` entries missing; `24:` is still reported. Restore.
4. **A lookalike module.** In `REMEDIES`, replace the first key `'App\\Service\\Reader\\' => [` with `'App\\Service\\Reader' => [`. Expected: FAIL, with two extra actual entries, `41:` and `45: Service module boundary: App\Service\ReaderAudit\Fixtures references App\Service\Search\SearchTerms. …` (without the separator, `ReaderAudit` passes as `Reader`). Restore.
5. **A lookalike reference.** In `ClassNameReferences::matches()` (reused, not new, and restored before anything else runs), replace `return self::isInAnyOf($name, [$forbiddenName]);` with `return str_starts_with(strtolower($name), strtolower(rtrim($forbiddenName, '\\')));`. Expected: FAIL, with two extra actual entries, `52:` and `56: Service module boundary: App\Service\Recommendation\Feed\Fixtures references App\Service\ReaderAudit\ReaderAuditRunner. …`. Restore, then run `php bin/phpunit tests/PhpStan` and expect PASS.

Paste the five failing outputs into the task report.

- [ ] **Step 6: Register the rule, and prove it on the real tree**

`phpstan.dist.neon`, append after the `ServiceModuleCycleRule` service:
```neon
    -
        class: App\Tests\PhpStan\ServiceModuleBoundaryRule
        tags:
            - phpstan.rules.rule
```

```bash
composer stan
```
Expected: `[OK] No errors`.

Then insert the line `use App\Service\Search\SearchTerms;` directly after `namespace App\Service\Reader;` and its blank line in `src/Service/Reader/ArticleExtractorInterface.php`, and run `composer stan`. Expected: exactly one error, `Service module boundary: App\Service\Reader references App\Service\Search\SearchTerms. Reading state, and the search it needs, lives in Service/Reading (#1163).`, at the inserted line. Delete the line with the Edit tool, run `composer stan`, and expect `[OK] No errors`. Paste both outputs into the task report.

- [ ] **Step 7: Gates and commit**

```bash
composer check
php bin/phpunit tests/PhpStan
git add tests/PhpStan/ServiceModuleBoundaryRule.php tests/PhpStan/ServiceModuleBoundaryRuleTest.php tests/PhpStan/data/service-module-boundary-fixtures.php phpstan.dist.neon
git commit -m "refactor(#1161): ServiceModuleBoundaryRule keeps reader to search and recommendation to reader out"
```
Expected: clean and PASS before the commit. PhpStorm `lint_files` on the three new PHP files.

---

### Task B3: `docs/architecture.md` §9 and `CLAUDE.md`

**Files:**
- Modify: `docs/architecture.md` (a new §9 at the end)
- Modify: `CLAUDE.md` (two bullets)

- [ ] **Step 1: `docs/architecture.md` §9 (from the repository root)**

Append after the last paragraph of §8 (the one ending "`DomainKnowsNoHttpRule` (no `App\Http` or `App\Dto` in domain code), both in `backend/tests/PhpStan/` and run by `composer stan`."), with one blank line before it:
```markdown
## 9. Service modules form no cycle

A `Service/*` module is the first directory under `backend/src/Service`: `Recommendation` with its `Prompt`, `Run`,
`Feed` and `Settings` subdirectories is one module. Every service belongs to a module, and the modules depend on
each other without a cycle, so each one can be read, tested and moved without the others. Decided in #1161.

- **What both sides need lives on the lower side.** When a module needs something from a module that depends on it,
  the class moves to the module that owns the concept, or the lower module owns an interface the higher one
  implements (`Ai\Completion\CompletionStreamHeartbeat`, implemented in `Recommendation\Run`).
- **The cycles #1161 broke.** The favicon fetcher moved from `Catalog` to `Image` (now `FaviconFetcher`), ending a
  nine-module cycle through `Category`, `Discovery`, `Ingest`, `Opml`, `Parser`, `Scraper` and `Subscription`.
  The recommendation driver liveness (`WorkerPresence`, `SweepStreamHeartbeat`, `RecommendationDriverKind`) moved
  from `Worker` to `Recommendation\Run`. A URL's origin moved from `Fetch\UrlResolver` to `Url\UrlOrigin`.
  `FeedScheduler` and `OrphanedFeedReclaimer` left the `Service` root for `Service/Feed`. #1159 had already removed
  `Fetch ↔ Proxy` and `Grafana ↔ Profiling`.
- **Removed on purpose.** `Reader → Search` and `Recommendation → Reader` (#1163) closed no cycle, so the cycle rule
  would not stop them coming back; `ServiceModuleBoundaryRule` names them.

Enforced by `ServiceModuleCycleRule` and `ServiceModuleBoundaryRule`, both in `backend/tests/PhpStan/` and run by
`composer stan`. A collector records every `App\Service` name a module's code mentions (imports, class names and
strings, not comments), and the cycle rule reports each cycle once and names its path. A class loose in the `Service`
root counts as a module of its own.
```

- [ ] **Step 2: `CLAUDE.md` (from the repository root)**

After the "Shared values have one home." bullet, whose last line is `  `PersistenceKnowsNoServiceRule`).`, insert:
```markdown
- **Service modules form no cycle.** A module is the first directory under
  `src/Service`, and every service belongs to one. When two modules need each other,
  the class moves to the module that owns it, or the lower module owns an interface
  the higher one implements ([docs/architecture.md](docs/architecture.md) §9).
```

After the `EntityIdCoercionRule` bullet, whose last line is `  `$entity->getId() ?? …`.`, insert:
```markdown
- **`ServiceModuleCycleRule`** (`tests/PhpStan/ServiceModuleCycleRule.php`, fed by
  `ServiceModuleDependencyCollector`) — no dependency cycle between `Service/*`
  modules; the message names the cycle. **`ServiceModuleBoundaryRule`** keeps out
  the two dependencies #1163 removed (`Reader → Search`, `Recommendation → Reader`).
```

- [ ] **Step 3: Check and commit (from the repository root)**

```bash
grep -n '^## 9\. Service modules form no cycle$' docs/architecture.md
grep -c -E 'Service modules form no cycle|ServiceModuleCycleRule' CLAUDE.md
git add docs/architecture.md CLAUDE.md
git commit -m "refactor(#1161): architecture section 9 and CLAUDE.md describe the service module rules"
```
Expected: one match for the heading; `2` in `CLAUDE.md` (the line that opens each new bullet).

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
Expected: all green. PR B changes no `src` file, so `infection:diff` finds no mutation and passes (`--ignore-msi-with-no-mutations`). Before the MySQL leg, check that the containers are current.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'` except `tests/PhpStan/data/**` (the fixtures break rules on purpose). ERROR and WARNING block.

- [ ] **Step 3: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`, with its four angle reviewers (reuse, simplification, efficiency, altitude) run as parallel agents. Apply only fixes that keep every gate green and undo no decision in this plan (D1, D2, D8–D12, D-P3). Every fixture line number the tests assert must stay where it is. Re-run Step 1's gates if anything changed, and commit as `refactor(#1161): simplify pass`.

- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **What counts.** The collector records every `App\Service` name that a file in `App\Service` mentions (imports, group imports, code names, strings) and nothing from other namespaces. Module derivation: first segment; a root class by its file name; `''` skipped; same-module names skipped.
2. **Cycle detection.** Deterministic (files, modules and dependencies sorted); one error per cycle; the path is a real cycle in the graph; the report site is the first place, by file and line, where the path's last module names its first; no false positive on a diamond.
3. **Deletion checks.** Tasks B1 Step 7 and B2 Step 5 recorded a red run for each break, and Steps B1.9 and B2.6 recorded the real-tree breaks.
4. **Registration.** The collector carries `phpstan.collector` and both rules `phpstan.rules.rule`; `composer stan` runs them over `src` and `tests`, and the fixtures stay excluded.
5. **Docs.** §9 and the two `CLAUDE.md` bullets match what the rules do.
6. **Comment bar** on every new file (D12).

Fix each finding it rates Important or above in its own commit (`refactor(#1161): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin refactor/1161-service-module-rules
gh pr create --base develop --title "refactor(#1161): PHPStan rules keep the service module graph acyclic" --body "$(cat <<'BODY'
Closes #1161 (PR B of two; PR A broke the cycles).

- `ServiceModuleCycleRule`: `ServiceModuleDependencyCollector` records every `App\Service` name a service file mentions, and the rule reports each cycle between `Service/*` modules once, naming its path (`Alpha -> Beta -> Gamma -> Alpha`) at the line that closes it. Fixture tests cover a two-module cycle, a three-module cycle, an acyclic diamond and a class loose in the `Service` root, and each has a recorded deletion check.
- `ServiceModuleBoundaryRule` keeps out the two dependencies #1163 removed on purpose and that close no cycle: `Reader → Search` and `Recommendation → Reader`. This takes over two #1169 carry-forward items (a namespace-cycle rule and a namespace-dependency rule).
- `docs/architecture.md` §9 and `CLAUDE.md` describe the module rule and both checks.

No `src` change, no behaviour change, no wire change.
BODY
)"
```

- [ ] **Step 6: Merge when green**

Watch the checks with the Monitor tool, as one command with no loop: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`. On a failure, read the failing job (`gh run view --log-failed`); if only the tramp step fails, run `composer show larspohlmann/phptramp` first; fix on the branch, push, and watch again.

- [ ] **Step 7: Verify the issue closed**

Run: `gh issue view 1161 --json state --jq .state`. Expected: `CLOSED`. If it is still open, check that the PR body kept `Closes #1161` and report; do not close it by hand.
