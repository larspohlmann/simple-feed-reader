# Frontend

The Angular 20 single-page app for simple-feed-reader: the reader UI and the full
auth journey (register, email confirmation, sign-in by password, passkey or OAuth,
password reset). Standalone components and signals throughout; bespoke SCSS over Angular
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
empty (`src/environments/environment.development.ts`), so the app calls `/api`
same-origin: the dev server proxies `/api` and `/state` to the `nginx` container
(`proxy.conf.json`), and only the Docker `frontend` service starts it with
`--proxy-config proxy.conf.json` (see "Run in Docker" below). Bring the
[Docker stack](../docs/local-docker.md) up first. The dev build reloads on source
changes.

In production the API base URL is empty (`src/environments/environment.ts`): the
SPA is served same-origin with the backend, so requests are relative.

## Run in Docker

You don't need Node on the host at all — the [Docker stack](../docs/local-docker.md)
runs the frontend too. `docker compose up -d` (from the repo root) starts the
Angular dev server at http://localhost:4200 with live reload alongside the backend.

The **production stack** serves the compiled bundle same-origin behind nginx —
see [docs/docker-production.md](../docs/docker-production.md); it can be run
locally with mkcert certificates to preview the production topology.

See [§9 of the Docker guide](../docs/local-docker.md#9-frontend-in-docker) for the
node_modules-volume refresh and the npm-11 pin.

## The gate

```bash
npm run check
```

Runs the full quality gate, the same one CI runs:

- **ESLint** (`npm run lint`) — TypeScript + Angular template rules.
- **Prettier** (`npm run format:check`) — formatting. `npm run format` rewrites.
- **Stylelint** (`npm run stylelint`) — the `.scss` files. `color-no-hex` is on:
  **hex colours are forbidden in `.scss` outside `src/app/theme/`** and the global
  stylesheets (`src/styles.scss`, `src/styles/`). Spacing, font-size and sizing
  values take relative units only, and a width media query takes no literal.
  Every component keeps its styles in a sibling `.scss` file (`styleUrl`), so
  Stylelint sees all of them.
- **Spec typecheck** (`npm run typecheck:spec`) — `tsc` over `tsconfig.spec.json`.
- **Jest** (`npm test`) — unit tests (jest-preset-angular, jsdom).

## Build

```bash
npm run build
```

Compiles to `dist/frontend/browser/` (production configuration by default:
budgets enforced, output hashing on). CI runs this to prove the app compiles.

### Production output path (release step)

A production install serves the bundle **same-origin** with the API (which is
why the production API base URL is empty). The copy is a **release-time step**,
not part of every CI run: the Docker `web` image (`docker/web/Dockerfile`) builds
the bundle and copies `dist/frontend/browser/` into nginx's document root, and the
Strato release (`deploy/strato/build-release.sh`) builds it with
`--configuration production,strato` and copies the same directory into the
release's `public/`. CI builds to `dist/` to verify compilation and stops there.

## End-to-end smoke

```bash
npm run e2e
```

Playwright specs under `e2e/`: four smokes, over the auth journey
(`e2e/auth-smoke.spec.ts`), the reader shell (`e2e/reader-smoke.spec.ts`), the
magazine reading layout (`e2e/magazine-smoke.spec.ts`), and settings + admin
(`e2e/settings-admin-smoke.spec.ts`), plus focused specs that each pin one
behaviour (for example `e2e/pull-to-refresh-mobile.spec.ts`). They need the
**Docker stack up** (they drive the real backend), so they are **not** part of
`npm run check` or the CI gate: run them locally against Docker. The weekly
`.github/workflows/e2e-rot-check.yml` runs them in CI. The global setup
(`e2e/global-setup.ts`) purges the accounts a previous run left behind, then
seeds the `app:e2e:seed-admin` account (`e2e-admin@example.com`) and gives it a
subscription. The reader, magazine, and settings/admin smokes all sign in as
that account, the same fixture the backend e2e suite authenticates as, and skip
cleanly when that account or the stack is absent.

## Reader

The reader is the app's home screen (`src/app/reader/`) — a three-region shell
composed by `ReaderShellComponent`:

- **Sidebar** — the navigation tree: All items, Favorites, Kept and Viewed, For
  you (when AI is ready for the account), your saved searches, then your tags
  (each expandable to the subscriptions under it) and untagged feeds, every row
  carrying its count. The selection lives in the URL — the query parameters
  `view` / `tag` / `subscription` / `entry` / `q`, and the paths
  `/searches/saved/<id>` and `/searches/saved/all` for saved searches — so any
  view is linkable and survives a reload.
- **Entry list** — the selected view's entries, cursor-paginated with infinite
  scroll (an `IntersectionObserver` sentinel), an unread-only toggle, and
  mark-all-read. Opening an entry marks it read and decrements the counts
  optimistically, rolling back if the server rejects it.
- **Article reader** — the shared reader view for a single entry, with
  read / favorite / keep and prev/next navigation.

Subscribing is by URL: the **Add feed** dialog takes a feed or site address and,
when the address is an HTML page, lists the discovered feed candidates to pick
from. A **Refresh** button in the sidebar and in the list header, and
pull-to-refresh on a phone, drive the backend refresh loop; a hairline under the
app bar shows its progress.

### Reading layout (Magazine / List / Pane)

A **Magazine / List / Pane** preference sits beside the theme control in the
sidebar's foot (`ViewControlsComponent`), backed by `ReadingLayoutService` and
persisted **on-device** in `localStorage` (`sfr.layout`) exactly like the theme
choice — it is never sent to the server. **Magazine** is the default, boxed or
airy; that style, unlike the layout, is saved to the account
(`MagazineStyleService`), with `localStorage` as its paint cache. **List** opens
the article over the list. **Pane** places the list and article side by side, but
only on a wide viewport (`LayoutService.isWide`, a CDK `BreakpointObserver`
query); it falls back to List on narrow screens.

#### Magazine layout

Magazine renders the same ordered entries as a single varied column instead of
uniform rows. A pure planner, `planMagazine({ entries, grouping, complete })`
(`reader/magazine/magazine-planner.ts`), turns the entry list into a sequence
of typed blocks (`reader/magazine/magazine-block.ts`) — it never touches the DOM
or the network, so it's plain unit tested:

- **Entry blocks**, tallest first: `hero`, `wide`, `quote`, `split`, `kicker`,
  `thumb` and `compact`. Page templates (`reader/magazine/magazine-templates.ts`)
  give each entry a slot. An image-poor view, or a text-rich one that is not
  image-rich, uses the text family of templates; any other view the image
  family. A slot its entry cannot fill steps down the `DEMOTION` ladder until it
  fits or reaches `compact`.
- **Source group** — a run of 8 or more entries in a row from one feed keeps
  its first 3 as full blocks and folds the rest into one `group` block that
  previews 4 rows before "Show more". Grouping is off in a single-stream view
  (one subscription, or For you), and in a view with fewer than 3 sources active
  within a day of its first entry.

The planner is a stable prefix: while more pages can load (`complete` is false)
it holds back a partial trailing page, so appending a page only adds blocks
after what's already planned and never re-plans earlier ones — infinite scroll
doesn't reshuffle content already on screen. Its thresholds (the template-family
shares, the run length, the page height cap) are named constants at the top of
the file, tunable without touching the algorithm. `EntryHeroComponent` also
gates on the loaded image's natural size, dropping the picture from a hero whose
image turns out narrower than 200 pixels, such as a tracking pixel.

### Preview images

Row preview images come from the server: each entry in the API response carries
its persisted `imageUrl` with `imageWidth` / `imageHeight` (null when the feed
did not say), which `entryImage()` (`reader/preview-image.ts`) reads; the dek is
the server's plain-text `excerpt` (`entrySnippet()`). Images render with
`referrerpolicy="no-referrer"`.

### Dependencies

The reader leans on **`@angular/cdk`** (`^20.2`): its `Dialog` backs the dialogs,
`Overlay` the toasts and action sheets, `DragDrop` the sidebar's drag-to-reorder
and drag-to-tag, and `BreakpointObserver` (`LayoutService`) the wide-screen Pane
layout and the narrow-screen drawer. It also loads **`hls.js`** for streaming
video (`reader/hls-streams.ts`) and **`highlight.js`** for code blocks
(`reader/code-highlight.ts`), both on first use.

### Out of the reader's scope

**OPML** import/export, the account backup, the **Organise** page and the
**admin** screens are not part of this shell; they live under `/settings` (see
"Settings and admin" below). The shell's own management is the sidebar's
manage menus, which open the same dialogs through `ManageActions`.

## Settings and admin

Everything that changes a feed, a tag, the account, or another user's account,
beyond the sidebar's manage menus, lives under `/settings` (`src/app/settings/`):
a lazy-loaded shell (`SettingsShellComponent`) with a hub page
(`SettingsHubComponent`) and one lazy route per section. `SETTINGS_SECTIONS`
(`src/app/settings/settings-sections.ts`) is the one list the navigation rail
and the hub both draw from.

- **General sections** — Organise (`settings/organise/`: reorder feeds and tags
  by drag, tag several feeds at once, change their visibility, retry or
  unsubscribe unhealthy feeds), Import & export (OPML and the account backup),
  Preferences, Email (the digest), Account (member-since date, sign out,
  passkeys, deleting the account), AI, and About.
- **Admin sections** — Users (with a detail page per user), Catalog, Instance
  settings, Proxy, Mail, and Grafana, under `/settings/admin/`. `/admin/users`
  and `/admin/catalog` redirect there.
- **Sidebar manage menus** — each tag and feed row in the reader sidebar
  carries a hover/tap "⋮" menu (Edit / Delete, Edit feed / Unsubscribe) that
  opens the same dialogs settings uses, so an action taken from the sidebar
  and one taken from Settings behave identically.
- **`ManageActions`** (`src/app/reader/feeds/manage/manage-actions.service.ts`) is
  the one place a management dialog is opened and its result applied — both
  the settings sections and the sidebar call it, so a dialog's own API write
  and the store refresh afterward happen exactly once, in exactly one place.
- **Users** (`src/app/admin/users/admin-users.component.ts`) — the user-approval
  queue: filter by status, then approve / reject / suspend. Every admin route
  is gated by `adminGuard`, a UX-only check (it fetches the current user if not
  already loaded, then requires `ROLE_ADMIN`); the real enforcement is the
  backend's `ROLE_ADMIN` requirement on `^/api/admin/`. An admin can never
  reject or suspend themselves — those actions are hidden for their own row.

## Theming

Theming is CSS custom properties. There is one theme, **Graphite**, in light,
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
components only ever read tokens (Stylelint's `color-no-hex` keeps hex colours
out of their `.scss`), the new palette applies everywhere with no component
changes.

## Auth model

The **bearer JWT held in `localStorage` is the whole auth story** — no auth
cookie, no server-side session. A functional HTTP interceptor
(`src/app/core/auth/auth.interceptor.ts`) attaches `Authorization: Bearer …` to API
requests and, on a `401`, clears the token and routes to `/login`. This stateless
transport is deliberately native-client-friendly.

The **one credentialed call** in the whole app is the OAuth code→token exchange on
the callback (the backend binds the flow with the `__Host-oauth_flow` cookie for
that single request). Everything else is a plain bearer-token request.
