# Simple Feed Reader — Design

**Date:** 2026-07-21
**Status:** Approved design, pre-implementation

A multi-user RSS/Atom feed reader. Symfony JSON API + Angular SPA, deployed to
Strato shared hosting via GitHub Actions. The repository is public and serves as
a showcase for the backend code.

## Constraints (fixed)

- **Hosting:** Strato shared hosting. No SSH, no Docker, no long-running
  processes, no Redis. SFTP-only deploy. PHP version selectable in the panel.
  `max_execution_time` applies to every request.
- **No `bin/console` in production, ever.** Every operational action needs a
  web-reachable path.
- **Refresh triggering:** must work via cron (if available), via an external
  HTTP pinger (e.g. cron-job.org), and manually from the UI — same code path,
  different time budgets.
- **Database:** MySQL in production, SQLite for local dev and tests. Doctrine
  abstraction; no vendor-specific SQL without a portability check.
- **Code style:** PSR-12, enforced by PHPCS. PHPStan at max level. During
  development the checks run through `mcp-phpstan-server` and
  `mcp-phpcs-server`; CI runs the same binaries.

## Scope

**v1 in:** subscribe/unsubscribe by URL (with feed autodiscovery), tags
(many-to-many, with color + icon), unread counts, article list + reading view,
read/unread (single + mark-all-read), favorite flag, keep flag,
retention/cleanup, OPML import/export, manual refresh with live progress,
email+password auth (CAPTCHA + double opt-in), Google + Apple sign-in,
manual account approval, admin area (user queue, feed health, log viewer).

**v1 out:** full-text search, keyboard shortcuts, full-article scraping,
mobile apps, social features, E2E test suite.

## Architecture

Pragmatic Symfony: hand-written controllers → services → Doctrine entities.
No API Platform, no hexagonal layering. Two interfaces exist because tests
need them, not for ceremony:

- `FeedFetcherInterface` — all outbound HTTP; carries the SSRF guard; faked in
  tests.
- `ClockInterface` (symfony/clock) — injected everywhere time matters; no
  service calls `new \DateTimeImmutable()` directly.

### Repository layout

```
simple-feed-reader/
├── backend/                  # Symfony 7.x, PHP 8.3+
│   ├── src/
│   │   ├── Controller/       #   Api/, Admin/ (JSON only, no Twig)
│   │   ├── Entity/
│   │   ├── Repository/
│   │   ├── Service/          #   FeedFetcher, RefreshRunner, Sanitizer, OpmlService, …
│   │   ├── Security/         #   UserChecker, MaintenanceTokenAuthenticator, OAuth providers
│   │   └── Command/          #   app:feeds:refresh, app:user:*
│   ├── config/  migrations/  tests/
│   └── public/index.php
├── frontend/                 # Angular workspace
├── .github/workflows/        # ci.yml, deploy.yml
├── docs/
└── README.md
```

`backend/` and `frontend/` are complete standalone projects; CI treats them as
two jobs.

### Production layout (Strato)

Everything on one domain. The Strato docroot is mapped to
`/feedreader/public`, so `vendor/`, `var/`, and config are never
web-reachable. The Angular production build is copied into `public/app/` at
deploy time. Symfony serves `/api/*` and `/maintenance/*`; an `.htaccess`
fallback hands everything else to the Angular `index.html`. Same origin →
no CORS.

### Frontend

One Angular app. The admin section is a lazy-loaded route module guarded by a
route guard reading the role claim from the JWT. The guard is UX only —
enforcement is `ROLE_ADMIN` on `/api/admin/*` in `security.yaml`.

## Authentication & accounts

### Tokens

- LexikJWTAuthenticationBundle. Single access token, **7-day lifetime**, no
  refresh tokens.
- Token stored in `localStorage`, attached by an Angular HTTP interceptor.
- Revocation: the Doctrine user provider loads the user from the DB on every
  request anyway; a `UserChecker` rejects non-`active` users. Suspension takes
  effect on the next request.
- XSS exposure is bounded by Angular template escaping plus server-side
  sanitization of the only third-party HTML we render (article content).

### Identities

```
User          id, email, passwordHash|null, roles, status, createdAt, approvedAt
UserIdentity  id, user→User, provider (google|apple|…), providerUserId,
              email|null, createdAt      UNIQUE(provider, providerUserId)
```

A user may hold several identities (password + Google + Apple). New providers
are a new `provider` value plus one provider class — no migration.

### OAuth flow (Google + Apple, both in v1)

Authorization-code flow, server-side, via `knpuniversity/oauth2-client-bundle`
behind a small `OAuthProviderInterface`:

1. `GET /api/auth/oauth/{provider}` → 302 to provider (server-stored `state`)
2. Provider callback → backend exchanges code, obtains verified identity
3. Known `UserIdentity` → issue JWT. Unknown → create user in
   `pending_approval` (OAuth verifies identity; the admin decides membership)
4. Backend redirects to the SPA with a **one-time login code** (30 s TTL,
   single use); SPA exchanges it via `POST /api/auth/oauth/exchange` for the
   JWT. The JWT itself never appears in a URL.

**Linking rule:** if the provider-verified email matches an existing account,
link the identity to it. Apple private-relay addresses
(`…@privaterelay.appleid.com`) never match and simply become new pending
accounts.

### Registration (email + password)

User lifecycle:

```
pending_verification ──(email link, 24h)──▶ pending_approval ──(admin)──▶ active
                                                 │                          │
                                              rejected ◀──────────── suspended
```

- **Double opt-in:** verification link required before the account appears in
  the admin queue. Unverified accounts auto-purge after 48 h.
- **Tokens** (verification + password reset): one `ActionToken` entity with a
  `purpose` column; random 32-byte value stored **hashed**, single-use, 24 h
  expiry.
- **CAPTCHA:** ALTCHA (self-hosted proof-of-work, no third party, no cookies)
  on `register` and `password-reset-request` — the two anonymous
  email-triggering endpoints. Login is protected by Symfony login throttling
  instead.
- **Mailer:** Symfony Mailer over Strato SMTP. Exactly three mails: verify,
  approved, password reset. OAuth users skip email verification entirely.
- Growth is controlled by **manual approval** (no hard numeric cap): every
  new account waits in `pending_approval` until an admin approves or rejects
  it.

## Data model

Feeds are shared and deduplicated by URL; everything user-specific hangs off
subscriptions.

```
User          (see above)

Feed          id, url (unique), siteUrl, title, description, faviconUrl,
              status (active|erroring|gone), lastFetchedAt, nextFetchAt,
              fetchIntervalMinutes, consecutiveFailures, lastErrorMessage,
              etag, lastModified

Entry         id, feed→Feed, guid, url, title, author, summary,
              contentHtml (sanitized at ingest), publishedAt, createdAt
              UNIQUE(feed, guidHash)          # guidHash = sha256(guid)

Tag           id, user→User, name, color|null, icon|null
              UNIQUE(user, name)              # icon = named Material Symbol

Subscription  id, user→User, feed→Feed, customTitle|null,
              markedReadUntil|null, createdAt
              UNIQUE(user, feed)
              tags ↔ many-to-many via subscription_tag

EntryState    user→User, entry→Entry, isRead, isFavorite, isKept, readAt
              PK(user, entry)
```

Key decisions:

- **Sparse read state.** An `EntryState` row exists only after an explicit
  action. "Mark all read" writes a `markedReadUntil` watermark on the
  subscription; unread = entries newer than the watermark minus
  explicitly-read rows.
- **Tags, not folders.** A subscription can carry multiple tags. Untagged is a
  legitimate state (shown under "All", no forced bucket). Unread counts per
  tag may overlap — correct behavior.
- **Tag icons** are named identifiers from the Material Symbols set, rendered
  via `<mat-icon>`. No uploads. Backend validates length/charset only.
- **`favorite` vs `keep`:** favorite = curation ("this is great"),
  keep = retention instruction ("don't delete"). Both are independent flags;
  both protect from pruning. Sidebar gets a Favorites view and a Kept view
  (a protect flag you cannot review is a leak).
- **Sanitize at ingest** (symfony/html-sanitizer), store clean HTML, serve
  raw. The DB never contains live XSS.
- **Retention:** entries older than ~90 days are pruned during refresh runs
  unless any user's state row has `isFavorite` or `isKept` (`NOT EXISTS`).
  Protection is global across users — inherent to shared entries, documented.
- **Portability:** SQLite is the dev/test default, MySQL production. Only the
  DSN differs. Portable column types only; `guidHash` exists so the unique
  index behaves identically on both.

## Fetch pipeline

One service, `RefreshRunner`, three callers (cron command, maintenance
endpoint, user refresh endpoint). Callers differ only in **scope**
(all due / one user's feeds / one feed), **force** flag, and **time budget**
(~5 min CLI, ~20 s maintenance HTTP, ~10 s user slice).

Loop:

1. **Acquire global lock** (Symfony Lock, DB store). Already running → exit
   with "busy"/"already running".
2. **Select due feeds:** `nextFetchAt <= now AND status != 'gone'`, ordered,
   batched.
3. **Per feed:** conditional GET (stored ETag/Last-Modified; 304 is
   near-free) → parse (RSS 2.0 / Atom / RSS 1.0) → dedupe by `guidHash` →
   sanitize → insert new entries → update schedule → **flush per feed**.
   One broken feed never affects the others; a budget exit loses nothing
   committed.
4. **Budget check between feeds:** if remaining budget < safety margin
   (~10 s, one worst-case fetch), stop cleanly. Unprocessed feeds stay due.
5. **Return a report** (fetched / notModified / failed / skippedForBudget /
   remaining) — CLI output, endpoint response, admin feed-health data.

**Adaptive scheduling:** base interval 60 min, multiplicatively nudged by
observed activity; floor 30 min, ceiling 24 h.

**Failure handling (per feed):** errors increment `consecutiveFailures`,
interval backs off exponentially (×2, cap 7 days); success resets. ~30
consecutive failures → status `gone` (never auto-fetched; UI shows "appears
dead" with manual retry). HTTP 410 short-circuits. A 301 updates the stored
URL once the target proves fetchable.

**SSRF guard** (in the fetcher, applies to every outbound request including
subscribe-time discovery — the user-triggered, most dangerous path):

- `http`/`https` only
- Resolve DNS first, reject private/reserved ranges (RFC 1918, loopback,
  link-local, ULA), connect to the validated IP (closes DNS rebinding)
- Max 5 redirects, each hop re-validated
- Response cap ~5 MB, timeout ~10 s per request

**Feed discovery:** pasting an HTML URL scans for
`<link rel="alternate" type="application/rss+xml">` and offers candidates.

### Manual refresh with live progress

`POST /api/refresh` (user JWT) runs one ~10 s slice scoped to the caller's
feeds and returns the tally:

```json
{ "status": "partial", "total": 42, "fetched": 9, "notModified": 21,
  "failed": 1, "remaining": 11 }
```

The Angular client loops until `remaining` is 0, driving a progress bar. The
response of each call is the progress event — no WebSockets/SSE/job table.

- Manual = ignore the schedule, but skip feeds fetched within the last
  ~5 min (cooldown).
- Global lock shared with cron; `{"status":"busy"}` → client retries after a
  pause.
- Rate-limited (Symfony RateLimiter, ~1 full cycle per user per few minutes).
- Optional `feedId` parameter doubles as the per-feed "retry now" for dead
  feeds.

## API surface

All under `/api`, JWT-protected except auth endpoints:

```
POST   /auth/register            → 202, pending_verification (ALTCHA required)
POST   /auth/verify-email        { token }
POST   /auth/login               → { token } (403 + reason when pending/suspended)
POST   /auth/password-reset-request | POST /auth/password-reset   (ALTCHA on request)
GET    /auth/oauth/{provider}    → 302   |  …/callback  |  POST /auth/oauth/exchange
GET    /me
POST   /refresh                  ← progress-loop endpoint
GET    /subscriptions            (tags + unread counts included)
POST   /subscriptions            { url } → subscribed or discovery candidates
PATCH  /subscriptions/{id}       (customTitle, tags)
DELETE /subscriptions/{id}
GET    /tags | POST | PATCH | DELETE
GET    /entries?feed=&tag=&view=unread|favorites|kept&before=…
PATCH  /entries/{id}/state       { isRead?, isFavorite?, isKept? }
POST   /entries/mark-read        { scope: all|feed|tag, id?, until }
POST   /opml/import  |  GET /opml/export
GET    /admin/users | POST /admin/users/{id}/approve | reject | suspend
GET    /admin/feeds  (health)   |  GET /admin/logs
GET    /health                   (public — used by the deploy health check)
```

- **Cursor pagination** (`before` = publishedAt+id): offset pagination
  duplicates/skips items while new entries arrive.
- **`mark-read` carries an `until` timestamp** (client sends its list-load
  time) so entries arriving during reading stay unread — the watermark
  surfacing in the API.

### Maintenance endpoints (machine callers, not JWT)

`POST /maintenance/{action}?token=…` — long random token from env,
constant-time comparison, fixed action allowlist:

- `refresh` — budgeted RefreshRunner slice (for cron-job.org / panel cron)
- `post-deploy` — migrate → cache:clear → cache:warmup, plain-text report

### Error contract

RFC 7807 `application/problem+json` everywhere, produced by a single
exception listener mapping exception classes → status codes. Stable
machine-readable `type` values (`validation_error`, `feed_unreachable`,
`subscription_limit_reached`, …) the client switches on. Unexpected errors →
opaque 500, details to the log only. Controllers never build error responses
by hand.

### Logging

Monolog `rotating_file`, ~7 days retained — a full disk quota on shared
hosting takes the DB down. The admin UI includes a read-only viewer for the
current log; it is the only window into production errors.

## Testing

Backend-focused pyramid, PHPUnit:

- **Unit:** feed parsing against a directory of real-world fixture files
  (missing GUIDs → hash of link+title, duplicate GUIDs, broken dates, CDATA,
  encoding lies — every production oddity becomes a fixture); SSRF guard with
  stubbed DNS (private ranges, rebinding, redirect revalidation); adaptive
  scheduling, backoff, watermark logic, and time budgets via `MockClock`;
  sanitizer in both directions (strips scripts, keeps images/formatting).
- **Integration:** the non-trivial repository queries (due selection, unread
  counts, prune-except-kept) against a real DB. **CI runs the suite on both
  SQLite and MySQL** — the matrix is what proves portability.
- **Functional:** `WebTestCase` with a fake `FeedFetcherInterface`. Full
  journeys (register → verify → approve → login → subscribe → refresh → read)
  plus the authorization matrix for every endpoint (401 anonymous, 403/404
  cross-user, 403 non-admin). OAuth tested to the boundary with a mocked
  provider.
- **Frontend:** component tests for the refresh-progress loop and the auth
  interceptor only. No E2E in v1.

## CI/CD & deployment

**`ci.yml`** (PRs + main): backend job — PHPCS, PHPStan max, PHPUnit on
SQLite + MySQL; frontend job — lint, tests, production build. Branch
protection requires green CI; README carries the badges.

**`deploy.yml`** (on tag):

1. Build backend: `composer install --no-dev --optimize-autoloader`; PHP
   minor version pinned in workflow **and** `composer.json` `config.platform`
   to match Strato.
2. Build frontend into `backend/public/app/`.
3. Inject config from GitHub Secrets → `composer dump-env prod` →
   `.env.local.php`. JWT keypair generated once, stored in Secrets — never
   regenerated per deploy.
4. **Enter maintenance mode:** lftp `put` of `var/maintenance.flag`.
5. SFTP mirror (`lftp mirror --reverse --delete`), with `var/` excluded from
   deletion (logs, runtime cache, flag file — and keeps SQLite-in-prod
   reversible).
6. `curl` `POST /maintenance/post-deploy` — migrate, cache:clear,
   cache:warmup. Non-2xx fails the workflow.
7. **Leave maintenance mode:** lftp delete of the flag — only on success.
8. Health check: `curl /api/health`.

**Maintenance mode** is a flag file + rewrite rule in `public/.htaccess`:

```apache
RewriteCond %{DOCUMENT_ROOT}/../var/maintenance.flag -f
RewriteCond %{REQUEST_URI} !^/maintenance/
RewriteRule .* - [R=503,L]
ErrorDocument 503 /maintenance.html
```

Static `maintenance.html` ships with the artifact; `/maintenance/*` stays
reachable so the post-deploy call can end the outage; API 503s are mapped by
the Angular interceptor to a "back in a minute" banner (`Retry-After: 60`).
On failure the site **stays in maintenance** — better than a half-migrated
app. Manual escape hatch: delete the flag via SFTP (documented in README).

**Rollback:** deploy the previous tag. Migrations are written additive-first
(add column → deploy → drop old column next release) so the previous release
always runs on the current schema.

## Decisions log (short form)

| Decision | Choice | Why |
|---|---|---|
| Hosting | Strato shared (SFTP, no SSH) | Given; mirrors homepage setup |
| Backend shape | Pragmatic Symfony, no API Platform | Hand-written API is the showcase; no layer ceremony |
| Frontend | Angular (+ admin as lazy module) | Batteries included; frontend is not the showcase |
| Auth | Lexik JWT 7d in localStorage; DB user check per request = instant revocation | Symfony loads the user per request anyway |
| Social login | Google + Apple v1, provider interface for more | Apple dev account exists |
| Registration | CAPTCHA (ALTCHA) + double opt-in + manual approval | Bounded SSRF blast radius, verified queue |
| Multi-user data | Shared feeds, per-user subscriptions/tags/state | Fetch once per feed, not per user |
| Categories | Tags (m:n) with color + icon, not folders | One feed, multiple categories |
| Flags | favorite (curation) + keep (retention), both prune-proof | Distinct intents |
| Refresh | One budgeted, lock-guarded, resumable runner; 3 entry points | HTTP time limits; no daemons |
| DB | Doctrine, SQLite dev / MySQL prod, CI matrix proves portability | Requested abstraction, made testable |
| Deploy | Tag → CI build → SFTP mirror → maintenance-mode window → post-deploy endpoint | No SSH; no stale-container race |
