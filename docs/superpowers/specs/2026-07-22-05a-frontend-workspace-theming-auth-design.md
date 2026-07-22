# Frontend 5a — Angular workspace, theming, and auth — Design

The frontend is built in three sub-projects, each with its own spec → plan →
implementation cycle:

- **5a (this document)** — the Angular workspace, a themeable design system
  (Graphite, light + dark), and the complete authentication journey, landing the
  signed-in user in a minimal shell.
- **5b** — the core reading experience (responsive shell, subscription/tag tree
  with unread counts, entry list with cursor pagination, reading view,
  read/favorite/keep, mark-all-read, subscribe-by-URL, live refresh progress).
- **5c** — management and admin (tag CRUD with colour/icon, subscription
  management, OPML import/export, the admin user queue, settings).

5a is the foundation the other two build on: it owns the workspace, the theme
system, the HTTP/auth transport, and the app shell. It renders no reader content
of its own beyond a placeholder that 5b replaces.

---

## Goal

A user can register, verify their email, be approved, sign in (email/password or
Google/Apple), reset a forgotten password, and land in a themeable authenticated
shell — with the whole surface honouring a muted Graphite design system in light
and dark, and a code-style/lint gate matching the backend's discipline.

## Constraints (fixed)

- **Angular SPA**, one app; the admin section will be a lazy-loaded route module
  in 5c. This matches the overall
  [design spec](2026-07-21-simple-feed-reader-design.md) and
  [architecture doc](../../architecture.md).
- **Bearer-JWT transport, stateless.** The token lives in `localStorage` and is
  attached by an HTTP interceptor. No auth cookie, no server session, no CSRF
  token on the JSON API. This preserves the native-client-readiness invariants in
  the architecture doc — the frontend must not be the thing that regresses them.
- **The backend contract is frozen.** 5a writes no backend code. Every route,
  payload, and redirect below already exists (Plans 3, 3b, 4a, 4b). The frontend
  conforms to the backend, never the reverse.
- **Muted Graphite palette**, light + dark now, structured so more themes are
  additive later.
- **Responsive**: works on desktop and mobile. In 5a the surfaces are auth
  screens and the shell chrome; the responsive reader layout itself lands in 5b,
  but the shell and theme system are built mobile-first from the start.

## Non-goals for 5a

- No reader UI (entry list, reading view, sidebar tree) — that is 5b.
- No tag/subscription management, OPML, admin, or settings pages — that is 5c.
- No NgRx or other external state library — signal-based services suffice.
- No component/design framework (Angular Material, PrimeNG, etc.) — bespoke SCSS
  over Angular CDK primitives.
- No offline/PWA/service-worker work.

---

## Architecture

### Workspace & conventions

A new Angular workspace at `frontend/`, using the **latest stable Angular**
(standalone components, signals, native control flow `@if`/`@for`/`@switch`,
functional guards and interceptors, `provideRouter`/`provideHttpClient`
bootstrap). No NgModules anywhere. The exact Angular version, the Jest/Angular
integration, and the ESLint/Stylelint flat-config wiring are pinned in the
implementation plan against the then-current tooling.

- **State** is held in signal-based injectable services. No NgRx.
- **Styling** is SCSS with CSS custom properties as design tokens (below).
  **Angular CDK** supplies behaviour only — `a11y` (focus-trap, live-announcer),
  `overlay`, `portal` — with no visual opinions. Icons come from a **self-hosted
  Material Symbols** font (no runtime CDN dependency).
- **Structure** is feature-first, each unit small and single-purpose:

  ```
  frontend/
    src/
      app/
        core/          transport + identity (no UI)
          api-base.ts          apiBaseUrl provider from environment
          token.store.ts       JWT in localStorage, exposed as a signal
          auth.service.ts      login/logout, current user (GET /api/me), role claim
          auth.interceptor.ts  attach bearer; map 401; map problem+json
          auth.guard.ts        authGuard, guestGuard (adminGuard stub → 5c)
          problem.ts           application/problem+json typed model + parser
        theme/
          theme.service.ts     resolve/persist/apply theme; system-preference watch
          tokens.scss          the token contract (variable names only)
          themes/_graphite.scss  light + dark token values
          themes/registry.ts   theme id → label, for the toggle
        auth/            one standalone component per screen + a shared auth-shell
          auth-shell.component.*        centered card layout
          login.component.*
          register.component.*
          verify-email.component.*
          reset-password-request.component.*
          reset-password.component.*
          oauth-callback.component.*
          altcha.ts                     ALTCHA challenge fetch + solve helper
        shell/
          shell.component.*    topbar (app name, theme toggle, account menu), <router-outlet>
        shared/          reusable presentational primitives
          button, text-field, icon, spinner, form-error, ...
        app.routes.ts
        app.config.ts
      styles/
        reset.scss, base.scss   global reset + base typography (token-driven)
      environments/
        environment.ts, environment.development.ts
    (eslint, prettier, stylelint, jest configs at workspace root)
  ```

- **Config.** `environment.ts` exposes `apiBaseUrl`. Development points at the
  Docker backend `https://localhost:8443`; production is same-origin (empty
  base). All API paths are built relative to `apiBaseUrl`.

### Development and build integration

- The Angular dev server runs on **port 4200**. This is deliberate: the backend's
  `APP_FRONTEND_URL` is `http://localhost:4200` and its `CorsListener` echoes that
  origin, so the dev SPA talks to the Docker API **cross-origin with CORS already
  configured** — no dev proxy is introduced. The browser reaches
  `https://localhost:8443` over the mkcert-issued certificate the local stack
  already uses.
- **Production** builds to `backend/public/app/` (per the design spec's
  production layout) and is served **same-origin** behind the `.htaccess` SPA
  fallback, so there is no CORS in production and the OAuth exchange is a
  same-origin request there.

### Theming system (themeable-first)

The requirement is "themeable in the future; light/dark is enough for now." The
system is therefore built so a new theme is a data change, not a code change.

- **Tokens** are CSS custom properties declared on `:root`. `tokens.scss` is the
  *contract* — the list of variable names every component may use, and nothing
  else:
  - colour: `--surface-0` (page), `--surface-1` (panel), `--surface-2` (card),
    `--border`, `--border-strong`, `--text-primary`, `--text-secondary`,
    `--text-muted`, `--accent`, `--accent-soft` (tint fill), `--on-accent`,
    plus role tints `--danger`/`--bg-danger`, `--success`/`--bg-success`;
  - shape/space/type: `--radius`, spacing scale, a small type scale, control
    height.
- **A theme is one set of values for those tokens.** `_graphite.scss` supplies
  two blocks — `:root[data-theme="light"]` and `:root[data-theme="dark"]` — with
  the muted Graphite greyscale and the muted teal accent. Concrete starting
  values (tunable during implementation):

  | Token | Light | Dark |
  |---|---|---|
  | `--surface-0` | `#f5f5f4` | `#161616` |
  | `--surface-1` | `#ffffff` | `#1c1c1c` |
  | `--surface-2` | `#ffffff` | `#242424` |
  | `--border` | `#e4e4e2` | `#2a2a2a` |
  | `--text-primary` | `#2a2a2a` | `#d8d8d6` |
  | `--text-secondary` | `#5f5f5c` | `#9a9a97` |
  | `--text-muted` | `#8f8f8b` | `#6a6a67` |
  | `--accent` | `#3f8676` | `#5aa694` |
  | `--accent-soft` | `#e9f1ef` | `#20302c` |
  | `--on-accent` | `#ffffff` | `#0f1a17` |

  The accent is used sparingly — active nav item, unread counts, links, the
  primary button, focus rings — never as a fill for large areas.
- **Adding a future theme** = a new `themes/_<name>.scss` (its own light/dark
  blocks) plus a `registry.ts` entry. No component changes, because components
  reference only token names.
- **`ThemeService`** (signal-based) resolves the initial theme in order: a saved
  choice in `localStorage` → otherwise the OS `prefers-color-scheme`. It writes
  `data-theme` (light|dark) and a separate persisted *mode* (`light`|`dark`|
  `system`) so that "system" keeps tracking the OS live via a `matchMedia`
  listener. It exposes the current mode as a signal and a `setMode(...)` method.
- **No flash of the wrong theme:** a tiny inline script in `index.html` reads the
  saved mode (or the media query) and sets `data-theme` on `<html>` before
  Angular boots.

### Auth architecture (the transport contract)

- **`TokenStore`** holds the JWT in `localStorage` and exposes it (and derived
  "is authenticated") as signals. It is the single owner of that storage key.
- **`AuthService`** performs `login`/`logout`, exposes the current user (loaded
  from `GET /api/me`) as a signal, and decodes the role claim from the JWT so the
  5c admin guard has what it needs. Logout clears the token and routes to
  `/login`.
- **`authInterceptor`** (functional) attaches `Authorization: Bearer <token>` to
  every request whose URL targets `apiBaseUrl`. On a `401` it clears the token
  and redirects to `/login` (session expiry / suspension take effect on the next
  request, per the backend's per-request user check). It parses
  `application/problem+json` error bodies into the typed `Problem` model the
  forms render — the backend answers problem+json for every error regardless of
  `Accept`, so there is exactly one error shape to handle.
- **The OAuth exchange is the single credentialed request.** `POST
  /api/auth/oauth/exchange` is sent with `withCredentials: true` because it must
  carry the `__Host-oauth_flow` cookie the backend set at flow start (OAuth doc
  §7.3). Every other call is pure bearer with no credentials — the property that
  keeps the API usable by a future native client unchanged.
- **Guards** (functional): `authGuard` protects the shell route; `guestGuard`
  bounces already-authenticated users away from the auth screens. `adminGuard` is
  a stub in 5a and is wired to real admin routes in 5c.

### Routes and screens

Routing uses the exact paths the backend already links to from its emails and
OAuth redirect — these are contract, not choices:

| Route | Purpose | Backend call(s) |
|---|---|---|
| `/login` | email/password + OAuth buttons | `POST /api/auth/login`; `GET /api/auth/oauth/providers`; redirect to `GET /api/auth/oauth/{provider}` |
| `/register` | create account (ALTCHA) | `GET /api/auth/altcha-challenge`; `POST /api/auth/register` |
| `/verify-email?token=…` | confirm email from link | `POST /api/auth/verify-email` |
| `/reset-password-request` | request reset (ALTCHA) | `POST /api/auth/password-reset-request` |
| `/reset-password?token=…` | set new password from link | `POST /api/auth/password-reset` |
| `/auth/callback?code=…|error=…` | finish OAuth | `POST /api/auth/oauth/exchange` (credentialed) |
| `/` | authenticated shell (guarded) | `GET /api/me` |

All auth screens share a centered-card `auth-shell` styled in Graphite and built
mobile-first. Behaviour notes:

- **Login** renders account-status problems (`pending_approval`, `suspended`,
  bad credentials, throttled) as friendly, specific messages rather than a raw
  error — the backend distinguishes these in the problem body.
- **Register** fetches an ALTCHA challenge, solves it client-side, and submits
  the solution with the registration. The solve uses the official `altcha`
  library's programmatic solver (Web Crypto proof-of-work) behind our own
  "verifying…" UI — no third-party widget chrome, so it stays on-theme. On
  success it shows a "check your email" confirmation (the backend defers the mail
  and answers uniformly to avoid account enumeration).
- **Verify-email** posts the token from the query string and, on success, tells
  the user their account is awaiting admin approval.
- **Reset-password-request** solves ALTCHA and shows a neutral "if that address
  exists, a link is on its way" confirmation regardless of outcome (again,
  no enumeration).
- **Reset-password** posts the token plus the new password, then routes to login.
- **OAuth callback** reads `code` (success) or `error` (failure) from the query
  string. On `code` it exchanges credentially for a JWT, stores it, and routes to
  `/`; on `error` it shows the reason and a way back to `/login`.

### The 5a shell (placeholder for 5b)

The authenticated `/` route renders `ShellComponent`: a topbar with the app name,
the theme toggle (light/dark/system), and an account menu showing the signed-in
email (from `GET /api/me`) with a sign-out action — over a placeholder content
area that reads as intentionally empty ("your reader lands here"). Its job is to
prove the entire auth loop end-to-end and to give 5b a frame to build the reader
into. It is responsive: the topbar and menu behave correctly at mobile widths.

---

## Quality gate

The frontend gets the same "clean or it doesn't land" discipline as the backend
(`composer cs`/`stan`/`md` and the PhpStorm inspections). Three tools, each with
an npm script, all run in CI and expected to pass with zero findings on touched
files:

- **ESLint** with `angular-eslint` + `typescript-eslint` (flat config) —
  correctness and code-style lint rules for TypeScript and Angular templates
  (naming, accessibility rules on templates, no floating promises, consistent
  member ordering, etc.). Script: `npm run lint`.
- **Prettier** — formatting/code-style, checked in CI (`--check`) and applied
  locally (`--write`). ESLint defers formatting concerns to Prettier
  (`eslint-config-prettier`) so the two never fight. Scripts:
  `npm run format` / `npm run format:check`.
- **Stylelint** with `stylelint-config-standard-scss` — code style for the SCSS
  (which is where much of this app's surface lives): declaration order, no
  invalid/duplicate properties, and a guard rule that **disallows hard-coded
  colour literals outside `theme/`** so components can only theme through tokens.
  Script: `npm run stylelint`.

A single `npm run check` runs lint + format:check + stylelint + `jest` so the
whole gate is one command, mirroring the backend. The implementation plan wires
these into the existing CI (the frontend is already treated as a standalone
project by CI per the design spec) and may add a `lint-staged` pre-commit hook.

## Testing

Jest (`jest-preset-angular`) for unit and component tests, following TDD. Target
coverage for 5a:

- **ThemeService** — initial resolution (saved mode wins over system; system mode
  follows `prefers-color-scheme`), persistence, `data-theme` application, and
  live reaction to an OS change while in system mode.
- **authInterceptor** — attaches the bearer header to `apiBaseUrl` requests (and
  not to others); clears token and redirects on 401; parses problem+json into the
  typed model.
- **TokenStore / AuthService** — store/derive/clear; role-claim decode; current
  user load.
- **Guards** — `authGuard` allows with a token and blocks without; `guestGuard`
  the inverse.
- **Each auth screen** — happy path, client-side validation, and problem+json
  error rendering (including login's status-specific messages).
- **OAuth callback** — exchanges on `code` with the credentialed flag set,
  stores the token, routes to `/`; renders the reason on `error`.
- **ALTCHA helper** — fetches a challenge and produces a solution the backend's
  format expects (verified against a known challenge/expected-solution vector).

**Frontend end-to-end** (Playwright against the Docker stack) is a cross-cutting
concern that spans 5a–5c. The plan for 5a adds a **thin happy-path smoke**:
register is skipped (needs mail), so the smoke seeds/uses an approved account and
drives email/password login → lands in the shell → toggles theme → signs out.
OAuth and email-link flows are covered by component tests in 5a and folded into
the broader e2e as 5b/5c add reachable surfaces. If a fuller frontend e2e suite
is wanted, it becomes its own small plan rather than bloating 5a.

---

## Design decisions (short form)

| Decision | Choice | Why |
|---|---|---|
| Framework | Angular, standalone + signals, no NgModules | Matches the fixed stack; modern, lean |
| UI layer | Bespoke SCSS + Angular CDK; Material Symbols font | Full control of the muted, themeable look without fighting a design framework |
| State | Signal-based services | Right-sized; NgRx is overkill here |
| Palette | Graphite (greyscale + muted teal), light + dark | Softened-Feedly, quiet; user-selected |
| Theming | CSS custom-property tokens + `data-theme`; theme = data | New themes are additive, zero component churn |
| Token transport | JWT in `localStorage`, bearer interceptor | Per design spec; preserves native-client readiness |
| OAuth exchange | Single credentialed cross-origin call | Required by the flow cookie (OAuth §7.3); everything else stays pure bearer |
| ALTCHA | Official `altcha` solver, own UI | On-theme; interoperates with the backend's algorithmic PoW |
| Dev transport | Cross-origin to Docker API on :4200, backend CORS already set | No proxy; exercises the real CORS path; prod is same-origin |
| Test runner | Jest (`jest-preset-angular`) | Karma deprecated; Jest is the mature greenfield choice |
| Code-style gate | ESLint (angular-eslint) + Prettier + Stylelint, one `npm run check` | Parity with the backend's cs/stan/md discipline; tokens enforced in SCSS |

## Open items for the plan

- Pin the exact Angular version and the matching `jest-preset-angular`,
  `angular-eslint`, and Stylelint versions against current tooling.
- Confirm the self-hosted Material Symbols delivery (subset vs full, variable
  font) and the icon set 5b/5c will need.
- Decide whether the CI frontend job builds the production bundle into
  `backend/public/app/` on every run or only on release (design spec implies
  release-time; the plan will state one).
