# OPML import dialog: folders, tags and a shared file drop zone (#2541)

## Intent

Importing a large OPML file works today, but the dialog fights you. Every folder is always expanded. A folder's
name is fixed as the tag it becomes. A feed either goes into its folder's tag or gets hand-picked tags, never
both. Tags are set one feed at a time. The file pickers are bare browser `<input type=file>` controls. Lars wants
the dialog to let him shape the import before applying it, and both file pickers in Settings (OPML import and
Account backup restore) to look like part of the app and accept a dropped file.

## Goals

1. **Collapsible folders.** Every folder row in the import list expands and collapses.
2. **Rename a folder's tag.** The name a folder becomes as a tag can be edited before import.
3. **Folder plus tags.** A feed can keep its folder tag *and* get extra tags.
4. **Bulk tagging.** One tag choice applies to every ticked feed, with a warning when that is every importable feed.
6. **Open a feed's site.** The host shown on each feed row is a link that opens the feed's website in a new tab.
5. **Shared drop zone.** One shared component replaces the file input in the OPML section and in the Account
   backup section: a styled target that accepts a click, the keyboard, or a dropped file.

## Non-goals

- No change to what the checkboxes mean: they still decide which feeds are imported.
- No separate "selection for tagging" mode (Lars: "the current checkboxes are ok").
- No change to the backup restore flow beyond how the file is picked.
- No change to the OPML export.

## Anti-goals

- Renaming must not bypass the server's tag planning: the "(1)" de-duplication against existing tags, the reuse of
  an existing tag at the same spot, the 100-character limit and the depth limit (`Tag::MAX_DEPTH`) all still apply
  to a renamed folder.
- Bulk tagging must not silently retag everything. Ticking is the default for all feeds, so "apply to ticked" with
  all ticked must say so first.
- The drop zone must not become two copies of the same markup.

## Constraints

- `CLAUDE.md`: Symfony JSON API, the iOS-client constraint (bearer auth, JSON in, problem+json out; the rename
  logic lives on the server so a native client gets it for free), Clean Code rules, the PHPStan rules, PHPMD.
- Frontend: standalone components and signals, styles in a sibling `.scss`, no hex colours or ad-hoc `px` outside
  the theme, `docs/design-language.md` is the token and component catalog. A new shared component is added to it.
- The OPML import endpoints already exist and are in production; changes to their request shapes are additive.

## Approaches

**Renaming a folder's tag**

- **A. Backend override (chosen).** The preview and import requests carry a `folderNames` map keyed by the
  folder's position in the tree. `OpmlPlacementPlanner` uses the override in place of the OPML name, so every
  existing rule in `OpmlTagPlan` applies unchanged. Costs a DTO field, validation and backend tests.
- **B. Frontend rewrite of the OPML.** The dialog edits the outline's `text` attribute in the browser and
  re-previews; no backend change. Rejected: re-serialising the user's XML in the browser is fragile (encoding,
  namespaces), and a native client would have to duplicate it.

**Shared file picker**

- **A. Shared component `app-file-drop` (chosen).** In `src/app/shared/file-drop/`, used by both sections.
- **B. A directive on the existing `<input>`.** Rejected: each section keeps its own markup and styles, so the two
  pickers would share only half their code.

Lars picked A and A.

## Design

### 1. Collapsible folders (frontend only)

- Each folder row gets a disclosure chevron before its checkbox. Collapsing hides every row beneath it (feeds and
  subfolders); the folder's own checkbox and tri-state still reflect the hidden feeds.
- All folders start expanded. *My call*: nothing is hidden on first open.
- The list head gets one "Collapse all" / "Expand all" toggle next to Select all / Select none. *My call.*
- Collapse state lives in `OpmlImportSelection` as a set of collapsed folder keys and survives a re-preview
  (keys are positional and the tree shape does not change between previews of the same file).
- The chevron is a real button with `aria-expanded` and an accessible name naming the folder.

### 2. Renaming a folder's tag

**Wire format.** `POST` preview and import requests gain an optional
`folderNames: Record<string, string>`. The key is the folder's position: the zero-based child indexes from the
root, joined by `.` (`"0"`, `"0.2"`, `"0.2.1"`). The value is the new name. Validation: each value is a non-blank
string of at most 100 characters after trimming; unknown keys are ignored. An empty or blank value is rejected
(problem+json 422), not treated as "remove the folder".

**Backend.** The preview and import selection models carry the map. `OpmlPlacementPlanner::plan()` walks the tree
with the current position and uses `folderNames[position] ?? $folder->name`. Everything downstream (destination
paths, dropped-for-depth, de-duplication) is unchanged. The preview response does not change shape: the folder's
`name` stays the OPML name, and its `destination` reflects the rename.

**Frontend.**
- The folder row shows the tag name the folder will become (the last segment of the folder's `destination` from
  the preview, so it reflects "(1)" suffixes and renames) with a small edit button. Editing swaps the name for
  a text input (max 100); Enter or blur commits, Escape cancels. A committed rename that differs from the OPML name
  is held in `OpmlImportSelection`; setting it back to the OPML name removes the override.
- A commit re-runs the preview through the existing debounced preview stream, together with the parent tag. The
  Import button stays disabled until the preview that matches the current parent tag *and* renames has arrived
  (extend today's `previewedParent` check to cover renames).
- Subfolders follow automatically, because the server computes their paths from the renamed parent.
- **Renaming onto a sibling's name merges** (Lars). `OpmlTagPlan` keys a spot by parent plus lower-cased name, so
  two siblings with the same name already share one tag today; a rename to a sibling's name (any case) puts both
  folders' feeds and subfolders into that one tag. No change to `OpmlTagPlan`. The preview shows it: both folder
  rows end in the same tag name.
- A renamed folder shows a "Reset" affordance back to the OPML name. *My call.*

### 3. Folder plus tags

Each importable feed has three tag modes:

| Mode | Meaning | Wire |
|---|---|---|
| Folder only | today's default | not in either map |
| Folder + tags | folder destination path **and** the chosen tags | `addedTagIdsByFeedUrl[url]` |
| Tags only | today's "Change tags": chosen tags, no folder path | `tagIdsByFeedUrl[url]` (unchanged) |

- **Backend.** The import request gains optional `addedTagIdsByFeedUrl: Record<string, number[]>`, validated like
  `tagIdsByFeedUrl`. A URL present in both maps is rejected with a 422. `OpmlImportItems::buildItem()` passes both
  the destination `tagPath` and the chosen tags for an added-tags feed (`BulkSubscribeItemModel` already accepts
  both). Tag ids are resolved against the user's tags the same way `OpmlImporter::findChosenTags()` does today.
- **Frontend.** No mode control: the "Tags" toggle opens the tag picker with the feed's folder as one extra
  removable pill (folder glyph, the last segment of its destination) beside the chosen tags. Pill and no tags is
  Folder only, pill and tags is Folder + tags, pill removed is Tags only; a ghost "+ Folder" button where the pill
  was brings it back. A feed without a folder ("No tag") shows no pill and its tags go out as Tags only. The
  destination line reads, for example, "→ Tech / News + Linux, Later". *Lars, 2026-11-25: the three-way segmented
  choice was confusing; the folder is a tag pill.*

### 4. Bulk tagging

- A "Tag ticked feeds" button in the list head opens a panel above the list with the tag picker, plus Apply and
  Cancel. It opens with the folder pill and no tags; the pill names the folder when every ticked feed shares one
  destination and reads "Their folders" otherwise, and is absent when no ticked feed has a folder. Removing it and
  "+ Folder" work as on a feed and map onto the same three modes. *Lars, 2026-11-25: pill, not three modes.*
- Apply writes the resulting mode and tags onto every ticked importable feed, replacing each one's previous choice.
  Feeds that are not ticked are untouched. *My call: replace, not merge; the result is the same mode and tags on
  every ticked feed, which is what "set tags for all selected" asks.*
- When every importable feed is ticked, the panel shows an inline `app-warning-box`: "This tags all N feeds in the
  file." Apply still works. *My call: inline warning, not a confirm dialog.*
- Applying with no feeds ticked is impossible (button disabled).

### 5. Shared drop zone `app-file-drop`

- Lives in `src/app/shared/file-drop/` with a sibling `.scss`, and is added to the component catalog in
  `docs/design-language.md`.
- Inputs: `accept` (string, passed to the hidden input and used to filter drops), `disabled`, `label` (the main
  line, e.g. "Drop an OPML file here"), optional `hint`. Output: `picked` emitting a single `File`.
- Renders a dashed, rounded target with an icon, the label, and a "Choose file" button. The whole target is
  clickable. The hidden `<input type=file>` stays in the DOM for the native picker and for tests.
- Drag-over state highlights the target. A drop with several files takes the first one. A drop whose file does not
  match `accept` (by extension or MIME type) emits nothing and shows an inline error line. *My call.*
- Keyboard: the "Choose file" button is focusable and opens the picker. Disabled state blocks click and drop.
- The input's value is cleared after each pick, so picking the same file twice fires again.
- **OPML section** uses it with `accept=".opml,.xml,text/xml,application/xml,text/x-opml"` and calls its existing
  file handler with the `File`.
- **Backup section** uses it with `accept=".zip,.gz"` and calls its existing `onFile(file)`; `onFileSelected(event)`
  goes away.

### 6. Open a feed's site

- `OpmlDocumentReader` also reads the outline's `htmlUrl` (trimmed; kept only when it is an absolute `http`/`https`
  URL), `OpmlFeedModel` gains `?string $siteUrl`, and the preview JSON gives each feed `siteUrl: string | null`.
  Additive; the import request is unchanged.
- The feed row's host label becomes `<a target="_blank" rel="noopener noreferrer">` to `siteUrl`, or, when the
  file has none, to the feed URL's origin (`https://host/`). *My call: the origin is almost always the site, and
  opening the feed URL itself would show raw XML.* Clicking the link does not toggle the row's checkbox (it sits
  outside the `<label>`, or stops propagation).

## Left to the builder

- Mapping the frontend's current row keys (`f0`, `f0.1`) to the wire position keys (`0`, `0.1`); a single helper
  owns that mapping.
- Translation keys for every new string in all shipped languages (fr and es are first-class; loanwords over literal
  translations).
- Exact icons and spacing within the design-language tokens.
- Where the per-feed choice state is stored: extend `tagChoices` in `OpmlImportSelection` to carry the mode.

## Testing

- Backend: unit tests for the planner with overrides (rename, rename colliding with an existing tag → "(1)",
  rename reusing an existing tag at the same spot, rename of a parent moves the children, rename onto a sibling's name merges into one tag, unknown keys ignored), the
  import items with added tags, DTO validation (blank name, too long, URL in both maps), and a functional test per
  endpoint.
- Frontend: specs for `OpmlImportSelection` (collapse, rename overrides, the three modes, bulk apply, all-ticked
  warning condition, request building), the dialog, and `app-file-drop` (click, drop, wrong type, disabled,
  same-file twice), plus the two sections using it.
