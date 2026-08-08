# Local Docker Environment

A full local stack — MySQL, PHP-FPM, nginx with real TLS, and a mail catcher —
for anyone who wants to run the backend without installing PHP or MySQL
natively. It is strictly additive: the native SQLite workflow (plain
`vendor/bin/phpunit` in `backend/`) keeps working unchanged.

---

## 1. What you get

Six services, started with one command from the repository root:

| Service | Where |
|---|---|
| Frontend (Angular dev server, live reload) | http://localhost:4200 |
| API (nginx → PHP-FPM 8.3) | https://localhost:8443 (http://localhost:8080 redirects there) |
| Mailpit web inbox | http://localhost:8025 |
| MySQL 8.4 | 127.0.0.1:33306 (user/password `feedreader`/`feedreader`, root `root`) |
| Worker — recommendation runs in the background, 5-minute feed refresh sweep | `docker compose logs -f worker` |

Every host port is bound to loopback only — nothing on your LAN can reach the
stack. MySQL sits on 33306 so a natively installed MySQL never collides.

**The additive guarantee.** The compose file injects `DATABASE_URL` and
`MAILER_DSN` as *real environment variables* into the PHP container. Symfony's
env precedence puts real env vars above `.env`/`.env.test` file values, so the
containers use MySQL and Mailpit while the committed `.env` files stay
untouched — run the suite natively and you still get SQLite, exactly as before.
The `backend/` directory is bind-mounted into the container, so edits on the
host are live immediately; no rebuild, no sync step.

---

## 2. Prerequisites

- **Docker Desktop** (or a compatible Docker Engine with the compose plugin),
  running before you start the First run steps.
- Free host ports **4200**, **8080**, **8443**, and **8025** (MySQL's 33306 is
  non-standard precisely to avoid collisions).
- **mkcert**, for a locally trusted TLS certificate:

  ```bash
  brew install mkcert && mkcert -install
  ```

  `mkcert -install` creates a local certificate authority and adds it to the
  system trust store — that is what makes the browser show a clean padlock
  instead of a warning. If Firefox matters to you, run `brew install nss`
  *before* `mkcert -install`; Firefox keeps its own trust store and mkcert
  needs `certutil` from nss to reach it. On Linux, install `mkcert` from your
  package manager (e.g. `apt install mkcert`; Firefox needs `libnss3-tools`)
  or see [mkcert's install docs](https://github.com/FiloSottile/mkcert#installation).

---

## 3. First run

> **The easy path.** If you just want the development stack running, use the
> one-line dev installer instead of the manual steps below — it does exactly
> this sequence for you, against the latest release:
>
> ```bash
> curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install-dev.sh | bash
> ```
>
> The steps below are the same commands spelled out, for when you want to run
> them yourself or understand what the installer does. See also
> [`scripts/`](../scripts/): `update.sh` moves an install to the newest release,
> and the two `frontend-*.sh` scripts start/stop the dev frontend.

From the repository root, in order:

```bash
mkdir -p docker/certs && mkcert -cert-file docker/certs/localhost.pem -key-file docker/certs/localhost-key.pem localhost 127.0.0.1 ::1
docker compose up -d
docker compose exec php composer install
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

Then open https://localhost:8443/api/health — it answers `{"status":"ok"}`.

The certificate lands in `docker/certs/`, which is git-ignored: TLS keys never
enter the repository, and each developer's cert is signed by their own local
CA. The first `up` also creates both databases: `feedreader` for dev (via the
image's `MYSQL_DATABASE` variable) and `feedreader_test` for the suite (via
`docker/mysql/init.sql`, which also grants the `feedreader` user access to
it).

---

## 4. Everyday commands

| Task | Command |
|---|---|
| Full test suite | `docker compose exec php vendor/bin/phpunit` |
| Coding standard | `docker compose exec php composer cs` |
| Static analysis | `docker compose exec php composer stan` |
| Any console command | `docker compose exec php bin/console …` |
| Follow logs | `docker compose logs -f php nginx` |
| Stop the stack | `docker compose down` |
| MySQL from a host GUI tool | connect to `127.0.0.1:33306` |
| Black-box e2e suite (from `backend/`) | `composer e2e` — see [`backend/tests/E2e/README.md`](../backend/tests/E2e/README.md) |

**The suite in the container is the MySQL leg.** The same suite that runs
against SQLite natively runs here against MySQL — in seconds, not minutes. It
uses
`feedreader_test`, not `feedreader` — Doctrine's `when@test` `dbname_suffix`
appends `_test` to whatever `DATABASE_URL` points at — so a test run never
touches your dev data. Double-opt-in mails sent during the run appear in the
Mailpit inbox, because the injected `MAILER_DSN` also wins in `APP_ENV=test`.
That is a feature, not a leak: you can watch exactly what the registration
flow mails out.

**`docker compose down` is safe; `docker compose down -v` DELETES the MySQL
data volume.** Plain `down` stops and removes the containers but keeps your
databases. Adding `-v` wipes them; the next `up` starts from an empty server
and re-runs `docker/mysql/init.sql`, after which you migrate again. One
exception to "plain `down` keeps everything": Mailpit's inbox is in-memory,
so it starts empty after any `down`.

---

## 5. Step debugging (Xdebug)

Xdebug is installed in trigger mode: requests run at full speed unless
something asks for a debug session, so it costs almost nothing when idle.

Point your IDE at port 9003 and map `backend/` on the host to `/app` in the
container. Then trigger a session:

- **HTTP requests** — set the `XDEBUG_TRIGGER` cookie, most easily via a
  browser extension such as "Xdebug helper".
- **CLI and tests** — pass the trigger as an env var:

  ```bash
  docker compose exec -e XDEBUG_TRIGGER=1 php vendor/bin/phpunit --filter SomeTest
  ```

To rule Xdebug out entirely for one run (e.g. when timing something), disable
it: `docker compose exec -e XDEBUG_MODE=off php vendor/bin/phpunit`.

---

## 6. Why HTTPS locally

The OAuth flow stores its browser-binding secret in a `__Host-oauth_flow`
cookie, and `__Host-` cookies are `Secure` by definition — the browser only
sends them over HTTPS. Chrome grants plain `http://localhost` a Secure-cookie
exemption; Safari does not. A plain-HTTP local stack would make the OAuth flow
work in one browser and silently fail in another, which is exactly the kind of
"works on my machine" this stack exists to prevent. Details of the flow:
[docs/oauth-sign-in.md](oauth-sign-in.md).

---

## 7. Gotchas

- **Editing `docker/nginx/default.conf` needs a recreate, not a restart.**
  Most editors save by writing a new file and renaming it over the old one,
  which gives the file a new inode — and a single-file bind mount keeps
  pointing at the old one. After a config edit, run
  `docker compose up -d --force-recreate nginx`.
- **502 after the php container is recreated.** nginx resolves `php:9000` at
  startup; if the php container comes back with a new address, nginx keeps
  talking to the old one. `docker compose restart nginx` fixes it.
- **The certificate expires.** mkcert leaf certificates are valid for roughly
  two years. When the browser starts complaining, re-run the `mkcert` command
  from section 3 (it overwrites in place) and force-recreate nginx.
- **Root-owned `vendor/` on Linux hosts.** `docker compose exec php composer
  install` runs as root inside the container; on Linux the bind mount exposes
  that ownership on the host. Either run composer natively or use
  `docker compose exec -u www-data php composer install`. macOS users never
  see this — Docker Desktop maps file ownership.

---

## 8. Extension points

Choices here were made so later plans can build on this stack without redoing
it:

- **Production image.** Delivered — `docker/php/Dockerfile` has a `prod`
  target (no dev deps, baked-in source, tuned opcache) driven by
  `docker-compose.prod.yml`. See [docker-production.md](docker-production.md).
- **Angular frontend.** Delivered — see [§9](#9-frontend-in-docker). The dev
  service runs cross-origin on `:4200` (bearer JWT, so no auth cookie to keep
  same-site); the production stack serves the built SPA same-origin, the
  topology this stack was designed to allow ([docs/oauth-sign-in.md](oauth-sign-in.md)).
- **Worker / cron container.** Delivered in #311 — see the `worker` service; it
  reuses the php image and the same env injection.

---

## 9. Frontend in Docker

`docker compose up -d` now also starts the **Angular dev server** at
http://localhost:4200 with live reload — the whole system comes up with one
command. The browser (on your host) loads the app from `:4200` and calls the API
at `https://localhost:8443` directly; that is cross-origin, which the backend
CORS already allows (`APP_FRONTEND_URL`). So the frontend container only serves
the bundle — it never talks to the backend containers. `http://localhost` is a
secure context, so the ALTCHA `crypto.subtle` solver works without TLS here.

**First run is slower.** The container installs the app's Linux dependencies into
a named volume (`frontend-node-modules`) — the host's macOS `node_modules` can't
be reused because esbuild ships a platform-specific binary. Later ups find the
volume populated and boot straight into the dev server. Watch the install with
`docker compose logs -f frontend`.

**After changing dependencies** (edited `package.json`/lockfile), refresh that
volume so the container reinstalls:

```bash
docker compose run --rm frontend npm ci
# or, to force a clean slate:
docker compose down && docker volume rm simple-feed-reader_frontend-node-modules
```

> npm note: the container pins npm 11 before installing. node 22 ships npm 10.9.8,
> but the lockfile was authored by npm 11; npm 10 mis-resolves a transitive dep
> and rejects the lock. The same pin is in the CI frontend job and the prod image.

### Previewing the production topology

The old `prod` profile (a production bundle served over the dev backend)
is gone. To preview the real thing, run the actual production stack
locally — [docker-production.md](docker-production.md) — with mkcert
certificates: `mkcert -cert-file docker/certs-prod/fullchain.pem
-key-file docker/certs-prod/privkey.pem localhost 127.0.0.1 ::1`, a
`.env.prod` with test values (ports moved off 80/443 to avoid clashing
with the dev stack), then `./scripts/prod-start.sh`. Unlike the old
preview, this exercises the production PHP runtime too — `APP_ENV=prod`,
no Mailpit, no xdebug.

Design and rationale: [docs/superpowers/specs/2026-07-22-frontend-docker-services-design.md](superpowers/specs/2026-07-22-frontend-docker-services-design.md).

## The weekly rot check

Both e2e suites also run in GitHub Actions every Wednesday
([.github/workflows/e2e-rot-check.yml](../.github/workflows/e2e-rot-check.yml)),
against a stack the runner boots the same way this page describes — including
its own mkcert certificate. It is deliberately **not** a pull-request check:
booting the stack costs minutes, and issue #96 chose weekly reporting over
per-PR gating.

The runner starts from an empty database, so it also catches host dependencies
a developer machine hides. The first runs found four: an untracked `vendor/`,
an untracked LexikJWT keypair, an unseeded feed catalog, and a seed command
that only created its fixture entry on the feed-creating branch.

A Playwright run where every spec skipped exits 0, which for an unattended
check is the worst outcome. `scripts/assert-playwright-ran.sh` re-decides that
verdict and fails the job when nothing was verified.

When a suite rots, the run opens a single issue labelled `e2e-rot` and comments
on that issue on later failures rather than opening more. A red run does not
block a deploy; the deploy guard still only reads `ci.yml`.

Run it early with:

```bash
gh workflow run e2e-rot-check.yml
```
