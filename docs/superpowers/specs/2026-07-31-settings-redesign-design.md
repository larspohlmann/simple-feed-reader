# Settings area redesign — design

**Issue:** [#180](https://github.com/larspohlmann/simple-feed-reader/issues/180)
**Date:** 2026-07-31
**Scope decision:** Phase 1 (navigation & layout) plus a full rework of the admin
pages, which move into the settings area. Phases 2 (server-persisted
preferences), 3 (search) and 4 (further visual polish) are follow-up tickets.
Frontend-only: no backend changes.

## Goal

Replace the flat, five-section `/settings` scroll with a navigable shell that
scales as sections are added, works first-class on desktop and mobile, and
absorbs the admin pages as a prominent, guarded nav group. Chosen layout:
**nav rail on desktop, hub-and-spoke on mobile** (the GitHub-settings /
iOS-Settings pattern), selected over horizontal tabs and a scroll-spy index
during the design round.

## 1. Routing and URLs

`/settings` becomes a shell route whose children live in a new
`settings.routes.ts`, loaded from `app.routes.ts` via `loadChildren`. Every
section is a lazy-loaded child component.

| Route | Content | Guard |
|---|---|---|
| `/settings` | Hub (mobile) / redirect to `/settings/tags` (desktop) | auth |
| `/settings/tags` | Tags section (content unchanged) | auth |
| `/settings/import` | OPML import/export (path renamed from the OPML section; content unchanged) | auth |
| `/settings/preferences` | Language preference (content unchanged) | auth |
| `/settings/account` | Account — the buried admin text links are removed | auth |
| `/settings/about` | About (content unchanged) | auth |
| `/settings/admin/users` | Reworked admin users | auth + admin |
| `/settings/admin/catalog` | Reworked admin catalog | auth + admin |

- The old `/admin/users` and `/admin/catalog` URLs become redirect routes to
  their new locations, so bookmarks and the e2e suite's entry points keep
  working.
- `reader-shell.component.html`'s empty-catalog warning link is updated to
  `/settings/admin/catalog` (the redirect would also cover it, but the direct
  link is honest).
- The "Browse catalog" link is **dropped from the settings top bar**. Discover
  is already reachable where the intent arises: the reader's empty entry list
  and the add-feed dialog.

### Bare `/settings` on desktop

The hub component (empty child path) redirects to `/settings/tags` when the
viewport is at or above the desktop breakpoint, using CDK `BreakpointObserver`
and `router.navigate(..., { replaceUrl: true })` so the redirect does not trap
back-navigation. The observer keeps watching: if the viewport crosses the
breakpoint while the hub is open (e.g. window resize), the redirect fires then
too. On mobile the hub simply renders.

## 2. Extensibility: the section config

A new `settings-sections.ts` declares each section once:

```ts
interface SettingsSection {
  path: string;            // child route path, e.g. 'tags' or 'admin/users'
  icon: string;            // Material Symbol name for <app-icon>
  labelKey: string;        // transloco key
  group: 'general' | 'admin';
  wide?: boolean;          // opts out of the default content max-width
}
```

Both nav renderings (rail and hub) iterate this array; the `admin` group is
rendered only for admins (same signal the `adminGuard` uses). Route
declarations stay in `settings.routes.ts` (lazy imports must remain statically
analyzable), so **adding a section = one config entry + one route entry + one
component**. The shell, rail and hub are untouched — this is the acceptance
criterion "new sections can be added without touching the layout shell".

`wide` exists for the admin catalog: the shell's content column defaults to the
current 820px measure; a `wide` section gets a larger max-width (~1200px)
because the catalog rows carry more per-line content. The shell reads the flag
from the config; no per-section CSS in the shell.

## 3. Shell layout

**Breakpoint:** `bp.$bp-lg` (900px), matching the reader's desktop switch.

**Desktop (≥ bp-lg):** the top bar stays as today (back to reader, "Settings"
title, minus the discover link). Below it, a two-column layout: a ~220px nav
rail and the content column. The rail uses compact-density rows (icon + label),
`routerLinkActive` for the active state, a small "Admin" group heading before
the admin entries, and follows the design-language sticky convention —
`position: sticky` on the host with `align-self: flex-start`, internal
scrolling and `overscroll-behavior: contain`.

**Mobile (< bp-lg):** the rail is hidden. Bare `/settings` renders the hub: a
full-page list of comfortable-density rows (icon, label, chevron), grouped
General / Admin like the rail. Tapping a row navigates to the section route,
which renders full-page.

**Back behavior:** on desktop the top-bar back always returns to the reader.
On mobile, a section page's back returns to the hub (`/settings`); the hub's
back returns to the reader.

**Headings:** the shell keeps the `h1` ("Settings"); each section keeps its
`h2`, which doubles as the page title on mobile.

Section components (tags, import, preferences, account, about) move to their
own routes with their content unchanged in this ticket; Phase 4 polish is out
of scope.

## 4. Admin users rework

Moves into the shell at `/settings/admin/users` and drops its own page chrome
(back-bar header). Content rework:

- Filter chips restyled to the design language (token-derived, no ad-hoc
  styles).
- User rows become comfortable-density rows: email, status badge, provider
  list; actions become `<app-button size="sm">`.
- **Reject and suspend become two-step destructive actions:** a
  `danger-outline` button opens the existing confirm dialog; the dialog's
  filled `danger` button performs the action. Today both fire immediately —
  for account suspension that is a footgun, and the two-step scale is exactly
  what the design language's destructive-weight rule prescribes. Approve stays
  one-step (`default` variant).
- Rows wrap on mobile (email/badges above, actions below).

## 5. Admin catalog rework

Moves into the shell at `/settings/admin/catalog` as a `wide` section. The
always-editable grid disappears entirely.

**Read-only rows.** A category row shows swatch + icon, name, lock indicator,
active state and feed count. A feed row (indented under its category) shows
title, feed URL as secondary text, and lock/inactive indicators. Row actions:
move up / move down, edit, delete — plus refresh-favicon on feed rows. Delete
is `danger-outline` and opens the shared confirm dialog — a behavior change:
today `deleteCategory`/`deleteFeed` fire immediately with no confirmation.

**Edit dialogs** (the tag-form pattern: `<app-overlay-panel>`, opened with
`panelClass: 'app-dialog'`, fullscreen on phones):

- *Category dialog:* name, inline `<app-icon-picker>`, `<app-color-field>`
  (not clearable — categories always have a colour), active checkbox, locked
  checkbox.
- *Feed dialog:* title, feed URL, site URL, description, category select,
  active checkbox, locked checkbox.
- *Add category / add feed* open the same dialogs with empty state; the ad-hoc
  inline "add" rows disappear.

**State handling.** Dialogs edit a copy of the entity and commit through the
API on save, then update the list — replacing today's direct `ngModel`
mutation of shared objects with a per-row save button. Cancel discards.

**Reordering** keeps the up/down buttons. No drag-and-drop: categories with
nested feeds are exactly the nested-`cdkDropList` trap, and reorder-by-drag is
Phase 4 material at most.

**Import tools.** Bundled import, OPML upload + import mode, and icon warming
group into one "Import and maintenance" card at the top of the page, keeping
their current `<app-field>`/`<app-button>` structure. Import result counts and
warming progress render inside that card.

## 6. Deletions and risks

Deleted: the always-on grid styles and per-row save logic in `admin-catalog`,
the inline add-rows, the settings top bar's discover link, and the duplicated
page chrome in both admin components.

Risks to manage during implementation:

- **Test ids.** The catalog rework moves `data-testid` hooks
  (`admin-category`, `admin-feed`, `feed-save`, `import-*`, …) into new
  markup/dialogs. The Playwright smokes and any backend e2e helpers that seed
  data through the admin UI must be audited and updated in the same PR.
- **Viewport-dependent redirect.** The bare-`/settings` desktop redirect is
  the one piece of viewport-conditional routing; it gets dedicated unit tests
  around the `BreakpointObserver` behavior (both initial state and crossing
  the breakpoint while open).
- **Old-URL redirects** are asserted in tests so `/admin/*` bookmarks keep
  working.

## 7. i18n and testing

- New en + de keys: nav labels and group headings, hub strings, catalog dialog
  labels/headings, confirm-dialog prompts for reject/suspend/delete.
- Unit tests: shell (nav renders from config; admin group hidden for
  non-admins), hub (renders on mobile, redirects on desktop), both catalog
  dialogs, users confirm flow. Existing section specs move with their
  components.
- E2e smoke: navigate through each settings section, follow an old `/admin/*`
  URL to its redirect target, edit a catalog feed through the dialog.
- Gates: `npm run check` clean; no PHP touched, so backend gates are
  unaffected.

## Out of scope (follow-up tickets)

- Phase 2: `UserSettings` entity, `GET/PATCH /api/me/settings`,
  server-persisted language, first new preference (theme).
- Phase 3: settings search.
- Phase 4: visual polish of section internals, drag-to-reorder, inline tag
  editing.
