# Sidebar views regroup and list-row thumb Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The thumbs up on list rows and magazine cards joins the favorite/kept/tick cluster as its first button, and the sidebar's views regroup into two cards — All items as a folder (Recently read, Liked, Shared) with Favorites and Kept, then Saved searches, For you, For you stream, Followed stories, Trending — all regular weight with one counter column.

**Architecture:** Frontend only. `EntryLikeComponent` learns to put its count before the icon, `EntryActionsComponent` learns to lead with the like, and `EntryMetaComponent` (the meta line of list rows and every magazine card) hands it to the actions instead of its discussion group; the grouped compact magazine entry's kicker-line actions lead with it too. The article view's actions keep today's order. In the sidebar, `sidebar.component.html` wraps the views in two `.card.views-group` wrappers; `SidebarViewsComponent` renders its items in three named slices instead of two cards; the four collapsible heads (and the new All items head) drop the boxed `section-head` look for a new `folder-head` row that shares the `.nav` row geometry, so weight, size and the count column match the plain rows.

**Tech Stack:** Angular 20 standalone components and signals, SCSS with the `_sidebar-row.scss` mixins, Jest (in the Docker `frontend` container), Playwright e2e against stubbed routes.

**Spec:** GitHub issue #2593 (body is the spec), plus these rulings from the conversation with Lars (2026-11-27):
- The thumb goes **first in the action cluster** (not the row's left edge), at the cluster's size; its count sits left of the icon.
- **List rows and magazine cards** (Lars, 2026-11-27: "Magazine cards should change, too"); the article/reader view's action bar is out of scope and keeps the like after the favorite.
- All items folder: **closed by default, open state remembered**.
- Favorites, Kept, Saved searches, Followed stories **keep their chevrons and sublists**.

## Global Constraints

- Sidebar order, verbatim: group 1 — **All items** (children in this order: **Recently read**, **Liked**, **Shared**), **Favorites**, **Kept**; divider; group 2 — **Saved searches**, **For you**, **For you stream**, **Followed stories**, **Trending**.
- None of these entries is bold (font-weight 400), except the existing `.active .name` emphasis on the current row, which stays.
- Every counter in both groups (heads and sublist rows) ends at the same x, on desktop and coarse pointer, in boxed and airy styles.
- Shared still shows only when `sharedCount() > 0`; For you only when `ai.ready()`; For you stream only when `forYouStream().available`; Trending only when `preferences.trendingEnabled()`; Saved searches and Followed stories only when they have entries — unchanged.
- No hex colours, ad-hoc `px` or media-query literals in component `.scss` (Stylelint); styles in sibling `.scss`.
- New i18n key `reader.toggleAllItems` in all four locales: en `Toggle All items`, de `Alle Einträge ein-/ausklappen`, fr `Afficher/masquer Tout`, es `Mostrar u ocultar Todo` (pattern of `toggleSavedSearches`).
- Persisted key for the folder: `sfr.sidebar.all-items.collapsed` via `persistedToggle(..., 'collapsed')`.
- Gate cadence (CLAUDE.md "Testing"): per task run only the touched specs in the container (`docker compose exec -T frontend npx jest <paths>`); no `npm run check` per task. Foreground commands only; no background shells, no `sleep`/`until` loops.

## Review Focus

1. **A child view is current while the folder is stored closed** (reload on `?view=liked`): the folder opens so the current row shows, the stored collapse stays, and the chevron still closes it — pinned in Task 2.
2. **Group 2 with nothing in it** (no AI, Trending off, no stream, no saved searches, no followed stories): no empty card or stray divider — pinned in Task 2 (Jest) and Task 3 (e2e).
3. **An expanded head inside a shared card** (Favorites open between Kept and All items): no band bleeding to the card edges, no extra rule; the sublist hangs under its head — pinned in Task 3 e2e screenshot-free geometry (head top equals previous row bottom + gap).
4. **Coarse pointer**: `.slot` is hidden and `.trail` widens to the tap target there; counters must still align — pinned in Task 3 e2e on the mobile project.
5. **A voted Lemmy post in a list row or magazine card** (no toggle, bare like count): the bare count also moves into the cluster, before nothing else — pinned in Task 1.

---

### Task 1: The thumb leads the action cluster on list rows and magazine cards

**Files:**
- Modify: `frontend/src/app/reader/entry/entry-like/entry-like.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/entry/entry-actions/entry-actions.component.{ts,html}`
- Modify: `frontend/src/app/reader/list/entry-meta/entry-meta.component.{ts,html}`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-compact/entry-compact.component.html`
- Test: `entry-like.component.spec.ts`, `entry-actions.component.spec.ts`, `entry-meta.component.spec.ts`, `entry-compact.component.spec.ts`

**Interfaces:**
- Produces: `EntryLikeComponent.countSide = input<'start' | 'end'>('end')`; `EntryActionsComponent.like = input<'first' | 'after-favorite' | 'none'>('after-favorite')` (replaces the boolean). `EntryMetaComponent` gets no new input: it always passes `like="first"`.

- [ ] **Step 1: Write the failing tests**
  - `entry-like`: `it('puts the count before the thumb when countSide is start')` — with `likeCount: 12` and `countSide: 'start'`, inside the button `.count` precedes `app-icon` (`compareDocumentPosition` FOLLOWING from count to icon); same for the bare `.likes` span on a voted Lemmy entry. Default `'end'` keeps icon first (existing tests stay).
  - `entry-actions`: `it('leads with the like when like is first')` — first child element of the host is `app-entry-like`; `like: 'none'` renders no `app-entry-like`; `'after-favorite'` keeps today's order (rewrite existing `[like]="false"` usages in the spec host to `'none'`).
  - `entry-meta`: replace `'puts the like before the comment count, out of the actions'` and `'keeps the like count beside the local toggle…'` with `it('hands the like to the actions, first and count-first')` — no `app-entry-like` inside `.discussion`; `app-entry-actions app-entry-like` is the actions' first element and its `.count` precedes its icon; a voted Lemmy entry with `likeCount` and `score` keeps the vote pill in `.discussion` and puts the bare count first in the actions; an entry with only a like (no score, no comments) renders no `.discussion` span.
  - `entry-compact`: `it('leads the kicker-line actions with the like')` — in the grouped (no source) case, `app-entry-kicker-line app-entry-actions` first element is `app-entry-like`.

- [ ] **Step 2: Run them, expect FAIL** — `docker compose exec -T frontend npx jest src/app/reader/entry/entry-like src/app/reader/entry/entry-actions src/app/reader/list/entry-meta src/app/reader/list/magazine/blocks/entry-compact`

- [ ] **Step 3: Implement**
  - `entry-like.component.html`: render `.count` before the icon when `countSide() === 'start'` in both the button and the bare span. In `.scss`, the start-side count uses `margin-inline-end: var(--space-1)` and no start margin.
  - `entry-actions.component.html`: `@if (like() === 'first') { <app-entry-like [entry]="entry()" [size]="size()" countSide="start" /> }` as the very first element (before the audio buttons and the label button); the existing after-favorite slot renders on `like() === 'after-favorite'`.
  - `entry-meta`: delete `showsLike` and the like from `.discussion` (its `@if` becomes score or comments); pass `like="first"` to `app-entry-actions`.
  - `entry-compact.component.html`: `<app-entry-actions [entry]="entry()" like="first" />`.

- [ ] **Step 4: Run the same jest command, expect PASS**; then `npx tsc -p tsconfig.app.json --noEmit` and `npx tsc -p tsconfig.spec.json --noEmit` in the container.

- [ ] **Step 5: Commit** — `feat(#2593): the thumb leads the action cluster on list rows and magazine cards, count first`

---

### Task 2: Regroup the sidebar views (structure and order)

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-views.component.{ts,html}`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.html`
- Modify: `sidebar-collection.component.html`, `sidebar-saved-searches.component.html`, `sidebar-followed-stories.component.html` (drop each component's own `<div class="card">` wrapper; the group card owns it)
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (`reader.toggleAllItems`)
- Test: `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`, `sidebar-followed-stories.component.spec.ts`, `sidebar-collection.component.spec.ts`

**Interfaces:**
- Produces: `SidebarViewsComponent.section = input<'all-items' | 'for-you' | 'trending'>('all-items')` (replaces `'primary' | 'discover'`; `showsDiscover` is deleted). Markup the styling task relies on:
  - `section="all-items"`: `<div class="folder-head all-head" [class.current]="selection().kind === 'all'">` containing `<a class="section-link" …all-items link…><app-icon name="inbox" size="md" /><span class="label">…allItems…</span>@if(totalUnread()>0){<span class="section-count">…</span>}</a><span class="slot" aria-hidden="true"></span><button class="chevzone" type="button" [attr.aria-expanded] [attr.aria-label]="'reader.toggleAllItems' | transloco">` (`expand_more`/`chevron_right`, size dense); then `@if (expanded()) { <div class="sublist"> Recently read, Liked, Shared `.nav` links, each ending in `<span class="slot" aria-hidden="true"></span><span class="trail" aria-hidden="true"></span>` }`.
  - `section="for-you"`: the For you and For you stream `.nav` links as today. `section="trending"`: the Trending `.nav` link as today. No `.card` inside the component in any slice.
  - The other three heads keep every class they have (`collection-head`, `savedsearch-head`, `followed-head`, `section-link`, `savedsearch-toggle`, `followed-toggle`, `chevzone`, `.label`, `.section-count`, `current`) except `section-head`, which becomes `folder-head`; their `<span class="favicon-slot"><app-icon … size="dense"/></span>` becomes a bare `<app-icon … size="md" />` (the plain rows' lead).
  - `sidebar.component.html`: `<div class="card views-group">` holding `app-sidebar-views section="all-items"`, `app-sidebar-collection favorites`, `app-sidebar-collection kept`; then `<div class="card views-group">` holding `app-sidebar-saved-searches`, `app-sidebar-views section="for-you"`, `app-sidebar-followed-stories`, `app-sidebar-views section="trending"`. The old `.collections` wrapper and its scss go (Task 3 removes the scss).
- Folder state in `SidebarViewsComponent`: `open = persistedToggle(() => 'sfr.sidebar.all-items.collapsed', 'collapsed')`; `childActive = computed(() => ['viewed','liked','shared'].includes(selection().kind))`; `collapsedHere` and `expanded` and `toggleExpanded()` follow `SidebarCollectionComponent`'s pattern exactly (a current child opens the folder without storing it; the chevron still closes it). If that pattern would now appear a third time, extract it into `core/preferences/` as one helper used by both — no copy.

- [ ] **Step 1: Write the failing tests** in `sidebar.component.spec.ts` (rewrite the Shared/Liked/For you stream/Trending describes; their selectors move from `.nav .name` to cover `.label` on heads):
  - `it('orders the first group All items, Favorites, Kept and the second Saved searches, For you, For you stream, Followed stories, Trending')` — with AI ready, stream available, trending on, one saved search, one followed story: the two `.views-group` cards' row labels (`.folder-head .label, :scope > * > .nav .name` excluding sublists) equal `['All items','Favorites','Kept']` and `['Saved searches','For you','For you stream','Followed stories','Trending']`.
  - `it('keeps the All items folder closed by default and remembers it')` — no `.all-head + .sublist` / no Recently read link; click `.all-head .chevzone` → children `['Recently read','Liked','Shared']` (sharedCount 2) and `localStorage['sfr.sidebar.all-items.collapsed'] === 'false'`; remount → still open.
  - `it('lists Shared last and only once something is shared')` — sharedCount 0 → children `['Recently read','Liked']`.
  - `it('opens the folder on a current child without storing it, and the chevron still closes it')` — selection `{kind:'liked'}`, nothing stored: Liked row visible and `.active`; storage key absent; chevron click hides the sublist.
  - `it('marks the All items head current on the all selection')` — `.all-head.current`; link `href` is the all-items link (no `view=`).
  - `it('draws no second group when none of its views shows')` — AI not ready, trending off, stream unavailable, no saved searches, no followed stories: the second `.views-group` has no `.nav`/`.folder-head` (Task 3 hides it by CSS; here assert the content is empty).
  - Keep the existing per-item assertions (Liked count/thumb/href, stream hidden/visible/active, Trending count/href/off) on their new markup.

- [ ] **Step 2: Run, expect FAIL** — `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar`

- [ ] **Step 3: Implement** the markup, inputs and folder state above; add the i18n key in all four files.

- [ ] **Step 4: Run the sidebar jest folder, expect PASS**; `tsc` app + spec in the container.

- [ ] **Step 5: Commit** — `feat(#2593): All items becomes a folder; the views regroup into two cards`

---

### Task 3: One row look, one counter column (styles + e2e)

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/_sidebar-row.scss` (new `@mixin folder-head`)
- Modify: `sidebar-views.component.scss`, `sidebar-collection.component.scss`, `sidebar-saved-searches.component.scss`, `sidebar-followed-stories.component.scss`, `sidebar.component.scss` (`.views-group`, delete `.collections` rules)
- Create: `frontend/e2e/sidebar-views-groups.spec.ts`
- Modify: e2e specs that measured the old heads: `frontend/e2e/sidebar-lead-space.spec.ts` (`'the saved searches head'` moves from `HEADS` to the plain-row checks, lead `.savedsearch-head > .section-link > app-icon`), and any of `saved-search-layout.spec.ts`, `header-scroll-mobile.spec.ts`, `saved-searches-combined.spec.ts`, `sidebar-airy-style.spec.ts` that fail on the new structure.

**Interfaces:**
- Consumes: Task 2's markup (`.views-group`, `.folder-head` with `.section-link`, `app-icon`, `.label`, `.section-count`, `.slot`, `.chevzone`/`.trail`, `.sublist`).
- Produces: `@mixin folder-head` in `_sidebar-row.scss` — `.folder-head` takes `row-geometry` (same height, inset, gap, font size and `--text-primary` as `.nav`), `row-states('.folder-head', '.folder-head.current')`, `row-link('.section-link')`; `.label` styled as `.name` (weight 400, no secondary colour, ellipsis); `.section-count` styled as `.count`; the lead `app-icon` muted unless current; **no** expanded band (none of `section-head`'s `:has([aria-expanded='true'])` rules apply). Each of the five head-owning components includes it instead of `section-head`/`section-head-glyph`.

- [ ] **Step 1: Write the failing e2e** `sidebar-views-groups.spec.ts`, stubbing every read in the style of `sidebar-lead-space.spec.ts` (`stubOneFeedReader`, `subscriptionsJson`, `stubAccountTheme(page, theme, style)`; trending on, AI ready via its stub, stream available, sharedCount 2, likedCount 3, one saved search, one followed story, favorites with one label so its chevron shows), All items folder and Favorites expanded via their chevrons. For each style in `['boxed', 'airy']`, on the desktop and the mobile project:
  - `test('every counter in both groups ends at the same x')` — the `right` of every visible `.views-group .count, .views-group .section-count` is equal within 0.5px.
  - `test('no view row is bold')` — computed `font-weight` of every `.views-group .label` and non-active `.views-group .name` is `400`.
  - `test('the groups are two cards with nothing between Kept and All items')` — exactly two `.views-group`; in boxed, Favorites/Kept heads are inside the first group's box (their rects within its rect) with no border between rows; in airy, the second group carries the section rule (`border-top-color` not transparent) and the first group's rows carry none.
  - `test('an expanded head keeps the row inset')` — the expanded Favorites `.folder-head` left equals the Kept head's left and its background is transparent when not hovered/current.
  - `test('an empty second group draws nothing')` — with AI, trending, stream, saved searches and followed stories all off: the second `.views-group` has zero height or `display: none`.

- [ ] **Step 2: Run, expect FAIL** — `cd frontend && npx playwright test e2e/sidebar-views-groups.spec.ts --trace off` (Docker stack up).

- [ ] **Step 3: Implement** the mixin and swap the components onto it; `.views-group` in `sidebar.component.scss` uses the existing `.card` look (boxed) / airy section rule; hide it with `.views-group:not(:has(.nav, .folder-head)) { display: none; }`; move the airy "no rule under the search band" rule from `.card.primary` (views scss) to the first `.views-group`; fix the coarse-pointer offsets (`.nav .trail` margin, `.slot` hidden) so the folder heads' counts land in the same column as the nav rows'.

- [ ] **Step 4: Run** the new spec plus the touched e2e specs and the sidebar Jest folder; expect PASS.

- [ ] **Step 5: Commit** — `feat(#2593): the view rows share one regular-weight look and one counter column`

---

After Task 3 (controller, not a task): screenshots of the real page — desktop and phone, boxed and airy, a list row and magazine cards (including a grouped compact entry) with a liked entry and a count — sent to Lars for sign-off before the final review, `/simplify`, the pre-PR gate run and the PR.
