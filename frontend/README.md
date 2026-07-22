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

A Playwright smoke over the auth journey. It needs the **Docker stack up** (it
drives the real backend), so it is **not** part of `npm run check` or the CI
unit-gate job — run it locally against Docker, or in a dedicated integration job
later.

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
