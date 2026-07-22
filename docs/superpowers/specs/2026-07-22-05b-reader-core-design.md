# Simple Feed Reader 5b — Core Reader (Design)

**Date:** 2026-07-22
**Status:** Approved design, pre-plan
**Branch:** `feature/05b-reader-core` (off `develop`)

The reading experience of the Angular SPA: the responsive shell, the sidebar
navigation tree, the entry list with infinite scroll and preview images, the
article reader, the read/favorite/keep actions, mark-all-read, subscribe-by-URL,
and the live refresh progress loop. Builds on the 5a workspace (theming, auth,
core services) and consumes the **frozen** 4a/4b reader API — 5b writes **no
backend code**.

## Goal

Turn the placeholder shell 5a shipped into a working feed reader: sign in, see
your subscriptions and unread counts, read entries, mark them read/favorite/kept,
add a feed by URL, and refresh with live progress — desktop and mobile.

## Scope

**In (5b):**

- Responsive reader shell (header, sidebar, main) replacing the 5a placeholder.
- Sidebar navigation tree: **All items / Favorites / Kept** views, a **Tags**
  section (each tag expands to its subscriptions), and an **Untagged** section —
  all read-only, with unread counts.
- Entry list: unread-only default with an **Unread / All** toggle, cursor-based
  **infinite scroll** (+ a Load-more fallback), two-line snippet, content-derived
  **preview image**, always-visible per-row actions.
- Article **reader** (shared component): title, byline/date, open-original link,
  the sanitized content, read/favorite/keep, prev/next.
- **Two reading-layout modes**, a user preference (on-device): **List** (default)
  and **Pane**. See "Reading-layout modes".
- Entry state: mark read **on open**, manual read/unread toggle, favorite, keep.
- **Mark all read** for the current selection (All / a tag / a subscription).
- **Subscribe by URL** dialog with feed-discovery candidate picker.
- **Refresh** button running the `POST /api/refresh` progress loop.
- Loading (skeletons), empty, and problem+json error states throughout.

**Out (deferred to 5c):**

- Tag create/edit/delete (name, color, icon) — sidebar tags are read-only here.
- Subscription rename / retag / delete; per-feed "retry dead feed" refresh
  (needs a feed id the subscription JSON does not expose).
- OPML import/export UI.
- Admin user-queue lazy module; feed-health and log viewers.
- A full settings page (5b surfaces only the reading-layout control, beside the
  existing theme toggle).

**Out (v1 entirely):** keyboard shortcuts, full-text search, full-article
scraping (per the top-level design).

## The frozen API 5b consumes (verified against source 2026-07-22)

All JWT-authenticated; the 5a bearer interceptor attaches the token and maps 401
to logout. Errors are RFC 7807 `application/problem+json`.

**`GET /api/subscriptions`** →
```
{ "subscriptions": [ {
  "id": int, "title": string, "customTitle": string|null,
  "feedUrl": string, "siteUrl": string|null, "status": "active"|"erroring"|"gone",
  "createdAt": string,
  "tags": [ { "id": int, "name": string, "color": string|null, "icon": string|null } ],
  "unreadCount": int
} ] }
```
The sidebar tree is built entirely from this response. `GET /api/tags` exists but
is not needed in 5b (empty tags matter only in 5c CRUD).

**`POST /api/subscriptions`** `{ "url": string }` →
- **201** `{ "subscription": {…SubscriptionJson} }` — subscribed directly.
- **200** `{ "candidates": [ { "url": string, "title": string } ] }` — the URL was
  an HTML page; user picks a candidate, which is re-POSTed as `url`.
- **422** `validation_error` (`errors.url`) — blank/invalid/too-long (≤750,
  http/https, TLD required).

**`GET /api/entries?view=&subscription=&tag=&cursor=&limit=`** →
```
{ "entries": [ {
  "id": int, "title": string, "url": string|null, "author": string|null,
  "summary": string|null, "contentHtml": string|null,
  "publishedAt": string|null, "createdAt": string,
  "subscriptionId": int, "source": string,
  "isRead": bool, "isFavorite": bool, "isKept": bool
} ], "nextCursor": string|null }
```
- `view` ∈ `all|unread|favorites|kept` (default `all`; bad value → `validation_error`).
- `subscription` and `tag` are **ids** (subscription id / tag id).
- `cursor` is the opaque `nextCursor` from a prior page; `nextCursor` is non-null
  only when a full page was returned (so it doubles as "there may be more").
- `contentHtml` and `summary` are present in the **list** response — this is what
  makes client-side preview-image extraction possible without a backend change.

**`PATCH /api/entries/{id}/state`** `{ "isRead"?, "isFavorite"?, "isKept"? }`
(null = leave unchanged) → `{ "state": { entryId, isRead, isFavorite, isKept, readAt } }`.
404 for a non-subscribed/foreign entry.

**`POST /api/entries/mark-read`** `{ "scope": "all"|"feed"|"tag", "until": ISO8601, "id"?: int }`
→ **204**. `until` is the client's list-load timestamp (entries arriving during
reading stay unread). **`scope=feed` ⇒ `id` is a *subscription* id**;
`scope=tag` ⇒ `id` is a tag id; `scope=all` ⇒ no id. Missing id → `validation_error`.

**`POST /api/refresh`** (no `feedId` in 5b) →
```
{ "status": "busy"|"partial"|"completed"|"aborted",
  "total": int, "fetched": int, "notModified": int, "failed": int,
  "skippedForBudget": int, "remaining": int, "pruned": int }
```
Rate-limited (429 problem when exceeded). Client loop: `partial` (remaining > 0)
→ call again; `busy` → wait and retry; `completed`/`aborted` → stop. Refetch the
current list and counts when the loop ends.

## Architecture

Standalone components + signals throughout, consistent with 5a. No NgRx. Bespoke
SCSS over Angular CDK; CSS-custom-property tokens; Material Symbols already
self-hosted. New reader code lives under `frontend/src/app/reader/`.

### Routing

The reader is the authenticated home. Selection and the open entry live in the
URL so back/forward, refresh, and deep links work:

```
/reader                              → shell; default selection = All items, unread
  ?view=favorites|kept               → Favorites / Kept views
  ?tag=<id>                          → a tag's entries
  ?subscription=<id>                 → one subscription's entries
  ?unread=0                          → show All (default is unread-only)
  ?entry=<id>                        → the open entry
```

`view`/`tag`/`subscription` are mutually exclusive selections (a helper reduces
them to one `EntryQuery`). `?entry=<id>` is orthogonal — in **List** mode it drives
the reader route/panel that replaces the list on mobile; in **Pane** mode it fills
the right pane on wide screens. A guarded redirect sends unknown/blank paths to
`/reader`; the 5a auth guard already protects it.

### State (signal stores)

- **`SubscriptionsStore`** — loads `GET /subscriptions`; exposes signals for the
  subscription list, the derived tag tree, and computed unread counts:
  - per-subscription `unreadCount` (from the API),
  - per-tag count = **sum** of `unreadCount` over subscriptions carrying that tag
    (overlap across tags is correct and intended),
  - "All items" total = sum over all subscriptions, each counted once.
  Favorites/Kept are curated views and carry **no** unread badge.
  Applies optimistic decrements when entries are marked read, then reconciles on
  the next load/refresh.
- **`EntriesStore`** — holds the current `EntryQuery` (selection + unread toggle),
  the loaded rows, the `nextCursor`, and loading/error signals. Appends pages for
  infinite scroll; resets when the query changes. Mutating an entry's state
  updates the row in place (optimistic) and PATCHes; on error it rolls back and
  surfaces a problem+json banner.
- **`RefreshService`** — runs the progress loop and exposes a progress signal
  (running / tally / done) the header binds to.
- **`ReadingLayoutService`** — the List/Pane preference, persisted in
  `localStorage` under an `sfr.*` key, mirroring 5a's `ThemeService`. Default
  **List**.

All HTTP goes through a thin `ReaderApi` wrapper over the 5a `HttpClient` setup
(base URL + bearer interceptor), returning typed models and mapping errors via
5a's `parseProblem`.

### Component tree

```
ReaderShellComponent            (header + sidebar + <router-outlet>/panes)
 ├ HeaderComponent              (title, refresh, add-feed, layout toggle, theme, account)
 │   └ RefreshProgressComponent
 ├ SidebarComponent             (views + tag tree + untagged; unread counts)
 ├ EntryListComponent           (list header, Unread/All toggle, Mark-all-read,
 │   └ EntryRowComponent          rows, infinite-scroll sentinel, skeleton/empty)
 └ ReaderViewComponent          (shared article renderer; route in List, pane in Pane)
Dialogs: AddFeedDialogComponent (URL input → candidate picker)
```

`ReaderViewComponent` is the single article renderer used by both layout modes.

## Reading-layout modes

The two modes differ **only in where the reader is placed on wide screens**;
mobile is one code path for both.

- **List (default).** One content column. The entry list is the main view; opening
  an entry navigates to the reader (`?entry=<id>`), which replaces the list with a
  back affordance. Same on desktop and mobile. Simplest; reader gets full width.
- **Pane.** On wide screens (≥ a breakpoint, ~900px) the reader is a right-hand
  pane beside the list; selecting a row fills it without leaving the list. Below
  the breakpoint, Pane behaves exactly like List (push a full-screen reader). A
  CDK/`matchMedia` signal drives the wide-vs-narrow switch.

The preference is a single control (segmented List/Pane) placed beside the theme
toggle in the account/preferences menu 5a shipped. Switching modes is instant and
does not lose the current selection or scroll position where avoidable.

## Entry list & rows

- **Default filter** unread-only (`view=unread` unless a curated view is selected);
  an **Unread / All** segmented toggle flips `?unread`. Favorites/Kept views ignore
  the toggle (they are their own `view`).
- **Infinite scroll** via an `IntersectionObserver` sentinel at the list foot:
  when it enters view and `nextCursor` is non-null and no load is in flight, fetch
  the next page and append. A visible **Load more** button is always rendered as
  the sentinel's content, so it works without the observer (accessibility, tests,
  reduced-motion). A short page (`nextCursor === null`) ends the list.
- **Row** = unread dot (filled unread / hollow read), title (bold when unread),
  `source · tag · relative time` meta, a two-line clamped snippet (from `summary`,
  falling back to a text extract of `contentHtml`), an optional preview thumbnail,
  and an always-visible action strip: **star** (favorite), **bookmark** (keep),
  **mark read/unread**. Clicking the row body opens the reader.

### Preview images (client-side, no backend change)

Derived from the `contentHtml` (fallback `summary`) the list already returns:

1. Take the **first `<img>`** whose `src` is an **absolute `https://` URL**
   (parsed inertly; never executed). `http://` images are skipped — the app is
   HTTPS and they would be mixed-content-blocked. No image → **text-only row**
   (no placeholder, no broken-image icon).
2. Render `<img loading="lazy" decoding="async" referrerpolicy="no-referrer">`
   with fixed dimensions and `object-fit: cover` — lazy + no-referrer so scanning
   the list neither janks nor leaks the reader's IP/referer to every feed's image
   host. `onerror` collapses the thumbnail to the text-only layout.
3. Extraction is memoized per entry (computed off the row model), so a page of
   entries is parsed once, not on every render.

## Article reader

`ReaderViewComponent` renders one entry: title, `source · author · date`,
an **open original** link (`url`, `target=_blank rel="noopener noreferrer"`), the
sanitized body via Angular `[innerHTML]` (**no** `bypassSecurityTrust*` — Angular's
sanitizer re-checks the already-server-sanitized HTML as defense in depth), the
read/favorite/keep action bar, and **prev/next** across the current list order.
Anchors inside the rendered body get `target=_blank rel="noopener noreferrer"`
(a small directive over the content host). Opening an entry marks it read (a
`PATCH …/state {isRead:true}`) unless already read; the manual toggle can set it
back to unread.

## Add feed

Header `+` opens `AddFeedDialogComponent` (CDK dialog): a URL field →
`POST /subscriptions`.

- **201** → close, refresh `SubscriptionsStore`, select the new subscription. The
  new feed lands **untagged** (tag assignment is 5c).
- **200 candidates** → show the returned `{url,title}` list; picking one re-POSTs
  that `url`. Zero candidates → an inline "no feeds found here" message.
- **422** → field error on the URL input from `errors.url`.

## Refresh with live progress

Header refresh button → `RefreshService.run()`: `POST /api/refresh` in a loop.
While `status==='partial'`, show determinate progress derived from the tally
(processed = `fetched+notModified+failed` over `total`) and call again; on `busy`,
brief backoff then retry (bounded); on `completed`/`aborted`, stop and refetch the
current list + `SubscriptionsStore`. A 429 problem shows a "try again shortly"
note and stops. The button is disabled while a run is in flight.

## Loading, empty, and error states

- **Loading** — skeleton rows in the list; a subtle sidebar loading state on first
  load; the reader shows a spinner while (if ever) fetching.
- **Empty** — unread list cleared → "You're all caught up"; a subscription/tag with
  no entries → "Nothing here yet"; no subscriptions at all → an onboarding empty
  state pointing at **Add feed**.
- **Error** — problem+json mapped via 5a's `parseProblem` to an inline, dismissible
  banner with a retry; 401 is handled by the existing interceptor (clear + `/login`).

## Theming & accessibility

- Reuses 5a tokens; **no hex outside `theme/`** (the Stylelint gate). New component
  styles are token-only.
- Semantics: the sidebar is a labeled nav; the list is a list; unread state is not
  color-only (dot + weight + `aria`); the action strip has `aria-label`s and is
  keyboard-focusable/operable (this is basic control accessibility, distinct from
  the reader-wide keyboard *shortcuts* that stay out of v1). Dialogs use CDK focus
  trapping. `prefers-reduced-motion` disables scroll/skeleton animation and the
  Load-more fallback still functions.

## Testing

- **Jest (unit/component):** `SubscriptionsStore` count derivation (per-tag sums,
  overlap, All-items total, optimistic decrement); `EntriesStore` pagination
  (append, reset-on-query-change, `nextCursor===null` terminates) and optimistic
  state with rollback-on-error; preview-image extraction (first https img, http
  skipped, none → text-only, malformed HTML safe); the refresh loop
  (partial→partial→completed, busy backoff, 429 stop); reader mark-on-open;
  add-feed dialog (201 vs candidates vs 422); the List/Pane preference service.
- **Playwright (integration smoke, against Docker):** sign in → see subscriptions →
  open an entry (marks read) → favorite/keep → mark-all-read → add a feed by URL.
  Extends the 5a smoke; not part of `npm run check`/CI unit gate.
- The gate stays `npm run check` (ESLint + Prettier + Stylelint + Jest) plus the
  production build in CI.

## Risks & notes

- **List payload weight.** `GET /entries` returns full `contentHtml` per row, so a
  page is heavier than a title-only list. That is the existing 4b contract; we
  exploit it for previews and reader prev/next (no per-entry content fetch). If it
  ever bites, a lighter list projection is a **backend** change for a later plan,
  not 5b.
- **Remote preview/reader images** load third-party hosts. `no-referrer` + `lazy`
  bound the exposure; we do not proxy images (that would be backend work).
- **`scope=feed` id is a subscription id** — easy to get wrong; the store sends the
  selected subscription id, never a feed id. Covered by a test.
- **Native readiness** is unaffected: 5b is web-only presentation over the same
  stateless bearer API; no new browser-only coupling in the contract.
