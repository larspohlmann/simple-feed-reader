# Frontend error logging → Loki — specification (#984)

**Status:** approved (design settled in #984; brainstorm answers F1/F2 + G1–G3 recorded on the issue).
**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/984
**Depends on:** #983 (JSON logging + the Monolog→Loki push handler + the Grafana settings). Merged to develop.

## Goal

Capture Angular frontend errors and ship them to Grafana Loki by routing them
through the backend, reusing #983's Monolog→Loki pipeline with a
`source=frontend` label. The browser never talks to Loki. Works on every
environment: dev, Docker-prod, and Strato → Grafana Cloud. Keeps the SSRF
boundary and the native-iOS / JSON-API contract intact.

## Environment applicability

| Environment | Behaviour |
|---|---|
| dev / Docker-prod | frontend errors land in local Loki (`source=frontend`) via the app |
| Strato | frontend errors ship to the configured external Loki (Grafana Cloud), same path |

No environment-specific frontend code: the reporter always POSTs to the backend
ingest endpoint; the backend's existing `SettingsLokiEndpoint` decides where the
line goes (unchanged from #983).

## Global constraints (apply to every phase)

- Symfony 7.4 / PHP 8.4, `declare(strict_types=1)`, Clean Code (CLAUDE.md):
  `final readonly` DTOs/VOs, thin controllers (`ThinControllerRule`), guard
  clauses, ≤3-line comments, names reveal intent, no boolean-flag params.
- Angular 20 standalone + signals, no NgModules; component styles in sibling
  `.scss`; no hex/px/media-literals outside `theme/`.
- Native-iOS-viable: JSON in, `application/problem+json` out, bearer auth
  (optional here), no CSRF, no browser-only inputs, no `text/html` fallback.
- Quality gates stay green: `composer check` (cs + stan max + tramp),
  `composer md`, `php bin/phpunit`, `composer infection:diff`; frontend
  `npm run check`. Frontend tests run in the Docker frontend container.
- Secrets/PII never reach Loki unscrubbed (see PII scrub below).
- The Loki push path stays fail-open (a dead Loki never breaks a request) —
  inherited from #983's `LokiClient`.

## Delivery path

`browser → POST /api/client-errors → backend logs via the `client_errors`
Monolog channel → the #983 `LokiPushHandler` labels the line `source=frontend`
and buffers it → flush on `kernel.terminate` → Loki`. The endpoint returns
`202 Accepted` immediately; the push is out of band, exactly like backend logs.

## Backend

### Endpoint
- `POST /api/client-errors`, a new thin `Controller/Api/ClientErrorController`.
- **Security:** a new `- { path: ^/api/client-errors$, roles: PUBLIC_ACCESS }`
  in `security.yaml` `access_control`, inserted **above** the `^/api/` catch-all
  (natural spot: after the favicon carve-out, before `^/api/admin/`). Anonymous
  is allowed — errors happen while logged out. The `api` firewall still runs the
  JWT authenticator on every `^/api` request, so a logged-in report resolves a
  user; read it with a **nullable `#[CurrentUser] ?User $user`** (new ground in
  this repo — Symfony returns null when anonymous; note it in the plan). Tag the
  user id only when present; never require auth.

### Request DTO + caps
- `ClientErrorReportRequest` (`Dto/ClientError/`), `final readonly`, constructor-
  promoted, `#[Assert]` attributes, mapped with `#[MapRequestPayload]` (422
  problem+json on validation failure via the existing `ApiExceptionListener`).
- A **batch**: `errors` is a list of report items (the reporter may coalesce a
  few). Enforce a **max-errors-per-request cap** (`#[Assert\Count(max: N)]`) and
  **per-field length caps** (message, stack, url, userAgent) so an oversized
  stack trace is rejected, not stored. Add a **hard request body size cap**
  (reject early — a small `#[Assert\Length]` on the serialized fields plus a
  controller-level guard; do not rely on PHP's post_max_size alone).
- Fields per item (all length-capped): `message`, `stack` (optional), `kind`
  (error kind/name), `url` (page url), `route` (Angular route), `buildVersion`
  (version+commit+builtAt string), `userAgent`, `at` (client timestamp, ISO).

### Rate limit
- A new `client_errors` limiter in `rate_limiter.yaml` (`sliding_window`,
  `cache.rate_limiter` pool). Consume via the existing
  `RateLimitGuard::enforceForClient($this->clientErrorsLimiter, $httpRequest)`
  (IP-keyed; the `RateLimitedException` → 429 + `Retry-After` path already
  exists). Pick a limit generous enough for a genuinely broken client but that
  caps a flood (e.g. 30 / 1 minute per IP — decide in the plan).

### PII scrub (server-side, before Loki)
- A new `ClientErrorScrubber` (`Service/ClientError/`), `final readonly`, unit-
  tested. Nothing like it exists to reuse. It must, for each report:
  - strip query strings and fragments from `url` (keep scheme/host/path);
  - redact obvious secrets in `stack`/`message`: bearer tokens, `?...=` query
    values, email addresses, long hex/JWT-looking runs — a small, documented,
    tested set of patterns (derive the rules from the artifact; do not overfit).
  - It returns a scrubbed copy; the controller logs only the scrubbed data.

### Logging into the pipeline (the reuse)
- Introduce a dedicated `client_errors` Monolog channel. The #983
  `LokiPushHandler::write()` currently hardcodes `'source' => 'backend'`; change
  it to derive the source from the record's channel — `frontend` for the
  `client_errors` channel, `backend` otherwise (a small explicit mapping on the
  handler, unit-tested; keeps `context` clean and reuses the one handler + the
  one `LokiFlushListener` + fail-open wholesale). The main JSON file handler
  still captures the line too (fine).
- The controller logs each scrubbed item through the channel logger
  (`#[Autowire(service: 'monolog.logger.client_errors')] LoggerInterface`) at
  `error` level, with structured context (kind, url, route, buildVersion,
  userAgent, userId-when-present). `request_id` is added by the existing
  processor. Return `202`.
- **`monolog.yaml`:** the existing `loki` handler keeps `channels: ["!event"]`
  (it still handles `client_errors`, now labelled `frontend` by the channel
  mapping — so NO channel-exclusion surgery is needed; verify the single handler
  labels correctly by channel). The `console`/`main` handlers are unchanged.

## Frontend

### Capture (errors only)
- A net-new global Angular `ErrorHandler` (`core/`), registered in
  `app.config.ts` (`{ provide: ErrorHandler, useClass: … }`), delegating to the
  reporter. It must not swallow Angular's existing console output (log then
  report).
- A `window` `unhandledrejection` listener (registered once at bootstrap).
- Extend `core/auth.interceptor.ts` `catchError`: report non-401 HTTP failures
  (5xx and status 0/network); the existing 401 clear-and-redirect is untouched
  and 401s are NOT reported.
- Fold the existing surfaces into the reporter: `NavigationFailureReporter`
  (`core/navigation-failure.ts`) and `revealBootErrorSurface`
  (`core/boot-error-surface.ts`) also report — but `boot-error-surface.ts` must
  stay Angular-import-free (runs pre-injector), so it calls a plain-function
  beacon, not the injectable reporter.
- The `index.html` boot watchdog (inline vanilla JS, pre-bundle) gets its own
  tiny inline `navigator.sendBeacon('/api/client-errors', …)` (a couple of
  lines; it cannot use any Angular/HttpClient) so a total boot failure is still
  reported. It must build the same JSON shape.

### `ClientErrorReporter` (root service, `core/`)
- `@Injectable({ providedIn: 'root' })`, `inject()`-style, colocated with
  `NavigationFailureReporter`/`VersionService`.
- Tags each report with **route** (from the Router), **buildVersion** (static
  import of `environments/version.ts` `buildVersion` — version+commit+builtAt;
  do NOT use `VersionService`, which does a network round-trip), **user id when
  present** (only if the app knows it), and **userAgent**.
- Delivers via `fetch(url, { keepalive: true })`; fallback
  `navigator.sendBeacon` when `fetch`+keepalive is unavailable — so unload-time
  errors survive. Fire-and-forget (never awaited on the error path; never throws
  back into the handler).
- **Client-side dedupe + throttle**: collapse identical errors (by a
  message+stack signature) within a short window and cap reports/interval, so
  one broken render cannot flood the endpoint.
- POSTs to `${API_BASE_URL}/api/client-errors` (inject `API_BASE_URL`; `''`
  same-origin, `/reader` on Strato).

## Testing
- Backend: functional test of the endpoint (anonymous 202; user-tagged when JWT
  present; 422 on oversized/too-many; 429 when rate-limited); unit tests for the
  scrubber (query strings, tokens, emails redacted) and the handler's channel→
  source mapping.
- Frontend: Jest for `ClientErrorReporter` (tags, dedupe/throttle, beacon
  fallback, fire-and-forget), the `ErrorHandler`, and the interceptor's non-401
  reporting (401 still NOT reported). No e2e in the CI gate.

## Out of scope (v1)
- Grafana Faro / web-vitals / session-level frontend observability / frontend
  traces (a Grafana Cloud or Docker-only upgrade, impossible on Strato).
- A deliberate app-event logger beyond errors.
- `console.*` mirroring.

## Key decisions / rulings
1. **Reuse the single `LokiPushHandler`, deriving `source` from the
   `client_errors` channel** — not a second handler instance, not a direct
   synchronous `LokiClient::push()`. Rationale: reuses #983's buffer + terminate-
   flush + fail-open with the least new wiring; the channel is Monolog's
   idiomatic dimension and `LokiFlushListener` already covers it.
2. **Buffered + 202, not synchronous push** — the endpoint returns immediately;
   the browser sends fire-and-forget (keepalive/beacon), so latency is moot and
   the request thread is never blocked ~1s per report.
3. **Nullable `#[CurrentUser] ?User`** on a `PUBLIC_ACCESS` route — new in this
   repo; standard Symfony (null when anonymous).
4. **Boot watchdog reports via inline vanilla `sendBeacon`**, separate from the
   injectable reporter, because it runs before the Angular injector exists.
5. **`buildVersion` has three fields** (`version`, `commit`, `builtAt`) — tag
   with the whole string; the issue's "version + commit" undercounted.
