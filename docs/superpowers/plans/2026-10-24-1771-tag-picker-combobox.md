# Tag Picker Combobox Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the wall of tag pills in the add-feed and edit-feed dialogs with a compact combobox — selected tags as chips, a filterable list, suggestions from the latest subscriptions, inline tag creation — and a full-screen tag sheet on phones and touch devices.

**Architecture:** Frontend only. The picker keeps its public contract (`tags`, `selected`, `toggled`) and gains a `suggested` input. The filtering, grouping, keyboard cursor and creation flow live in one plain class, `TagSearch`, that both surfaces instantiate: the inline combobox (fine pointer, ≥ `bp-sm`) and `TagSheetComponent`, a CDK dialog over the current one (phone or coarse pointer). One presentational `TagOptionListComponent` renders the listbox for both. Suggestions are a pure derivation over `SubscriptionsStore.subscriptions()` (newest `createdAt` first); creation posts through the existing `ReaderApi.createTag` and inserts the new tag into `TagsStore` without a reload.

**Tech Stack:** Angular 20 (standalone, signals), CDK Dialog, Transloco, Jest (jsdom).

**Spec:** GitHub issue #1771; design canvas linked there (boards "A + D" and "A + D on a phone").

## Global Constraints

- Session branch `claude/affectionate-gauss-rs99tu` (off `develop`); commits `feat(#1771): …`; no Claude attribution anywhere.
- Picker API stays `tags` / `selected` / `toggled`; it owns no selection state (#1034). New input: `excludeSubscriptionId: number | null` (default `null`); the picker derives its suggestions from `SubscriptionRows` itself.
- Suggestions: tags of subscriptions ordered by `createdAt` descending, deduped, selected tags dropped, the edited subscription skipped, first 4. Computed by the dialogs with one shared helper.
- Created tags are roots: `{ name, color: null, icon: null, parentId: null }`. The new tag is selected via `toggled`.
- "Create tag" appears only when the trimmed query is non-empty and no tag's own name equals it (case-insensitive).
- Sheet mode when `LayoutService.isPhone() || LayoutService.isCoarse()`. The sheet is a second CDK dialog (`app-overlay-panel fillOnMobile`) instead of the issue's "page inside the same dialog": the edit dialog is a content-height card on phones, so an in-card page would be cramped, and a stacked dialog gets focus trap, Escape and backdrop for free.
- No nested scroller in the dialog body on touch (#868): the inline listbox is fine-pointer only; the sheet's list is the sheet body's single scroller.
- Rows ≥ `--tap-target` under a coarse pointer; inputs in the sheet at `--fs-read` (16px, no iOS focus zoom).
- Tokens only in `.scss` (no hex, no ad-hoc px); sibling `.scss` files; no new comments unless a reader would get the code wrong without one.
- New i18n keys in **both** `frontend/public/i18n/en.json` and `de.json`.
- Tests: `npx jest <path>` from `frontend/` (the Docker frontend container where available); one Jest run at a time. Gate: `npm run check`.

---

### Task 1: Suggestions derivation and `TagsStore.add`

**Files:**
- Modify: `frontend/src/app/reader/state/subscription-derivations.ts`
- Modify: `frontend/src/app/reader/state/tags.store.ts`
- Test: `frontend/src/app/reader/state/subscription-derivations.spec.ts`, `frontend/src/app/reader/state/tags.store.spec.ts`

**Interfaces:**
- Produces: `suggestedTags(subscriptions: readonly SubscriptionDto[], selected: ReadonlySet<number>, exceptSubscriptionId: number | null): TagDto[]` (limit 4, exported const `SUGGESTED_TAG_LIMIT`).
- Produces: `TagsStore.add(tag: TagDto): void` — inserts and re-sorts with `compareTagPositions`.

- [ ] **Step 1: failing tests** — newest-first order across subscriptions; dedupe; selected dropped; excepted subscription skipped; limit 4; empty when no subscription has tags. `add` keeps position order.
- [ ] **Step 2: implement** — sort a copy by `createdAt` descending (`localeCompare` on ISO strings), `flatMap` tags, first-seen dedupe by id, filter selected, slice.
- [ ] **Step 3: run** both specs green; commit `feat(#1771): derive tag suggestions from the newest subscriptions`.

### Task 2: `TagSearch` — query, grouping, cursor, creation

**Files:**
- Create: `frontend/src/app/reader/feeds/tag-picker/tag-search.ts`
- Create: `frontend/src/app/reader/feeds/tag-picker/tag-creator.service.ts`
- Test: `frontend/src/app/reader/feeds/tag-picker/tag-search.spec.ts`, `tag-creator.service.spec.ts`

**Interfaces:**
- Produces: `TagCreator` (`providedIn: 'root'`): `create(name: string): Observable<TagDto>` — `api.createTag({ name, color: null, icon: null, parentId: null })`, maps to `response.tag`, taps `tagsStore.add`.
- Produces:
  ```ts
  export interface TagOption {
    readonly id: number; readonly name: string; readonly parentName: string | null;
    readonly label: string; readonly icon: string | null; readonly color: string | null;
    readonly selected: boolean; readonly indented: boolean;
  }
  export type TagEntry =
    | { readonly kind: 'option'; readonly group: 'suggested' | 'all' | 'matches'; readonly option: TagOption }
    | { readonly kind: 'create'; readonly name: string };
  export interface TagSearchSource {
    readonly tags: Signal<readonly TagDto[]>;
    readonly selected: Signal<ReadonlySet<number>>;
    readonly suggested: Signal<readonly TagDto[]>;
    readonly toggle: (id: number) => void;
  }
  export class TagSearch {
    constructor(source: TagSearchSource, creator: TagCreator);
    readonly query: WritableSignal<string>;
    readonly activeIndex: WritableSignal<number>;
    readonly creating: Signal<boolean>;
    readonly error: Signal<Problem | null>;
    readonly entries: Signal<TagEntry[]>;
    setQuery(text: string): void;     // resets cursor and error
    move(step: 1 | -1): void;          // wraps
    takeActive(): void;                // pick or create
    pick(id: number): void;            // toggle + clear query
    create(): void;                    // creates, then toggle(newId) + clear query
  }
  ```
- Empty query: `suggested` group (unselected suggestions), then `all` in `tagsInTreeOrder` with children `indented`. Query: `matches` on `tagLabel` (`includes`, case-insensitive), not indented, then the `create` entry when allowed.

- [ ] **Step 1: failing tests** — grouping with/without query; "en" matches "News / News en"; create entry rules (exact own-name match hides it, case-insensitive; blank query hides it); cursor wraps; `takeActive` on an option toggles and clears; on create calls the creator, toggles the new id, clears; while creating a second create is ignored; a failed create sets `error` (parsed problem) and keeps the query. Creator spec: posts the root body and adds to the store.
- [ ] **Step 2: implement** (signals in a plain class; `TagSearch` built with `new` by its two hosts, so no DI for it).
- [ ] **Step 3: run** green; commit `feat(#1771): tag search model shared by the combobox and the sheet`.

### Task 3: `TagOptionListComponent`

**Files:**
- Create: `frontend/src/app/reader/feeds/tag-picker/tag-option-list.component.{ts,html,scss}`
- Test: `tag-option-list.component.spec.ts`

**Interfaces:**
- Inputs: `entries: TagEntry[]`, `activeIndex: number`, `listId: string`, `creating: boolean`. Outputs: `picked: number`, `create: void`. Method `optionId(index)` → `${listId}-option-${index}`.
- Renders `<ul role="listbox">`; a group heading (`role="presentation"`) where the group changes ("Suggested", "All tags"); `role="option"` rows with tag glyph, muted parent prefix when `parentName && !indented`, a check icon and `aria-selected` when selected; the create row reads "Create tag "{name}"" or "Creating…". Empty: "No matching tags" (shown only if no entries).
- Mouse only on rows (keyboard is the combobox's `aria-activedescendant`), mirroring `searchable-select`'s eslint note.

- [ ] **Step 1: failing tests** — headings appear per group; selected row has `aria-selected="true"` and a check; click emits `picked`; create row emits `create`; active row has `.active` and the derived id.
- [ ] **Step 2: implement**; rows `min-height: var(--control-h)`, `var(--tap-target)` under `(pointer: coarse)`; active/hover `--accent-soft`.
- [ ] **Step 3: run** green; commit.

### Task 4: Inline combobox (`TagPickerComponent` rewrite)

**Files:**
- Modify: `frontend/src/app/reader/feeds/tag-picker/tag-picker.component.{ts,html,scss,spec.ts}`

**Interfaces:**
- Inputs `tags`, `selected`, `suggested` (new, default `[]`); output `toggled`. Injects `LayoutService`, `TagCreator`, `Dialog`.
- `sheetMode = computed(() => layout.isPhone() || layout.isCoarse())`.
- Template (both modes): a `.field` holding chips for selected tags in tree order (glyph + `tagLabel` + remove button `aria-label` "Remove {label}"); then a suggestion row of dashed pills (`suggested` minus selected) with a "Suggested" caption — one tap toggles.
- Inline mode: the text input inside `.field` (`role="combobox"`, `aria-expanded`, `aria-controls`, `aria-activedescendant`, placeholder "Add a tag…"); focus opens; `app-tag-option-list` below inside a `DismissOnOutside` wrapper; keys ↓/↑ move, Enter `takeActive`, Escape closes, Backspace on empty query toggles the last chip off; error shown with `app-form-error`-style line under the list.
- Sheet mode: the `.field` is a `<button>` ("Add a tag…" text after the chips) that opens `TagSheetComponent` (Task 5); chips' remove buttons still work (`stopPropagation`).

- [ ] **Step 1: failing tests** (inline mode, layout stubbed fine/wide) — chips render with labels, remove emits the id; suggestions row hides selected and emits on click; focusing opens the list with Suggested + All tags; typing filters; ArrowDown+Enter emits; Backspace on empty emits the last selected id; create posts `/api/tags` and emits the new id; Escape closes.
- [ ] **Step 2: implement**; `.field` uses `glass-rim.filled(var(--surface-1), var(--border-strong), control)`, `--rim-base: var(--accent)` on `:focus-within`; listbox panel as `searchable-select`'s `.panel` (in-flow, not absolute, so the dialog grows instead of clipping).
- [ ] **Step 3: run** green; commit `feat(#1771): tag picker as a combobox with chips and suggestions`.

### Task 5: `TagSheetComponent` (phone / touch)

**Files:**
- Create: `frontend/src/app/reader/feeds/tag-picker/tag-sheet.component.{ts,html,scss,spec.ts}`
- Modify: `tag-picker.component.ts` (`openSheet()`)

**Interfaces:**
- `DIALOG_DATA: TagSearchSource`; the picker passes its own input signals and `toggle: (id) => this.toggled.emit(id)`, so the sheet edits the caller's selection live.
- `app-overlay-panel` heading "Tags", `fillOnMobile`; body: search input (autofocus via `cdkFocusInitial`, `--fs-read`), selected chips, `app-tag-option-list` (always shown, no dropdown), error line; footer: primary "Done" closes.

- [ ] **Step 1: failing tests** — renders Suggested + All tags; typing filters; clicking a row calls `toggle`; Enter creates; Done closes the ref. Picker spec: in sheet mode (layout stubbed coarse) the field is a button and clicking it opens the dialog with the source.
- [ ] **Step 2: implement**.
- [ ] **Step 3: run** green; commit `feat(#1771): full-screen tag sheet on phones and touch`.

### Task 6: Wire the dialogs, copy, docs

**Files:**
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.{ts,html}` — drop the `@if (tagsStore.tags().length)` guard (creation works from zero tags); pass `[suggested]="suggestedTags()"` with `computed(() => suggestedTags(subscriptions.subscriptions(), checked(), null))`.
- Modify: `frontend/src/app/reader/feeds/manage/edit-subscription-dialog.component.{ts,html}` — same with `data.id`; remove the `dialog.editFeed.noTags` hint and key.
- Modify: `frontend/public/i18n/en.json`, `de.json` — `tagPicker.{placeholder, suggested, allTags, noMatch, create, creating, remove, sheetTitle, done, openSheet}`.
- Modify: `docs/design-language.md:359` — the flat-pill exception now names the picker's suggestion/chip shapes correctly (or is removed with `.on`).
- Modify: the dialogs' specs where they query `button.tag-pill`.

- [ ] **Step 1:** update dialog specs to the new DOM (chip/option selectors) and add one test each that the suggestion for the newest subscription's tag is offered.
- [ ] **Step 2:** implement, run both dialog specs.
- [ ] **Step 3:** commit `feat(#1771): dialogs pass suggestions; copy in en and de`.

### Task 7: Gate

- [ ] `npm run check` from `frontend/` (ESLint, Prettier, Stylelint, tsc app/spec/e2e, Jest). Fix everything.
- [ ] Re-read the diff adversarially (a11y attributes, no hex/px, no stale `tag-pill` selectors in e2e specs: `grep -rn "tag-pill" frontend/`).
- [ ] Push the session branch.
