# Split the Reader God Module: Reading State Out, the Body Cleaner as a Pipeline (#1163) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1163 in three PRs, with no change to what the reader returns.
- **PR A** (Tasks A0–A4, `Refs #1163`): reading state leaves `Service/Reader` for `Service/Reading`, the five mark-read services converge on one marker, `MarkReadService::mark()` takes a typed `ReadScope`, and `ExactSetGuard` moves to `Service/Tag`, its one consumer.
- **PR B** (Tasks B0–B6, `Refs #1163`): a page that cannot be parsed stops the extraction once, with a typed exception, and `ReaderBodyCleaner` becomes an ordered pipeline of steps over one per-pass context object.
- **PR C** (Tasks C0–C3, `Closes #1163`): extraction failure reasons become an enum, `ArticleExtractor` becomes `final readonly` and reads as orchestration, and the extractor seam takes `EntryHints` instead of three loose hint parameters.

**Architecture:**
- **One read marker.** `App\Service\Reading\EntryReadMarker` (today's `BulkEntryReadMarker`, renamed) is the only caller of `EntryReadMarkRepository`. It has two ways to mark:
  - `markSubscriptionsReadUntil(int $userId, list<Subscription>, \DateTimeImmutable $until)`: advances each watermark (never back) and flips the explicit unreads in those feeds, in one transaction. This is the code `MarkReadService` ran inline.
  - `markEntriesRead(int $userId, list<int> $entryIds)`: today's `markRead()`, by entry state, batched.
  - `MarkReadService` resolves the scope and hands the subscriptions over. `MarkEntriesReadService`, `SearchMarkReadService`, `SavedSearchMarkReadService` and `ForYouMarkReadService` each resolve an id list and hand it over.
- **The scope is typed.** `App\Service\Reading\ReadScope` is a `ReadScopeKind` enum (`all`, `feed`, `tag`) plus `?int $id`, built only through `ReadScope::all()`, `::feed(int)` and `::tag(int)`. `MarkReadRequest::toScope()` builds it after validation, with the same `validation_error` keys and messages as before, and `MarkReadService::mark(User, ReadScope, \DateTimeImmutable)` takes three parameters.
- **`Service/Reading` owns reading state:** the five mark-read services, `EntryReadMarker`, `EntryStateResolver`, `EntryStateUpdater` and `EntryStateChange` (#1182's value) join `ReadingActivity`, `ReadingActivityCounter` and `ReadingWindow`. After PR A, `Service/Reader` no longer imports `Service/Search`, and `Service/Recommendation` no longer imports `Service/Reader`. The one edge left between `Reading` and `Recommendation` is `Reading → Recommendation\Feed` (`ViewerTimeZone`), whose home #1169 decides.
- **`ExactSetGuard` leaves `Service/Reader`** for `Service/Tag`: `TagOrdering` is its only consumer at `566ee103`.
- **Parse failure is typed.** `App\Service\Html\HtmlDocumentParser::parse(string): HTMLDocument` throws `App\Service\Html\Exception\UnparseableHtmlException`. `FetchedPageNormalizer::normalize()` returns a document or throws. `ArticleExtractor` catches the exception once and returns the same `unextractable` result it returned before, so nothing downstream sees a nullable document any more:
  - `PageImageInventory::fromDocument()`, `LeadFigureCaptions::fromDocument()`, `PaywallSignals::isPreview()` and `ArticleReadability::richest()` each take an `HTMLDocument`.
  - `ArticleExtractor`'s `slideshowsIn()`/`teasersIn()` null guards go.
- **The body cleaner is a pipeline**, following the `FetchedPageNormalizer`/`PageRepair` pattern:
  - `App\Service\Reader\BodyCleaning\BodyCleaningStep::cleanIn(BodyCleaningPass $pass): void`.
  - `BodyCleaningPass` is the per-pass context object: the shared document, the `BodyCleaningInput` (what the extraction knows about the article besides its body: title candidates, lead image, media, feed media, entry author, slideshows, teasers, excerpt) and the one fact a step records for a later step (the body recovered its own embeds).
  - `ReaderBodyCleaner::clean(string $contentHtml, BodyCleaningInput $input): string` parses once, runs the steps in the order `services.yaml` lists them, and serialises once. Its constructor takes `iterable<BodyCleaningStep> $steps`, and the `ExcessiveParameterList` suppression goes.
  - The thirteen collaborators that were called in sequence implement `BodyCleaningStep` themselves (D2). Eleven keep their document-level method, now private, behind `cleanIn()`.
  - `PageMediaPlacement` is the one new step. It plans the page's media, restores the lead image unless a lead visual is top-placed, and applies the plan. `ReaderLeadImage::restore()` loses its `bool $topPlacesLeadVisual`, because the placement skips the restore instead of asking it to return early.
  - `FeedDimensionStamper` becomes an instance step.
  - The order is byte-for-byte the old call sequence, and `ReaderBodyCleanerWiringTest` pins it.
- **Failure reasons are an enum.** `App\Service\Reader\ExtractionFailure` is a string-backed enum whose values are exactly the wire strings (`no_url`, `fetch`, `unextractable`, `empty`, `mismatch`), and `ExtractionResult::$reason` is `?ExtractionFailure`. `ReaderJson` writes `->value`, so the JSON is unchanged.
- **The extractor seam takes `EntryHints`.** `ArticleExtractorInterface::extract(string $url, EntryHints $hints = new EntryHints())`: the feed entry's title, author and declared media travel as one value, and a URL-only call stays a URL-only call.
- **`ArticleExtractor` is `final readonly`.** `extract()` calls one private pipeline and maps every failure to a result in one `try`:
  - `PageFetchException` becomes `fetch`.
  - `UnparseableHtmlException` becomes `unextractable`.
  - `ArticleNotExtractedException` carries its own `ExtractionFailure`.
  - `ArticlePage` holds what the extractor reads off the page before readability consumes the normalised document.
  - `ArticleContentGate` owns the "enough text, or media that carries it" rule that was inlined as `mb_strlen(trim(...))`.

**Tech Stack:** PHP 8.4 (`\Dom\HTMLDocument`), Symfony 7.4 (DI argument lists in `config/services.yaml`), Doctrine ORM 3, fivefilters/readability.php, PHPUnit 12 (attributes), PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection.

**Spec:**
- GitHub issue #1163 (`gh issue view 1163`), parts 1–4 and the open question.
- Carry-forward (#1170 → #1163): "converge mark-read services on EntryReadMarkRepository". A1 does this: `EntryReadMarker` becomes the only caller of `EntryReadMarkRepository`, and all five services go through it.
- Carry-forward (#1162 PR A → #1169, "check at the #1163 reconcile"): `Service/Reading` imports `Recommendation\Feed\ViewerTimeZone`. Recorded as a note only (D1, "Not in scope"); the value's home is #1169's decision.
- Planner rulings of 2026-09-27 (D-P1, D-P2, D-P5 overruled; D-P3, D-P4 accepted), applied at this reconcile as A3, A4 and C3.
- Settled reader decisions (memory "reader-pipeline-settled-decisions"): host-agnostic rule with two approved exceptions; the order of `PageRepair` is behaviour; media sources share one raw `HTMLDocument`; the second readability grab stays; any reader-output change needs a `ReaderCacheService.VERSION` bump.
- CLAUDE.md "PHP code style — Clean Code is mandatory".

## Status

**Reconciled against 566ee103 (2026-09-28).**

| Task | State |
|---|---|
| A0: Branch and plan copy | ⬜ |
| A1: One read marker: `EntryReadMarker` | ⬜ |
| A2: Reading state moves to `Service/Reading` | ⬜ |
| A3: `ReadScope`: `mark()` takes a typed scope | ⬜ |
| A4: `ExactSetGuard` moves to `Service/Tag` | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: `HtmlDocumentParser::parse()` throws `UnparseableHtmlException` | ⬜ |
| B2: The extractor stops once on an unparseable page; downstream takes a document | ⬜ |
| B3: `BodyCleaningInput`: `clean()` takes two parameters | ⬜ |
| B4: `ReaderLeadImage::restore()` loses its flag; the cleaner skips the restore | ⬜ |
| B5: Every sequenced body-cleaning collaborator is a `BodyCleaningStep` | ⬜ |
| B6: The ordered pipeline in `services.yaml`; `PageMediaPlacement`; the suppression goes | ⬜ |
| C0: Preflight (PR B merged) | ⬜ |
| C1: `ExtractionFailure` | ⬜ |
| C2: `ArticleExtractor` is `final readonly` and reads as orchestration | ⬜ |
| C3: The extractor seam takes `EntryHints` | ⬜ |

## Scope

| Issue part or carry-forward item | Task |
|---|---|
| 1: reading state sits in the extraction module (`MarkReadService`, `MarkEntriesReadService`, `SearchMarkReadService`, `SavedSearchMarkReadService`, `EntryStateUpdater`, `EntryStateResolver`, `BulkEntryReadMarker`) | A2 |
| 1: `Recommendation/ForYouMarkReadService` (now `Recommendation/Feed/`, #1162) reaches into Reader for `BulkEntryReadMarker` | A1 (marker), A2 (move) |
| 1: the Reader → Search dependency exists only for `SearchMarkReadService` | A2 |
| 1: `MarkReadService` runs its own read-flip instead of the marker; converge the five on one marker | A1 |
| Carry-forward: converge the mark-read services on `EntryReadMarkRepository` | A1 |
| Ruling D-P1: `MarkReadService::mark(User, string $scope, ?int $id, …)` is stringly typed | A3 |
| Ruling D-P5: `ExactSetGuard` (a reorder guard) sits in `Service/Reader` | A4 |
| Carry-forward: `Service/Reading` imports `Recommendation\Feed\ViewerTimeZone` | Note only (D1); the home is #1169's |
| 1: `MagazineStyle` sits in Reader | Landed: #1182 moved it to `App\Enum\MagazineStyle` (see "Landed: #1182 and #1162") |
| 3: `parseOrNull` hides a parse failure as `null`; eight signatures re-check a nullable document | B1, B2 |
| 2: `ReaderBodyCleaner::clean()` takes 9 parameters, 5 optional, behind `@SuppressWarnings("PHPMD.ExcessiveParameterList")` | B3, B6 |
| 2: `ReaderLeadImage::restore(…, bool $topPlacesLeadVisual)` returns early on `true`; the pipeline should skip the step | B4, B6 |
| 2: 15 collaborators called one by one, every step the same shape; a tagged, ordered pipeline with a per-pass context | B5, B6 |
| 4: extraction failure reasons are docblock-only strings | C1 |
| 4: `extract()` mixes orchestration with `mb_strlen(trim(...))` checks and three identical `failed($url, 'empty')` exits; `ArticleExtractor` should be `final readonly` | C2 |
| Ruling D-P2: `ArticleExtractorInterface::extract()` takes four parameters, three of them hints | C3 |
| Open question: `TagesschauCarouselRecognizer`, `SubstackPosterLink`, `SubstackGatedVideoPlaceholder` | RULED (D-host): they stay. `SubstackPosterLink` becomes a pipeline step in B5, with its behaviour unchanged. |

## Design decisions

- **D-host — RULED: they stay (Lars, 2026-09-27).** The three publisher-specific classes are approved host-specific exceptions. The plan may move them or wire them into the pipeline, but their behaviour does not change, and there is no generalisation task. The facts:
  - `Slideshow/TagesschauCarouselRecognizer` hard-codes the tagesschau "Bildergalerie" shape: `[data-v-type="Carousel"]` elements whose `data-v` attribute holds entity-encoded JSON (`images`, `name`), with the rendition keys `l`, `m`, `s`, `xs`. It has no direct call site: `SlideshowScanner` collects it through the `app.slideshow_recognizer` tag. It is referenced by its own test and by `SlideshowExtractionTest`. This plan does not touch it.
  - `Media/SubstackPosterLink` hard-codes `#^https://substackcdn\.com/image/youtube/[^/]+/([A-Za-z0-9_-]{11})$#` and links the poster to `https://www.youtube-nocookie.com/embed/<id>`. It has one call site, `ReaderBodyCleaner::clean()`. B5 makes it a `BodyCleaningStep` without changing its logic.
  - `Repair/SubstackGatedVideoPlaceholder` hard-codes `.shows-video-player-container`, `article.podcast-post, article.shows-post` and the paywall selectors `[aria-label="Paywall"], [data-testid="paywall"]`. It has one call site, the `FetchedPageNormalizer` `$repairs` list in `services.yaml`. This plan does not touch it.
  - The settled rule stands: `SubstackProfileFeed` and `ZdfPlayerConfigSource` were the two approved exceptions, and these three now join them by ruling. A *second* platform-specific rewrite of the same kind becomes a tagged rule set, not another one-off.
- **D1: `ForYouMarkReadService` moves into `Service/Reading`** (from `Service/Recommendation/Feed/`, where #1162 put it, namespace `App\Service\Recommendation\Feed`). All five mark-read services then live beside the marker. The moved service imports only `RecommendationItemRepository` and the marker, so no new module edge appears, and `Recommendation → Reader` disappears.
  - **Dependency note (carry-forward, no action here).** At `566ee103`, `Service/Reading/ReadingActivityCounter` and `ReadingWindow` import `App\Service\Recommendation\Feed\ViewerTimeZone`, and nothing in `Service/Recommendation` imports `Service/Reading`. After PR A the direction stays one-way, `Reading → Recommendation\Feed`, and PR A adds no import to that edge. `ViewerTimeZone` stays where it is; moving it (to `Service/Reading`, a shared clock/time module, or elsewhere) is #1169's decision, which must keep the edge from turning into a `Reading ↔ Recommendation` cycle.
- **D2: collaborators implement `BodyCleaningStep` directly** (the `PageRepair` precedent), rather than 15 adapter classes. Eleven keep their document-level method, now private, behind a public `cleanIn()`. The two short ones, `InBodyEmbedRewriter` and `RecipeFactsCleaner`, read the pass directly. `PlayerChromeCleaner` already had a public `cleanIn(HTMLDocument)`, so that method is renamed to `removePlayerChromeFrom()`. The class names stay: the reader audit names them in its findings (`LeadingChromeMarkers`, `SuspiciousPhrases`).
- **D3: the order lives in `services.yaml`**, as an explicit `$steps` list like `FetchedPageNormalizer`'s `$repairs`, not a priority tag. `ReaderBodyCleanerWiringTest` pins the container's list against the call sequence of the old `clean()`. It also pins `ReaderBodyCleanerTest::steps()`, the hand-built list the unit tests share (the `FetchedPageNormalizerTest::repairs()` precedent), so that list cannot drift from the wiring.
- **D4: `BodyCleaningPass` is `final class`, not `readonly`.** It carries the one fact the old `clean()` kept in a local variable, the body recovered its own embeds (`$recoveredInBody`), so `PageMediaPlacement` can drop the page's discovered embeds (`discoveredMedia()`). The document and the input are `public readonly`.
- **D5: an unparseable *body* is still returned unchanged.** `ReaderBodyCleaner` catches `UnparseableHtmlException` at its own boundary (`testReturnsBlankInputUnchangedWithoutParsing`). Failing the extraction instead would turn today's `empty` reason into `unextractable` on the wire.
- **D6: `parse()` sits on top of `parseOrNull()`,** which keeps its current body. The one new line is fully covered, and the eight callers outside the extraction pipeline keep `parseOrNull()` (see "Not in scope").
- **D7: `ExtractionFailure` lives in `Service/Reader`, beside `ExtractionResult`.** No entity stores it. It crosses to `App\Http` only inside `ExtractionResult`, which already lives here (#1182 D1: a module-private value stays in its module).
- **D8: `ArticleContentGate` is a static helper** (`final class`, like `PaywallSignals`). A tenth constructor parameter on `ArticleExtractor` would reach PHPMD's `ExcessiveParameterList` threshold of 10.
- **D9: moved and rewritten files bring their comments to the bar** (#1182 D4). Every moved class, and every class this plan rewrites in full, is shown in its final form. A docblock this plan edits in place is trimmed to three lines or fewer, and a docblock the plan does not edit stays as it is, even in a touched file. A docblock that the change makes false is edited.
- **D10 (ruling D-P1): `ReadScope` and `ReadScopeKind` live in `Service/Reading`.** `docs/architecture.md` §8: `App\Enum` holds the enums an entity or a repository uses, and a module enum stays in its owning module. No entity or repository uses the scope (the repositories take plain ids), so both stay beside `MarkReadService`. The DTO turns into the value with `toScope()`, the §8 pattern (`->toChange()`, `->toUpdate()`). `toScope()` throws the same `ValidationException`s the service threw, with byte-identical keys and messages, the way `EntryView::fromRequestValue()` already does; the `Assert\Choice` on `scope` stays, so the choice message is Symfony's, as before.
- **D11 (ruling D-P5): `ExactSetGuard` moves to `Service/Tag`.** Its consumers at `566ee103`: `src/Service/Tag/TagOrdering.php` only (plus its own test). A single module, so it moves into that module, not to a §8 shared home. Its test moves to `tests/Service/Tag/`.
- **D12 (ruling D-P2): `new EntryHints()` is the default.** PHP 8.1 allows `new` in a parameter default, but not a static call, and `FeedMedia`'s constructor is private. So `EntryHints` takes `?FeedMedia $feedMedia = null` and stores `$feedMedia ?? FeedMedia::none()` in a non-null `public FeedMedia $feedMedia` (D-reconcile-2). `EntryHints::none()` is not needed.

## Planner rulings (2026-09-27): APPLIED at reconcile (566ee103)

- **D-host:** RULED by Lars. The three publisher classes stay (see above).
- **D-P1 OVERRULED → applied as Task A3.** `App\Service\Reading\ReadScope` (`ReadScopeKind $kind`, `?int $id`, private constructor, `ReadScope::all()`, `::feed(int $subscriptionId)`, `::tag(int $tagId)`, `targetId(): int`) and `enum App\Service\Reading\ReadScopeKind: string` (D10). `MarkReadRequest::toScope(): ReadScope` builds it after validation; `MarkReadService::mark(User $user, ReadScope $scope, \DateTimeImmutable $until): void`. The `validation_error` keys and messages stay byte-identical, and `EntryControllerTest::testMarkReadValidationErrorsKeepTheirKeysAndMessages` pins all three (`id` for `feed`, `id` for `tag`, `scope`).
- **D-P2 OVERRULED → applied as Task C3** (renumbered: C2 stays the orchestration rewrite and gives birth to `EntryHints` in its final form; C3 changes the seam). `ArticleExtractorInterface::extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult`. A default `new` is possible (D12), so `EntryHints::none()` is not added. 7 of the 39 `ArticleExtractorTest` calls pass hints and are rewritten; the 32 URL-only calls stay as they are. `FakeArticleExtractor`, `ReaderAuditRunner`, `EntryReaderController`, the two anonymous extractors in `ReaderAuditRunnerTest` and the three assertions that read the fake's recorded hints are shown in full.
- **D-P3 ACCEPTED:** `parseOrNull()` stays for the eight non-pipeline callers. Carried forward to #1169.
- **D-P4 ACCEPTED:** no digest test.
- **D-P5 OVERRULED → applied as Task A4.** Consumers at `566ee103`: `src/Service/Tag/TagOrdering.php` alone, so `ExactSetGuard` moves to `App\Service\Tag\ExactSetGuard` and its test to `tests/Service/Tag/ExactSetGuardTest.php` (D11).

## Planner decisions needed

The draft's D-P1 to D-P5 are ruled (above). These came up at the reconcile; each has a recommendation the plan already follows:

- **D-reconcile-1: `toScope()` keeps the unreachable "unknown scope" error. (ACCEPTED by the planner)** `Assert\Choice` rejects a bad `scope` before the controller runs, so the service's `Unknown scope "%s".` was never on the wire. Options: (a) `ReadScopeKind::tryFrom(...) ?? throw new ValidationException(['scope' => [...]])` in `toScope()`, the same message, like `EntryView::fromRequestValue()`; (b) `ReadScopeKind::from()`, a `\ValueError` if validation is ever skipped. Recommendation: (a). It keeps every message the old code could produce, and `MarkReadRequestTest` pins it, so no mutant escapes.
- **D-reconcile-2: how `new EntryHints()` gets its feed media. (ACCEPTED by the planner)** `FeedMedia`'s constructor is private and a default cannot call `FeedMedia::none()`. Options: (a) `EntryHints(?string $title = null, ?string $author = null, ?FeedMedia $feedMedia = null)` storing `$feedMedia ?? FeedMedia::none()` in a non-null property; (b) no default, and every URL-only call passes `EntryHints::none()` (32 test calls change instead of 7). Recommendation: (a), the ruling's first choice; the null exists only at the constructor, never on the value.
- **D-reconcile-3: `FakeArticleExtractor` records `list<EntryHints> $hints` (ACCEPTED by the planner)** instead of the `$requests` array shape. Kept as an array, `feedMedia` would be non-null and `$fake->requests[0]['feedMedia']?->posterFallback()` would fail PHPStan (nullsafe on a non-nullable type). Recommendation: record the value; three assertions change (shown in C3).
- **D-reconcile-4: D-P2 is its own task, C3, after C2. (ACCEPTED by the planner)** Folding the seam into C2's full-file rewrite would make one task change the interface, the fake, a controller, the audit runner and seven test calls on top of the orchestration rewrite. C2 now gives birth to `EntryHints` in its final form, and C3 only removes the four-parameter signature. Recommendation: accept; no bridge code exists between the two.

## Not in scope

- `MagazineStyle`: landed. #1182 moved it to `App\Enum\MagazineStyle`.
- The `parseOrNull()` callers (D-P3, #1169).
- Where `ViewerTimeZone` lives. `Service/Reading` imports `App\Service\Recommendation\Feed\ViewerTimeZone` (D1's note); #1169 decides its home.
- `ArticleReadability::richest()` keeps returning `?Article`: `null` there is "readability found no article in either variant", which `richer()` combines. The extractor turns it into `unextractable` in one place (C2).
- Generalising the three host-specific classes (D-host, ruled).
- Adopting `tests/Support/ParsesHtml` across the reader tests (#1169 carry-forward).
- A namespace-dependency PHPStan rule that would keep `Reader → Search` from coming back (#1169's namespace-cycle question).
- Long docblocks in files this plan touches only at one method (D9).
- The `$this->fail()`/`$this->assertSame()` style in the moved `ExactSetGuardTest` (#1169's `self::` sweep).

## Wire changes

None. Every response body, status code and header stays byte-identical:
- The `reason` strings are the enum's backing values, and `EntryReaderControllerTest` keeps asserting `'fetch'`, `'mismatch'` and `'no_url'` as strings.
- `frontend/src/app/reader/models.ts:437` (`'no_url' | 'fetch' | 'unextractable' | 'empty' | 'mismatch'`) needs no change.
- `POST /api/entries/mark-read` answers the same `validation_error` keys and messages (`id` for a `feed` or `tag` scope without an id, `scope` for an unknown scope). A3 pins all three before it moves the validation into `MarkReadRequest::toScope()`.
- No frontend file changes, so `npm run check` is not a gate.

## Reader output

Unchanged, so `ReaderCacheService.VERSION` is **not** bumped:
- The pipeline runs the same fifteen operations in the same order, over the same document, with the same arguments.
- The early `unextractable` exit returns exactly the result the old code reached, through a null document, a null readability result and `failed($url, 'unextractable')`.
- The `ArticleReadability` rewrite keeps the old call order whenever a collapsed variant exists, and skips only no-ops when it does not.

Every PR's final review checks this (attack points below). If a reviewer finds an output change, it is a bug to fix, not a reason to bump `VERSION`.

## Landed: #1182 and #1162 (checked at 566ee103)

Both issues are merged: #1182 as #1193 (`d3196d49`) and #1194 (`5229c947`), #1162 as #1195 (`45b8ce1c`), #1196 (`af04d4b3`), #1197 (`8a3413e1`) and #1200 (`566ee103`). Every fact below was read at `566ee103`. `git diff a124ad8a 566ee103 --stat` over the files this plan touches lists only the rows below marked changed, new, moved or gone; every other file the plan edits is byte-identical to the draft's base, so its anchors hold as drafted.

| File | State at `566ee103` | What this plan does with it |
|---|---|---|
| `src/Service/Reader/EntryStateChange.php` | New (#1182): `namespace App\Service\Reader;`, docblock `/** A partial state change: a null flag stays as it is. */`, `final readonly class EntryStateChange(public ?bool $isHidden = null, public ?bool $isFavorite = null, public ?bool $isKept = null, public ?bool $isViewed = null)` | A2 moves it to `src/Service/Reading/EntryStateChange.php`; only the namespace changes. |
| `src/Service/Reader/EntryStateUpdater.php` | Changed (#1182): `apply(User $user, EntryListRow $row, EntryStateChange $change): EntryState`; `applyTo`, `mirror` and `mirrorOnto` take `EntryStateChange $change` (4 hits); no `App\Dto` import. The body is exactly the code A2 shows; the class docblock is the old 3-line one. | A2 moves it; the namespace and the class docblock change. |
| `src/Dto/Entry/UpdateEntryStateRequest.php` | Changed (#1182): `use App\Service\Reader\EntryStateChange;` and `toChange(): EntryStateChange` | A2's perl rewrites the import to `App\Service\Reading\EntryStateChange`. |
| `tests/Dto/Entry/UpdateEntryStateRequestTest.php` | New (#1182); imports only `UpdateEntryStateRequest` and `TestCase` | Not touched; A2 runs it (`tests/Dto/Entry`). |
| `src/Controller/Api/EntryController.php` | Changed (#1182, #1162): `use App\Service\Reader\{EntryStateUpdater, MarkEntriesReadService, MarkReadService}` then `use App\Service\Recommendation\Feed\ForYouFeed;` and `use App\Service\Recommendation\Feed\ForYouMarkReadService;`; `updateState()` passes `$request->toChange()`; `markRead()` calls `$this->markRead->mark($user, $request->scope, $request->id, $request->until);` | A2 rewrites the imports; A3 changes the `markRead()` line to `$request->toScope()`. |
| `src/Dto/Entry/MarkReadRequest.php` | Unchanged: `#[Assert\Choice(choices: ['all', 'feed', 'tag'])] public string $scope`, `public \DateTimeImmutable $until`, `#[Assert\Positive] public ?int $id = null` | A3 adds `toScope()` (file shown in full). |
| `src/Service/Recommendation/Feed/ForYouMarkReadService.php` | Moved by #1162 from `src/Service/Recommendation/ForYouMarkReadService.php`; `namespace App\Service\Recommendation\Feed;`; imports `App\Service\Reader\BulkEntryReadMarker` and calls `->markRead(`. Body otherwise unchanged. | A1's perl points it at `EntryReadMarker`; A2 moves it to `src/Service/Reading/ForYouMarkReadService.php`, `namespace App\Service\Reading;`. |
| `src/Service/Reading/ReadingActivityCounter.php`, `ReadingWindow.php` | Changed (#1162): import `App\Service\Recommendation\Feed\ViewerTimeZone` | Not touched (D1's dependency note). |
| `src/Service/Reader/MagazineStyle.php` | Gone; #1182 moved it to `src/Enum/MagazineStyle.php` | Not touched. |
| `src/Service/Reader/ExactSetGuard.php` | Unchanged; `src/Service/Tag/TagOrdering.php` is its only consumer (`use App\Service\Reader\ExactSetGuard;`, `private ExactSetGuard $exactSet`); test `tests/Service/Reader/ExactSetGuardTest.php` | A4 moves both to `Service/Tag`. |
| `tests/Controller/Api/EntryControllerTest.php` | Changed (#1162: imports only); `testMarkReadFeedScopeWithoutIdIsUniformValidationError` unchanged | A3 replaces that test with a pinned data-provider test. |
| `tests/Controller/Api/EntryReaderControllerTest.php` | Changed (#1182): imports `App\Entity\Discussion` | C1 and C3 edit other lines; the anchors are independent. |
| `tests/Service/Tracing/TracedServiceMethodsTest.php` | Changed (#1162: Recommendation imports only); still yields `[ArticleExtractor::class, 'extract']` and `[ReaderBodyCleaner::class, 'clean']` | Run only. |
| `tests/Repository/RecommendationFeedTest.php` | Changed (#1162); imports no mark-read service | Run only (A2). |
| `config/services.yaml` | Changed (#1162: the `Ai\Completion` lines). The `FetchedPageNormalizer` `$repairs` block and the `App\Service\Search\EntrySearchInterface` line after it are unchanged. | B6's anchor holds. |
| `src/Service/ReaderAudit/{AuditFinding,AuditFindings,AuditFindingsFile,CleanupMarker}.php` | Changed (#1182: `toArray()`/`fromArray()` became `toFindingsFileRecord()`/`fromFindingsFileRecord()`) | Not touched. `ReaderAuditRunner`, `CleanupMarkersTest` and `ReaderAuditRunnerTest` are unchanged. |
| `CLAUDE.md` | Changed (#1182): the persistence Layout row and the "Domain code knows no HTTP" / "Shared values have one home" bullets. The `backend/src/Service/**` row is unchanged. | A2 edits the `backend/src/Service/**` row only. |
| `tests/PhpStan/DomainKnowsNoHttpRule.php`, `NoToArrayInServicesRule.php`, `PersistenceKnowsNoServiceRule.php` | #1182. `DomainKnowsNoHttpRule` also forbids `App\Dto` in domain code. | Not touched. Every class this plan adds or moves under `App\Service` imports no `App\Http`, no `App\Dto` and no Symfony HTTP, and has no `toArray()`/`jsonSerialize()`. `MarkReadRequest` (`App\Dto`) importing `App\Service\Reading` is the permitted direction (§8). |
| `docs/architecture.md` §8 | #1182: "Where shared values live" | Decides D10 (`ReadScope` stays in `Service/Reading`) and D11. |

**`git grep -F` cannot match `\E`.** The draft's A0/A2 file lists used `git grep -lF -e 'App\Service\Reader\EntryStateChange' …`. With git 2.55, a fixed-string pattern that contains `\E` matches nothing (git quotes it for PCRE with `\Q…\E`), so every `EntryState*` and `EntryReadMarker` file silently dropped out of the perl. The reconciled steps use `git grep -lE` with an alternation instead.

## Reconcile changes (566ee103)

- Status: set to "Reconciled against 566ee103 (2026-09-28)"; the table gains A3, A4 and C3, and A0 is renamed "Branch and plan copy".
- Goal: PR A is A0–A4 (adds `ReadScope` and the `ExactSetGuard` move); PR C is C0–C3 (adds the `EntryHints` seam).
- Architecture: added "The scope is typed", the `Reading → Recommendation\Feed` edge, "`ExactSetGuard` leaves `Service/Reader`" and "The extractor seam takes `EntryHints`".
- Spec: added the `ViewerTimeZone` carry-forward and the applied 2026-09-27 rulings.
- Scope table: rows for D-P1 (A3), D-P5 (A4), D-P2 (C3) and the `ViewerTimeZone` note; the `MagazineStyle` row now says it landed.
- D1: `ForYouMarkReadService` comes from `Service/Recommendation/Feed` (#1162), `ViewerTimeZone` is `Recommendation\Feed\ViewerTimeZone`, and the dependency-direction note is recorded.
- Design decisions: D10 (`ReadScope` home, §8), D11 (`ExactSetGuard` home) and D12 (`new EntryHints()` default) added.
- Planner rulings: section marked "APPLIED at reconcile (566ee103)", with the task and signature each ruling became.
- Planner decisions needed: the draft's D-P1 to D-P5 are superseded by the rulings; D-reconcile-1 to D-reconcile-4 added.
- Not in scope: D-P1, D-P2 and D-P5 removed; `ViewerTimeZone`'s home and the moved `ExactSetGuardTest`'s assertion style added; `MagazineStyle` marked landed.
- Wire changes: the mark-read `validation_error` bullet added.
- "Depends on #1182" replaced by "Landed: #1182 and #1162 (checked at 566ee103)", with the state of every drifted file and the `git grep -F`/`\E` finding.
- Global Constraints: per-task anchor checks for PR A and light read-only preflights for B and C; the `git grep -F` + `\E` rule; `App\Dto` and `NoToArrayInServicesRule` in the domain bullet; `ReadScope` in the `AutowireWrongClass` list.
- A0: now branch and plan copy only (same copy path), plus an ancestor check for `566ee103` and a drift check; the site survey moved into A1/A2 Step 0.
- A1: Step 0 added; `ForYouMarkReadService`'s path is `src/Service/Recommendation/Feed/` in Files, the perl and `git add`; the post-perl grep now matches `readMarker->markRead(`, because the draft's `->markRead(` also hit `SavedSearchSlugRoutingTest`'s own helper.
- A2: Step 0 added; the file-list greps use `git grep -lE` (the draft's `-lF` silently missed every `EntryState*`/`EntryReadMarker` file) and the `Recommendation\Feed\ForYouMarkReadService` name; `git mv` source path fixed; the `EntryStateUpdater` caveat replaced by the verified fact; the `EntryController` import block now reads `Recommendation\Feed\ForYouFeed`; Step 5 grep fixed and a `ViewerTimeZone` edge check added.
- A3 added: `ReadScope`/`ReadScopeKind`, `MarkReadRequest::toScope()`, `MarkReadService::mark(User, ReadScope, \DateTimeImmutable)`, and a controller pin for the three `validation_error` bodies (D-P1).
- A4 added: `ExactSetGuard` and its test move to `Service/Tag` (D-P5).
- Finishing PR A: simplify rulings, review points (module edges, `ReadScope`, the controller's one changed call) and PR body bullets updated.
- B0: expectations re-read at `566ee103` (unchanged); Step 3 added (read-only anchors, the `services.yaml` anchor, cross-task interfaces; no dry run).
- B1–B6 and C1: no edit. Their files are byte-identical between `a124ad8a` and `566ee103`, except `services.yaml` (only the `Ai\Completion` lines changed) and `EntryReaderControllerTest` (only the `Discussion` import changed); every anchor was re-checked against `566ee103` or is written by an earlier task.
- C0: Step 3 added (read-only anchors, C3's call sites with expected counts, cross-task interfaces).
- C2: `EntryHints` is born in its final form (defaults; D12) with `EntryHintsTest` and a sixth deletion check; `$entry` is renamed `$hints` in the rewritten extractor; the Interfaces line defers the seam to C3.
- C3 added: `extract(string $url, EntryHints $hints = new EntryHints())` on the interface, the extractor, the fake and two anonymous test extractors; 7 test calls, 2 production callers and 3 assertions rewritten (D-P2).
- Finishing PR C: simplify rulings, the `extract()` review point, PR body bullets and the carry-forward list (D-P1 and D-P5 are done; `ViewerTimeZone` added).

## Global Constraints

- **Paths and commands are relative to `backend/`**, except the preflight steps marked "from the repository root", and `docs/…` and `CLAUDE.md`.
- **Anchors are checked where they are used.** PR A has no site-survey preflight: every PR A task starts with a Step 0 that runs its own anchor greps against the branch as it stands, before any edit. PR B and PR C keep a light, read-only preflight at their real starting SHA (B0, C0). If an anchor differs, stop and report; never adapt a step silently.
- **Never `git grep -F` a pattern that contains `\E`** (a namespace such as `App\Service\Reader\EntryState…`): git quotes fixed strings for PCRE with `\Q…\E`, so the pattern matches nothing and the step silently skips files. Use `git grep -E 'App\\Service\\Reader\\(A|B)'` instead.
- **No wire change and no reader-output change** (see above). No `ReaderCacheService.VERSION` bump, and no frontend change.
- **The `PageRepair` order, the shared raw document and the second readability grab stay.**
  - `config/services.yaml`'s `FetchedPageNormalizer` `$repairs` list is not edited.
  - `PageMediaScannerWiringTest::testNoSourceChangesTheSharedDocument` stays as it is.
  - `ArticleReadability` still extracts the collapsed variant.
- **Clean Code (CLAUDE.md) is mandatory:**
  - Names reveal intent.
  - No boolean flag parameters. A stored `bool` in a value object (`ArticlePage::$paywalled`, `ExtractionResult::ok(… bool $paywalled)`) is data, not a flag (#1167 ruling).
  - Three parameters at most, constructors aside.
  - Guard clauses over nesting.
  - `final readonly` by default: `final class` for `BodyCleaningPass` (D4) and the static helpers (`HtmlDocumentParser`, `ArticleContentGate`), and exceptions extend `\RuntimeException`.
  - Queries live in `src/Repository`.
  - Typed exceptions live in `Service/*/Exception`.
  - Domain code imports nothing from `App\Http`, `App\Dto` or Symfony HTTP (`DomainKnowsNoHttpRule`), and no service builds a `toArray()` (`NoToArrayInServicesRule`).
- **Comments (D9):** default none, at most three lines, only where a future reader would otherwise get the code wrong. Every file this plan writes in full is shown with its final comments. No `@param`/`@return` that repeats the signature, unless PHPStan needs the array shape.
- **PSR-12 line length: 120 columns.** Every line in this plan fits. If a perl substitution pushes one past 120, wrap its arguments one per line.
- **Tests read persisted ids with `requireId()`** (`EntityIdCoercionRule` covers `tests/`), assert per field with `assertSame`, and use PHPUnit 12 attributes (`#[DataProvider]`).
- **Use the existing test helpers:** `SeedsUsers`, `ReloadsEntities`, `NoEgressProxy`, `DbTestCase`. The two new ones, `BodyCleaningInputs` and `BodyCleaningPasses`, live in `tests/Support`.
- **Every touched `src` file is PHPMD-clean** under `composer md`. Fix the design, never the threshold. After B6, `ReaderBodyCleaner` carries no suppression.
- **phptramp:** no chain of 4+ hops across 2+ classes forwards an unread parameter. The pass (`BodyCleaningPass`) and the page (`ArticlePage`) exist so the inputs have a home.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`.
- **Test-first where behaviour or an API changes; pin-first where it does not.** A pin passes on develop and must keep passing; the step that first runs it says "Expected: PASS (pin)". That is intended, not a mistake.
- **Every new test gets a deletion check.** Break the production line it covers, run the test and watch it fail, then restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report.
- **Gates for every task:**
  - the task's own tests,
  - `composer check` (cs + stan + tramp; run `bin/console cache:warmup` first if the dev cache is cold),
  - `composer md`,
  - PhpStorm inspections on every changed PHP file (`mcp__phpstorm__lint_files`). ERROR and WARNING block. A Symfony-plugin `AutowireWrongClass` false positive on a value object built with `new` (`BodyCleaningInput`, `BodyCleaningPass`, `ArticlePage`, `EntryHints`, `ReadScope`) gets `/** @noinspection AutowireWrongClass */` plus a one-line reason, as #1158 PR 2 did.
- **Reader fixture regression suite.** Every PR B and PR C task runs:
  ```bash
  php bin/phpunit tests/Service/Reader
  php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php
  ```
  Expected: PASS. No assertion that reads `tests/Fixtures/reader/` or `tests/Fixtures/Slideshow/` changes. The suites that read those fixtures are:
  - `ArticleExtractorTest`,
  - `Media/HostAgnosticDiscoveryTest`,
  - `Media/PageMediaScannerWiringTest`,
  - `Media/Provider/SoundCloudEmbedProviderTest`,
  - `Media/Source/{AttributeMediaSource,JsonLdMediaSource,MetaMediaSource,PageEmbedSource}Test`,
  - `Slideshow/SlideshowExtractionTest`,
  - `Slideshow/TagesschauCarouselRecognizerTest`.
- **Infection.** An escaped mutant on a line this PR touched gets a killing test in the task that owns the line. A provably equivalent mutant is removed by rewriting the line, or reported to the planner. Never add an `ignore` and never lower `minMsi`.
- **Commits:** `refactor(#1163): <lower-case summary>`, one per task, with no attribution lines. The plan copy is committed the same way (#1182 D8).
- **Branches, each cut from `origin/develop` after the previous PR merges:**
  - PR A: `refactor/1163-reading-state-module`.
  - PR B: `refactor/1163-body-cleaning-pipeline`.
  - PR C: `refactor/1163-extractor-failures`.
- **PR bodies.** PR A and PR B say `Refs #1163`. Neither their bodies, their prose nor any commit message on either branch may contain "close", "closes", "fix", "fixes", "resolve" or "resolves" in any form. PR C's body says `Closes #1163`.
- **The checkout is shared.** Run `git status --short` and `git branch --show-current` before any `switch`, `reset` or `stash`; another session may be mid-edit. Work in place, with no worktrees.

---

# PR A — Reading state moves out; one read marker

### Task A0: Branch and plan copy

**Files:** `docs/superpowers/plans/2026-09-27-1163-reader-module-split.md` (the plan copy). No code changes.

PR A has no site survey: each task's Step 0 checks that task's own anchors (Global Constraints).

- [ ] **Step 1: Confirm the checkout is free and develop contains the reconcile base (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1163 --json state --jq .state
git merge-base --is-ancestor 566ee103 origin/develop && echo 'base ok'
git diff --stat 566ee103 origin/develop -- backend/src/Service/Reader backend/src/Service/Reading \
  backend/src/Service/Recommendation/Feed/ForYouMarkReadService.php backend/src/Service/Tag \
  backend/src/Controller/Api/EntryController.php backend/src/Dto/Entry/MarkReadRequest.php
```
Expected:
- A clean tree, or only another session's files, which you leave alone.
- `OPEN`, then `base ok`.
- The last command prints nothing: develop has not moved under PR A's files since the reconcile. If it prints anything, carry on; each task's Step 0 decides whether its anchors still hold.

- [ ] **Step 2: Cut the branch and commit the plan copy (from the repository root)**

```bash
git switch -c refactor/1163-reading-state-module origin/develop
mkdir -p docs/superpowers/plans
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1163-draft.md docs/superpowers/plans/2026-09-27-1163-reader-module-split.md
git add docs/superpowers/plans/2026-09-27-1163-reader-module-split.md
git commit -m "refactor(#1163): add the implementation plan"
```

---

### Task A1: One read marker: `EntryReadMarker`

**Files:**
- Move: `src/Service/Reader/BulkEntryReadMarker.php` → `src/Service/Reader/EntryReadMarker.php` (rewritten in full)
- Modify: `src/Service/Reader/MarkReadService.php` (rewritten in full)
- Modify (perl): `src/Service/Reader/MarkEntriesReadService.php`, `src/Service/Reader/SearchMarkReadService.php`, `src/Service/Reader/SavedSearchMarkReadService.php`, `src/Service/Recommendation/Feed/ForYouMarkReadService.php`
- Create: `tests/Service/Reader/EntryReadMarkerTest.php`

**Interfaces:**
- Produces: `App\Service\Reader\EntryReadMarker::markSubscriptionsReadUntil(int $userId, list<Subscription> $subscriptions, \DateTimeImmutable $until): void` and `::markEntriesRead(int $userId, list<int> $entryIds): void`. A2 moves the class to `App\Service\Reading`.
- `MarkReadService`'s constructor becomes `(EntryReadMarker, SubscriptionRepository, TagRepository)`. Its public `mark()` is unchanged here; A3 types its scope.

- [ ] **Step 0: Verify this brief's anchors**

```bash
git grep -l BulkEntryReadMarker -- src tests config
git grep -l EntryReadMarkRepository -- src tests
git grep -n 'readMarker->markRead(' -- src
git grep -nF 'public function markRead(int $userId, array $entryIds): void' -- src/Service/Reader/BulkEntryReadMarker.php
git grep -nF 'public function mark(User $user, string $scope, ?int $id,' -- src/Service/Reader/MarkReadService.php
ls src/Service/Reader/EntryReadMarker.php tests/Service/Reader/EntryReadMarkerTest.php
```
Expected (as at `566ee103`):
- `BulkEntryReadMarker`, 5 files: `src/Service/Reader/BulkEntryReadMarker.php`, `src/Service/Reader/MarkEntriesReadService.php`, `src/Service/Reader/SavedSearchMarkReadService.php`, `src/Service/Reader/SearchMarkReadService.php`, `src/Service/Recommendation/Feed/ForYouMarkReadService.php`.
- `EntryReadMarkRepository`, 3 files: `src/Repository/EntryReadMarkRepository.php`, `src/Service/Reader/BulkEntryReadMarker.php`, `src/Service/Reader/MarkReadService.php`.
- `readMarker->markRead(`, 4 lines: the three `src/Service/Reader` id-list services and `src/Service/Recommendation/Feed/ForYouMarkReadService.php`.
- One hit for each signature.
- `ls` reports both files missing.

Anything else: stop and report.

- [ ] **Step 1: Write the failing test**

`tests/Service/Reader/EntryReadMarkerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Reader\EntryReadMarker;
use App\Tests\DbTestCase;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;

final class EntryReadMarkerTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    private User $reader;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = $this->user('marker@example.com');
        $this->feed = new Feed('https://example.com/marker.xml');
        $this->em->persist($this->feed);
        $this->subscription = new Subscription(
            $this->reader,
            $this->feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->em->persist($this->subscription);
        $this->em->flush();
    }

    public function testMarkingEntriesCreatesAHiddenRowWhereNoneExists(): void
    {
        $entry = $this->entry('no-row', '2026-07-05T00:00:00Z');

        $this->marker()->markEntriesRead($this->reader->requireId(), [$entry->requireId()]);

        $state = $this->stateOf($entry);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
    }

    public function testMarkingEntriesFlipsAnExplicitUnreadAndStampsIt(): void
    {
        $entry = $this->entry('unread', '2026-07-05T00:00:00Z');
        $this->explicitlyUnread($entry);

        $this->marker()->markEntriesRead($this->reader->requireId(), [$entry->requireId()]);

        $state = $this->stateOf($entry);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
        self::assertNotNull($state->getHiddenAt());
    }

    public function testMarkingEntriesLeavesEveryOtherEntryAlone(): void
    {
        $marked = $this->entry('marked', '2026-07-05T00:00:00Z');
        $other = $this->entry('other', '2026-07-05T00:00:00Z');
        $this->explicitlyUnread($other);

        $this->marker()->markEntriesRead($this->reader->requireId(), [$marked->requireId()]);

        $state = $this->stateOf($other);
        self::assertNotNull($state);
        self::assertFalse($state->isHidden());
    }

    public function testMarkingSubscriptionsAdvancesTheWatermarkAndFlipsUnreadsUpToUntil(): void
    {
        $covered = $this->entry('covered', '2026-07-05T00:00:00Z');
        $newer = $this->entry('newer', '2026-07-20T00:00:00Z');
        $this->explicitlyUnread($covered);
        $this->explicitlyUnread($newer);

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$this->subscription],
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame('2026-07-10T00:00:00+00:00', $this->watermark());
        self::assertTrue($this->stateOf($covered)?->isHidden());
        self::assertFalse($this->stateOf($newer)?->isHidden());
    }

    public function testMarkingSubscriptionsNeverMovesAWatermarkBack(): void
    {
        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-15T00:00:00Z'));
        $this->em->flush();

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$this->subscription],
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame('2026-07-15T00:00:00+00:00', $this->watermark());
    }

    private function marker(): EntryReadMarker
    {
        $marker = self::getContainer()->get(EntryReadMarker::class);
        self::assertInstanceOf(EntryReadMarker::class, $marker);

        return $marker;
    }

    private function entry(string $guid, string $effectiveDate): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            null,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function explicitlyUnread(Entry $entry): void
    {
        $state = new EntryState($this->reader, $entry);
        $state->markUnread();
        $this->em->persist($state);
        $this->em->flush();
    }

    /** Clears first: the marks are bulk DQL, which the identity map never sees. */
    private function stateOf(Entry $entry): ?EntryState
    {
        $this->em->clear();

        return $this->em->getRepository(EntryState::class)
            ->findOneForUserEntry($this->reader->requireId(), $entry->requireId());
    }

    private function watermark(): ?string
    {
        return $this->reload($this->subscription)->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/EntryReadMarkerTest.php`
Expected: FAIL. All five tests error with `You have requested a non-existent service "App\Service\Reader\EntryReadMarker"`.

- [ ] **Step 3: Implement**

```bash
git mv src/Service/Reader/BulkEntryReadMarker.php src/Service/Reader/EntryReadMarker.php
```

`src/Service/Reader/EntryReadMarker.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryReadMarkRepository;
use App\Repository\EntryStateRepository;
use App\Repository\ReadMarking;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The one way entries are marked read: by subscription watermark for a feed or tag scope, or by entry state for
 * the lists no watermark can scope (search, saved searches, For You, a batch of ids).
 */
final readonly class EntryReadMarker
{
    private const int BATCH = 500;

    public function __construct(
        private EntryStateRepository $states,
        private EntryReadMarkRepository $readMarks,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<Subscription> $subscriptions */
    public function markSubscriptionsReadUntil(int $userId, array $subscriptions, \DateTimeImmutable $until): void
    {
        if ($subscriptions === []) {
            return;
        }

        $feedIds = [];
        foreach ($subscriptions as $subscription) {
            $feedIds[] = $subscription->getFeed()->requireId();
            $this->advanceWatermark($subscription, $until);
        }

        // Atomic: the read-flip joins the transaction, which flushes the watermark changes before it commits.
        $this->em->wrapInTransaction(function () use ($userId, $feedIds, $until): void {
            $this->readMarks->hideUnreadInFeedsUntil(new ReadMarking($userId, $this->clock->now()), $feedIds, $until);
        });
    }

    /**
     * Batched, so a broad list never loads every id at once. The ids must be distinct and exist: a missing state
     * row is persisted by reference, so a pruned or repeated id fails the insert.
     *
     * @param list<int> $entryIds
     */
    public function markEntriesRead(int $userId, array $entryIds): void
    {
        if ($entryIds === []) {
            return;
        }

        $marking = new ReadMarking($userId, $this->clock->now());
        foreach (array_chunk($entryIds, self::BATCH) as $chunk) {
            $this->readMarks->hideUnreadAmong($marking, $chunk);
            $this->createMissing($marking, $chunk);
            $this->em->flush();
            $this->em->clear();
        }
    }

    private function advanceWatermark(Subscription $subscription, \DateTimeImmutable $until): void
    {
        $current = $subscription->getMarkedReadUntil();
        if ($current === null || $current < $until) {
            $subscription->setMarkedReadUntil($until);
        }
    }

    /** @param list<int> $entryIds */
    private function createMissing(ReadMarking $marking, array $entryIds): void
    {
        $withState = $this->states->entryIdsWithStateForUser($marking->userId, $entryIds);
        $missing = array_values(array_diff($entryIds, $withState));
        if ($missing === []) {
            return;
        }
        $userRef = $this->em->getReference(User::class, $marking->userId)
            ?? throw new \LogicException('The current user has no reference.');
        foreach ($missing as $entryId) {
            $entryRef = $this->em->getReference(Entry::class, $entryId)
                ?? throw new \LogicException('An entry just selected for marking has no reference.');
            $state = new EntryState($userRef, $entryRef);
            $state->hide($marking->at);
            $this->em->persist($state);
        }
    }
}
```

`src/Service/Reader/MarkReadService.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;

/** "Mark all read until T" for a scope: every subscription the scope covers is marked by watermark. */
final readonly class MarkReadService
{
    public function __construct(
        private EntryReadMarker $readMarker,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
    ) {
    }

    public function mark(User $user, string $scope, ?int $id, \DateTimeImmutable $until): void
    {
        $subscriptions = $this->resolveScope($user, $scope, $id);
        $this->readMarker->markSubscriptionsReadUntil($user->requireId(), $subscriptions, $until);
    }

    /**
     * @return list<Subscription>
     */
    private function resolveScope(User $user, string $scope, ?int $id): array
    {
        $userId = $user->requireId();

        return match ($scope) {
            'all' => $this->includedInAllItems($this->subscriptions->findForUserWithTags($userId)),
            'feed' => [$this->requireSubscription($id, $userId)],
            'tag' => $this->subscriptions->findForUserByTagId($userId, $this->requireTag($id, $userId)),
            default => throw new ValidationException(['scope' => [sprintf('Unknown scope "%s".', $scope)]]),
        };
    }

    /**
     * Scope "all" mirrors what the All-items list shows, so a feed hidden from
     * it must not have its watermark advanced or its entries flipped read.
     *
     * @param  list<Subscription> $subscriptions
     * @return list<Subscription>
     */
    private function includedInAllItems(array $subscriptions): array
    {
        return array_values(array_filter(
            $subscriptions,
            static fn (Subscription $subscription): bool => $subscription->isIncludeInAllItems(),
        ));
    }

    private function requireSubscription(?int $id, int $userId): Subscription
    {
        if ($id === null) {
            // Same validation_error contract as every other bad field, so the
            // client's type-switch handles a missing id uniformly.
            throw new ValidationException(['id' => ['An id is required when scope is "feed".']]);
        }

        return $this->subscriptions->getOneForUser($userId, $id);
    }

    private function requireTag(?int $id, int $userId): int
    {
        if ($id === null) {
            throw new ValidationException(['id' => ['An id is required when scope is "tag".']]);
        }

        return $this->tags->getOneForUser($userId, $id)->requireId();
    }
}
```

The four id-list services:
```bash
perl -pi -e 's/\bBulkEntryReadMarker\b/EntryReadMarker/g; s/\$this->readMarker->markRead\(/\$this->readMarker->markEntriesRead(/g' \
  src/Service/Reader/MarkEntriesReadService.php \
  src/Service/Reader/SearchMarkReadService.php \
  src/Service/Reader/SavedSearchMarkReadService.php \
  src/Service/Recommendation/Feed/ForYouMarkReadService.php
git grep -n "BulkEntryReadMarker\|readMarker->markRead(" -- src tests
git grep -l EntryReadMarkRepository -- src
```
Expected:
- The first grep prints nothing. (It matches `readMarker->markRead(`, not `->markRead(`: `SavedSearchSlugRoutingTest` has its own `$this->markRead(` helper.)
- The second prints `src/Repository/EntryReadMarkRepository.php` and `src/Service/Reader/EntryReadMarker.php` only: the marker is now the repository's one caller (the carry-forward).
- `ForYouMarkReadService` now imports `App\Service\Reader\EntryReadMarker`, and `SearchMarkReadService`'s docblock names `EntryReadMarker`. A2 rewrites both files in full.

- [ ] **Step 4: Run to verify it passes**

```bash
php bin/phpunit tests/Service/Reader/EntryReadMarkerTest.php tests/Service/Reader/MarkReadServiceTest.php tests/Service/Reader/SearchMarkReadServiceTest.php
php bin/phpunit tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntrySearchMarkReadTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Controller/Api/SavedSearchSlugRoutingTest.php
```
Expected: PASS. `MarkReadServiceTest` passes unchanged: it pins the watermark, the flip, the "all" exclusion and the validation errors through `MarkReadService`.

- [ ] **Step 5: Deletion checks**

Break each line, run `php bin/phpunit tests/Service/Reader/EntryReadMarkerTest.php`, and restore it by hand:
1. In `advanceWatermark()`, delete `$subscription->setMarkedReadUntil($until);`. Expected: `testMarkingSubscriptionsAdvancesTheWatermarkAndFlipsUnreadsUpToUntil` fails.
2. Change `$current < $until` to `$current > $until`. Expected: both watermark tests fail.
3. In `markEntriesRead()`, delete `$this->createMissing($marking, $chunk);`. Expected: `testMarkingEntriesCreatesAHiddenRowWhereNoneExists` fails.
4. Delete `$this->readMarks->hideUnreadAmong($marking, $chunk);`. Expected: `testMarkingEntriesFlipsAnExplicitUnreadAndStampsIt` fails.

- [ ] **Step 6: Gates and commit**

Run `composer check` and `composer md`, and run PhpStorm `lint_files` on the six changed PHP files. Expected: green.
```bash
git add src/Service/Reader/EntryReadMarker.php src/Service/Reader/MarkReadService.php \
  src/Service/Reader/MarkEntriesReadService.php src/Service/Reader/SearchMarkReadService.php \
  src/Service/Reader/SavedSearchMarkReadService.php src/Service/Recommendation/Feed/ForYouMarkReadService.php \
  tests/Service/Reader/EntryReadMarkerTest.php
git commit -m "refactor(#1163): the five mark-read services go through one entry read marker"
```

---

### Task A2: Reading state moves to `Service/Reading`

**Files:**
- Move (then rewritten in full, comments brought to the bar per D9): `src/Service/Reader/{EntryReadMarker,EntryStateResolver,EntryStateUpdater,EntryStateChange,MarkReadService,MarkEntriesReadService,SearchMarkReadService,SavedSearchMarkReadService}.php` and `src/Service/Recommendation/Feed/ForYouMarkReadService.php` → `src/Service/Reading/`
- Move (perl on namespace and imports): `tests/Service/Reader/{EntryReadMarkerTest,EntryStateResolverTest,EntryStateUpdaterTest,MarkReadServiceTest,SearchMarkReadServiceTest}.php` → `tests/Service/Reading/`
- Modify (perl, names only): `src/Controller/Api/EntrySearchController.php`, `src/Controller/Api/SavedSearchEntriesController.php`, `src/Dto/Entry/UpdateEntryStateRequest.php`, and any other file Step 0 lists
- Modify: `src/Controller/Api/EntryController.php` (the import block)
- Modify: `tests/Service/Reading/EntryStateResolverTest.php`, `tests/Service/Reading/EntryStateUpdaterTest.php` (one docblock each)
- Modify: `CLAUDE.md` (the `backend/src/Service/**` Layout row)

**Interfaces:**
- Produces: `App\Service\Reading\{EntryReadMarker, EntryStateResolver, EntryStateUpdater, EntryStateChange, MarkReadService, MarkEntriesReadService, SearchMarkReadService, SavedSearchMarkReadService, ForYouMarkReadService}`. Members are unchanged; only the namespaces and comments change.

- [ ] **Step 0: Verify this brief's anchors**

```bash
git grep -lE 'App\\Service\\Reader\\(EntryReadMarker|EntryStateResolver|EntryStateUpdater|EntryStateChange|MarkReadService|MarkEntriesReadService|SearchMarkReadService|SavedSearchMarkReadService)|App\\Service\\Recommendation\\Feed\\ForYouMarkReadService' -- src tests config
git grep -c 'EntryStateChange $change' -- src/Service/Reader/EntryStateUpdater.php
git grep -n '^namespace' -- src/Service/Recommendation/Feed/ForYouMarkReadService.php
git grep -n 'App\\Service\\Recommendation' -- src/Service/Reading src/Controller/Api/EntryController.php
ls src/Service/Reading
```
Expected (as at `566ee103` plus A1):
- The fully qualified names, 10 files: `src/Controller/Api/EntryController.php`, `src/Controller/Api/EntrySearchController.php`, `src/Controller/Api/SavedSearchEntriesController.php`, `src/Dto/Entry/UpdateEntryStateRequest.php`, `src/Service/Recommendation/Feed/ForYouMarkReadService.php`, `tests/Service/Reader/EntryReadMarkerTest.php` (A1), `tests/Service/Reader/EntryStateResolverTest.php`, `tests/Service/Reader/EntryStateUpdaterTest.php`, `tests/Service/Reader/MarkReadServiceTest.php`, `tests/Service/Reader/SearchMarkReadServiceTest.php`.
- The count `4` (`apply`, `applyTo`, `mirror`, `mirrorOnto`).
- `namespace App\Service\Recommendation\Feed;`.
- Four import lines: `ReadingActivityCounter.php` and `ReadingWindow.php` import `App\Service\Recommendation\Feed\ViewerTimeZone`; `EntryController.php` imports `App\Service\Recommendation\Feed\ForYouFeed` and `App\Service\Recommendation\Feed\ForYouMarkReadService`.
- `ReadingActivity.php`, `ReadingActivityCounter.php`, `ReadingWindow.php`.

A file not in the first list gets the same rewrite as its siblings; record it in the task report. Any other difference: stop and report.

- [ ] **Step 1: Point the tests at the new home**

```bash
for test in EntryReadMarkerTest EntryStateResolverTest EntryStateUpdaterTest MarkReadServiceTest SearchMarkReadServiceTest; do
  git mv "tests/Service/Reader/$test.php" "tests/Service/Reading/$test.php"
done
perl -pi -e 's/^namespace App\\Tests\\Service\\Reader;$/namespace App\\Tests\\Service\\Reading;/' \
  tests/Service/Reading/EntryReadMarkerTest.php tests/Service/Reading/EntryStateResolverTest.php \
  tests/Service/Reading/EntryStateUpdaterTest.php tests/Service/Reading/MarkReadServiceTest.php \
  tests/Service/Reading/SearchMarkReadServiceTest.php
git grep -lE 'App\\Service\\Reader\\(EntryReadMarker|EntryStateResolver|EntryStateUpdater|EntryStateChange|MarkReadService|MarkEntriesReadService|SearchMarkReadService|SavedSearchMarkReadService)|App\\Service\\Recommendation\\Feed\\ForYouMarkReadService' -- tests \
  | xargs perl -pi -e 's/App\\Service\\Reader\\(EntryReadMarker|EntryStateResolver|EntryStateUpdater|EntryStateChange|MarkReadService|MarkEntriesReadService|SearchMarkReadService|SavedSearchMarkReadService)(?![A-Za-z0-9_])/App\\Service\\Reading\\$1/g; s/App\\Service\\Recommendation\\Feed\\ForYouMarkReadService(?![A-Za-z0-9_])/App\\Service\\Reading\\ForYouMarkReadService/g'
git grep -n 'App\\Service\\Reader\\' -- tests/Service/Reading
```
Expected: the perl rewrites the five moved tests (and nothing else under `tests` at `566ee103`); the last grep prints nothing.

In `tests/Service/Reading/EntryStateResolverTest.php`, replace
```php
    /**
     * The #496 concurrency bug: two requests touching the same duplicate group
     * both find no state row, both lazily create one, and the second flush dies
     * on the composite primary key. Here the concurrent winner commits the row
     * (through the idempotent insert) after this request already resolved it;
     * the fix reloads the winning row, so the flush issues only an UPDATE.
     */
```
with
```php
    /**
     * #496: a concurrent writer inserts the row after this request resolved it. resolve() reloads the winning
     * row, so the flush issues an UPDATE, not a duplicate-key INSERT.
     */
```

In `tests/Service/Reading/EntryStateUpdaterTest.php`, replace
```php
    /**
     * The persisted row, or null when a mirror that should have skipped this
     * sibling never created one. stateOf() cannot tell the two apart: its
     * unpersisted fallback reads all-false, same as a row that was created
     * but never written to.
     */
```
with
```php
    /**
     * Null when no row was ever created. stateOf() cannot tell that apart from a row created but never written
     * to: its unpersisted fallback reads all-false too.
     */
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Reading`
Expected: FAIL. The five moved classes error with `You have requested a non-existent service "App\Service\Reading\…"` (`EntryReadMarker`, `EntryStateResolver`, `EntryStateUpdater`, `MarkReadService`, `SearchMarkReadService`). `ReadingWindowTest` still passes.

- [ ] **Step 3: Move the classes**

```bash
for class in EntryReadMarker EntryStateResolver EntryStateUpdater EntryStateChange MarkReadService MarkEntriesReadService SearchMarkReadService SavedSearchMarkReadService; do
  git mv "src/Service/Reader/$class.php" "src/Service/Reading/$class.php"
done
git mv src/Service/Recommendation/Feed/ForYouMarkReadService.php src/Service/Reading/ForYouMarkReadService.php
```

Write each moved file in full.

`src/Service/Reading/EntryReadMarker.php`: the A1 file, with only its namespace line changed:
```php
namespace App\Service\Reading;
```
Its body, imports and comments are exactly A1's (they already meet the bar).

`src/Service/Reading/MarkReadService.php`: the A1 file, with only its namespace line changed to `namespace App\Service\Reading;`.

`src/Service/Reading/EntryStateChange.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

/** A partial state change: a null flag stays as it is. */
final readonly class EntryStateChange
{
    public function __construct(
        public ?bool $isHidden = null,
        public ?bool $isFavorite = null,
        public ?bool $isKept = null,
        public ?bool $isViewed = null,
    ) {
    }
}
```

`src/Service/Reading/EntryStateResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\EntryStateRepository;

/**
 * The one place a lazily created EntryState row comes into existence. It is seeded hidden when the watermark
 * already reads the entry, so no caller can flip a read entry back to unread; the bulk writers (EntryReadMarker,
 * RestoreEntryLoader) create rows read from birth instead.
 */
final readonly class EntryStateResolver
{
    public function __construct(
        private EntryStateRepository $states,
    ) {
    }

    /**
     * An idempotent insert then a reload, never ORM new+persist, which would race concurrent writers into a
     * duplicate-key flush (#496).
     */
    public function resolve(User $user, EntryListRow $row): EntryState
    {
        $userId = $user->requireId();
        $entryId = $row->entry->requireId();

        $existing = $this->states->findOneForUserEntry($userId, $entryId);
        if ($existing !== null) {
            return $existing;
        }

        $this->states->ensureRow($userId, $entryId, $row->isHidden ? $row->markedReadUntil : null);

        $created = $this->states->findOneForUserEntry($userId, $entryId);
        if ($created === null) {
            throw new \LogicException('ensureRow created the entry_state row, so the reload cannot be null.');
        }

        return $created;
    }
}
```

`src/Service/Reading/EntryStateUpdater.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Mirrors isHidden/isViewed onto every other subscribed copy of the same article (#496), so a collapse-hidden
 * duplicate cannot resurface as unread; isFavorite/isKept stay per copy.
 */
final readonly class EntryStateUpdater
{
    public function __construct(
        private EntryStateResolver $states,
        private EntryListRepository $rows,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function apply(User $user, EntryListRow $row, EntryStateChange $change): EntryState
    {
        $state = $this->states->resolve($user, $row);
        $this->applyTo($state, $change);
        $this->mirror($user, $row, $change);
        $this->em->flush();

        return $state;
    }

    private function applyTo(EntryState $state, EntryStateChange $change): void
    {
        if ($change->isHidden !== null) {
            // Unread also clears "opened" (EntryState::markUnread, #478), so the
            // rule reaches every client, not just the web app.
            $change->isHidden ? $state->hide($this->clock->now()) : $state->markUnread();
        }
        if ($change->isFavorite !== null) {
            $change->isFavorite ? $state->markFavorite() : $state->clearFavorite();
        }
        if ($change->isKept !== null) {
            $change->isKept ? $state->markKept() : $state->clearKept();
        }
        if ($change->isViewed !== null) {
            // markViewed sets only the viewed flag; ViewedImpliesHiddenListener
            // adds the hidden flag on flush. clearViewed leaves the entry hidden.
            $change->isViewed ? $state->markViewed($this->clock->now()) : $state->clearViewed();
        }
    }

    private function mirror(User $user, EntryListRow $row, EntryStateChange $change): void
    {
        if ($change->isHidden === null && $change->isViewed === null) {
            return;
        }
        $hash = $row->entry->getUrlHash();
        if ($hash === null) {
            return;
        }

        $siblings = $this->rows->siblingRowsForUser($user->requireId(), $hash, $row->entry->requireId());
        foreach ($siblings as $siblingRow) {
            $this->mirrorOnto($this->states->resolve($user, $siblingRow), $change);
        }
    }

    private function mirrorOnto(EntryState $sibling, EntryStateChange $change): void
    {
        // Same isHidden/isViewed invariants as applyTo() above (#478,
        // ViewedImpliesHiddenListener): mirroring must not sidestep them.
        if ($change->isHidden !== null) {
            $change->isHidden ? $sibling->hide($this->clock->now()) : $sibling->markUnread();
        }
        if ($change->isViewed !== null) {
            $change->isViewed ? $sibling->markViewed($this->clock->now()) : $sibling->clearViewed();
        }
    }
}
```
Checked at `566ee103`: every line below the class docblock is the landed #1182 code. The namespace and the class docblock are the only changes.

`src/Service/Reading/MarkEntriesReadService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryRepository;

final readonly class MarkEntriesReadService
{
    public function __construct(
        private EntryReadMarker $readMarker,
        private EntryRepository $entries,
    ) {
    }

    /** @param list<int> $entryIds */
    public function mark(User $user, array $entryIds): void
    {
        $this->readMarker->markEntriesRead($user->requireId(), $this->entries->findExistingIds($entryIds));
    }
}
```

`src/Service/Reading/SearchMarkReadService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;

/** Marks read every unread entry matching a search term. A search spans every feed, so no watermark scopes it. */
final readonly class SearchMarkReadService
{
    public function __construct(
        private EntryListRepository $entries,
        private EntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, string $rawQuery, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();

        $this->readMarker->markEntriesRead($userId, $this->entries->unreadMatchingEntryIdsForUser(
            new EntrySearchQuery($userId, SearchTerms::fromInput($rawQuery)),
            $until,
        ));
    }
}
```

`src/Service/Reading/SavedSearchMarkReadService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchRepository;

/**
 * Marks read every unread member of the caller's saved searches no newer than $until, the rows the combined
 * unread list shows (#1116). By entry state: a search spans feeds, so a watermark would leave the entry unread
 * in its feed list.
 */
final readonly class SavedSearchMarkReadService
{
    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchEntryRepository $entries,
        private EntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();
        $this->markSearches($userId, $this->savedSearches->idsForUser($userId), $until);
    }

    public function markOne(User $user, int $savedSearchId, \DateTimeImmutable $until): void
    {
        $this->markSearches($user->requireId(), [$savedSearchId], $until);
    }

    /** @param list<int> $searchIds */
    private function markSearches(int $userId, array $searchIds, \DateTimeImmutable $until): void
    {
        $this->readMarker->markEntriesRead($userId, $this->entries->unreadMemberIdsUpTo($userId, $searchIds, $until));
    }
}
```

`src/Service/Reading/ForYouMarkReadService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\RecommendationItemRepository;

/**
 * Marks the caller's for-you picks read by entry state only. A watermark would also mark read what All items
 * shows but the picks never did, and it emptied the recommendation candidate pool in #665.
 */
final readonly class ForYouMarkReadService
{
    public function __construct(
        private RecommendationItemRepository $items,
        private EntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();

        $this->readMarker->markEntriesRead($userId, $this->items->unreadEntryIdsForYou($userId, $until));
    }
}
```

The callers:
```bash
git grep -lE 'App\\Service\\Reader\\(EntryReadMarker|EntryStateResolver|EntryStateUpdater|EntryStateChange|MarkReadService|MarkEntriesReadService|SearchMarkReadService|SavedSearchMarkReadService)|App\\Service\\Recommendation\\Feed\\ForYouMarkReadService' -- src config \
  | xargs perl -pi -e 's/App\\Service\\Reader\\(EntryReadMarker|EntryStateResolver|EntryStateUpdater|EntryStateChange|MarkReadService|MarkEntriesReadService|SearchMarkReadService|SavedSearchMarkReadService)(?![A-Za-z0-9_])/App\\Service\\Reading\\$1/g; s/App\\Service\\Recommendation\\Feed\\ForYouMarkReadService(?![A-Za-z0-9_])/App\\Service\\Reading\\ForYouMarkReadService/g'
```
Expected files: `src/Controller/Api/EntryController.php`, `src/Controller/Api/EntrySearchController.php`, `src/Controller/Api/SavedSearchEntriesController.php`, `src/Dto/Entry/UpdateEntryStateRequest.php`.

`src/Controller/Api/EntryController.php`: the perl leaves the imports out of order. Replace
```php
use App\Service\Reading\EntryStateUpdater;
use App\Service\Reading\MarkEntriesReadService;
use App\Service\Reading\MarkReadService;
use App\Service\Recommendation\Feed\ForYouFeed;
use App\Service\Reading\ForYouMarkReadService;
```
with
```php
use App\Service\Reading\EntryStateUpdater;
use App\Service\Reading\ForYouMarkReadService;
use App\Service\Reading\MarkEntriesReadService;
use App\Service\Reading\MarkReadService;
use App\Service\Recommendation\Feed\ForYouFeed;
```

`CLAUDE.md` (from the repository root), in the Layout table, replace
```
| `backend/src/Service/**` | The domain work, one subdirectory per concern (`Fetch`, `Parser`, `Scraper`, `Reader`, `Refresh`, `OAuth`, `Auth`, `Opml`, `Preview`, `Discovery`, `Subscription`, `Mail`) |
```
with
```
| `backend/src/Service/**` | The domain work, one subdirectory per concern (`Fetch`, `Parser`, `Scraper`, `Reader`, `Reading`, `Refresh`, `OAuth`, `Auth`, `Opml`, `Preview`, `Discovery`, `Subscription`, `Mail`) |
```

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reading tests/Dto/Entry
php bin/phpunit tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntrySearchMarkReadTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Controller/Api/SavedSearchSlugRoutingTest.php tests/Repository/RecommendationFeedTest.php
```
Expected: PASS.

- [ ] **Step 5: Check the module edges**

```bash
git grep -n 'App\\Service\\Search' -- src/Service/Reader
git grep -n 'App\\Service\\Reader' -- src/Service/Recommendation src/Service/Reading
git grep -l EntryReadMarkRepository -- src
git grep -n 'App\\Service\\Reader\\' -- src/Service/Reading tests/Service/Reading
ls src/Service/Reader | grep -E 'Mark|EntryState'
git grep -n 'App\\Service\\Recommendation' -- src/Service/Reading
git grep -n 'App\\Service\\Reading' -- src/Service/Recommendation
```
Expected:
- The first, second and fourth commands print nothing: Reader no longer imports Search, and neither Recommendation nor Reading imports Reader.
- The third prints `src/Repository/EntryReadMarkRepository.php` and `src/Service/Reading/EntryReadMarker.php`.
- The `ls` prints nothing.
- The sixth prints exactly the two `ViewerTimeZone` imports (`ReadingActivityCounter.php`, `ReadingWindow.php`), and the seventh prints nothing: the one edge between the modules stays `Reading → Recommendation\Feed` (D1's note). Record both outputs in the task report.

- [ ] **Step 6: Gates and commit**

Run `composer check` and `composer md`, and run PhpStorm `lint_files` on every changed PHP file. Expected: green.
```bash
git add -A src/Service/Reader src/Service/Reading src/Service/Recommendation src/Controller/Api src/Dto/Entry tests/Service/Reader tests/Service/Reading ../CLAUDE.md
git status --short
git commit -m "refactor(#1163): reading state moves to service/reading"
```
If Step 0 listed a file outside these paths, `git add` it as well. Expected: `git status --short` shows only this task's renames and edits before the commit (the `git mv` renames are already staged).

---

### Task A3: `ReadScope`: `mark()` takes a typed scope

Ruling D-P1, home per D10.

**Files:**
- Create: `src/Service/Reading/ReadScopeKind.php`, `src/Service/Reading/ReadScope.php`
- Create: `tests/Service/Reading/ReadScopeTest.php`, `tests/Dto/Entry/MarkReadRequestTest.php`
- Modify: `src/Dto/Entry/MarkReadRequest.php` (rewritten in full), `src/Service/Reading/MarkReadService.php` (rewritten in full), `src/Controller/Api/EntryController.php` (one line)
- Modify: `tests/Service/Reading/MarkReadServiceTest.php` (imports, the `mark()` calls, two tests removed), `tests/Controller/Api/EntryControllerTest.php` (one test replaced by a pinned data-provider test)

**Interfaces:**
- Produces: `enum App\Service\Reading\ReadScopeKind: string { All = 'all'; Feed = 'feed'; Tag = 'tag'; }`.
- Produces: `final readonly class App\Service\Reading\ReadScope` with `public ReadScopeKind $kind`, `public ?int $id`, a private constructor, `static all(): self`, `static feed(int $subscriptionId): self`, `static tag(int $tagId): self` and `targetId(): int` (throws `\LogicException` for `all`).
- Produces: `App\Dto\Entry\MarkReadRequest::toScope(): ReadScope`, which throws `App\Exception\ValidationException` with `['scope' => ['Unknown scope "<scope>".']]` or `['id' => ['An id is required when scope is "<feed|tag>".']]`.
- Changes: `App\Service\Reading\MarkReadService::mark(User $user, ReadScope $scope, \DateTimeImmutable $until): void`. It no longer throws `ValidationException`.
- Wire: unchanged. `POST /api/entries/mark-read` answers the same `validation_error` bodies, pinned in Step 1.

- [ ] **Step 0: Verify this brief's anchors**

```bash
git grep -n 'markRead->mark(' -- src
git grep -nF 'public function mark(User $user, string $scope, ?int $id, \DateTimeImmutable $until): void' -- src/Service/Reading/MarkReadService.php
git grep -cF "mark(\$user, 'all', null, " -- tests/Service/Reading/MarkReadServiceTest.php
git grep -n 'function testMarkReadFeedScopeWithoutIdIsUniformValidationError\|function testFeedScopeWithoutIdIsRejected\|function testUnknownScopeIsRejected' -- tests
git grep -nF "#[Assert\Choice(choices: ['all', 'feed', 'tag'])]" -- src/Dto/Entry/MarkReadRequest.php
git grep -n 'ReadScope' -- src tests
ls tests/Dto/Entry
```
Expected:
- Two lines: `src/Controller/Api/EntryController.php`, `        $this->markRead->mark($user, $request->scope, $request->id, $request->until);` (this task changes it), and `src/Controller/Api/SavedSearchEntriesController.php`, `        $this->markRead->mark($user, $request->until);` (a different service, untouched).
- One hit for the signature (A1's file, moved by A2).
- The count `5`.
- Three functions: one in `tests/Controller/Api/EntryControllerTest.php`, two in `tests/Service/Reading/MarkReadServiceTest.php`.
- One `Assert\Choice` hit.
- No `ReadScope` hit.
- `UpdateEntryStateRequestTest.php` only.

Anything else: stop and report.

- [ ] **Step 1: Pin the wire first**

`tests/Controller/Api/EntryControllerTest.php`, replace
```php
    public function testMarkReadFeedScopeWithoutIdIsUniformValidationError(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-marknoid@example.com');
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['scope' => 'feed', 'until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
        // A missing required id reports the same validation_error the client
        // switches on for every other bad field — not a bare 400 request_error.
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('id', $body['errors']);
    }
```
with
```php
    /**
     * @param array<string, string>       $payload
     * @param array<string, list<string>> $errors
     */
    #[DataProvider('invalidMarkReadPayloads')]
    public function testMarkReadValidationErrorsKeepTheirKeysAndMessages(array $payload, array $errors): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('e-markinvalid@example.com');
        $client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload + ['until' => '2026-08-01T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);
        self::assertSame($errors, $body['errors']);
    }

    /** @return iterable<string, array{array<string, string>, array<string, list<string>>}> */
    public static function invalidMarkReadPayloads(): iterable
    {
        yield 'feed without an id' => [
            ['scope' => 'feed'],
            ['id' => ['An id is required when scope is "feed".']],
        ];
        yield 'tag without an id' => [
            ['scope' => 'tag'],
            ['id' => ['An id is required when scope is "tag".']],
        ];
        yield 'unknown scope' => [
            ['scope' => 'bogus'],
            ['scope' => ['The value you selected is not a valid choice.']],
        ];
    }
```
`DataProvider` is already imported. The unknown-scope message is Symfony's `Assert\Choice` default, which the DTO keeps.

Run: `php bin/phpunit tests/Controller/Api/EntryControllerTest.php --filter testMarkReadValidationErrorsKeepTheirKeysAndMessages`
Expected: PASS (pin), 3 tests. This is develop's wire as it stands. If a message differs, stop and report: the pin must record the wire, not the plan.

- [ ] **Step 2: Write the failing tests**

`tests/Service/Reading/ReadScopeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reading;

use App\Service\Reading\ReadScope;
use App\Service\Reading\ReadScopeKind;
use PHPUnit\Framework\TestCase;

final class ReadScopeTest extends TestCase
{
    public function testAllNamesNoSubscriptionOrTag(): void
    {
        $scope = ReadScope::all();

        self::assertSame(ReadScopeKind::All, $scope->kind);
        self::assertNull($scope->id);
    }

    public function testFeedCarriesItsSubscriptionId(): void
    {
        $scope = ReadScope::feed(7);

        self::assertSame(ReadScopeKind::Feed, $scope->kind);
        self::assertSame(7, $scope->id);
        self::assertSame(7, $scope->targetId());
    }

    public function testTagCarriesItsTagId(): void
    {
        $scope = ReadScope::tag(9);

        self::assertSame(ReadScopeKind::Tag, $scope->kind);
        self::assertSame(9, $scope->id);
        self::assertSame(9, $scope->targetId());
    }

    public function testAnAllScopeHasNoTargetId(): void
    {
        $this->expectException(\LogicException::class);

        ReadScope::all()->targetId();
    }
}
```

`tests/Dto/Entry/MarkReadRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Entry;

use App\Dto\Entry\MarkReadRequest;
use App\Exception\ValidationException;
use App\Service\Reading\ReadScopeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkReadRequestTest extends TestCase
{
    public function testAllIgnoresAnId(): void
    {
        $scope = $this->request('all', 5)->toScope();

        self::assertSame(ReadScopeKind::All, $scope->kind);
        self::assertNull($scope->id);
    }

    public function testFeedCarriesItsId(): void
    {
        $scope = $this->request('feed', 5)->toScope();

        self::assertSame(ReadScopeKind::Feed, $scope->kind);
        self::assertSame(5, $scope->id);
    }

    public function testTagCarriesItsId(): void
    {
        $scope = $this->request('tag', 6)->toScope();

        self::assertSame(ReadScopeKind::Tag, $scope->kind);
        self::assertSame(6, $scope->id);
    }

    #[DataProvider('scopesThatNeedAnId')]
    public function testAFeedOrTagScopeWithoutAnIdIsAValidationError(string $scope, string $message): void
    {
        $this->assertRejectedWith(['id' => [$message]], $this->request($scope, null));
    }

    /** @return iterable<string, array{string, string}> */
    public static function scopesThatNeedAnId(): iterable
    {
        yield 'feed' => ['feed', 'An id is required when scope is "feed".'];
        yield 'tag' => ['tag', 'An id is required when scope is "tag".'];
    }

    public function testAnUnknownScopeIsAValidationError(): void
    {
        $this->assertRejectedWith(['scope' => ['Unknown scope "bogus".']], $this->request('bogus', null));
    }

    private function request(string $scope, ?int $id): MarkReadRequest
    {
        return new MarkReadRequest($scope, new \DateTimeImmutable('2026-07-10T00:00:00Z'), $id);
    }

    /** @param array<string, list<string>> $errors */
    private function assertRejectedWith(array $errors, MarkReadRequest $request): void
    {
        try {
            $request->toScope();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame($errors, $exception->errors);
        }
    }
}
```

`tests/Service/Reading/MarkReadServiceTest.php`:
- Replace
```php
use App\Exception\ValidationException;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Reading\MarkReadService;
```
with
```php
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Reading\MarkReadService;
use App\Service\Reading\ReadScope;
```
- Replace all five occurrences (replace_all) of `->mark($user, 'all', null, ` with `->mark($user, ReadScope::all(), `.
- Replace `        $this->service()->mark($user, 'feed', 999999, new \DateTimeImmutable('2026-07-10T00:00:00Z'));` with `        $this->service()->mark($user, ReadScope::feed(999999), new \DateTimeImmutable('2026-07-10T00:00:00Z'));`.
- Replace
```php
        $this->service()->mark($user, 'tag', $tag->requireId(), new \DateTimeImmutable('2026-07-25T00:00:00Z'));
```
with
```php
        $this->service()->mark(
            $user,
            ReadScope::tag($tag->requireId()),
            new \DateTimeImmutable('2026-07-25T00:00:00Z'),
        );
```
- Replace
```php
        $this->service()->mark($user, 'tag', $tag->requireId(), new \DateTimeImmutable('2026-07-10T00:00:00Z'));
```
with
```php
        $this->service()->mark(
            $user,
            ReadScope::tag($tag->requireId()),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
```
- Replace
```php
            'tag',
            $strangerTag->requireId(),
```
with
```php
            ReadScope::tag($strangerTag->requireId()),
```
- Replace
```php
            'feed',
            $excludedSub->requireId(),
```
with
```php
            ReadScope::feed($excludedSub->requireId()),
```
- Delete these two tests (the validation moved to `MarkReadRequest`; `MarkReadRequestTest` and the Step 1 pin cover it):
```php
    public function testFeedScopeWithoutIdIsRejected(): void
    {
        [$user] = $this->seed();
        $this->expectException(ValidationException::class);
        $this->service()->mark($user, 'feed', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
    }

    public function testUnknownScopeIsRejected(): void
    {
        [$user] = $this->seed();

        try {
            $this->service()->mark($user, 'bogus', null, new \DateTimeImmutable('2026-07-10T00:00:00Z'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['scope' => ['Unknown scope "bogus".']], $exception->errors);
        }
    }

```
- Check: `git grep -n "ValidationException\|'all', null\|'feed',\|'tag',\|'bogus'" -- tests/Service/Reading/MarkReadServiceTest.php` prints nothing.

Run: `php bin/phpunit tests/Service/Reading/ReadScopeTest.php tests/Dto/Entry/MarkReadRequestTest.php tests/Service/Reading/MarkReadServiceTest.php`
Expected: FAIL. `Class "App\Service\Reading\ReadScope" not found` (and `ReadScopeKind`), and `Call to undefined method App\Dto\Entry\MarkReadRequest::toScope()`.

- [ ] **Step 3: Implement**

`src/Service/Reading/ReadScopeKind.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

enum ReadScopeKind: string
{
    case All = 'all';
    case Feed = 'feed';
    case Tag = 'tag';
}
```

`src/Service/Reading/ReadScope.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

final readonly class ReadScope
{
    private function __construct(
        public ReadScopeKind $kind,
        public ?int $id,
    ) {
    }

    public static function all(): self
    {
        return new self(ReadScopeKind::All, null);
    }

    public static function feed(int $subscriptionId): self
    {
        return new self(ReadScopeKind::Feed, $subscriptionId);
    }

    public static function tag(int $tagId): self
    {
        return new self(ReadScopeKind::Tag, $tagId);
    }

    public function targetId(): int
    {
        return $this->id ?? throw new \LogicException('An "all" scope names no subscription or tag.');
    }
}
```

`src/Dto/Entry/MarkReadRequest.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use App\Exception\ValidationException;
use App\Service\Reading\ReadScope;
use App\Service\Reading\ReadScopeKind;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkReadRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['all', 'feed', 'tag'])]
        public string $scope,
        public \DateTimeImmutable $until,
        #[Assert\Positive]
        public ?int $id = null,
    ) {
    }

    public function toScope(): ReadScope
    {
        $kind = ReadScopeKind::tryFrom($this->scope)
            ?? throw new ValidationException(['scope' => [sprintf('Unknown scope "%s".', $this->scope)]]);

        return match ($kind) {
            ReadScopeKind::All => ReadScope::all(),
            ReadScopeKind::Feed => ReadScope::feed($this->requiredIdFor($kind)),
            ReadScopeKind::Tag => ReadScope::tag($this->requiredIdFor($kind)),
        };
    }

    private function requiredIdFor(ReadScopeKind $kind): int
    {
        return $this->id ?? throw new ValidationException([
            'id' => [sprintf('An id is required when scope is "%s".', $kind->value)],
        ]);
    }
}
```

`src/Service/Reading/MarkReadService.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;

/** "Mark all read until T" for a scope: every subscription the scope covers is marked by watermark. */
final readonly class MarkReadService
{
    public function __construct(
        private EntryReadMarker $readMarker,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
    ) {
    }

    public function mark(User $user, ReadScope $scope, \DateTimeImmutable $until): void
    {
        $userId = $user->requireId();
        $this->readMarker->markSubscriptionsReadUntil($userId, $this->subscriptionsIn($userId, $scope), $until);
    }

    /** @return list<Subscription> */
    private function subscriptionsIn(int $userId, ReadScope $scope): array
    {
        return match ($scope->kind) {
            ReadScopeKind::All => $this->includedInAllItems($this->subscriptions->findForUserWithTags($userId)),
            ReadScopeKind::Feed => [$this->subscriptions->getOneForUser($userId, $scope->targetId())],
            ReadScopeKind::Tag => $this->subscriptions->findForUserByTagId(
                $userId,
                $this->tags->getOneForUser($userId, $scope->targetId())->requireId(),
            ),
        };
    }

    /**
     * Scope "all" mirrors what the All-items list shows, so a feed hidden from
     * it must not have its watermark advanced or its entries flipped read.
     *
     * @param  list<Subscription> $subscriptions
     * @return list<Subscription>
     */
    private function includedInAllItems(array $subscriptions): array
    {
        return array_values(array_filter(
            $subscriptions,
            static fn (Subscription $subscription): bool => $subscription->isIncludeInAllItems(),
        ));
    }
}
```

`src/Controller/Api/EntryController.php`: replace `        $this->markRead->mark($user, $request->scope, $request->id, $request->until);` with `        $this->markRead->mark($user, $request->toScope(), $request->until);`.

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reading tests/Dto/Entry
php bin/phpunit tests/Controller/Api/EntryControllerTest.php
git grep -n 'string \$scope\|ValidationException' -- src/Service/Reading
```
Expected: PASS, including the Step 1 pin unchanged; the grep prints nothing.

- [ ] **Step 5: Deletion checks**

Break each line, run the named test, and restore it by hand:
1. In `MarkReadRequest::toScope()`, change the `ReadScopeKind::Feed` arm to `ReadScope::tag($this->requiredIdFor($kind))`. Expected: `MarkReadRequestTest::testFeedCarriesItsId` fails.
2. In `requiredIdFor()`, change `'An id is required when scope is "%s".'` to `'An id is required for scope "%s".'`. Expected: `MarkReadRequestTest::testAFeedOrTagScopeWithoutAnIdIsAValidationError` and the two id cases of `EntryControllerTest::testMarkReadValidationErrorsKeepTheirKeysAndMessages` fail.
3. In `toScope()`, replace `ReadScopeKind::tryFrom($this->scope)` with `ReadScopeKind::tryFrom('all')`. Expected: `MarkReadRequestTest::testAnUnknownScopeIsAValidationError` fails.
4. In `ReadScope::targetId()`, replace `?? throw new \LogicException('An "all" scope names no subscription or tag.')` with `?? 0`. Expected: `ReadScopeTest::testAnAllScopeHasNoTargetId` fails.
5. In `MarkReadService::subscriptionsIn()`, change the `ReadScopeKind::Feed` arm to `$this->subscriptions->findForUserWithTags($userId)`. Expected: `MarkReadServiceTest::testFeedScopeRequiresOwnership` fails.

- [ ] **Step 6: Gates and commit**

Run `composer check` and `composer md`, and run PhpStorm `lint_files` on every changed PHP file. Expected: green (a `ReadScope` `AutowireWrongClass` false positive gets the suppression the Global Constraints describe).
```bash
git add src/Service/Reading/ReadScopeKind.php src/Service/Reading/ReadScope.php src/Service/Reading/MarkReadService.php \
  src/Dto/Entry/MarkReadRequest.php src/Controller/Api/EntryController.php \
  tests/Service/Reading/ReadScopeTest.php tests/Service/Reading/MarkReadServiceTest.php \
  tests/Dto/Entry/MarkReadRequestTest.php tests/Controller/Api/EntryControllerTest.php
git commit -m "refactor(#1163): mark-read takes a typed read scope"
```

---

### Task A4: `ExactSetGuard` moves to `Service/Tag`

Ruling D-P5, home per D11.

**Files:**
- Move (rewritten in full, comments brought to the bar per D9): `src/Service/Reader/ExactSetGuard.php` → `src/Service/Tag/ExactSetGuard.php`
- Move (perl on namespace and import): `tests/Service/Reader/ExactSetGuardTest.php` → `tests/Service/Tag/ExactSetGuardTest.php`
- Modify: `src/Service/Tag/TagOrdering.php` (one import removed: same namespace now)

**Interfaces:**
- Produces: `App\Service\Tag\ExactSetGuard::assertPermutation(list<int> $requested, list<int> $owned, string $message): void`, unchanged apart from the namespace.

- [ ] **Step 0: Verify this brief's anchors**

```bash
git grep -ln ExactSetGuard -- src tests config
git grep -n 'use App\\Service\\Reader\\ExactSetGuard;' -- src tests
git grep -n '^namespace' -- tests/Service/Reader/ExactSetGuardTest.php
ls tests/Service/Tag
```
Expected:
- 3 files: `src/Service/Reader/ExactSetGuard.php`, `src/Service/Tag/TagOrdering.php`, `tests/Service/Reader/ExactSetGuardTest.php`. A consumer outside `Service/Tag` means D11 no longer holds: stop and report.
- Two import lines: `src/Service/Tag/TagOrdering.php` and `tests/Service/Reader/ExactSetGuardTest.php`.
- `namespace App\Tests\Service\Reader;`.
- `TagEditorTest.php`, `TagOrderingTest.php`.

- [ ] **Step 1: Point the test at the new home**

```bash
git mv tests/Service/Reader/ExactSetGuardTest.php tests/Service/Tag/ExactSetGuardTest.php
perl -pi -e 's/^namespace App\\Tests\\Service\\Reader;$/namespace App\\Tests\\Service\\Tag;/; s/^use App\\Service\\Reader\\ExactSetGuard;$/use App\\Service\\Tag\\ExactSetGuard;/' \
  tests/Service/Tag/ExactSetGuardTest.php
git grep -n 'Reader' -- tests/Service/Tag/ExactSetGuardTest.php
```
Expected: the grep prints nothing.

Run: `php bin/phpunit tests/Service/Tag/ExactSetGuardTest.php`
Expected: FAIL with `Class "App\Service\Tag\ExactSetGuard" not found`.

- [ ] **Step 2: Move the class**

```bash
git mv src/Service/Reader/ExactSetGuard.php src/Service/Tag/ExactSetGuard.php
```

`src/Service/Tag/ExactSetGuard.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Exception\InvalidSelectionException;

/** A reorder must list exactly the set it reorders: a missing, extra or repeated id leaves the positions ambiguous. */
final readonly class ExactSetGuard
{
    /**
     * @param list<int> $requested
     * @param list<int> $owned
     */
    public function assertPermutation(array $requested, array $owned, string $message): void
    {
        // $owned comes from map keys (unique), so once both are sorted a plain
        // equality rejects missing ids, extras, AND duplicates in $requested.
        $sortedRequested = array_map('intval', $requested);
        sort($sortedRequested);
        $sortedOwned = array_map('intval', $owned);
        sort($sortedOwned);

        if ($sortedRequested !== $sortedOwned) {
            throw new InvalidSelectionException($message);
        }
    }
}
```
The method docblock's prose repeated the class docblock and is folded into it; the inline comment states an invariant the code cannot, so it stays.

`src/Service/Tag/TagOrdering.php`: delete the line `use App\Service\Reader\ExactSetGuard;`.

- [ ] **Step 3: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Tag tests/Controller/Api/ReorderTest.php
git grep -ln ExactSetGuard -- src tests config
git grep -n ExactSetGuard -- src/Service/Reader tests/Service/Reader
```
Expected:
- PASS.
- 3 files: `src/Service/Tag/ExactSetGuard.php`, `src/Service/Tag/TagOrdering.php`, `tests/Service/Tag/ExactSetGuardTest.php`.
- The last grep prints nothing.

No new test, so no deletion check: the test moved with its class unchanged.

- [ ] **Step 4: Gates and commit**

Run `composer check` and `composer md`, and run PhpStorm `lint_files` on the three changed PHP files. Expected: green.
```bash
git add -A src/Service/Reader/ExactSetGuard.php src/Service/Tag tests/Service/Reader/ExactSetGuardTest.php tests/Service/Tag
git commit -m "refactor(#1163): the exact-set guard moves to service/tag, its one consumer"
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
- Before the MySQL leg, check that the containers are current (memory "Check the container is current").
- An escaped mutant on a touched line gets a killing test in the task that owns the line (Global Constraints, Infection).
- Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no deprecation or error from a file this PR touched.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'`. ERROR and WARNING block.

- [ ] **Step 3: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`. It runs its angle reviewers (reuse, simplification, efficiency, altitude) as parallel agents. Apply only fixes that keep every gate green and undo no ruling in this plan (D1, D10, D11, D-P1, D-P5, D-reconcile-1). Re-run Step 1's gates if anything changed, and commit as `refactor(#1163): simplify pass`.

- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Watermark behaviour, byte for byte:** `EntryReadMarker::markSubscriptionsReadUntil()` must advance only forward, compute `hiddenAt` from the clock inside the transaction, and flush the watermark changes in the same transaction as the read-flip, exactly as `MarkReadService` did.
2. **One marker:** `git grep -l EntryReadMarkRepository -- src` lists only the repository and `EntryReadMarker`; no mark-read service touches `EntityManagerInterface`, `ReadMarking` or the clock.
3. **Module edges:** Step 5 of A2 holds; nothing in `Service/Reading` imports `Service/Reader`; the only `Reading → Recommendation` imports are the two `Feed\ViewerTimeZone` ones that were there before (D1's note), and `Recommendation` imports nothing from `Reading`; `ExactSetGuard` lives in `Service/Tag` and nothing in `Service/Reader` names it.
4. **#1182's `EntryStateChange`/`EntryStateUpdater`** are moved with their code unchanged apart from the namespace and the class docblock.
5. **`ReadScope`:** no invalid scope can be built (private constructor; `feed`/`tag` take an `int`); `MarkReadService` neither validates nor throws `ValidationException`; `MarkReadRequest::toScope()` produces the old keys and messages byte for byte, and `EntryControllerTest::testMarkReadValidationErrorsKeepTheirKeysAndMessages` is unchanged since A3's Step 1.
6. **Comment bar:** every moved class's comments are one to three lines and each stops a future reader from getting the code wrong.
7. **Wire:** `git diff origin/develop --stat -- src/Http` is empty, and the controllers change only imports, plus `EntryController::markRead()`'s one call (`$request->toScope()`).
8. **No closing keyword** for #1163 in any commit message on this branch.

Fix each finding it rates Important or above in its own commit (`refactor(#1163): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin refactor/1163-reading-state-module
git log origin/develop..HEAD --format=%B | grep -inE '(close|fix|resolve)[sd]?\b' && echo 'STOP: closing keyword in a commit' || true
gh pr create --base develop --title "refactor(#1163): reading state moves to service/reading behind one read marker" --body "$(cat <<'BODY'
Refs #1163 (PR A of three).

- `Service/Reading` now owns reading state: the five mark-read services, `EntryStateResolver`, `EntryStateUpdater` and `EntryStateChange` joined the reading-activity classes. `Recommendation/Feed/ForYouMarkReadService` moved there too.
- One marker: `EntryReadMarker` (formerly `BulkEntryReadMarker`) is the only caller of `EntryReadMarkRepository`. It marks either by subscription watermark (`markSubscriptionsReadUntil`, the transaction `MarkReadService` ran inline) or by entry state (`markEntriesRead`). All five services go through it.
- `Service/Reader` no longer imports `Service/Search`, and `Service/Recommendation` no longer imports `Service/Reader`. `Service/Reading` still imports `Recommendation\Feed\ViewerTimeZone`, as before; its home is left to #1169.
- `MarkReadService::mark(User, ReadScope, \DateTimeImmutable)`: `ReadScope` (`all()`, `feed(int)`, `tag(int)`) replaces the string scope and nullable id. `MarkReadRequest::toScope()` builds it and throws the same `validation_error` keys and messages as before, which a controller test now pins.
- `ExactSetGuard` moved from `Service/Reader` to `Service/Tag`, its one consumer.

No wire change and no reader-output change.
BODY
)"
gh pr view --json body --jq .body | grep -inE '(close|fix|resolve)[sd]?\b' && echo 'STOP: closing keyword in the body' || true
```
Expected: neither `STOP` line prints.

- [ ] **Step 6: Merge when green**

Watch the checks with the Monitor tool, as one command with no loop: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`: it merges immediately. On a failure:
- read the failing job (`gh run view --log-failed`),
- if only the tramp step fails, run `composer show larspohlmann/phptramp` first: CI runs phptramp's `develop` tip,
- fix on the branch, push, and watch again.

- [ ] **Step 7: Verify the issue stayed open**

Run: `gh issue view 1163 --json state --jq .state`. Expected: `OPEN`. If it closed, reopen it and report: a closing keyword slipped in.

---

### Execution rulings (PR A)

- A2 added `EntryStateResolverTest::testResolveSkipsTheInsertWhenTheRowAlreadyExists` (out of brief) to kill an escaped mutant on the resolver's early return; it counts statements with the existing `QueryRecorder`.
- Escaped mutant `UnwrapArrayValues` on `MarkReadService::includedInAllItems()` accepted as equivalent: the line predates #1163, and its one consumer iterates key-agnostically. Dropping `array_values` would break the `list<Subscription>` type, so it cannot be rewritten away.
- Final review fix wave: the `EntryReadMarker` docblock no longer calls it the one way entries are marked read (`EntryStateUpdater` also marks). `ReadScope::$id` is private, so tests assert `targetId()`. `MarkReadRequest`'s `Assert\Choice` lists `ReadScopeKind` values. A new test moves an earlier watermark forward, because dropping `|| $current < $until` passed every test.
- The simplify pass's other finding is pre-existing and left for a follow-up: scope `all` fetch-joins every subscription's tags and then filters `includeInAllItems` in PHP.
- The real run (#1162's recommendation run) is not a gate here. PR A moves `ForYouMarkReadService` unchanged and does not touch the run pipeline.

---

# PR B — Parse failure is typed; the body cleaner is an ordered pipeline

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR A merged and cut the branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log origin/develop --oneline | grep -c '(#1163)'
gh issue view 1163 --json state --jq .state
git switch -c refactor/1163-body-cleaning-pipeline origin/develop
ls backend/src/Service/Reading/EntryReadMarker.php
```
Expected: a non-zero count, `OPEN`, and the file listed. Anything else: stop and report.

- [ ] **Step 2: Re-take the PR B sites (from `backend/`)**

```bash
git grep -n "parseOrNull(" -- src
git grep -n "?HTMLDocument" -- src/Service/Reader
git grep -n "fromDocument(null)" -- tests
git grep -n "bodyCleaner->clean(\|cleaner->clean(\|cleaner()->clean(" -- src tests | cut -d: -f1 | sort | uniq -c
git grep -n "new ReaderBodyCleaner(" -- src tests
```
Expected at `566ee103` plus PR A (PR A touched none of these files; the counts were re-read at `566ee103` and match the draft's):
- `parseOrNull(`, 11 lines:
  - `FeedLinkScanner`, `WordPressRestProbe`, `ItemImageExtractor`, `ExtractionCoverageGate`, `FetchedPageNormalizer`, `HeroImageSelector`, `RawPage`, `MetaRefreshTarget`, `ReaderBodyCleaner`, `ExtractedBody`;
  - plus the definition in `HtmlDocumentParser`.
- `?HTMLDocument`, 11 lines: `ArticleExtractor` (2), `ArticleReadability` (3), `FetchedPageNormalizer` (3), `LeadFigureCaptions`, `PageImageInventory`, `PaywallSignals`.
- `fromDocument(null)`, 10 lines: `LeadFigureCaptionsTest` (1), `PageImageInventoryTest` (1), `ReaderBodyCleanerTest` (4), `ReaderLeadImageTest` (1), `SlideshowExtractionTest` (3).
- `clean(` callers: `src/Service/Reader/ArticleExtractor.php` (1), `tests/Service/Reader/ReaderBodyCleanerTest.php` (23), `tests/Service/Reader/Slideshow/SlideshowExtractionTest.php` (3).
- `new ReaderBodyCleaner(`: `ArticleExtractorTest`, `ReaderBodyCleanerTest`, `SlideshowExtractionTest`.

- [ ] **Step 3: Anchors and cross-task interfaces (read-only; from `backend/`)**

No dry run: apply no edit, run no test. Read, compare, report.
1. For every "replace … with" block and every inline "Replace `…`" in B1–B6 whose target is develop code (not text an earlier B task writes), check the "before" text still occurs verbatim at the branch's starting SHA. `git grep -n` its first distinctive line, or `git show HEAD:backend/<file>`. At `566ee103` all of them held; the ones that do not exist yet are B-internal (for example B4's `$input->media` block, which B3 writes).
2. The `services.yaml` anchor B6 replaces:
   ```bash
   git grep -n -A1 -F "HorizontalRuleUnwrapper'" -- config/services.yaml
   ```
   Expected: `                - '@App\Service\Reader\Repair\HorizontalRuleUnwrapper'` followed by `    App\Service\Search\EntrySearchInterface: '@App\Service\Search\EntrySearchWithFallback'`.
3. Cross-task interfaces: each B task's **Interfaces** "Produces" line matches the signatures later B tasks call. `BodyCleaningInput`'s constructor (B3) against `BodyCleaningInputs` and `ArticleExtractor` (B3, B6), `BodyCleaningStep::cleanIn(BodyCleaningPass)` (B5) against the `$steps` list and `ReaderBodyCleaner` (B6), `ReaderLeadImage::restore()` without its flag (B4) against `PageMediaPlacement` (B6), and `HtmlDocumentParser::parse()` (B1) against `FetchedPageNormalizer` (B2) and `ReaderBodyCleaner` (B6).

Record the result in the task report. A drifted anchor or a mismatched interface: stop and report.

---

### Task B1: `HtmlDocumentParser::parse()` throws `UnparseableHtmlException`

**Files:**
- Create: `src/Service/Html/Exception/UnparseableHtmlException.php`
- Modify: `src/Service/Html/HtmlDocumentParser.php` (rewritten in full)
- Modify: `tests/Service/Html/HtmlDocumentParserTest.php` (imports; three tests added)

**Interfaces:**
- Produces: `App\Service\Html\HtmlDocumentParser::parse(string $html): HTMLDocument`, which throws `App\Service\Html\Exception\UnparseableHtmlException` for blank or unparseable input. `parseOrNull()` is unchanged (D6).

- [ ] **Step 1: Write the failing tests**

In `tests/Service/Html/HtmlDocumentParserTest.php`, replace
```php
use App\Service\Html\HtmlDocumentParser;
use PHPUnit\Framework\TestCase;
```
with
```php
use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Html\HtmlDocumentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
```
and add, before the class's closing `}`, after `testBlankInputYieldsNull()`:
```php

    public function testParseHandsBackTheDocument(): void
    {
        $document = HtmlDocumentParser::parse('<html lang="en"><body><p>Body</p></body></html>');

        self::assertSame('Body', $document->querySelector('p')?->textContent);
    }

    #[DataProvider('blankHtml')]
    public function testParseRefusesBlankInput(string $html): void
    {
        $this->expectException(UnparseableHtmlException::class);
        $this->expectExceptionMessage('The HTML is blank or could not be parsed.');

        HtmlDocumentParser::parse($html);
    }

    /** @return iterable<string, array{string}> */
    public static function blankHtml(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \n\t "];
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Html/HtmlDocumentParserTest.php`
Expected: FAIL. The three new cases error with `Call to undefined method App\Service\Html\HtmlDocumentParser::parse()`; the three old tests pass.

- [ ] **Step 3: Implement**

`src/Service/Html/Exception/UnparseableHtmlException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Html\Exception;

final class UnparseableHtmlException extends \RuntimeException
{
}
```

`src/Service/Html/HtmlDocumentParser.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Html;

use App\Service\Html\Exception\UnparseableHtmlException;
use Dom\HTMLDocument;

/**
 * Parses HTML into the HTML5 DOM (`\Dom\HTMLDocument`, lexbor) the reader, discovery and scraper read. It
 * resolves no entities and opens no connections, so it needs no LIBXML_NONET, which it rejects as a flag.
 */
final class HtmlDocumentParser
{
    public static function parse(string $html): HTMLDocument
    {
        return self::parseOrNull($html)
            ?? throw new UnparseableHtmlException('The HTML is blank or could not be parsed.');
    }

    public static function parseOrNull(string $html): ?HTMLDocument
    {
        if (trim($html) === '') {
            return null;
        }

        try {
            return HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Service/Html`
Expected: PASS.

- [ ] **Step 5: Deletion check**

Replace the whole `?? throw new UnparseableHtmlException(...)` line with `?? HTMLDocument::createEmpty();` and run `php bin/phpunit tests/Service/Html/HtmlDocumentParserTest.php`. Expected: both `testParseRefusesBlankInput` cases fail. Restore the line by hand.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on the three files. Expected: green.
```bash
git add src/Service/Html/Exception/UnparseableHtmlException.php src/Service/Html/HtmlDocumentParser.php tests/Service/Html/HtmlDocumentParserTest.php
git commit -m "refactor(#1163): html parsing that must succeed throws a typed exception"
```

---

### Task B2: The extractor stops once on an unparseable page; downstream takes a document

**Files:**
- Modify (rewritten in full): `src/Service/Reader/FetchedPageNormalizer.php`, `src/Service/Reader/ArticleReadability.php`, `src/Service/Reader/PageImageInventory.php`, `src/Service/Reader/LeadFigureCaptions.php`, `src/Service/Reader/Paywall/PaywallSignals.php`
- Modify: `src/Service/Reader/ArticleExtractor.php` (imports, the normalise block, two helpers deleted)
- Modify (tests): `tests/Service/Reader/FetchedPageNormalizerTest.php`, `PageImageInventoryTest.php`, `LeadFigureCaptionsTest.php`, `Paywall/PaywallSignalsTest.php`, `ReaderLeadImageTest.php`, `ReaderBodyCleanerTest.php`, `Slideshow/SlideshowExtractionTest.php`, `ArticleExtractorTest.php`

**Interfaces:**
- `FetchedPageNormalizer::normalize(string $html): HTMLDocument` throws `UnparseableHtmlException`. `collapseWrapperChains(string $html): ?HTMLDocument` still returns `null` when there is no chain.
- `PageImageInventory::fromDocument(HTMLDocument)`, `LeadFigureCaptions::fromDocument(HTMLDocument)`, `PaywallSignals::isPreview(HTMLDocument $rawDocument, HTMLDocument $normalized)`, `ArticleReadability::richest(HTMLDocument $normalized, PageResponse $page, list<ContainerSignature> $slideshowContainers): ?Article`.
- `ArticleExtractor::extract()`: a page that cannot be parsed returns `ExtractionResult::failed($url, 'unextractable')`, exactly what it returned before, without running the page scans or readability.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Reader/FetchedPageNormalizerTest.php`:
- Replace `use App\Service\Html\PictureSources;` with
```php
use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Html\PictureSources;
```
- Replace `use PHPUnit\Framework\TestCase;` with
```php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
```
- Replace both occurrences (replace_all) of
```php
        $normalized = $this->normalizer->normalize($html)?->saveHtml() ?? '';
```
with
```php
        $normalized = $this->normalizer->normalize($html)->saveHtml();
```
- Replace
```php
    public function testEmptyInputYieldsNull(): void
    {
        self::assertNull($this->normalizer->normalize(''));
        self::assertNull($this->normalizer->normalize('   '));
    }
```
with
```php
    #[DataProvider('blankPages')]
    public function testABlankPageIsUnparseable(string $html): void
    {
        $this->expectException(UnparseableHtmlException::class);

        $this->normalizer->normalize($html);
    }

    /** @return iterable<string, array{string}> */
    public static function blankPages(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }
```
- Replace
```php
    /** normalize() then serialize; the fixtures under test always parse. */
    private function normalized(string $html): string
    {
        $document = $this->normalizer->normalize($html);
        self::assertNotNull($document);

        return $document->saveHtml();
    }
```
with
```php
    private function normalized(string $html): string
    {
        return $this->normalizer->normalize($html)->saveHtml();
    }
```

`tests/Service/Reader/PageImageInventoryTest.php`:
- Replace `return PageImageInventory::fromDocument(HtmlDocumentParser::parseOrNull($html));` with `return PageImageInventory::fromDocument(HtmlDocumentParser::parse($html));`.
- Delete
```php
    public function testANullDocumentDrawsNothing(): void
    {
        $inventory = PageImageInventory::fromDocument(null);

        self::assertFalse($inventory->draws(ImageIdentity::fromUrl('https://cdn.test/hero-photo.jpg')));
    }

```

`tests/Service/Reader/LeadFigureCaptionsTest.php`:
- Replace `return LeadFigureCaptions::fromDocument(HtmlDocumentParser::parseOrNull($html));` with `return LeadFigureCaptions::fromDocument(HtmlDocumentParser::parse($html));`.
- Delete
```php
    public function testANullDocumentYieldsAnEmptyInstance(): void
    {
        $captions = LeadFigureCaptions::fromDocument(null);

        self::assertNull($captions->captionFor('https://cdn.test/hero-photo.jpg'));
    }

```

`tests/Service/Reader/Paywall/PaywallSignalsTest.php`:
- Replace `return PaywallSignals::isPreview($this->rawPage($html), HtmlDocumentParser::parseOrNull($html));` with `return PaywallSignals::isPreview($this->rawPage($html), HtmlDocumentParser::parse($html));`.
- Delete the test whose `null` document can no longer be passed. `testAPremiumDeclarationFlagsAPreview` still pins "the declaration decides without a gated block".
```php
    public function testWithoutADocumentTheDeclarationStillDecides(): void
    {
        $premium = '<script type="application/ld+json">{"isAccessibleForFree":"False"}</script>';

        self::assertFalse(PaywallSignals::isPreview($this->rawPage(''), null));
        self::assertTrue(PaywallSignals::isPreview($this->rawPage($premium), null));
    }

```

`tests/Service/Reader/ReaderLeadImageTest.php`:
- Replace `return PageImageInventory::fromDocument(HtmlDocumentParser::parseOrNull('<body>' . $images . '</body>'));` with `return PageImageInventory::fromDocument(HtmlDocumentParser::parse('<body>' . $images . '</body>'));`.
- Replace
```php
    private function pageDrawingNothing(): PageImageInventory
    {
        return PageImageInventory::fromDocument(null);
    }
```
with
```php
    private function pageDrawingNothing(): PageImageInventory
    {
        return $this->pageDrawing();
    }
```

`tests/Service/Reader/ReaderBodyCleanerTest.php` (B3 rewrites this file in full; these edits keep B2 green):
- Replace `use App\Service\Reader\AuthorBio\AuthorBioSeparator;` with
```php
use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
```
- Replace all four occurrences (replace_all) of `PageImageInventory::fromDocument(null)` with `$this->pageDrawingNothing()`.
- Then replace, as the `replace_all` above left it,
```php
    private function noLead(): LeadImageCandidate
    {
        return new LeadImageCandidate(null, $this->pageDrawingNothing());
    }
```
with
```php
    private function noLead(): LeadImageCandidate
    {
        return new LeadImageCandidate(null, $this->pageDrawingNothing());
    }

    private function pageDrawingNothing(): PageImageInventory
    {
        return PageImageInventory::fromDocument(HtmlDocumentParser::parse('<body></body>'));
    }
```

`tests/Service/Reader/Slideshow/SlideshowExtractionTest.php` (B3 rewrites it in full):
- Replace all three occurrences (replace_all) of `PageImageInventory::fromDocument(null)` with `$this->pageDrawingNothing()`.
- Replace
```php
    private function scanner(): SlideshowScanner
```
with
```php
    private function pageDrawingNothing(): PageImageInventory
    {
        return PageImageInventory::fromDocument(HtmlDocumentParser::parse('<body></body>'));
    }

    private function scanner(): SlideshowScanner
```

`tests/Service/Reader/ArticleExtractorTest.php`: add this pin directly after `testUnextractablePageMapsToReason()`'s closing brace:
```php

    public function testABlankPageStopsAsUnextractable(): void
    {
        $extractor = $this->extractor([new MockResponse('   ', ['http_code' => 200])]);

        $result = $extractor->extract('https://site.test/post');

        self::assertFalse($result->ok);
        self::assertSame('unextractable', $result->reason);
        self::assertNull($result->detail);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/FetchedPageNormalizerTest.php tests/Service/Reader/ArticleExtractorTest.php`
Expected:
- FAIL: both `testABlankPageIsUnparseable` cases fail with `Failed asserting that exception of type "App\Service\Html\Exception\UnparseableHtmlException" is thrown`.
- `testABlankPageStopsAsUnextractable` passes: it pins today's result, which the early exit must keep.
- `composer stan` also reports the nullsafe calls in the other edited tests until Step 3 lands.

- [ ] **Step 3: Implement**

`src/Service/Reader/FetchedPageNormalizer.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Repair\PageRepair;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Parses a fetched page once and runs the PageRepair pipeline over it, in the order services.yaml wires, before
 * readability scores it. <script>/<style> are cut from the raw source first, bounded by the real close tag the
 * tokenizer would use, to stay byte-identical to the pipeline this replaced.
 */
final readonly class FetchedPageNormalizer
{
    private const string SCRIPT_OR_STYLE_PATTERN = '#<(script|style)\b[^>]*>.*?</\1\s*>#is';

    /** @param iterable<PageRepair> $repairs */
    public function __construct(private iterable $repairs)
    {
    }

    /** @throws UnparseableHtmlException when the page is blank or cannot be parsed */
    #[WithSpan]
    public function normalize(string $html): HTMLDocument
    {
        return $this->repair($html);
    }

    /**
     * The page with single-child <div> wrapper chains collapsed (#235), or null when there is none. A fresh
     * parse, not normalize()'s document: readability consumes each document it reads, and the collapse
     * breaks some pages (#476).
     */
    public function collapseWrapperChains(string $html): ?HTMLDocument
    {
        $document = $this->repair($html);
        if ($this->unwrapSingleChildDivs($document) === 0) {
            return null;
        }

        return $document;
    }

    private function repair(string $html): HTMLDocument
    {
        $document = HtmlDocumentParser::parse($this->removeScriptAndStyleBlocks($html));
        foreach ($this->repairs as $repair) {
            $repair->repairIn($document);
        }

        return $document;
    }

    private function removeScriptAndStyleBlocks(string $html): string
    {
        return preg_replace(self::SCRIPT_OR_STYLE_PATTERN, '', $html) ?? $html;
    }

    private function unwrapSingleChildDivs(HTMLDocument $document): int
    {
        $divs = iterator_to_array($document->getElementsByTagName('div'));
        // Reverse document order visits descendants before their ancestors, so
        // one pass collapses a whole wrapper chain from the inside out.
        $collapsed = 0;
        foreach (array_reverse($divs) as $div) {
            $child = $this->soleDivChild($div);
            if ($child !== null && $div->parentNode !== null) {
                $div->parentNode->replaceChild($child, $div);
                ++$collapsed;
            }
        }

        return $collapsed;
    }

    private function soleDivChild(Element $div): ?Element
    {
        $soleElement = null;
        foreach ($div->childNodes as $child) {
            if ($child instanceof Element) {
                if ($soleElement !== null) {
                    return null;
                }
                $soleElement = $child;
            } elseif ($child instanceof Text && trim((string) $child->textContent) !== '') {
                return null;
            }
        }

        return $soleElement instanceof Element && $soleElement->localName === 'div' ? $soleElement : null;
    }
}
```
`src/Service/Reader/PageImageInventory.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Srcset;
use Dom\HTMLDocument;

/**
 * The URLs a normalised page draws, read before readability consumes the document (#684). It tells
 * ReaderLeadImage whether the page draws the lead or og:image is a meta-only share render; fingerprints are
 * computed lazily in draws(), stopping at the first match.
 */
final readonly class PageImageInventory
{
    /** @param list<string> $renderedUrls */
    private function __construct(private array $renderedUrls)
    {
    }

    public static function fromDocument(HTMLDocument $page): self
    {
        return new self(self::renderedUrls($page));
    }

    public function draws(ImageIdentity $lead): bool
    {
        foreach ($this->renderedUrls as $url) {
            if ($lead->matches(ImageIdentity::fromUrl($url))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every URL the page draws, in document order */
    private static function renderedUrls(HTMLDocument $page): array
    {
        $urls = [];
        foreach ($page->getElementsByTagName('img') as $image) {
            $source = trim($image->getAttribute('src') ?? '');
            if ($source !== '') {
                $urls[] = $source;
            }
        }
        foreach ($page->getElementsByTagName('source') as $source) {
            $first = Srcset::firstUrl($source->getAttribute('srcset'));
            if ($first !== null) {
                $urls[] = $first;
            }
        }

        return $urls;
    }
}
```

`src/Service/Reader/LeadFigureCaptions.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Text\Whitespace;
use App\Service\Url\AbsoluteHttpUrl;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Every figure's (image src, caption) pair, read from the normalised page before readability drops a header
 * figure as chrome (#684), so a restored lead gets its caption back. Fingerprints are computed lazily in
 * captionFor(), stopping at the first match.
 */
final readonly class LeadFigureCaptions
{
    /** @param list<array{url: string, caption: string}> $figures */
    private function __construct(private array $figures)
    {
    }

    public static function fromDocument(HTMLDocument $page): self
    {
        return new self(self::captionedFigures($page));
    }

    public function captionFor(?string $leadUrl): ?string
    {
        $leadUrl = AbsoluteHttpUrl::orNull($leadUrl);
        if ($leadUrl === null) {
            return null;
        }

        $lead = ImageIdentity::fromUrl($leadUrl);
        foreach ($this->figures as $figure) {
            if ($lead->isSameAsset(ImageIdentity::fromUrl($figure['url']))) {
                return $figure['caption'];
            }
        }

        return null;
    }

    /** @return list<array{url: string, caption: string}> */
    private static function captionedFigures(HTMLDocument $page): array
    {
        $figures = [];
        foreach ($page->getElementsByTagName('figure') as $figure) {
            $entry = self::captionedFigure($figure);
            if ($entry !== null) {
                $figures[] = $entry;
            }
        }

        return $figures;
    }

    /** @return ?array{url: string, caption: string} */
    private static function captionedFigure(Element $figure): ?array
    {
        $image = $figure->getElementsByTagName('img')->item(0);
        $source = trim($image?->getAttribute('src') ?? '');
        $captionElement = $figure->getElementsByTagName('figcaption')->item(0);
        if ($source === '' || $captionElement === null) {
            return null;
        }

        $caption = Whitespace::collapse($captionElement->textContent);

        return $caption !== '' ? ['url' => $source, 'caption' => $caption] : null;
    }
}
```

`src/Service/Reader/Paywall/PaywallSignals.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use Dom\HTMLDocument;

/**
 * The reader paywall verdict: the publisher's schema.org `isAccessibleForFree` declaration decides. A page that
 * declares nothing is a preview when a gated block (#908) or a membership checkout (#998) sits outside the page
 * furniture. Judged before readability consumes the normalised document.
 */
final readonly class PaywallSignals
{
    public static function isPreview(HTMLDocument $rawDocument, HTMLDocument $normalized): bool
    {
        return match (SchemaOrgAccess::declaredIn($rawDocument)) {
            AccessDeclaration::Paywalled => true,
            AccessDeclaration::Free => false,
            AccessDeclaration::Undeclared => self::gatedInBody($normalized),
        };
    }

    private static function gatedInBody(HTMLDocument $normalized): bool
    {
        return PaywallBlocks::foundOutsideFurnitureIn($normalized)
            || MembershipCheckout::foundOutsideFurnitureIn($normalized);
    }
}
```

`src/Service/Reader/ArticleReadability.php` (whole file). The collapsed variant is still extracted, so the second readability grab stays (settled). With a chain, the calls run in the old order; without one, only the old no-ops are skipped:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Slideshow\ContainerSignature;
use Dom\HTMLDocument;
use fivefilters\Readability\Article;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Runs readability over the normalised page and over its wrapper-collapsed variant (#235) and keeps the richer
 * extraction, since the collapse rescues block-component pages but breaks some well-structured ones (#476). The
 * frame keep-list comes from the embed providers, so every host the reader renders survives extraction (#1053).
 */
final readonly class ArticleReadability
{
    public function __construct(
        private FetchedPageNormalizer $normalizer,
        private RelatedTeaserGridRemover $teaserGridRemover,
        private EmbedProviders $embedProviders,
    ) {
    }

    /**
     * The conservative document arrives already normalised: the caller reads it before readability consumes
     * it (#684).
     *
     * @param list<ContainerSignature> $slideshowContainers
     */
    #[WithSpan]
    public function richest(HTMLDocument $normalized, PageResponse $page, array $slideshowContainers): ?Article
    {
        $collapsed = $this->normalizer->collapseWrapperChains($page->html);
        $this->teaserGridRemover->removeFrom($normalized, $slideshowContainers);
        if ($collapsed === null) {
            return $this->parse($normalized, $page->finalUrl);
        }
        $this->teaserGridRemover->removeFrom($collapsed, $slideshowContainers);

        return $this->richer($this->parse($normalized, $page->finalUrl), $this->parse($collapsed, $page->finalUrl));
    }

    private function parse(HTMLDocument $document, string $finalUrl): ?Article
    {
        $readability = new Readability(new Configuration(
            // EdgeBoilerplateTrimmer reads class/id fingerprints on this output
            // (#582); readability strips classes by default, which would make
            // that signal a permanent no-op.
            keepClasses: true,
            allowedVideoRegex: $this->embedProviders->videoEmbedRegex(),
            fixRelativeURLs: true,
            originalURL: $finalUrl,
        ));

        try {
            return $readability->parse($document);
        } catch (ParseException) {
            return null;
        }
    }

    /** Keep the extraction with more readable text; a tie keeps the conservative one. */
    private function richer(?Article $conservative, ?Article $collapsed): ?Article
    {
        if ($conservative === null) {
            return $collapsed;
        }
        if ($collapsed === null) {
            return $conservative;
        }

        return $this->textLength($collapsed) > $this->textLength($conservative)
            ? $collapsed
            : $conservative;
    }

    private function textLength(Article $article): int
    {
        return mb_strlen(trim((string) $article->textContent));
    }
}
```
`src/Service/Reader/ArticleExtractor.php`:
- Replace `use App\Service\Reader\Exception\PageFetchException;` with
```php
use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Reader\Exception\PageFetchException;
```
- Delete `use App\Service\Reader\Media\Teaser\TeaserPlayer;` and `use Dom\HTMLDocument;` (their only uses were the two helpers deleted below).
- Replace
```php
        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);
        $leadCaptions = LeadFigureCaptions::fromDocument($normalized);
        $rawPage = RawPage::parse($page->html, $page->finalUrl);
        $paywalled = PaywallSignals::isPreview($rawPage->document, $normalized);
        $media = $this->mediaScanner->scan($rawPage, $feedMedia);
        $slideshows = $this->slideshowsIn($normalized);
        $teasers = $this->teasersIn($normalized, $page->finalUrl);
```
with
```php
        try {
            $normalized = $this->normalizer->normalize($page->html);
        } catch (UnparseableHtmlException) {
            return ExtractionResult::failed($url, 'unextractable');
        }
        $pageImages = PageImageInventory::fromDocument($normalized);
        $leadCaptions = LeadFigureCaptions::fromDocument($normalized);
        $rawPage = RawPage::parse($page->html, $page->finalUrl);
        $paywalled = PaywallSignals::isPreview($rawPage->document, $normalized);
        $media = $this->mediaScanner->scan($rawPage, $feedMedia);
        $slideshows = $this->slideshowScanner->scan($normalized);
        $teasers = $this->teaserScanner->scan($normalized, $page->finalUrl);
```
- Delete
```php
    /** @return list<Slideshow> */
    private function slideshowsIn(?HTMLDocument $normalized): array
    {
        return $normalized === null ? [] : $this->slideshowScanner->scan($normalized);
    }

    /** @return list<TeaserPlayer> */
    private function teasersIn(?HTMLDocument $normalized, string $finalUrl): array
    {
        return $normalized === null ? [] : $this->teaserScanner->scan($normalized, $finalUrl);
    }

```

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reader tests/Service/Html
php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php
git grep -n "?HTMLDocument" -- src/Service/Reader
```
Expected:
- PASS. The fixture suites are unchanged.
- The grep prints one line: `FetchedPageNormalizer::collapseWrapperChains(): ?HTMLDocument`, where `null` means "no chain to collapse", not a failure.

- [ ] **Step 5: Deletion checks**

Break each line, run the named test, and restore it by hand:
1. In `ArticleExtractor::extract()`, replace `return ExtractionResult::failed($url, 'unextractable');` in the new `catch` with `return ExtractionResult::failed($url, 'fetch');`. Expected: `ArticleExtractorTest::testABlankPageStopsAsUnextractable` fails.
2. In `FetchedPageNormalizer::repair()`, replace `HtmlDocumentParser::parse($this->removeScriptAndStyleBlocks($html));` with `HtmlDocumentParser::parseOrNull($this->removeScriptAndStyleBlocks($html)) ?? HTMLDocument::createEmpty();`. Expected: both `FetchedPageNormalizerTest::testABlankPageIsUnparseable` cases fail.
3. In `PaywallSignals::isPreview()`, replace `self::gatedInBody($normalized)` with `false`. Expected: `PaywallSignalsTest` fails on the undeclared gated-block cases.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on every changed PHP file. Expected: green.
```bash
git add src/Service/Reader/FetchedPageNormalizer.php src/Service/Reader/ArticleReadability.php src/Service/Reader/PageImageInventory.php \
  src/Service/Reader/LeadFigureCaptions.php src/Service/Reader/Paywall/PaywallSignals.php src/Service/Reader/ArticleExtractor.php \
  tests/Service/Reader/FetchedPageNormalizerTest.php tests/Service/Reader/PageImageInventoryTest.php \
  tests/Service/Reader/LeadFigureCaptionsTest.php tests/Service/Reader/Paywall/PaywallSignalsTest.php \
  tests/Service/Reader/ReaderLeadImageTest.php tests/Service/Reader/ReaderBodyCleanerTest.php \
  tests/Service/Reader/Slideshow/SlideshowExtractionTest.php tests/Service/Reader/ArticleExtractorTest.php
git commit -m "refactor(#1163): an unparseable page stops the extraction once; downstream takes a document"
```

---

### Task B3: `BodyCleaningInput`: `clean()` takes two parameters

**Files:**
- Create: `src/Service/Reader/BodyCleaning/BodyCleaningInput.php`
- Create: `tests/Support/BodyCleaningInputs.php`
- Modify: `src/Service/Reader/ReaderBodyCleaner.php` (rewritten in full; the class docblock and the constructor are unchanged until B6)
- Modify: `src/Service/Reader/ArticleExtractor.php` (one import, the `clean()` call)
- Modify: `src/Service/Reader/LeadImageCandidate.php` (docblock: it names the old nine-parameter `clean()`)
- Modify: `tests/Service/Reader/ReaderBodyCleanerTest.php`, `tests/Service/Reader/Slideshow/SlideshowExtractionTest.php` (both rewritten in full)

**Interfaces:**
- Produces: `App\Service\Reader\BodyCleaning\BodyCleaningInput::__construct(list<string|null> $titleCandidates, LeadImageCandidate $leadImage, ArticleMedia $media, FeedMedia $feedMedia, ?string $entryAuthor = null, list<Slideshow> $slideshows = [], list<TeaserPlayer> $teasers = [], ?string $excerpt = null)`.
- Produces: `ReaderBodyCleaner::clean(string $contentHtml, BodyCleaningInput $input): string`.
- Produces (tests): `App\Tests\Support\BodyCleaningInputs`, with `nothingKnown()`, `withTitles()`, `withLeadImage()`, `withMedia()`, `withFeedMedia()`, `withEntryAuthor()`, `withSlideshows()`, `withTeasers()`, `withExcerpt()`, `noLeadImage()` and `pageDrawingNothing()`.

- [ ] **Step 1: Write the failing tests**

`tests/Support/BodyCleaningInputs.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\FeedMedia;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\PageImageInventory;
use App\Service\Reader\Slideshow\Slideshow;

final class BodyCleaningInputs
{
    public static function nothingKnown(): BodyCleaningInput
    {
        return new BodyCleaningInput([], self::noLeadImage(), ArticleMedia::none(), FeedMedia::none());
    }

    /** @param list<string|null> $titleCandidates */
    public static function withTitles(array $titleCandidates): BodyCleaningInput
    {
        return new BodyCleaningInput($titleCandidates, self::noLeadImage(), ArticleMedia::none(), FeedMedia::none());
    }

    public static function withLeadImage(LeadImageCandidate $leadImage): BodyCleaningInput
    {
        return new BodyCleaningInput([], $leadImage, ArticleMedia::none(), FeedMedia::none());
    }

    public static function withMedia(ArticleMedia $media): BodyCleaningInput
    {
        return new BodyCleaningInput([], self::noLeadImage(), $media, FeedMedia::none());
    }

    public static function withFeedMedia(FeedMedia $feedMedia): BodyCleaningInput
    {
        return new BodyCleaningInput([], self::noLeadImage(), ArticleMedia::none(), $feedMedia);
    }

    public static function withEntryAuthor(?string $entryAuthor): BodyCleaningInput
    {
        return new BodyCleaningInput(
            [],
            self::noLeadImage(),
            ArticleMedia::none(),
            FeedMedia::none(),
            entryAuthor: $entryAuthor,
        );
    }

    /** @param list<Slideshow> $slideshows */
    public static function withSlideshows(array $slideshows): BodyCleaningInput
    {
        return new BodyCleaningInput(
            [],
            self::noLeadImage(),
            ArticleMedia::none(),
            FeedMedia::none(),
            slideshows: $slideshows,
        );
    }

    /** @param list<TeaserPlayer> $teasers the teasers to rebuild, beside the media the pipeline already placed */
    public static function withTeasers(array $teasers, ArticleMedia $placedMedia): BodyCleaningInput
    {
        return new BodyCleaningInput([], self::noLeadImage(), $placedMedia, FeedMedia::none(), teasers: $teasers);
    }

    public static function withExcerpt(?string $excerpt): BodyCleaningInput
    {
        return new BodyCleaningInput(
            [],
            self::noLeadImage(),
            ArticleMedia::none(),
            FeedMedia::none(),
            excerpt: $excerpt,
        );
    }

    public static function noLeadImage(): LeadImageCandidate
    {
        return new LeadImageCandidate(null, self::pageDrawingNothing());
    }

    public static function pageDrawingNothing(): PageImageInventory
    {
        return PageImageInventory::fromDocument(HtmlDocumentParser::parse('<body></body>'));
    }
}
```

`tests/Service/Reader/ReaderBodyCleanerTest.php` (whole file). Every test keeps its body and assertions. Only the input changes shape, and the four docblocks over three lines are trimmed (D9):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\DuplicateBlockCollapser;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\FeedMedia;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\Provider\SpotifyEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Service\Reader\MediaOnlyLede;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\PlayerChromeCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Tests\Support\BodyCleaningInputs;
use PHPUnit\Framework\TestCase;

final class ReaderBodyCleanerTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private ReaderBodyCleaner $cleaner;

    protected function setUp(): void
    {
        $markup = new MediaMarkup();
        $embedProviders = new EmbedProviders([new YouTubeEmbedProvider(), new SpotifyEmbedProvider()]);
        $this->cleaner = new ReaderBodyCleaner(
            new NavigationChromeTrimmer(),
            new LeadingTitleRemover(),
            new LeadingEngagementCleaner(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter($embedProviders, $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new PageMediaInserter($markup),
            new SlideshowInserter(new SlideshowMarkup()),
            new RecipeFactsCleaner(),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new DuplicateBlockCollapser($embedProviders),
            new AuthorBioSeparator(),
        );
    }

    /**
     * The Verge ships the dek once per breakpoint; the reader collapses it, and keeps the lead image untouched
     * (#963, #1088).
     */
    public function testCollapsesTheResponsiveDuplicateDek(): void
    {
        $dek = 'Apple might recycle the name from Microsoft dual-screen device for its first folding iPhone.';
        $content = "<div><p>$dek</p></div><div><p>$dek</p></div>"
            . '<p><img src="https://x.test/stk071-apple-b.jpg?w=2400" alt="Apple event"></p>'
            . '<p>' . self::PROSE . '</p>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertSame(1, substr_count($result, 'recycle the name from Microsoft'));
    }

    /** The trailing "about the author" furniture is set apart in its own figure (#1000). */
    public function testSetsTheTrailingAuthorBioApartFromTheBody(): void
    {
        $content = '<div>'
            . '<div><p>' . self::PROSE . ' Erster.</p><p>' . self::PROSE . ' Zweiter.</p></div>'
            . '<div><p>' . self::PROSE . ' Zur Autorin.</p>'
            . '<p><a href="https://news.test/author/jane-doe/">View Bio</a></p></div>'
            . '</div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('<figure class="reader-author-bio">', $result);
    }

    public function testRebuildsAnOrphanTeaserThumbnailAsAnInlinePlayer(): void
    {
        $content = '<p>' . self::PROSE . '</p><p><img src="https://x.test/still.jpg"></p>';
        $teaser = new TeaserPlayer(
            MediaKind::Video,
            'https://x.test/clip.mp4',
            'https://x.test/still.jpg',
            'The headline',
            'https://x.test/related.html',
        );

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTeasers([$teaser], ArticleMedia::none()));

        self::assertStringContainsString('<figure class="reader-teaser">', $result);
        self::assertStringContainsString('<video', $result);
        self::assertStringContainsString('https://x.test/related.html', $result);
    }

    public function testDoesNotRebuildATeaserThePipelineAlreadyPlaced(): void
    {
        $content = '<p>' . self::PROSE . '</p><p><img src="https://x.test/still.jpg"></p>';
        $teaser = new TeaserPlayer(MediaKind::Video, 'https://x.test/clip.mp4', 'https://x.test/still.jpg', null, null);
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/clip.mp4', 'https://x.test/still.jpg'),
        ]);

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTeasers([$teaser], $media));

        self::assertStringNotContainsString('reader-teaser', $result);
    }

    public function testRelaysARecipeFactBlockToTheReaderFigure(): void
    {
        $facts = '<div class="details-items">'
            . '<div class="detail-item"><span class="detail-item-icon"></span>'
            . '<span class="detail-item-label">Portionen</span>'
            . '<p class="detail-item-value">1</p>'
            . '<span class="detail-item-unit">Portionen</span></div></div>';
        $content = '<div><p>' . self::PROSE . '</p>' . $facts . '</div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('<figure class="reader-recipe-facts">', $result);
        self::assertStringContainsString('<dt>Portionen</dt><dd>1 Portionen</dd>', $result);
        self::assertStringNotContainsString('detail-item', $result);
    }

    public function testStripsALeadingNavigationChromeRegionInTheSamePass(): void
    {
        $header = '<div class="site-header"><nav><a href="/a">Editorial</a>'
            . '<a href="/b">Blog</a><a href="/c">Debate</a><a href="/d">About</a></nav></div>';
        $content = '<div>' . $header . '<main><p>' . self::PROSE . '</p></main></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringNotContainsString('site-header', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testDropsTheLeadingDuplicateHeadingInOnePass(): void
    {
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTitles(['My Article']));

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testDropsADuplicateTitleThatSatBehindAKicker(): void
    {
        $title = 'Schwedens Wohlfahrtsstaat nach 30 Jahren neoliberalem Experiment';
        $content = '<div><p>Kapitalismus</p><h2>' . $title . '</h2><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTitles([$title]));

        self::assertStringNotContainsString('Kapitalismus', $result);
        self::assertStringNotContainsString($title, $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRemovesLeadingEngagementChromeInTheSamePass(): void
    {
        $content = '<div><p>1.251 Klicks</p><p>❤️️</p><p>' . self::PROSE . '</p></div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringNotContainsString('Klicks', $result);
        self::assertStringNotContainsString('❤️', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testTrimsTrailingEdgeBoilerplateInTheSamePass(): void
    {
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $content = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::nothingKnown());

        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRemovesTheDuplicateHeadingAndTheTrailingBoilerplateTogether(): void
    {
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $content = '<div><h2>My Article</h2><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p>' . $grid . '</div>';

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withTitles(['My Article']));

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testReturnsBlankInputUnchangedWithoutParsing(): void
    {
        // Readability output is always non-empty in the pipeline, but a body that
        // cannot be parsed must fall through untouched rather than crash the pass.
        self::assertSame('   ', $this->cleaner->clean('   ', BodyCleaningInputs::withTitles(['My Article'])));
    }

    public function testRestoresTheLeadIntoATextOnlyBodyInTheSharedWindow(): void
    {
        $content = '<div><p>' . self::PROSE . '</p></div>';
        $candidate = new LeadImageCandidate('https://cdn.test/hero.jpg', BodyCleaningInputs::pageDrawingNothing());

        $result = $this->cleaner->clean($content, BodyCleaningInputs::withLeadImage($candidate));

        self::assertStringContainsString('<img src="https://cdn.test/hero.jpg"', $result);
        self::assertStringContainsString('Fliesstext', $result);
    }

    public function testRewritesAnInBodyEmbedAndKeepsItsPosition(): void
    {
        $html = '<h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';

        $out = $this->cleaner->clean($html, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
        self::assertStringNotContainsString('<iframe', $out);
    }

    /** One video per section recovers a poster per embed, all `hqdefault.jpg`; every embed stays (#1051). */
    public function testKeepsEveryInBodyEmbedWhenTheirPostersShareAStem(): void
    {
        $html = '<h4>One</h4><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<h4>Two</h4><div><iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe></div>'
            . '<h4>Three</h4><div><iframe src="https://www.youtube.com/embed/ccccccccccc"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';

        $out = $this->cleaner->clean($html, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('embed/aaaaaaaaaaa', $out);
        self::assertStringContainsString('embed/bbbbbbbbbbb', $out);
        self::assertStringContainsString('embed/ccccccccccc', $out);
    }

    /** A Spotify player embedded in the body survives as an embed link the client upgrades (#1053). */
    public function testRewritesAnInBodySpotifyEmbed(): void
    {
        $html = '<p>' . self::PROSE . '</p><h3>Playlist</h3>'
            . '<div><iframe src="https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4"></iframe></div>';

        $out = $this->cleaner->clean($html, BodyCleaningInputs::nothingKnown());

        self::assertStringContainsString('open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4', $out);
        self::assertStringNotContainsString('<iframe', $out);
    }

    /** A discovered embed is dropped when the body recovered its own, so the same video never appears twice. */
    public function testSuppressesDiscoveredEmbedsWhenTheBodyHadItsOwn(): void
    {
        $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb', null, 'Watch'),
        ]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertStringContainsString('aaaaaaaaaaa', $out);
        self::assertStringNotContainsString('bbbbbbbbbbb', $out);
    }

    /** Audio is not an embed, so the suppression must not reach it. */
    public function testKeepsDiscoveredAudioEvenWhenTheBodyHadAnEmbed(): void
    {
        $html = '<div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></div>'
            . '<p>' . self::PROSE . '</p>';
        $discovered = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertStringContainsString('a.mp3', $out);
    }

    /**
     * tagesschau 491512: the video reconciles into the body img that shares its poster's path UUID, in place and
     * once; an audio candidate with no matching img is top-placed.
     */
    public function testReconcilesARecoveredVideoIntoItsMatchingBodyImage(): void
    {
        $poster = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $bodyImg = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/B/16x9-big/t.jpg';
        $html = '<div><p>' . self::PROSE . '</p><figure><img src="' . $bodyImg . '" alt=""></figure></div>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', $poster),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
        ]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertSame(1, substr_count($out, '<video'));
        self::assertStringNotContainsString('<img', $out);
        self::assertLessThan(strpos($out, '<video'), strpos($out, '<audio'), 'audio has no match, so it leads');
        self::assertGreaterThan(
            strpos($out, 'Fliesstext'),
            strpos($out, '<video'),
            'the video stays in place, not at top',
        );
    }

    /**
     * heise 487576: the embed poster and the hero are one picture on two CDNs, so identity cannot match them;
     * the embed is top-placed and the hero is suppressed rather than stacked above it.
     */
    public function testSuppressesTheHeroWhenARecoveredEmbedIsTopPlaced(): void
    {
        $html = '<div><p>' . self::PROSE . '</p></div>';
        $lead = new LeadImageCandidate(
            'https://heise.cloudimg.example/thumb.jpg',
            BodyCleaningInputs::pageDrawingNothing(),
        );
        $discovered = new ArticleMedia([
            new MediaCandidate(
                MediaKind::Embed,
                'https://www.youtube-nocookie.com/embed/ccccccccccc',
                'https://i.ytimg.example/hqdefault.jpg',
                'Watch',
            ),
        ]);

        $out = $this->cleaner->clean($html, new BodyCleaningInput([], $lead, $discovered, FeedMedia::none()));

        self::assertStringNotContainsString('heise.cloudimg.example', $out);
        self::assertStringContainsString('i.ytimg.example/hqdefault.jpg', $out);
    }

    /**
     * tagesschau 491912: video1 reconciles into its matching body img, video2 has no match and is top-placed, and
     * an unrelated map img is left untouched.
     */
    public function testMixesReconciledAndTopPlacedVideosInTheSamePass(): void
    {
        $video1Poster = 'https://media.tagesschau.de/image/80085f9c-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $video1Body = 'https://media.tagesschau.de/image/80085f9c-1234-5678-9abc-def012345678/B/16x9-big/t.jpg';
        $video2Poster = 'https://media.tagesschau.de/image/58e272fd-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $mapImg = 'https://media.tagesschau.de/image/deadbeef-0000-0000-0000-000000000000/A/map.jpg';
        $html = '<div><p>' . self::PROSE . '</p>'
            . '<figure><img src="' . $video1Body . '" alt=""></figure>'
            . '<figure><img src="' . $mapImg . '" alt=""></figure></div>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v1.mp4', $video1Poster),
            new MediaCandidate(MediaKind::Video, 'https://x.test/v2.mp4', $video2Poster),
        ]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertSame(2, substr_count($out, '<video'));
        self::assertSame(1, substr_count($out, '<img'));
        self::assertStringContainsString($mapImg, $out);
        self::assertLessThan(strpos($out, 'v1.mp4'), strpos($out, 'v2.mp4'), 'the unmatched video leads');
    }

    /** The <a> guard: a body img inside an anchor is not reconciled even when its asset matches. */
    public function testDoesNotReconcileABodyImageInsideAnAnchor(): void
    {
        $poster = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $bodyImg = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/B/16x9-big/t.jpg';
        $html = '<div><p>' . self::PROSE . '</p>'
            . '<a href="https://x.test/story"><img src="' . $bodyImg . '" alt=""></a></div>';
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', $poster),
        ]);

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withMedia($discovered));

        self::assertStringContainsString('<img', $out);
        self::assertStringContainsString('<video', $out);
    }

    /**
     * Substack 481600, a paid video post: a byline card ("Paid"), then #627's poster link, then the teaser. The
     * poster link is the reader's play overlay hook and must survive.
     */
    public function testKeepsTheGatedVideoPosterBelowASubstackBylineCard(): void
    {
        $poster = 'https://substackcdn.com/image/fetch/w_1200/https%3A%2F%2Fsubstack-video.s3.amazonaws.com%2Fp.png';
        $html = '<div><div><p>Sheldrake—Vernon Dialogue 103</p></div>'
            . '<div><p><time datetime="2026-08-25T10:44:42Z">Aug 25, 2026</time></p><p>∙ Paid</p></div>'
            . '<div><p><a href="https://x.substack.com/p/plants"><img src="' . $poster . '"'
            . ' alt="Video — open the original article to watch" width="1280" height="720"></a></p>'
            . '<p>' . self::PROSE . '</p></div></div>';
        $lead = new LeadImageCandidate($poster, BodyCleaningInputs::pageDrawingNothing());

        $out = $this->cleaner->clean($html, BodyCleaningInputs::withLeadImage($lead));

        self::assertStringContainsString('alt="Video — open the original article to watch"', $out);
        self::assertSame(1, substr_count($out, '<img'), 'the poster is the only picture, no restored hero');
    }
}
```
`tests/Service/Reader/Slideshow/SlideshowExtractionTest.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\DuplicateBlockCollapser;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\FeedMedia;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\MediaOnlyLede;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\PlayerChromeCleaner;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\ReaderLeadImage;
use App\Service\Reader\Slideshow\MarkupCarouselRecognizer;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use App\Service\Reader\Slideshow\SlideshowInserter;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Reader\Slideshow\TagesschauCarouselRecognizer;
use App\Service\Sanitize\EntrySanitizer;
use App\Tests\Support\BodyCleaningInputs;
use PHPUnit\Framework\TestCase;

final class SlideshowExtractionTest extends TestCase
{
    public function testTagesschauGalleryBecomesAReaderSlideshowAfterItsHeading(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        $slideshows = $this->scanner()->scan($rawDocument);

        // The cleaned "body" here is the readability output: the heading survives,
        // the attribute-only carousel div is gone.
        $body = '<h2>Die Hauptgründe für das Ergebnis in Sachsen-Anhalt</h2><p>Body text.</p>';
        $clean = $this->cleaner()->clean($body, BodyCleaningInputs::withSlideshows($slideshows));

        self::assertStringContainsString('reader-slideshow', $clean);
        self::assertSame(3, substr_count($clean, '<img'));
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'Hauptgründe'));
    }

    public function testSwiperTeaserCaptionsAndLinksSurviveTheSanitizer(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/swiper-teaser-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        $body = '<p>An intro paragraph long enough to anchor the gallery that follows it here.</p>';
        $clean = $this->cleaner()->clean(
            $body,
            BodyCleaningInputs::withSlideshows($this->scanner()->scan($rawDocument)),
        );

        $safe = (new EntrySanitizer())->sanitize($clean);
        self::assertIsString($safe);
        self::assertStringContainsString('First headline', $safe);
        self::assertStringContainsString('https://www.example.com/first-article', $safe);
        self::assertStringContainsString('<p>Second headline</p>', $safe);
        // The script text inside the slide is not visible, so it never reaches the caption.
        self::assertStringNotContainsString('drop me', $safe);
    }

    public function testRestoresTheExcerptWhenTheGalleryLeavesTheBodyWithoutProse(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/tagesschau-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        // Readability dropped the header block, so its output carries no prose.
        $clean = $this->cleaner()->clean('<div></div>', new BodyCleaningInput(
            ['Article title', 'Article title'],
            BodyCleaningInputs::noLeadImage(),
            ArticleMedia::none(),
            FeedMedia::none(),
            slideshows: $this->scanner()->scan($rawDocument),
            excerpt: 'Er war passionierter Segler und bei den Norwegern ausgesprochen beliebt.',
        ));

        self::assertStringContainsString('<p>Er war passionierter Segler', $clean);
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'passionierter Segler'));
    }

    public function testDropsTheTeaserCarouselsOfIdenticalPlaceholderImages(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/toi-placeholder-carousel.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        self::assertSame([], $this->scanner()->scan($rawDocument));
    }

    public function testRecoversRealImagesBehindARepeatedRemotePlaceholderSrc(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../Fixtures/Slideshow/lazy-placeholder-gallery.html');
        self::assertIsString($raw);
        $rawDocument = HtmlDocumentParser::parseOrNull($raw);
        self::assertNotNull($rawDocument);

        $slideshows = $this->scanner()->scan($rawDocument);

        self::assertCount(1, $slideshows);
        self::assertSame(
            [
                'https://cdn.example.com/photo/first-1600.jpg',
                'https://cdn.example.com/photo/second-1600.jpg',
                'https://cdn.example.com/photo/third-1600.jpg',
            ],
            array_map(static fn ($slide): string => $slide->imageUrl, $slideshows[0]->slides),
        );
    }

    private function scanner(): SlideshowScanner
    {
        return new SlideshowScanner([
            new MarkupCarouselRecognizer(new SlideImageResolver(), new SlideCaptionResolver()),
            new TagesschauCarouselRecognizer(),
        ]);
    }

    private function cleaner(): ReaderBodyCleaner
    {
        $markup = new MediaMarkup();
        $embedProviders = new EmbedProviders([new YouTubeEmbedProvider()]);

        return new ReaderBodyCleaner(
            new NavigationChromeTrimmer(),
            new LeadingTitleRemover(),
            new LeadingEngagementCleaner(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter($embedProviders, $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new PageMediaInserter($markup),
            new SlideshowInserter(new SlideshowMarkup()),
            new RecipeFactsCleaner(),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new DuplicateBlockCollapser($embedProviders),
            new AuthorBioSeparator(),
        );
    }
}
```
The first two tests passed the titles `['Article title', 'Article title']` before. `withSlideshows()` passes none, which is safe: neither body holds a heading that matches them, so `LeadingTitleRemover` removes nothing either way. The third test keeps them verbatim.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/ReaderBodyCleanerTest.php tests/Service/Reader/Slideshow/SlideshowExtractionTest.php`
Expected: FAIL with `Class "App\Service\Reader\BodyCleaning\BodyCleaningInput" not found`.

- [ ] **Step 3: Implement**

`src/Service/Reader/BodyCleaning/BodyCleaningInput.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning;

use App\Service\Reader\FeedMedia;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Slideshow\Slideshow;

/** What the extraction knows about the article besides its body, for the body-cleaning steps to read. */
final readonly class BodyCleaningInput
{
    /**
     * @param list<string|null>  $titleCandidates readability's title and the feed entry's, either possibly absent
     * @param list<Slideshow>    $slideshows
     * @param list<TeaserPlayer> $teasers
     */
    public function __construct(
        public array $titleCandidates,
        public LeadImageCandidate $leadImage,
        public ArticleMedia $media,
        public FeedMedia $feedMedia,
        public ?string $entryAuthor = null,
        public array $slideshows = [],
        public array $teasers = [],
        public ?string $excerpt = null,
    ) {
    }
}
```

`src/Service/Reader/ReaderBodyCleaner.php` (whole file). The class docblock and the constructor are develop's, unchanged; B6 replaces the class:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\Slideshow\SlideshowInserter;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Cleans readability's article HTML for the reader view through one shared
 * \Dom\HTMLDocument: parse once, rewrite in-body media, repair the page's own
 * players, drop the duplicate leading title, trim edge boilerplate, plan where
 * page-discovered media belongs, restore the lead image against that plan,
 * reconcile the media into the body, restore the lede of a media-only article,
 * serialise once — mirroring FetchedPageNormalizer's discipline of never
 * serialising and re-parsing between steps (#586, #684, #748).
 *
 * Handed on to EntrySanitizer, the XSS boundary, which stays string-in/
 * string-out since Symfony's HtmlSanitizer operates on strings, not a shared
 * DOM — the shared-document window ends here, with one serialise.
 *
 * A body too broken to parse is returned unchanged: readability output is
 * always parseable in practice, but a degenerate one falls through rather
 * than crashing the pass.
 *
 * The constructor collaborators are deliberate: this is the body-cleaning
 * pipeline's composition root, and each one is a seam the tests swap
 * independently (trimmers, restorers, inserters, …). Bagging them into a
 * parameter object would hide that coupling, not reduce it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class ReaderBodyCleaner
{
    public function __construct(
        private NavigationChromeTrimmer $navigationTrimmer,
        private LeadingTitleRemover $titleRemover,
        private LeadingEngagementCleaner $engagementCleaner,
        private EdgeBoilerplateTrimmer $boilerplateTrimmer,
        private ReaderLeadImage $leadImage,
        private InBodyEmbedRewriter $embedRewriter,
        private SubstackPosterLink $substackPoster,
        private PlayerChromeCleaner $playerChrome,
        private PageMediaInserter $mediaInserter,
        private SlideshowInserter $slideshowInserter,
        private RecipeFactsCleaner $recipeFactsCleaner,
        private TeaserPlayerInserter $teaserInserter,
        private MediaOnlyLede $mediaOnlyLede,
        private DuplicateBlockCollapser $duplicateCollapser,
        private AuthorBioSeparator $authorBioSeparator,
    ) {
    }

    #[WithSpan]
    public function clean(string $contentHtml, BodyCleaningInput $input): string
    {
        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }

        // Media first: a trimmer must not remove a block that now holds a
        // recovered player, and the lead-image restore must see a poster the
        // body has gained before it decides whether to add another picture.
        $recoveredInBody = $this->embedRewriter->rewriteIn($document);
        $this->substackPoster->linkIn($document);
        $this->playerChrome->cleanIn($document);

        $this->navigationTrimmer->trimIn($document);
        // Engagement first: it strips the leading kickers and breadcrumbs that
        // otherwise sit in front of the title and hide it from the title remover.
        $this->engagementCleaner->removeFrom($document, $input->entryAuthor);
        $this->titleRemover->removeFrom($document, $input->titleCandidates);
        $this->boilerplateTrimmer->trimIn($document);

        // A recreated slideshow replaces the publisher's original carousel, which
        // extraction leaves as a broken pile of markup or an empty box. Runs after
        // the trimmers so a trimmer cannot drop the anchor, before media planning
        // so the plan sees the finished structure.
        $this->slideshowInserter->insert($document, $input->slideshows);

        // A publisher's recipe-fact block (servings/calories/time) lays out with
        // its own stylesheet, which the sanitizer never receives; relay it to the
        // reader's own row-of-cells marker before media planning sees the body.
        $this->recipeFactsCleaner->cleanIn($document);

        // Drop the dek a responsive page ships twice, one copy hidden by CSS the
        // scraper never runs (#963; the fragile image half was removed in #1088).
        $this->duplicateCollapser->collapseIn($document);

        // plan() only classifies, so restore() still sees every body image and
        // can skip the hero when a lead visual will land at the top; apply()'s
        // mutation runs after, or the hero would come back (#755).
        $discoveredMedia = $recoveredInBody ? $input->media->withoutEmbeds() : $input->media;
        $plan = $this->mediaInserter->plan($document, $discoveredMedia);
        $restoredHero = $this->leadImage->restore($document, $input->leadImage, $plan->topPlacesLeadVisual());
        $this->mediaInserter->apply($document, $plan, $restoredHero);

        // Inline teasers the extraction reduced to a lone thumbnail: rebuild each
        // as a player where its still still sits, skipping any the media pipeline
        // already placed so the lead media is never mistaken for one (#948).
        $this->teaserInserter->insert($document, $input->teasers, $this->mediaUrls($input->media));

        // A gallery or video article whose only prose lived in a dropped header
        // now has a body of pure media; give it back the lede readability kept as
        // the excerpt. Runs last, so it judges "media-only" against the final body.
        $this->mediaOnlyLede->restore($document, $input->excerpt);

        // Over the settled body: set the trailing author bio and its disclosure
        // apart from the article prose they otherwise run on into (#1000).
        $this->authorBioSeparator->separateIn($document);

        // Last, over the finished body: the feed's real pixel sizes on the
        // images and players it enumerated, so none of them reflows the article.
        FeedDimensionStamper::stampInto($document, $input->feedMedia);

        return $document->saveHtml();
    }

    /** @return list<string> the URLs the media pipeline placed, so a teaser is not rebuilt over one */
    private function mediaUrls(ArticleMedia $media): array
    {
        return array_map(static fn ($candidate): string => $candidate->url, $media->candidates);
    }
}
```

`src/Service/Reader/ArticleExtractor.php`:
- Replace `use App\Service\Html\Exception\UnparseableHtmlException;` with
```php
use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
```
- Replace
```php
        $leadImage = new LeadImageCandidate($article->image, $pageImages, $leadCaptions->captionFor($article->image));
        $body = $this->bodyCleaner->clean(
            $article->content,
            [$article->title, $entryTitle],
            $leadImage,
            $this->bodyMedia->resolveForBody($media, $page->html),
            $entryAuthor,
            $feedMedia,
            $slideshows,
            $teasers,
            $article->excerpt,
        );
```
with the same evaluation order (the lead's caption lookup, then `resolveForBody()`):
```php
        $body = $this->bodyCleaner->clean($article->content, new BodyCleaningInput(
            titleCandidates: [$article->title, $entryTitle],
            leadImage: new LeadImageCandidate($article->image, $pageImages, $leadCaptions->captionFor($article->image)),
            media: $this->bodyMedia->resolveForBody($media, $page->html),
            feedMedia: $feedMedia,
            entryAuthor: $entryAuthor,
            slideshows: $slideshows,
            teasers: $teasers,
            excerpt: $article->excerpt,
        ));
```

`src/Service/Reader/LeadImageCandidate.php`, replace
```php
/**
 * The lead image ReaderLeadImage may restore, with the evidence to decide it:
 * the og:image URL readability reported (null or non-http when there is none to
 * restore), the inventory of images the page actually draws, and the caption
 * its dropped figure carried, if any. Grouped so ReaderBodyCleaner::clean
 * carries one lead parameter, not three.
 */
```
with
```php
/**
 * The lead image ReaderLeadImage may restore, with the evidence to decide it: readability's og:image URL (null or
 * non-http when there is none), the images the page draws, and the caption its dropped figure carried.
 */
```

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reader
php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php tests/Service/Tracing/TracedServiceMethodsTest.php
```
Expected: PASS. `TracedServiceMethodsTest` still finds `#[WithSpan]` on `ReaderBodyCleaner::clean`.

- [ ] **Step 5: Deletion checks**

Break each line in `ReaderBodyCleaner::clean()`, run `php bin/phpunit tests/Service/Reader/ReaderBodyCleanerTest.php tests/Service/Reader/Slideshow/SlideshowExtractionTest.php`, and restore it by hand:
1. `$input->titleCandidates` → `[]`. Expected: `testDropsTheLeadingDuplicateHeadingInOnePass` fails.
2. `$input->teasers` → `[]`. Expected: `testRebuildsAnOrphanTeaserThumbnailAsAnInlinePlayer` fails.
3. `$input->excerpt` → `null`. Expected: `SlideshowExtractionTest::testRestoresTheExcerptWhenTheGalleryLeavesTheBodyWithoutProse` fails.
4. `$input->slideshows` → `[]`. Expected: `SlideshowExtractionTest::testTagesschauGalleryBecomesAReaderSlideshowAfterItsHeading` fails.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on every changed PHP file. Expected: green.
```bash
git add src/Service/Reader/BodyCleaning/BodyCleaningInput.php src/Service/Reader/ReaderBodyCleaner.php \
  src/Service/Reader/ArticleExtractor.php src/Service/Reader/LeadImageCandidate.php tests/Support/BodyCleaningInputs.php \
  tests/Service/Reader/ReaderBodyCleanerTest.php tests/Service/Reader/Slideshow/SlideshowExtractionTest.php
git commit -m "refactor(#1163): the body cleaner takes one input value instead of nine parameters"
```

---

### Task B4: `ReaderLeadImage::restore()` loses its flag; the cleaner skips the restore

**Files:**
- Modify: `src/Service/Reader/ReaderLeadImage.php` (class docblock, `restore()` signature, one guard)
- Modify: `src/Service/Reader/ReaderBodyCleaner.php` (one comment, one line)
- Modify: `tests/Service/Reader/ReaderLeadImageTest.php` (helper; two flag tests go; one call)

**Interfaces:**
- `ReaderLeadImage::restore(HTMLDocument $document, LeadImageCandidate $lead): ?Element`. The caller skips the call when `MediaInsertionPlan::topPlacesLeadVisual()` is true.

- [ ] **Step 1: Write the failing test change**

`tests/Service/Reader/ReaderLeadImageTest.php`:
- Replace
```php
    /** Run restore in place and return the serialised body markup. */
    private function restoredBody(
        string $bodyHtml,
        PageImageInventory $pageImages,
        ?string $leadUrl,
        bool $willTopPlace = false,
    ): string {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);
        $this->leadImage->restore($document, new LeadImageCandidate($leadUrl, $pageImages), $willTopPlace);

        return (string) $document->body?->innerHTML;
    }
```
with
```php
    private function restoredBody(string $bodyHtml, PageImageInventory $pageImages, ?string $leadUrl): string
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);
        $this->leadImage->restore($document, new LeadImageCandidate($leadUrl, $pageImages));

        return (string) $document->body?->innerHTML;
    }
```
- Delete the two tests of the flag. The skip now lives in the caller, and `ReaderBodyCleanerTest::testSuppressesTheHeroWhenARecoveredEmbedIsTopPlaced` pins it. B6's `PageMediaPlacementTest` pins it again.
```php
    public function testSkipsRestoringTheHeroWhenAPlayerWillBeTopPlaced(): void
    {
        // heise 487576: an embed poster and the hero are the same picture from
        // different CDNs, so identity cannot match them — but a player is about
        // to be prepended, so the hero must not stack a second copy above it.
        $lead = 'https://cdn.test/hero-photo.jpg';
        $body = '<p>Just words.</p>';

        $result = $this->restoredBody($body, $this->pageDrawing($lead), $lead, true);

        self::assertSame($this->unchangedBody($body), $result);
    }

    public function testStillRestoresTheHeroWhenNothingWillBeTopPlaced(): void
    {
        // willTopPlace=false must behave exactly as before: a legitimately
        // distinct hero on a mid-body-video article is not dropped.
        $lead = 'https://cdn.test/hero-photo.jpg';

        $result = $this->restoredBody('<p>Just words.</p>', $this->pageDrawing($lead), $lead, false);

        self::assertStringContainsString('hero-photo.jpg', $result);
    }

```
- Replace `$this->leadImage->restore($document, $candidate, false);` with `$this->leadImage->restore($document, $candidate);`.

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Service/Reader/ReaderLeadImageTest.php`
Expected: FAIL with `ArgumentCountError: Too few arguments to function App\Service\Reader\ReaderLeadImage::restore(), 2 passed … and exactly 3 expected`.

- [ ] **Step 3: Implement**

`src/Service/Reader/ReaderLeadImage.php`:
- Replace
```php
/**
 * Restores a page-drawn lead unless the body starts with an image, already
 * contains that asset, or a recovered lead visual is about to be top-placed. A
 * share render — a subscribe card, a generated preview — is refused by what
 * it is: an imageless body takes any lead, so drawn-on-page cannot gate it
 * (#786).
 */
```
with
```php
/**
 * Restores a page-drawn lead unless the body opens with an image or already shows that asset. A share render (a
 * subscribe card, a generated preview) is refused by what it is: an imageless body takes any lead (#786).
 */
```
- Replace
```php
    public function restore(HTMLDocument $document, LeadImageCandidate $lead, bool $topPlacesLeadVisual): ?Element
```
with
```php
    public function restore(HTMLDocument $document, LeadImageCandidate $lead): ?Element
```
- Replace
```php
        // A top-placed video or embed takes the article's lead position; adding
        // the hero above it would stack a second lead. A narration audio player
        // does not, so the hero still belongs above it (#907).
        if ($topPlacesLeadVisual || !$this->belongsAbove($body, $lead)) {
            return null;
        }
```
with
```php
        if (!$this->belongsAbove($body, $lead)) {
            return null;
        }
```

`src/Service/Reader/ReaderBodyCleaner.php`, replace
```php
        // plan() only classifies, so restore() still sees every body image and
        // can skip the hero when a lead visual will land at the top; apply()'s
        // mutation runs after, or the hero would come back (#755).
        $discoveredMedia = $recoveredInBody ? $input->media->withoutEmbeds() : $input->media;
        $plan = $this->mediaInserter->plan($document, $discoveredMedia);
        $restoredHero = $this->leadImage->restore($document, $input->leadImage, $plan->topPlacesLeadVisual());
```
with
```php
        // plan() only classifies, so restore() still sees every body image; apply()'s
        // mutation runs after, or the hero would come back (#755). A top-placed video or
        // embed takes the lead position, so no hero is restored above it (#907).
        $discoveredMedia = $recoveredInBody ? $input->media->withoutEmbeds() : $input->media;
        $plan = $this->mediaInserter->plan($document, $discoveredMedia);
        $restoredHero = $plan->topPlacesLeadVisual() ? null : $this->leadImage->restore($document, $input->leadImage);
```

- [ ] **Step 4: Run to verify it passes**

```bash
php bin/phpunit tests/Service/Reader
php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php
```
Expected: PASS. That includes `ArticleExtractorTest::testKeepsTheHeroAboveATopPlacedNarrationPlayer` (#907, audio is not a lead visual) and `ReaderBodyCleanerTest::testSuppressesTheHeroWhenARecoveredEmbedIsTopPlaced`.

- [ ] **Step 5: Deletion check**

In `ReaderBodyCleaner::clean()`, replace `$plan->topPlacesLeadVisual() ? null : ` with nothing (always restore). Run `php bin/phpunit tests/Service/Reader/ReaderBodyCleanerTest.php`. Expected: `testSuppressesTheHeroWhenARecoveredEmbedIsTopPlaced` fails. Restore the text by hand.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on the three files. Expected: green.
```bash
git add src/Service/Reader/ReaderLeadImage.php src/Service/Reader/ReaderBodyCleaner.php tests/Service/Reader/ReaderLeadImageTest.php
git commit -m "refactor(#1163): the cleaner skips the lead restore instead of passing it a flag"
```

---

### Task B5: Every sequenced body-cleaning collaborator is a `BodyCleaningStep`

**Files:**
- Create: `src/Service/Reader/BodyCleaning/BodyCleaningStep.php`, `src/Service/Reader/BodyCleaning/BodyCleaningPass.php`
- Create: `tests/Service/Reader/BodyCleaning/BodyCleaningPassTest.php`, `tests/Support/BodyCleaningPasses.php`
- Modify (implement the step; the old public method becomes private): `src/Service/Reader/Media/SubstackPosterLink.php`, `src/Service/Reader/PlayerChromeCleaner.php`, `src/Service/Reader/NavigationChromeTrimmer.php`, `src/Service/Reader/LeadingEngagementCleaner.php`, `src/Service/Reader/LeadingTitleRemover.php`, `src/Service/Reader/EdgeBoilerplateTrimmer.php`, `src/Service/Reader/Slideshow/SlideshowInserter.php`, `src/Service/Reader/DuplicateBlockCollapser.php`, `src/Service/Reader/Media/Teaser/TeaserPlayerInserter.php`, `src/Service/Reader/MediaOnlyLede.php`, `src/Service/Reader/AuthorBio/AuthorBioSeparator.php`
- Modify (rewritten in full): `src/Service/Reader/Media/InBodyEmbedRewriter.php`, `src/Service/Reader/RecipeFacts/RecipeFactsCleaner.php`, `src/Service/Reader/ReaderBodyCleaner.php` (the constructor is unchanged until B6)
- Modify (tests): `tests/Service/Reader/Media/InBodyEmbedRewriterTest.php` and `tests/Service/Reader/Media/Teaser/TeaserPlayerInserterTest.php` (rewritten in full), plus one helper line and its imports in each of `Media/SubstackPosterLinkTest`, `PlayerChromeCleanerTest`, `NavigationChromeTrimmerTest`, `LeadingEngagementCleanerTest`, `tests/Service/ReaderAudit/LeadingEngagementMarkersTest`, `LeadingTitleRemoverTest`, `EdgeBoilerplateTrimmerTest`, `Slideshow/SlideshowInserterTest`, `RecipeFacts/RecipeFactsCleanerTest`, `DuplicateBlockCollapserTest`, `MediaOnlyLedeTest`, `AuthorBio/AuthorBioSeparatorTest`

**Interfaces:**
- Produces: `interface App\Service\Reader\BodyCleaning\BodyCleaningStep { public function cleanIn(BodyCleaningPass $pass): void; }`.
- Produces: `final class BodyCleaningPass`, with `__construct(HTMLDocument $document, BodyCleaningInput $input)` (both `public readonly`), `recordEmbedsRecoveredInBody(): void` and `discoveredMedia(): ArticleMedia`.
- Produces (tests): `App\Tests\Support\BodyCleaningPasses::over(HTMLDocument $document): BodyCleaningPass`.
- The thirteen collaborators expose `cleanIn(BodyCleaningPass)` only. The reader audit names them in its findings, so their class names do not change (D2).

- [ ] **Step 1: Write the failing tests**

`tests/Support/BodyCleaningPasses.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use Dom\HTMLDocument;

final class BodyCleaningPasses
{
    public static function over(HTMLDocument $document): BodyCleaningPass
    {
        return new BodyCleaningPass($document, BodyCleaningInputs::nothingKnown());
    }
}
```

`tests/Service/Reader/BodyCleaning/BodyCleaningPassTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Tests\Support\BodyCleaningInputs;
use PHPUnit\Framework\TestCase;

final class BodyCleaningPassTest extends TestCase
{
    public function testTheDiscoveredMediaAreTheInputMediaWhileTheBodyRecoveredNoEmbed(): void
    {
        $media = $this->embedAndAudio();

        $pass = new BodyCleaningPass(HtmlDocumentParser::parse('<p>Body.</p>'), BodyCleaningInputs::withMedia($media));

        self::assertSame($media, $pass->discoveredMedia());
    }

    public function testARecoveredEmbedDropsTheDiscoveredEmbedsButKeepsTheAudio(): void
    {
        $pass = new BodyCleaningPass(
            HtmlDocumentParser::parse('<p>Body.</p>'),
            BodyCleaningInputs::withMedia($this->embedAndAudio()),
        );

        $pass->recordEmbedsRecoveredInBody();
        $urls = array_map(
            static fn (MediaCandidate $candidate): string => $candidate->url,
            $pass->discoveredMedia()->candidates,
        );

        self::assertSame(['https://x.test/a.mp3'], $urls);
    }

    private function embedAndAudio(): ArticleMedia
    {
        return new ArticleMedia([
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb', null, 'Watch'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3'),
        ]);
    }
}
```
`tests/Service/Reader/Media/InBodyEmbedRewriterTest.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\BodyCleaningPasses;
use PHPUnit\Framework\TestCase;

final class InBodyEmbedRewriterTest extends TestCase
{
    private InBodyEmbedRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new InBodyEmbedRewriter(
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
            new MediaMarkup(),
        );
    }

    private function rewrite(string $html): string
    {
        $pass = BodyCleaningPasses::over(HtmlDocumentParser::parse($html));
        $this->rewriter->cleanIn($pass);

        return $pass->document->saveHtml();
    }

    /** The OZORA shape: a heading, then the embed, ten times over. */
    public function testKeepsEachEmbedAtItsHeadingPosition(): void
    {
        $html = '<body><h3>One</h3><div><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa?si=x"></iframe></div>'
            . '<h3>Two</h3><div><iframe src="https://www.youtube.com/embed/bbbbbbbbbbb"></iframe></div></body>';

        $out = $this->rewrite($html);

        self::assertStringContainsString('<h3>One</h3>', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/aaaaaaaaaaa', $out);
        self::assertStringContainsString('youtube-nocookie.com/embed/bbbbbbbbbbb', $out);
        self::assertLessThan(
            strpos($out, 'bbbbbbbbbbb'),
            strpos($out, 'aaaaaaaaaaa'),
            'embeds must keep source order'
        );
        self::assertStringNotContainsString('<iframe', $out);
        self::assertStringNotContainsString('si=x', $out);
    }

    public function testAYouTubeEmbedBecomesAPosterLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/aaaaaaaaaaa"', $out);
        self::assertStringContainsString('i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $out);
    }

    /** No cheap poster, so the link carries text a reader can act on. */
    public function testASoundCloudEmbedBecomesATextLink(): void
    {
        $out = $this->rewrite('<body><iframe src="https://w.soundcloud.com/player/'
            . '?url=https%3A//api.soundcloud.com/tracks/2370150908&amp;auto_play=true"></iframe></body>');

        self::assertStringContainsString('Listen on SoundCloud', $out);
        self::assertStringNotContainsString('auto_play', $out);
    }

    public function testAnUnknownIframeIsLeftForTheSanitizer(): void
    {
        $html = '<body><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-1"></iframe></body>';

        self::assertStringContainsString('googletagmanager', $this->rewrite($html));
    }

    public function testRecordsARecoveredEmbedSoTheDiscoveredEmbedsStandDown(): void
    {
        $discovered = new ArticleMedia([
            new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb'),
        ]);
        $none = new BodyCleaningPass(
            HtmlDocumentParser::parse('<body><p>text</p></body>'),
            BodyCleaningInputs::withMedia($discovered),
        );
        $one = new BodyCleaningPass(
            HtmlDocumentParser::parse('<body><iframe src="https://youtu.be/aaaaaaaaaaa"></iframe></body>'),
            BodyCleaningInputs::withMedia($discovered),
        );

        $this->rewriter->cleanIn($none);
        $this->rewriter->cleanIn($one);

        self::assertSame($discovered, $none->discoveredMedia());
        self::assertTrue($one->discoveredMedia()->isEmpty());
    }

    /** Do not reuse #627's alt text: its CSS paints a play badge on that string. */
    public function testDoesNotReuseTheSubstackPlaceholderAltText(): void
    {
        $out = $this->rewrite('<body><iframe src="https://www.youtube.com/embed/aaaaaaaaaaa"></iframe></body>');

        self::assertStringNotContainsString('Video — open the original article to watch', $out);
    }
}
```

`tests/Service/Reader/Media/Teaser/TeaserPlayerInserterTest.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Teaser;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use App\Tests\Support\BodyCleaningInputs;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class TeaserPlayerInserterTest extends TestCase
{
    private TeaserPlayerInserter $inserter;

    protected function setUp(): void
    {
        $this->inserter = new TeaserPlayerInserter(new TeaserPlayerMarkup());
    }

    private function document(string $bodyHtml): HTMLDocument
    {
        return HtmlDocumentParser::parse('<body>' . $bodyHtml . '</body>');
    }

    /** @param list<TeaserPlayer> $teasers */
    private function insert(HTMLDocument $document, array $teasers, ArticleMedia $placedMedia): void
    {
        $this->inserter->cleanIn(
            new BodyCleaningPass($document, BodyCleaningInputs::withTeasers($teasers, $placedMedia)),
        );
    }

    private function video(
        string $mediaUrl = 'https://x.test/clip.mp4',
        string $poster = 'https://x.test/still.jpg',
    ): TeaserPlayer {
        return new TeaserPlayer(MediaKind::Video, $mediaUrl, $poster, 'The headline', 'https://x.test/related.html');
    }

    public function testReplacesTheOrphanThumbnailWithAPlayerCaptionAndLink(): void
    {
        $document = $this->document('<p>Prose.</p><p><img src="https://x.test/still.jpg"></p>');

        $this->insert($document, [$this->video()], ArticleMedia::none());

        $html = (string) $document->saveHtml();
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<video', $html);
        self::assertStringContainsString('poster="https://x.test/still.jpg"', $html);
        self::assertStringContainsString('src="https://x.test/clip.mp4"', $html);
        self::assertStringContainsString('href="https://x.test/related.html"', $html);
        self::assertStringContainsString('The headline', $html);
    }

    /** An audio teaser keeps its thumbnail beside the player, since <audio> shows none. */
    public function testAnAudioTeaserKeepsItsThumbnail(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');
        $teaser = new TeaserPlayer(MediaKind::Audio, 'https://x.test/ep.mp3', 'https://x.test/still.jpg', 'Head', null);

        $this->insert($document, [$teaser], ArticleMedia::none());

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<audio', $html);
        self::assertStringContainsString('src="https://x.test/still.jpg"', $html);
    }

    /** A teaser the media pipeline already inserted (its URL is known) is not reconstructed again. */
    public function testSkipsATeaserAlreadyAmongTheArticleMedia(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');
        $placed = new ArticleMedia([new MediaCandidate(MediaKind::Video, 'https://x.test/clip.mp4')]);

        $this->insert($document, [$this->video('https://x.test/clip.mp4')], $placed);

        self::assertStringContainsString('<img', (string) $document->saveHtml());
        self::assertStringNotContainsString('<video', (string) $document->saveHtml());
    }

    public function testLeavesAnUnmatchedOrphanUntouched(): void
    {
        $document = $this->document('<p><img src="https://x.test/other.jpg"></p>');

        $this->insert($document, [$this->video()], ArticleMedia::none());

        self::assertStringNotContainsString('<video', (string) $document->saveHtml());
    }

    /** tagesschau serves the body thumbnail at a different size; the CMS asset id still matches. */
    public function testMatchesAcrossSizeVariantsOfTheSameStill(): void
    {
        $uuid = '5cecefc4-18df-44da-b585-b3e16fa7d7c0';
        $body = '<p><img src="https://images.test/image/' . $uuid
            . '/AA/BB/1x1-small/konjunktur-416.jpg?width=256"></p>';
        $document = $this->document($body);
        $teaser = new TeaserPlayer(
            MediaKind::Video,
            'https://x.test/clip.mp4',
            'https://images.test/image/' . $uuid . '/AA/CC/16x9-1920/konjunktur-416.jpg',
            null,
            null,
        );

        $this->insert($document, [$teaser], ArticleMedia::none());

        self::assertStringContainsString('<video', (string) $document->saveHtml());
    }

    /** A thumbnail alone in a paragraph takes the paragraph with it, leaving no empty wrapper. */
    public function testReplacesTheWholeParagraphWhenTheThumbnailIsAlone(): void
    {
        $document = $this->document('<p><img src="https://x.test/still.jpg"></p>');

        $this->insert($document, [$this->video()], ArticleMedia::none());

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<figure class="reader-teaser">', $html);
        self::assertStringNotContainsString('<p>', $html);
    }

    /** A thumbnail sharing its paragraph with text is replaced in place; the paragraph stays. */
    public function testKeepsAParagraphThatHoldsMoreThanTheThumbnail(): void
    {
        $document = $this->document('<p>Around <img src="https://x.test/still.jpg"> it.</p>');

        $this->insert($document, [$this->video()], ArticleMedia::none());

        $html = (string) $document->saveHtml();
        self::assertStringContainsString('<p>Around <figure', $html);
        self::assertStringContainsString('it.</p>', $html);
    }

    public function testEachTeaserClaimsOneThumbnailOnly(): void
    {
        $document = $this->document(
            '<p><img src="https://x.test/still.jpg"></p><p><img src="https://x.test/still.jpg"></p>',
        );

        $this->insert($document, [$this->video()], ArticleMedia::none());

        self::assertSame(1, substr_count((string) $document->saveHtml(), '<video'));
        self::assertStringContainsString('<img', (string) $document->saveHtml());
    }
}
```

The one-line helper edits. Each adds the named imports, in alphabetical position among the file's `use` lines:

| Test file | Replace | With | Add imports |
|---|---|---|---|
| `tests/Service/Reader/Media/SubstackPosterLinkTest.php` | `        $this->rule->linkIn($document);` | `        $this->rule->cleanIn(BodyCleaningPasses::over($document));` | `App\Tests\Support\BodyCleaningPasses` |
| `tests/Service/Reader/PlayerChromeCleanerTest.php` | `        $this->cleaner->cleanIn($document);` | `        $this->cleaner->cleanIn(BodyCleaningPasses::over($document));` | `App\Tests\Support\BodyCleaningPasses` |
| `tests/Service/Reader/NavigationChromeTrimmerTest.php` | `        $this->trimmer->trimIn($document);` | `        $this->trimmer->cleanIn(BodyCleaningPasses::over($document));` | `App\Tests\Support\BodyCleaningPasses` |
| `tests/Service/Reader/EdgeBoilerplateTrimmerTest.php` | `        $this->trimmer->trimIn($document);` | `        $this->trimmer->cleanIn(BodyCleaningPasses::over($document));` | `App\Tests\Support\BodyCleaningPasses` |
| `tests/Service/Reader/DuplicateBlockCollapserTest.php` | `        $this->collapser->collapseIn($document);` | `        $this->collapser->cleanIn(BodyCleaningPasses::over($document));` | `App\Tests\Support\BodyCleaningPasses` |
| `tests/Service/Reader/AuthorBio/AuthorBioSeparatorTest.php` | `        $this->separator->separateIn($document);` | `        $this->separator->cleanIn(BodyCleaningPasses::over($document));` | `App\Tests\Support\BodyCleaningPasses` |
| `tests/Service/Reader/RecipeFacts/RecipeFactsCleanerTest.php` | `        (new RecipeFactsCleaner())->cleanIn($document);` | `        (new RecipeFactsCleaner())->cleanIn(BodyCleaningPasses::over($document));` | `App\Tests\Support\BodyCleaningPasses` |
| `tests/Service/Reader/LeadingEngagementCleanerTest.php` | `        $this->cleaner->removeFrom($document, $entryAuthor);` | `        $this->cleaner->cleanIn(new BodyCleaningPass($document, BodyCleaningInputs::withEntryAuthor($entryAuthor)));` | `App\Service\Reader\BodyCleaning\BodyCleaningPass`, `App\Tests\Support\BodyCleaningInputs` |
| `tests/Service/Reader/LeadingTitleRemoverTest.php` | `        $this->remover->removeFrom($document, $titleCandidates);` | `        $this->remover->cleanIn(new BodyCleaningPass($document, BodyCleaningInputs::withTitles($titleCandidates)));` | `App\Service\Reader\BodyCleaning\BodyCleaningPass`, `App\Tests\Support\BodyCleaningInputs` |
| `tests/Service/Reader/MediaOnlyLedeTest.php` | `        (new MediaOnlyLede())->restore($document, $excerpt);` | `        (new MediaOnlyLede())->cleanIn(new BodyCleaningPass($document, BodyCleaningInputs::withExcerpt($excerpt)));` | `App\Service\Reader\BodyCleaning\BodyCleaningPass`, `App\Tests\Support\BodyCleaningInputs` |

`tests/Service/ReaderAudit/LeadingEngagementMarkersTest.php`:
- Replace `        (new LeadingEngagementCleaner())->removeFrom($document, $entryAuthor);` with
```php
        (new LeadingEngagementCleaner())->cleanIn(
            new BodyCleaningPass($document, BodyCleaningInputs::withEntryAuthor($entryAuthor)),
        );
```
- Replace `use App\Service\Html\HtmlDocumentParser;` with
```php
use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
```
- Replace `use PHPUnit\Framework\TestCase;` with
```php
use App\Tests\Support\BodyCleaningInputs;
use PHPUnit\Framework\TestCase;
```

`tests/Service/Reader/Slideshow/SlideshowInserterTest.php`:
- Replace `use App\Service\Html\HtmlDocumentParser;` with
```php
use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
```
- Replace `use PHPUnit\Framework\TestCase;` with
```php
use App\Tests\Support\BodyCleaningInputs;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
```
- Replace both occurrences (replace_all) of `        (new SlideshowInserter(new SlideshowMarkup()))->insert($document, [$show]);` with `        $this->insert($document, [$show]);`.
- Replace `        (new SlideshowInserter(new SlideshowMarkup()))->insert($document, [$first, $second]);` with `        $this->insert($document, [$first, $second]);`.
- Replace
```php
    /** @return list<Slide> */
    private function slides(): array
```
with
```php
    /** @param list<Slideshow> $slideshows */
    private function insert(HTMLDocument $document, array $slideshows): void
    {
        (new SlideshowInserter(new SlideshowMarkup()))->cleanIn(
            new BodyCleaningPass($document, BodyCleaningInputs::withSlideshows($slideshows)),
        );
    }

    /** @return list<Slide> */
    private function slides(): array
```

Check:
```bash
git grep -nE -e '->(trimIn|linkIn|collapseIn|separateIn|rewriteIn)\(' -- tests/Service/Reader tests/Service/ReaderAudit
git grep -n -e 'inserter->insert(' -e 'Markup()))->insert(' -e 'Lede())->restore(' -- tests/Service/Reader
git grep -n -e '->removeFrom(' -- tests/Service/Reader tests/Service/ReaderAudit
```
Expected: the first two print nothing. The third prints only `RelatedTeaserGridRemoverTest`'s `removeFrom`, a pre-readability remover and not a body-cleaning step.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit/LeadingEngagementMarkersTest.php`
Expected: FAIL with `Class "App\Service\Reader\BodyCleaning\BodyCleaningPass" not found` in every edited test.

- [ ] **Step 3: Implement**

`src/Service/Reader/BodyCleaning/BodyCleaningStep.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning;

/** One step of the reader body clean; it mutates the pass's shared document in place. */
interface BodyCleaningStep
{
    public function cleanIn(BodyCleaningPass $pass): void;
}
```

`src/Service/Reader/BodyCleaning/BodyCleaningPass.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning;

use App\Service\Reader\Media\ArticleMedia;
use Dom\HTMLDocument;

/**
 * One clean of one body: the document every step mutates, what the extraction knows about the article, and the
 * one fact a step records for a later one.
 */
final class BodyCleaningPass
{
    private bool $embedsRecoveredInBody = false;

    public function __construct(
        public readonly HTMLDocument $document,
        public readonly BodyCleaningInput $input,
    ) {
    }

    public function recordEmbedsRecoveredInBody(): void
    {
        $this->embedsRecoveredInBody = true;
    }

    /** The page's media to place: no embed once the body recovered its own, so a video never shows twice. */
    public function discoveredMedia(): ArticleMedia
    {
        return $this->embedsRecoveredInBody ? $this->input->media->withoutEmbeds() : $this->input->media;
    }
}
```

`src/Service/Reader/Media/InBodyEmbedRewriter.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\BodyCleaningStep;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Turns a publisher's in-body player into a link the reader renders, where the publisher put it. It runs before
 * EntrySanitizer, so the iframe still has its src; an iframe no provider claims is left for the sanitizer to drop.
 */
final readonly class InBodyEmbedRewriter implements BodyCleaningStep
{
    public function __construct(
        private EmbedProviders $providers,
        private MediaMarkup $markup,
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $rewritten = false;
        foreach (iterator_to_array($pass->document->getElementsByTagName('iframe')) as $iframe) {
            $rewritten = $this->rewriteOne($pass->document, $iframe) || $rewritten;
        }
        if ($rewritten) {
            $pass->recordEmbedsRecoveredInBody();
        }
    }

    private function rewriteOne(HTMLDocument $body, Element $iframe): bool
    {
        $target = $this->providers->resolve($iframe->getAttribute('src') ?? '');
        if ($target === null || $iframe->parentNode === null) {
            return false;
        }

        $iframe->parentNode->replaceChild($this->markup->embedLink($body, $target), $iframe);

        return true;
    }
}
```

`src/Service/Reader/RecipeFacts/RecipeFactsCleaner.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\BodyCleaningStep;

/**
 * Replaces each recognized recipe-fact block with the reader's own facts
 * figure, in place, so the surrounding article order is kept.
 */
final readonly class RecipeFactsCleaner implements BodyCleaningStep
{
    public function __construct(
        private RecipeFactsRecognizer $recognizer = new RecipeFactsRecognizer(),
        private RecipeFactsMarkup $markup = new RecipeFactsMarkup(),
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        foreach ($this->recognizer->recognize($pass->document) as $card) {
            $figure = $this->markup->figureFor($pass->document, $card->facts);
            $card->container->parentNode?->replaceChild($figure, $card->container);
        }
    }
}
```

The eleven classes that keep their document method, now private, behind `cleanIn()`. Each takes three edits:
1. The imports: add `use App\Service\Reader\BodyCleaning\BodyCleaningPass;` and `use App\Service\Reader\BodyCleaning\BodyCleaningStep;` directly before the anchor import named below (alphabetical position).
2. The class line gains `implements BodyCleaningStep`.
3. The public method is replaced as shown.

Nothing else in these files changes, so `SubstackPosterLink`'s behaviour is untouched (D-host).

`src/Service/Reader/Media/SubstackPosterLink.php` (imports before `use Dom\Element;`; `final readonly class SubstackPosterLink` → `final readonly class SubstackPosterLink implements BodyCleaningStep`). Replace
```php
    public function linkIn(HTMLDocument $body): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->linkIn($pass->document);
    }

    private function linkIn(HTMLDocument $body): void
```

`src/Service/Reader/PlayerChromeCleaner.php` (imports before `use App\Service\Reader\Media\NarrationSignals;`; `final readonly class PlayerChromeCleaner` → `… implements BodyCleaningStep`). Replace
```php
    public function cleanIn(HTMLDocument $document): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->removePlayerChromeFrom($pass->document);
    }

    private function removePlayerChromeFrom(HTMLDocument $document): void
```

`src/Service/Reader/NavigationChromeTrimmer.php` (imports before `use Dom\Element;`; `final readonly class NavigationChromeTrimmer` → `… implements BodyCleaningStep`). Replace
```php
    public function trimIn(HTMLDocument $document): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->trimIn($pass->document);
    }

    private function trimIn(HTMLDocument $document): void
```

`src/Service/Reader/EdgeBoilerplateTrimmer.php` (imports before `use Dom\Element;`; `final readonly class EdgeBoilerplateTrimmer` → `… implements BodyCleaningStep`): the same replacement as `NavigationChromeTrimmer` (`trimIn`).

`src/Service/Reader/LeadingEngagementCleaner.php` (imports before `use App\Service\Text\Whitespace;`; `final readonly class LeadingEngagementCleaner` → `… implements BodyCleaningStep`). Replace
```php
    public function removeFrom(HTMLDocument $document, ?string $entryAuthor): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->removeFrom($pass->document, $pass->input->entryAuthor);
    }

    private function removeFrom(HTMLDocument $document, ?string $entryAuthor): void
```

`src/Service/Reader/LeadingTitleRemover.php` (imports before `use App\Service\Text\Whitespace;`; `final readonly class LeadingTitleRemover` → `… implements BodyCleaningStep`). Replace
```php
    /** @param list<string|null> $titleCandidates */
    public function removeFrom(HTMLDocument $document, array $titleCandidates): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->removeFrom($pass->document, $pass->input->titleCandidates);
    }

    /** @param list<string|null> $titleCandidates */
    private function removeFrom(HTMLDocument $document, array $titleCandidates): void
```

`src/Service/Reader/Slideshow/SlideshowInserter.php` (imports before `use App\Service\Reader\Media\PageTextBlocks;`; `final readonly class SlideshowInserter` → `… implements BodyCleaningStep`). Replace
```php
    /** @param list<Slideshow> $slideshows */
    public function insert(HTMLDocument $body, array $slideshows): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->insert($pass->document, $pass->input->slideshows);
    }

    /** @param list<Slideshow> $slideshows */
    private function insert(HTMLDocument $body, array $slideshows): void
```

`src/Service/Reader/DuplicateBlockCollapser.php` (imports before `use App\Service\Reader\Media\EmbedProviders;`; `final readonly class DuplicateBlockCollapser` → `… implements BodyCleaningStep`). Replace
```php
    public function collapseIn(HTMLDocument $document): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->collapseIn($pass->document);
    }

    private function collapseIn(HTMLDocument $document): void
```

`src/Service/Reader/MediaOnlyLede.php` (imports before `use Dom\Element;`; `final readonly class MediaOnlyLede` → `… implements BodyCleaningStep`). Replace
```php
    public function restore(HTMLDocument $document, ?string $excerpt): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->restore($pass->document, $pass->input->excerpt);
    }

    private function restore(HTMLDocument $document, ?string $excerpt): void
```

`src/Service/Reader/AuthorBio/AuthorBioSeparator.php` (imports directly after `use App\Service\Reader\BlockText;`; `final readonly class AuthorBioSeparator` → `… implements BodyCleaningStep`). Replace
```php
    public function separateIn(HTMLDocument $document): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->separateIn($pass->document);
    }

    private function separateIn(HTMLDocument $document): void
```

`src/Service/Reader/Media/Teaser/TeaserPlayerInserter.php`:
- Replace `use App\Service\Reader\ImageIdentity;` with
```php
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\BodyCleaningStep;
use App\Service\Reader\ImageIdentity;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
```
- `final readonly class TeaserPlayerInserter` → `final readonly class TeaserPlayerInserter implements BodyCleaningStep`.
- Replace
```php
    /**
     * @param list<TeaserPlayer> $teasers
     * @param list<string>       $articleMediaUrls the players the media pipeline already placed
     */
    public function insert(HTMLDocument $document, array $teasers, array $articleMediaUrls): void
```
with
```php
    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->insert($pass->document, $pass->input->teasers, $this->placedMediaUrls($pass->input->media));
    }

    /** @return list<string> the players the media pipeline placed, so no teaser is rebuilt over one (#948) */
    private function placedMediaUrls(ArticleMedia $media): array
    {
        return array_map(static fn (MediaCandidate $candidate): string => $candidate->url, $media->candidates);
    }

    /**
     * @param list<TeaserPlayer> $teasers
     * @param list<string>       $articleMediaUrls the players the media pipeline already placed
     */
    private function insert(HTMLDocument $document, array $teasers, array $articleMediaUrls): void
```
The URLs come from the input media, not `discoveredMedia()`: the old `clean()` passed `mediaUrls($media)` of the unfiltered media.

`src/Service/Reader/ReaderBodyCleaner.php` (whole file). The class docblock, the constructor and every inline comment are B4's, unchanged. Only the calls change, and `mediaUrls()` moves into `TeaserPlayerInserter`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\Slideshow\SlideshowInserter;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Cleans readability's article HTML for the reader view through one shared
 * \Dom\HTMLDocument: parse once, rewrite in-body media, repair the page's own
 * players, drop the duplicate leading title, trim edge boilerplate, plan where
 * page-discovered media belongs, restore the lead image against that plan,
 * reconcile the media into the body, restore the lede of a media-only article,
 * serialise once — mirroring FetchedPageNormalizer's discipline of never
 * serialising and re-parsing between steps (#586, #684, #748).
 *
 * Handed on to EntrySanitizer, the XSS boundary, which stays string-in/
 * string-out since Symfony's HtmlSanitizer operates on strings, not a shared
 * DOM — the shared-document window ends here, with one serialise.
 *
 * A body too broken to parse is returned unchanged: readability output is
 * always parseable in practice, but a degenerate one falls through rather
 * than crashing the pass.
 *
 * The constructor collaborators are deliberate: this is the body-cleaning
 * pipeline's composition root, and each one is a seam the tests swap
 * independently (trimmers, restorers, inserters, …). Bagging them into a
 * parameter object would hide that coupling, not reduce it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final readonly class ReaderBodyCleaner
{
    public function __construct(
        private NavigationChromeTrimmer $navigationTrimmer,
        private LeadingTitleRemover $titleRemover,
        private LeadingEngagementCleaner $engagementCleaner,
        private EdgeBoilerplateTrimmer $boilerplateTrimmer,
        private ReaderLeadImage $leadImage,
        private InBodyEmbedRewriter $embedRewriter,
        private SubstackPosterLink $substackPoster,
        private PlayerChromeCleaner $playerChrome,
        private PageMediaInserter $mediaInserter,
        private SlideshowInserter $slideshowInserter,
        private RecipeFactsCleaner $recipeFactsCleaner,
        private TeaserPlayerInserter $teaserInserter,
        private MediaOnlyLede $mediaOnlyLede,
        private DuplicateBlockCollapser $duplicateCollapser,
        private AuthorBioSeparator $authorBioSeparator,
    ) {
    }

    #[WithSpan]
    public function clean(string $contentHtml, BodyCleaningInput $input): string
    {
        $document = HtmlDocumentParser::parseOrNull($contentHtml);
        if ($document === null) {
            return $contentHtml;
        }
        $pass = new BodyCleaningPass($document, $input);

        // Media first: a trimmer must not remove a block that now holds a
        // recovered player, and the lead-image restore must see a poster the
        // body has gained before it decides whether to add another picture.
        $this->embedRewriter->cleanIn($pass);
        $this->substackPoster->cleanIn($pass);
        $this->playerChrome->cleanIn($pass);

        $this->navigationTrimmer->cleanIn($pass);
        // Engagement first: it strips the leading kickers and breadcrumbs that
        // otherwise sit in front of the title and hide it from the title remover.
        $this->engagementCleaner->cleanIn($pass);
        $this->titleRemover->cleanIn($pass);
        $this->boilerplateTrimmer->cleanIn($pass);

        // A recreated slideshow replaces the publisher's original carousel, which
        // extraction leaves as a broken pile of markup or an empty box. Runs after
        // the trimmers so a trimmer cannot drop the anchor, before media planning
        // so the plan sees the finished structure.
        $this->slideshowInserter->cleanIn($pass);

        // A publisher's recipe-fact block (servings/calories/time) lays out with
        // its own stylesheet, which the sanitizer never receives; relay it to the
        // reader's own row-of-cells marker before media planning sees the body.
        $this->recipeFactsCleaner->cleanIn($pass);

        // Drop the dek a responsive page ships twice, one copy hidden by CSS the
        // scraper never runs (#963; the fragile image half was removed in #1088).
        $this->duplicateCollapser->cleanIn($pass);

        // plan() only classifies, so restore() still sees every body image; apply()'s
        // mutation runs after, or the hero would come back (#755). A top-placed video or
        // embed takes the lead position, so no hero is restored above it (#907).
        $plan = $this->mediaInserter->plan($document, $pass->discoveredMedia());
        $restoredHero = $plan->topPlacesLeadVisual() ? null : $this->leadImage->restore($document, $input->leadImage);
        $this->mediaInserter->apply($document, $plan, $restoredHero);

        // Inline teasers the extraction reduced to a lone thumbnail: rebuild each
        // as a player where its still still sits, skipping any the media pipeline
        // already placed so the lead media is never mistaken for one (#948).
        $this->teaserInserter->cleanIn($pass);

        // A gallery or video article whose only prose lived in a dropped header
        // now has a body of pure media; give it back the lede readability kept as
        // the excerpt. Runs last, so it judges "media-only" against the final body.
        $this->mediaOnlyLede->cleanIn($pass);

        // Over the settled body: set the trailing author bio and its disclosure
        // apart from the article prose they otherwise run on into (#1000).
        $this->authorBioSeparator->cleanIn($pass);

        // Last, over the finished body: the feed's real pixel sizes on the
        // images and players it enumerated, so none of them reflows the article.
        FeedDimensionStamper::stampInto($document, $input->feedMedia);

        return $document->saveHtml();
    }
}
```

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reader
php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php tests/Service/Tracing/TracedServiceMethodsTest.php
```
Expected: PASS, with the fixture suites unchanged.

- [ ] **Step 5: Deletion checks**

Break each line, run the named test, and restore it by hand:
1. In `InBodyEmbedRewriter::cleanIn()`, delete `$pass->recordEmbedsRecoveredInBody();`. Expected: `InBodyEmbedRewriterTest::testRecordsARecoveredEmbedSoTheDiscoveredEmbedsStandDown` and `ReaderBodyCleanerTest::testSuppressesDiscoveredEmbedsWhenTheBodyHadItsOwn` fail.
2. In `BodyCleaningPass::discoveredMedia()`, swap the two branches of the ternary. Expected: both `BodyCleaningPassTest` tests fail.
3. In `TeaserPlayerInserter::cleanIn()`, replace `$this->placedMediaUrls($pass->input->media)` with `[]`. Expected: `TeaserPlayerInserterTest::testSkipsATeaserAlreadyAmongTheArticleMedia` fails.
4. In `LeadingTitleRemover::cleanIn()`, replace `$pass->input->titleCandidates` with `[]`. Expected: the `LeadingTitleRemoverTest` removal tests fail.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on every changed PHP file. Expected: green, with no new PHPMD finding (every class gains one short public method).
```bash
git add src/Service/Reader tests/Service/Reader tests/Service/ReaderAudit/LeadingEngagementMarkersTest.php tests/Support/BodyCleaningPasses.php
git status --short
git commit -m "refactor(#1163): each body-cleaning collaborator is a step over one per-pass context"
```
Expected: `git status --short` lists only the files this task names.

---

### Task B6: The ordered pipeline in `services.yaml`; `PageMediaPlacement`; the suppression goes

**Files:**
- Create: `src/Service/Reader/BodyCleaning/PageMediaPlacement.php`
- Create: `tests/Service/Reader/BodyCleaning/PageMediaPlacementTest.php`, `tests/Service/Reader/ReaderBodyCleanerWiringTest.php`
- Modify (rewritten in full): `src/Service/Reader/ReaderBodyCleaner.php`, `src/Service/Reader/FeedDimensionStamper.php`
- Modify (docblock only; both name `ReaderBodyCleaner` as the one that runs restore between plan and apply): `src/Service/Reader/Media/PageMediaInserter.php`, `src/Service/Reader/Media/MediaInsertionPlan.php`
- Modify: `config/services.yaml` (one block added after the `FetchedPageNormalizer` block)
- Modify (tests): `tests/Service/Reader/ReaderBodyCleanerTest.php` (imports, `setUp()`, `steps()`), `tests/Service/Reader/ArticleExtractorTest.php` (imports, `bodyCleaner()`), `tests/Service/Reader/Slideshow/SlideshowExtractionTest.php` (imports, `cleaner()`), `tests/Service/Reader/FeedDimensionStamperTest.php` (imports, `stamp()`)

**Interfaces:**
- `ReaderBodyCleaner::__construct(iterable<BodyCleaningStep> $steps)`. `clean()` is unchanged, and so is its `#[WithSpan]`.
- Produces: `App\Service\Reader\BodyCleaning\PageMediaPlacement implements BodyCleaningStep`, with `__construct(PageMediaInserter $mediaInserter, ReaderLeadImage $leadImage)`.
- `FeedDimensionStamper` becomes `final readonly class … implements BodyCleaningStep`, and its static `stampInto()` goes.
- Produces (tests): `ReaderBodyCleanerTest::steps(EmbedProviders $embedProviders): list<BodyCleaningStep>`, the hand-built list in wiring order (the `FetchedPageNormalizerTest::repairs()` precedent).

- [ ] **Step 1: Write the failing tests**

`tests/Service/Reader/ReaderBodyCleanerWiringTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ArticleExtractor;
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use App\Service\Reader\BodyCleaning\PageMediaPlacement;
use App\Service\Reader\DuplicateBlockCollapser;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\FeedDimensionStamper;
use App\Service\Reader\LeadingEngagementCleaner;
use App\Service\Reader\LeadingTitleRemover;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\InBodyEmbedRewriter;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\SubstackPosterLink;
use App\Service\Reader\Media\Teaser\TeaserPlayerInserter;
use App\Service\Reader\MediaOnlyLede;
use App\Service\Reader\NavigationChromeTrimmer;
use App\Service\Reader\PlayerChromeCleaner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Reader\RecipeFacts\RecipeFactsCleaner;
use App\Service\Reader\Slideshow\SlideshowInserter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReaderBodyCleanerWiringTest extends KernelTestCase
{
    /** The call sequence ReaderBodyCleaner::clean() hard-coded before #1163. The order is behaviour. */
    private const array ORDER = [
        InBodyEmbedRewriter::class,
        SubstackPosterLink::class,
        PlayerChromeCleaner::class,
        NavigationChromeTrimmer::class,
        LeadingEngagementCleaner::class,
        LeadingTitleRemover::class,
        EdgeBoilerplateTrimmer::class,
        SlideshowInserter::class,
        RecipeFactsCleaner::class,
        DuplicateBlockCollapser::class,
        PageMediaPlacement::class,
        TeaserPlayerInserter::class,
        MediaOnlyLede::class,
        AuthorBioSeparator::class,
        FeedDimensionStamper::class,
    ];

    public function testTheWiredStepsRunInTheOrderTheCleanerHardCoded(): void
    {
        self::assertSame(self::ORDER, $this->classesOf($this->wiredSteps()));
    }

    public function testTheTestsHandBuiltPipelineMatchesTheWiring(): void
    {
        $steps = ReaderBodyCleanerTest::steps(new EmbedProviders([new YouTubeEmbedProvider()]));

        self::assertSame(self::ORDER, $this->classesOf($steps));
    }

    /** @return iterable<mixed> */
    private function wiredSteps(): iterable
    {
        self::bootKernel();
        $extractor = self::getContainer()->get(ArticleExtractorInterface::class);
        self::assertInstanceOf(ArticleExtractor::class, $extractor);
        $cleaner = new \ReflectionProperty(ArticleExtractor::class, 'bodyCleaner')->getValue($extractor);
        self::assertInstanceOf(ReaderBodyCleaner::class, $cleaner);
        $steps = new \ReflectionProperty(ReaderBodyCleaner::class, 'steps')->getValue($cleaner);
        self::assertIsIterable($steps);

        return $steps;
    }

    /**
     * @param iterable<mixed> $steps
     *
     * @return list<string>
     */
    private function classesOf(iterable $steps): array
    {
        $classes = [];
        foreach ($steps as $step) {
            self::assertIsObject($step);
            $classes[] = $step::class;
        }

        return $classes;
    }
}
```
It reaches the cleaner through `ArticleExtractorInterface`, which `services_test.yaml` already makes public, so no test-only service entry is needed.

`tests/Service/Reader/BodyCleaning/PageMediaPlacementTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\PageMediaPlacement;
use App\Service\Reader\FeedMedia;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\ReaderLeadImage;
use App\Tests\Support\BodyCleaningInputs;
use PHPUnit\Framework\TestCase;

final class PageMediaPlacementTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private PageMediaPlacement $placement;

    protected function setUp(): void
    {
        $this->placement = new PageMediaPlacement(new PageMediaInserter(new MediaMarkup()), new ReaderLeadImage());
    }

    public function testRestoresTheHeroIntoABodyWithNoMediaToPlace(): void
    {
        $out = $this->placed(BodyCleaningInputs::withLeadImage($this->hero()));

        self::assertStringContainsString('cdn.test/hero.jpg', $out);
    }

    public function testSkipsTheHeroWhenAnEmbedIsTopPlaced(): void
    {
        $embed = new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/ccccccccccc',
            'https://i.ytimg.example/hqdefault.jpg',
            'Watch',
        );

        $out = $this->placed(new BodyCleaningInput([], $this->hero(), new ArticleMedia([$embed]), FeedMedia::none()));

        self::assertStringNotContainsString('cdn.test/hero.jpg', $out);
        self::assertStringContainsString('i.ytimg.example/hqdefault.jpg', $out);
    }

    /** #907: narration audio is not a lead visual, so the hero stays and the player sits below it. */
    public function testSeatsATopPlacedAudioPlayerBelowTheRestoredHero(): void
    {
        $audio = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->placed(new BodyCleaningInput([], $this->hero(), $audio, FeedMedia::none()));

        self::assertLessThan(strpos($out, '<audio'), strpos($out, 'cdn.test/hero.jpg'));
    }

    public function testPlacesNoDiscoveredEmbedOnceTheBodyRecoveredItsOwn(): void
    {
        $embed = new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb',
            null,
            'Watch',
        );
        $pass = new BodyCleaningPass(
            HtmlDocumentParser::parse('<p>' . self::PROSE . '</p>'),
            BodyCleaningInputs::withMedia(new ArticleMedia([$embed])),
        );
        $pass->recordEmbedsRecoveredInBody();

        $this->placement->cleanIn($pass);

        self::assertStringNotContainsString('bbbbbbbbbbb', $pass->document->saveHtml());
    }

    private function hero(): LeadImageCandidate
    {
        return new LeadImageCandidate('https://cdn.test/hero.jpg', BodyCleaningInputs::pageDrawingNothing());
    }

    private function placed(BodyCleaningInput $input): string
    {
        $pass = new BodyCleaningPass(HtmlDocumentParser::parse('<p>' . self::PROSE . '</p>'), $input);
        $this->placement->cleanIn($pass);

        return $pass->document->saveHtml();
    }
}
```

`tests/Service/Reader/ReaderBodyCleanerTest.php`:
- Replace `use App\Service\Reader\BodyCleaning\BodyCleaningInput;` with
```php
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\BodyCleaning\BodyCleaningStep;
use App\Service\Reader\BodyCleaning\PageMediaPlacement;
```
- Replace `use App\Service\Reader\EdgeBoilerplateTrimmer;` with
```php
use App\Service\Reader\EdgeBoilerplateTrimmer;
use App\Service\Reader\FeedDimensionStamper;
```
- Replace B3's `setUp()`
```php
    protected function setUp(): void
    {
        $markup = new MediaMarkup();
        $embedProviders = new EmbedProviders([new YouTubeEmbedProvider(), new SpotifyEmbedProvider()]);
        $this->cleaner = new ReaderBodyCleaner(
            new NavigationChromeTrimmer(),
            new LeadingTitleRemover(),
            new LeadingEngagementCleaner(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter($embedProviders, $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new PageMediaInserter($markup),
            new SlideshowInserter(new SlideshowMarkup()),
            new RecipeFactsCleaner(),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new DuplicateBlockCollapser($embedProviders),
            new AuthorBioSeparator(),
        );
    }
```
with
```php
    protected function setUp(): void
    {
        $this->cleaner = new ReaderBodyCleaner(
            self::steps(new EmbedProviders([new YouTubeEmbedProvider(), new SpotifyEmbedProvider()])),
        );
    }

    /** @return list<BodyCleaningStep> the steps in the order services.yaml wires them */
    public static function steps(EmbedProviders $embedProviders): array
    {
        $markup = new MediaMarkup();

        return [
            new InBodyEmbedRewriter($embedProviders, $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new NavigationChromeTrimmer(),
            new LeadingEngagementCleaner(),
            new LeadingTitleRemover(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new SlideshowInserter(new SlideshowMarkup()),
            new RecipeFactsCleaner(),
            new DuplicateBlockCollapser($embedProviders),
            new PageMediaPlacement(new PageMediaInserter($markup), new ReaderLeadImage()),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new AuthorBioSeparator(),
            new FeedDimensionStamper(),
        ];
    }
```

`tests/Service/Reader/ArticleExtractorTest.php`:
- Replace
```php
    private function bodyCleaner(): ReaderBodyCleaner
    {
        $markup = new MediaMarkup();
        $embedProviders = $this->providers();

        return new ReaderBodyCleaner(
            new NavigationChromeTrimmer(),
            new LeadingTitleRemover(),
            new LeadingEngagementCleaner(),
            new EdgeBoilerplateTrimmer(new BoilerplateVerdict()),
            new ReaderLeadImage(),
            new InBodyEmbedRewriter($embedProviders, $markup),
            new SubstackPosterLink(),
            new PlayerChromeCleaner(),
            new PageMediaInserter($markup),
            new SlideshowInserter(new SlideshowMarkup()),
            new RecipeFactsCleaner(),
            new TeaserPlayerInserter(new TeaserPlayerMarkup()),
            new MediaOnlyLede(),
            new DuplicateBlockCollapser($embedProviders),
            new AuthorBioSeparator(),
        );
    }
```
with
```php
    private function bodyCleaner(): ReaderBodyCleaner
    {
        return new ReaderBodyCleaner(ReaderBodyCleanerTest::steps($this->providers()));
    }
```
- Drop the nineteen imports only that method used. `ReaderBodyCleanerTest` shares the namespace, so it needs no import:
```bash
perl -ni -e 'print unless /^use App\\Service\\Reader\\(AuthorBio\\AuthorBioSeparator|BoilerplateVerdict|DuplicateBlockCollapser|EdgeBoilerplateTrimmer|LeadingEngagementCleaner|LeadingTitleRemover|Media\\InBodyEmbedRewriter|Media\\MediaMarkup|Media\\Teaser\\TeaserPlayerInserter|Media\\Teaser\\TeaserPlayerMarkup|Media\\PageMediaInserter|Media\\SubstackPosterLink|MediaOnlyLede|NavigationChromeTrimmer|PlayerChromeCleaner|RecipeFacts\\RecipeFactsCleaner|ReaderLeadImage|Slideshow\\SlideshowInserter|Slideshow\\SlideshowMarkup);$/' tests/Service/Reader/ArticleExtractorTest.php
git grep -c "^use " -- tests/Service/Reader/ArticleExtractorTest.php
```
Expected: 19 fewer `use` lines than before. `composer stan` reports no unknown class, which proves none of them had another use.

`tests/Service/Reader/Slideshow/SlideshowExtractionTest.php`:
- Run the same `perl -ni` line against this file. It imports the same nineteen, and B3's `cleaner()` was their only user.
- Replace `use App\Tests\Support\BodyCleaningInputs;` with
```php
use App\Tests\Service\Reader\ReaderBodyCleanerTest;
use App\Tests\Support\BodyCleaningInputs;
```
- Replace B3's `cleaner()` (the fifteen-argument `new ReaderBodyCleaner(...)` body) with
```php
    private function cleaner(): ReaderBodyCleaner
    {
        $embedProviders = new EmbedProviders([new YouTubeEmbedProvider()]);

        return new ReaderBodyCleaner(ReaderBodyCleanerTest::steps($embedProviders));
    }
```

`tests/Service/Reader/FeedDimensionStamperTest.php`:
- Replace `use App\Service\Reader\FeedDimensionStamper;` with
```php
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\FeedDimensionStamper;
```
- Replace `use Dom\HTMLDocument;` with
```php
use App\Tests\Support\BodyCleaningInputs;
use Dom\HTMLDocument;
```
- Replace `        FeedDimensionStamper::stampInto($document, $feed);` with
```php
        (new FeedDimensionStamper())->cleanIn(
            new BodyCleaningPass($document, BodyCleaningInputs::withFeedMedia($feed)),
        );
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/ReaderBodyCleanerWiringTest.php tests/Service/Reader/BodyCleaning tests/Service/Reader/ReaderBodyCleanerTest.php tests/Service/Reader/FeedDimensionStamperTest.php`
Expected: FAIL.
- `PageMediaPlacementTest`: `Class "App\Service\Reader\BodyCleaning\PageMediaPlacement" not found`.
- `ReaderBodyCleanerTest`: the same (from `steps()`).
- `FeedDimensionStamperTest`: `Call to undefined method App\Service\Reader\FeedDimensionStamper::cleanIn()`.
- The wiring test: the same class-not-found from `steps()`, and `Property App\Service\Reader\ReaderBodyCleaner::$steps does not exist`.

- [ ] **Step 3: Implement**

`src/Service/Reader/BodyCleaning/PageMediaPlacement.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning;

use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\ReaderLeadImage;

/**
 * Places the page's media and restores the lead image between planning and applying (#755): plan() only
 * classifies, so the restore still sees every body image. A top-placed video or embed takes the lead position,
 * so no hero is restored above it; a narration audio player leaves the hero its place (#907).
 */
final readonly class PageMediaPlacement implements BodyCleaningStep
{
    public function __construct(
        private PageMediaInserter $mediaInserter,
        private ReaderLeadImage $leadImage,
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $plan = $this->mediaInserter->plan($pass->document, $pass->discoveredMedia());
        $restoredHero = $plan->topPlacesLeadVisual()
            ? null
            : $this->leadImage->restore($pass->document, $pass->input->leadImage);
        $this->mediaInserter->apply($pass->document, $plan, $restoredHero);
    }
}
```

`src/Service/Reader/FeedDimensionStamper.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\BodyCleaningStep;
use Dom\Element;

/**
 * Stamps the feed-declared pixel size onto a reader image or video the feed enumerated (#914), so the browser
 * reserves its box instead of reflowing the article. A picture the reader already sized is left untouched.
 */
final readonly class FeedDimensionStamper implements BodyCleaningStep
{
    public function cleanIn(BodyCleaningPass $pass): void
    {
        foreach ($pass->document->querySelectorAll('img[src], video[src]') as $element) {
            $this->stamp($element, $pass->input->feedMedia);
        }
    }

    private function stamp(Element $element, FeedMedia $feedMedia): void
    {
        if ($element->hasAttribute('width') || $element->hasAttribute('height')) {
            return;
        }
        $medium = $feedMedia->declaredMediumFor($element->getAttribute('src') ?? '');
        if ($medium === null || $medium->width === null || $medium->height === null) {
            return;
        }

        $element->setAttribute('width', (string) $medium->width);
        $element->setAttribute('height', (string) $medium->height);
    }
}
```

`src/Service/Reader/ReaderBodyCleaner.php` (whole file). The `ExcessiveParameterList` suppression goes. The ordering comments live beside the list in `services.yaml`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\BodyCleaningStep;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Cleans readability's article HTML through one shared document: parse once, run the steps in the order
 * services.yaml lists them, serialise once for EntrySanitizer (#586, #684, #748). A body too broken to parse is
 * returned unchanged, so a degenerate readability output falls through instead of failing the extraction.
 */
final readonly class ReaderBodyCleaner
{
    /** @param iterable<BodyCleaningStep> $steps */
    public function __construct(private iterable $steps)
    {
    }

    #[WithSpan]
    public function clean(string $contentHtml, BodyCleaningInput $input): string
    {
        try {
            $pass = new BodyCleaningPass(HtmlDocumentParser::parse($contentHtml), $input);
        } catch (UnparseableHtmlException) {
            return $contentHtml;
        }

        foreach ($this->steps as $step) {
            $step->cleanIn($pass);
        }

        return $pass->document->saveHtml();
    }
}
```

`src/Service/Reader/Media/PageMediaInserter.php`, replace
```php
/**
 * Places media the source page offers but the extracted body never had.
 *
 * Two phases run around ReaderLeadImage::restore() (see ReaderBodyCleaner).
 * `plan()` classifies each candidate: reconcilable (poster matches a body
 * `<img>`, so the player replaces it), anchored (body still holds the prose
 * block the media followed, so the player goes after it), or top-placed (no
 * trace in the body). `apply()` mutates in that order, then prepends
 * top-placed candidates in source order — split so restore() can check
 * `topPlacesLeadVisual()` before either mutation runs.
 */
```
with
```php
/**
 * Places media the page offers but the extracted body lost: in place of a body `<img>` its poster matches, after
 * the prose block it followed, or at the top. `plan()` only classifies and `apply()` mutates, so
 * PageMediaPlacement decides the hero restore between them (#755).
 */
```

`src/Service/Reader/Media/MediaInsertionPlan.php`, replace
```php
/**
 * A read-only classification of where recovered media belongs: each reconcile
 * pair names a body `<img>` to swap for a player in place, each anchored pair
 * names the body block the player follows, and the remainder go to the top,
 * in source order. Built by `PageMediaInserter::plan()` before
 * `ReaderLeadImage::restore()` runs, so restore can consult
 * `topPlacesLeadVisual()` without any document mutation happening first
 * (see ReaderBodyCleaner).
 */
```
with
```php
/**
 * Where recovered media belongs, classified before anything mutates the body: the body `<img>` each player
 * replaces, the block each player follows, and the rest for the top, in source order (see PageMediaPlacement).
 */
```

`config/services.yaml`, replace
```yaml
                - '@App\Service\Reader\Repair\HorizontalRuleUnwrapper'
    App\Service\Search\EntrySearchInterface: '@App\Service\Search\EntrySearchWithFallback'
```
with
```yaml
                - '@App\Service\Reader\Repair\HorizontalRuleUnwrapper'

    # The reader body's cleaning steps, run in this order over one shared document. The order is
    # behaviour (ReaderBodyCleanerWiringTest pins it): a new step joins at its place in this list.
    App\Service\Reader\ReaderBodyCleaner:
        arguments:
            $steps:
                # Media first: no trimmer may drop a block that now holds a recovered player.
                - '@App\Service\Reader\Media\InBodyEmbedRewriter'
                - '@App\Service\Reader\Media\SubstackPosterLink'
                - '@App\Service\Reader\PlayerChromeCleaner'
                - '@App\Service\Reader\NavigationChromeTrimmer'
                # Engagement before the title: its kickers hide the title from the remover.
                - '@App\Service\Reader\LeadingEngagementCleaner'
                - '@App\Service\Reader\LeadingTitleRemover'
                - '@App\Service\Reader\EdgeBoilerplateTrimmer'
                # After the trimmers, which could drop its anchor; before media placement, which must see it.
                - '@App\Service\Reader\Slideshow\SlideshowInserter'
                - '@App\Service\Reader\RecipeFacts\RecipeFactsCleaner'
                - '@App\Service\Reader\DuplicateBlockCollapser'
                - '@App\Service\Reader\BodyCleaning\PageMediaPlacement'
                # After placement, so a teaser is never rebuilt over placed lead media (#948).
                - '@App\Service\Reader\Media\Teaser\TeaserPlayerInserter'
                # These judge the settled body; the feed's pixel sizes go on last.
                - '@App\Service\Reader\MediaOnlyLede'
                - '@App\Service\Reader\AuthorBio\AuthorBioSeparator'
                - '@App\Service\Reader\FeedDimensionStamper'
    App\Service\Search\EntrySearchInterface: '@App\Service\Search\EntrySearchWithFallback'
```

- [ ] **Step 4: Run to verify they pass**

```bash
bin/console cache:clear
php bin/phpunit tests/Service/Reader
php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php tests/Service/Tracing/TracedServiceMethodsTest.php
git grep -n "SuppressWarnings" -- src/Service/Reader
git diff origin/develop -- config/services.yaml | grep '^[-+] ' | grep -c 'Repair'
```
Expected:
- PASS, with the fixture suites unchanged.
- The `SuppressWarnings` grep prints nothing.
- The last count is `0`: the `FetchedPageNormalizer` `$repairs` list is untouched.

- [ ] **Step 5: Deletion checks**

Break each line, run the named test, and restore it by hand (the YAML too, never with `git checkout --`):
1. In `config/services.yaml`, swap the `LeadingEngagementCleaner` and `LeadingTitleRemover` lines, then run `bin/console cache:clear && php bin/phpunit tests/Service/Reader/ReaderBodyCleanerWiringTest.php`. Expected: `testTheWiredStepsRunInTheOrderTheCleanerHardCoded` fails, showing the two swapped entries.
2. In `ReaderBodyCleanerTest::steps()`, move `new FeedDimensionStamper()` above `new AuthorBioSeparator()`. Expected: `testTheTestsHandBuiltPipelineMatchesTheWiring` fails.
3. In `PageMediaPlacement::cleanIn()`, replace `$plan->topPlacesLeadVisual()` with `$plan->topPlaced !== []`. Expected: `PageMediaPlacementTest::testSeatsATopPlacedAudioPlayerBelowTheRestoredHero` fails.
4. Replace the whole ternary with `$this->leadImage->restore($pass->document, $pass->input->leadImage)`. Expected: `testSkipsTheHeroWhenAnEmbedIsTopPlaced` fails.
5. In `FeedDimensionStamper::cleanIn()`, replace `$pass->input->feedMedia` with `FeedMedia::none()`. Expected: `FeedDimensionStamperTest::testStampsFeedDimensionsOnAMatchingImage` fails.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on every changed PHP file. Expected: green, with `ReaderBodyCleaner` PHPMD-clean without a suppression.
```bash
git add config/services.yaml src/Service/Reader tests/Service/Reader
git status --short
git commit -m "refactor(#1163): the body cleaner runs an ordered step pipeline wired in services.yaml"
```
Expected: `git status --short` lists only the files this task names.

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
Expected: all green, including the reader fixture regression suite (Global Constraints). Before the MySQL leg, check that the containers are current and clear the container's DI cache (`docker compose exec php bin/console cache:clear`). The pipeline's order comes from `services.yaml`, which a stale compiled container would not see (memory "Docker dev serves stale DI container"). Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'`. ERROR and WARNING block; an `AutowireWrongClass` false positive gets the suppression the Global Constraints describe.

- [ ] **Step 3: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`, with its angle reviewers (reuse, simplification, efficiency, altitude) as parallel agents. Apply only fixes that keep every gate green and undo no ruling (D-host, D2–D6, D9). Adapter classes, a priority tag or a second pipeline file are rulings, not simplifications. Re-run Step 1's gates if anything changed, and commit as `refactor(#1163): simplify pass`.

- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Same work, same order, same arguments.** Read `git show origin/develop:backend/src/Service/Reader/ReaderBodyCleaner.php` side by side with the `$steps` list and every `cleanIn()`. The fifteen operations must run in the same order over the same document, with the same inputs:
   - the entry author and the title candidates;
   - the slideshows;
   - the discovered media, filtered only after an in-body embed was recovered;
   - the lead image, skipped exactly when `topPlacesLeadVisual()`;
   - the teaser URLs from the *unfiltered* media;
   - the excerpt and the feed media.
2. **Reader output unchanged → no `ReaderCacheService.VERSION` bump.** `git diff origin/develop --stat -- ../frontend` is empty, and no assertion that reads `tests/Fixtures/reader/` or `tests/Fixtures/Slideshow/` changed (`git diff origin/develop -- tests/Service/Reader/ArticleExtractorTest.php` touches only the builder, its imports and the added pin).
3. **The early exit is the old result.** Before, an unparseable page reached `failed($url, 'unextractable')` with a null detail; after, too. Which calls no longer run on that path (page scans, media scan, readability)? None of them affected the result.
4. **Settled constraints:**
   - the `PageRepair` list is byte-identical;
   - `PageMediaScannerWiringTest` and `RawPage` are untouched (one shared raw document);
   - `ArticleReadability` still extracts the collapsed variant, and runs its calls in the old order when a chain exists.
5. **D-host:** `git diff origin/develop -- src/Service/Reader/Media/SubstackPosterLink.php src/Service/Reader/Repair/SubstackGatedVideoPlaceholder.php src/Service/Reader/Slideshow/TagesschauCarouselRecognizer.php` shows only `SubstackPosterLink`'s `implements`, its `cleanIn()` and the `linkIn()` visibility.
6. **Clean Code:**
   - no boolean flag parameter left on `ReaderLeadImage`;
   - `ReaderBodyCleaner` has no suppression and one collaborator;
   - no step exposes its old document method publicly;
   - phptramp: the pass is read, not forwarded.
7. **Comment bar** on every rewritten file and every edited docblock.
8. **No closing keyword** for #1163 in any commit message on this branch.

Fix each finding rated Important or above in its own commit (`refactor(#1163): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin refactor/1163-body-cleaning-pipeline
git log origin/develop..HEAD --format=%B | grep -inE '(close|fix|resolve)[sd]?\b' && echo 'STOP: closing keyword in a commit' || true
gh pr create --base develop --title "refactor(#1163): typed parse failure; the reader body cleaner is an ordered pipeline" --body "$(cat <<'BODY'
Refs #1163 (PR B of three).

- **Parse failure is typed.** `HtmlDocumentParser::parse()` throws `UnparseableHtmlException`. `FetchedPageNormalizer::normalize()` returns a document or throws, and `ArticleExtractor` stops once, with the same `unextractable` result as before. `PageImageInventory`, `LeadFigureCaptions`, `PaywallSignals` and `ArticleReadability` take a non-null document.
- **The body cleaner is a pipeline.** `ReaderBodyCleaner::clean(string, BodyCleaningInput)` runs `BodyCleaningStep`s in the order `config/services.yaml` lists them, over one `BodyCleaningPass` (the shared document, the input, and the one fact a step records for a later step). The thirteen collaborators it used to call one by one are steps now. `PageMediaPlacement` plans, restores the lead and applies. `ReaderLeadImage::restore()` lost its flag, because the placement skips the restore instead. The `ExcessiveParameterList` suppression is gone.
- `ReaderBodyCleanerWiringTest` pins the wired order against the call sequence the old `clean()` hard-coded, and pins the tests' hand-built list against the wiring.
- The `PageRepair` order, the shared raw document and the second readability grab are unchanged. `SubstackPosterLink` became a step with its behaviour untouched (host-specific exception, ruled).

No wire change and no reader-output change, so no `ReaderCacheService.VERSION` bump.
BODY
)"
gh pr view --json body --jq .body | grep -inE '(close|fix|resolve)[sd]?\b' && echo 'STOP: closing keyword in the body' || true
```
Expected: neither `STOP` line prints.

- [ ] **Step 6: Merge when green**

As in Finishing PR A, Step 6: a Monitor on `gh pr checks <PR> --watch --fail-fast`, then `gh pr merge <PR> --merge`, never `--auto`.

- [ ] **Step 7: Verify the issue stayed open**

Run: `gh issue view 1163 --json state --jq .state`. Expected: `OPEN`.

---

# PR C — Typed extraction failures; the extractor reads as orchestration

### Task C0: Preflight (PR B merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR B merged and cut the branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1163 --json state --jq .state
git grep -n "App\\\\Service\\\\Reader\\\\ReaderBodyCleaner:" origin/develop -- backend/config/services.yaml
git switch -c refactor/1163-extractor-failures origin/develop
```
Expected: `OPEN`, and one `services.yaml` hit (PR B's `$steps` block). Anything else: stop and report.

- [ ] **Step 2: Re-take the PR C sites (from `backend/`)**

```bash
git grep -nE "failed\([^)]*'(fetch|unextractable|empty|mismatch|no_url)'" -- src tests
git grep -n "->reason" -- src/Http tests/Service/Reader tests/Service/ReaderAudit
git grep -n "'fetch', 'no_url', 'unextractable', 'empty', 'mismatch'" -- tests
```
Expected:
- The `failed(` calls with a string reason:
  - `src`: `ArticleExtractor` (6: `fetch` 1, `unextractable` 2, `empty` 3), `ExtractionCoverageGate` (`mismatch`), `Controller/Api/EntryReaderController` (`no_url`);
  - `tests`: `ExtractionCoverageGateTest` (`fetch`), `EntryReaderControllerTest` (`fetch`), `ReaderAuditRunnerTest` (`mismatch`).
- `->reason`: `src/Http/ReaderJson.php` (1), `ArticleExtractorTest` (4, B2's pin included), `ExtractionCoverageGateTest` (4).
- The `CleanupMarkersTest` loop (1).


- [ ] **Step 3: Anchors and cross-task interfaces (read-only; from `backend/`)**

No dry run: apply no edit, run no test. Read, compare, report.
1. Every "replace" anchor in C1–C3 that targets develop code (not text an earlier C task writes) occurs verbatim at the branch's starting SHA. PR B rewrote `ArticleExtractor`; C1's six `failed($url, '…')` edits and C2's full rewrite read against that file.
2. C3's call sites of the extractor seam:
   ```bash
   git grep -n -- '->extract(' -- src/Controller/Api/EntryReaderController.php src/Service/ReaderAudit/ReaderAuditRunner.php
   git grep -n 'implements ArticleExtractorInterface' -- src tests
   git grep -n -- '->requests\[' -- tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php
   git grep -c -- '->extract(' -- tests/Service/Reader/ArticleExtractorTest.php
   git grep -nE -- "->extract\('[^']*', " -- tests/Service/Reader/ArticleExtractorTest.php
   ```
   Expected (as at `566ee103`; PR B changes none of these lines):
   - `EntryReaderController.php` (`: $this->extractor->extract(`) and `ReaderAuditRunner.php` (`$this->extractor->extract($entry->url, $entry->title, $entry->author),`).
   - Four implementers: `src/Service/Reader/ArticleExtractor.php`, `tests/Support/FakeArticleExtractor.php`, and two anonymous classes in `tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`.
   - Three lines: `EntryReaderControllerTest.php` (`['author']`, `['feedMedia']`) and `ReaderAuditRunnerTest.php` (`['author']`).
   - `40`: the 39 at `566ee103` plus B2's pin `testABlankPageStopsAsUnextractable` (URL-only).
   - Seven calls that pass hints: the `feedMedia:` call, the title-and-author call (`'The Haiku Challenge', 'Clark Strand'`), and five title-only calls (`'Political Balancing Act'`, `'Shoulder to Shoulder'`, `'September Wallpapers'`, `'Block Component Headline'`, `'Inline video headline'`).
3. Cross-task interfaces: C1's `ExtractionFailure` and `ExtractionResult::failed(?string, ExtractionFailure, ?string)` against C2's rewrite; C2's `EntryHints(?string $title = null, ?string $author = null, ?FeedMedia $feedMedia = null)` against C3's callers.

Record the result in the task report. A drifted anchor or a mismatched interface: stop and report.

---

### Task C1: `ExtractionFailure`

**Files:**
- Create: `src/Service/Reader/ExtractionFailure.php`, `tests/Service/Reader/ExtractionFailureTest.php`
- Modify: `src/Service/Reader/ExtractionResult.php` (rewritten in full), `src/Service/Reader/ArticleExtractor.php` (perl), `src/Service/Reader/ExtractionCoverageGate.php` (one line), `src/Controller/Api/EntryReaderController.php` (one import, one line), `src/Http/ReaderJson.php` (one line)
- Modify (tests): `tests/Service/Reader/ExtractionCoverageGateTest.php`, `tests/Controller/Api/EntryReaderControllerTest.php`, `tests/Service/ReaderAudit/CleanupMarkersTest.php`, `tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`, `tests/Service/Reader/ArticleExtractorTest.php`

**Interfaces:**
- Produces: `enum App\Service\Reader\ExtractionFailure: string { NoUrl = 'no_url'; Fetch = 'fetch'; Unextractable = 'unextractable'; Empty = 'empty'; Mismatch = 'mismatch'; }`.
- `ExtractionResult::$reason` is `?ExtractionFailure`, and `ExtractionResult::failed(?string $url, ExtractionFailure $reason, ?string $detail = null)`.
- The wire is unchanged: `ReaderJson` writes `$reason->value`, which `frontend/src/app/reader/models.ts:437` already expects.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Reader/ExtractionFailureTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ExtractionFailure;
use PHPUnit\Framework\TestCase;

final class ExtractionFailureTest extends TestCase
{
    /** The reader client (frontend/src/app/reader/models.ts) switches on exactly these strings. */
    public function testTheWireValuesAreTheOnesTheClientSwitchesOn(): void
    {
        self::assertSame(
            ['no_url', 'fetch', 'unextractable', 'empty', 'mismatch'],
            array_map(static fn (ExtractionFailure $failure): string => $failure->value, ExtractionFailure::cases()),
        );
    }
}
```

`tests/Service/Reader/ExtractionCoverageGateTest.php`:
- Replace `use App\Service\Reader\ExtractionResult;` with
```php
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
```
- Replace all four occurrences (replace_all) of `self::assertSame('mismatch', ` with `self::assertSame(ExtractionFailure::Mismatch, `.
- Replace `        $failed = ExtractionResult::failed('https://site.test/post', 'fetch');` with `        $failed = ExtractionResult::failed('https://site.test/post', ExtractionFailure::Fetch);`.
- Check: `git grep -n "'mismatch'\|'fetch'" -- tests/Service/Reader/ExtractionCoverageGateTest.php` prints nothing.

`tests/Controller/Api/EntryReaderControllerTest.php`:
- Replace `use App\Service\Reader\ExtractionResult;` with
```php
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
```
- Replace `            ExtractionResult::failed('https://example.com/article', 'fetch', 'HTTP 403 Forbidden'),` with `            ExtractionResult::failed('https://example.com/article', ExtractionFailure::Fetch, 'HTTP 403 Forbidden'),`.
- The JSON assertions (`self::assertSame('fetch', $body['reason']);`, `'mismatch'`, `'no_url'`) stay strings: they pin the wire.

`tests/Service/ReaderAudit/CleanupMarkersTest.php`:
- Replace `use App\Service\Reader\ExtractionResult;` with
```php
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
```
- Replace
```php
        foreach (['fetch', 'no_url', 'unextractable', 'empty', 'mismatch'] as $reason) {
            $failed = ExtractionResult::failed(null, $reason);

            self::assertSame([], $this->markers->detect($failed, $this->entry(), null), $reason);
        }
```
with
```php
        foreach (ExtractionFailure::cases() as $reason) {
            $failed = ExtractionResult::failed(null, $reason);

            self::assertSame([], $this->markers->detect($failed, $this->entry(), null), $reason->value);
        }
```

`tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`:
- Replace `use App\Service\Reader\ExtractionResult;` with
```php
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
```
- Replace `        $extractor->willReturn(ExtractionResult::failed('https://example.test/a', 'mismatch'));` with `        $extractor->willReturn(ExtractionResult::failed('https://example.test/a', ExtractionFailure::Mismatch));`.

`tests/Service/Reader/ArticleExtractorTest.php`:
- Replace `use App\Service\Reader\ExtractionResult;` with
```php
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
```
- Replace both occurrences (replace_all) of `        self::assertSame('fetch', $result->reason);` with `        self::assertSame(ExtractionFailure::Fetch, $result->reason);`.
- Replace `        self::assertContains($result->reason, ['unextractable', 'empty']);` with `        self::assertContains($result->reason, [ExtractionFailure::Unextractable, ExtractionFailure::Empty]);`.
- Replace `        self::assertSame('unextractable', $result->reason);` (B2's pin) with `        self::assertSame(ExtractionFailure::Unextractable, $result->reason);`.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/ExtractionFailureTest.php tests/Service/Reader/ExtractionCoverageGateTest.php tests/Service/ReaderAudit/CleanupMarkersTest.php`
Expected: FAIL with `Class "App\Service\Reader\ExtractionFailure" not found`.

- [ ] **Step 3: Implement**

`src/Service/Reader/ExtractionFailure.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/** Why an extraction failed. The values are wire vocabulary the reader client switches on. */
enum ExtractionFailure: string
{
    /** The entry has no source URL to fetch. */
    case NoUrl = 'no_url';
    /** The page could not be retrieved: network, SSRF-blocked, oversized. */
    case Fetch = 'fetch';
    /** The page could not be parsed, or readability found no article in it. */
    case Unextractable = 'unextractable';
    /** The extraction held too little to show, before or after sanitising. */
    case Empty = 'empty';
    /** The extraction did not reflect the article the feed carries (#654). */
    case Mismatch = 'mismatch';
}
```

`src/Service/Reader/ExtractionResult.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * An extraction's outcome: the cleaned article, or why it failed, so the client falls back to the feed body.
 * `paywalled` marks an ok body that is the free preview of a paywalled article (#785).
 */
final readonly class ExtractionResult
{
    /** Derived, not stored: a failure always carries a reason and a success never does. */
    public bool $ok;

    private function __construct(
        public ?string $url,
        public ?ExtractionFailure $reason,
        public ?string $detail,
        public ?string $title,
        public ?string $byline,
        public ?string $siteName,
        public ?string $contentHtml,
        public ?string $excerpt,
        public bool $paywalled,
    ) {
        $this->ok = $reason === null;
    }

    public static function ok(
        string $url,
        string $title,
        ?string $byline,
        ?string $siteName,
        string $contentHtml,
        ?string $excerpt,
        bool $paywalled = false,
    ): self {
        return new self($url, null, null, $title, $byline, $siteName, $contentHtml, $excerpt, $paywalled);
    }

    /** `$detail` is the underlying cause in words when there is one, such as a fetch's HTTP status. */
    public static function failed(?string $url, ExtractionFailure $reason, ?string $detail = null): self
    {
        return new self($url, $reason, $detail, null, null, null, null, null, false);
    }
}
```

`src/Service/Reader/ArticleExtractor.php` (same namespace as the enum, so no import; C2 rewrites the file):
- Replace `ExtractionResult::failed($url, 'fetch', $failure->getMessage())` with `ExtractionResult::failed($url, ExtractionFailure::Fetch, $failure->getMessage())`.
- Replace both occurrences (replace_all) of `ExtractionResult::failed($url, 'unextractable')` with `ExtractionResult::failed($url, ExtractionFailure::Unextractable)`.
- Replace all three occurrences (replace_all) of `ExtractionResult::failed($url, 'empty')` with `ExtractionResult::failed($url, ExtractionFailure::Empty)`.
- Check: `git grep -n "failed(\$url, '" -- src/Service/Reader/ArticleExtractor.php` prints nothing, and `git grep -c "ExtractionFailure::" -- src/Service/Reader/ArticleExtractor.php` prints `6`.

`src/Service/Reader/ExtractionCoverageGate.php`: replace `        return ExtractionResult::failed($result->url, 'mismatch');` with `        return ExtractionResult::failed($result->url, ExtractionFailure::Mismatch);`.

`src/Controller/Api/EntryReaderController.php`:
- Replace `use App\Service\Reader\ExtractionCoverageGate;` with
```php
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionFailure;
```
- Replace `            ? ExtractionResult::failed(null, 'no_url')` with `            ? ExtractionResult::failed(null, ExtractionFailure::NoUrl)`.

`src/Http/ReaderJson.php`: replace `                'reason' => (string) $r->reason,` with `                'reason' => (string) $r->reason?->value,`.

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php tests/Command/ReaderAuditCommandTest.php
```
Expected: PASS. `EntryReaderControllerTest` still reads `'fetch'`, `'mismatch'` and `'no_url'` off the wire.

- [ ] **Step 5: Deletion checks**

Break each line, run the named test, and restore it by hand:
1. In `ReaderJson::one()`, replace `$r->reason?->value` with `$r->reason?->name`. Expected: `EntryReaderControllerTest` fails on `'fetch'`/`'mismatch'`/`'no_url'` (it reads `Fetch`, `Mismatch`, `NoUrl`).
2. In `ExtractionFailure`, change `case NoUrl = 'no_url';` to `case NoUrl = 'no-url';`. Expected: `ExtractionFailureTest` and `EntryReaderControllerTest`'s no-URL test fail.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on every changed PHP file. Expected: green.
```bash
git add src/Service/Reader/ExtractionFailure.php src/Service/Reader/ExtractionResult.php src/Service/Reader/ArticleExtractor.php \
  src/Service/Reader/ExtractionCoverageGate.php src/Controller/Api/EntryReaderController.php src/Http/ReaderJson.php \
  tests/Service/Reader/ExtractionFailureTest.php tests/Service/Reader/ExtractionCoverageGateTest.php \
  tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/CleanupMarkersTest.php \
  tests/Service/ReaderAudit/ReaderAuditRunnerTest.php tests/Service/Reader/ArticleExtractorTest.php
git commit -m "refactor(#1163): extraction failure reasons are an enum"
```

---

### Task C2: `ArticleExtractor` is `final readonly` and reads as orchestration

**Files:**
- Create: `src/Service/Reader/EntryHints.php`, `src/Service/Reader/ArticlePage.php`, `src/Service/Reader/ArticleContentGate.php`, `src/Service/Reader/Exception/ArticleNotExtractedException.php`
- Create: `tests/Service/Reader/ArticleContentGateTest.php`, `tests/Service/Reader/Exception/ArticleNotExtractedExceptionTest.php`, `tests/Service/Reader/EntryHintsTest.php`
- Modify: `src/Service/Reader/ArticleExtractor.php` (rewritten in full)

**Interfaces:**
- Produces: `App\Service\Reader\Exception\ArticleNotExtractedException(ExtractionFailure $failure)`, with `public readonly ExtractionFailure $failure` and the message `The article was not extracted: <value>.`.
- Produces: `App\Service\Reader\ArticleContentGate::contentOf(Article $article, ArticleMedia $media): string`, which throws `ArticleNotExtractedException(Empty)`.
- Produces: `App\Service\Reader\EntryHints(?string $title = null, ?string $author = null, ?FeedMedia $feedMedia = null)`, with `public ?string $title`, `public ?string $author` and a non-null `public FeedMedia $feedMedia` (`FeedMedia::none()` when none is given; D12). It is born here in its final form, so C3 can use `new EntryHints()` as a default.
- Produces: `App\Service\Reader\ArticlePage(PageResponse $page, HTMLDocument $normalized, PageImageInventory $pageImages, LeadFigureCaptions $leadCaptions, bool $paywalled, ArticleMedia $media, list<Slideshow> $slideshows, list<TeaserPlayer> $teasers)`.
- `ArticleExtractorInterface::extract()` keeps its four parameters in this task; `ArticleExtractor::extract()` builds `EntryHints` from them. C3 changes the seam (ruling D-P2, D-reconcile-4).

- [ ] **Step 1: Write the failing tests**

`tests/Service/Reader/ArticleContentGateTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ArticleContentGate;
use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use fivefilters\Readability\Article;
use PHPUnit\Framework\TestCase;

final class ArticleContentGateTest extends TestCase
{
    public function testHandsBackTheContentOfALongEnoughArticle(): void
    {
        $article = $this->article('<p>body</p>', str_repeat('a', 200));

        self::assertSame('<p>body</p>', ArticleContentGate::contentOf($article, ArticleMedia::none()));
    }

    public function testRefusesAnArticleReadabilityFoundNoContentFor(): void
    {
        $this->assertRefused($this->article(null, str_repeat('a', 500)), ArticleMedia::none());
    }

    public function testRefusesAShortArticleWithoutMedia(): void
    {
        $this->assertRefused($this->article('<p>body</p>', str_repeat('a', 199)), ArticleMedia::none());
    }

    /** #748: recovered media is itself evidence of an article, so a thin text passes. */
    public function testAcceptsAShortArticleWhoseMediaCarriesIt(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Video, 'https://x.test/clip.mp4')]);

        self::assertSame('<p>body</p>', ArticleContentGate::contentOf($this->article('<p>body</p>', 'short'), $media));
    }

    public function testCountsTheCharactersOfTheTrimmedText(): void
    {
        $padded = '   ' . str_repeat('ü', 199) . '   ';

        $this->assertRefused($this->article('<p>body</p>', $padded), ArticleMedia::none());
    }

    private function assertRefused(Article $article, ArticleMedia $media): void
    {
        try {
            ArticleContentGate::contentOf($article, $media);
            self::fail('Expected the article to be refused.');
        } catch (ArticleNotExtractedException $refusal) {
            self::assertSame(ExtractionFailure::Empty, $refusal->failure);
        }
    }

    private function article(?string $content, string $textContent): Article
    {
        return new Article(
            title: 'Title',
            byline: null,
            dir: null,
            lang: null,
            content: $content,
            textContent: $textContent,
            length: mb_strlen($textContent),
            excerpt: null,
            siteName: null,
            publishedTime: null,
            image: null,
            images: [],
            contentElement: null,
        );
    }
}
```

`tests/Service/Reader/EntryHintsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EntryHints;
use App\Service\Reader\FeedMedia;
use PHPUnit\Framework\TestCase;

final class EntryHintsTest extends TestCase
{
    public function testNoHintsMeanNoTitleNoAuthorAndNoFeedMedia(): void
    {
        $hints = new EntryHints();

        self::assertNull($hints->title);
        self::assertNull($hints->author);
        self::assertNull($hints->feedMedia->posterFallback());
    }

    public function testKeepsTheFeedMediaItIsGiven(): void
    {
        $feedMedia = FeedMedia::none();

        self::assertSame($feedMedia, (new EntryHints(feedMedia: $feedMedia))->feedMedia);
    }
}
```

`tests/Service/Reader/Exception/ArticleNotExtractedExceptionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Exception;

use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\ExtractionFailure;
use PHPUnit\Framework\TestCase;

final class ArticleNotExtractedExceptionTest extends TestCase
{
    public function testCarriesAndNamesItsFailure(): void
    {
        $exception = new ArticleNotExtractedException(ExtractionFailure::Empty);

        self::assertSame(ExtractionFailure::Empty, $exception->failure);
        self::assertSame('The article was not extracted: empty.', $exception->getMessage());
    }
}
```

- [ ] **Step 2: Run to verify they fail, and pin the extractor**

```bash
php bin/phpunit tests/Service/Reader/ArticleContentGateTest.php tests/Service/Reader/Exception tests/Service/Reader/EntryHintsTest.php
php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php
```
Expected: the first run FAILs with `Class "App\Service\Reader\ArticleContentGate" not found`, `Class "App\Service\Reader\Exception\ArticleNotExtractedException" not found` and `Class "App\Service\Reader\EntryHints" not found`. The second PASSes (pin): it holds every failure reason and every fixture outcome the rewrite must keep.

- [ ] **Step 3: Implement**

`src/Service/Reader/Exception/ArticleNotExtractedException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Exception;

use App\Service\Reader\ExtractionFailure;

final class ArticleNotExtractedException extends \RuntimeException
{
    public function __construct(public readonly ExtractionFailure $failure)
    {
        parent::__construct(sprintf('The article was not extracted: %s.', $failure->value));
    }
}
```

`src/Service/Reader/ArticleContentGate.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\Media\ArticleMedia;
use fivefilters\Readability\Article;

/**
 * Readability's content, when there is enough of it to show. Recovered media is itself evidence of an article,
 * so a thin text with media passes (#748).
 */
final class ArticleContentGate
{
    private const int MIN_TEXT_LENGTH = 200;

    public static function contentOf(Article $article, ArticleMedia $media): string
    {
        if ($article->content === null || ($media->isEmpty() && self::textLength($article) < self::MIN_TEXT_LENGTH)) {
            throw new ArticleNotExtractedException(ExtractionFailure::Empty);
        }

        return $article->content;
    }

    private static function textLength(Article $article): int
    {
        return mb_strlen(trim((string) $article->textContent));
    }
}
```
The old `$article->content === null || !$article->hasContent()` was one test twice: `hasContent()` is `content !== null`.

`src/Service/Reader/EntryHints.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * What the feed entry tells the extractor: its title (to drop a headline repeated in the body), its author, and the
 * media it declared, trusted over the reader's guesses for a scraped URL it enumerated (#914).
 */
final readonly class EntryHints
{
    public FeedMedia $feedMedia;

    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        ?FeedMedia $feedMedia = null,
    ) {
        $this->feedMedia = $feedMedia ?? FeedMedia::none();
    }
}
```

`src/Service/Reader/ArticlePage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Slideshow\Slideshow;
use Dom\HTMLDocument;

/** What the extractor reads off the fetched page before readability consumes the normalised document (#684). */
final readonly class ArticlePage
{
    /**
     * @param list<Slideshow>    $slideshows
     * @param list<TeaserPlayer> $teasers
     */
    public function __construct(
        public PageResponse $page,
        public HTMLDocument $normalized,
        public PageImageInventory $pageImages,
        public LeadFigureCaptions $leadCaptions,
        public bool $paywalled,
        public ArticleMedia $media,
        public array $slideshows,
        public array $teasers,
    ) {
    }
}
```

`src/Service/Reader/ArticleExtractor.php` (whole file). The call order is the old one:
1. fetch, then normalise;
2. the page inventory and lead captions, then the raw parse;
3. the paywall verdict, the media scan, the slideshow scan and the teaser scan;
4. readability, then the content gate;
5. the lead caption and `resolveForBody()`, then the clean;
6. the sanitiser.
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\Media\BodyMediaResolver;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\RawPage;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Reader\Paywall\PaywallSignals;
use App\Service\Reader\Slideshow\ContainerSignature;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Sanitize\EntrySanitizer;
use fivefilters\Readability\Article;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Fetch, normalise, read the page, run readability, clean the body, sanitise (EntrySanitizer is the XSS barrier).
 * Every page read happens before readability consumes the normalised document (#684, #748). An ordinary failure is
 * a `failed` result, never a throw, so the endpoint stays 200 and the client falls back to the feed body.
 */
final readonly class ArticleExtractor implements ArticleExtractorInterface
{
    public function __construct(
        private HtmlPageFetcher $fetcher,
        private FetchedPageNormalizer $normalizer,
        private ReaderBodyCleaner $bodyCleaner,
        private EntrySanitizer $sanitizer,
        private PageMediaScanner $mediaScanner,
        private BodyMediaResolver $bodyMedia,
        private SlideshowScanner $slideshowScanner,
        private TeaserPlayerScanner $teaserScanner,
        private ArticleReadability $readability,
    ) {
    }

    #[WithSpan]
    public function extract(
        string $url,
        ?string $entryTitle = null,
        ?string $entryAuthor = null,
        ?FeedMedia $feedMedia = null,
    ): ExtractionResult {
        $hints = new EntryHints($entryTitle, $entryAuthor, $feedMedia);
        try {
            return $this->extractPage($this->fetcher->fetch($url), $hints);
        } catch (PageFetchException $failure) {
            return ExtractionResult::failed($url, ExtractionFailure::Fetch, $failure->getMessage());
        } catch (UnparseableHtmlException) {
            return ExtractionResult::failed($url, ExtractionFailure::Unextractable);
        } catch (ArticleNotExtractedException $failure) {
            return ExtractionResult::failed($url, $failure->failure);
        }
    }

    private function extractPage(PageResponse $page, EntryHints $hints): ExtractionResult
    {
        $scanned = $this->scan($page, $hints->feedMedia);
        $containers = $this->slideshowContainers($scanned->slideshows);
        $article = $this->readability->richest($scanned->normalized, $page, $containers)
            ?? throw new ArticleNotExtractedException(ExtractionFailure::Unextractable);
        $content = ArticleContentGate::contentOf($article, $scanned->media);
        $body = $this->bodyCleaner->clean($content, $this->bodyCleaningInput($article, $scanned, $hints));
        $clean = $this->sanitizer->sanitize($body) ?? throw new ArticleNotExtractedException(ExtractionFailure::Empty);

        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $article->title,
            byline: $article->byline,
            siteName: $article->siteName,
            contentHtml: $clean,
            excerpt: $article->excerpt,
            paywalled: $scanned->paywalled,
        );
    }

    private function scan(PageResponse $page, FeedMedia $feedMedia): ArticlePage
    {
        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);
        $leadCaptions = LeadFigureCaptions::fromDocument($normalized);
        $rawPage = RawPage::parse($page->html, $page->finalUrl);

        return new ArticlePage(
            page: $page,
            normalized: $normalized,
            pageImages: $pageImages,
            leadCaptions: $leadCaptions,
            paywalled: PaywallSignals::isPreview($rawPage->document, $normalized),
            media: $this->mediaScanner->scan($rawPage, $feedMedia),
            slideshows: $this->slideshowScanner->scan($normalized),
            teasers: $this->teaserScanner->scan($normalized, $page->finalUrl),
        );
    }

    private function bodyCleaningInput(Article $article, ArticlePage $scanned, EntryHints $hints): BodyCleaningInput
    {
        return new BodyCleaningInput(
            titleCandidates: [$article->title, $hints->title],
            leadImage: new LeadImageCandidate(
                $article->image,
                $scanned->pageImages,
                $scanned->leadCaptions->captionFor($article->image),
            ),
            media: $this->bodyMedia->resolveForBody($scanned->media, $scanned->page->html),
            feedMedia: $hints->feedMedia,
            entryAuthor: $hints->author,
            slideshows: $scanned->slideshows,
            teasers: $scanned->teasers,
            excerpt: $article->excerpt,
        );
    }

    /**
     * @param list<Slideshow> $slideshows
     * @return list<ContainerSignature>
     */
    private function slideshowContainers(array $slideshows): array
    {
        return array_values(array_filter(
            array_map(static fn (Slideshow $slideshow): ?ContainerSignature => $slideshow->container, $slideshows),
        ));
    }
}
```
The three `failed($url, 'empty')` exits become two throw sites, `ArticleContentGate` and the sanitiser. The one `catch` maps them with the URL, as it maps `fetch` and `unextractable`.

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reader tests/Service/Html
php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php tests/Command/ReaderAuditCommandTest.php tests/Service/Tracing/TracedServiceMethodsTest.php
git grep -n "failed(\$url, ExtractionFailure::Empty)\|mb_strlen" -- src/Service/Reader/ArticleExtractor.php
```
Expected:
- PASS, with the fixture suites unchanged.
- `TracedServiceMethodsTest` still finds `#[WithSpan]` on `ArticleExtractor::extract`.
- The grep prints nothing.

- [ ] **Step 5: Deletion checks**

Break each line, run the named test, and restore it by hand:
1. In `ArticleContentGate::contentOf()`, change `< self::MIN_TEXT_LENGTH` to `<= self::MIN_TEXT_LENGTH`. Expected: `testHandsBackTheContentOfALongEnoughArticle` fails.
2. Remove `trim(` … `)` from `textLength()` (keep the cast). Expected: `testCountsTheCharactersOfTheTrimmedText` fails.
3. In `ArticleExtractor::extract()`, replace `return ExtractionResult::failed($url, $failure->failure);` with `return ExtractionResult::failed($url, ExtractionFailure::Fetch);`. Expected: `ArticleExtractorTest::testUnextractablePageMapsToReason` fails.
4. In the same method, delete the `catch (UnparseableHtmlException)` arm with its body. Expected: `ArticleExtractorTest::testABlankPageStopsAsUnextractable` errors with the uncaught exception.
5. In `ArticleNotExtractedException`, delete the `parent::__construct(...)` line. Expected: `ArticleNotExtractedExceptionTest` fails.
6. In `EntryHints::__construct()`, replace `$feedMedia ?? FeedMedia::none()` with `FeedMedia::none()`. Expected: `EntryHintsTest::testKeepsTheFeedMediaItIsGiven` and `ArticleExtractorTest::testStampsFeedDeclaredDimensionsOnAMatchingBodyImage` fail.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on every changed PHP file. Expected: green, with `ArticleExtractor` PHPMD-clean (nine constructor parameters, below the threshold of 10) and every method short.
```bash
git add src/Service/Reader/ArticleExtractor.php src/Service/Reader/EntryHints.php src/Service/Reader/ArticlePage.php \
  src/Service/Reader/ArticleContentGate.php src/Service/Reader/Exception/ArticleNotExtractedException.php \
  tests/Service/Reader/ArticleContentGateTest.php tests/Service/Reader/Exception/ArticleNotExtractedExceptionTest.php \
  tests/Service/Reader/EntryHintsTest.php
git commit -m "refactor(#1163): the article extractor is final readonly and maps every failure in one place"
```

---

### Task C3: The extractor seam takes `EntryHints`

Ruling D-P2; D12, D-reconcile-2, D-reconcile-3 and D-reconcile-4. C0 Step 3 checked this task's anchors.

**Files:**
- Modify: `src/Service/Reader/ArticleExtractorInterface.php` (rewritten in full), `src/Service/Reader/ArticleExtractor.php` (the `extract()` signature), `src/Controller/Api/EntryReaderController.php` (one import, one call), `src/Service/ReaderAudit/ReaderAuditRunner.php` (one import, one call)
- Modify (tests): `tests/Support/FakeArticleExtractor.php` (rewritten in full), `tests/Service/Reader/ArticleExtractorTest.php` (one import, seven calls), `tests/Controller/Api/EntryReaderControllerTest.php` (two assertions), `tests/Service/ReaderAudit/ReaderAuditRunnerTest.php` (imports, two anonymous extractors, one assertion)

**Interfaces:**
- Changes: `App\Service\Reader\ArticleExtractorInterface::extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult`. `ArticleExtractor` and `FakeArticleExtractor` implement exactly that.
- Changes: `App\Tests\Support\FakeArticleExtractor` records `public array $hints` (`list<EntryHints>`) instead of `$requests`; `$calls` (`list<string>`) stays.
- Unchanged: every URL-only call (32 of the 39 at `566ee103`, plus B2's pin), `services.yaml` and `services_test.yaml` (the interface alias), and `#[WithSpan]` on `ArticleExtractor::extract`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Reader/ArticleExtractorTest.php`:
- Replace `use App\Service\Reader\ExtractionFailure;` with
```php
use App\Service\Reader\EntryHints;
use App\Service\Reader\ExtractionFailure;
```
- In `testStampsFeedDeclaredDimensionsOnAMatchingBodyImage()`, replace
```php
        $result = $extractor->extract('https://site.test/post', feedMedia: FeedMedia::fromEntry($entry));
```
with
```php
        $hints = new EntryHints(feedMedia: FeedMedia::fromEntry($entry));
        $result = $extractor->extract('https://site.test/post', $hints);
```
- In `testStripsASemanticHeaderMasthead()`, replace
```php
        $result = $extractor->extract('https://site.test/post', 'The Haiku Challenge', 'Clark Strand');
```
with
```php
        $hints = new EntryHints(title: 'The Haiku Challenge', author: 'Clark Strand');
        $result = $extractor->extract('https://site.test/post', $hints);
```
- In `testStripsABreadcrumbSeparatorAndKickerMasthead()`, replace
```php
        $result = $extractor->extract('https://site.test/post', 'Political Balancing Act');
```
with
```php
        $result = $extractor->extract('https://site.test/post', new EntryHints(title: 'Political Balancing Act'));
```
- In `testStripsAMetaToolbarThatSitsBelowAStandfirst()`, replace
```php
        $result = $extractor->extract('https://site.test/post', 'Shoulder to Shoulder');
```
with
```php
        $result = $extractor->extract('https://site.test/post', new EntryHints(title: 'Shoulder to Shoulder'));
```
- In `testStripsAReadingTimeAndCategoryMetaBar()`, replace
```php
        $result = $extractor->extract('https://site.test/post', 'September Wallpapers');
```
with
```php
        $result = $extractor->extract('https://site.test/post', new EntryHints(title: 'September Wallpapers'));
```
- In `testKeepsHeadingsAndImagesOnBlockComponentPages()`, replace
```php
        $result = $extractor->extract('https://site.test/post', 'Block Component Headline');
```
with
```php
        $result = $extractor->extract('https://site.test/post', new EntryHints(title: 'Block Component Headline'));
```
- In `testRestoresAnInlineVideoAfterTheParagraphItFollowed()`, replace
```php
        $result = $extractor->extract('https://site.test/post', 'Inline video headline');
```
with
```php
        $result = $extractor->extract('https://site.test/post', new EntryHints(title: 'Inline video headline'));
```
- Check: `git grep -nE -- "->extract\('[^']*', '" -- tests/Service/Reader/ArticleExtractorTest.php` prints nothing, `git grep -c 'new EntryHints(' -- tests/Service/Reader/ArticleExtractorTest.php` prints `7`, and `git grep -c -- '->extract(' -- tests/Service/Reader/ArticleExtractorTest.php` still prints `40` (the other 33 calls pass only a URL and stay as they are).

`tests/Controller/Api/EntryReaderControllerTest.php`:
- Replace `        self::assertSame('Jana Steger', $fake->requests[0]['author']);` with `        self::assertSame('Jana Steger', $fake->hints[0]->author);`.
- Replace `        self::assertSame('https://example.com/poster.jpg', $fake->requests[0]['feedMedia']?->posterFallback());` with `        self::assertSame('https://example.com/poster.jpg', $fake->hints[0]->feedMedia->posterFallback());`.

`tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`: replace `        self::assertSame('Jana Steger', $extractor->requests[0]['author']);` with `        self::assertSame('Jana Steger', $extractor->hints[0]->author);`.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Reader/ArticleExtractorTest.php tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`
Expected: FAIL.
- The seven `ArticleExtractorTest` tests above error with a `TypeError`: `EntryHints` given where `?string` (`$entryTitle`) is expected.
- `testCarriesTheEntryAuthorIntoTheReaderExtraction`, `testCarriesTheFeedPosterFallbackIntoTheReaderExtraction` and `testCarriesTheSampledEntryAuthorIntoTheReaderExtraction` fail on the undefined `FakeArticleExtractor::$hints`.

- [ ] **Step 3: Implement**

`src/Service/Reader/ArticleExtractorInterface.php` (whole file). The `@param` notes moved into `EntryHints`' docblock in C2:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/** The reader endpoint's seam: tests swap in a fake through the public alias in services_test.yaml. */
interface ArticleExtractorInterface
{
    public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult;
}
```

`src/Service/Reader/ArticleExtractor.php`: replace
```php
    #[WithSpan]
    public function extract(
        string $url,
        ?string $entryTitle = null,
        ?string $entryAuthor = null,
        ?FeedMedia $feedMedia = null,
    ): ExtractionResult {
        $hints = new EntryHints($entryTitle, $entryAuthor, $feedMedia);
        try {
```
with
```php
    #[WithSpan]
    public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult
    {
        try {
```

`tests/Support/FakeArticleExtractor.php` (whole file):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\EntryHints;
use App\Service\Reader\ExtractionResult;

/**
 * Test double for the reader endpoint: returns a preconfigured outcome and
 * records every call, so a test can both control the response and assert the
 * extractor was (or was NOT) invoked without any outbound network I/O.
 */
final class FakeArticleExtractor implements ArticleExtractorInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<EntryHints> */
    public array $hints = [];

    private ?ExtractionResult $result = null;

    public function willReturn(ExtractionResult $result): void
    {
        $this->result = $result;
    }

    public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult
    {
        $this->calls[] = $url;
        $this->hints[] = $hints;

        return $this->result
            ?? throw new \LogicException('FakeArticleExtractor::extract called without a configured result.');
    }
}
```

`tests/Service/ReaderAudit/ReaderAuditRunnerTest.php`:
- Replace
```php
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\FeedMedia;
```
with
```php
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\EntryHints;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\ExtractionResult;
```
- Replace both occurrences (replace_all) of
```php
            public function extract(
                string $url,
                ?string $entryTitle = null,
                ?string $entryAuthor = null,
                ?FeedMedia $feedMedia = null,
            ): ExtractionResult {
```
with
```php
            public function extract(string $url, EntryHints $hints = new EntryHints()): ExtractionResult
            {
```

`src/Controller/Api/EntryReaderController.php`:
- Replace `use App\Service\Reader\ArticleExtractorInterface;` with
```php
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\EntryHints;
```
- Replace
```php
            : $this->extractor->extract(
                $url,
                $entry->getTitle(),
                $entry->getAuthor(),
                FeedMedia::fromEntry($entry),
            );
```
with
```php
            : $this->extractor->extract($url, new EntryHints(
                title: $entry->getTitle(),
                author: $entry->getAuthor(),
                feedMedia: FeedMedia::fromEntry($entry),
            ));
```

`src/Service/ReaderAudit/ReaderAuditRunner.php`:
- Replace `use App\Service\Reader\ArticleExtractorInterface;` with
```php
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\EntryHints;
```
- Replace `            $this->extractor->extract($entry->url, $entry->title, $entry->author),` with `            $this->extractor->extract($entry->url, new EntryHints(title: $entry->title, author: $entry->author)),`.

- [ ] **Step 4: Run to verify they pass**

```bash
php bin/phpunit tests/Service/Reader tests/Service/Html
php bin/phpunit tests/Service/ReaderAudit tests/Controller/Api/EntryReaderControllerTest.php tests/Command/ReaderAuditCommandTest.php tests/Service/Tracing/TracedServiceMethodsTest.php
git grep -n 'entryTitle\|?FeedMedia' -- src/Service/Reader/ArticleExtractor.php src/Service/Reader/ArticleExtractorInterface.php tests/Support/FakeArticleExtractor.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php
git grep -n -- '->requests\[' -- tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php
git grep -n 'implements ArticleExtractorInterface' -- src tests
```
Expected:
- PASS, with the fixture suites unchanged. `TracedServiceMethodsTest` still finds `#[WithSpan]` on `ArticleExtractor::extract`.
- The two greps print nothing.
- The four implementers from C0 Step 3, each with the two-parameter `extract()`.

- [ ] **Step 5: Deletion checks**

The changed assertions guard the new call sites. Break each line, run the named test, and restore it by hand:
1. In `ReaderAuditRunner`, drop `, author: $entry->author` from the `EntryHints`. Expected: `ReaderAuditRunnerTest::testCarriesTheSampledEntryAuthorIntoTheReaderExtraction` fails.
2. In `EntryReaderController`, delete the `feedMedia: FeedMedia::fromEntry($entry),` line. Expected: `EntryReaderControllerTest::testCarriesTheFeedPosterFallbackIntoTheReaderExtraction` fails.
3. In `ArticleExtractorTest::testStripsASemanticHeaderMasthead()`, drop `, author: 'Clark Strand'`. Expected: that test fails on `Clark Strand`. This proves the author still reaches the cleaner through the new seam.

- [ ] **Step 6: Gates and commit**

Run `composer check`, `composer md` and PhpStorm `lint_files` on every changed PHP file. Expected: green; `ArticleExtractor::extract()` has two parameters.
```bash
git add src/Service/Reader/ArticleExtractorInterface.php src/Service/Reader/ArticleExtractor.php \
  src/Controller/Api/EntryReaderController.php src/Service/ReaderAudit/ReaderAuditRunner.php \
  tests/Support/FakeArticleExtractor.php tests/Service/Reader/ArticleExtractorTest.php \
  tests/Controller/Api/EntryReaderControllerTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php
git commit -m "refactor(#1163): the extractor seam takes entry hints"
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
Expected: all green, including the reader fixture regression suite. Check that the containers are current before the MySQL leg. Then scan today's dev log as in Finishing PR A.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every file in `git diff --name-only --relative origin/develop -- '*.php'`. ERROR and WARNING block.

- [ ] **Step 3: /simplify**

Invoke the `simplify` skill over `git diff origin/develop...HEAD`, with its angle reviewers (reuse, simplification, efficiency, altitude) as parallel agents. Apply only fixes that keep every gate green and undo no ruling (D7, D8, D12, D-P2, D-reconcile-2 to D-reconcile-4). Re-run Step 1's gates if anything changed, and commit as `refactor(#1163): simplify pass`.

- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Every failure returns what it returned before, reason and detail:**
   - `fetch` with the exception message;
   - `unextractable` for an unparseable page and for "readability found nothing", with a null detail;
   - `empty` for no content, for a short text without media, and for a sanitiser `null`;
   - `mismatch` and `no_url` unchanged.

   Read `git show origin/develop:backend/src/Service/Reader/ArticleExtractor.php` against the new file.
2. **Call order:** the page reads all run before readability consumes the normalised document, in the old order. `resolveForBody()` runs after the lead caption lookup, as the old argument order had it. The media landing's mocked responses in `ArticleExtractorTest` are consumed in order, so an order slip fails there.
3. **Wire and output:**
   - `ReaderJson` emits the same strings;
   - `git diff origin/develop --stat -- ../frontend` is empty;
   - no fixture assertion changed;
   - no `ReaderCacheService.VERSION` bump is needed.
4. **Clean Code:**
   - `ArticleExtractor` is `final readonly`;
   - no method takes more than three parameters, and `extract()` is `(string $url, EntryHints $hints = new EntryHints())` on the interface, the extractor and the fake (D-P2);
   - every caller that passed hints builds `new EntryHints(...)` with named arguments, and every URL-only call is unchanged;
   - no `mb_strlen` in the orchestrator;
   - `ArticleNotExtractedException` lives in `Service/Reader/Exception`.
5. **Comment bar** on the rewritten files.
6. **The PR body says `Closes #1163`.**

Fix each finding rated Important or above in its own commit, re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin refactor/1163-extractor-failures
gh pr create --base develop --title "refactor(#1163): typed extraction failures; the article extractor reads as orchestration" --body "$(cat <<'BODY'
Closes #1163 (PR C of three; PR A and PR B are merged).

- **`ExtractionFailure`** is a string-backed enum whose values are the wire strings (`no_url`, `fetch`, `unextractable`, `empty`, `mismatch`). `ExtractionResult::$reason` is typed, and `ReaderJson` writes `->value`, so the JSON is unchanged.
- **`ArticleExtractor` is `final readonly`.** `extract()` maps every failure to a result in one `try`: `PageFetchException` becomes `fetch`, `UnparseableHtmlException` becomes `unextractable`, and `ArticleNotExtractedException` carries its own reason. `ArticlePage` holds what the extractor reads off the page before readability consumes it. `ArticleContentGate` owns the "enough text, or media that carries it" rule.
- **The extractor seam takes `EntryHints`.** `ArticleExtractorInterface::extract(string $url, EntryHints $hints = new EntryHints())` replaces the three loose hint parameters. `EntryReaderController` and `ReaderAuditRunner` build the hints with named arguments; URL-only calls are unchanged.

No wire change and no reader-output change.

Across the three PRs:
- reading state moved to `Service/Reading` behind one read marker, and "mark all read" takes a typed `ReadScope`;
- `ExactSetGuard` moved to `Service/Tag`;
- parse failure is typed;
- the body cleaner is an ordered pipeline, and its `ExcessiveParameterList` suppression is gone;
- the three host-specific classes stay as approved exceptions (ruled).
BODY
)"
```

- [ ] **Step 6: Merge when green, and verify the issue closed**

As in Finishing PR A, Step 6: a Monitor on `gh pr checks <PR> --watch --fail-fast`, then `gh pr merge <PR> --merge`, never `--auto`. Then run `gh issue view 1163 --json state --jq .state`. Expected: `CLOSED`. Do not close it by hand; if it stayed open, report.

- [ ] **Step 7: Report to the planner**

Send the planner the three PR numbers and merge SHAs, and these carry-forward items:
- **#1169:**
  - D-P3: the eight `parseOrNull()` callers.
  - A namespace-dependency rule that would keep `Reader → Search` and `Recommendation → Reader` from coming back.
  - `ViewerTimeZone`'s home: `Service/Reading` imports `Recommendation\Feed\ViewerTimeZone` (two files), and after #1163 that is the only edge between the two modules (D1's note).
- Any equivalent mutant this issue met, and how its line was rewritten.
