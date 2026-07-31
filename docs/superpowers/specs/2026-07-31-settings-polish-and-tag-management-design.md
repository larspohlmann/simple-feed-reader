# Settings visual polish and tag management — design

**Issue:** [#180](https://github.com/larspohlmann/simple-feed-reader/issues/180) Phase 4 (visual polish & tag management)
**Related:** #182 (Phase 1, the settings shell), #193 (Phase 5, admin statistics)
**Date:** 2026-07-31
**Branch:** `feature/180-settings-polish-tags`
**Scope:** Mostly frontend. One small backend addition: a write path for the
user's own locale.

## Goal

Make the settings area consistent to look at and honest about what it is doing,
give the tags section the reordering and editing it never got, and let a
language change actually reach the server so account emails follow it.

Four workstreams. They are ordered: 1 before 2, and both before the surfaces
that adopt them. Workstream 4 is independent of 1–3.

## 1. A shared section card

### The problem

Seven stylesheets carry five different treatments for the same idea:

| Surface | Today |
|---|---|
| `settings/opml-section` | bordered card per block (`opml-section.component.scss:6-16`) |
| `settings/tags-section` | bordered card per **row** (`tags-section.component.scss:28-37`) |
| `settings/about-section`, `account-section`, `preferences-section` | bare, unwrapped `<section>` |
| `admin/admin-users` | rows separated by `border-bottom` only (`admin-users.component.scss:39-45`) |
| `admin/admin-user-detail` | `--radius-lg` cards (`admin-user-detail.component.scss:52-58`) |
| `admin/admin-catalog` | mixes `--radius` and `--radius-lg` panels (`admin-catalog.component.scss:31,87`) |

Nothing stops a sixth treatment appearing with the next section.

### The design

A `SettingsCardComponent` under `frontend/src/app/shared/settings-card/`,
following the conventions of the components already there. It renders one
titled surface: a heading, an optional description line, and projected content.
It uses `--radius-lg`, the surface and border tokens, and `--space-*` spacing —
no hex colours, no raw `px`, per the standing rule for anything outside
`src/app/theme/`.

All eight surfaces convert to it: About, Account, OPML, Preferences, Tags,
Admin users, Admin catalog, Admin user detail.

**A card wraps a section, not a row.** The tags list's current per-row card is
replaced by plain rows inside one card. This removes the nested-card look and
brings tags into line with the admin lists, which already treat rows as rows.

### Two bugs fixed in the same pass

- **`--fs-md` does not exist.** `theme/tokens.scss` defines `--fs-xs`, `--fs-sm`,
  `--fs-base`, `--fs-read`, `--fs-lg` and `--fs-xl` — there is no `md`. Three
  files reference it and silently get nothing:
  `admin/admin-user-detail.component.scss:37`, `reader/entry-split.component.scss:59`,
  `reader/entry-thumb.component.scss:52`. All three become `--fs-base`. This is
  pre-existing and unrelated to #193; it is fixed here because this is the pass
  that touches the type scale.
- **`app-spinner` is undocumented.** It is used by three admin components but is
  absent from `docs/design-language.md`'s shared component catalog.

`docs/design-language.md` gains catalog entries for `SettingsCardComponent`,
`SkeletonComponent` (workstream 2) and the existing `app-spinner`.

## 2. Loading and error states

### The problem

Of the sections that fetch, only the three admin pages show anything. About,
Account and Tags show nothing at all.

The tags case is a defect, not a gap. `TagsStore` exposes `loading()` and
`error()`, but `tags-section.component.html` reads neither — its only branch is
`@if (tagsStore.tags().length === 0)`. During the fetch the tag array is empty,
so the template renders its "you have no tags" empty state: a user who *has*
tags is told they have none, every time the section loads.

### The design

A `SkeletonComponent` under `frontend/src/app/shared/skeleton/`, rendering a
configurable number of placeholder rows at the row height the density tokens
already define, so the layout does not shift when data arrives. Used by the four
list surfaces: settings Tags, Admin users, Admin catalog, Admin user detail.

`app-spinner` covers the non-list fetches — About's version load.

`app-error-banner` (shared, added in #193) is adopted by OPML, replacing its
hand-rolled `<p class="error" role="alert">` at
`opml-section.component.scss:29-33`, and by Tags, which has no error surface at
all today.

**The empty state becomes reachable only when loading has finished and the list
is genuinely empty.** This is the acceptance condition for the tags bug, and it
must be pinned by a test that fails if the guard is removed.

## 3. Tag reordering and inline edit

### Reordering

The backend is already done. `Tag.position` exists (`backend/src/Entity/Tag.php:35`),
`TagController::create` assigns it via `TagRepository::nextPositionForUser`, and
`PATCH /api/tags/reorder` (`TagController.php:66-91`) persists a new order —
it requires the exact permutation of the user's tag ids. The reader sidebar
already drives all of this.

So this is frontend-only. The settings tags list becomes a **single
`cdkDropList` of sibling rows**. It reuses `ManageActions.reorderTags()`,
including its existing optimistic-update-then-reconcile behaviour
(`manage-actions.service.ts:73-118`).

**No nested `cdkDropList`s.** This is a standing project rule — nesting silently
breaks cross-list drag, and it already cost this project once. A flat list of
tags has no reason to nest; the constraint is recorded so nobody adds feeds
under each tag here later without reading it.

Drag uses an **explicit handle**, because the row is now also inline-editable and
a whole-row drag target would fight the edit controls.

### Inline edit

A row expands in place into name, colour and icon controls, reusing the same
primitives the dialog uses: `app-field`, `app-color-field`, `app-icon-picker`.
Save issues the same `PATCH /api/tags/{id}` the dialog does. Escape cancels.
Only one row is in edit mode at a time.

`TagFormDialogComponent` (`frontend/src/app/reader/manage/tag-form-dialog.component.ts`)
is **unchanged and stays** — the reader sidebar keeps using it. The sidebar is
too narrow for an inline form; settings has the room. Two surfaces, each with the
affordance that fits it, is the deliberate choice here.

Creating a tag from settings keeps using the dialog. Delete keeps its existing
`ConfirmDialogComponent` two-step confirm.

## 4. Locale write-through

### The problem

`User.locale` (`backend/src/Entity/User.php:80`) is written exactly once, by
`RegistrationService::register()`. Nothing has updated it since. `AccountMailer`
reads it to pick the language of every transactional email
(`AccountMailer.php:95-97`).

Meanwhile the UI language lives only in `localStorage` under `sfr.lang`
(`frontend/src/app/core/language.service.ts`) and is never sent anywhere.

A user who switches the app to German therefore receives English account emails
forever. The preference also cannot follow them to a new browser or to a native
iOS client, which cannot read browser storage.

### The design

**The server is the source of truth; `localStorage` is a cache.**

`MeController` converts from a single `__invoke` to two routed methods on the
same resource:

- `GET /api/me` gains a `locale` field. The response stays hand-built rather than
  serialised from the entity, preserving the existing guarantee that a column
  added later cannot leak in by default.
- `PATCH /api/me` accepts `{ "locale": "en" | "de" }`.

An unsupported value is a **422 `application/problem+json`** against an explicit
allow-list. It is never coerced to a default silently — a wrong locale that
degrades quietly is how the current bug survived unnoticed.

`LanguageService` becomes the single reconcile point:

- On login, adopt `User.locale` and write it to the `sfr.lang` cache.
- On switch, set the active language immediately for responsiveness, then write
  through to the server. A failed write surfaces to the user rather than leaving
  the two values silently disagreeing.
- Logged-out visitors keep using the cached value, so pre-login screens are
  unaffected.

### Native iOS

The new endpoint is JSON in, `application/problem+json` out,
bearer-authenticated and stateless. No cookie, no CSRF token, no browser-only
input. The design-time checklist in `docs/architecture.md` §6 applies.

## Testing

- **The tags empty-state bug** gets a test that fails if the loading guard is
  removed — the empty state must not render while a fetch is in flight.
- **Reordering**: a test that a drop reorders the list and calls
  `reorderTags()` with the new id order, and that a rejected request reconciles
  back to the server's order.
- **Inline edit**: a test per behaviour — save issues the PATCH, Escape cancels
  without saving, and only one row edits at a time.
- **Locale**: a backend functional test for `PATCH /api/me` covering the happy
  path, the 422 on an unsupported value, and 401 unauthenticated; a frontend test
  that login adopts the server value and that switching writes through.
- **The shared components** get their own specs, in the manner of
  `error-banner.component.spec.ts`.
- **Every new assertion is proved by mutation** — break the production code,
  watch the test fail, restore it. Eight of the nine tasks in #193 shipped tests
  that passed while the code was broken; reading never caught it, mutation always
  did.
- Gates: `npm run check` and `composer check` clean; PHPMD clean per touched
  `src` file. Note that the whole-`src` `composer md` sweep cannot run on this
  checkout — pdepend 2.16.2 fails on PHP 8.4's `new Foo()->bar()` syntax,
  pre-existing and tracked as [#183](https://github.com/larspohlmann/simple-feed-reader/issues/183).

## Out of scope

- A `UserSettings` entity or a `GET/PATCH /api/me/settings` pair. Phase 2 was
  dropped deliberately; this is a single write path for one field, not an
  entity.
- Theme persistence. Dark mode is legitimately device-local.
- Inline editing in the reader sidebar, and any change to
  `TagFormDialogComponent`.
- Drag-and-drop in the admin catalog. The categories-with-nested-feeds shape is
  exactly the nested-`cdkDropList` trap; it needs its own design.
- Reordering feeds within a tag from settings. The sidebar already does it.
