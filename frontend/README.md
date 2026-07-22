# Frontend

The Angular 20 single-page app for simple-feed-reader: the reader UI and the full
auth journey (register, email confirmation, sign-in by password or OAuth, password
reset). Standalone components and signals throughout; bespoke SCSS over Angular
CDK; CSS-custom-property theming. The bearer JWT in `localStorage` is the entire
auth story, which keeps a future native client in play (see
[../docs/architecture.md](../docs/architecture.md)).

## Install

```bash
npm ci
```

Node 22. `npm ci` installs from the committed `package-lock.json`.

## Development server

```bash
npm start
```

Serves the app at `http://localhost:4200/`. In development the API base URL is
`https://localhost:8443` (`src/environments/environment.development.ts`), so bring
the [Docker stack](../docs/local-docker.md) up first — the SPA talks to the
backend running there over TLS. The dev build reloads on source changes.

In production the API base URL is empty (`src/environments/environment.ts`): the
SPA is served same-origin with the backend, so requests are relative.

## Run in Docker

You don't need Node on the host at all — the [Docker stack](../docs/local-docker.md)
runs the frontend too. `docker compose up -d` (from the repo root) starts the
Angular dev server at http://localhost:4200 with live reload alongside the backend.
A `prod` profile previews the production bundle served same-origin on
`https://localhost:8444`:

```bash
docker compose --profile prod up -d --build frontend-prod
```

See [§9 of the Docker guide](../docs/local-docker.md#9-frontend-in-docker) for the
node_modules-volume refresh, the npm-11 pin, and the OAuth caveat on the preview.

## The gate

```bash
npm run check
```

Runs the full quality gate, the same one CI runs:

- **ESLint** (`npm run lint`) — TypeScript + Angular template rules.
- **Prettier** (`npm run format:check`) — formatting. `npm run format` rewrites.
- **Stylelint** (`npm run stylelint`) — the `.scss` files. `color-no-hex` is on:
  **hex colours are forbidden in `.scss` outside `src/app/theme/`**, the one place
  literal colours may appear. Component styles are inline in their `.ts` files
  (outside Stylelint's `.scss` glob) and are kept token-only (`var(--…)`) by
  convention, not by the linter.
- **Jest** (`npm test`) — unit tests (jest-preset-angular, jsdom).

## Build

```bash
npm run build
```

Compiles to `dist/` (production configuration by default: budgets enforced,
output hashing on). CI runs this to prove the app compiles.

### Production output path (release step)

For a release the production bundle is copied into `backend/public/app/` so the
SPA is served **same-origin** with the API (which is why the production API base
URL is empty). That copy is a **release-time step**, not part of every CI run —
CI builds to `dist/` to verify compilation and stops there. No `angular.json`
configuration wires the copy in 5a.

## End-to-end smoke

```bash
npm run e2e
```

Playwright smokes over the auth journey (`e2e/auth-smoke.spec.ts`), the reader
shell (`e2e/reader-smoke.spec.ts`), and settings + admin
(`e2e/settings-admin-smoke.spec.ts`). They need the **Docker stack up** (they
drive the real backend), so they are **not** part of `npm run check` or the CI
unit-gate job — run them locally against Docker, or in a dedicated integration
job later. Both the reader and the settings/admin smoke sign in as the seeded
`app:e2e:seed-admin` account (`e2e-admin@example.com`), the same fixture the
backend e2e suite authenticates as, and skip cleanly when that account or the
stack is absent.

## Reader

The reader is the app's home screen (`src/app/reader/`) — a three-region shell
composed by `ReaderShellComponent`, over the frozen 4a/4b read-model API:

- **Sidebar** — the navigation tree: All items, Favorites, and Kept, then your
  tags (each expandable to the subscriptions under it) and untagged feeds, every
  row carrying its unread count. The selection lives in the URL query
  (`view` / `tag` / `subscription` / `unread` / `entry`), so any view is
  linkable and survives a reload.
- **Entry list** — the selected view's entries, cursor-paginated with infinite
  scroll (an `IntersectionObserver` sentinel), an unread-only toggle, and
  mark-all-read. Opening an entry marks it read and decrements the counts
  optimistically, rolling back if the server rejects it.
- **Article reader** — the shared reader view for a single entry, with
  read / favorite / keep and prev/next navigation.

Subscribing is by URL: the **Add feed** dialog takes a feed or site address and,
when the address is an HTML page, lists the discovered feed candidates to pick
from. A header refresh control drives the backend refresh loop and shows its
progress inline.

### Reading layout (List / Pane)

A **List / Pane** preference sits beside the theme toggle in the header, backed
by `ReadingLayoutService` and persisted **on-device** in `localStorage`
(`sfr.layout`) exactly like the theme choice — it is never sent to the server.
**List** is the default: the article opens over the list. **Pane** places the
list and article side by side, but only on a wide viewport (a CDK
`BreakpointObserver` query); it falls back to List on narrow screens.

### Preview images

Row preview images are derived **client-side** from the entry content the API
already returns — there is **no backend change**. `firstPreviewImage()` parses
the content (falling back to the summary) inertly with `DOMParser` and takes the
first absolute `https://` image `src`; `http`, relative, and `data:` sources are
rejected — the app is served over https, so `http` images are mixed-content
blocked and relative ones have no base to resolve against. Images render with
`referrerpolicy="no-referrer"`.

### Dependency

The reader adds one dependency, **`@angular/cdk`** (`^20.2`): its `Dialog` and
`A11yModule` focus-trap back the Add-feed dialog, and `BreakpointObserver`
drives the wide-screen Pane layout.

### Out of the reader's scope

Tag and subscription **management** (renaming, retagging, unsubscribing),
**OPML** import/export, and the **admin** approval queue are not part of this
shell — the reader consumes the read-model and the subscribe / refresh /
entry-state endpoints only. See "Management & admin (5c)" below for where
those live.

## Management & admin (5c)

Everything that changes a feed, a tag, or another user's account lives outside
the reader shell, over the same frozen JSON API plus its `/api/tags`,
`/api/opml/*`, and `/api/admin/users*` endpoints:

- **`/settings`** (`src/app/settings/`) — a single page, four sections, each
  its own component composed by `SettingsComponent`:
  - **Feeds** — rename a subscription (`customTitle`), retag it, or
    unsubscribe, via the shared `EditSubscriptionDialogComponent` /
    `ConfirmDialogComponent`.
  - **Tags** — full CRUD: name, an optional colour (`#rrggbb`, curated swatches
    plus a native colour picker) and an optional Material Symbol icon, via
    `TagFormDialogComponent`. Deleting a tag removes it from every feed that
    used it.
  - **Import & export** — download all subscriptions as an OPML file, or
    import one (file picker or pasted XML); the result reports imported /
    already-subscribed / invalid / over-limit counts, and new feeds fill in on
    the next refresh.
  - **Account** — email, member-since date, sign out, and (for admins) a link
    into the user queue.
- **Sidebar manage menus** — each tag and feed row in the reader sidebar
  carries a hover/tap "⋮" menu (Edit / Delete, Edit feed / Unsubscribe) that
  opens the same dialogs settings uses, so an action taken from the sidebar
  and one taken from Settings behave identically.
- **`ManageActions`** (`src/app/reader/manage/manage-actions.service.ts`) is
  the one place a management dialog is opened and its result applied — both
  the settings sections and the sidebar call it, so a dialog's own API write
  and the store refresh afterward happen exactly once, in exactly one place.
- **`/admin/users`** (`src/app/admin/`, lazy-loaded) — the user-approval
  queue: filter by status, then approve / reject / suspend. Route-gated by
  `adminGuard`, a UX-only check (it fetches the current user if not already
  loaded, then requires `ROLE_ADMIN`); the real enforcement is the backend's
  `ROLE_ADMIN` requirement on `^/api/admin/`. An admin can never reject or
  suspend themselves — those actions are hidden for their own row.

### The one deferral

A per-feed "retry this dead feed" action was on the roadmap. The backend
supports it (`POST /api/refresh?feedId=`), but keys on the **Feed** id, which
`SubscriptionJson` doesn't expose (only `feedUrl` and `status`). Erroring feeds
surface a tooltip explaining that the next global refresh will retry them;
targeted per-feed retry is deferred to a small follow-up plan that adds a
`feedId` to `SubscriptionJson`.

## Theming

Theming is CSS custom properties. 5a ships one theme, **Graphite**, in light,
dark, and system modes:

- `src/app/theme/tokens.scss` maps the `data-theme` attribute on `:root` to the
  theme's light/dark mixins, plus mode-invariant tokens (radius, spacing, control
  height).
- `src/app/theme/themes/_graphite.scss` defines the Graphite palette.
- `src/app/theme/themes/registry.ts` lists registered themes and the `ThemeMode`
  type.
- `ThemeService` resolves the saved-or-system mode, persists the choice, and
  reacts to OS changes; a no-flash boot script applies the theme before first
  paint.

**Adding a theme** is additive and touches no component: create a new SCSS file
under `src/app/theme/themes/` and add one entry to `registry.ts`. Because
components only ever read tokens (token-only by convention), the new palette
applies everywhere with no component changes.

## Auth model

The **bearer JWT held in `localStorage` is the whole auth story** — no auth
cookie, no server-side session. A functional HTTP interceptor
(`src/app/core/auth.interceptor.ts`) attaches `Authorization: Bearer …` to API
requests and, on a `401`, clears the token and routes to `/login`. This stateless
transport is deliberately native-client-friendly.

The **one credentialed call** in the whole app is the OAuth code→token exchange on
the callback (the backend binds the flow with a `__Host-` cookie for that single
cross-origin request). Everything else is a plain bearer-token request.
