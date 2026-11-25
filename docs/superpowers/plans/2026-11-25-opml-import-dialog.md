# OPML Import Dialog Implementation Plan (#2541)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the OPML import dialog, folders collapse, folder tags can be renamed, a feed can take its folder tag plus extra tags, ticked feeds can be tagged in bulk, each feed's site opens in a new tab, and the OPML and backup sections share one file drop zone.

**Architecture:** Renames go to the server as a `folderNames` map keyed by tree position, and the existing tag planner applies them. "Folder + tags" is a new `addedTagIdsByFeedUrl` request map whose feeds get both the folder path and the chosen tags. Everything else lives in the frontend: `OpmlImportSelection` holds collapse, rename and tag-mode state, and a new shared `app-file-drop` component replaces both file inputs.

**Tech Stack:** Symfony 8.1 / PHP 8.5, PHPUnit 12; Angular 20 signals, Jest, Transloco.

**Spec:** `docs/superpowers/specs/2026-11-25-opml-import-dialog-design.md`

## Global Constraints

- Branch `feature/2541-opml-import-dialog` off a fresh `develop`, in the **main checkout** (no worktree).
- Commit format: `type(#2541): lower-case summary`. No Claude attribution.
- Every rule in `CLAUDE.md`: naming verbs, `final readonly`, no boolean flag parameters, three parameters at most, no comments unless a reader would get the code wrong, typed exceptions mapped in `src/Http/Problem/ExceptionProblems/OpmlProblems.php`, `Response::HTTP_*` constants (never a literal status), every touched `src` file PHPMD-clean.
- Gate cadence: per task, only the tests covering the touched code, plus `composer cs` / `composer stan` on the changed PHP files. Frontend specs run in the container: `docker compose exec -T frontend npx jest <path>`. No full legs, no `infection:diff`, no `npm run check` during tasks. Foreground commands only: no background shells, no `until`/`sleep` loops.
- Folder position key: the zero-based child indexes from the root, joined by `.` (`"0"`, `"0.2"`, `"0.2.1"`). The root has no key.
- A folder name override is trimmed, non-blank, and at most 100 characters. Unknown position keys are ignored.
- Renaming onto a sibling's name (in any case) merges the two folders into one tag. `OpmlTagPlan` is **not** changed.
- A feed URL present in both `tagIdsByFeedUrl` and `addedTagIdsByFeedUrl` is a 422 problem, type `conflicting_tag_choice`.
- Frontend: standalone components and signals, styles in a sibling `.scss`, no hex and no ad-hoc `px` outside the theme. Every new string goes into all four locales: `public/i18n/{en,de,fr,es}.json`. fr and es are first-class, and loanwords beat literal translations.

## Review Focus

1. **A rename arrives, then you change the parent tag:** the preview must carry both. Import stays disabled until a preview for the current parent **and** the current renames has arrived. Pinned in Task 8.
2. **A re-preview after a rename:** collapse state, ticks and per-feed tag choices must survive it, because positions and URLs don't change. Pinned in Tasks 6 and 8.
3. **Bulk apply when some ticked feeds already have their own choice:** each one's choice is replaced, and unticked feeds are untouched. Pinned in Task 6.
4. **A drop with the wrong file type, or a drop while disabled:** nothing is emitted, and a wrong type shows the error line. Pinned in Task 4.
5. **Picking the same file twice:** it must fire twice. Today `onFile` clears `input.value`, and the backup section doesn't. Pinned in Task 4.

---

### Task 1: Planner applies folder name overrides

**Files:**
- Create: `backend/src/Service/Library/Opml/Model/OpmlPlacementChoiceModel.php`
- Create: `backend/src/Service/Library/Opml/Pass/OpmlFolderPlacing.php`
- Modify: `backend/src/Service/Library/Opml/OpmlPlacementPlanner.php`
- Test: `backend/tests/Service/Library/Opml/OpmlPlacementPlannerTest.php`

**Interfaces:**
- Produces: `final readonly class OpmlPlacementChoiceModel { __construct(public ?string $parentTagName, /** @var array<string,string> */ public array $folderNames = []) }`.
- Produces: `OpmlPlacementPlanner::plan(User $user, OpmlFolderModel $root, OpmlPlacementChoiceModel $choice): OpmlPlacedFolderModel`. The third parameter is no longer `?string`.
- Produces: `final readonly class OpmlFolderPlacing` (a per-call `Pass/` object built with `new` inside `plan()`), with `__construct(OpmlTagPlan $tagPlan, array $folderNames)` and `place(OpmlFolderModel $folder, array $parentPath, ?string $position): OpmlPlacedFolderModel`. The planner's static `placeFolder` recursion moves into it. A child's position is `$position === null ? (string) $index : "$position.$index"`. The name used is `$folderNames[$position] ?? $folder->name`. `OpmlPlacedFolderModel->name` keeps the **OPML** name.

- [ ] **Step 1: Write the failing tests.** Keep the helper `plan(?string $parent, OpmlFolderModel ...$folders)`, now building `new OpmlPlacementChoiceModel($parent)`. Add `planRenamed(?string $parent, array $folderNames, OpmlFolderModel ...$folders)`. Add:
  - `testARenamedFolderTakesTheNewName`: folders `A` (child `B`), names `['0' => 'Alpha']` → `$root->folders[0]->destination === ['Alpha']`, `$root->folders[0]->folders[0]->destination === ['Alpha', 'B']`, `$root->folders[0]->name === 'A'`.
  - `testANestedFolderIsRenamedByItsPosition`: `A` > `B`, names `['0.0' => 'Beta']` → `['A', 'Beta']`.
  - `testARenameOntoATakenTagNameIsNumbered`: stored tag `Tech` nested under stored `Other`; folder `X` renamed `['0' => 'Tech']` with parent `'P'` → `['P', 'Tech (1)']`.
  - `testARenameOntoASiblingsNameMergesThem`: folders `A`, `B`, names `['1' => 'a']` → both `$root->folders[0]->destination` and `$root->folders[1]->destination` are `['A']`.
  - `testAnUnknownPositionIsIgnored`: names `['7' => 'Z', '0.9' => 'Y']` → `['A']`.
- [ ] **Step 2: Run them and check that they fail.** Run: `php bin/phpunit tests/Service/Library/Opml/OpmlPlacementPlannerTest.php`. Expected: an error that `OpmlPlacementChoiceModel` was not found.
- [ ] **Step 3: Implement the model, the pass and the new `plan()` signature.** `plan()` trims `parentTagName` as today. Update the two callers (`OpmlPreviewer::preview`, `OpmlImporter::import`) **minimally**, passing `new OpmlPlacementChoiceModel($parentTagName)`, so the tree compiles. Task 2 replaces that.
- [ ] **Step 4: Run the planner, previewer and importer tests and check that they pass.** Run: `php bin/phpunit tests/Service/Library/Opml/`. Expected: OK. Run `composer cs`, and `vendor/bin/phpstan analyse` on the changed files.
- [ ] **Step 5: Commit** `feat(#2541): the opml planner takes folder name overrides by position`.

### Task 2: Preview and import accept `folderNames`

**Files:**
- Modify: `backend/src/Dto/Opml/OpmlPreviewRequest.php`, `backend/src/Dto/Opml/OpmlImportRequest.php`
- Modify: `backend/src/Service/Library/Opml/Model/OpmlImportSelectionModel.php`
- Modify: `backend/src/Service/Library/Opml/OpmlPreviewer.php`, `OpmlImporter.php`
- Modify: `backend/src/Controller/Api/OpmlController.php`
- Test: `backend/tests/Controller/Api/OpmlControllerTest.php`, `backend/tests/Service/Library/Opml/OpmlPreviewerTest.php`, `OpmlImporterTest.php`

**Interfaces:**
- Consumes: `OpmlPlacementChoiceModel` from Task 1.
- Produces: `OpmlPreviewer::preview(User $user, string $opml, OpmlPlacementChoiceModel $choice): OpmlPreviewModel`.
- Produces: `OpmlImportSelectionModel(string $opml, OpmlPlacementChoiceModel $placement, array $feedUrls, array $tagIdsByFeedUrl, array $addedTagIdsByFeedUrl = [])`. `addedTagIdsByFeedUrl` is used in Task 3; add it here with a default so Task 3 only fills it in.
- Produces: `OpmlPreviewRequest::toChoice(): OpmlPlacementChoiceModel`. `OpmlImportRequest::toSelection()` builds the choice the same way.
- Both DTOs get `/** @param array<string,string> $folderNames */ public array $folderNames = []`, with
  `#[Assert\All([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: 100, normalizer: 'trim')])]`. The override values reach the planner trimmed.

- [ ] **Step 1: Write the failing functional tests** in `OpmlControllerTest`:
  - `testPreviewCarriesAFolderRenameIntoTheDestinations`: post a file with folder `Tech` holding one feed, `folderNames: {"0": "Gadgets"}` → `root.folders[0].destination === ["Gadgets"]` and `root.folders[0].name === "Tech"`.
  - `testImportFilesTheFeedUnderTheRenamedFolder`: same file, import its feed with the rename → the subscription carries a tag named `Gadgets`, and no `Tech` tag exists.
  - `testABlankFolderNameIsRejected`: `{"0": "  "}` → `Response::HTTP_UNPROCESSABLE_ENTITY`.
  - `testAnOverlongFolderNameIsRejected`: 101 characters → 422.
- [ ] **Step 2: Run them and check that they fail.** Run: `php bin/phpunit tests/Controller/Api/OpmlControllerTest.php`. Expected: the two happy-path tests FAIL, still on `Tech`.
- [ ] **Step 3: Implement.** Make the DTO fields, `toChoice()` / `toSelection()`, the new previewer signature, the importer reading `$selection->placement`, and the controller calling `preview($user, $request->opml, $request->toChoice())`. Update the existing previewer and importer test helpers (`everything()` and friends) to the new model.
- [ ] **Step 4: Run the tests and check that they pass.** Run: `php bin/phpunit tests/Controller/Api/OpmlControllerTest.php tests/Service/Library/Opml/`. Expected: OK. Then run cs and stan on the changed files.
- [ ] **Step 5: Commit** `feat(#2541): opml preview and import take folder renames`.

### Task 3: Folder plus tags on import

**Files:**
- Create: `backend/src/Service/Library/Opml/Exception/ConflictingTagChoiceException.php`
- Modify: `backend/src/Service/Library/Opml/Pass/OpmlImportItems.php`, `OpmlImporter.php`
- Modify: `backend/src/Dto/Opml/OpmlImportRequest.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/OpmlProblems.php`
- Test: `backend/tests/Service/Library/Opml/OpmlImporterTest.php`, `backend/tests/Controller/Api/OpmlControllerTest.php`, plus the existing `OpmlProblems` test if one exists (`grep -rl OpmlProblems backend/tests`)

**Interfaces:**
- Consumes: `OpmlImportSelectionModel->addedTagIdsByFeedUrl` from Task 2.
- Produces: `OpmlImportItems::__construct(array $feedUrls, OpmlChosenTags $chosen)`, where `final readonly class OpmlChosenTags` (in `Pass/`) holds `/** array<string, list<Tag>> */ $replacing` and `$adding`. That keeps the constructor at two parameters. `buildItem` asks `$chosen->findReplacing($url)` / `findAdding($url)`. For a replacing feed, `tagPath: []`. For an adding feed, `tagPath: $destination` and `chosenTags: $adding`.
- Produces: `ConflictingTagChoiceException extends \DomainException`, with the conflicting URLs in its message. `OpmlImporter::import` throws it before planning when `array_intersect_key($tagIdsByFeedUrl, $addedTagIdsByFeedUrl)` is non-empty. `OpmlProblems` maps it to `ApiProblem('conflicting_tag_choice', 'A feed has two tag choices', Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getMessage())`.
- `findChosenTags` resolves both maps with **one** repository query: collect the ids from both maps together.
- The DTO gets `addedTagIdsByFeedUrl` with exactly the constraints `tagIdsByFeedUrl` has.

- [ ] **Step 1: Write the failing tests.**
  - `OpmlImporterTest::testAddedTagsJoinTheFolderTag`: the feed in folder `Tech` with added tag `Later` (stored) → the subscription's tags are exactly `{Tech, Later}`.
  - `OpmlImporterTest::testAddedTagsKeepTheParentPath`: parent `P`, folder `Tech`, added `Later` → the tags include the `Tech` tag nested under `P`, plus `Later`.
  - `OpmlImporterTest::testAFeedWithBothChoicesIsRefused`: the same URL in both maps → `expectException(ConflictingTagChoiceException::class)`, and no subscription is created.
  - `OpmlImporterTest::testAnAddedTagOfAnotherAccountIsNotJoined`: mirror the existing `testATagOfAnotherAccountIsNotJoined` → only the folder tag.
  - `OpmlControllerTest::testImportWithBothChoicesForOneFeedIsAProblem`: → 422 with `type` ending in `conflicting_tag_choice` (match how the existing problem tests assert the type).
- [ ] **Step 2: Run them and check that they fail.** Run: `php bin/phpunit tests/Service/Library/Opml/OpmlImporterTest.php tests/Controller/Api/OpmlControllerTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** as in the Interfaces block.
- [ ] **Step 4: Run the tests and check that they pass.** Run the same command. Expected: OK. Then run cs, stan, and `vendor/bin/phpmd` (codesize) on the touched `src` files.
- [ ] **Step 5: Commit** `feat(#2541): an opml feed can keep its folder tag and add chosen tags`.

### Task 4: Shared `app-file-drop` component

**Files:**
- Create: `frontend/src/app/shared/file-drop/file-drop.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (a `fileDrop` block under the existing shared/common namespace that the other shared components use; follow the one `app-warning-box` or `app-disclosure` uses)
- Modify: `docs/design-language.md` §2 Component catalog: add `### <app-file-drop>` after `<app-disclosure>`, in that catalog's format (what it is for, inputs and outputs, states, the do/don't)

**Interfaces:**
- Produces: `selector: 'app-file-drop'`. Inputs: `accept = input.required<string>()`, `label = input.required<string>()` (already translated), `hint = input<string | null>(null)`, `disabled = input(false)`. Output: `picked = output<File>()`.
- Produces: `export function matchesAccept(file: File, accept: string): boolean` in `file-drop/matches-accept.ts`. It splits `accept` on `,`. An entry starting with `.` matches the lower-cased file-name suffix, and any other entry matches `file.type` exactly. An empty `accept` matches everything.
- Markup: a `<div class="target" [class.is-over]="over()" [class.is-disabled]="disabled()">` with `(dragenter)`, `(dragover)` (calls `preventDefault`), `(dragleave)` and `(drop)` handlers, an `<app-icon name="upload_file">`, the label, the optional hint, an `<app-button>` "Choose file" that clicks the hidden `<input type="file" [accept]>`, and an error line `data-testid="file-drop-error"` when the last drop didn't match. A click anywhere on the target opens the picker. The input's value is set to `''` after every change. Styles: dashed border with design tokens only. Reuse the dashed treatment of the sidebar dropzone in `design-language.md` if it has a token, otherwise use `--border` colours and the spacing tokens.

- [ ] **Step 1: Write the failing spec** `file-drop.component.spec.ts` (host component pattern, as in `segmented-choice.component.spec.ts`):
  - `emits the file picked through the input`: set `input.files` via a `DataTransfer`, dispatch `change` → `picked` emits that file, and `input.value === ''`.
  - `emits the same file picked twice`: two change events → two emissions.
  - `emits the first dropped file that matches`: drop `[a.opml]` with `accept=".opml"` → emits `a.opml`.
  - `rejects a drop of the wrong type`: drop `x.zip` with `accept=".opml,text/xml"` → no emission, and `[data-testid=file-drop-error]` is present.
  - `ignores a drop while disabled`: `disabled=true` → no emission, and no error.
  - `marks the target while a file is dragged over it`: `dragenter` → `.target.is-over`; `dragleave` → not.
  - `matchesAccept` cases: `.OPML` suffix matches case-insensitively; `text/xml` matches the MIME type; `''` matches everything.
- [ ] **Step 2: Run it and check that it fails.** Run: `docker compose exec -T frontend npx jest src/app/shared/file-drop`. Expected: FAIL, cannot find module.
- [ ] **Step 3: Implement** the component and `matchesAccept`, add the i18n keys (`fileDrop.choose`: en "Choose file"; `fileDrop.wrongType`: en "That file type isn't supported here."), and add the catalog entry.
- [ ] **Step 4: Run it and check that it passes.** Same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2541): a shared file drop zone`.

### Task 5: OPML and backup sections use `app-file-drop`

**Files:**
- Modify: `frontend/src/app/settings/import/opml-section.component.{ts,html,spec.ts}`
- Modify: `frontend/src/app/settings/backup/backup-section.component.{ts,html,spec.ts}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (`settings.opml.dropLabel`: en "Drop an OPML file here"; `settings.backup.dropLabel`: en "Drop a backup file here". Keep `settings.backup.chooseFile` only if it is still used, otherwise delete it from all four locales)

**Interfaces:**
- Consumes: `app-file-drop` from Task 4.
- Produces: `OpmlSectionComponent.onFile(file: File): void`, which now takes a `File` and no longer touches the input. `BackupSectionComponent.onFileSelected` is deleted, and the template binds `(picked)="onFile($event)"`.

- [ ] **Step 1: Update the specs first.** Wherever the specs dispatch `change` on `input[type=file]`, emit through the drop zone instead: query `app-file-drop` and trigger `picked` with a `File`, or keep dispatching `change` on its inner input, whichever the spec already does. Add `opml section previews a dropped file` and `backup section restores from a dropped file`, each asserting what the existing pick test asserts.
- [ ] **Step 2: Run them and check that they fail.** Run: `docker compose exec -T frontend npx jest src/app/settings/import/opml-section src/app/settings/backup`. Expected: FAIL.
- [ ] **Step 3: Implement.** Swap the two `<input type="file">` blocks for `<app-file-drop>` with the same `accept` strings (`.opml,.xml,text/xml,application/xml,text/x-opml` and `.zip,.gz`), with `[disabled]` bound to `previewing()` and to `restoring()` respectively.
- [ ] **Step 4: Run them and check that they pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2541): the opml and backup pickers share the file drop zone`.

### Task 6: Selection model: tag modes and bulk tagging

**Files:**
- Modify: `frontend/src/app/settings/import/opml-import.models.ts`, `opml-import-selection.ts`, `opml-import-selection.spec.ts`

**Interfaces:**
- Produces: `export type OpmlTagMode = 'folder' | 'folderPlusTags' | 'tagsOnly';` and `export interface OpmlTagChoice { mode: Exclude<OpmlTagMode, 'folder'>; tagIds: ReadonlySet<number> }`.
- Produces: `OpmlImportRequest` gains `addedTagIdsByFeedUrl: Record<string, number[]>` and `folderNames: Record<string, string>` (`folderNames` is filled in Task 8; here it is always `{}`).
- Produces on `OpmlImportSelection`: `modeOf(url): OpmlTagMode`, `setMode(url, mode: OpmlTagMode): void` (`'folder'` deletes the choice; switching between the two tag modes keeps the chosen tags), `chosenTags(url)` (unchanged), `toggleTag(url, tagId)` (unchanged; only for a feed whose mode isn't `'folder'`), `applyToTicked(mode: OpmlTagMode, tagIds: ReadonlySet<number>): void`, and `readonly allTicked: Signal<boolean>`, true when `selectedCount() > 0` and every importable URL is ticked. `changeTags` / `useFolder` / `isChanged` are removed, and their template callers are replaced in Task 7.
- `toRequest`: a `tagsOnly` feed goes to `tagIdsByFeedUrl`, a `folderPlusTags` feed to `addedTagIdsByFeedUrl`, both with sorted ids. Only ticked feeds are sent, as today.

- [ ] **Step 1: Write the failing specs** (rewrite the existing `changeTags`/`useFolder` specs into these):
  - `a feed starts in folder mode`, and `setMode folder clears its tags`.
  - `switching between the tag modes keeps the chosen tags`.
  - `applyToTicked writes the mode and tags onto every ticked feed and replaces earlier choices`: feed A ticked with its own `tagsOnly {1}`, feed B ticked, feed C unticked; `applyToTicked('folderPlusTags', {2,3})` → A and B are `folderPlusTags {2,3}`, and C is `folder`.
  - `applyToTicked folder clears the ticked feeds' choices`.
  - `allTicked is true only when every importable feed is ticked`: after load it is true; untick one → false; selectNone → false.
  - `toRequest splits the two tag modes into their maps`: expect `tagIdsByFeedUrl` and `addedTagIdsByFeedUrl` exactly, with `folderNames: {}`.
  - `a choice survives a re-preview of the same file`: `load(preview)` twice → `modeOf(url)` is unchanged.
- [ ] **Step 2: Run them and check that they fail.** Run: `docker compose exec -T frontend npx jest src/app/settings/import/opml-import-selection`. Expected: FAIL.
- [ ] **Step 3: Implement.** Replace the `tagChoices` map value with `OpmlTagChoice`.
- [ ] **Step 4: Run them and check that they pass.** Same command. Expected: PASS. (The dialog spec may fail until Task 7. Don't run it here.)
- [ ] **Step 5: Commit** `feat(#2541): opml selection holds three tag modes and bulk tagging`.

### Task 7: Dialog: collapsible folders, per-feed modes, bulk tagging panel

**Files:**
- Modify: `frontend/src/app/settings/import/opml-import-selection.ts` (collapse state), `opml-import-selection.spec.ts`
- Modify: `frontend/src/app/settings/import/opml-import-dialog.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`

**Interfaces:**
- Consumes: Task 6's selection API, `app-segmented-choice`, `app-tag-picker`, `app-warning-box`.
- Produces on `OpmlImportSelection`: `toggleCollapsed(folderKey: string): void`, `collapseAll(): void`, `expandAll(): void`, `isCollapsed(folderKey): boolean`, `readonly anyExpanded: Signal<boolean>`, and `visibleRows: Signal<OpmlImportRow[]>`: `rows()` minus every row whose key starts with `<collapsedKey>.`. Folder row keys are `f0`, `f0.1`; a feed row's key is its URL, so the folder rows also need their child rows' ancestry. Add `ancestors: string[]` (folder keys) to **both** row kinds in `flatten`, and filter on that.
- Template: folder row gets an `<app-icon-button>` chevron (`expand_more` / `chevron_right`) with `[attr.aria-expanded]` and an aria label `settings.opml.toggleFolder` naming the folder. The list head gets `settings.opml.collapseAll` / `expandAll`, shown according to `anyExpanded()`, and `settings.opml.tagTicked`, disabled at `selectedCount() === 0`. The feed row's change/use-folder buttons become `<app-segmented-choice [options]="['folder','folderPlusTags','tagsOnly']" labelPrefix="settings.opml.mode.">`, and the tag picker shows when `modeOf(url) !== 'folder'`. The destination line shows the path, followed by ` + <tag names>` in `folderPlusTags` mode. The bulk panel (`data-testid="opml-import-bulk"`) holds its own local mode signal and tag set, the same segmented choice and tag picker, Apply / Cancel, and `<app-warning-box data-testid="opml-import-bulk-all">` with `settings.opml.bulkAll` (`{ count }`) when `selection.allTicked()`.
- English copy: `toggleFolder` "Show or hide {{name}}", `collapseAll` "Collapse all", `expandAll` "Expand all", `tagTicked` "Tag ticked feeds", `mode.folder` "Folder", `mode.folderPlusTags` "Folder + tags", `mode.tagsOnly` "Only tags", `bulkAll` "This tags all {{count}} feeds in the file.", `bulkApply` "Apply to ticked". Delete `changeTags` / `useFolder` from all locales.

- [ ] **Step 1: Write the failing specs.**
  - Selection: `collapsing a folder hides its feeds and subfolders but keeps the folder row`; `collapse state survives a re-preview`; `collapseAll then expandAll restores every row`.
  - Dialog: `the chevron hides the folder's rows` (count `opml-import-feed` before and after); `picking Folder + tags shows the tag picker and the plus line`; `the bulk panel applies its tags to the ticked feeds` (assert through `selection.toRequest`); `the bulk panel warns when every feed is ticked` and `does not warn when one is unticked`.
- [ ] **Step 2: Run them and check that they fail.** Run: `docker compose exec -T frontend npx jest src/app/settings/import`. Expected: FAIL.
- [ ] **Step 3: Implement.** Indentation stays on `--depth`.
- [ ] **Step 4: Run them and check that they pass.** Same command. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2541): collapsible folders, tag modes and bulk tagging in the opml dialog`.

### Task 8: Dialog: rename a folder's tag

**Files:**
- Modify: `frontend/src/app/settings/settings-api.ts` (`previewOpml`)
- Modify: `frontend/src/app/settings/import/opml-import-selection.ts`, `opml-import.models.ts`, `opml-import-dialog.component.{ts,html,scss,spec.ts}`, the specs
- Modify: `frontend/public/i18n/{en,de,fr,es}.json`

**Interfaces:**
- Consumes: the backend `folderNames` from Task 2.
- Produces: `SettingsApi.previewOpml(opml: string, placement: OpmlPlacement): Observable<OpmlPreview>`, with `export interface OpmlPlacement { parentTagName: string | null; folderNames: Record<string, string> }`. Update the OPML section's call to `{ parentTagName: null, folderNames: {} }`.
- Produces: `export function toPosition(folderKey: string): string` in `opml-import-selection.ts`: `'f0.2'` → `'0.2'`. It is the **only** place that maps a row key to a position.
- Produces on `OpmlImportSelection`: `renameFolder(row: FolderRow, name: string): void` (trimmed; equal to `row.name`, or blank → removes the override), `resetFolder(row)`, `isRenamed(row): boolean`, `readonly folderNames: Signal<Record<string, string>>` keyed by position. `toRequest` fills `folderNames`. The folder row type gains `tagName: string`: the last segment of the preview folder's `destination`, or `name` when `destination` is empty.
- Dialog: the typed-parent stream becomes a placement stream. `typeParent` and a committed rename both push `{ parentTagName, folderNames }` into one `Subject<OpmlPlacement>`, which is debounced as today. `previewedParent` becomes `previewedPlacement: signal<OpmlPlacement | null>`, and `canImport` compares the trimmed current placement to it by value (parent plus a stably ordered `folderNames`). The folder row shows `row.tagName`, with an edit `app-icon-button` (`edit`, label `settings.opml.renameFolder` with `{ name }`). Edit mode is an `<input maxlength="100">` with the current tag name. Enter or blur commits, and Escape cancels without committing. When `isRenamed(row)`, a `settings.opml.resetFolder` ghost button appears.
- English copy: `renameFolder` "Rename {{name}}", `resetFolder` "Use the file's name".

- [ ] **Step 1: Write the failing specs.**
  - Selection: `toPosition maps row keys to positions` (`f0` → `0`, `f0.2.1` → `0.2.1`); `renaming back to the file's name drops the override`; `a blank rename drops the override`; `toRequest carries folderNames by position`; `the folder row shows the planned tag name` (a preview whose folder `Tech` has destination `['Tech (1)']` → `tagName === 'Tech (1)'`).
  - Dialog: `committing a rename previews with the new folder names` (the api spy is called with `{ parentTagName: null, folderNames: { '0': 'Gadgets' } }` after the debounce); `Escape cancels a rename without a preview`; `import waits for the preview of the current renames` (rename → `canImport` false → the preview arrives → true); `a rename and a parent change are previewed together`; `ticks and tag choices survive the rename's preview`.
- [ ] **Step 2: Run them and check that they fail.** Run: `docker compose exec -T frontend npx jest src/app/settings/import`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and check that they pass.** Same command, plus `npx jest src/app/settings/settings-api` if that spec exists. Expected: PASS.
- [ ] **Step 5: Commit** `feat(#2541): rename a folder's tag in the opml dialog`.

### Task 9: The feed's site opens in a new tab

**Files:**
- Modify: `backend/src/Service/Library/Opml/Model/OpmlFeedModel.php`, `OpmlDocumentReader.php`, `backend/src/Http/OpmlJson.php`
- Modify: `frontend/src/app/settings/import/opml-import.models.ts`, `opml-import-selection.ts`, `opml-import-dialog.component.{html,scss,spec.ts}`
- Test: `backend/tests/Service/Library/Opml/OpmlDocumentReaderTest.php`, `backend/tests/Controller/Api/OpmlControllerTest.php`, `frontend/src/app/settings/import/opml-import-selection.spec.ts`

**Interfaces:**
- Produces: `OpmlFeedModel(string $url, ?string $title, string $sourceFormat, ?string $siteUrl = null)`. The reader sets `siteUrl` from `htmlUrl` only when `parse_url` gives scheme `http`/`https` and a host; otherwise null. The preview feed JSON gains `'siteUrl' => $feed->siteUrl` (extend the `@return` shape in `OpmlJson`).
- Produces: `OpmlPreviewFeed.siteUrl: string | null`. The feed row gains `siteHref: string`, which is `siteUrl ?? '<protocol>//<host>/'` of the feed URL, or the feed URL itself when it doesn't parse (built in `flatten` with `parseUrl`).
- Template: the `.host` span becomes `<a class="host" [href]="row.siteHref" target="_blank" rel="noopener noreferrer" data-testid="opml-import-site">` placed so a click doesn't toggle the checkbox.

- [ ] **Step 1: Write the failing tests.** Reader: `testAnOutlineCarriesItsSiteUrl` (`htmlUrl="https://example.com/"`); `testANonHttpSiteUrlIsDropped` (`htmlUrl="javascript:alert(1)"` → null); `testAMissingSiteUrlIsNull`. Controller: `testPreviewListsEachFeedsSiteUrl`. Selection: `a feed links to its site from the file`; `a feed without a site links to its feed's origin` (`https://blog.example/feed.xml` → `https://blog.example/`). Dialog: `the site link opens in a new tab and leaves the tick alone` (`target === '_blank'`, `rel` contains `noopener`, click → ticks unchanged).
- [ ] **Step 2: Run them and check that they fail.** Run: `php bin/phpunit tests/Service/Library/Opml/OpmlDocumentReaderTest.php tests/Controller/Api/OpmlControllerTest.php` and `docker compose exec -T frontend npx jest src/app/settings/import`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them and check that they pass.** Same commands. Expected: PASS. Then run cs and stan on the changed PHP.
- [ ] **Step 5: Commit** `feat(#2541): an opml feed's site opens in a new tab`.

### Task 10: Live check

- [ ] With the branch in the main checkout and the Docker stack up, import a nested OPML at `http://localhost:4200` in the built-in browser. Check that you can collapse a folder, rename one onto a sibling's name and see both rows end in the same tag, set Folder + tags on one feed, bulk-tag with all ticked and see the warning, import, and that the tags are right in the sidebar. Click a feed's site link and check that it opens in a new tab without changing the tick. Drop a `.zip` on the OPML zone and see the error. Drop a backup archive on the backup zone and see its preview. Check the dialog at phone width.
- [ ] Scan today's `backend/var/log/dev-*.log` for errors or deprecations: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.
