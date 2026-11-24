# OPML Import Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An OPML import shows the file first, imports only the feeds the reader ticks, can put them under a parent tag, and lets single feeds take other tags.

**Architecture:** The backend splits today's one-shot `OpmlImporter` into a reader (file → folder tree), a placement planner (folder → final tag names, one naming rule), a previewer and an importer that both run reader + planner. Tags are written by a new in-place path through `BulkSubscriber`/`BulkTagger` that creates missing tags and never moves an existing one; the merging path every other caller uses stays as it is. The frontend replaces the paste box with a file picker that opens an `app-overlay-panel` dialog driven by one signal-based selection class.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine, PHPUnit 12; Angular 20 (standalone, signals), CDK Dialog, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-24-2531-opml-import-selection-design.md` (wire shapes and the ten planning rulings are there; read it before any task).

## Global Constraints

- CLAUDE.md house style: `final readonly`, verb-first method names, no comment unless a reader would get the code wrong, every touched `src` file PHPMD-clean, no new class whose only reason is a PHPMD count.
- New backend classes live in `backend/src/Service/Library/Opml` by role (`Model/`, `Pass/`, root services). No new module edge: `Library/Opml` already depends on `Library/Subscription`.
- Tag names compare case-insensitively and accent-sensitively (`mb_strtolower`), as `TagRepository::existsForUserAndName` does. Tag names are at most 100 characters, feed titles 512, `Tag::MAX_DEPTH` is 2 (three levels).
- The numbered suffix is exactly ` (1)`, ` (2)`, … — a space, the number in parentheses, never translated.
- `BulkTagger::tag()` and `BulkSubscriber::subscribeAll()` keep their behaviour: catalog bundles, social imports and private-feed sync must not change.
- HTTP statuses are `Response::HTTP_*` constants in `src` and `tests`, never a literal.
- Frontend: component styles in a sibling `.scss`, tokens only (no hex, no ad-hoc `px`), every new string in all four dictionaries (`frontend/public/i18n/{en,de,fr,es}.json`), French *vous*, Spanish *tú*; glossary in `docs/superpowers/plans/2026-11-13-2205-french-spanish-translations.md` (tag = `Tag` / `étiquette` / `etiqueta`, feed = `Feed` / `flux` / `feed`).
- This branch lives in a worktree. Backend tests: `cd backend && TEST_TOKEN=wt2531 php bin/phpunit --filter <Name>`. Frontend specs: `cd frontend && ./node_modules/.bin/jest <spec path>` (the Docker `frontend` container serves the main checkout, not this worktree). Never bare `npx jest`.
- Gate cadence (CLAUDE.md): per task run only the tests that cover the change plus `composer cs`/`composer stan` on the changed files, or the one Jest spec. The full set runs once, in Task 10.
- Commits: `type(#2531): lower-case summary`, no attribution lines.

## Review Focus

- The same file imported twice under the same parent — the second run creates no tag and no `Tech (2)` (Task 2 and Task 5 tests).
- Two folders with one name in different places of one file (`A/Tech`, `B/Tech`) — two tags, `Tech` and `Tech (1)`, and the same names whether or not `A/Tech` is selected (Task 2, Task 5).
- A folder name of 100 characters that needs a suffix — the result is 100 characters, not 104, and still unique (Task 2).
- A reader changes the parent tag after ticking and re-tagging rows — the selection and the changed tags survive the new preview (Task 7).
- A selection larger than the free slots sent straight to the API — the cap still holds and nothing is orphaned (Task 5).

---

### Task 1: `OpmlDocumentReader` — the file as a folder tree

**Files:**
- Create: `backend/src/Service/Library/Opml/Model/OpmlFeedModel.php`
- Create: `backend/src/Service/Library/Opml/Model/OpmlFolderModel.php`
- Create: `backend/src/Service/Library/Opml/OpmlDocumentReader.php`
- Test: `backend/tests/Service/Library/Opml/OpmlDocumentReaderTest.php`

**Interfaces:**
- Produces: `OpmlFeedModel(public string $url, public ?string $title, public string $sourceFormat)`.
- Produces: `OpmlFolderModel(public ?string $name, public array $feeds, public array $folders)` — `list<OpmlFeedModel>`, `list<OpmlFolderModel>`; `$name` is null only for the root.
- Produces: `OpmlDocumentReader::__construct(OpmlBodyReader $bodyReader)`, `read(string $opml): OpmlFolderModel`, throwing `InvalidOpmlException('The OPML body is empty or larger than 1 MB.')` for `''` or more than 1 048 576 bytes, before parsing.

Rules the reader owns (all moved from `OpmlImporter`, which Task 5 then empties of them): an outline with a non-blank `xmlUrl` is a feed and its children are ignored; a group's name is trimmed `text`, else trimmed `title`; a group with neither passes its feeds and folders to the folder above, in place; the source format rules (`resolveSourceFormat`) are unchanged. New: a feed's title is trimmed `text`, else trimmed `title`, else null, cut to 512 characters with `mb_substr`; a URL already seen earlier in the document is left out.

- [ ] **Step 1: Write the failing tests** — a plain `TestCase` building `new OpmlDocumentReader(new OpmlBodyReader())`, with the `opml(string $bodyInner): string` helper copied from `OpmlImporterTest`.

```php
public function testAFeedCarriesItsUrlTitleAndFormat(): void
{
    $root = $this->read('<outline type="rss" text=" Example " xmlUrl=" https://a.example/feed.xml "/>');

    self::assertNull($root->name);
    self::assertEquals(
        [new OpmlFeedModel('https://a.example/feed.xml', 'Example', SourceFormat::XML)],
        $root->feeds,
    );
}

public function testABlankTextFallsBackToTheTitleAndNeitherIsNull(): void
{
    $root = $this->read(
        '<outline text=" " title="Titled" xmlUrl="https://a.example/1"/>'
        . '<outline xmlUrl="https://a.example/2"/>',
    );

    self::assertSame(['Titled', null], array_map(static fn (OpmlFeedModel $feed): ?string => $feed->title, $root->feeds));
}

public function testATitleIsCutToTheFeedColumn(): void
{
    $root = $this->read('<outline text="' . str_repeat('é', 513) . '" xmlUrl="https://a.example/1"/>');

    self::assertSame(512, mb_strlen((string) $root->feeds[0]->title));
}

public function testAFolderHoldsItsFeedsAndItsFolders(): void
{
    $root = $this->read(
        '<outline text="Reading"><outline text="F" xmlUrl="https://a.example/1"/>'
        . '<outline title="Long reads"><outline text="G" xmlUrl="https://a.example/2"/></outline></outline>',
    );

    self::assertSame([], $root->feeds);
    self::assertSame('Reading', $root->folders[0]->name);
    self::assertSame('https://a.example/1', $root->folders[0]->feeds[0]->url);
    self::assertSame('Long reads', $root->folders[0]->folders[0]->name);
}

public function testANamelessGroupPassesItsFeedsAndFoldersToTheFolderAbove(): void
{
    $root = $this->read(
        '<outline text="Reading"><outline><outline text="F" xmlUrl="https://a.example/1"/>'
        . '<outline text="Inner"/></outline></outline>',
    );

    self::assertSame('https://a.example/1', $root->folders[0]->feeds[0]->url);
    self::assertSame('Inner', $root->folders[0]->folders[0]->name);
}

public function testAUrlListedTwiceStaysUnderItsFirstFolder(): void
{
    $root = $this->read(
        '<outline text="A"><outline text="F" xmlUrl="https://a.example/1"/></outline>'
        . '<outline text="B"><outline text="F" xmlUrl="https://a.example/1"/></outline>',
    );

    self::assertCount(1, $root->folders[0]->feeds);
    self::assertSame([], $root->folders[1]->feeds);
}
```

Also move, unchanged in what they assert, from `OpmlImporterTest` to this class, asserting on `$root->feeds[0]->sourceFormat` or the exception instead of on stored rows: `testADeclaredVerbatimFormatImportsAsThatFormat`, `testADeclaredFormatThatIsNotVerbatimImportsAsXml`, `testADeclaredFormatWinsOverTheCloudcastsUrl`, `testADeclaredFormatIsTrimmed`, `testACloudcastsUrlWithoutAFormatImportsAsMixcloud`, `testAnEmptyBodyIsRefusedBeforeParsing`, `testABodyOverOneMegabyteIsRefusedBeforeParsing`, `testABodyOfExactlyOneMegabyteReachesTheParser`, `testRejectsNonOpml`, `testRejectsMalformedXml`, `testRejectsDtd`. Leave the originals in `OpmlImporterTest` until Task 5.

- [ ] **Step 2: Run** `TEST_TOKEN=wt2531 php bin/phpunit --filter OpmlDocumentReaderTest` — FAIL, class not found.
- [ ] **Step 3: Implement** the two models and the reader. `OpmlImporter` is not touched yet.
- [ ] **Step 4: Run** the same filter — PASS. Then `composer cs` and `composer stan` on the new files.
- [ ] **Step 5: Commit** `feat(#2531): read an opml file into a folder tree`.

---

### Task 2: `OpmlPlacementPlanner` — the tag every folder ends up in

**Files:**
- Create: `backend/src/Service/Library/Opml/Model/OpmlPlacedFolderModel.php`
- Create: `backend/src/Service/Library/Opml/Pass/OpmlTagPlan.php`
- Create: `backend/src/Service/Library/Opml/OpmlPlacementPlanner.php`
- Test: `backend/tests/Service/Library/Opml/OpmlPlacementPlannerTest.php` (`DbTestCase`, `SeedsUsers`)

**Interfaces:**
- Consumes: `OpmlFolderModel`, `OpmlFeedModel` (Task 1); `TagRepository::findForUser(int $userId): list<Tag>`.
- Produces: `OpmlPlacedFolderModel(public ?string $name, public bool $dropped, public array $destination, public array $feeds, public array $folders)` — `$destination` is `list<string>`, final tag names outermost first, `[]` for "no tag"; `$feeds` is `list<OpmlFeedModel>`; `$folders` is `list<OpmlPlacedFolderModel>`.
- Produces: `OpmlTagPlan::__construct(array $storedTags)` (`list<Tag>`), `resolve(array $path): array` — `list<string>` in, `list<string>` out, one final name per path name.
- Produces: `OpmlPlacementPlanner::__construct(TagRepository $tags)`, `plan(User $user, OpmlFolderModel $root, ?string $parentTagName): OpmlPlacedFolderModel`.

The algorithm the signatures do not determine:

```text
plan: prefix = trimmed parentTagName is '' or null ? [] : [parentTagName]
      walk the tree pre-order: a folder is resolved before the folders inside it, siblings in file order;
      a folder's path = prefix + the names of the folders above it + its own name;
      path longer than Tag::MAX_DEPTH + 1  →  dropped = true, destination = the destination of the folder above;
      otherwise                             →  destination = OpmlTagPlan::resolve(path).
      The root's path is the prefix alone.

OpmlTagPlan::resolve(path): for each name, outermost first, cut to 100 characters, at the spot (resolved parent, name):
      a spot resolved earlier in this plan returns the same final name;
      else take the first candidate of  name, name (1), name (2), …  that no other spot of this plan holds and that
        - no stored tag has, or
        - a stored tag has that sits at this spot: its parent is the stored tag the parent spot resolved to
          (null at the top level). A parent spot that resolved to a new name has no stored children.
      A candidate with a suffix is  mb_substr(name, 0, 100 - mb_strlen(suffix)) . suffix.
      A spot that reuses a stored tag returns the stored spelling.
```

- [ ] **Step 1: Write the failing tests.** Helpers: `tag(User $user, string $name, ?Tag $parent = null): Tag` persists and flushes a tag; `folder(string $name, OpmlFolderModel ...$folders): OpmlFolderModel`; `plan(User $user, ?string $parent, OpmlFolderModel ...$folders): OpmlPlacedFolderModel` wraps them in a root. One test per line, each asserting `destination` (and `dropped` where named):

| Test | Stored tags | Parent | File | Expected |
|---|---|---|---|---|
| `testAFolderPathBecomesItsDestination` | none | null | `A/B` | root `[]`, A `['A']`, B `['A','B']` |
| `testAParentTagLeadsEveryDestination` | none | `From Feedly` | `A` | root `['From Feedly']`, A `['From Feedly','A']` |
| `testABlankParentIsNoParent` | none | `'  '` | `A` | root `[]` |
| `testAFolderNamedLikeATagElsewhereIsNumbered` | `Tech` (top) | `From Feedly` | `Tech` | `['From Feedly','Tech (1)']` |
| `testAFolderAtItsTagsOwnSpotReusesIt` | `Tech` (top) | null | `Tech` | `['Tech']` |
| `testASecondImportReusesTheNumberedTag` | `Tech` (top), `From Feedly` (top), `Tech (1)` under `From Feedly` | `From Feedly` | `Tech` | `['From Feedly','Tech (1)']` |
| `testATakenNumberMovesToTheNext` | `Tech` (top), `Tech (1)` (top) | `From Feedly` | `Tech` | `['From Feedly','Tech (2)']` |
| `testTwoFoldersOfOneNameInOneFileAreTwoTags` | none | null | `A/Tech`, `B/Tech` | `['A','Tech']`, `['B','Tech (1)']` |
| `testSiblingFoldersOfOneNameShareATag` | none | null | `Tech`, `Tech` | both `['Tech']` |
| `testANumberedNameAlreadyPlannedIsNotReused` | `Tech` (top) | `P` | `Tech`, `Tech (1)` | `['P','Tech (1)']`, `['P','Tech (1) (1)']` |
| `testNamesMatchWhateverTheirCase` | `tech` (top) | `P` | `Tech` | `['P','Tech (1)']` |
| `testAReusedTagKeepsItsStoredSpelling` | `tech` (top) | null | `Tech` | `['tech']` |
| `testAnAccentMakesADifferentName` | `Café` (top) | `P` | `Cafe` | `['P','Cafe']` |
| `testAFolderBelowTheThirdLevelIsDropped` | none | null | `A/B/C/D` | C `['A','B','C']` kept, D `dropped`, destination `['A','B','C']` |
| `testAParentTagCostsTheFileOneLevel` | none | `P` | `A/B/C` | B `['P','A','B']`, C `dropped`, destination `['P','A','B']` |
| `testATypedParentNamedLikeANestedTagIsNumbered` | `Shelf` (top), `Reading` under `Shelf` | `Reading` | — | root `['Reading (1)']` |
| `testAParentNamedLikeATopLevelTagIsThatTag` | `Reading` (top) | `reading` | — | root `['Reading']` |
| `testASuffixedNameStaysWithinTheColumn` | `str_repeat('a', 100)` (top) | `P` | folder `str_repeat('a', 100)` | last name is `str_repeat('a', 96) . ' (1)'` |
| `testFeedsStayWithTheirFolder` | none | null | `A` holding one feed | placed A `feeds` equals the read A `feeds` |
| `testPlanningWritesNothing` | none | `P` | `A` | `TagRepository::countForUser` is 0 afterwards |

- [ ] **Step 2: Run** `--filter OpmlPlacementPlannerTest` — FAIL, class not found.
- [ ] **Step 3: Implement** the model, the per-call `OpmlTagPlan` (built with `new` by the planner) and the planner.
- [ ] **Step 4: Run** the filter — PASS; `composer cs`, `composer stan` on the new files.
- [ ] **Step 5: Commit** `feat(#2531): plan the tag each opml folder lands in`.

---

### Task 3: In-place tagging in the bulk subscriber

**Files:**
- Modify: `backend/src/Service/Library/Subscription/Model/BulkSubscribeItemModel.php`
- Modify: `backend/src/Service/Library/Subscription/BulkTagger.php`
- Modify: `backend/src/Service/Library/Subscription/BulkSubscriber.php`
- Test: `backend/tests/Service/Library/Subscription/BulkSubscriberTest.php`

**Interfaces:**
- Produces: `BulkSubscribeItemModel::$chosenTags` — a sixth promoted constructor parameter `public array $chosenTags = []`, `@param list<Tag> $chosenTags the account's own tags the feed joins as they are; read by the in-place path only`.
- Produces: `BulkTagger::tagInPlace(BulkSubscribeBatch $batch, Subscription $subscription, BulkSubscribeItemModel $item): void`.
- Produces: `BulkSubscriber::subscribeAllInPlace(User $user, iterable $items): BulkSubscribeResultModel` — same contract as `subscribeAll` (tag-structure lock, cap, counts, one flush), tagging through `tagInPlace`.

`tagInPlace`: the subscription joins every `$item->chosenTags` tag, then walks `$item->tagPath` outermost first — a name the batch or the store already has (`existingTag`) is used where it is, a missing one is created under the tag before it — and joins the innermost. It never calls `setParent` on a tag it did not create, and it cuts neither the path nor the names (the planner did). `BulkSubscriber` picks the tagger with a first-class callable handed through its private helpers; no boolean parameter and no new constructor dependency.

- [ ] **Step 1: Write the failing tests** in `BulkSubscriberTest`, using its existing helpers:

```php
public function testInPlaceCreatesEachMissingTagUnderTheOneBefore(): void
// items: one feed, tagPath ['From Feedly', 'Tech (1)']
// assert: tag 'Tech (1)' has parent 'From Feedly'; 'From Feedly' has no parent;
//         the subscription's tag names are ['Tech (1)']; result->tagsCreated has both.

public function testInPlaceNeverMovesATagItFinds(): void
// stored: root tag 'Tech'. item tagPath ['News', 'Tech']
// assert: 'Tech' still has no parent; 'News' exists with no parent; the feed is tagged 'Tech'.

public function testInPlaceLeavesANestedTagUnderItsOwnParent(): void
// stored: 'Shelf' > 'Reading'. item tagPath ['Reading']
// assert: 'Reading' still has parent 'Shelf'; the feed is tagged 'Reading'; no tag created.

public function testInPlaceJoinsTheChosenTagsAndNothingElse(): void
// stored: 'One', 'Two' (each already holding one feed at position 0). item chosenTags [One, Two], tagPath []
// assert: the subscription's tag names are ['One', 'Two']; its position in each is 1; no tag created.

public function testInPlaceLeavesAFeedWithNeitherUntagged(): void
// item with tagPath [] and chosenTags [] → subscription has no tags, result->imported is 1.

public function testAnInPlaceBatchHoldsTheTagStructureLock(): void
// mirror testABatchHoldsTheTagStructureLock against subscribeAllInPlace.

public function testAnInPlaceBatchKeepsTheCapAndTheCounts(): void
// user capped at 1 (setMaxSubscriptions), two valid items + one 'ftp://x' item
// assert: imported 1, skippedOverLimit 1, invalid 1, and one Feed row exists.
```

- [ ] **Step 2: Run** `--filter BulkSubscriberTest` — the new tests FAIL (unknown method), the existing ones pass.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `--filter 'BulkSubscriber|Catalog|SocialImporter|PrivateFeed'` — PASS, proving the merging path is untouched; `composer cs`, `composer stan`, `composer md` on the three `src` files.
- [ ] **Step 5: Commit** `feat(#2531): bulk-subscribe in place, without moving tags`.

---

### Task 4: `OpmlPreviewer`

**Files:**
- Create: `backend/src/Service/Library/Subscription/Support/SubscribableUrl.php`
- Modify: `backend/src/Service/Library/Subscription/BulkSubscriber.php` (use the helper; drop `isSubscribableUrl` and `MAX_FEED_URL`)
- Create: `backend/src/Service/Library/Opml/Model/OpmlFeedState.php`
- Create: `backend/src/Service/Library/Opml/Model/OpmlPreviewModel.php`
- Create: `backend/src/Service/Library/Opml/OpmlPreviewer.php`
- Test: `backend/tests/Service/Library/Subscription/Support/SubscribableUrlTest.php`
- Test: `backend/tests/Service/Library/Opml/OpmlPreviewerTest.php` (`DbTestCase`)

**Interfaces:**
- Consumes: `OpmlDocumentReader::read`, `OpmlPlacementPlanner::plan`; `SubscriptionRepository::findFeedSummariesForUser(int $userId)` (each row has `feedUrl`), `SubscriptionRepository::countForUser`, `SubscriptionLimitResolver::resolve(User $user): int`.
- Produces: `SubscribableUrl::isSubscribable(string $url): bool` — static, the body of today's `BulkSubscriber::isSubscribableUrl` with its 750-character limit and the comment that explains it.
- Produces: `enum OpmlFeedState: string { New = 'new'; Subscribed = 'subscribed'; Scraped = 'scraped'; InvalidUrl = 'invalid_url'; }`.
- Produces: `OpmlPreviewModel(public int $freeSlots, public OpmlPlacedFolderModel $root, public array $states)` — `array<string, OpmlFeedState>` by feed URL, one entry per feed in the tree.
- Produces: `OpmlPreviewer::preview(User $user, string $opml, ?string $parentTagName): OpmlPreviewModel`.

State precedence: not subscribable → `InvalidUrl`; else already subscribed → `Subscribed`; else `SourceFormat::SCRAPED` → `Scraped`; else `New`. `freeSlots` is `max(0, limit − subscriptions)`.

- [ ] **Step 1: Write the failing tests.**

```php
// SubscribableUrlTest (plain TestCase, #[DataProvider])
// true:  'https://a.example/feed', 'http://a.example', an https URL padded to exactly 750 characters
// false: '', 'ftp://a.example/feed', 'https:///feed', 'a.example/feed', the same URL padded to 751 characters

// OpmlPreviewerTest
public function testEveryFeedGetsAState(): void
// user subscribed to https://old.example/feed; file holds that URL, https://new.example/feed,
// 'ftp://bad.example/feed', and one outline with sourceFormat="scraped" at https://scraped.example/page
// assert states: old → Subscribed, new → New, ftp → InvalidUrl, scraped → Scraped

public function testASubscribedScrapedFeedIsSubscribed(): void

public function testFreeSlotsAreTheCapLessTheSubscriptions(): void
// setMaxSubscriptions(3), one subscription → freeSlots 2

public function testFreeSlotsNeverGoBelowZero(): void
// setMaxSubscriptions(1), two subscriptions → freeSlots 0

public function testTheParentTagReachesTheDestinations(): void
// preview(..., 'From Feedly') → root->destination === ['From Feedly']

public function testAPreviewWritesNothing(): void
// Feed, Subscription and Tag row counts are unchanged afterwards

public function testAnUnreadableFileIsRefused(): void
// expectException(InvalidOpmlException::class) for 'not xml'
```

- [ ] **Step 2: Run** `--filter 'SubscribableUrlTest|OpmlPreviewerTest'` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `--filter 'SubscribableUrlTest|OpmlPreviewerTest|BulkSubscriberTest'` — PASS; cs, stan, md on the touched `src` files.
- [ ] **Step 5: Commit** `feat(#2531): preview an opml file without importing it`.

---

### Task 5: `OpmlImporter` imports a selection, over JSON

**Files:**
- Create: `backend/src/Service/Library/Opml/Model/OpmlImportSelectionModel.php`
- Modify: `backend/src/Service/Library/Opml/OpmlImporter.php` (rewrite)
- Create: `backend/src/Dto/Opml/OpmlImportRequest.php`
- Modify: `backend/src/Controller/Api/OpmlController.php` (the `import` action)
- Test: `backend/tests/Controller/Api/OpmlControllerTest.php` (the import tests)
- Modify: `backend/src/Repository/TagRepository.php:120-123` (the docblock of `findOneByNameForUser` no longer names the OPML import; say "bulk subscribe")
- Test: `backend/tests/Service/Library/Opml/OpmlImporterTest.php` (rewrite)

**Interfaces:**
- Consumes: Tasks 1–3; `TagRepository::findAllByIdsForUser(int $userId, list<int> $tagIds): list<Tag>`.
- Produces: `OpmlImportSelectionModel(public string $opml, public ?string $parentTagName, public array $feedUrls, public array $tagIdsByFeedUrl)` — `list<string>`, `array<string, list<int>>`.
- Produces: `OpmlImporter::__construct(OpmlDocumentReader $reader, OpmlPlacementPlanner $planner, TagRepository $tags, BulkSubscriber $subscriber)`, `import(User $user, OpmlImportSelectionModel $selection): OpmlImportResultModel`.
- Produces: `OpmlImportRequest(#[Assert\NotBlank] public string $opml = '', #[Assert\Length(max: 100)] public ?string $parentTagName = null, #[Assert\Count(min: 1, max: SubscriptionService::MAX_BULK_REQUEST_IDS)] #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])] public array $feedUrls = [], #[Assert\All([new Assert\Type('array'), new Assert\All([new Assert\Type('integer'), new Assert\Positive()])])] public array $tagIdsByFeedUrl = [])`, `toSelection(): OpmlImportSelectionModel`.
- Produces: `POST /api/opml/import` taking `#[MapRequestPayload] OpmlImportRequest` and passing `$request->toSelection()`; the response shape (`OpmlJson::imported`) is unchanged.

Behaviour: read, plan with the parent, then one `BulkSubscribeItemModel` per feed whose URL is in `feedUrls`, in document order, with `feedTitle` from the file. A URL with a key in `tagIdsByFeedUrl` gets `chosenTags` (the account's own tags among those ids, fetched once for the whole import) and an empty `tagPath`; every other feed gets its folder's `destination` as `tagPath`. Scraped feeds are left out and counted as invalid, as today. A URL in `feedUrls` that the file does not hold is ignored. The batch goes through `BulkSubscriber::subscribeAllInPlace`.

- [ ] **Step 1: Rewrite the tests.** Delete the tests Task 1 moved and every test that asserts merging or moving (`testANestedGroup…` through `testATagAlreadyNestedKeepsItsFirstParent`, `testReusesPreExistingTagCaseInsensitively`, `testANamelessGroup…`, `testAGroupNameIsTrimmed…`). Add an `everything(string $opml, ?string $parent = null): OpmlImportSelectionModel` helper that selects every `xmlUrl` in the markup. Keep, on the new signature: `testImportsFeedsCreatesTagsAndSchedulesFetch`, `testAnImportHoldsTheTagStructureLock…`, `testReusesExistingFeedAndSkipsAlreadySubscribed`, `testDuplicateNewUrlSubscribesOnce`, `testDuplicateExistingUnsubscribedUrlSubscribesOnce`, `testOverlongUrlIsInvalidAndRestStillImports`, `testSkippedOverLimitCreatesNoOrphanFeeds`, `testAScrapedOutlineIsInvalidEvenWhenScrapingIsOn`. New tests:

```php
public function testOnlyTheSelectedFeedsAreImported(): void
// file: A/feed1, A/feed2, B/feed3; feedUrls [feed1]
// assert: imported 1; the user's only subscription is feed1; tag 'B' does not exist.

public function testASelectedUrlTheFileDoesNotHoldIsIgnored(): void
// feedUrls ['https://elsewhere.example/feed'] → imported 0, invalid 0, no Feed row for it.

public function testAParentTagHoldsTheFoldersAndTheLooseFeeds(): void
// file: loose feed0, A/feed1; parent 'From Feedly'
// assert: feed0 tagged ['From Feedly']; feed1 tagged ['A']; 'A' has parent 'From Feedly'.

public function testAFolderNamedLikeATagElsewhereBecomesANumberedTagAndTheOldOneStays(): void
// stored: root 'Tech' holding one subscription. file: Tech/feed1; parent 'From Feedly'
// assert: feed1 tagged ['Tech (1)'] under 'From Feedly'; stored 'Tech' has no parent and still one feed.

public function testImportingTheSameFileTwiceCreatesNoTagTheSecondTime(): void
// run the previous import, unsubscribe feed1 (remove the Subscription row), import again
// assert: second result imported 1; tag names of the user are exactly ['From Feedly', 'Tech', 'Tech (1)'].

public function testTheSecondOfTwoSameNamedFoldersKeepsItsNumberWhenTheFirstIsNotSelected(): void
// file: A/Tech/feed1, B/Tech/feed2; feedUrls [feed2]
// assert: feed2 tagged ['Tech (1)'] whose parent is 'B'; no tag named 'Tech' and none named 'A'.

public function testChosenTagsReplaceTheFolderAndTheParent(): void
// stored: 'Mine', 'Also'. file: A/feed1; parent 'From Feedly'; tagIdsByFeedUrl [feed1 => [Mine, Also]]
// assert: feed1 tagged ['Also', 'Mine'] (sorted); no tag 'A' and no tag 'From Feedly'.

public function testAnEmptyChoiceLeavesTheFeedUntagged(): void
// tagIdsByFeedUrl [feed1 => []] → subscription has no tags; tag 'A' does not exist.

public function testATagOfAnotherAccountIsNotJoined(): void
// other user's tag id in tagIdsByFeedUrl → the subscription has no tags; the other tag has no feeds.

public function testTheFeedTitleComesFromTheFile(): void
// <outline text="Example" xmlUrl="https://new.example/feed.xml"/> → the new Feed row's title is 'Example'.

public function testAFolderTooDeepPutsItsFeedInTheFolderAbove(): void
// A/B/C/D/feed1, no parent → feed1 tagged ['C']; no tag 'D'.

public function testASelectionOverTheCapImportsUpToIt(): void
// setMaxSubscriptions(1), two feeds selected → imported 1, skippedOverLimit 1, one Feed row.
```

In `OpmlControllerTest`, replace `testImportCreatesSubscriptions`, `testImportRejectsEmptyBody` and `testImportRejectsOversizedBody` with these (JSON bodies via `json_encode`, `CONTENT_TYPE` `application/json`; read how the suite's other tests assert a problem body and follow that):

```php
public function testImportSubscribesTheListedFeedsUnderTheParent(): void
// fixture subscriptions.opml, feedUrls = its first URL only, parentTagName 'From Feedly'
// → Response::HTTP_OK, imported 1; one subscription; a tag 'From Feedly' exists.
public function testImportWithoutFeedsIsRejected(): void        // feedUrls [] → Response::HTTP_UNPROCESSABLE_ENTITY
public function testImportWithoutAFileIsRejected(): void        // no opml → Response::HTTP_UNPROCESSABLE_ENTITY
public function testImportRejectsATagIdThatIsNotANumber(): void // tagIdsByFeedUrl {"u": ["x"]} → Response::HTTP_UNPROCESSABLE_ENTITY
public function testImportRejectsOversizedBody(): void          // opml of 1 048 577 bytes → HTTP_UNPROCESSABLE_ENTITY, type ends 'invalid_opml'
```

- [ ] **Step 2: Run** `--filter 'OpmlImporterTest|OpmlControllerTest'` — FAIL (signature).
- [ ] **Step 3: Implement** the model, the importer, the request DTO and the `import` action. The controller stays thin.
- [ ] **Step 4: Run** `--filter 'Opml'` — PASS; cs, stan, md on the touched `src` files.
- [ ] **Step 5: Commit** `feat(#2531): import the selected opml feeds in place, over json`.

---

### Task 6: HTTP — the preview endpoint

**Files:**
- Create: `backend/src/Dto/Opml/OpmlPreviewRequest.php`
- Modify: `backend/src/Controller/Api/OpmlController.php` (add `preview`)
- Modify: `backend/src/Http/OpmlJson.php`
- Test: `backend/tests/Controller/Api/OpmlControllerTest.php`
- Test: `backend/tests/Http/OpmlJsonTest.php` (create)
- Modify: `backend/tests/E2e/ReaderJourneyE2eTest.php:277-296`

**Interfaces:**
- Consumes: `OpmlPreviewer::preview`, `OpmlPreviewModel`, `OpmlFeedState`.
- Produces: `OpmlPreviewRequest(#[Assert\NotBlank] public string $opml = '', #[Assert\Length(max: 100)] public ?string $parentTagName = null)`.
- Produces: `POST /api/opml/preview` (`api_opml_preview`) taking `#[MapRequestPayload] OpmlPreviewRequest` and passing `$request->opml`, `$request->parentTagName`; `OpmlJson::preview(OpmlPreviewModel $preview): array` in the spec's wire shape.

- [ ] **Step 1: Write the failing tests.**

```php
// OpmlJsonTest
public function testAPreviewNestsFoldersAndCarriesEachFeedsState(): void
// model: root(destination ['P']) with feed a (New) and folder 'Tech' (destination ['P','Tech (1)'], dropped false)
// assertSame on the whole array, exactly the spec's example shape, 'state' => 'new'.

// OpmlControllerTest (JSON bodies via json_encode, CONTENT_TYPE application/json)
public function testPreviewRequiresAuth(): void                 // Response::HTTP_UNAUTHORIZED
public function testPreviewListsTheFileWithoutSubscribing(): void
// fixture subscriptions.opml → 200; body has integer 'freeSlots' and 'root'; the user has 0 subscriptions afterwards.
public function testPreviewOfAnUnreadableFileIsAProblem(): void
// {"opml":"not xml"} → Response::HTTP_UNPROCESSABLE_ENTITY, content type application/problem+json, type ends 'invalid_opml'
public function testPreviewWithoutAFileIsRejected(): void       // {} → HTTP_UNPROCESSABLE_ENTITY
public function testPreviewCarriesTheParentIntoTheDestinations(): void
// parentTagName 'From Feedly' → body['root']['destination'] === ['From Feedly']
```

- [ ] **Step 2: Run** `--filter 'OpmlControllerTest|OpmlJsonTest'` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Update the e2e journey**: step (f) posts JSON to `/api/opml/preview` with the export, collects every `url` in the returned tree, posts `{opml, parentTagName: null, feedUrls}` to `/api/opml/import`, and keeps asserting `alreadySubscribed >= 1`. It cannot run from this worktree (the Docker stack serves the main checkout); Task 10 records that.
- [ ] **Step 5: Run** `--filter 'Opml'` — PASS; cs, stan, md on touched `src`; `ls -t var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .` shows no new deprecation.
- [ ] **Step 6: Commit** `feat(#2531): the opml preview endpoint`.

---

### Task 7: Frontend API and the selection state

**Files:**
- Create: `frontend/src/app/settings/import/opml-import.models.ts`
- Modify: `frontend/src/app/settings/settings-api.ts:27-31`, `frontend/src/app/settings/settings-api.spec.ts`
- Create: `frontend/src/app/settings/import/opml-import-selection.ts`
- Test: `frontend/src/app/settings/import/opml-import-selection.spec.ts`
- Modify: `frontend/src/app/settings/import/opml-section.component.{ts,html,scss,spec.ts}` (remove the paste import)

**Interfaces:**
- Produces (models):

```ts
export type OpmlFeedState = 'new' | 'subscribed' | 'scraped' | 'invalid_url';
export interface OpmlPreviewFeed { url: string; title: string | null; state: OpmlFeedState }
export interface OpmlPreviewFolder {
  name: string | null; dropped: boolean; destination: string[];
  feeds: OpmlPreviewFeed[]; folders: OpmlPreviewFolder[];
}
export interface OpmlPreview { freeSlots: number; root: OpmlPreviewFolder }
export interface OpmlImportRequest {
  opml: string; parentTagName: string | null; feedUrls: string[]; tagIdsByFeedUrl: Record<string, number[]>;
}
export type OpmlImportRow =
  | { kind: 'folder'; key: string; depth: number; name: string; dropped: boolean; importableUrls: string[] }
  | { kind: 'feed'; key: string; depth: number; feed: OpmlPreviewFeed; destination: string[] };
```

- Produces (API): `previewOpml(opml: string, parentTagName: string | null): Observable<OpmlPreview>` → `POST /api/opml/preview` with `{ opml, parentTagName }`; `importOpml(request: OpmlImportRequest): Observable<OpmlImportResult>` → `POST /api/opml/import` with the request as JSON (no `Content-Type: text/xml`).
- Produces (`@Injectable() export class OpmlImportSelection`, provided by the dialog, not in root):
  - `load(preview: OpmlPreview): void` — the first call selects every `new` feed; a later call swaps the preview and keeps the selection and the changed tags, dropping URLs the new preview no longer holds as `new`.
  - `readonly rows: Signal<OpmlImportRow[]>` — the tree flattened in document order, a folder row before its feeds and folders, `depth` 0 for the root's own feeds; the root has no row; `importableUrls` are the `new` feeds anywhere below the folder; `key` is unique and stable across `load` (feed: its URL; folder: its index path such as `f0.2`).
  - `readonly selected: Signal<ReadonlySet<string>>`, `toggleFeed(url: string): void`, `toggleFolder(row): void` (all of its importable feeds selected → clears them, otherwise selects them all), `folderState(row): 'all' | 'some' | 'none'`, `selectAll(): void`, `selectNone(): void`.
  - `readonly selectedCount: Signal<number>`, `readonly freeSlots: Signal<number>`, `readonly overLimit: Signal<boolean>` (`selectedCount > freeSlots`).
  - `readonly droppedFolders: Signal<string[]>` — names of the dropped folders, document order.
  - `isChanged(url: string): boolean`, `chosenTags(url: string): ReadonlySet<number>`, `changeTags(url: string): void` (starts an empty choice), `toggleTag(url: string, tagId: number): void`, `useFolder(url: string): void`.
  - `toRequest(opml: string, parentTagName: string | null): OpmlImportRequest` — `feedUrls` are the selected URLs in document order; `tagIdsByFeedUrl` holds only selected feeds whose tags were changed, ids ascending; a blank `parentTagName` is sent as `null`.

- [ ] **Step 1: Write the failing specs.** `settings-api.spec.ts`: `previewOpml` posts `{ opml: '<opml/>', parentTagName: 'P' }` to `/api/opml/preview`; `importOpml` posts the request object to `/api/opml/import` (replace the raw-XML expectation). `opml-import-selection.spec.ts`, with one fixture preview (root feed `r` new; folder `A` with `a1` new, `a2` subscribed, and folder `A/B` with `b1` new, `b2` scraped; folder `Deep` dropped):

```ts
it('selects every new feed on first load', …)                 // selected = {r, a1, b1}; selectedCount 3
it('flattens the tree in document order', …)                  // row kinds/keys: feed r, folder A, feed a1, feed a2, folder B, feed b1, feed b2, folder Deep
it('a folder row knows the importable feeds below it', …)     // A.importableUrls = [a1, b1]
it('reports a folder as all, some or none', …)                // toggleFeed(b1) → A 'some', B 'none'; selectNone → A 'none'; selectAll → A 'all'
it('toggling a full folder clears it and a partial one fills it', …)
it('never selects a feed that is not new', …)                 // toggleFeed(a2) and selectAll leave a2 and b2 out
it('is over the limit when more are selected than slots are free', …) // freeSlots 2 → overLimit true; toggleFeed(r) → false
it('names the dropped folders', …)                            // ['Deep']
it('keeps the selection and the changed tags across a new preview', …)
  // toggleFeed(r); changeTags(a1); toggleTag(a1, 7); load(preview with other destinations) → selected {a1, b1}, chosenTags(a1) = {7}
it('drops a feed the new preview no longer offers', …)        // second preview marks b1 subscribed → b1 not selected
it('builds the request from the selection', …)
  // changeTags(b1); toggleTag(b1, 9); toggleTag(b1, 3); changeTags(r); toggleFeed(r)
  // toRequest('<opml/>', ' ') → { opml: '<opml/>', parentTagName: null, feedUrls: ['a1','b1'], tagIdsByFeedUrl: { b1: [3, 9] } }
it('use folder forgets the changed tags', …)                  // changeTags(a1); useFolder(a1) → isChanged false, absent from the request
it('an empty choice is sent as untagged', …)                  // changeTags(a1) → tagIdsByFeedUrl.a1 equals []
```

- [ ] **Step 2: Run** `./node_modules/.bin/jest src/app/settings/import/opml-import-selection.spec.ts src/app/settings/settings-api.spec.ts` — FAIL.
- [ ] **Step 3: Implement.** `importOpml` no longer takes a string, so the card's paste import goes in this task: delete the textarea, the Import button, the file input, `text`, `reading`, `importing`, `value()`, `onFile()`, `importText()`, their styles and their spec tests. The card keeps the export group, the import lead text, and the `result`/`importError` signals with their template blocks; Task 9 gives it the file picker. Between this commit and Task 9 the branch has no import UI.
- [ ] **Step 4: Run** the two specs and `opml-section.component.spec.ts` — PASS; `npx tsc -p tsconfig.app.json --noEmit` is clean.
- [ ] **Step 5: Commit** `feat(#2531): opml preview api and the import selection state`.

---

### Task 8: The import dialog

**Files:**
- Create: `frontend/src/app/settings/import/opml-import-dialog.component.{ts,html,scss}`
- Test: `frontend/src/app/settings/import/opml-import-dialog.component.spec.ts`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (`settings.opml`)
- Modify: `docs/design-language.md` (the `--panel-w` paragraph of `<app-overlay-panel>`: add this dialog as the fourth exception)

**Interfaces:**
- Consumes: `OpmlImportSelection`, `SettingsApi.previewOpml`/`importOpml`, `TagsStore` (`reader/state/tags.store`), `<app-tag-picker [tags] [selected] (toggled)>`, `<app-overlay-panel>`, `<app-field>`, `<app-button>`, `<app-error-banner>`, `<app-warning-box>`, `hostOf`/`parseUrl` (`shared/url-host`).
- Produces: `export interface OpmlImportDialogData { opml: string; preview: OpmlPreview }`; `OpmlImportDialogComponent`, opened with `Dialog.open<OpmlImportResult | undefined, OpmlImportDialogData>(…, { data, panelClass: 'app-dialog' })`, closing with the result on success and `undefined` on cancel.

Layout, following `social-import-dialog.component.html` for structure and row styling:

- `<app-overlay-panel cdkTrapFocus fillOnMobile [heading]="'settings.opml.dialogTitle' | transloco">`, host `--panel-w: 760px`.
- `subheader`: an `<app-field>` holding `<input type="text" list="…" maxlength="100" data-testid="opml-import-parent">` with a `<datalist>` of the names of `TagsStore` tags whose `parentId` is null; then one line with the counter (`data-testid="opml-import-counter"`) and two ghost `sm` buttons, select all / select none. The counter line carries the over-limit text instead when `overLimit()`.
- Body: an `<app-warning-box data-testid="opml-import-dropped">` when `droppedFolders().length`, then `<ul class="rows">` over `selection.rows()`, each row indented by `depth` through a CSS custom property and a spacing token.
  - Folder row (`data-testid="opml-import-folder"`): a checkbox with `[checked]` when `'all'`, `[indeterminate]` when `'some'`, disabled when `importableUrls` is empty, and the folder name.
  - Feed row (`data-testid="opml-import-feed"`): checkbox (disabled unless `state === 'new'`), title (the URL's host when the title is null), the host, and
    - for a feed that is not `new`: the state text, the row dimmed;
    - for a `new` feed whose tags are unchanged: the muted line `→ A / B` (`destination.join(' / ')`, or `settings.opml.untagged` when empty) and a ghost `sm` button "Change tags" (`data-testid="opml-import-change-tags"`);
    - for a changed feed: `<app-tag-picker>` bound to `selection.chosenTags(url)` / `selection.toggleTag(url, $event)` and a ghost `sm` button "Use folder" (`data-testid="opml-import-use-folder"`).
- A failure `<app-error-banner subheader data-testid="opml-import-failure">` for a failed preview refresh or a failed import.
- Footer: Cancel (`opml-import-cancel`, disabled while importing) and the primary button (`opml-import-apply`), disabled when nothing is selected or `overLimit()`, `[loading]` while importing, labelled with the count.

Behaviour: on construction `selection.load(data.preview)` and `tagsStore.load()` when its list is empty. Typing in the parent field waits 300 ms (`debounceTime`, `switchMap`), calls `previewOpml(data.opml, value)` and `selection.load`s the answer; a failure shows the banner and keeps the previous preview. Import calls `importOpml(selection.toRequest(data.opml, parent))`; success closes with the result; failure shows the problem's `detail || title` and leaves the dialog and every choice as they were.

Strings (`settings.opml`), exact:

| Key | en | de | fr | es |
|---|---|---|---|---|
| `importLead` (replace) | Import feeds from an OPML file. You choose which ones come in. | Importiere Feeds aus einer OPML-Datei. Du wählst aus, welche übernommen werden. | Importez des flux depuis un fichier OPML. Vous choisissez lesquels. | Importa feeds desde un archivo OPML. Tú eliges cuáles. |
| `previewing` | Reading the file… | Datei wird gelesen… | Lecture du fichier… | Leyendo el archivo… |
| `dialogTitle` | Import feeds | Feeds importieren | Importer des flux | Importar feeds |
| `parentTag` | Parent tag | Übergeordnetes Tag | Étiquette parente | Etiqueta superior |
| `parentTagHint` | Optional. Every imported feed goes under it. Pick a top-level tag or type a new name. | Optional. Alle importierten Feeds landen darunter. Wähle ein Tag der obersten Ebene oder gib einen neuen Namen ein. | Facultatif. Tous les flux importés y sont rangés. Choisissez une étiquette de premier niveau ou saisissez un nouveau nom. | Opcional. Todos los feeds importados quedan dentro. Elige una etiqueta de primer nivel o escribe un nombre nuevo. |
| `selectAll` | Select all | Alle auswählen | Tout sélectionner | Seleccionar todo |
| `selectNone` | Select none | Keine auswählen | Tout désélectionner | No seleccionar nada |
| `counter` | {{selected}} selected · {{free}} slots left | {{selected}} ausgewählt · {{free}} Plätze frei | {{selected}} sélectionnés · {{free}} places libres | {{selected}} seleccionados · {{free}} plazas libres |
| `overLimit` | {{selected}} selected, but only {{free}} slots are left. Clear some to import. | {{selected}} ausgewählt, aber nur {{free}} Plätze frei. Wähle einige ab, um zu importieren. | {{selected}} sélectionnés, mais il ne reste que {{free}} places. Désélectionnez-en pour importer. | {{selected}} seleccionados, pero solo quedan {{free}} plazas. Quita algunos para importar. |
| `stateSubscribed` | Already subscribed | Bereits abonniert | Déjà abonné | Ya suscrito |
| `stateScraped` | Scraped feeds cannot be imported | Gescrapte Feeds lassen sich nicht importieren | Les flux extraits d'une page ne peuvent pas être importés | Los feeds extraídos de una página no se pueden importar |
| `stateInvalidUrl` | Not a valid feed address | Keine gültige Feed-Adresse | Adresse de flux non valide | Dirección de feed no válida |
| `destination` | → {{path}} | → {{path}} | → {{path}} | → {{path}} |
| `untagged` | No tag | Kein Tag | Aucune étiquette | Sin etiqueta |
| `changeTags` | Change tags | Tags ändern | Modifier les étiquettes | Cambiar etiquetas |
| `useFolder` | Use folder | Ordner verwenden | Utiliser le dossier | Usar la carpeta |
| `dropped` | Nested too deep. Feeds in these folders go into the folder above: {{names}} | Zu tief verschachtelt. Feeds aus diesen Ordnern landen im Ordner darüber: {{names}} | Imbrication trop profonde. Les flux de ces dossiers vont dans le dossier au-dessus : {{names}} | Demasiados niveles. Los feeds de estas carpetas van a la carpeta superior: {{names}} |
| `applyCount` | Import {{count}} | {{count}} importieren | Importer {{count}} | Importar {{count}} |
| `selectFolder` | Select folder {{name}} | Ordner {{name}} auswählen | Sélectionner le dossier {{name}} | Seleccionar la carpeta {{name}} |
| `selectFeed` | Select {{name}} | {{name}} auswählen | Sélectionner {{name}} | Seleccionar {{name}} |

Check `stateScraped` against the word each dictionary already uses for scraped feeds (`grep -n -i scrap frontend/public/i18n/*.json`) and take that word.

- [ ] **Step 1: Write the failing spec**, mounting the component with `DIALOG_DATA`, a `DialogRef` stub with a `close` spy, `provideHttpClientTesting`, `provideTranslocoTesting()` and a `TagsStore` stub (`tags` signal with a root tag `Mine` id 7 and a child tag, `load` spy). Use the Task 7 fixture preview.

```ts
it('shows the tree with new feeds ticked and the rest disabled', …)   // a2 and b2 checkboxes disabled and unchecked; a1 checked
it('shows each new feed its destination', …)                          // a1 row text contains '→ P / A' for destination ['P','A']
it('shows the reason on a feed that cannot be imported', …)           // b2 row contains the stateScraped text
it('marks a partly selected folder indeterminate', …)                 // untick b1 → folder A checkbox.indeterminate === true
it('offers only top-level tags as a parent', …)                       // datalist options are ['Mine']
it('asks for a new preview 300 ms after the parent changes', …)       // fake timers: type 'P' → no request; advance 300 → POST /api/opml/preview body { opml, parentTagName: 'P' }; flush → destinations update
it('keeps the old preview when the refresh fails', …)                 // flush 422 → failure banner shown, rows unchanged
it('swaps the destination for the tag picker and back', …)            // click change-tags → app-tag-picker present, destination line gone; click use-folder → back
it('disables import over the limit and says why', …)                  // preview.freeSlots 1 → apply disabled, counter line holds the overLimit text
it('disables import when nothing is selected', …)
it('sends the selection and closes with the result', …)               // click apply → POST /api/opml/import body equals selection.toRequest(...); flush result → close called with it
it('stays open with its choices when the import fails', …)            // flush 422 problem → close not called; failure banner has the detail; a1 still ticked
it('names the dropped folders', …)                                    // warning box contains 'Deep'
```

- [ ] **Step 2: Run** `./node_modules/.bin/jest src/app/settings/import/opml-import-dialog.component.spec.ts` — FAIL.
- [ ] **Step 3: Implement** the component, its stylesheet (tokens only; reuse the social import dialog's row measures by token, not by copying hex or px) and the strings; add the design-language sentence: "The OPML import dialog (#2531) is the fourth: 760px for a checkbox, a title, a host and a tag picker per row, and `fillOnMobile` because a file runs to hundreds of rows."
- [ ] **Step 4: Run** the spec — PASS; `npx prettier --check` and `npx stylelint` on the new files; `npx eslint` on the new `.ts`/`.html`.
- [ ] **Step 5: Commit** `feat(#2531): the opml import dialog`.

---

### Task 9: The settings card — file only, opens the dialog

**Files:**
- Modify: `frontend/src/app/settings/import/opml-section.component.{ts,html,scss}`
- Modify: `frontend/src/app/settings/import/opml-section.component.spec.ts`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`: delete `settings.opml.pastePlaceholder`, and `import` and `importing` when `grep` shows nothing reads them; in `result` remove the skipped-over-limit clause from each language's existing sentence, nothing else.

**Interfaces:**
- Consumes: `SettingsApi.previewOpml`, `OpmlImportDialogComponent`, `OpmlImportDialogData`, `Dialog` (`@angular/cdk/dialog`), `SubscriptionsStore.load`, `TagsStore.load`, `RefreshService.run`.
- Produces: the card with the export group unchanged and an import group of lead text, one file input, a "Reading the file…" line while the preview runs, the error banner and the result line. No textarea, no Import button, no `text`/`importText`/`value` members.

Behaviour: choosing a file reads it, calls `previewOpml(contents, null)`, and on success opens the dialog with `{ opml: contents, preview }`. The input's value is cleared after every pick so the same file can be chosen again. A failed preview shows `detail || title` in the card and opens nothing. A dialog that closes with a result sets the result line, reloads subscriptions and tags, and starts `refresh.run(…, { kind: 'neverFetched' })` when `imported > 0`; a dialog that closes with `undefined` changes nothing.

- [ ] **Step 1: Rewrite the spec.** Provide a `Dialog` stub whose `open` returns `{ closed: subject }`. Give the file input a `File` through a stubbed `files` property and `dispatchEvent(new Event('change'))`; await the read.

```ts
it('has no paste box', …)                                   // no textarea in the card
it('previews the chosen file and opens the dialog with it', …)
  // POST /api/opml/preview body { opml: '<opml/>', parentTagName: null }; flush preview → dialog.open called with OpmlImportDialogComponent and data { opml: '<opml/>', preview }
it('says it is reading while the preview runs', …)
it('shows the reason and opens nothing when the file cannot be read', …)  // flush 422 { detail: 'Not a well-formed OPML 2.0 document.' }
it('shows the result and reloads when the dialog imported', …)
  // closed.next({ imported: 2, alreadySubscribed: 1, invalid: 0, skippedOverLimit: 0 }) → result text holds 2 and 1 and no 'limit' wording; subs.load and tags.load called; refresh.run called
it('starts no refresh when nothing was imported', …)
it('changes nothing when the dialog is cancelled', …)       // closed.next(undefined) → no result line, no load
it('lets the same file be chosen twice', …)                 // input.value is '' after the pick
```

Keep the export tests as they are.

- [ ] **Step 2: Run** `./node_modules/.bin/jest src/app/settings/import/opml-section.component.spec.ts` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the spec — PASS; `grep -rn "pastePlaceholder\|importText" frontend/src frontend/public frontend/e2e` returns nothing.
- [ ] **Step 5: Commit** `feat(#2531): the opml card takes a file and opens the import dialog`.

---

### Task 10: Whole-branch gates

- [ ] **Step 1:** Merge `origin/develop` into the branch.
- [ ] **Step 2:** Run once, concurrently where CLAUDE.md allows (Jest never alongside the test legs): `composer cs`, `composer stan`, `composer md`, `composer tramp`, `COMPOSER_PROCESS_TIMEOUT=0 composer test:parallel` (SQLite), the MySQL leg natively against the Docker MySQL with a `feedreader_test_wt2531` database name (the `php` container tests the main checkout), `composer infection:diff`, then in `frontend/`: lint, Prettier, Stylelint, `tsc`, `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/"` and `npm run build`.
- [ ] **Step 3:** Record what this worktree cannot prove and hand it to the branch's first run in the main checkout: `composer e2e` (the journey's OPML step), PhpStorm inspections (they do not see `.claude/worktrees/`), and the live check of the dialog on desktop and phone widths at `localhost:4200`.
- [ ] **Step 4:** Scan today's dev log for deprecations the new endpoints raised.
