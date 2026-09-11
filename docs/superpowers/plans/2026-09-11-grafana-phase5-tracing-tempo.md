# Grafana Phase 5 — OpenTelemetry tracing + Tempo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** On dev + Docker-prod (never Strato), produce a per-request span waterfall in Grafana Tempo, correlated to the logs, via OpenTelemetry auto-instrumentation — without breaking CI or the Strato release build, which run without the `opentelemetry` extension.

**Architecture:** Add the OTel SDK + OTLP exporter + `opentelemetry-auto-symfony` to `require`, pin `config.platform.ext-opentelemetry` so composer resolves everywhere, and register the auto-instrumentation bundle ONLY when `extension_loaded('opentelemetry')`. The Docker images install the real extension. An `OtelTraceContext` (pure-PHP, reads the active span, returns null when none) replaces `NullTraceContext` behind the Phase-1 seam, so logs carry `trace_id`/`span_id` wherever tracing runs. A `tempo` container (dev: opt-in profile; prod: part of the `grafana` profile) receives OTLP; Grafana is provisioned with a Tempo datasource and a Loki→Tempo correlation.

**Tech Stack:** OpenTelemetry PHP (SDK + auto-symfony + `ext-opentelemetry`), Grafana Tempo, Docker.

**Spec:** `docs/superpowers/specs/2026-09-11-grafana-observability-design.md`

**Depends on:** Phase 1 (`TraceContext` seam), Phase 4 (Loki+Grafana stack, the `grafana` profile, provisioning tree).

## Global Constraints

- **CI and the Strato release build run WITHOUT `ext-opentelemetry`** and MUST stay green: one committed `composer.lock`, `config.platform.ext-opentelemetry` so resolution never needs the real extension, and the bundle registered only when the extension is actually loaded.
- Strato must NEVER activate tracing (no extension there; the bundle stays unregistered).
- `OtelTraceContext` is pure PHP and degrades to null when no span is active — binding it is safe with or without the extension.
- 100% head sampling; OTLP to the internal Tempo only (`http://tempo:4318`); Tempo trace retention 72h.
- Dev tracing is opt-in (a compose profile); prod tracing rides the `grafana` profile.
- Gates green: `composer check`, `composer md`, `php bin/phpunit`, `composer infection:diff`; `docker compose config`.

---

### Task 1: Add the OTel dependencies without breaking no-extension builds

**Files:**
- Modify: `backend/composer.json`
- Modify: `backend/composer.lock` (generated)

- [ ] **Step 1: Pin the platform extension.** In `backend/composer.json` `config.platform`, add alongside `php`:

```json
        "platform": {
            "php": "8.4.0",
            "ext-opentelemetry": "1.0.0"
        }
```

This makes composer treat the extension as present on every machine (CI, Strato build), so requiring the auto bundle (which declares `ext-opentelemetry: *`) resolves without the real extension installed.

- [ ] **Step 2: Require the packages.** Run:

```bash
cd backend && composer require \
  open-telemetry/sdk \
  open-telemetry/exporter-otlp \
  open-telemetry/opentelemetry-auto-symfony \
  --no-scripts
```

The SDK/exporter need a PSR-18 client + PSR-17 factories; `symfony/http-client` (already present) provides PSR-18 via `psr18` and `nyholm/psr7` or `symfony/psr-http-message-bridge` provides factories. If composer reports a missing PSR-17/18 implementation, add `symfony/http-client` is already there — also require `nyholm/psr7` if the resolver asks. Let composer resolve; verify it did NOT need `--ignore-platform-req` (the platform pin should make it clean).

- [ ] **Step 3: Prevent Flex from registering the bundle automatically.** The bundle may ship a Symfony Flex recipe that writes `config/bundles.php`. We register it CONDITIONALLY ourselves (Task 2), so if Flex added an unconditional line to `bundles.php`, remove it (Task 2 replaces it with the gated form). Check `git diff config/bundles.php` after the require.

- [ ] **Step 4: Prove no-extension resolution + boot.** On this machine (no `opentelemetry` extension):

```bash
cd backend && composer validate --no-check-publish \
  && composer install --dry-run 2>&1 | grep -vi 'nothing to install' \
  && php bin/console cache:clear --env=prod \
  && php bin/console cache:clear --env=dev \
  && php bin/phpunit --testsuite=... # or the full suite
```

Expected: cache clears (kernel boots) in both envs WITHOUT the extension, because the bundle is not yet registered. The full suite stays green.

- [ ] **Step 5: Commit**

```bash
git add backend/composer.json backend/composer.lock
git commit -m "feat(#983): add OpenTelemetry SDK and Symfony auto-instrumentation, platform-pinned"
```

---

### Task 2: Register the auto-instrumentation bundle only when the extension is loaded

**Files:**
- Modify: `backend/config/bundles.php`
- Test: `backend/tests/ObservabilityBundleGateTest.php`

- [ ] **Step 1: Write the failing test.** Assert the gate expression's behavior directly (the test can call the same `extension_loaded` gate the file uses, but more valuably assert the app kernel boots in the test env — which has no extension — and does NOT have the OTel bundle registered).

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ObservabilityBundleGateTest extends KernelTestCase
{
    public function testTracingBundleIsAbsentWhenExtensionIsNotLoaded(): void
    {
        if (extension_loaded('opentelemetry')) {
            self::markTestSkipped('extension present; the absent-path gate is what this asserts');
        }

        self::bootKernel();
        $bundles = array_keys(self::$kernel->getBundles());

        self::assertNotContains('OpenTelemetry\\Contrib\\Instrumentation\\Symfony\\OtelSdkBundle', $bundles);
        // The precise bundle FQCN is whatever the package ships; assert no bundle
        // whose name contains "OpenTelemetry" is registered without the extension.
        foreach ($bundles as $bundle) {
            self::assertStringNotContainsString('OpenTelemetry', $bundle);
        }
    }
}
```

Adjust the assertion to the real bundle class name the package provides (find it after Task 1 via `grep -ri "class.*Bundle" vendor/open-telemetry/opentelemetry-auto-symfony/` or the package's `composer.json` `extra.symfony.bundles`).

- [ ] **Step 2: Gate the registration.** Edit `backend/config/bundles.php` to append the bundle conditionally. bundles.php returns a plain array, so:

```php
<?php

$bundles = [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    // ... existing entries unchanged ...
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
];

// The OpenTelemetry auto-instrumentation bundle depends on the `opentelemetry`
// PECL extension (zend_observer hooks). CI and the Strato release build run
// without it; registering it there would fail to boot. Gate it on the
// extension actually being loaded — the Docker images install it, Strato never
// does, so tracing self-activates only where it can work.
if (extension_loaded('opentelemetry')) {
    $bundles[\OpenTelemetry\Contrib\Instrumentation\Symfony\OtelSdkBundle::class] = ['all' => true];
}

return $bundles;
```

Use the REAL bundle FQCN discovered in Step 1.

- [ ] **Step 3: Run + boot check.**

```bash
cd backend && php bin/phpunit tests/ObservabilityBundleGateTest.php \
  && php bin/console lint:container --env=dev \
  && php bin/console lint:container --env=prod
```

Expected: pass; both containers lint clean without the extension (bundle absent).

- [ ] **Step 4: Commit**

```bash
git add backend/config/bundles.php backend/tests/ObservabilityBundleGateTest.php
git commit -m "feat(#983): register the OTel bundle only when the extension is loaded"
```

---

### Task 3: OtelTraceContext behind the Phase-1 seam

**Files:**
- Create: `backend/src/Service/Logging/OtelTraceContext.php`
- Modify: `backend/config/services.yaml` (rebind `TraceContext`)
- Delete: `backend/src/Service/Logging/NullTraceContext.php` + its test (superseded — OtelTraceContext degrades to null, so NullTraceContext is dead)
- Test: `backend/tests/Service/Logging/OtelTraceContextTest.php`

**Interfaces:** `OtelTraceContext implements App\Service\Logging\TraceContext` — `traceId()`/`spanId()` read the active OTel span and return null when none is valid.

- [ ] **Step 1: Write the failing test.** Without the extension, no span is active, so both return null (the common CI path). If the OTel API offers a way to activate a span in-process (pure PHP, no extension needed for manual spans), add a case that activates a span and asserts the ids come back; otherwise assert the null path and note the active-span path is covered by the Docker smoke.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\OtelTraceContext;
use PHPUnit\Framework\TestCase;

final class OtelTraceContextTest extends TestCase
{
    public function testReturnsNullWhenNoSpanIsActive(): void
    {
        $context = new OtelTraceContext();

        self::assertNull($context->traceId());
        self::assertNull($context->spanId());
    }
}
```

Add, if the pure-PHP API allows activating a span in the test (`OpenTelemetry\SDK\Trace\TracerProvider` + a span scope), a second test asserting non-null ids inside an active span and null after it ends. Verify the exact API against the installed `open-telemetry/api`.

- [ ] **Step 2: Implement.** Read the active span via the OTel API and return the ids only when the span context is valid.

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging;

use OpenTelemetry\API\Trace\Span;

final class OtelTraceContext implements TraceContext
{
    public function traceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    public function spanId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getSpanId() : null;
    }
}
```

Verify `Span::getCurrent()`, `->getContext()`, `->isValid()`, `->getTraceId()`, `->getSpanId()` against the installed `open-telemetry/api` (these are the stable API names as of the 1.x SDK). `Span::getCurrent()` returns a non-recording no-op span with an invalid context when nothing is active, so `isValid()` is the guard.

- [ ] **Step 3: Rebind + delete the null impl.** In `services.yaml` change `App\Service\Logging\TraceContext:` to point at `'@App\Service\Logging\OtelTraceContext'`. Delete `NullTraceContext.php` and `NullTraceContextTest.php`. Grep to confirm nothing else references `NullTraceContext`.

- [ ] **Step 4: Run + gates.**

```bash
cd backend && php bin/phpunit tests/Service/Logging \
  && php bin/console lint:container --env=dev \
  && composer stan
```

Expected: pass; the processor now reads `OtelTraceContext`, which returns null in the no-extension suite (so Phase-1 tests still see no trace ids), matching the existing assertions.

- [ ] **Step 5: Commit**

```bash
git add -A backend/src/Service/Logging backend/config/services.yaml backend/tests/Service/Logging
git commit -m "feat(#983): read trace ids from the active OTel span"
```

---

### Task 4: Install the extension in the Docker images + OTLP config

**Files:**
- Modify: `docker/php/Dockerfile` (dev + prod stages)
- Modify: `docker-compose.yml` (dev php+worker OTel env; tempo behind a `tracing` profile)
- Modify: `docker-compose.prod.yml` (php/worker OTel env via x-app-environment; tempo under the `grafana` profile)

- [ ] **Step 1: Add the extension to both Dockerfile stages.** Append `opentelemetry` to the `install-php-extensions` line in the `dev` stage (line ~18) and the `prod` stage (line ~33):

```dockerfile
RUN install-php-extensions ... opentelemetry
```

(dev also has xdebug; prod does not — just add `opentelemetry` to each existing list.)

- [ ] **Step 2: OTel env — dev.** Add to the `php` AND `worker` `environment:` blocks in `docker-compose.yml`:

```yaml
      OTEL_PHP_AUTOLOAD_ENABLED: "true"
      OTEL_SERVICE_NAME: "simple-feed-reader"
      OTEL_TRACES_EXPORTER: "otlp"
      OTEL_METRICS_EXPORTER: "none"
      OTEL_LOGS_EXPORTER: "none"
      OTEL_EXPORTER_OTLP_PROTOCOL: "http/protobuf"
      OTEL_EXPORTER_OTLP_ENDPOINT: "http://tempo:4318"
      OTEL_TRACES_SAMPLER: "always_on"
```

- [ ] **Step 3: Tempo — dev (opt-in profile).** Add a `tempo` service behind `profiles: ["tracing"]` so a plain `docker compose up` does NOT start it (dev tracing is opt-in); an operator runs `COMPOSE_PROFILES=tracing docker compose up -d`:

```yaml
  tempo:
    image: grafana/tempo:2.7.1
    profiles: ["tracing"]
    command: ["-config.file=/etc/tempo/tempo-config.yaml"]
    volumes:
      - ./docker/tempo/tempo-config.yaml:/etc/tempo/tempo-config.yaml:ro
      - tempo-data:/var/tempo
    ports:
      - "127.0.0.1:4318:4318"
```

Add `tempo-data:` to the dev `volumes:` block. Because the OTel env points at `http://tempo:4318` unconditionally, confirm the exporter fails open when the profile is off (the extension retries/drops silently; a failed export must never break a request — verify in the Step 6 smoke that the app is unaffected with tracing off).

- [ ] **Step 4: Tempo — prod (rides the grafana profile).** Add a `tempo` service to `docker-compose.prod.yml` with `profiles: ["grafana"]` (same profile as loki+grafana, so enabling observability enables tracing too, per Q19), `restart: unless-stopped`, the config mount, and `tempo-data` named volume. Add the OTel env vars to `x-app-environment` (so php+worker export), but gate the ENDPOINT so a non-grafana install does not try to export: set `OTEL_PHP_AUTOLOAD_ENABLED: ${OTEL_PHP_AUTOLOAD_ENABLED:-false}` and have the installer set it true with the grafana profile — OR simpler, rely on the bundle being unregistered when the extension is absent. On Docker-prod the extension IS present, so gate autoload on the grafana profile: `OTEL_PHP_AUTOLOAD_ENABLED: ${GRAFANA_TRACING_ENABLED:-false}` and set `GRAFANA_TRACING_ENABLED=true` in `use_grafana` (Phase 4 installer). Decide the cleanest gate and keep it consistent with Phase 4's env model; document it in the compose comment.

- [ ] **Step 5: Tempo config** (`docker/tempo/tempo-config.yaml`) — single-binary, OTLP http receiver on 4318, 72h retention:

```yaml
server:
  http_listen_port: 3200

distributor:
  receivers:
    otlp:
      protocols:
        http:
          endpoint: 0.0.0.0:4318

storage:
  trace:
    backend: local
    local:
      path: /var/tempo/blocks
    wal:
      path: /var/tempo/wal

compactor:
  compaction:
    block_retention: 72h
```

Verify keys against `grafana/tempo:2.7.x`.

- [ ] **Step 6: Validate.**

```bash
docker compose config >/dev/null && echo dev-ok
docker compose -f docker-compose.prod.yml --env-file .env.prod config >/dev/null && echo prod-ok
```

Live smoke (needs the images rebuilt with the extension):

```bash
docker compose build php worker
COMPOSE_PROFILES=tracing docker compose up -d
# exercise an endpoint, then check Tempo received a trace:
curl -sf http://localhost:8443/api/health -k >/dev/null
docker compose exec -T php php -r 'var_dump(extension_loaded("opentelemetry"));'  # true
# query Tempo for recent traces, or check grafana Explore
```

- [ ] **Step 7: Commit**

```bash
git add docker/php/Dockerfile docker/tempo docker-compose.yml docker-compose.prod.yml
git commit -m "feat(#983): install the OTel extension and run Tempo behind a profile"
```

---

### Task 5: Grafana Tempo datasource + Loki→Tempo correlation

**Files:**
- Create: `docker/grafana/provisioning/datasources/tempo.yaml`
- Modify: `docker/grafana/provisioning/datasources/loki.yaml` (derived field → Tempo)

- [ ] **Step 1: Tempo datasource** (`tempo.yaml`), uid `tempo`, url `http://tempo:3200`, tracesToLogs pointing at the Loki datasource on `trace_id`.

```yaml
apiVersion: 1

datasources:
  - name: Tempo
    type: tempo
    access: proxy
    uid: tempo
    url: http://tempo:3200
    editable: false
    jsonData:
      tracesToLogsV2:
        datasourceUid: loki
        filterByTraceID: true
        tags: [{ key: 'trace_id', value: 'trace_id' }]
```

- [ ] **Step 2: Loki → Tempo derived field.** Add to the Loki datasource `jsonData` in `loki.yaml`:

```yaml
    jsonData:
      derivedFields:
        - name: TraceID
          matcherType: label
          matcherRegex: trace_id
          datasourceUid: tempo
          url: '$${__value.raw}'
```

(Match the current Grafana provisioning schema for derived fields; `$$` escapes the `$` in compose-interpolated files — verify whether escaping is needed for a plain mounted file, it is NOT interpolated by compose, so a single `$` is correct: use `url: '${__value.raw}'`.)

- [ ] **Step 3: Smoke.** With `COMPOSE_PROFILES=tracing` up and the extension present, generate a request, open Grafana Explore on Loki, and confirm a log line with `trace_id` shows a "Tempo" link that opens the trace; and the Tempo trace's spans link back to logs.

- [ ] **Step 4: Commit**

```bash
git add docker/grafana/provisioning/datasources
git commit -m "feat(#983): provision Tempo datasource and Loki-Tempo trace correlation"
```

---

## Phase-5 exit checks

```bash
cd backend && composer validate --no-check-publish && composer check && composer md && php bin/phpunit && composer infection:diff
docker compose config >/dev/null && docker compose -f docker-compose.prod.yml --env-file .env.prod config >/dev/null
```

All green WITHOUT the extension (proving CI/Strato stay green). The trace-in-Tempo and log↔trace correlation are the Docker live smokes (Task 4 Step 6, Task 5 Step 3) since they require the extension + Tempo.

## Self-review notes

- Spec coverage: implements "Phase 5" — OTel auto-instrumentation gated on the extension, `config.platform` so CI/Strato resolve, `OtelTraceContext` feeding trace ids into the logs (retiring `NullTraceContext`), Tempo container (dev opt-in / prod on the grafana profile), 72h retention, 100% sampling, Grafana Tempo datasource + Loki→Tempo correlation.
- Risk/verification: the OTel PHP API names and the auto-symfony bundle FQCN/config MUST be verified against the actually-installed versions (Task 1 pulls them); the plan's class/method names are the stable 1.x API but confirm. Tracing behavior itself is only provable in Docker with the extension — the native suite proves the no-extension path (CI/Strato) stays green, which is the load-bearing safety property.
- Type consistency: `OtelTraceContext` implements the Phase-1 `TraceContext` (`traceId()`/`spanId()`), so the `RequestLogProcessor` is unchanged; the env var the installer sets to gate prod autoload must match between Phase 4's `use_grafana` and docker-compose.prod.yml.
