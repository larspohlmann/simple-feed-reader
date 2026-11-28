# Saved List Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Export Favorites, Kept, one label in either, or either list's Unlabeled folder as a Netscape bookmark file
or a Google Reader starred JSON file, from one Export item in the sidebar's `…` menu.

**Architecture:**
- A new `Service/Library/SavedListExport` module resolves the scope and walks it in keyset batches through
  `EntryListRepository::listForUser`, so the export can't drift from the sidebar list.
- Two `src/Http/Factory` response factories stream one format each.
- One controller has a route per format.
- The Angular side adds an export dialog fed by a format list, and an Export item on every collection, label and
  Unlabeled row.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Doctrine, PHPUnit 12; Angular 20 standalone components with signals, CDK Dialog,
Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-28-saved-list-export-design.md`

## Global Constraints

- **Routes:** `GET /api/exports/bookmarks` and `GET /api/exports/google-reader`, with the query params
  `view=favorites|kept`, `label=<id>|unlabeled` (optional) and `order=asc|desc` (optional, default newest first).
- **Media types:** `text/html; charset=UTF-8` and `application/json`, each with
  `Content-Disposition: attachment; filename="…"`.
- **File names:** `simplefeedreader-<list>[-<label>]-<Ymd>.html` and
  `simplefeedreader-<list>[-<label>]-<Ymd>-starred.json`.
  - Each part is sanitised to `[a-z0-9_-]`.
  - A label part that sanitises to empty becomes `label-<id>`.
  - Unlabeled is the part `unlabeled`.
- **Errors before the first byte:** a bad view or label is 422 `validation_error`; an unknown label, or another
  user's, is 404. Both are `application/problem+json`.
- **Content:** the entry's `contentHtml`, else its `summary`, else an empty string. No scraped article.
- **Dates:** the entry's `effectiveDate` as Unix seconds (UTC) for `ADD_DATE`, `published` and `updated`.
- **Origin:** an `origin` with `streamId`/`htmlUrl` only for an `http`/`https` feed URL. Otherwise it is
  `{"title": …}` alone; this covers the `saved:` and `social:` feeds.
- **Starred:** every JSON item carries `user/-/state/com.google/starred`, plus `user/-/label/<name>` for each label.
- **JSON flags:** `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
  JSON_INVALID_UTF8_SUBSTITUTE`.
- **HTML escaping:** `htmlspecialchars(…, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')`.
- **Bookmark file rules:**
  - Each export is one `<H3>` folder named after the export.
  - An entry without a URL is skipped.
  - A comma in a label name becomes a space inside `TAGS`.
- **Export name:**
  - `Favorites` or `Kept` for a whole list.
  - `<List> – <label name>` for a label, and `<List> – Unlabeled` for Unlabeled. The dash is U+2013 with a space on
    each side.
  - English, built on the server.
- **Bearer auth only.** No cookie, nothing browser-only (architecture §6).
- **i18n** under `reader.export.*` in `frontend/public/i18n/{en,de,fr,es}.json`. The fr/es loanword rules are in
  memory `languages`.
- **House rules** (CLAUDE.md):
  - Clean Code naming; `final readonly`; no comments unless they clear the bar.
  - `Response::HTTP_*`, never a status literal.
  - Thin controllers.
  - Queries stay in `src/Repository`.
  - Every touched `src` file must be PHPMD-clean.
- **Gate cadence:**
  - Per task: the tests covering the touched code, plus `composer cs` / `composer stan` on the changed files. For the
    frontend, the touched specs in the container (`docker compose exec -T frontend npx jest <path>`).
  - The full gate set runs once before the PR, not per task.
- **Commands:** foreground only, with no background shells or `sleep` loops.

## Review Focus

1. **Batch boundary.** Take a list of `EntryQuery::MAX_LIMIT + 1` entries that share one `effectiveDate`. It must
   export every entry exactly once, in both orders. Pinned in Task 1.
2. **A label name full of markup or commas** (`A, "B" & <C>`). It must stay one escaped `TAGS` value and an escaped
   folder name, and the JSON label stays `user/-/label/A, "B" & <C>`. A label name that sanitises to empty (`日本`)
   gives `label-<id>` in the file name. Pinned in Tasks 2 and 3.
3. **A rescued post on a `saved:<userId>` feed.** Its JSON origin must be `{"title": …}` with no `saved:` string
   anywhere in the file. Pinned in Task 3.
4. **Invalid UTF-8 or control characters in entry content.** They must still give a parseable JSON file. Pinned in
   Task 3.
5. **Another user's label id.** It must give a 404 problem response, never a 200 with an empty file. Pinned in
   Task 2.

---

### Task 1: Scope resolution and batched walk

**Files:**
- Create: `backend/src/Service/Library/SavedListExport/SavedListScopeResolver.php`
- Create: `backend/src/Service/Library/SavedListExport/SavedListWalk.php`
- Create: `backend/src/Service/Library/SavedListExport/Model/SavedListScopeModel.php`
- Create: `backend/src/Service/Library/SavedListExport/Model/SavedListEntryModel.php`
- Create: `backend/src/Service/Library/SavedListExport/Model/SavedListOriginModel.php`
- Create: `backend/src/Service/Library/SavedListExport/Model/SavedListFilenameModel.php`
- Test: `backend/tests/Service/Library/SavedListExport/SavedListScopeResolverTest.php`
- Test: `backend/tests/Service/Library/SavedListExport/SavedListWalkTest.php`
- Test: `backend/tests/Service/Library/SavedListExport/Model/SavedListFilenameModelTest.php`

**Interfaces:**
- Produces:
  - **`SavedListScopeModel`** (`final readonly`):
    - Fields: `EntryView $view`, `?LabelScope $labelScope`, `?int $labelId`, `?string $labelName` and
      `ListOrder $order`.
    - `exportName(): string`, which gives `Favorites`, `Kept – Reading list` or `Kept – Unlabeled`.
    - `isUnlabeled(): bool`.
  - **`SavedListScopeResolver::requireScope(int $userId, ?string $view, ?string $label, ?string $order):
    SavedListScopeModel`**. It throws:
    - `ValidationException(['view' => ['An export needs view=favorites or view=kept.']])` when the view is anything
      else, or missing;
    - the `LabelScope::fromRequestValue` validation errors as they are;
    - `ListOrder::fromRequestValue`'s refusal;
    - `RecordNotFoundException` through `LabelRepository::requireOneForUser` for an unknown or foreign label.
  - **`SavedListOriginModel`** (`final readonly`):
    - Fields: `string $title`, `string $feedUrl` and `?string $siteUrl`.
    - `isSubscribable(): bool`, true only for an `http` or `https` scheme.
  - **`SavedListEntryModel`** (`final readonly`), all scalars and no entities:
    - Fields: `string $guid`, `?string $url`, `string $title`, `?string $author`, `string $contentHtml`,
      `\DateTimeImmutable $effectiveDate`, `SavedListOriginModel $origin` and `list<string> $labelNames`.
    - `$labelNames` is alphabetical, as `findLabelsByEntryIds` returns it.
  - **`SavedListWalk::batches(int $userId, SavedListScopeModel $scope): \Generator<int, list<SavedListEntryModel>>`**.
  - **`SavedListFilenameModel`**: `new SavedListFilenameModel(SavedListScopeModel $scope,
    \DateTimeImmutable $now)`, with `withExtension(string $extension): string`. Pass the extension as `'.html'` or
    `'-starred.json'`.

- [ ] **Step 1: Write the failing tests**
  - **`SavedListScopeResolverTest`** (`KernelTestCase`, with users and labels seeded as `EntryLabelScopeTest`
    seeds them):
    - `testAWholeListHasNoLabel`: `requireScope($id, 'kept', null, null)` gives the view `Kept`, `labelScope`
      null, and `exportName()` `'Kept'`.
    - `testALabelCarriesItsName`: a label `Reading list` gives `labelName` `'Reading list'` and `exportName()`
      `'Kept – Reading list'`.
    - `testUnlabeledIsNamedUnlabeled`: `'unlabeled'` makes `isUnlabeled()` true and gives `exportName()`
      `'Favorites – Unlabeled'`.
    - `testTheAllViewIsRefused`: `'all'` throws a `ValidationException` whose errors equal
      `['view' => ['An export needs view=favorites or view=kept.']]`.
    - `testAMissingViewIsRefused`: `null` throws the same error.
    - `testAnotherUsersLabelIsNotFound`: another user's label id throws `RecordNotFoundException`.
    - `testOldestFirstIsRead`: `'asc'` gives `ListOrder::OldestFirst`.
  - **`SavedListWalkTest`** (`KernelTestCase`):
    - `testItYieldsTheKeptEntriesNewestFirst`: three kept entries and one unkept give three titles, newest first.
    - `testOldestFirstReversesTheOrder`.
    - `testALabelNarrowsTheWalk` and `testUnlabeledLeavesOutLabelledEntries`.
    - `testEveryEntryAppearsOnceAcrossABatchBoundary`: `EntryQuery::MAX_LIMIT + 1` kept entries share one
      `effectiveDate`. Run in both orders: the flattened guids are unique, and their count is `MAX_LIMIT + 1`. The
      first batch has `MAX_LIMIT` entries.
    - `testAnotherUsersEntriesNeverAppear`.
    - `testItCarriesLabelsContentAndOrigin`. The model has:
      - labels `['Alpha', 'Beta']`;
      - `contentHtml` `'<p>body</p>'`, and `'summary'` when `contentHtml` is null;
      - `origin->title` equal to the subscription's display title, and `origin->feedUrl` equal to the feed URL.
  - **`SavedListFilenameModelTest`** (a unit test):
    - Kept, with label `Lesen & Später`, at `2026-11-28` and `'.html'`, gives
      `'simplefeedreader-kept-lesen-sp-ter-20261128.html'`.
    - Label `日本` with id 7 gives `'simplefeedreader-kept-label-7-20261128.html'`.
    - Unlabeled Favorites with `'-starred.json'` gives `'simplefeedreader-favorites-unlabeled-20261128-starred.json'`.
    - A whole list gives `'simplefeedreader-kept-20261128.html'`.
    - Follow `BackupFilenameModel`'s sanitising (lower-case, runs of anything outside `[a-z0-9_-]` become one `-`,
      trimmed), and adjust the first expectation to match its exact rule.

- [ ] **Step 2: Run the tests to verify they fail**
  - Run: `cd backend && php bin/phpunit tests/Service/Library/SavedListExport`
  - Expected: FAIL, because the classes are missing.

- [ ] **Step 3: Implement the models and `SavedListScopeResolver`**
  - Parse with `EntryView::fromRequestValue`, then refuse anything but Favorites or Kept.
  - Use `LabelScope::fromRequestValue`, then `ListOrder::fromRequestValue`.
  - Look the label name up through `LabelRepository::requireOneForUser` only for a label id.

- [ ] **Step 4: Implement `SavedListWalk::batches`**
  - Loop `EntryListRepository::listForUser(new EntryQuery(userId: …, view: …, limit: EntryQuery::MAX_LIMIT,
    order: …, cursor: $cursor))`, plus `->withLabelScope()` when the scope has one.
  - Take the next cursor from the last row: `new EntryCursor(EntryListSort::PublishedDate->requireInstant($row),
    $row->entry->requireId())`.
  - Stop when a batch has fewer than `$query->limit` rows, the same rule `EntryPage::from` uses.
  - Per batch:
    - load the labels with `EntryLabelRepository::findLabelsByEntryIds`;
    - map each row's survivor entry to a `SavedListEntryModel` (duplicates aren't exported);
    - yield the list, then call `EntityManagerInterface::clear()`.

- [ ] **Step 5: Run the tests to verify they pass**
  - Run: `cd backend && php bin/phpunit tests/Service/Library/SavedListExport`
  - Expected: PASS.
  - Then run `composer cs` and `composer stan` on the new files. If `ServiceRoleRule` names another folder for a
    class, move it there.

- [ ] **Step 6: Commit**
  - `git commit -m "feat(#2585): resolve and walk a saved list export scope"`

### Task 2: Bookmark file endpoint

**Files:**
- Create: `backend/src/Http/Factory/BookmarkFileResponseFactory.php`
- Create: `backend/src/Controller/Api/SavedListExportController.php`
- Test: `backend/tests/Controller/Api/SavedListExportBookmarksTest.php`

**Interfaces:**
- Consumes: `SavedListScopeResolver::requireScope`, `SavedListWalk::batches` and `SavedListFilenameModel` from
  Task 1.
- Produces:
  - **`BookmarkFileResponseFactory::stream(SavedListScopeModel $scope, \Generator $batches): StreamedResponse`**,
    which takes `ClockInterface` in its constructor.
  - **`SavedListExportController`**, `#[Route('/api/exports')]`, with:
    - `bookmarks(#[CurrentUser] User $user, #[MapQueryParameter] ?string $view = null,
      #[MapQueryParameter] ?string $label = null, #[MapQueryParameter] ?string $order = null): StreamedResponse`,
      named `api_exports_bookmarks`;
    - Task 3 adds the `googleReader` action to the same controller.

- [ ] **Step 1: Write the failing tests** (`ApiTestCase`, read with `getInternalResponse()->getContent()`)
  - `testItDownloadsTheKeptListAsABookmarkFile`:
    - status 200;
    - `Content-Type` starts with `text/html`;
    - `Content-Disposition` is `attachment; filename="simplefeedreader-kept-<today Ymd>.html"`;
    - the body starts with `<!DOCTYPE NETSCAPE-Bookmark-file-1>`;
    - it contains `<H3>Kept</H3>`, and one `<DT><A HREF="https://…/1" ADD_DATE="<unix>" TAGS="Alpha,Beta">Entry
      One</A>`.
  - `testALabelExportIsNamedAfterTheLabel`: the folder `<H3>Kept – Reading list</H3>`, and the filename contains
    `-kept-reading-list-`.
  - `testUnlabeledExportsOnlyEntriesWithoutALabel`.
  - `testMarkupInTitlesUrlsAndLabelsIsEscaped`:
    - title `Tom & "Jerry" <b>` gives `Tom &amp; &quot;Jerry&quot; &lt;b&gt;`;
    - URL `https://x.example/?a=1&b=2` gives `HREF="https://x.example/?a=1&amp;b=2"`;
    - label `A, "B" & <C>` gives `TAGS="A  &quot;B&quot; &amp; &lt;C&gt;"`, its comma replaced by a space.
  - `testAnEntryWithoutAUrlIsSkipped`.
  - `testAnEmptyListIsAValidEmptyFolder`: 200, with the header and `<H3>Favorites</H3>` followed by an empty
    `<DL><p>`.
  - `testAddDateIsTheUtcEffectiveDate`: an entry at `2026-07-01T00:00:00Z` gives `ADD_DATE="1782864000"`. Assert the
    value from `(new \DateTimeImmutable('2026-07-01T00:00:00Z'))->getTimestamp()`.
  - `testTheAllViewIsAValidationError`: 422, `type` `validation_error`, and errors
    `['view' => ['An export needs view=favorites or view=kept.']]`.
  - `testAnotherUsersLabelIsNotFound`: 404, with the `application/problem+json` content type.
  - `testItNeedsAToken`: 401 without a token.

- [ ] **Step 2: Run the tests to verify they fail**
  - Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedListExportBookmarksTest.php`
  - Expected: FAIL with 404 route-not-found.

- [ ] **Step 3: Implement the factory and the controller**
  - **The controller:** call `requireScope` before building the response, so 422 and 404 fire before the stream.
    Then return `$factory->stream($scope, $walk->batches($user->requireId(), $scope))`.
  - **Writes, in order:**
    - header: `<!DOCTYPE NETSCAPE-Bookmark-file-1>`,
      `<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">`, `<TITLE>Bookmarks</TITLE>`,
      `<H1>Bookmarks</H1>`, then `<DL><p>`, `<DT><H3>{exportName}</H3>` and `<DL><p>`;
    - one `<DT><A …>` line per entry that has a URL;
    - footer: `</DL><p>` and `</DL><p>`.
  - Write each batch with `echo` and call `flush()` after it.

- [ ] **Step 4: Run the tests to verify they pass**
  - Run the Step 2 command. Expected: PASS.
  - Then run `composer cs` and `composer stan` on the changed files.

- [ ] **Step 5: Commit**
  - `git commit -m "feat(#2585): export a saved list as a bookmark file"`

### Task 3: Google Reader starred JSON endpoint

**Files:**
- Create: `backend/src/Http/Factory/GoogleReaderStarredResponseFactory.php`
- Modify: `backend/src/Controller/Api/SavedListExportController.php`
- Test: `backend/tests/Controller/Api/SavedListExportGoogleReaderTest.php`

**Interfaces:**
- Consumes: Task 1, plus the controller from Task 2.
- Produces:
  - **`GoogleReaderStarredResponseFactory::stream(SavedListScopeModel $scope, \Generator $batches):
    StreamedResponse`**.
  - **`SavedListExportController::googleReader(…)`**, with the same parameters as `bookmarks`, route
    `/google-reader` and name `api_exports_google_reader`.

- [ ] **Step 1: Write the failing tests**
  - `testItDownloadsAParseableStarredFile`:
    - status 200 and `application/json`;
    - the filename ends in `-starred.json`;
    - `json_decode($body, true, flags: JSON_THROW_ON_ERROR)` gives `id` `'user/-/state/com.google/starred'`,
      `title` `'Kept'`, an integer `updated`, and one item.
  - `testAnItemCarriesWhatFreshRssReads`. The item equals:
    - `id`: the guid;
    - `title`;
    - `author`;
    - `published` and `updated`: the same Unix int;
    - `alternate`: `[['href' => url, 'type' => 'text/html']]`;
    - `content`: `['direction' => 'ltr', 'content' => '<p>body</p>']`;
    - `origin`: `['streamId' => 'feed/https://…/feed.xml', 'title' => <subscription title>,
      'htmlUrl' => <site url>]`;
    - `categories`: `['user/-/state/com.google/starred', 'user/-/label/Alpha', 'user/-/label/Beta']`.
  - `testAFavoritesExportIsStarredToo`: the items in a Favorites export carry the starred category as well.
  - `testASavedFeedEntryHasATitleOnlyOrigin`:
    - Move an entry onto a `saved:<userId>` feed with `SavedFeedUrl`'s builder, or seed the feed by URL.
    - Its `origin` equals `['title' => …]`, and `str_contains($body, 'saved:')` is false.
  - `testASocialFeedEntryHasATitleOnlyOrigin`: the same for a `social:bluesky:…` feed URL.
  - `testInvalidUtf8AndControlCharactersStillParse`: content `"ok \xB1 \x01 end"` decodes without error.
  - `testAnEntryWithoutAUrlHasNoAlternate`: the `alternate` key is absent, and the item is kept.
  - `testAnEmptyListHasNoItems`: `items` is `[]`.
  - `testMarkupInALabelSurvivesAsItsCategory`: `'user/-/label/A, "B" & <C>'`.
  - `testMoreThanOneBatchIsOneValidDocument`: with `EntryQuery::MAX_LIMIT + 1` kept entries, the body decodes, and
    `count(items)` is `MAX_LIMIT + 1`.

- [ ] **Step 2: Run the tests to verify they fail**
  - Run: `cd backend && php bin/phpunit tests/Controller/Api/SavedListExportGoogleReaderTest.php`
  - Expected: FAIL.

- [ ] **Step 3: Implement the factory and the action**
  - Write `{"id":…,"title":…,"updated":…,"items":[`, then the items joined by `,` across batches (track the first
    item), then `]}`.
  - Encode each item with the Global Constraints flags.
  - Omit `author` when it's null or empty.
  - Build the origin with `streamId`/`htmlUrl` only when `isSubscribable()`, and leave out a null `htmlUrl`.

- [ ] **Step 4: Run the tests to verify they pass**
  - Run the Step 2 command and the Task 2 test file. Expected: PASS.
  - Then run `composer cs` and `composer stan` on the changed files.

- [ ] **Step 5: Commit**
  - `git commit -m "feat(#2585): export a saved list as google reader starred json"`

### Task 4: Export dialog and format list

**Files:**
- Move: `frontend/src/app/settings/backup/save-as.ts` and `save-as.spec.ts` to
  `frontend/src/app/core/download/`. Update the imports in `settings/backup/backup-section.component.ts` and
  `settings/import/opml-export.ts`.
- Create: `frontend/src/app/reader/export/export-formats.ts`
- Create: `frontend/src/app/reader/export/export.api.ts`
- Create: `frontend/src/app/reader/export/export-dialog.component.ts`, `.html` and `.scss`
- Create: `frontend/src/app/reader/export/export-dialog.service.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`, `fr.json` and `es.json`, adding the `reader.export.*` keys.
- Test: `frontend/src/app/reader/export/export-dialog.component.spec.ts`
- Test: `frontend/src/app/reader/export/export.api.spec.ts`

**Interfaces:**
- Produces:
  - `export type ExportFormatId = 'bookmarks' | 'google-reader';`
  - `export interface ExportFormat { id: ExportFormatId; nameKey: string; descriptionKey: string; path: string;
    fallbackFilename: string }`
  - `export const EXPORT_FORMATS: readonly ExportFormat[]` holds two entries:
    - `bookmarks`, with `path` `'/api/exports/bookmarks'` and `fallbackFilename` `'bookmarks.html'`;
    - `google-reader`, with `path` `'/api/exports/google-reader'` and `fallbackFilename` `'starred.json'`.
  - `export interface ExportTarget { collection: LabelCollection; label: LabelFilter | null; title: string }`.
    `title` is the dialog heading's subject, already translated.
  - `ExportApi.download(format: ExportFormat, target: ExportTarget, order: ListOrder):
    Observable<HttpResponse<Blob>>`. It sends:
    - `view` set to the collection;
    - `label` when it isn't null (`'unlabeled'` or the id);
    - `order=asc` only for `'oldest'`;
    - `responseType: 'blob'` and `observe: 'response'`.
  - `ExportDialogService.open(target: ExportTarget): void`. It opens `ExportDialogComponent` with
    `panelClass: 'app-dialog'`, as `label-menu.service.ts` does.
- **i18n keys:**
  - `reader.export.action` = "Export"
  - `reader.export.title` = "Export {{name}}"
  - `reader.export.format` = "Format"
  - `reader.export.bookmarksName` = "Bookmarks (HTML)"
  - `reader.export.bookmarksDescription` = "For browsers and bookmark tools such as Raindrop, Linkding, Wallabag or
    Pinboard."
  - `reader.export.googleReaderName` = "Google Reader JSON"
  - `reader.export.googleReaderDescription` = "For feed readers such as FreshRSS. Keeps the article text and your
    labels. FreshRSS subscribes to each article's feed on import."
  - `reader.export.submit` = "Export"
  - `reader.export.unlabeled` = "Unlabeled"
  - Write de, fr and es per memory `languages`.

- [ ] **Step 1: Write the failing specs**
  - `export.api.spec.ts` (`HttpTestingController`):
    - Kept, label 12, `'oldest'` requests `/api/exports/google-reader?view=kept&label=12&order=asc`.
    - Favorites, no label, `'newest'` requests `/api/exports/bookmarks?view=favorites`, with no `order` and no
      `label`.
  - `export-dialog.component.spec.ts`:
    - It renders one radio per `EXPORT_FORMATS` entry, with its name and description. The first is checked.
    - The heading reads "Export Kept – Reading list" for that target title.
    - Choosing Google Reader and submitting calls `ExportApi.download` with that format. On a response it calls
      `saveAs` with the `Content-Disposition` filename, then closes the dialog.
    - The submit button is disabled while the download runs.
    - A 404 problem blob shows the problem's detail in the dialog, and the dialog stays open.

- [ ] **Step 2: Run the specs to verify they fail**
  - Run: `docker compose exec -T frontend npx jest src/app/reader/export src/app/core/download`
  - Expected: FAIL.

- [ ] **Step 3: Implement**
  - Move `save-as`, then build `export-formats`, `ExportApi`, the dialog and the dialog service.
  - The dialog follows `new-label-dialog`: `<form cdkTrapFocus>` with `<app-overlay-panel [heading]>`. The radios
    come from `EXPORT_FORMATS`. The footer `<app-button>`s are Cancel (`common.cancel`) and the primary submit.
  - Errors go through `parseProblemAsync`.
  - The order comes from `ListOrderService.orderFor` for the target's collection selection (`kind` is the
    collection, `label` the filter, as `query.ts` builds it).
  - Styles live in the sibling `.scss`, using theme tokens only.

- [ ] **Step 4: Run the specs to verify they pass**
  - Run the Step 2 command, plus `src/app/settings/backup` and `src/app/settings/import`. Expected: PASS.
  - The i18n dictionary spec (`src/app/core/i18n/i18n-dictionaries.spec.ts`) must pass too.

- [ ] **Step 5: Commit**
  - `git commit -m "feat(#2585): export dialog with a format list"`

### Task 5: Export item in the sidebar menus

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-collection.component.ts`, `.html` and `.scss`
- Test: `frontend/src/app/reader/shell/sidebar/sidebar-collection.component.spec.ts`

**Interfaces:**
- Consumes: `ExportDialogService.open(target)` and `ExportTarget` from Task 4.
- Produces: no new exports.

- [ ] **Step 1: Write the failing specs** (in the existing spec file)
  - **Fine pointer:**
    - The collection head has an `app-sidebar-row-menu` whose only item is Export. Clicking it calls `open` with
      `{ collection: 'kept', label: null, title: 'Kept' }`.
    - A label row's menu lists Rename, Delete and Export. Export calls `open` with
      `{ collection, label: <id>, title: 'Kept – <name>' }`.
    - The Unlabeled row has a menu with only Export, which calls `open` with `label: 'unlabeled'` and the title
      `'Kept – Unlabeled'`.
  - **Coarse pointer** (`LayoutService.isCoarse` true):
    - The head, label and Unlabeled rows each show a `.dots` button that opens the action sheet.
    - The label sheet holds rename, delete and export. The head and Unlabeled sheets hold export only.
    - Choosing `'export'` calls `open` with the same targets.
  - Each Export closes the row menu first (`rows.closeMenu()`).

- [ ] **Step 2: Run the specs to verify they fail**
  - Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/sidebar-collection`
  - Expected: FAIL.

- [ ] **Step 3: Implement**
  - The head menu takes the slot `<span class="slot">`, with the menu key `collection-${collection}`.
  - The Unlabeled row's menu key is `label-${collection}-unlabeled`.
  - Build the titles from `reader.favorites`/`reader.kept`, the label name and `reader.export.unlabeled`, joined by
    `' – '`.
  - Use the `.dots` geometry the label rows already use; no new spacing values.

- [ ] **Step 4: Run the specs to verify they pass**
  - Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Commit**
  - `git commit -m "feat(#2585): export item on favorites, kept, label and unlabeled rows"`

### Task 6: Live checks

**Files:** none, unless a check fails. A fix goes to the task that owns the code, with a test that pins it.

- [ ] **Step 1: Bookmark import into a browser**
  - On `localhost:4200`, export Kept as bookmarks and import the file into a browser's bookmarks.
  - Check that the folder name, titles and dates come through, and that tags survive where the browser keeps them
    (Firefox does).
- [ ] **Step 2: FreshRSS import**
  - This needs the `freshrss/freshrss` image from Docker Hub, a download: ask Lars before pulling it.
  - Run it throwaway (`docker run --rm -p 8099:80 freshrss/freshrss`) and create a user.
  - Import the `-starred.json` file in Subscription management → Import / Export.
  - Check that the articles arrive starred, with their content and with the labels as FreshRSS labels. Rescued
    `saved:` posts must land under the disabled "Import" feed.
  - Stop the container afterwards.
- [ ] **Step 3: Coarse-pointer check**
  - With the mobile viewport in the built-in browser, open each sheet and run one export.
- [ ] **Step 4: Report** each check with what was seen. No commit unless a fix was needed.
