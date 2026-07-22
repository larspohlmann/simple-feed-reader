# Frontend Docker Services — Design

**Goal:** Bring the Angular SPA into the local Docker stack so `docker compose up`
starts the whole system — backend and frontend — with one command, and add an
opt-in profile that previews the production bundle served same-origin.

**Status:** implemented 2026-07-22 on branch `feature/05a-frontend-workspace-theming-auth`
(additive to the local Docker stack; extends plan 5a).

## Context

The [local Docker stack](../../local-docker.md) already runs MySQL, PHP-FPM,
nginx (mkcert TLS at `https://localhost:8443`) and Mailpit. Plan 5a delivered the
Angular workspace under `frontend/`, run on the host with `npm start`. This work
closes the gap the local-docker spec left open ("Angular frontend… when the SPA
arrives, it joins this nginx") so the frontend no longer needs a separate host
process.

Key fact that shapes the design: **the browser runs on the host.** It fetches the
SPA bundle from the frontend container and calls the API directly at
`https://localhost:8443` (dev) or same-origin (prod). So a dev frontend container
only has to *serve the bundle* — it never talks to the backend containers. And
`http://localhost` is a secure context, so `crypto.subtle` (the ALTCHA solver)
works without TLS on the dev server.

## Decision: two services, dev by default + prod behind a profile

The user asked for both a dev default and a prod profile.

### `frontend` — dev server (default `docker compose up`)

- `node:22-slim`, runs `ng serve --host 0.0.0.0 --port 4200 --poll 2000`.
- Source bind-mounted (`./frontend:/app`); a **named volume** shields
  `/app/node_modules` so the host's macOS `node_modules` (platform-specific
  esbuild binary) is never used inside the Linux container. First run installs
  Linux deps into the volume; later ups skip straight to the dev server.
- `--poll` makes file-watching work across the bind mount on macOS (live reload).
- Cross-origin to the API on `:8443`, which the backend CORS already allows
  (`APP_FRONTEND_URL=http://localhost:4200`). **No backend change.**
- Port `127.0.0.1:4200:4200` — loopback only, like every other service.

### `frontend-prod` — production preview (`--profile prod`)

- Multi-stage `docker/frontend/Dockerfile`: stage one `ng build` (production
  config → `apiBaseUrl` `''`), stage two nginx serving the static bundle.
- `docker/frontend/prod.conf`: SPA at `/` with `try_files … /index.html`
  fallback; `location /api/` `fastcgi_pass`es to `php:9000` with a hardcoded
  `SCRIPT_FILENAME=/app/public/index.php` (valid because php mounts `./backend`
  at `/app` regardless of this nginx's own root). **Same-origin, so no CORS.**
- TLS on `127.0.0.1:8444:443` reusing the mkcert certs (`__Host-oauth_flow`
  needs Secure/HTTPS, same reasoning as the backend nginx).
- Hidden unless the `prod` profile is active, so plain `docker compose up` never
  builds or runs it.

## npm version pin (why `npm i -g npm@11`)

The `package-lock.json` was authored by npm 11 (the host). node 22 — used by the
dev container, the prod build, and the CI frontend job — ships npm 10.9.8, which
re-resolves a transitive dev dependency (`chokidar`) and rejects the lock with
`EUSAGE`. Rather than adopt an untested `chokidar` major by regenerating the lock,
every place that runs `npm ci` pins npm 11 first, matching the lock's author and
keeping the exact tree already proven green (39 Jest + 3 Playwright + build). This
also fixes the CI frontend job, which would otherwise have failed identically.

## Verification (all confirmed against the running stack)

- Dev: `docker compose up -d frontend` → `http://localhost:4200` serves the app;
  the served bundle carries `apiBaseUrl "https://localhost:8443"`.
- Prod: `docker compose --profile prod up -d --build frontend-prod` →
  `https://localhost:8444/` serves the SPA, `/login` returns 200 (client-route
  fallback), `/api/health` returns `{"status":"ok"}` (same-origin proxy), and the
  bundle carries `apiBaseUrl ""`.

## Deliberately out of scope / known limitations

- **OAuth on the prod preview.** The backend redirects OAuth to
  `APP_FRONTEND_URL` (`:4200`). On `:8444` the OAuth round-trip therefore lands
  back on the dev origin; the prod preview is for the built bundle + same-origin
  password/API flows. Override `APP_FRONTEND_URL` on the php service to exercise
  OAuth on `:8444`.
- **Dependency changes.** Because the dev container's `node_modules` lives in a
  named volume populated on first run, changing dependencies needs a refresh:
  `docker compose run --rm frontend npm ci` (or remove the
  `frontend-node-modules` volume) — documented in the guide.
- **No production deploy target.** This is a local preview, not the deployment
  image (that remains plan 6). Prod OAuth, real secrets, and opcache tuning are
  out of scope here.
