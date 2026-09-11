# Grafana observability — specification (#983)

**Status:** approved (design settled in the #983 brainstorming session).
**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/983
**Follow-up:** #984 (frontend error logging) builds on this and is out of scope here.

## Goal

Ship structured application logs to Grafana Loki, and — where the environment
allows — request traces to Grafana Tempo, and view both in Grafana. Self-host
the containers where Docker exists; point at an external Grafana (for example
Grafana Cloud free tier) elsewhere.

## Environment matrix

| Environment | Loki + Grafana | Tempo / tracing | Log destination |
|---|---|---|---|
| dev (`docker-compose.yml`) | on by default (always on, like Meilisearch) | opt-in (heavier) | local Loki |
| Docker-prod installs (`docker-compose.prod.yml`) | optional (compose profile) | with the profile | local Loki |
| Strato (live prod, non-Docker) | none | none | Grafana Cloud (external endpoint) |

Strato cannot run containers or the PECL extension. It still gets JSON logs, a
`request_id` on every line, and the reserved `trace_id` field.

## Global constraints (verbatim, apply to every phase)

- Symfony 7.4 LTS, PHP 8.4, `declare(strict_types=1)` in every PHP file.
- Clean Code is mandatory (see CLAUDE.md). `final readonly class` with
  constructor promotion is the house style. Thin controllers (enforced by
  `ThinControllerRule`). No boolean flag parameters.
- Quality gates must stay green: `composer check` (cs + stan level max + tramp),
  `composer md`, `php bin/phpunit`, and — for changed files — `composer
  infection:diff`. Frontend: `npm run check`.
- `declare(strict_types=1)`; secrets encrypted at rest and write-only over the
  API (only a `has…` hint crosses the wire).
- Native-iOS-viable: JSON in, `application/problem+json` out, bearer auth, no
  CSRF, no browser-only inputs, no `text/html` fallback.
- Datetimes stored as naive UTC.
- One committed `composer.lock`; the Strato release build and CI run on machines
  **without** the `opentelemetry` extension and must stay green.
- Log retention (Loki) 14 days; trace retention (Tempo) 72 hours; 100% head
  sampling for traces.
- **Grafana image pinned to a 9.0-or-later release** — the `mcp-grafana` server
  requires Grafana 9.0+. Phases 4 and 5 use `grafana/grafana:11.5.2`; never
  downgrade below 9.0.

## Phase 1 — structured JSON logging + request-id processor

**Deliverable:** every log line carries a stable `request_id`, and the log
output is JSON where machines read it, while dev keeps a human-readable console.

- A `RequestId` value/holder that mints one ULID per request (via
  `symfony/uid`, already a dependency) and exposes it for the whole request.
  A `kernel.request` listener assigns it; a CLI/worker path mints one per
  process (per handled message for the messenger worker).
- A Monolog **processor** that adds `request_id` to every record's `extra`.
  It also adds `trace_id`/`span_id` **only when an OpenTelemetry span is
  active** (Phase 5 wires the span; Phase 1 leaves the fields absent when no
  span exists — the processor asks a small seam that returns null pre-Phase-5).
- Monolog formatting:
  - dev: keep the `console` handler human-readable; the rotating `main` file
    handler emits **JSON** (`JsonFormatter`).
  - test: unchanged (rotating file, debug, fingers_crossed) — but JSON on the
    nested handler is acceptable if it does not break existing assertions;
    default to leaving test formatting alone.
  - prod: the rotating `main` file handler emits JSON, level `info`, as today.
- The JSON records must contain `level`, `channel`, `message`, `context`,
  `extra` (with `request_id`). `datetime` stays ISO-8601 UTC.

**Testable without containers.** No Loki, no Docker.

## Phase 2 — Monolog → Loki push handler

**Deliverable:** the app pushes its own `info`+ logs to a configured Loki push
URL, buffered and flushed out of band, fail-open.

- A `LokiPushHandler` (Monolog handler) that batches records and POSTs them to
  Loki's push API (`/loki/api/v1/push`) as a single stream, labelled
  (`app`, `env`, `channel`, `level`, `source=backend`). Body is the Loki JSON
  push shape: `{"streams":[{"stream":{...labels},"values":[["<ns>","<line>"],…]}]}`.
  The line is the Phase-1 JSON record.
- **Buffering + flush:** records accumulate in memory for the request. Flush on
  `kernel.terminate` (after `fastcgi_finish_request` when available) with a hard
  ~1s timeout. The messenger worker flushes per handled message and on a size
  cap (records buffered ≥ N). All Loki/network errors are swallowed — a dead
  Loki never touches request handling.
- **Auth:** optional HTTP basic auth (username + token) for Grafana Cloud; none
  for the local container. Credentials come from the Phase-3 settings service
  (URL + username + sealed token), resolved at flush time. When no URL is
  configured, the handler is inert (no-op).
- **Level:** `info`+ (independent of the file handler's level).
- Uses `Symfony\Contracts\HttpClient\HttpClientInterface`, and must respect the
  SSRF boundary rules already in the codebase for outbound HTTP (the Loki URL is
  operator-configured, not user-supplied, but reuse the established client).

**Testable without containers** via a mock HTTP client asserting the push body,
labels, timeout, and fail-open behaviour.

## Phase 3 — admin Grafana settings

**Deliverable:** an admin can configure the shipping endpoint (Loki push URL +
optional username + secret token) and the viewing URL (Grafana), with the
local-container values shown as effective defaults and the DB storing only
overrides.

Clone the `ProxyServerSettings` sealed-secret pattern and the `publicBaseUrl`
override/effective-default pattern.

Backend:
- `GrafanaSettings` entity (singleton row): `lokiPushUrl` (nullable override),
  `lokiUsername` (nullable), sealed token columns (`tokenCiphertext`,
  `tokenNonce`, `tokenSalt`, `keyVersion`) + a clear-text `tokenHint` (last 4
  chars, like `AiProviderSettings.apiKeyHint`), `grafanaUrl` (nullable override).
  Absence of the row = "use env defaults / not configured".
- `GrafanaApiKeyCipher` (purpose `grafana-api-key`) wrapping
  `InstanceSecretCipher`, mirroring `ProxyPasswordCipher`.
- `GrafanaSettings` orchestration service with `view()` / `update()`; resolves
  env effective-defaults (`GRAFANA_LOKI_PUSH_URL`, `GRAFANA_URL`, set by the
  installer when the container profile is on) and returns both the override and
  the effective value in the payload.
- `GrafanaSettingsRequest` DTO with the 3-state secret intent (`?string $token`
  null=keep / string=replace, `bool $removeToken`), plus `?string $lokiPushUrl`,
  `?string $lokiUsername`, `?string $grafanaUrl` (null clears an override →
  fall back to env default).
- `GrafanaSettingsJson` mapper exposing `hasToken` (never the token),
  `tokenHint`, and both override + effective values for each URL, plus
  `containerPresent` (whether an env default exists).
- `AdminGrafanaController` at `/api/admin/grafana` (GET, PUT), thin.
- A resolver the Phase-2 handler reads at flush time to get the effective Loki
  push URL + username + decrypted token.

Frontend (`frontend/src/app/settings/admin/grafana/`):
- `grafana-section.component.ts/html` + `grafana-settings.service.ts` extending
  `DraftSettingsService`, mirroring `proxy-section`. Two setting groups
  (shipping, viewing). Token via `PasswordInputComponent`, never seeded from the
  server, empty = keep. Show the effective/local-container value beside each
  overridable field (like `publicBaseUrl` + `publicBaseUrlDefault`).

**Testable:** backend functional tests over the endpoint (auth via
`^/api/admin/` prefix), unit tests for the 3-state secret and env fallback;
frontend Jest tests for the service draft/save/remove logic.

## Phase 4 — self-host container stack + provisioning + installer

**Deliverable:** an operator can opt into a Loki + Grafana stack; dev has it on
by default; Grafana is provisioned ready to use.

- **dev `docker-compose.yml`:** add `loki` and `grafana` services with **no
  profile** — always on, exactly like the dev `meilisearch` service. No opt-out
  is needed (per the user). The app pushes to `http://loki:3100`. Tempo (Phase 5)
  stays opt-in in dev, behind a profile, since it is the heavier piece.
- **prod `docker-compose.prod.yml`:** add `loki` and `grafana` behind
  `profiles: ["grafana"]` (mirror the Meilisearch service at
  `docker-compose.prod.yml:115-123`), each with a named volume. Drive the profile
  from `.env.prod` exactly as Meilisearch does.
- **Provisioning:** a Grafana provisioning tree (datasources + dashboards)
  mounted into the container: Loki datasource pointing at the internal Loki, one
  starter "Application logs" dashboard (log volume stacked by level, an
  error+critical stat, a live log panel with `level`/`channel` template
  variables). Loki config with 14-day retention (compactor). A generated
  `GF_SECURITY_ADMIN_PASSWORD` shown once at install.
- **Installer (`scripts/lib.sh`, `scripts/install.sh`, `scripts/prod-configure.sh`):**
  clone the Meilisearch path — `prod_uses_grafana()` predicate, extend
  `prod_compose_profiles()` to append `grafana`, `use_grafana`/`use_no_grafana`
  appliers, a `configure_grafana` prompt. Print a **mention before the package
  prompt**, then ask a dedicated Grafana yes/no in every **non-quick** path
  (S/M/L/C), re-askable in `prod-configure.sh`. Quick (`Q`) stays off. Write the
  effective-default env vars (`GRAFANA_LOKI_PUSH_URL`, `GRAFANA_URL`) into
  `.env.prod` when the profile is on. Cover with `scripts/test/` bash unit tests
  like `configure-search-engine.test.sh`.

**Testable:** installer bash unit tests; `docker compose config` validity; a
manual/CI smoke that the stack starts and Grafana provisions.

## Phase 5 — OpenTelemetry tracing + Tempo

**Deliverable:** on dev + Docker-prod (never Strato), each request produces a
span waterfall in Tempo, correlated to its logs.

- **Packaging:** add `open-telemetry/sdk`, `open-telemetry/exporter-otlp`, and
  `open-telemetry/opentelemetry-auto-symfony` to `require`. Set
  `config.platform.ext-opentelemetry` in `composer.json` so CI and the Strato
  build resolve on machines without the extension. **Register the bundle only
  when `extension_loaded('opentelemetry')` is true** (conditional registration in
  `config/bundles.php` or a kernel hook), so Strato never activates tracing.
- **Docker images:** add the `opentelemetry` PECL extension via
  `install-php-extensions` in the php image build(s) used by dev + Docker-prod.
- **Exporter:** OTLP HTTP to the internal Tempo (`http://tempo:4318`), set by an
  installer env var; 100% head sampling. Never external in v1.
- **Tempo container:** add `tempo` behind the same compose profile as
  Loki+Grafana; 72h trace retention; provision it as a Grafana datasource and
  wire the Loki→Tempo `trace_id` correlation (derived field on the Loki
  datasource → Tempo).
- **Correlation:** the Phase-1 processor now finds an active span and adds
  `trace_id`/`span_id` to every log line, so a log line links to its trace.
- **dev:** tracing is **opt-in** (the third container + an active exporter),
  even though logs are on by default.

**Testable:** the conditional bundle registration (present-vs-absent extension);
composer resolves without the extension; `docker compose config`; a manual smoke
that a request appears as a trace in Tempo and its logs link across.

## Dev tooling — Grafana MCP server

Not a product/stack change: a repo-root `.mcp.json` entry runs the open-source
`mcp/grafana` server over stdio (in Docker) for local Claude sessions, pointed
at the dev Grafana (`http://host.docker.internal:3000`). It reads a Grafana
service-account token from `GRAFANA_SERVICE_ACCOUNT_TOKEN` (never committed);
setup is documented in `docs/local-docker.md`. Requires Grafana 9.0+ (satisfied
by the pinned 11.5.2). It is deliberately **not** shipped as a stack container
(the user chose tooling-only), and production uses its own Grafana or Grafana
Cloud's MCP.

## Out of scope (this issue)

- Embedding/proxying Grafana panels (link-out only).
- Caller/callee service-graph topology (a monolith has little to show).
- Tracing on Strato or any external Tempo target.
- Frontend error logging (#984).
