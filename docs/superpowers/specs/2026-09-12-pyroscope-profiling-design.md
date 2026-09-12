# Continuous + per-request PHP profiling with Pyroscope — specification (#993)

**Status:** approved in brainstorming (path A; admin toggle default off; dev + Docker-prod; extra performance dashboard).
**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/993
**Depends on:** #983 (Grafana/Loki/Tempo stack, admin Grafana settings, OTel tracing) and #984 (`source` label). Both merged.

## Goal

Add code-level profiling to the observability stack: always-on **continuous
profiling** of the app and the `messenger:consume` worker, plus **per-request
flame graphs linked from Tempo trace spans** ("code hotspots inside a request").
Controlled by an **admin toggle on the existing Grafana settings page, default
off**. Also add a **performance dashboard** so traces are easy to find, with
aggregated request rate, error rate and latency from Tempo's TraceQL metrics.

## Facts this design rests on (verified 2026-09-12)

- Grafana ships no maintained PHP Pyroscope SDK (PHP support was dropped in
  Pyroscope v1.0). The bridge is ours.
- `ext-excimer` (PHP Foundation sampling profiler) supports PHP 8.4 and is
  installable via the image's existing `install-php-extensions` tool. It is an
  interrupting sampler with negligible overhead (no `zend_execute_ex` hook).
  `setPeriod()` accepts 0.001 s; `EXCIMER_REAL` = wall time; `getLog()` →
  `ExcimerLog::formatCollapsed()` yields folded stacks `a;b;c count`.
- Pyroscope `POST /ingest` (legacy-compatible, still served by
  `pkg/ingester/pyroscope/ingest_handler.go`) accepts query params `name`
  (`app{label=value,…}`), `from`, `until` (unix seconds), `sampleRate` (Hz),
  `spyName`, `units`, `aggregationType`, `format`. With `format` omitted the
  body is the folded "groups" format — exactly what Excimer emits.
- Grafana's trace→profiles link: the span carries attribute
  `pyroscope.profile.id`; Tempo's `tracesToProfiles` runs a Pyroscope query over
  the span's time window. With `customQuery: true` the query may use
  `${__span.spanId}` (confirmed available to trace-to-profiles). Labeling each
  request's samples with `span_id` therefore yields a **request-precise** link.
- Tempo 2.7.1 supports TraceQL metrics (`rate()`, `quantile_over_time()`, …)
  when the metrics-generator `local-blocks` processor is enabled; no Prometheus
  is needed (only `service-graphs`/`span-metrics` need remote-write).
- Grafana datasource type for Pyroscope: `grafana-pyroscope-datasource`, built
  in, no plugin. Pyroscope server image `grafana/pyroscope`, latest release
  `v2.3.1` (2026-09-08), port 4040.
- Strato shared hosting cannot install PECL extensions (confirmed in
  `deploy/strato/README.md`); profiling is inert there, like Tempo tracing.

## Environment applicability

| Environment | Behaviour |
|---|---|
| dev (Docker) | `pyroscope` container on by default (like Tempo); profiling off until the admin toggle is on |
| Docker-prod | `pyroscope` rides the `grafana` compose profile; installer writes `PYROSCOPE_PUSH_URL`; toggle default off |
| Strato | inert: no `excimer` extension → Null sampler; `PYROSCOPE_PUSH_URL` empty → no endpoint. The toggle shows "profiler not available on this host" |

## Global constraints (apply to every phase)

- Symfony 7.4 / PHP 8.4, `declare(strict_types=1)`, Clean Code (CLAUDE.md):
  `final readonly` where possible, thin controllers, guard clauses, ≤3-line
  comments, no boolean-flag parameters, PHPMD-clean touched files.
- Angular 20 standalone + signals; styles in sibling `.scss`; no hex/px/media
  literals outside `theme/`; Transloco keys in `public/i18n/{en,de}.json`.
- Native-iOS-viable API: JSON in/out, bearer auth, no browser-only inputs.
- **Fail-open everywhere:** a dead or absent Pyroscope, a DB error while reading
  the toggle, or a missing extension must never break a request or the worker.
- **No new Composer package.** Excimer is used through its native classes;
  PHPStan gets a stub file. No unconditional autoload → nothing new to silence
  on Strato/CI (contrast: the OTel packages, see memory
  `otel-no-extension-autoload-warning`).
- Quality gates: `composer check`, `composer md`, `php bin/phpunit` (both
  legs), `composer infection:diff` (MSI ≥ gate); frontend `npm run check` in the
  Docker frontend container; installer `scripts/test/configure-grafana.test.sh`;
  CI migration leg (SQLite + MySQL from empty + `schema:validate`).

## Architecture

```
web request:   kernel.request ──► ProfilingPolicy.isEnabled?
                                   yes → ExcimerSampler.start(1 ms)
                                         Span::getCurrent()->setAttribute('pyroscope.profile.id', spanId)
               kernel.terminate ─► sampler.stop() → PyroscopeClient.push(profile, {service_name, process=web, trace_id, span_id})
                                   (after the response is sent; fail-open)

worker:        WorkerStarted ────► policy check → sampler.start(10 ms)
               WorkerRunning ────► every 30 s re-check toggle; every 10 s rotate (stop → push {process=worker} → start)
               WorkerStopped ────► stop → push

Grafana:       Tempo span ──(pyroscope.profile.id + tracesToProfiles customQuery span_id="${__span.spanId}")──► that request's flame graph
               Performance dashboard: TraceQL metrics (rate/error/p95), traces lists, service flame graph
```

## Backend

### `Service/Profiling/` (new)

- `CollapsedProfile` — `final readonly` VO: `collapsedStacks` (string),
  `sampleCount`, `sampleRateHz`, `startedAtUnix`, `endedAtUnix`.
- `ProfileSampler` — interface: `isAvailable(): bool`, `start(float
  $periodSeconds): void`, `stop(): ?CollapsedProfile` (null when not running or
  no samples), `isRunning(): bool`.
- `ExcimerSampler` — thin adapter over `\ExcimerProfiler` (`EXCIMER_REAL`,
  `setMaxDepth(250)`); `isAvailable()` = `extension_loaded('excimer')`.
- `NullProfileSampler` — everything no-op; `isAvailable()` false.
- `ProfileSamplerFactory::create(): ProfileSampler` — picks Excimer when the
  extension is loaded, else Null. Wired in `services.yaml` as the factory for
  the `ProfileSampler` interface.
- `ProfileLabels` — `final readonly` VO built by `forWebRequest(traceId,
  spanId)` / `forWorker()`; always carries `service_name=simple-feed-reader` and
  `process=web|worker`; `toNameParameter('simple-feed-reader')` renders
  `simple-feed-reader{service_name=…,process=…,trace_id=…,span_id=…}`.
- `PyroscopeEndpoint` — interface `pushUrl(): ?string`.
- `PyroscopeClient` — `final readonly`; `push(CollapsedProfile, ProfileLabels)`
  POSTs `{pushUrl}/ingest` with query `name`, `from`, `until`, `sampleRate`,
  `spyName=excimer`, body = folded stacks, `timeout 1.0`, whole body in
  `try/catch (\Throwable)` (fail-open, mirrors `LokiClient`; never injects a
  logger).
- `ProfilingPolicy` — `isEnabled()` = sampler available **and** settings toggle
  on **and** endpoint resolves; any exception → false.

### Settings (extend `Service/Grafana`, entity, DTOs)

- Entity `GrafanaSettings` gains `profiling_enabled` (bool, default false) and
  `pyroscope_push_url` (nullable override). One Doctrine migration.
- `GrafanaConnection` gains `?string $pyroscopePushUrl`, `bool
  $profilingEnabled`; the entity's `apply*` methods set both.
- `GrafanaEnvDefaults` gains `#[Autowire('%env(PYROSCOPE_PUSH_URL)%')] public
  string $pyroscopePushUrl`.
- `GrafanaSettings` service gains `effectivePyroscopePushUrl(): ?string`
  (override ?? non-empty env default) and `profilingEnabled(): bool`; `view()`
  reports the new fields; it receives `ProfileSampler` to report availability.
- `SettingsPyroscopeEndpoint implements PyroscopeEndpoint` (alias in
  `services.yaml`, like `LokiEndpoint`).
- `GrafanaSettingsRequest` gains `?string $pyroscopePushUrl` (URL, ≤255) and
  `bool $profilingEnabled = false`.
- `GrafanaSettingsJson::from(?GrafanaSettings, GrafanaEnvDefaults, bool
  $profilerAvailable)` — signature refactored to take the defaults object —
  adds `pyroscopePushUrl`, `pyroscopePushUrlDefault`,
  `pyroscopePushUrlEffective`, `profilingEnabled`, `profilingContainerPresent`
  (`'' !== default`), `profilerAvailable`.
- `AdminGrafanaController` unchanged in shape (thin; GET/PUT).

### Listeners (`EventListener/`)

- `RequestProfilingListener` — `RequestEvent` (main request, priority 4096) and
  `TerminateEvent` (priority 16, so sampling stops before the Loki flush).
  Starts only when the policy allows **and** a trace is active (`TraceContext`
  ids non-null); sets `pyroscope.profile.id` = span id on `Span::getCurrent()`;
  at terminate stops and pushes with `forWebRequest` labels. Request sample
  period **1 ms**.
- `WorkerProfilingListener` — `WorkerStartedEvent` / `WorkerRunningEvent` /
  `WorkerStoppedEvent`; sample period **10 ms**; rotate every **10 s**; re-read
  the toggle every **30 s** (so a change takes effect without a restart); pushes
  with `forWorker` labels. Uses `Psr\Clock\ClockInterface` for testability.

### Ruling: web profiles require an active trace

A request profile is only valuable through its span link; without a trace
(OTel off) the listener does nothing. Continuous coverage comes from the
worker. Cost if wrong: no aggregate web profiles when tracing is disabled —
acceptable; tracing is on wherever profiling can run.

## Infrastructure

- `docker/php/Dockerfile`: append `excimer` to both `install-php-extensions`
  lines (dev and prod stages).
- `docker-compose.yml`: `pyroscope` service (`grafana/pyroscope:2.3.1`,
  `pyroscope-data:/data`, port `127.0.0.1:4040:4040`), on by default; php +
  worker get `PYROSCOPE_PUSH_URL: "http://pyroscope:4040"`.
- `docker-compose.prod.yml`: `pyroscope` under `profiles: ["grafana"]`,
  `restart: unless-stopped`, volume, no host port; `x-app-environment` gets
  `PYROSCOPE_PUSH_URL: ${PYROSCOPE_PUSH_URL:-}`.
- `backend/.env`: `PYROSCOPE_PUSH_URL=`; `.env.prod.example`: same.
- `scripts/lib.sh`: `use_grafana` sets `PYROSCOPE_PUSH_URL
  'http://pyroscope:4040'`; `use_no_grafana` sets `''`;
  `stop_disabled_grafana_containers` also removes `tempo` and `pyroscope`.
  `configure-grafana.test.sh` asserts the new key both ways.
- Tempo `docker/tempo/tempo-config.yaml`: enable the metrics-generator
  `local-blocks` processor (no remote-write) so TraceQL metrics work.
- Grafana provisioning: `datasources/pyroscope.yaml`
  (`grafana-pyroscope-datasource`, uid `pyroscope`); `tempo.yaml` gains
  `tracesToProfiles` (`datasourceUid: pyroscope`, discovered `profileTypeId`,
  `customQuery: true`, `query: 'span_id="$${__span.spanId}"'` — note the `$$`
  escape, the same provisioning gotcha fixed in #992).
- New dashboard `docker/grafana/dashboards/application-performance.json` (uid
  `application-performance`): request rate by status, error rate, p95 latency,
  p95 by route, slowest traces, error traces, service flame graph; cross-links
  with the Application logs dashboard.
- Strato: nothing to add (no autoload, extension guard, empty push URL).

## Frontend

`settings/admin/grafana/` gains a third `<app-settings-group>` "Profiling":
- `<app-toggle>` bound to `profilingEnabled`, **instant-persist** via
  `saveInstant({ profilingEnabled })` (the page's first instant toggle; typed
  fields stay draft-gated), disabled with a hint when `profilerAvailable` is
  false.
- `pyroscopePushUrl` typed URL field (draft, `data-testid="grafana-pyroscope-push-url"`),
  placeholder from `pyroscopePushUrlDefault`, "Local container: …" hint when
  `profilingContainerPresent`.
- State/body interfaces extended; `TypedGrafanaEdits` excludes
  `profilingEnabled` (it is not a draft field). i18n keys
  `settings.grafana.profiling.*` in `en.json` and `de.json`.

## Testing

- Backend unit: factory picks Null without the extension; `ExcimerSampler`
  adapter test guarded by `#[RequiresPhpExtension('excimer')]` (runs in the
  Docker leg) and excluded from Infection (`infection.json5` `excludes`) because
  mutation testing cannot execute it natively; `PyroscopeClient` request shape +
  fail-open (transport error, endpoint throws, null URL, no-op on null profile);
  `ProfileLabels` rendering; `ProfilingPolicy` truth table incl. exception →
  false; both listeners with stub sampler/client/clock.
- Backend functional: `AdminGrafanaControllerTest` round-trips the new fields;
  a request with the toggle on pushes one profile whose `name` carries the
  request's `span_id`/`trace_id` and sets `pyroscope.profile.id` (captured via
  the `MockHttpClient` container-set trick, stub sampler, a real activated
  span as in `OtelTraceContextTest`).
- Migration verified by the CI migration leg.
- Installer: `configure-grafana.test.sh` new assertions.
- Frontend Jest: toggle PUT body, draft URL, hints, disabled-when-unavailable.
- Manual (dev stack, recorded in the plan): toggle on → request → Tempo span
  shows `pyroscope.profile.id` → **Profiles** opens that request's flame graph;
  worker flame graph on the performance dashboard; toggle off stops pushes;
  `docker compose stop pyroscope` leaves requests at 200.

## Out of scope (v1)

Strato profiling; Grafana Alloy eBPF; SPX; profiling console commands; CPU-time
profiles (wall time only); admin-configurable sample rates.

## Key decisions

1. **Own the bridge** (Excimer → `/ingest` folded format) rather than depend on
   an unmaintained community package.
2. **Request-precise link** via `span_id` label + `pyroscope.profile.id` +
   `customQuery` — the same mechanism the official bridges use.
3. **Toggle gates both start and push**; default off so the 1 ms request rate
   is opt-in.
4. **Extra performance dashboard** rather than growing the logs dashboard.
5. **TraceQL metrics via local-blocks** — no Prometheus.
