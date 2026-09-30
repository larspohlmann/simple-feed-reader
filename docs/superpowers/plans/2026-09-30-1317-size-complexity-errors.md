# Size and complexity rules become errors — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; one commit per task). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Every finding of `max-lines` (300), `max-lines-per-function` (60), `complexity` (10), `@typescript-eslint/max-params` (3), `sonarjs/cognitive-complexity` (15) and `@angular-eslint/template/cyclomatic-complexity` (25) is fixed in the design, and all six rules become `error` (#1317). The thresholds do not change. There is no behaviour change.

**Architecture:**
- Four large components shed cohesive clusters, following the #1303 precedent:
  - **Services** (`@Injectable()` in the component's `providers`, exposed as a field) for logic that needs no component inputs.
  - **Plain helper classes** built in field initializers with an options object (`new X({...})`, which is injection context, so `inject()`/`effect()` work) for logic that reads the component's inputs or view queries. A component-provided service cannot inject its host component (NG0200).
  - **Child components** for template regions whose `@if`/`@for`/`@case` push the template over 25. Their hosts are `display: contents`, so the boxes stay identical.
- Small functions are fixed with one of three patterns:
  - lookup tables typed `Record<Kind, …>`, which keep exhaustiveness;
  - an options or value object for 4 parameters;
  - extracted helpers.

The designs below were produced by reading the code at `c0a52544b`. Line numbers are approximate; find members by name.

**Tech Stack:** Angular 20 signals, standalone components, CDK drag-drop, Jest and Playwright in Docker.

**Out of scope (lean):** thresholds; the ALTCHA hot loop in `sha256Hex` (only padding and the message schedule move out); anything not reported by the six rules.

## Global Constraints

- Starts after #1314 (merged as `c0a52544b`). Branch `refactor/1317-size-complexity-errors` off `develop`. Commit format `type(#1317): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate after every task: `docker compose exec -T frontend npm run check` and `npm run build`. One Jest process at a time.
- **Names:** `id-length` and `prevent-abbreviations` are errors. The design sketches below use full names; never introduce `el`, `ev`, `s`, `b`, `d`, `grp` and the like.
- **Effect order is load-bearing.** A service's or helper's effects are created at the moment it is constructed. Keep each moved effect at its original position in the creation order; each task says how.
- **CSS encapsulation:** markup that moves to a child takes its CSS rules along. Any rule left behind silently stops matching. After each UI task, compare computed styles of the moved region in the browser on :4200.
- **Specs stay in their existing spec files.** Only the reach-ins to moved members change. Never change an asserted value.
- **Moves and new files:** if the :4200 dev server keeps serving the old tree after new files appear, run `docker compose restart frontend`.

---

### Task 1: Small functions and spec helpers

Each item states the fix. For a max-params fix, update every call site; the counts are given.

**Production code:**

- **`admin/catalog/feed-form-dialog.component.ts` field initializer (complexity 15).** Add a module-level `initialFeedValues({ feed, categoryId }: FeedFormData): FeedFormValues`.
  - With no feed, it returns the empty defaults with the given `categoryId`.
  - Otherwise it copies the feed's fields, keeping `siteUrl ?? ''` and `description ?? ''`.
  - The form group is built from its result.
- **`core/preferences/digest.service.ts` `adopt` (14).**
  - Replace the six `DEFAULT_*` constants with one `DIGEST_DEFAULTS: UserDigestPreferences`.
  - Add `withDefaults(stored: Partial<UserDigestPreferences> | undefined)`. It falls back per field: `pick = <Key extends keyof …>(key: Key) => stored?.[key] ?? DIGEST_DEFAULTS[key]`.
  - Add `private apply(preferences: UserDigestPreferences)`, which sets the six signals.
  - `adopt` becomes `apply(withDefaults(user.preferences?.digest))`. `reset` becomes `apply(DIGEST_DEFAULTS)` followed by `saveFailed.set(false)`.
- **`core/problem.ts` `problemFromBody` (12).** Add three helpers: `isProblemBody(body): body is Record<string, unknown>`, `stringField(fields, key)` and `numberField(fields, key)` (the last two use `typeof` guards). Each field then reads through a helper, e.g. `status: numberField(fields, 'status') ?? status`.
- **`reader/article/reading/reading-sections.ts`:**
  - `divide` (11): add a table `DIVIDERS: Map<string, (block: HTMLElement) => HTMLElement[]>`.
    - PRE, FIGURE, VIDEO, IFRAME and IMG map to `() => []`.
    - UL and OL map to their `li`; DL maps to `dt, dd`; TABLE maps to `tr`.
    - `divide` becomes `DIVIDERS.get(tag)?.(block) ??` the existing fallback.
  - `focusUnits` (4 params): becomes `focusUnits(blocks, scrollerHeight, unit: UnitMeasure)` with `interface UnitMeasure { measure: MeasureHeight; lang: string }`. 1 production call site and 9 in the spec.
- **`reader/article/reading/reading-focus.ts` `focusOpacityForSpan` (4):** becomes `(span: BlockSpan, viewportHeight, curve = LIST_FOCUS_CURVE)` with `interface BlockSpan { top: number; bottom: number }`. Call sites: `reading-focus-applier.ts` (1) and the spec (31, mechanical).
- **`reader/list/magazine/magazine-planner.ts`**: a per-pass state object replaces the threaded parameters.

```ts
interface PlanPass {
  readonly ordered: EntryDto[];
  readonly templates: readonly (readonly Slot[])[];
  readonly complete: boolean;
  readonly collapseEnabled: boolean;
  readonly blocks: MagazineBlock[];
  page: number;
}
```

  - The emitters become `emitPages(pass, items): void`, `emitFeaturedLead(pass, run)` and `emitInterlopers(pass, run)`. They push into `pass.blocks` and advance `pass.page` directly.
  - `planMagazine` (complexity 14, cognitive 20) splits into four functions:
    - `startPass(input): PlanPass`: family choice, `leadWithImage`, `collapseEnabled`.
    - `planStep(pass, index): number | null`: runs `detectRun` only when collapse is enabled, then calls one of the next two.
    - `collapseRun(pass, run): number | null`: returns `null` for the "defer" break; otherwise lead, digest, interlopers, then `run.end`.
    - `emitOrdinaryPage(pass, index): number | null`: returns `null` for the partial-page break.
    - The body becomes `const pass = startPass(input); let index: number | null = 0; while (index !== null && index < pass.ordered.length) index = planStep(pass, index); return pass.blocks;`
  - `toBlock` becomes `(kind, entry, at: SlotAt)` with `interface SlotAt { page: number; position: number }`.
  - `fits` (21): add a table `FITS: Record<EntryKind, (entry: EntryDto) => boolean>`, backed by `imageAtLeast(entry, min)` and `landscapeImageAtLeast(entry, min)`.
    - Both helpers keep the exact rule `width >= min || (width === 0 && !!imageUrl)`; the landscape one adds `!isPortrait`.
    - hero is landscape 500, wide is landscape 400, split is 300, thumb needs an image, quote is snippet ≥ `QUOTE_MIN_TEXT`, kicker needs a summary, compact is always true.
    - `isImageRich` stays as it is.
- **`reader/query/query.ts`:**
  - `viewQuery` (22): add a table `VIEW_QUERY: Record<Selection['kind'], (selection) => EntryQuery>` with helpers `withUnread(query, unread)` and `listView(selection)`. Keep the key order of the returned objects.
  - `markReadTarget` (12): add `MARK_READ_TARGET: Partial<Record<Selection['kind'], …>>` with an `idScoped(scope)` helper. The body becomes `MARK_READ_TARGET[selection.kind]?.(selection) ?? null`.
- **`settings/recommendations/recommendation-settings-card.component.ts` `onReset` (17):** add a module-level `typedSeed(state): TypedSeed` that keeps the existing `??` fallbacks. `onReset` becomes `discardDraft()`, then the eight `.set(seed.…)` calls, then the two error resets.
- **`auth/sha256.ts` `sha256Hex` (76 lines):** extract `padMessage(bytes): Uint8Array` and `expandSchedule(padded, blockOffset): void`. The working variables and the 64-round loop stay inline, because this is the ALTCHA hot path. `sha256.spec.ts` compares against `crypto.subtle`.
- **`reader/article/decorators/reader-slideshow.ts` `build` (85 lines):** extract four pieces; `build` ends at about 20 lines:
  - `slideStepper(parts: SlideshowParts): (delta: number) => void`, which keeps `current` in its closure; the old `show(0)` becomes `step(0)`;
  - `controlBar(labels, counter, step)`;
  - `bindArrowKeys(figure, step)`;
  - `bindSwipe(figure, step)`.
- **`reader/reader-api.ts` `subscribe` (4):** becomes `subscribe(request: SubscribeRequest)` with `interface SubscribeRequest { url: string; format?: string; tagIds?: number[]; title?: string }`. It keeps omitting a falsy format, a falsy title and empty `tagIds`. Call sites: `add-feed-dialog` (1) and `reader-api.spec` (3).
- **`reader/reader-gestures.ts` `atBottom` (4):** becomes `atBottom(metrics: ScrollMetrics, tolerance = 2)` with `type ScrollMetrics = Pick<Element, 'scrollTop' | 'clientHeight' | 'scrollHeight'>`. Call sites: reader-view (1) and the spec (2).
- **`reader/scroll/header-scroll.ts` `nextHeaderHidden` (4):** becomes `nextHeaderHidden(scroll: HeaderScroll)` with `interface HeaderScroll { previousHidden: boolean; lastTop: number; top: number; isWide: boolean }`. Call sites: entry-list (1), reader-view (1) and the spec (7).
- **`reader/state/saved-searches.store.ts` `createSavedSearch` (4):** becomes `createSavedSearch(draft: SavedSearchDraft, onSuccess?)`. Give the inline body type in `reader-api.ts` a name, `SavedSearchDraft { term; wholeWord; phrase }`. Call sites: the shell (1) and the spec (3).

**Specs:**

- **`admin/users/admin-user-detail.component.spec.ts:~103` (complexity 29):** add `cellTexts(root, selector): string[]`, which returns the trimmed `textContent ?? ''`.
- **`reader/shell/sidebar/sidebar.component.spec.ts`:**
  - `mount` (17): add `inputDefaults()`, which returns a fresh object holding the 12 input defaults. Loop over it with `setInput(name, overrides[name] ?? fallback)`. `user ?? account(null)` stays outside the loop.
  - `drop` (4): becomes `drop(item, target, from: { source?: DropData; currentIndex?: number } = {})`.
  - The three 4-parameter `moveFeedToTag` mock arrows are handled in Task 3.
- **`settings/recommendations/recommendation-run-history-month.component.spec.ts:~218` (13):** add a table `HEADER_CELLS: [selector, label][]`, looped through `cellText(header, selector)`. Keep the per-row comments.
- **`reader/list/entry-list/entry-list.component.spec.ts` `stubGeometry`:** becomes `(fixture, entries, fold: { scrollerTop?: number; headerBottom?: number } = {})` with defaults 0 and 100. All 9 calls drop the last two arguments.
- **`reader/shell/drawer-swipe.directive.spec.ts` `swipe`:** becomes `(fromX, toX, at: { y?: number; target?: Element } = {})`.
- **`reader/state/subscriptions.store.spec.ts`:**
  - `sub` drops its fourth parameter; its 4 callers use `{ ...sub(1, 0, tags), entryCount: 30 }`.
  - The identical `counts` and `fullCounts` merge into one module-level `counts(subscriptions, totals: { favorites?; kept?; viewed? } = {})`.
  - `countsBody` takes the same `totals` object.
- **`settings/about/about-section.component.spec.ts` `mount`:** becomes `mount(api, options: { unavailable?; store?; activity? } = {})`.
- **`settings/backup/backup-archive.spec.ts`:** add `interface EncodedMember { nameBytes; data; crc }`, taken by both `buildCentralDirectoryEntry(member, offset)` and `buildLocalFileHeader(member)`.
- **`settings/organise/organise.store.spec.ts` `sub`:** becomes `sub(id, title, at: { tagIds?: number[]; position?: number } = {})`.
- **`settings/settings-nav.component.spec.ts` `mount`:** becomes `mount(roles, options: { variant?; unhealthyCount?; mailFailureCount? } = {})`.

- [ ] **Step 1:** Make the changes above, one file at a time, and run each file's spec after its change: `docker compose exec -T frontend npx jest <path>`.
- [ ] **Step 2:** Run `npx eslint "src/**/*.ts"` in the container. Every finding listed in this task must be gone.
- [ ] **Step 3:** Run the gate. Commit `refactor(#1317): small functions within the size and complexity limits`.

### Task 2: Reader shell and ListHeading

The shell is 454 lines and must get to about 242. `list-heading.service.ts` has two arrow functions with complexity 17 and 23.

Four injectables go in `ReaderShellComponent.providers`, each exposed as a field like `markRead` and `heading`:

1. **`shell/saved-search-toggle.service.ts`, `SavedSearchToggle`, field `savedSearch`.** It takes these members, renamed:
   - `viewingSavedSearch` → `viewing`
   - `canToggleSavedSearch` → `canToggle`
   - `searchedTermAndMode` (same name)
   - `currentSavedSearch` → `current`
   - `savedSearchActionLabel` → `actionLabel`
   - `onToggleSavedSearch` → `toggle()`
   - private `confirmRemoveSavedSearch` (same name)
   - `confirmToggleDigest(row)` (same name)
2. **`shell/refresh-actions.service.ts`, `RefreshActions`, field `refresh`.** It takes these members, renamed:
   - `fetchFailureKey` → `failureKey`
   - `onRefresh` → `refreshAll()`
   - private `refreshScope` (same name)
   - `onScopedRefresh` → `refreshScoped()`
   - `startRecommendations()` (same name)
   - private `confirmFreshRun` and `chooseResumeOrFreshRun` (same names)
   - `onAddFeed` → `addFeed()`
3. **`shell/list-reload.service.ts`, `ListReload`.** It is injected only for its effects, like `PasskeyFirstBootOffer`, and is the single owner of list reloads (#502). It takes the three reload effects, in their current order:
   - selection change, including `entryActions.clearLeaving()`;
   - refresh slice or finish;
   - For You `completedStamp`.
4. **`shell/reader-tab-title.service.ts`, `ReaderTabTitle`.** It takes the tab-title effect.
   - The adjacent-entry prefetch effect moves into `ReaderRouteState`'s constructor. That service already owns `openEntryId`, `EntriesStore` and `EntryBodyService`.

- [ ] **Step 1:** Create the four files and move the members.
  - Inject the fields in the order that keeps effect creation where it was. `ListReload` and `ReaderTabTitle` take the position of the first effect they replace.
  - Update the template bindings, e.g. `refresh.refreshAll()`, `savedSearch.canToggle()` and `!savedSearch.viewing()`.
  - Remove the injects and imports that are now unused.
  - What stays in the shell: header, drawer and app-bar geometry, the resize observer, the sidebar state, and the focus and close-on-selection effects. These depend on view children.
- [ ] **Step 2: ListHeading.** Replace each `switch` with a table declared above the computeds. The tables are typed `Record<Selection['kind'], …>`, so a missing kind still fails to compile.
  - `private readonly titleByKind: Record<Selection['kind'], () => string>`. `title` becomes `this.language.lang(); return this.titleByKind[this.routeState.selection().kind]();`.
  - `private readonly countByKind: Record<Selection['kind'], (selection: Selection) => TitleCount>`.
  - Signals read inside the table functions are still tracked, because they run inside the `computed`.
- [ ] **Step 3: Shell spec.** Make 20 mechanical rewrites:
  - `onRefresh`, `onScopedRefresh` and `onAddFeed` → `componentInstance.refresh.*`
  - `onToggleSavedSearch`, `currentSavedSearch` and `confirmToggleDigest` → `componentInstance.savedSearch.*`
  - Re-run the positional `ctrl.match` checks (around lines 194 and 1239). If request order shifted, fix the injection order, never the assertion.
- [ ] **Step 4:** Run the gate. On :4200: refresh all, a scoped refresh, add feed, save and unsave a search, the digest toggle, the tab title on article open, and the For You reload after a run. Commit `refactor(#1317): shell sheds saved-search, refresh, reload and title services`.

### Task 3: Sidebar and the feed move

The sidebar is 330 lines and must get to about 210. Its template complexity goes from 40 to about 21. `moveFeed`, `moveFeedToTag` and `afterMove` each have 4 parameters.

**Drag-drop stays in the sidebar template.** That covers `cdkDropListGroup`, every `cdkDropList`, every `cdkDrag` element and the tag rows. No new child contains a `cdkDropList`. Only the feed row's `cdkDragHandle` moves into a child. It registers with its `cdkDrag` parent through DI, which crosses component boundaries; `settings/organise/organise-tag-group.component.html` already does exactly this with `app-organise-feed-row`.

- [ ] **Step 1: The move value.** Reuse the existing `MoveFeedToTag { fromTagId; toTagId; position }` from `reader/models.ts`.
  - The signatures become `ManageActions.moveFeedToTag(subscription, move: MoveFeedToTag)`, private `afterMove(current, move: MoveFeedToTag)` and `api.moveFeedToTag(subscription.id, move)`.
  - The object must have exactly those three keys, because the spec compares the request body exactly.
  - Replace the sidebar's `moveFeed` with a module-level `feedMove(source: DropData, target: DropData, position: number | null): MoveFeedToTag` placed next to `tagIdOf`.
  - `organise-tag-group.component.ts` `moveFeedHere` builds the same object.
  - Specs:
    - `manage-actions.service.spec` (3 calls): pass the object argument.
    - `organise-tag-group.component.spec` (3): assert `toHaveBeenCalledWith(SUB, { fromTagId, toTagId, position })`.
    - `sidebar.component.spec`: the three mock arrows become `(subscription, move) => spy({ subscription, ...move })`. The assertions stay unchanged.
- [ ] **Step 2: `sidebar-row-actions.service.ts`, `SidebarRowActions`**, in `SidebarComponent.providers`.
  - It takes `menuFor`, `toggleMenu`, `closeMenu`, `openTagSheet`, `openFeedSheet`, the private `toggleLabel`, `exclusionTitle`, and the injects for the action sheet, transloco and `DestroyRef`.
  - Because it is provided on the sidebar, a late sheet choice still dies with the sidebar. The tag rows and the feed-row children share the one open-menu state.
- [ ] **Step 3: `sidebar-feed-row.component.{ts,html,scss}`, `SidebarFeedRowComponent` (`app-sidebar-feed-row`).**
  - It takes the inner content of both `.feedrow` blocks (the tagged one around lines 373–467 and the untagged one around 520–603), which removes the duplication.
  - Inputs: `subscription` (required), `organising`, `selection` and `tagId: number | null`.
    - The menu key is `'sub-' + tagId + '-' + id` when tagged, `'sub-' + id` when untagged.
    - The `tag-sub` class is set when `tagId` is non-null.
  - It injects `ManageActions`, `LayoutService` and `SidebarRowActions`, and imports `CdkDragHandle`.
  - Its host style is `:host { display: contents }`.
  - These CSS rules move with it:
    - `.feedname*`, `.tag-sub` and `.feed-exclusion-marker`.
    - `.feedrow .nav` becomes `.nav { flex: 1; min-width: 0 }`.
    - `.tagfeeds .nav:hover:not(.active)` becomes `.tag-sub:hover:not(.active)`.
    - `.cdk-drag-preview .rowmenu` becomes `:host-context(.cdk-drag-preview) .rowmenu`.
  - `.feedrow.cdk-drag-placeholder > *` stays in the sidebar.
- [ ] **Step 4: `sidebar-saved-searches.component.*`, `SidebarSavedSearchesComponent`.**
  - It takes template lines around 108–217. Its outer `@if (savedSearches().length)` moves **inside** the child, and the child always renders. That way the frozen order and the expanded state survive the count dropping to 0 and back.
  - Inputs: `savedSearches`, `activeSavedSearchId`, `combinedActive` (the parent passes `selection().kind === 'saved-searches'`) and `showDigestToggles`. Output: `toggleDigest`, which the sidebar re-emits.
  - It takes these members: `SIDEBAR_SAVED_SEARCH_LIMIT`, `rankedSavedSearchIds`, `sameIds`, `savedSearchesExpanded`, `frozenSavedSearchOrder`, `refreezeSavedSearchOrder`, `refreezeOnStructuralChange`, `orderedSavedSearches`, `savedSearchesUnread`, `savedSearchListExpanded`, `visibleSavedSearches`, `hiddenSavedSearchCount`, `toggleSavedSearchList` and `toggleSavedSearches`.
  - These CSS rules move with it:
    - `.savedsearch-*`;
    - the `.savedsearch-list` halves of the shared selector lists (split those lists);
    - `.digest-toggle .muted`;
    - the whole-word and phrase badge rules.
  - In the sidebar spec, the 10 direct `toggleSavedSearches()` calls go through a helper `savedSearchesOf(fixture)`, which queries `By.directive(SidebarSavedSearchesComponent)`.
- [ ] **Step 5: Shared row styles.** Add `shell/sidebar/_sidebar-row.scss` with mixins in the `theme/_segmented.scss` pattern: `nav-row`, `row-menu`, `drag-handle`, `chevzone` and `tag-head`. The sidebar and both children `@use` it. Put the includes **first**, so rules of equal specificity keep their current order.
- [ ] **Step 6:** Run the gate. On :4200, desktop and the mobile viewport, check each of these:
  - Drag a feed between tags and into the untagged list; reorder tags.
  - The drag preview hides the row menu, and the placeholder line shows.
  - Open a feed's row menu and a tag's row menu.
  - Organise mode shows the handles.
  - The saved-search list collapses and expands, and keeps its order after one is removed and re-added.
  - The coarse-pointer 44px targets hold.

  Commit `refactor(#1317): sidebar feed row and saved searches become components; one feed-move value`.

### Task 4: Entry list

The TS file is 646 lines and must get to about 255. The template complexity goes from 50 to about 19. All paths are under `reader/list/`.

**Helpers**, built in field initializers with options objects:

- **`entry-list/list-content.ts`, `ListContent`:** `new ListContent({ entries, hasMore, selection, newestRunId, leavingIds })`.
  - It takes `revealedCount`, `revealFrame`, `lastEntries`, `renderedEntries` (renamed `rendered`), `fullyRevealed`, `_revealAppended`, `startReveal`, `scheduleRevealStep` and `cancelReveal` (via `DestroyRef`).
  - It also takes `runGroups`, `showRunHeader`, `blocks`, `hiddenAboveIds`, `_resetHiddenAbove`, `visibleBlocks`, `visibleRunGroups` and `visibleEntryCount`.
  - New methods: `hide(ids)` and `clearHidden()`.
- **`entry-list/pull-to-refresh.ts`, `PullToRefresh`:** `new PullToRefresh({ scroller: () => HTMLElement | undefined, enabled: () => boolean, refreshing, reduceMotion, onTrigger })`.
  - It takes `MAX_PULL`, `REFRESH_REVEAL` (exported from here), `pulled`, `dragging`, `pullArmed`, `revealOffset`, `revealTransform`, `pullStartY`, `pullTracking` and `pullCleanup`.
  - It also takes `_wirePull`, `pullEnabled` (which becomes the `enabled` closure), `onPullStart`/`onPullMove`/`onPullEnd`, and the `--refresh-reveal` property, set via `inject(ElementRef)`.
- **`entry-list/list-scroll-state.ts`, `ListScrollState`:** `new ListScrollState({ scroller, selection, loading, layout, reduceMotion, aboveFold: (element) => boolean, onReloaded })`.
  - It takes the settle constants, `collapsed`, `lastScrollTop`, `showToTop`, `hasAboveFold` and `_resetCollapse`.
  - It takes `onRowsScroll` (renamed `onScroll`), `rowsBelongToSelection`, `renderedSelection`, `wasLoading`, `_restoreScroll` and `_scrollOnSelectionChange`.
  - It takes `applyScroll` (public, because the spec spies on it), `settleTo`, `cancelSettle`, `onUserScrollIntent`, and the host wheel and touchmove listeners.
  - New methods: `scrollToTop(): boolean` and `landAtTop()`.
  - Create its effects in this order: `_resetCollapse`, then `_restoreScroll`, then `_scrollOnSelectionChange`.
- **`entry-list/list-reading-focus.ts`:** `bindListReadingFocus({ scroller, rendered, entries, selection, reduceMotion }): void`. It takes the applier, `_bindReadingFocus`, `_pushReadingFocus` and the destroy hook.
- **`entry-list/above-fold.ts`** (existing file): add `measureEntries(scroller)` and `withHiddenDuplicates(ids, entries)`.
- **`scroller` is always a closure,** `() => this.rows()?.nativeElement`, evaluated at call time. The spec's `jest.spyOn(instance, 'rows')` relies on that; the same goes for `listHdr` in `aboveFold`.

**Field order in the component** (this is the effect order): `content`, `reloadSpinner`, `publish`, `bindListReadingFocus`, `measureHeader`, `pull`, `_wire`, `scrolling`.

**Child components**, each with `:host { display: contents }`:

- **`list-header/list-header.component.*`, `ListHeaderComponent` (`app-list-header`).**
  - It renders what is inside `<header class="list-header">`: `.heading` and `.tools`, around template lines 10–185. The `<header #listHdr [class.collapsed] [class.is-search]>` element itself stays in the parent, so the ResizeObserver, `foldTop` and the spec's `listHdr` stub are unchanged.
  - It takes these members:
    - `FIXED_VIEW_ICON` and `TitleCount` (update the import in `shell/list-heading.service.ts`);
    - `hasUnreadFilter`, `hasListOrder`, `oldestFirst` and `headingCount`;
    - `showWholeWordBadge`, `showPhraseBadge` and `titleIcon`;
    - `lastRefreshedLabel`, `nextRefreshLabel` and a copy of `canRefresh`;
    - `listTitle`, renamed `titleElement`, with a public `focusTitle()`.
  - Inputs: `selection`, `title`, `searchTitlePrefix`, `searchTitleTerm`, `searchCountLabel`, `titleCount`, `titleTag`, `titleFaviconUrl`, `lastRefreshed`, `nextRefresh`, `titleLeading`, `leadingActions`, `headerActions`, `collapsed`, `refreshing` and `canMarkAllRead`.
  - Outputs: `refresh`, `orderChange`, `unreadOnlyChange` and `markAllRead`; the parent re-emits them.
  - Its host binding is `{ '[class.collapsed]': 'collapsed()', '[class.is-search]': "selection().kind === 'search'" }`.
  - CSS: the header rules move (scss around lines 57–207 and 222–239, plus the container rule and the compact break), rewritten as follows:
    - `.list-header:not(.is-search) .title-row` becomes `:host(:not(.is-search)) .title-row`;
    - `.list-header.collapsed …` becomes `:host(.collapsed) …`;
    - `.list-header h2 …` becomes `h2 …`.
  - The parent keeps `.list-header`, `.list-header.collapsed { padding }` and the reduced-motion transition.
- **`magazine/magazine-block.component.*`, `MagazineBlockComponent`.** Inputs: `block: MagazineBlock` and `feedTags`.
  - It takes the `@switch` (around lines 289–342), `entryOf`, `side`, the group helper (the former `grp`, renamed `group`), `NO_TAGS` (keep its shared identity; OnPush, #501), its own `tagsFor`, and the 8 block imports.
  - The slot, `.row-slot-inner` and the strip stay in the parent, because the airy sibling rule needs them as direct children. No CSS moves.
- **`list-empty-state/list-empty-state.component.*`, `ListEmptyStateComponent`.** Inputs: `selection` and `savedSearchCount`.
  - It takes lines around 245–256, `displayedSearchTerm`, `catalogEmpty`, `suggestFeeds`, `FEW_SUBSCRIPTIONS`, the catalog and subscriptions injects, `RouterLink` and `CaughtUpIllustration`.
  - The `.empty` CSS moves with it. `.empty-wrap` and `.top-block` stay in the parent.

**What stays in the component:**
- All inputs and outputs (the shell spec reads `titleCount()` and `titleTag()`).
- `collapsed`, as an alias of `scrolling.collapsed`.
- `scrollToTop()`: the helper, then `header()?.focusTitle()`.
- `hideAboveMarked()`: `cancelSettle`, `content.hide`, `landAtTop`.
- The private view queries, plus a new `header = viewChild(ListHeaderComponent)`.
- `foldTop`, `onMarkAboveRead`, the header measurement, the sentinel `_wire`, `reloadSpinner` and the layout helpers.

- [ ] **Step 1:** Create the helpers first and move the members; update the entry-list spec reach-ins as you go:
  - `revealOffset`/`revealTransform` → `.pull.*`, and `REFRESH_REVEAL` is imported from `./pull-to-refresh`.
  - `hiddenAboveIds`, `blocks`, `visibleBlocks`, `visibleRunGroups` and `visibleEntryCount` → `.content.*`.
  - `onRowsScroll` → `.scrolling.onScroll`; `showToTop` and `hasAboveFold` → `.scrolling.*`.
  - The `applyScroll` spies → `componentInstance.scrolling`.

  Run the spec, then commit `refactor(#1317): entry list logic moves into content, scroll, pull and focus helpers`.
- [ ] **Step 2:** Create the three child components, moving template regions and their CSS. Run the spec. Update the comment in `e2e/list-header-narrow-pane.spec.ts` that names the header scss file.
- [ ] **Step 3:** Run the gate and `npm run build`.
  - Run the Playwright specs `list-header-count-one-line`, `list-header-narrow-pane` and `list-header-actions-mobile` (the Docker stack is up).
  - On :4200:
    - the header in all selections, including search with its badges;
    - collapse on scroll, and scroll to top;
    - pull to refresh in the mobile viewport;
    - mark above read;
    - the magazine in boxed and airy layouts;
    - the empty states;
    - list reading focus.

  Commit `refactor(#1317): entry list header, magazine block and empty state become components`.

### Task 5: Reader view

The TS file is 610 lines and must get to about 235. The constructor is 138 lines and must get to about 40. The template complexity goes from 26 to 23. Also fixed: an arrow with complexity 11 and `onTouchMove` with 13. All paths are under `reader/article/`.

**Wiring:**
- The services are `@Injectable()`s in `providers`, next to the existing `READER_SCROLLER` factory. They reach the host element through `inject(READER_SCROLLER)` and clean up with their own `DestroyRef`.
- Whatever only the component has (inputs, `viewChild` signals, `close.emit`) goes in through one `connect(…)` call made from the constructor, and **`connect` creates the service's effects**. Effects must not be created in service constructors: injection happens at field init, which would put them before the entry effect.
- Add `reading/reduced-motion.ts` with `prefersReducedMotion(): boolean`, replacing the `reduceMotion` field.

- [ ] **Step 1: Services.**
  - **`reader-view/article-gestures.service.ts`, `ArticleGestures`:** back-swipe and pull-to-return.
    - It takes `MAX_PULL`, `LEAVE_ANIM_MS`, `MEDIA_CONTROL_SELECTOR`, `startsOnMediaControl`, the gesture fields, `readerTransform`, `readerTransition`, `pulling`, `pullArmed`, `onTouchStart`/`onTouchMove`/`onTouchEnd`, `slideBack`, `leave`, the touch listener add/remove (keep the same `passive` flags, with `touchmove` non-passive) and the `leaveTimer` clear.
    - API: `connect({ fullscreen: Signal<boolean>; close: () => void })`, plus public `leaving` and `slideBack()`.
    - `onTouchMove` splits into private `tracking(event): boolean` (shared with `onTouchStart`), `lockAxis(deltaX, deltaY): boolean` and `followDrag(deltaX, deltaY, event): void`.
    - It injects `ArticleScrollRestore`, and `onTouchStart` calls `restore.abort()`, as today.
  - **`reading/article-scroll-restore.service.ts`, `ArticleScrollRestore`.**
    - It takes the `ARTICLE_SETTLE_*` constants, `pendingRestore`, `restoreRaf`, `startRestore`, `cancelRestore`, the wheel abort, the destroy cancel, and the arming lines of the entry effect.
    - API: `arm(entryId)`, `reseat(currentId: () => number | undefined)`, `abort()` and `remember(id, top)`. `remember` is the save guard, minus the `leaving` check, which stays with the caller. It injects `ListScrollMemory`.
  - **`content/article-source.service.ts`, `ArticleSource`.**
    - It takes `READER_LOAD_TIMEOUT_MS`, `loadSub`, `state`, `loading`, `failed`, `errorDetail`, `article`, `paywalled`, `paywallUrl`, `heroFailed`, `heroSource`, `hero`, `feedBody`, `feedBodyFailed`, `displayHtml`, `runLoad`, the load branch of the entry effect, and the unsubscribe on destroy.
    - API: `connect(entry: Signal<EntryDto | null>)`, `open(entry)`, `reload(id)` and `retryBody(id)`.
  - **`reading/reading-scope.service.ts`, `ReadingScope`.**
    - It takes the `applier` and `scopeObs` fields (rename `scopeObs` to `scopeObserver`), the applier and focus-toggle effects, the resize listener, and the ResizeObserver effect with its destroy.
    - It also takes `contentBottom`, `readingBottom`, `viewportHeight`, `scrollTop`, `hasTail`, `showProgress`, `progressPercent`, `measureScrollRange`, `bottomInScroller`, `commentsHost` and `isPresent`.
    - API: `connect(content, comments)` (both are `viewChild` signals, read `untracked` as today), `refresh()`, `trackScroll(top)` and `reset()`.
- [ ] **Step 2: Plain modules.**
  - `decorators/decorate-article.ts`: `decorateArticle(host: HTMLElement, i18n: TranslocoService): void` and `openExternalLinksInNewTab(host)`. This takes the decorate microtask's body, which fixes the arrow with complexity 11 and drops 11 imports from the component.
  - `reading/reading-toc.ts`: `TocEntry`, `slugify` and `collectToc(host): TocEntry[]`.
- [ ] **Step 3: The entry effect** stays in the component, at about 12 lines. It keeps the `loadedId` guard and runs, in this order:
  1. `readerMode.reset()`, which must come before `source.open()`, because a synchronous load calls `enableToggle()`;
  2. `restore.arm(id)`;
  3. `toc.set([])` and `tocOpen.set(false)`;
  4. the `showToTop`, `toolbarHidden` and `lastToolbarScrollTop` resets;
  5. `scope.reset()`;
  6. `source.open(entry)`.

  The service calls must add no signal reads to this effect.
  - The decorate effect becomes: `decorateArticle`, then `toc.set(collectToc(host))`, then `scope.refresh()`, then `restore.reseat(...)`.
  - Call the `connect()`s at the original effect positions: scope's applier and focus effects after the entry effect, and the observer effect after the decorate effect.
  - The template reads `source.*`, `scope.*` and `gestures.*` as protected fields, with no aliases.
- [ ] **Step 4: `reader-toc/reader-toc.component.*`, `ReaderTocComponent` (`app-reader-toc`).**
  - It takes template lines around 146–167 (`@if showToc`, `@if tocOpen`, `@for`). The parent renders `<app-reader-toc [entries]="toc()" [(open)]="tocOpen" (jump)="scrollToHeading($event)" />` with no wrapping `@if`.
  - Child members: `entries = input.required<TocEntry[]>()`, `open = model(false)`, `jump = output<string>()`, `TOC_MIN_HEADINGS` and `visible = computed(...)`.
  - `tocOpen` stays in the parent, because it survives the reader/original toggle.
  - The `.toc*` CSS moves with it, plus `:host { display: block }`.
- [ ] **Step 5: Spec.** Only the gesture tests reach into moved members. Add `const gestures = (fixture) => fixture.debugElement.injector.get(ArticleGestures);`, then use it for `onTouchStart`/`onTouchMove`/`onTouchEnd` and `leaving()`. Everything else is DOM-level or uses members that stay on the component.
- [ ] **Step 6:** Run the gate. On :4200, desktop and the mobile viewport:
  - open an article and switch between reader and original;
  - the table of contents opens and jumps to a heading;
  - the reading progress bar;
  - scroll-position restore after closing an article and reopening it;
  - back-swipe and pull-to-return in the mobile viewport;
  - retry after a failed load (block the article request);
  - a slideshow, a code block and an embed.

  Commit `refactor(#1317): reader view sheds gestures, restore, source and scope; toc is a component`.

### Task 6: The flip

- [ ] **Step 1:** Run `docker compose exec -T frontend npx eslint "src/**/*.ts" "src/**/*.html"` and expect zero warnings.
- [ ] **Step 2:** In `frontend/eslint.config.js`, set these six entries from `"warn"` to `"error"`, keeping their options: `max-lines`, `max-lines-per-function`, `complexity`, `@typescript-eslint/max-params`, `sonarjs/cognitive-complexity` and `@angular-eslint/template/cyclomatic-complexity`. The spec override keeps `max-lines` and `max-lines-per-function` off.
- [ ] **Step 3: Break-test.** Add `export function breakTest(first: number, second: number, third: number, fourth: number): number { return first + second + third + fourth; }` to `src/app/core/api.ts` and expect a `max-params` **error**. Remove it by editing, then confirm the file is back as it was with `cmp` against `git show HEAD:…`.
- [ ] **Step 4:** Run the gate and the build. Commit `refactor(#1317): size and complexity rules are errors`, open the PR (`Closes #1317`) and merge when green.

## Amendments (implementation)

1. **Task 1, recommendation card:** a `typedSeed` that only moved the eight `??` fallbacks was itself at complexity 17 and pushed the file past 300 lines. It uses a local `pick(key, fallback)` like digest, returns the existing `RecommendationExpertDefaults`, and a new private `reseed(seed)` is shared with `resetToFactoryDefaults`.
2. **Task 1, query tables:** `Selection` is one interface with a `kind` union, not a union type, so `Extract<Selection, { kind: K }>` is `never`. `VIEW_QUERY` and `MARK_READ_TARGET` take `(selection: Selection)`.
3. **Task 1, `createSavedSearch`:** the store and spec change landed in the Task 2 commit, because its one call site moved into `SavedSearchToggle`. `SavedSearchDraft` is in the Task 1 commit.
4. **Task 1, counts:** `header-scroll.spec` has 6 `nextHeaderHidden` calls and `reader-api.spec` 4 `subscribe` calls. The sidebar has 13 inputs; `loading` joins `inputDefaults()`.
5. **Task 2, effect order:** the constructor is gone. `ListReload`, a `_closeDrawerOnSelection` field effect and `ReaderTabTitle` are declared after `_publishBarVars`. The refresh and For You reload effects are therefore created before the drawer-close and title effects. The positional request checks pass unchanged.
6. **Task 3, sidebar spec:** the mock arrows are `(sub, move) => spy({ sub, ...move })`, because the assertions key on `sub`.
7. **Task 4:** list reading focus is a class, `ListReadingFocus`, which holds the applier, rather than a `bindListReadingFocus` function. `NO_TAGS` is exported from `magazine-block.component.ts` and reused by the list rows' `tagsFor`, so both keep one identity. Steps 1 and 2 are one commit, because the slimmed component already depends on the children. `hasUnreadFilter` is public on `ListHeaderComponent`, and the shell spec's one reach-in queries that component, with its asserted value unchanged.
8. **Task 5:** `ReadingScope` also gets `observeResizes()`, called after the decorate effect, so the ResizeObserver effect keeps its position. `connect()` creates only the applier and focus effects. `ArticleGestures.connect` and `ArticleSource.connect` create no effects, because they need none. There is no `leaving` alias; the spec reads `gestures(fixture).leaving()` everywhere.
