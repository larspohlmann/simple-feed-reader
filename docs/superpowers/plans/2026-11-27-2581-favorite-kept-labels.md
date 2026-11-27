# Labels for Favorites and Kept Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user can put labels on Favorites and Kept articles, browse them as sidebar subfolders (plus "Unlabeled"), and add, remove or create labels from a menu on the row and the article header.

**Architecture:** A new `label` table (per user) with an n:n `entry_label` join, a `Service/Library/Label` module and `/api/labels` CRUD, attach/detach under `/api/entries/{id}/labels/{labelId}`, and a `label=` filter on the existing `GET /api/entries?view=favorites|kept` list. Rows carry their labels through the existing row enrichment. The SPA gets a `LabelsStore`, a `label` selection param, a collapsible sidebar block per collection, a label menu in `app-entry-actions`, and label pills in `app-entry-pills`.

**Tech Stack:** Symfony 8.1 / Doctrine (MySQL + SQLite), PHPUnit; Angular 20 standalone + signals, CDK overlay/dialog, Transloco, Jest.

**Spec:** GitHub issue #2581 (the agreed design; read it before each task).

## Global Constraints

- Labels are completely distinct from tags (tags label subscriptions). Never reuse `Tag`, `TagJson` or the `tag=` param.
- Names: trimmed, 1–60 characters, unique per user **case-insensitively** (`LOWER()` check in the service, column collation `utf8mb4_0900_bin` like `Tag::$name`, see memory "Tag names: utf8mb4_0900_bin").
- Default label, created in the user's locale and then an ordinary label: en `Read later`, de `Später lesen`, fr `À lire plus tard`, es `Leer más tarde`.
- `label=` accepts `<positive id>` or `unlabeled`, only with `view=favorites|kept`; anything else is a 422 `validation_error` on field `label`.
- Label subfolder membership and counts use the parent's scope: entries of feeds the user is subscribed to (`EntryStateRepository::stateCountsQuery`).
- Sidebar order: labels alphabetical (case-insensitive), "Unlabeled" last and only when at least one label is listed; a label is listed only when its count in that collection is > 0.
- Pills: alphabetical, first 3 then `+N`.
- iOS viability (docs/architecture.md §6): JSON in, problem+json out, no browser-only inputs.
- All copy in en, de, fr, es (`frontend/public/i18n/*.json`, memory "Languages": fr/es first-class).
- CLAUDE.md gate cadence: per task only the covering tests + `composer cs`/`composer stan` on changed files (backend) or the relevant spec in the container (frontend). Full gates once before the PR.

## Review Focus

- **A label id that belongs to another user** on attach, detach, rename, delete → 404 `not_found`; as `label=` filter → an empty page, never the other user's entries. Tests in Tasks 2, 3, 4.
- **Renaming a label to a case variant of itself** (`read later` → `Read Later`) succeeds; renaming to another label's name in any case is a 409. Test in Task 2.
- **Padded or blank names**: `"  read later "` collides with `read later` and is stored trimmed; `"   "` is a 422. Test in Task 2.
- **Deleting the label the list is filtered by**: the sidebar navigates to the parent collection instead of leaving a dead `label=<id>` URL. Test in Task 8.
- **An entry that is both favorite and kept**: it counts under the label in both collections, but the delete confirmation's `articleCount` counts it once. Test in Task 2.

---

### Task 1: Label persistence, migration and the default label

**Files:**
- Create: `backend/src/Entity/Label.php`, `backend/src/Entity/EntryLabel.php`
- Create: `backend/src/Repository/LabelRepository.php`, `backend/src/Repository/EntryLabelRepository.php` (empty shells with the constructor; queries come in later tasks)
- Create: `backend/src/Doctrine/UserDefaultLabelListener.php`
- Create: `backend/translations/labels.en.yaml`, `labels.de.yaml`, `labels.fr.yaml`, `labels.es.yaml`
- Create: `backend/migrations/Version20261127120000.php`
- Test: `backend/tests/Doctrine/UserDefaultLabelListenerTest.php`

**Interfaces:**
- Produces: `Label(User $user, string $name)`; `getId(): ?int`, `requireId()` (via `PersistedId`), `getUser(): User`, `getName(): string`, `rename(string $name): void`. Table `label`: `id`, `user_id` (FK `app_user`, `ON DELETE CASCADE`), `name` VARCHAR(60) `utf8mb4_0900_bin`, unique `uniq_label_user_name (user_id, name)`.
- Produces: `EntryLabel(Label $label, Entry $entry)`; `getLabel(): Label`, `getEntry(): Entry`. Table `entry_label`: composite primary key (`label_id`, `entry_id`), both FKs `ON DELETE CASCADE`, index on `entry_id`.
- Produces: translation domain `labels`, key `read_later`.

- [ ] **Step 1: Write the failing test** `UserDefaultLabelListenerTest`
  - `testNewUserGetsReadLaterInEnglish()`: persist a user with locale `en`, flush; the user has exactly one `Label`, named `Read later`.
  - `testNewUserGetsReadLaterInTheirLocale()`: with a data provider over `de` → `Später lesen`, `fr` → `À lire plus tard`, `es` → `Leer más tarde`.
- [ ] **Step 2: Run it** — `php bin/phpunit tests/Doctrine/UserDefaultLabelListenerTest.php`. Expected: FAIL (class `App\Entity\Label` not found).
- [ ] **Step 3: Implement the entities, the empty repositories, the translation files and `UserDefaultLabelListener`**
  - `#[AsEntityListener(event: Events::prePersist, entity: User::class)]`, `prePersist(User $user, PrePersistEventArgs $event): void`. It injects `TranslatorInterface` and persists `new Label($user, $translator->trans('read_later', domain: 'labels', locale: $user->getLocale()))`.
  - The listener sits in `App\Doctrine` next to `UserSettingsRowsListener` and imports no `App\Service` class (`PersistenceKnowsNoServiceRule`).
- [ ] **Step 4: Run the test.** Expected: PASS.
- [ ] **Step 5: Write the migration** `Version20261127120000`.
  - Follow the dialect split in `Version20261126120000`: `abortIfUnsupportedPlatform()`, and `isTransactional()` returns false on MySQL.
  - Create both tables per dialect.
  - Seed one label for every existing user: `INSERT INTO label (user_id, name) SELECT id, CASE locale WHEN 'de' THEN 'Später lesen' WHEN 'fr' THEN 'À lire plus tard' WHEN 'es' THEN 'Leer más tarde' ELSE 'Read later' END FROM app_user`.
  - `down()` drops both tables.
- [ ] **Step 6: Verify the migration on both dialects** (CLAUDE.md "Migrations need their own verification").
  - SQLite: on a scratch `DATABASE_URL`, run `bin/console doctrine:migrations:migrate --no-interaction` and then `bin/console doctrine:schema:validate`. Expected: `[OK]` mapping and database in sync.
  - MySQL: run the same pair with `docker compose exec php …`. Expected: the same `[OK]`. The dev database is never cleared (memory "Dev database safety").
  - On MySQL, also check that the dev user got its label: `SELECT name FROM label`.
- [ ] **Step 7: Commit** — `feat(#2581): label and entry_label tables, with read later for every account`

### Task 2: Label CRUD API with per-collection counts

**Files:**
- Create: `backend/src/Service/Library/Label/LabelEditor.php`
- Create: `backend/src/Service/Library/Label/Exception/LabelNameTakenException.php`
- Create: `backend/src/Service/Library/Label/Model/LabelTallyModel.php`, `Model/LabelTalliesModel.php`
- Create: `backend/src/Dto/Label/LabelNameRequest.php`
- Create: `backend/src/Http/LabelJson.php`
- Create: `backend/src/Http/Problem/ExceptionProblems/LabelProblems.php` (register it the way `TagProblems` is registered)
- Create: `backend/src/Controller/Api/LabelController.php`
- Modify: `backend/src/Repository/LabelRepository.php`
- Test: `backend/tests/Controller/Api/LabelControllerTest.php`, `backend/tests/Service/Library/Label/LabelEditorTest.php`

**Interfaces:**
- Consumes: `Label`, `EntryLabel` (Task 1).
- Produces, all in `LabelRepository`:
  - `findForUser(int $userId): list<Label>`
  - `findOneForUser(int $userId, int $labelId): ?Label`
  - `requireOneForUser(int $userId, int $labelId): Label`, which throws `RecordNotFoundException('No such label.')`
  - `existsForUserAndName(int $userId, string $name, ?int $excludeId = null): bool`, compared with `LOWER()`
  - `findOrCreateByName(User $user, string $name): Label`, which persists without flushing and is used by Task 6
  - `tallyForUser(int $userId): LabelTalliesModel`
- Produces: `LabelTallyModel(int $id, string $name, int $favoriteCount, int $keptCount, int $articleCount)` and `LabelTalliesModel(list<LabelTallyModel> $labels, int $unlabeledFavoriteCount, int $unlabeledKeptCount)`. `articleCount` counts distinct entries carrying the label, in any collection.
- Produces: `LabelEditor::create(User $user, string $name): Label`, `rename(Label $label, string $name): void` and `delete(Label $label): void`. A name clash throws `LabelNameTakenException`.
- Produces: `LabelNameRequest` (`#[Assert\NotBlank(normalizer: 'trim')]`, `#[Assert\Length(max: 60, normalizer: 'trim')]`, `public string $name = ''`, `toName(): string` returning the trimmed name).
- Produces these routes:
  - `GET /api/labels` returns `{labels: [{id, name, favoriteCount, keptCount, articleCount}], unlabeled: {favoriteCount, keptCount}}`, with labels alphabetical by `LOWER(name)`, including labels with zero counts.
  - `POST /api/labels {name}` returns 201 `{label: {id, name}}`.
  - `PATCH /api/labels/{id} {name}` returns `{label: {id, name}}`.
  - `DELETE /api/labels/{id}` returns 204.
  - A clash is 409 `label_name_taken`.

- [ ] **Step 1: Write the failing tests** in `LabelControllerTest`. Use `ApiTestCase` and `jsonBearerFor()` as in `TagControllerTest`, with `Response::HTTP_*` constants only:
  - `testAnonymousIsRejected()`: 401.
  - `testNewAccountListsReadLater()`: `labels` holds one entry named `Read later` with all counts 0, and `unlabeled` is `{favoriteCount: 0, keptCount: 0}`.
  - `testCreateTrimsAndRejectsCaseInsensitiveDuplicate()`: POST `"  Work "` returns 201 with name `Work`. POST `"work"` returns 409 with type `label_name_taken`. POST `"   "` returns 422.
  - `testRenameToOwnCaseVariantSucceeds()`: PATCH `Read later` → `READ LATER` returns 200. PATCH `Work` → `read later` returns 409.
  - `testForeignLabelIsNotFound()`: another user's label id returns 404 on PATCH and on DELETE.
  - `testCountsSplitByCollectionAndCountEachArticleOnce()`: one subscribed feed with three entries. A is favorite+kept with `Work`, B is kept with no label, C is favorite with `Work`. Expected for `Work`: `favoriteCount: 2`, `keptCount: 1`, `articleCount: 2`. Expected `unlabeled`: `{favoriteCount: 0, keptCount: 1}`. Then unsubscribe the feed: every count is 0.
  - `testDeleteRemovesItFromEveryEntry()`: after DELETE, no `entry_label` row references it, and the entries' `entry_state` rows are untouched.
- [ ] **Step 2: Run them** — `php bin/phpunit tests/Controller/Api/LabelControllerTest.php`. Expected: FAIL (404 on `/api/labels`).
- [ ] **Step 3: Implement the repository queries, the models, `LabelEditor`, `LabelNameRequest`, `LabelJson` (`one(Label): array{id:int,name:string}`, `tallies(LabelTalliesModel): array`), `LabelProblems` and `LabelController`.**
  - `tallyForUser` joins `entry_label → entry_state (same user) → Subscription (s.feed = e.feed AND s.user = :user)`, the scope of `stateCountsQuery`.
  - The unlabeled counts use `NOT EXISTS` on the user's `entry_label`.
  - Delete removes the label; the cascade removes its `entry_label` rows.
  - The controller stays thin (`ThinControllerRule`).
- [ ] **Step 4: Run the tests.** Expected: PASS. Then run `composer cs` and `composer stan` on the changed files. Expected: clean.
- [ ] **Step 5: Commit** — `feat(#2581): label CRUD API with per-collection counts`

### Task 3: Attach and detach a label on an entry

**Files:**
- Create: `backend/src/Service/Library/Label/EntryLabeling.php`
- Create: `backend/src/Controller/Api/EntryLabelController.php`
- Modify: `backend/src/Repository/EntryLabelRepository.php`
- Test: `backend/tests/Controller/Api/EntryLabelControllerTest.php`

**Interfaces:**
- Consumes: `LabelRepository::requireOneForUser`, and `EntryListRepository::getRowForUser(int $userId, int $entryId)` as the access check (as `EntryController::updateState` uses it).
- Produces: `EntryLabeling::attach(Label $label, Entry $entry): void` and `detach(Label $label, Entry $entry): void`. Both are idempotent.
- Produces: `EntryLabelRepository::exists(Label $label, Entry $entry): bool` and `removeFor(Label $label, Entry $entry): void`.
- Produces: `PUT /api/entries/{id}/labels/{labelId}` returns 204, and `DELETE /api/entries/{id}/labels/{labelId}` returns 204.
- Attaching is allowed whether or not the entry is favorite or kept; the menu's visibility is a client concern (issue §Label menu).

- [ ] **Step 1: Write the failing tests**:
  - `testAttachTwiceKeepsOneRow()`
  - `testDetachOfAbsentLabelIs204()`
  - `testForeignLabelIs404()`
  - `testEntryOutsideTheUsersFeedsIs404()`
  - `testLabelSurvivesUnfavoriting()`: attach `Read later` to a favorite and PATCH `isFavorite: false`; the `entry_label` row remains. Favorite it again and `GET /api/labels` counts it again.
- [ ] **Step 2: Run them.** Expected: FAIL (404 for the route).
- [ ] **Step 3: Implement `EntryLabeling`, the repository methods and `EntryLabelController`.**
- [ ] **Step 4: Run the tests.** Expected: PASS. Then `composer cs` and `composer stan` on the changed files.
- [ ] **Step 5: Commit** — `feat(#2581): attach and detach a label on an entry`

### Task 4: The `label=` filter on the favorites and kept lists

**Files:**
- Create: `backend/src/Repository/LabelScope.php`
- Modify: `backend/src/Repository/EntryQuery.php`, `backend/src/Repository/EntryScopePredicates.php`, `backend/src/Controller/Api/EntryController.php`
- Test: `backend/tests/Controller/Api/EntryLabelScopeTest.php`, `backend/tests/Repository/LabelScopeTest.php`

**Interfaces:**
- Produces `LabelScope`, a `final readonly` class in the shape of `NetworkScope`:
  - Named constructors `forLabel(int $labelId): self` and `unlabeled(): self`.
  - `public ?int $labelId`; null means unlabeled.
  - `static fromRequestValue(?string $label, EntryView $view): ?self`. It returns null for null. It throws `ValidationException(['label' => ['A label needs view=favorites or view=kept.']])` for any other view, and `ValidationException(['label' => ['A label is an id or "unlabeled".']])` for a value that is neither a positive int nor `unlabeled`.
- Produces: `EntryQuery::withLabelScope(LabelScope $scope): self` (`#[\NoDiscard]`, a `clone()` wither) and `public ?LabelScope $labelScope`, null by default.
  - Decided: a wither, not a 10th constructor parameter. The constructor already takes 9, PHPMD's ExcessiveParameterList fires at 10, and only two views take a label.
  - `inAllView()` must carry `labelScope` over; switch it to `clone()` too.
- Modify: `EntryScopePredicates::applyList` applies the scope, so the list, the duplicate collapse and the unread counts share it.
  - A label becomes `EXISTS (entry_label el JOIN label l WITH l.user = :user WHERE el.entry = e AND l.id = :labelId)`.
  - Unlabeled becomes `NOT EXISTS (entry_label el JOIN label l WITH l.user = :user WHERE el.entry = e)`.
  - Use parameter names that cannot clash with the existing ones (`labelScopeId`).

- [ ] **Step 1: Write the failing tests.**
  - `LabelScopeTest` covers every `fromRequestValue` branch: null; `'12'` with favorites; `'unlabeled'` with kept; `'12'` with `all` (throws); `'0'`, `'abc'` and `'-3'` (throw).
  - `EntryLabelScopeTest` uses the three entries from Task 2's counts test:
    - `?view=favorites&label=<Work>` returns [A, C].
    - `?view=kept&label=<Work>` returns [A].
    - `?view=kept&label=unlabeled` returns [B].
    - `?view=favorites&label=unlabeled` returns [].
    - `?view=all&label=<Work>` is 422 with `validation_error` naming `label`.
    - Another user's label id gives an empty `entries`, not their entries.
    - `&unread=true` narrows within the label.
    - Cursor paging over 3 labeled favorites with `limit=2` returns all three across two pages.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement `LabelScope`, the wither and the predicates, and parse `#[MapQueryParameter] ?string $label` in `EntryController::list`.**
- [ ] **Step 4: Run the tests plus `tests/Controller/Api/EntryControllerTest.php`.** Expected: PASS. Then `composer cs` and `composer stan` on the changed files.
- [ ] **Step 5: Commit** — `feat(#2581): filter the favorites and kept lists by label or unlabeled`

### Task 5: Rows carry their labels

**Files:**
- Modify: `backend/src/Repository/EntryLabelRepository.php`, `backend/src/Repository/EntryListRowEnrichment.php`, `backend/src/Service/Library/EntryList/EntryListRowEnricher.php`, `backend/src/Http/EntryJson.php`
- Test: `backend/tests/Controller/Api/EntryControllerTest.php` (new test methods), `backend/tests/Service/Library/EntryList/EntryListRowEnricherTest.php` (extend if it exists)

**Interfaces:**
- Produces: `EntryLabelRepository::labelsByEntryIds(int $userId, list<int> $entryIds): array<int, list<array{id: int, name: string}>>`. It returns the user's labels only, alphabetical by `LOWER(name)`, in one query.
- Produces: the row JSON gains `labels: list<{id, name}>` on list rows and duplicates (`EntryJson::listRow`), and so on `GET /api/entries/{id}` too (`EntryDetailController` uses the same enricher).
- Decided: `EntryListRowEnrichment` already has 9 constructor parameters, so `labels` would be the 10th.
  - Group the reader's own groupings that the pill strip renders, `savedSearches` and `labels`, into a new `App\Repository\EntryListRowGroupings(array $savedSearches = [], array $labels = [])`. The enrichment holds it as `public EntryListRowGroupings $groupings`.
  - Update every reader of `->enrichment->savedSearches`.
  - The reviewer checks this is a real concept, not a count dodge (CLAUDE.md PHPMD rule, #2025). If the reviewer rejects it, the fallback is to ask Lars.

- [ ] **Step 1: Write the failing tests.**
  - `testListRowsCarryTheirLabelsAlphabetically()`: an entry with `work` and `Read later` returns `labels` `[{Read later}, {work}]`.
  - `testLabelsOfAnotherUserNeverShow()`.
  - `testEntryDetailCarriesLabels()`.
- [ ] **Step 2: Run them.** Expected: FAIL (no `labels` key).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the tests plus the existing tests for the enricher and the entry list.** Expected: PASS. Then run `composer md` on the touched files; it must be PHPMD-clean.
- [ ] **Step 5: Commit** — `feat(#2581): entry rows carry the reader's labels`

### Task 6: Labels move with saved copies and round-trip through backups

**Files:**
- Modify: `backend/src/Service/Library/SavedPosts/SavedStateMover.php`, `backend/src/Repository/EntryLabelRepository.php`
- Modify: `backend/src/Service/Operations/Backup/Dto/EntryStateLine.php`, the exporter that calls `EntryStateLine::fromState` (find it from `AccountBackupExporter`), and the `RestoreEntryStatesInterface` implementation
- Modify: the backup format doc, if `docs/` describes the line fields (`grep -rn "isKept" docs/`)
- Test: `backend/tests/Service/Library/SavedPosts/SavedStateMoverTest.php`, the existing backup round-trip test (find it with `grep -rln "EntryStateLine" tests`)

**Interfaces:**
- Produces: `EntryLabelRepository::moveToEntries(int $userId, array<int, int> $targetByEntryId): void`. Each source entry's labels of this user end up on its target with no duplicate rows, and are removed from the source.
- Produces: `EntryStateLine` gains `public array $labels`, a list of label names (`list<string>`).
  - `fromLine` reads a missing `labels` as `[]`, so older backups restore unchanged.
  - `toBackupLine` writes it.
  - On restore, `LabelRepository::findOrCreateByName` (Task 2) resolves each name case-insensitively, then attaches.

- [ ] **Step 1: Write the failing tests.**
  - `testMoveCarriesLabelsOntoTheSavedCopy()`: the source has `Work`, the target has `Work` and `Read later`. After the move the target has both once and the source has none.
  - Backup round trip: an account with A (favorite, `Work`) restores into a fresh account that has the default label. A is favorite with `Work`, and `Read later` is not duplicated.
  - `testLineWithoutLabelsRestores()`: an old line with no `labels` key.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.** Call `moveToEntries` from `SavedStateMover::move` before its flush.
- [ ] **Step 4: Run the tests plus the whole backup test folder.** Expected: PASS. Then `composer cs` and `composer stan` on the changed files.
- [ ] **Step 5: Commit** — `feat(#2581): labels follow saved copies and survive a backup restore`

### Task 7: SPA models, `LabelsStore` and the `label` selection

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`EntryDto.labels`, `EntryQuery.label`)
- Create: `frontend/src/app/reader/labels/labels.models.ts`, `labels.api.ts`, `labels.store.ts`
- Modify: `frontend/src/app/reader/query/query.ts`, `query/entry-query.ts`, the entries API service that turns `EntryQuery` into params, `reader/shell/entry-state-actions.service.ts`
- Test: `labels.store.spec.ts`, `query/query.spec.ts`, `query/entry-query.spec.ts`, `shell/entry-state-actions.service.spec.ts`

**Interfaces:**
- Produces these types:
  - `LabelPill { id: number; name: string }`; `EntryDto.labels: LabelPill[]`.
  - `LabelTally { id; name; favoriteCount; keptCount; articleCount }`.
  - `LabelFilter = number | 'unlabeled'`; `LabelCollection = 'favorites' | 'kept'`.
- Produces `LabelsStore` (`providedIn: 'root'`):
  - Signals `labels: Signal<LabelTally[]>` and `unlabeled: Signal<{ favoriteCount: number; keptCount: number }>`.
  - `reload(): void`.
  - `create(name: string): Observable<LabelPill>`, `rename(id: number, name: string): Observable<void>`, `remove(id: number): Observable<void>`.
  - `attach(entry: EntryDto, label: LabelPill): void` and `detach(entry: EntryDto, label: LabelPill): void`. Both are optimistic on the row's `labels` in `EntryRowCache` (add a `patchLabels(entryId, labels)` there, following `markSharedLocally`), roll back on failure with `toast.showProblem`, and `reload()` when saved.
- Produces: `SelectionFields.favorites` and `.kept` become `OrderedList & { label?: LabelFilter }`.
  - `'label'` joins `SELECTION_PARAM_NAMES`.
  - `viewFromParams` reads it only for the state views `favorites`/`kept`: a positive int or `unlabeled`, anything else is dropped.
  - `queryFromSelection` passes `label` through.
  - The entries API sends `label`.
- Produces: `leavesSavedView` also returns true when a label change takes the row out of the current label or unlabeled selection. `LabelsStore.attach/detach` mark the row leaving through the list on screen, following `patchInList`.
- Produces: `EntryStateActions.favorite/keep` call `LabelsStore.reload()` from `onSaved`, because the collection counts per label move with the flag.

- [ ] **Step 1: Write the failing specs.**
  - `?view=kept&label=unlabeled` parses to `{kind: 'kept', label: 'unlabeled'}`.
  - `?view=all&label=3` drops `label`.
  - `queryFromSelection({kind: 'favorites', label: 3})` gives `{view: 'favorites', label: 3}`.
  - Attach patches `labels` at once and rolls back on a 500.
  - Detaching `Work` while the selection is favorites/`Work` marks the row leaving; detaching a different label does not.
  - Attaching any label while the selection is unlabeled marks the row leaving.
  - Un-favoriting reloads the label tallies after the save.
- [ ] **Step 2: Run them** — `docker compose exec -T frontend npx jest src/app/reader/labels src/app/reader/query src/app/reader/shell/entry-state-actions.service.spec.ts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the specs.** Expected: PASS.
- [ ] **Step 5: Commit** — `feat(#2581): label store and the label selection in the SPA`

### Task 8: Sidebar blocks for Favorites and Kept with label subfolders

**Files:**
- Create: `frontend/src/app/reader/shell/sidebar/sidebar-collection.component.{ts,html,scss,spec.ts}`
- Modify: `sidebar-views.component.html/.ts` (drop the Favorites and Kept rows and their count inputs), `sidebar.component.html/.ts` (two `app-sidebar-collection` blocks before `app-sidebar-followed-stories`)
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`, and `docs/design-language.md` (§3 Sidebar cards: one line on collection blocks)
- Test: `sidebar-collection.component.spec.ts`, `sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `LabelsStore` (Task 7), `SubscriptionsStore` favorites/kept counts, `ConfirmDialogComponent`, `app-sidebar-row-menu` (and the coarse-pointer `ActionSheet` that `SidebarRowActions` already routes to).
- Produces: `SidebarCollectionComponent` with `collection = input.required<LabelCollection>()`.
  - The head follows `sidebar-saved-searches`. The head link opens `selectionQueryParams({ view: collection })` and shows the collection's total when > 0, with the star or bookmark icon as today.
  - The `chevzone` only shows when a subfolder exists. Open/closed is stored in `localStorage` key `sidebar.collection.<collection>.open`; every read and write is wrapped in try/catch, and the default is collapsed.
  - Subfolder rows use the `label` icon and link to `selectionQueryParams({ view: collection, label: id })`. They show their count in this collection, only for counts > 0, alphabetical.
  - "Unlabeled" (`label: 'unlabeled'`) comes last, only when at least one label row shows.
  - Each label row's `…` menu offers Rename and Delete.
    - Rename edits the name in place in the row (Enter saves through `LabelsStore.rename`, Esc cancels, 409 → toast).
    - Delete confirms with `articleCount` (copy: en "Delete “{name}”? It's on {count} articles.").
    - Deleting the label the selection is filtered by navigates to `{ view: collection }`.
- New i18n keys under `reader.labels.*`: `unlabeled`, `rename`, `delete`, `deleteConfirm`, `toggleFavorites`, `toggleKept`.

- [ ] **Step 1: Write the failing specs.**
  - Rows sort alphabetically regardless of case.
  - A label whose count in this collection is 0 is not listed but is listed in the other collection.
  - Unlabeled is absent when no label row shows.
  - The chevron is absent with no subfolders.
  - The open state survives a re-create (localStorage) and survives `localStorage` throwing.
  - The active row is marked from the selection.
  - Delete confirm text carries the count.
  - Deleting the active label navigates to the parent.
  - `sidebar.component.spec`: the views list no longer renders Favorites or Kept rows, and the two blocks render before followed stories.
- [ ] **Step 2: Run them** in the container. Expected: FAIL.
- [ ] **Step 3: Implement.** Styles go in the `.scss` with tokens only (no hex, no ad-hoc px; memory "Frontend lint gotchas").
- [ ] **Step 4: Run the specs.** Expected: PASS.
- [ ] **Step 5: Commit** — `feat(#2581): favorites and kept sidebar blocks with label subfolders`

### Task 9: The label menu on rows and in the article header

**Files:**
- Create: `frontend/src/app/reader/labels/label-menu.service.ts`
- Create: `frontend/src/app/reader/labels/label-menu-panel.component.{ts,html,scss,spec.ts}`
- Create: `frontend/src/app/reader/labels/new-label-dialog.component.{ts,html,scss}`
- Modify: `reader/entry/entry-actions/entry-actions.component.{ts,html}`, `reader/article/reader-view/reader-view.component.html`, `frontend/public/i18n/*.json`, and `docs/design-language.md` (`<app-entry-actions>` entry: the label button and its two placements)
- Test: `label-menu-panel.component.spec.ts`, `label-menu.service.spec.ts`, `entry-actions.component.spec.ts`

**Interfaces:**
- Consumes: `LabelsStore`, `injectListOnScreen()` (its `selection()`), `core/coarse-pointer.ts`, `ActionSheet` with `ActionSheetCheck` items, `app-menu-checkbox`, `TOOLBAR_MENU_POSITIONS`/`stepMenu` from `shared/menu-keys.ts`, `app-overlay-panel`.
- Produces: `LabelMenu.open(entry: EntryDto, anchor: HTMLElement): void` (`providedIn: 'root'`).
  - **Fine pointer:** a CDK connected overlay at `anchor` holding `LabelMenuPanelComponent`. It has a `role="menu"` list of `app-menu-checkbox`, one per label, checked when `entry.labels` has the id; a toggle calls attach or detach and leaves the menu open. The last item is "New label…", which turns into an inline text field: Enter creates the label, attaches it and keeps the menu open, and a 409 shows a toast. A filter field heads the list when there are more than 8 labels. Escape, an outside click (`dismiss-on-outside`) or the trigger closes it.
  - **Coarse pointer:** `ActionSheet.open` with `ActionSheetCheck` items (`checked` derived from the row signal) and a "New label…" action that opens `NewLabelDialogComponent` in `app-overlay-panel`. Its submit creates and attaches the label.
- Produces: `EntryActionsComponent.labelMenu = input<'list' | 'article'>('list')`.
  - `list` renders the label button before the favorite button, only when `selection().kind` is `favorites` or `kept`.
  - `article` renders it after the tick, whenever `entry().isFavorite || entry().isKept`.
  - The button follows the existing ones (stopPropagation on click, Enter and Space; `aria-label`/`title` from `reader.labels.menu`; icon `label`; filled when `entry().labels.length > 0`).
  - `reader-view` passes `labelMenu="article"`.
  - Compact magazine tiles and list rows keep the default.

- [ ] **Step 1: Write the failing specs.**
  - entry-actions:
    - With `list` in the favorites selection, the label button renders right before the star.
    - In `all` it is absent.
    - With `article`, on a kept entry it renders right after the tick.
    - On an article that is neither favorite nor kept it is absent, and it appears once `isFavorite` flips.
  - The panel:
    - Checks reflect `entry.labels`.
    - A toggle calls attach or detach once.
    - "New label…" → typing `Work` → Enter calls `create('Work')` and then attach.
    - A 409 leaves the field open with a toast.
    - The filter appears at 9 labels and not at 8.
  - The service picks the action sheet when the pointer is coarse.
- [ ] **Step 2: Run them** in the container. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the specs.** Expected: PASS.
- [ ] **Step 5: Commit** — `feat(#2581): label menu on rows and in the article header`

### Task 10: Label pills

**Files:**
- Modify: `reader/entry/entry-pills/entry-pills.component.{ts,html,scss,spec.ts}`, the hosts that render `app-entry-pills` for list rows and the article header (find them with `grep -rn "app-entry-pills" frontend/src/app`; leave `entry-compact` without labels), `frontend/public/i18n/*.json`
- Modify: `docs/features.md` (one line on labels next to favorites and keep, lines 15–16)

**Interfaces:**
- Consumes: `LabelMenu.open` (Task 9) and `injectListOnScreen().selection()`.
- Produces: `EntryPillsComponent.labelled = input<EntryDto | null>(null)`.
  - When the entry is favorite or kept and has labels, the strip renders, after the saved-search pills, one pill per label (alphabetical as delivered, the first 3) with the `label` glyph.
  - Each pill is a `routerLink` to `selectionQueryParams({ view, label: id })`. `view` is the current selection's kind when that is `favorites` or `kept`; otherwise `favorites` if `isFavorite`, else `kept`.
  - Past 3 labels a `+N` button opens `LabelMenu.open(entry, button)`.
  - Clicks stop propagating, as the tag pills do.
  - i18n `reader.labels.filterBy` (title) and `reader.labels.more` (`+{count}` aria label).

- [ ] **Step 1: Write the failing specs.**
  - Four labels render 3 pills and `+1`.
  - Pill order follows the array.
  - Inside kept, the pill links to `view=kept`.
  - In `all` on a favorite+kept entry, it links to `view=favorites`.
  - A labeled entry that is neither favorite nor kept renders no label pills.
  - `+1` calls `LabelMenu.open`.
- [ ] **Step 2: Run them** in the container. Expected: FAIL.
- [ ] **Step 3: Implement.** Pass `[labelled]="entry()"` from the row and article hosts.
- [ ] **Step 4: Run the specs.** Expected: PASS.
- [ ] **Step 5: Commit** — `feat(#2581): label pills on rows and the article header`

### Task 11: Live check

- [ ] **Step 1:** With the Docker stack up and migrated, open `http://localhost:4200` (memory "Live checks"). As the dev account:
  1. Favorite an article and label it `Read later` from the row menu inside Favorites.
  2. Create `Work` from the article header and attach it.
  3. Open Favorites › Work.
  4. Remove `Work` there and watch the row leave.
  5. Rename and delete a label from the sidebar.
  6. Check "Unlabeled" in Kept.
  7. Check the pills and their links.
  8. Repeat 1–2 at phone width for the action sheet.
  9. Check that the subfolder state survives a reload.
- [ ] **Step 2:** Scan today's dev log for errors and deprecations (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'`).
- [ ] **Step 3:** Clean up whatever the check created (labels, flags), so that the dev account is left as it was found.
