# Plan 5c — Management + Admin — Design

**Status:** approved (autonomous brainstorm, 2026-07-22)
**Depends on:** 5a (workspace/theming/auth), 5b (core reader). Backend 4a/4b/3/3b (frozen).
**Sub-project of:** Plan 5 (Angular frontend), decomposed 5a → 5b → 5c.

## Goal

Give the reader everything the frozen backend already exposes but 5b left out: **feed
management** (rename, retag, unsubscribe), **tag CRUD** (name, colour, icon), **OPML
import/export**, an **account** view, and a lazy, admin-only **user approval queue**. All
of it is a new Angular surface over the existing bearer-JWT JSON API — **no backend
change** (see "The one deferral" below).

## Scope boundary — the frozen contract

The frontend writes **no** backend code. Every 5c action maps to an endpoint verified
present in `backend/src/Controller` on 2026-07-22:

| Action | Endpoint | Request | Response |
| --- | --- | --- | --- |
| List feeds | `GET /api/subscriptions` | — | `{subscriptions:[SubscriptionDto]}` (already used by 5b) |
| Rename / retag feed | `PATCH /api/subscriptions/{id}` | `{customTitle:string\|null, tagIds:int[]}` — **replaces the whole tag set** | `{subscription:SubscriptionDto}` |
| Unsubscribe | `DELETE /api/subscriptions/{id}` | — | `204` |
| List tags | `GET /api/tags` | — | `{tags:[TagDto]}` |
| Create tag | `POST /api/tags` | `{name, color?, icon?}` | `{tag:TagDto}` `201` |
| Edit tag | `PATCH /api/tags/{id}` | `{name, color?, icon?}` | `{tag:TagDto}` (`409 tag_name_taken`) |
| Delete tag | `DELETE /api/tags/{id}` | — | `204` (backend detaches from every feed) |
| Export OPML | `GET /api/opml/export` | — | `200` body = OPML XML, `Content-Disposition: attachment` |
| Import OPML | `POST /api/opml/import` | raw XML body, ≤ 1 MB | `{imported, alreadySubscribed, invalid, skippedOverLimit}` |
| List users (admin) | `GET /api/admin/users?status=` | `status` = a `UserStatus` value or absent | `{users:[AdminUserDto]}` |
| Approve / reject / suspend | `POST /api/admin/users/{id}/{action}` | — | `{status}` (`422` if reject/suspend targets self) |

**Backend validation the UI must mirror (so errors are rare, not the primary guard):**
- Tag `name`: non-blank, 1–100 chars.
- Tag `color`: optional, must match `/^#[0-9a-fA-F]{6}$/` (e.g. `#3f8676`).
- Tag `icon`: optional, must match `/^[a-z0-9_]+$/` (a Material Symbol name).
- `customTitle`: optional, ≤ 512 chars; empty string is stored as `null` (clears the override).

**Admin authorization** is enforced server-side by `access_control: ^/api/admin/ ROLE_ADMIN`
in `security.yaml`. The Angular `adminGuard` is **UX only** — it keeps non-admins from
seeing a page that would 403 anyway; it is not a security boundary.

### The one deferral — per-feed "retry dead feed"

The roadmap listed a per-feed "retry this dead feed" refresh. The backend supports it
(`POST /api/refresh?feedId=`, IDOR-guarded), **but keys on the Feed id, which
`SubscriptionJson` does not expose** (it returns `feedUrl` and `status`, no `feedId`).
Reaching it would require a one-line backend addition, which is out of scope for a
frontend-only plan.

**Decision:** 5c **surfaces feed health** — the `status` field (`active` / `erroring` /
`gone`) is shown as a badge in the Feeds section — and relies on the existing global
refresh. The targeted per-feed retry is **deferred** to a future small plan that pairs a
`feedId` field on `SubscriptionJson` with a per-row "Retry" button. This keeps 5c purely
frontend and the backend contract frozen.

## Architecture

Three new areas, all lazy-loaded, plus a shared set of management dialogs. Nothing in the
existing reader shell/stores is rewritten; the shell gains only entry-point links and (in
the sidebar) hover affordances that reuse the shared dialogs.

```
src/app/
  core/
    admin.guard.ts            NEW  – ensures user loaded, then requires ROLE_ADMIN
  reader/
    reader-api.ts             EDIT – add mutation methods (subs PATCH/DELETE, tags CRUD, OPML)
    models.ts                 EDIT – add OpmlImportResult, CreateTag/UpdateTag payloads
    tags.store.ts             NEW  – GET /api/tags (all tags incl. empty ones) + CRUD refresh
    manage/
      confirm-dialog.component.ts        NEW – generic yes/no dialog (returns boolean)
      edit-subscription-dialog.component.ts NEW – rename + retag
      tag-form-dialog.component.ts       NEW – create OR edit a tag (name/colour/icon)
      icon-choices.ts                    NEW – curated Material Symbol names + colour swatches
      manage-actions.service.ts          NEW – opens the dialogs, refreshes stores on success
    sidebar/sidebar.component.ts EDIT – per-row "⋯" hover menu → outputs
    header/reader-header.component.ts EDIT – account menu gains Settings / Admin links
  settings/
    settings.component.ts     NEW  – lazy page; hosts the sections below + back-to-reader
    feeds-section.component.ts NEW  – table of all feeds: status badge, tags, row actions
    tags-section.component.ts  NEW  – list of all tags: create, edit, delete
    opml-section.component.ts  NEW  – export (download) + import (file/paste) with result
    account-section.component.ts NEW – email, member-since, sign out, link to Admin if admin
  admin/
    admin-api.ts              NEW  – GET list, POST approve/reject/suspend
    admin.models.ts           NEW  – AdminUserDto, AdminUserStatus
    admin-users.component.ts  NEW  – lazy page; status filter + user rows + actions
```

**Routing** (`app.routes.ts`): two new lazy top-level routes, so admin is a genuinely
separate chunk:

```ts
{ path: 'settings',    canActivate: [authGuard],              loadComponent: SettingsComponent }
{ path: 'admin/users', canActivate: [authGuard, adminGuard],  loadComponent: AdminUsersComponent }
```

### Data flow

- **SubscriptionsStore** (existing) stays the single source of truth for the feed list and
  its unread counts — the Feeds section renders `subs.subscriptions()`, and every mutation
  calls `subs.load()` to re-sync (counts, tags, titles). No optimistic bookkeeping is added
  for management actions; a reload is cheap and keeps the sidebar and settings identical.
- **TagsStore** (new) owns the *complete* tag list from `GET /api/tags` — including tags
  with zero feeds, which never appear in the sidebar's `buildTagTree` (that derives tags
  from subscriptions). The Tags section and the retag picker both read `tags.tags()`.
- **ManageActions** (new service) is the one place that opens a management dialog and, on a
  truthy close result, refreshes the affected store(s). Both the sidebar (via the shell) and
  the settings sections call it, so "edit tag" behaves identically wherever it is triggered.
- **AdminApi + local component state**: the admin page holds its own `users` signal and a
  `status` filter signal; an action re-fetches the current filter on success (small list,
  no store needed).

### Dialogs

All dialogs use `@angular/cdk/dialog` exactly as `AddFeedDialogComponent` does: a `.dialog`
surface with `cdkTrapFocus`, `cdkFocusInitial` on the first control, `DialogRef` injected,
`ref.close(result)`. They mirror its token-only styling (`--surface-2`, `--border`,
`--radius`, `--space-*`, `--accent`).

- **ConfirmDialogComponent** — data `{title, message, confirmLabel, danger?}`; closes
  `true` on confirm, `undefined`/`false` on cancel. Used for unsubscribe and delete-tag.
- **EditSubscriptionDialogComponent** — data `SubscriptionDto`. A text field prefilled with
  `customTitle` (placeholder = the resolved feed title), and a checkbox list of **all**
  tags (`TagsStore`) with the feed's current tags checked. Submit → `PATCH` with
  `{customTitle: value || null, tagIds: checkedIds}`. Closes the updated `SubscriptionDto`.
- **TagFormDialogComponent** — data `TagDto | null` (null = create). Fields: name; colour (a
  row of preset swatches + a native `<input type="color">` for custom, value is the `#rrggbb`
  hex; clearable to "no colour"); icon (a curated grid of Material Symbol names from
  `icon-choices.ts`, plus a "none" option). Submit → `POST` (create) or `PATCH` (edit).
  Surfaces `409 tag_name_taken` inline on the name field. Closes the saved `TagDto`.

### Colour & icon pickers

- **Colours**: `icon-choices.ts` exports `TAG_COLORS: string[]` — ~10 `#rrggbb` presets
  drawn from muted, theme-friendly hues (teal/blue/green/amber/rose/violet/slate…). These
  are **data values in a `.ts` file**, so the Stylelint `color-no-hex` guard (which globs
  only `*.scss`) does not apply; they are not stylesheet colours. A native
  `<input type="color">` covers anything off-palette. The stored value is always the hex the
  backend regex accepts.
- **Icons**: `TAG_ICONS: string[]` — ~24 curated outlined Material Symbol names
  (`label`, `rss_feed`, `newspaper`, `code`, `science`, `sports_esports`, `movie`, `music_note`,
  `sports_soccer`, `restaurant`, `flight`, `work`, `favorite`, `pets`, `shopping_cart`,
  `school`, `public`, `bolt`, `local_cafe`, `terminal`, `palette`, `camera`, `trending_up`,
  `star`). The full `material-symbols/outlined.css` font is already loaded, so every name
  renders; the curated list is only for a tidy picker. All match the backend `[a-z0-9_]+`
  rule.

## The settings page

`SettingsComponent` is a full-height page with its own slim header (a "← Back to reader"
link + "Settings" title, mirroring the reader header's surface treatment) and a single
scrolling column of sections. It injects `SubscriptionsStore` and `TagsStore` and calls
`.load()` for each in `ngOnInit` (idempotent — the stores may already be warm from the
reader).

1. **Feeds** (`FeedsSectionComponent`) — a list/table of `subs.subscriptions()` sorted by
   title. Each row: title (with the custom-title override shown if set), the site/feed host,
   a **status badge** (active = quiet, erroring = amber, gone = danger), its tag chips, and
   the unread count. Row actions (buttons, keyboard-reachable): **Rename & tags** (opens
   `EditSubscriptionDialog` via `ManageActions`) and **Unsubscribe** (confirm → `DELETE`).
   Empty state: "No feeds yet — add one from the reader." A `gone`/`erroring` badge carries a
   tooltip explaining the feed last failed and that a global refresh will retry it.
2. **Tags** (`TagsSectionComponent`) — `tags.tags()` sorted by name, each with its colour dot
   + icon + name + a count of feeds using it (derived from `subs`). Actions: **New tag**
   (top), **Edit**, **Delete** (confirm; the confirm message notes it will be removed from N
   feeds). Empty state hint.
3. **Import / Export** (`OpmlSectionComponent`):
   - **Export**: a button that fetches `GET /api/opml/export` **through HttpClient**
     (`responseType: 'text'`, so the bearer interceptor authenticates it — a plain
     `<a href>` cannot send the Authorization header), wraps the body in a `Blob`, and
     triggers a client-side download named `feeds.opml`.
   - **Import**: a file input (`accept=".opml,.xml,text/xml"`) **and** a paste textarea; on
     submit, POST the raw text. Show the result counts (`imported`, `alreadySubscribed`,
     `invalid`, `skippedOverLimit`) and a note that imported feeds fill in on the next
     refresh (the backend does not fetch inline). On success, `subs.load()`.
4. **Account** (`AccountSectionComponent`) — email, roles, "member since"
   (`auth.user().createdAt`), a **Sign out** button, and — only when `auth.isAdmin()` — a
   link to `/admin/users`.

The reader header's account menu gains **Settings** (always) and **Admin** (only if
`isAdmin()`) links, so the page is reachable from the reader without touching the URL.

## The admin page

`AdminUsersComponent` (lazy, behind `authGuard + adminGuard`):

- **Filter**: a segmented control — *All* plus one chip per `UserStatus`
  (`pending_verification`, `pending_approval`, `active`, `rejected`, `suspended`). Changing
  the filter refetches `GET /api/admin/users?status=`. Default *All* (no `status` param).
  Server orders by `createdAt ASC` (oldest first — the queue reads top-to-bottom).
- **Rows**: email, a status badge, sign-up providers (the `identities` array — an OAuth-only
  account with a `…@oauth.invalid` synthetic address is a normal case, not an anomaly), and
  created/approved dates.
- **Actions per row**, shown by status:
  - `pending_approval`, `pending_verification`, `rejected` → **Approve**.
  - `active`, `pending_approval`, `pending_verification` → **Suspend** (and **Reject** for
    the two pending states).
  - `suspended`, `rejected` → **Approve** (reinstate / grant).
  A simple rule the component encodes: **Approve** is offered whenever the user is not
  already `active`; **Reject** only for the two pending states; **Suspend** whenever the user
  is `active` (or pending). Each action POSTs and, on success, refetches the current filter.
- **Self-guard**: the current admin's own row (`user.id === auth.user()?.id`) hides Reject
  and Suspend — the backend returns `422` for self-targeting; the UI simply never offers it.
  A backend `422` (e.g. a race) is surfaced as an inline error, not a crash.
- **adminGuard** (`core/admin.guard.ts`): if `auth.user()` is null (deep link, no reader
  visited yet), it `loadMe()` first, then allows when `ROLE_ADMIN` is present, else redirects
  to `/`. Async `CanActivateFn` returning `Observable<boolean | UrlTree>`.

## Sidebar affordances (convenience, isolated task)

The sidebar stays presentational (inputs + local expanded state). It gains a hover/focus
`⋯` button on each **tag** row and each **feed** row that opens a tiny inline menu emitting
typed outputs:

- tag row → `editTag(TagDto)`, `deleteTag(TagDto)`
- feed row (tagged sub + untagged feed) → `editFeed(SubscriptionDto)`, `unsubscribe(SubscriptionDto)`

The **shell** wires each output to `ManageActions` in one line. This is the natural reader
UX and reuses the exact dialogs the settings page uses. It is the **last** implementation
task so it is fully isolated: if it risks regressions in the well-tested sidebar, it can be
dropped without affecting the settings/admin core.

## Error, loading, empty states

- Every list section shows a spinner while its store `loading()` is true, a problem banner
  (`parseProblem`) on error with a retry, and a friendly empty state otherwise — matching
  5b's conventions (`--danger`/`--bg-danger` banner, `AppSpinner`).
- Dialogs disable their submit button while a request is in flight and show inline field
  errors (`parseProblem(e).errors?.[field]?.[0] ?? detail ?? title`), exactly as add-feed does.
- Destructive actions (unsubscribe, delete tag, reject/suspend user) always route through a
  confirm dialog or are clearly labelled; nothing destructive is a single mis-click.

## Testing

- **Unit (Jest)**: a `.spec.ts` beside every new component/service/store. `TagsStore` and
  the new `ReaderApi`/`AdminApi` methods drive `HttpTestingController` (assert method, URL,
  body, and the store's resulting signal). Dialog specs mount with a stub `DialogRef` and
  `{provide: DIALOG_DATA}` and assert the emitted close payload and the request fired.
  Component specs assert the rendered rows, the action-visibility rules (admin buttons per
  status; self-guard), and the empty/error states. `adminGuard` spec covers: user present +
  admin → true; present + non-admin → UrlTree('/'); absent → loadMe() then decide.
- **Playwright smoke** (`e2e/settings-admin-smoke.spec.ts`): sign in as the seeded admin
  (reuse `reader-smoke`'s helper), open **Settings** from the account menu, assert the Feeds
  and Tags sections render and the Tag-form dialog opens/closes; then navigate to
  **/admin/users**, assert the queue and filter render. Skip-if-unreachable, same convention
  as the existing smokes. No live network / no destructive action.
- Gate: `npm run check` (ESLint + Prettier + Stylelint + Jest) stays green; `npm run build`
  green. Verified live against the Docker stack.

## Native-iOS readiness (standing rule)

All new calls are bearer-JWT JSON — native-ready. The **only** web-coupled pieces are the
OPML **file download** (Blob + object-URL anchor) and **file upload** (browser File API); a
native client would reimplement those with native file handling against the same endpoints.
No cookies, sessions, or CSRF are introduced. The admin surface is plain JSON. Flagged, not
blocking — consistent with `native-ios-readiness`.

## Out of scope (explicit)

- Per-feed targeted "retry dead feed" (deferred — see above; needs a backend `feedId`).
- Bulk feed operations (multi-select unsubscribe/retag), drag-and-drop tag assignment.
- Tag reordering / nesting; per-feed fetch interval overrides; admin user search/pagination
  (the queue list is small; `findForAdminList` returns all).
- Any new theme; new reading preferences beyond 5b's List/Pane + theme.
- Editing a feed's URL (backend `PATCH` accepts only `customTitle` + `tagIds`).
```
