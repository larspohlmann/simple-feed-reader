# Remove OpenTelemetry and Pyroscope (#1328) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove OpenTelemetry tracing (Tempo) and Excimer/Pyroscope profiling from the whole stack: code, dependencies, containers, installer, CI and docs. Grafana keeps working with Loki logs only.

**Architecture:** Five tasks, one commit each. Profiling goes first, because `RequestProfilingListener` is the only `src` consumer of the OTel `Span` API besides `OtelTraceContext`. Tracing and the Composer packages go second. The frontend, the infrastructure and the docs follow. Nothing replaces `#[WithSpan]`; the issue records why.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine Migrations, PHPUnit 12, Angular 20 + Jest, Docker Compose, bash installer.

**Spec:** GitHub issue #1328 (`gh issue view 1328`); CLAUDE.md.

## Global Constraints

- Clean Code rules from CLAUDE.md apply to every PHP line touched; every touched `src` file is PHPMD-clean.
- No new comment unless it clears CLAUDE.md's comment bar. Delete comments that only explained the removed parts.
- Old plans and specs under `docs/superpowers/` and `CHANGELOG.md` are history and stay untouched.
- Volumes `tempo-data` and `pyroscope-data` are never deleted by a script. The docs name the command.
- Stale `.env.prod` lines (`PYROSCOPE_PUSH_URL`, `OTEL_*`) are left in place; nothing reads them.

## Decisions taken while planning

1. **Upgrade cleanup is `--remove-orphans`.** Verified on Compose v5.3.0 with a throwaway project: `up -d --remove-orphans` removed a service deleted from the file and kept a running service that was only disabled by profile. So `prod-start.sh` and the dev branch of `update.sh` add the flag. No per-service list to maintain.
2. **`EffectiveGrafanaSettings` becomes `ResetInterface`.** Today the worker re-reads the Grafana row every 30 s only because `WorkerProfilingListener` calls `refresh()`. With the listener gone, the Loki settings would stay frozen for the worker's whole `--time-limit` hour. Messenger's `ResetServicesListener` resets services after every handled message (on `WorkerRunningEvent` when not idle), so `reset()` clearing the memo keeps admin changes reaching the worker (from the shared cache, not the database). `refresh()` is folded into `reset()`; `#[ProcessLifetimeState]` goes.
3. **`nyholm/psr7` and `php-http/discovery` go too.** Both arrived in #983 for the OTel SDK (`git show 912e78805`); `composer why` shows no other dependant. `composer remove` lets Flex unconfigure `config/packages/http_discovery.yaml`.
4. **The *Application performance* dashboard is deleted.** All nine panels read Tempo or Pyroscope.

---

### Task 1: Remove profiling (backend)

**Files:**
- Delete: `backend/src/Service/Profiling/` (whole module), `backend/src/EventListener/RequestProfilingListener.php`, `backend/src/EventListener/WorkerProfilingListener.php`, `backend/src/Service/Grafana/SettingsPyroscopeEndpoint.php`, `backend/tests/PhpStan/stubs/excimer.stub`
- Delete tests: `backend/tests/Service/Profiling/` (whole dir), `backend/tests/EventListener/RequestProfilingListenerTest.php`, `backend/tests/EventListener/WorkerProfilingListenerTest.php`, `backend/tests/Functional/RequestProfilingTest.php`, `backend/tests/Service/Grafana/SettingsPyroscopeEndpointTest.php`, `backend/tests/Support/RecordingProfileSampler.php`, `backend/tests/Support/TrackingProfileSampler.php`
- Create: `backend/migrations/Version20261001120000.php`
- Modify: `backend/src/Entity/GrafanaSettings.php`, `backend/src/Entity/GrafanaConnection.php`, `backend/src/Service/Grafana/{EffectiveGrafanaSettings,GrafanaEnvDefaults,GrafanaSettings}.php`, `backend/src/Service/Grafana/Model/{GrafanaSettingsSnapshotModel,GrafanaSettingsOverviewModel}.php`, `backend/src/Dto/Admin/GrafanaSettingsRequest.php`, `backend/src/Http/Admin/GrafanaSettingsJson.php`, `backend/config/services.yaml`, `backend/config/packages/cache.yaml`, `backend/phpstan.dist.neon`, `backend/infection.json5`, `backend/.env`
- Modify tests: `backend/tests/Support/SettingsRequests.php`, `backend/tests/Support/BuildsEffectiveGrafanaSettings.php`, `backend/tests/Controller/Admin/AdminGrafanaControllerTest.php`, `backend/tests/Dto/Admin/GrafanaSettingsRequestTest.php`, `backend/tests/Entity/GrafanaSettingsTest.php`, `backend/tests/Http/Admin/GrafanaSettingsJsonTest.php`, `backend/tests/Service/Grafana/{EffectiveGrafanaSettingsTest,GrafanaSettingsCacheTest,GrafanaSettingsTest}.php`, `backend/tests/Service/Grafana/Model/GrafanaSettingsSnapshotModelTest.php`

**Interfaces produced:**
- `new GrafanaConnection(?string $lokiPushUrl, ?string $lokiUsername, ?string $grafanaUrl)`
- `new GrafanaEnvDefaults(string $lokiPushUrl, string $grafanaUrl)`
- `new GrafanaSettingsOverviewModel(GrafanaSettingsSnapshotModel $stored, GrafanaEnvDefaults $defaults)`
- `new GrafanaSettings($storedSettings, $entityManager, $cipher, $effective, $defaults)` (no sampler)
- `EffectiveGrafanaSettings implements ResetInterface`: `stored()`, `reset()`, `forgetStored()`, `effectiveLokiPushUrl()`, `lokiUsername()`, `lokiToken()`
- `GrafanaSettingsJson::from()` keys: `lokiPushUrl, lokiPushUrlDefault, lokiPushUrlEffective, lokiUsername, grafanaUrl, grafanaUrlDefault, grafanaUrlEffective, hasToken, tokenHint, containerPresent`
- `PUT /api/admin/grafana` body: `lokiPushUrl, lokiUsername, grafanaUrl` (required), `token`, `removeToken` (optional)

- [ ] **Step 1: Delete the profiling files** listed above (`git rm -r`).

- [ ] **Step 2: Trim the Grafana value types.**
  - `GrafanaConnection`: drop `$pyroscopePushUrl` and `$profilingEnabled`.
  - `GrafanaSettings` entity: drop both columns, `isProfilingEnabled()`, `getPyroscopePushUrlOverride()`, and their lines in `connection()` and `applyWithoutToken()`.
  - `GrafanaEnvDefaults`: drop `$pyroscopePushUrl` and its `#[Autowire]`.
  - `GrafanaSettingsSnapshotModel`: drop the two keys from `toCacheEntry()` and from `connectionFromArrayOrNull()`. A pool entry written by the old release still carries them; extra keys are ignored, so it reads back.
  - `GrafanaSettingsOverviewModel`: drop `$profilerAvailable`.
  - `GrafanaSettings` service: drop the `ProfileSamplerInterface` dependency; `overview()` builds `new GrafanaSettingsOverviewModel($this->effective->stored(), $this->defaults)`.
  - `GrafanaSettingsRequest`: drop both properties and their constructor arguments to `GrafanaConnection`.
  - `GrafanaSettingsJson`: drop the six profiling keys from the array and its `@return` shape.

- [ ] **Step 3: Make `EffectiveGrafanaSettings` resettable.**

```php
use Symfony\Contracts\Service\ResetInterface;

final class EffectiveGrafanaSettings implements ResetInterface
{
    private ?GrafanaSettingsSnapshotModel $memoised = null;

    // constructor unchanged

    public function stored(): GrafanaSettingsSnapshotModel
    {
        return $this->memoised ??= $this->cache->remember($this->loadSingleton(...));
    }

    public function reset(): void
    {
        $this->memoised = null;
    }

    public function forgetStored(): void
    {
        $this->cache->forget();
        $this->reset();
    }
    // effectiveLokiPushUrl(), lokiUsername(), lokiToken(), loadSingleton(), defaultOrNull() unchanged;
    // effectivePyroscopePushUrl() and profilingEnabled() deleted
}
```

- [ ] **Step 4: Wiring and config.**
  - `services.yaml`: delete the three `App\Service\Profiling\…` aliases (lines 231–236).
  - `cache.yaml`: the `grafana.settings.cache` comment becomes `# The Grafana singleton row (#1012), so Loki's endpoint reads stop querying the database. EffectiveGrafanaSettings forgets it on every admin save.` (wrapped like its neighbours).
  - `phpstan.dist.neon`: delete the excimer stub entry and its comment.
  - `infection.json5`: delete the `ExcimerSampler` exclude and its comment. If `excludes` is then empty, delete the key.
  - `backend/.env`: delete `PYROSCOPE_PUSH_URL=`.

- [ ] **Step 5: Migration** `backend/migrations/Version20261001120000.php`. It mirrors `Version20260912075325` in reverse: same platform branches, same `isTransactional(): false`.

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PLATFORM-AWARE DDL: SQLite drops one column per ALTER, and tests never run a migration, so a dialect error
 * here is caught only by CI's migrate-from-empty leg.
 */
final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop profiling_enabled and pyroscope_push_url from grafana_settings (#1328)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE grafana_settings DROP profiling_enabled, DROP pyroscope_push_url');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE grafana_settings DROP COLUMN profiling_enabled');
            $this->addSql('ALTER TABLE grafana_settings DROP COLUMN pyroscope_push_url');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the grafana_settings profiling migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE grafana_settings ADD profiling_enabled TINYINT DEFAULT 0 NOT NULL, ADD pyroscope_push_url VARCHAR(255) DEFAULT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE grafana_settings ADD COLUMN profiling_enabled BOOLEAN DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE grafana_settings ADD COLUMN pyroscope_push_url VARCHAR(255) DEFAULT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the grafana_settings profiling migration.');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
```

- [ ] **Step 6: Tests.** Several tests used `profilingEnabled` as their witness bit; they switch to `lokiUsername`, which also comes from the row and has no env default.
  - `SettingsRequests::grafana()`: drop both parameters and named arguments.
  - `BuildsEffectiveGrafanaSettings`: both defaults become `new GrafanaEnvDefaults('', '')`.
  - `AdminGrafanaControllerTest`:
    - `grafanaBody()` loses both keys.
    - Delete `testAdminCanRoundTripTheProfilingToggleAndPyroscopeUrl`.
    - `grafanaBodyKeys()` loses both yields.
    - `testAPutLeavingSettingsOutIsRefusedAndStoresNothing`: the first PUT sends `['lokiPushUrl' => 'https://loki.example/push']`. The incomplete body sets `lokiUsername => 'tenant7'` and unsets `grafanaUrl` and `lokiPushUrl`. Expected errors `['lokiPushUrl', 'grafanaUrl']`; the stored `lokiPushUrl` stays `'https://loki.example/push'` and the stored `lokiUsername` stays null.
  - `GrafanaSettingsRequestTest`:
    - Delete the three `PyroscopePushUrl…` tests.
    - The direct `new GrafanaSettingsRequest(...)` at line 24 loses both arguments.
    - `testToUpdateCarriesTheOverridesAndTheProfilingSwitch` becomes `testToUpdateCarriesTheOverrides`, without the Pyroscope and profiling lines.
    - `testToUpdateTurnsBlankOverridesIntoNone` drops its Pyroscope and profiling lines.
  - `GrafanaSettingsTest` (entity): drop the profiling and Pyroscope assertions and constructor arguments.
  - `GrafanaSettingsJsonTest`:
    - Delete both `testProfiling…` tests.
    - `GrafanaEnvDefaults` takes two arguments.
    - `GrafanaSettingsOverviewModel` loses its third argument.
    - `GrafanaConnection` takes three arguments.
  - `EffectiveGrafanaSettingsTest`:
    - Delete `PYROSCOPE_DEFAULT` and every Pyroscope assertion.
    - `testProfilingAndTheLokiUsernameComeFromTheRow` becomes `testTheLokiUsernameComesFromTheRow`.
    - `testWithNoRowProfilingIsOff…` becomes `testWithNoRowThereIsNoUsernameOrToken`.
    - `testRefreshAlone…` becomes `testResetAloneKeepsServingTheCachedRowWithoutQueryingAgain`, calling `lokiUsername()` and `reset()`.
    - `testForgetStored…` calls `lokiUsername()`.
    - The memo test becomes `testTheMemoKeepsServingTheOldValueUntilResetRereadsTheInvalidatedCache`. Its witness is `lokiUsername()`: null before, `'tenant42'` after, from `new GrafanaConnection(null, 'tenant42', null)`.
  - `GrafanaSettingsCacheTest`: `enabledSnapshot()` becomes `configuredSnapshot()` with `new GrafanaConnection(null, 'tenant42', null)`; assert `->connection->lokiUsername === 'tenant42'` via `assertSame`.
  - `GrafanaSettingsTest` (service):
    - Drop the sampler imports and parameter, the six keys in the `viewOf` shape, and `testTheViewReportsWhetherTheProfilerIsAvailable`.
    - The cache-invalidation test saves `lokiUsername: 'tenant42'` and asserts the worker's `lokiUsername()` is null before and `'tenant42'` after `reset()`. Its docblock says "re-reads the row in its own process" instead of "re-checks the toggle".
  - `GrafanaSettingsSnapshotModelTest`:
    - Drop both keys from the valid entry.
    - Delete the `'profiling flag is not a bool'` case.
    - `GrafanaConnection` takes three arguments.

- [ ] **Step 7: Verify.**
  - From `backend/`, `git grep -n -i -E 'profil(ing|er)Available|profilingEnabled|pyroscope|excimer|ProfileSampler' -- src tests config .env phpstan.dist.neon infection.json5` prints nothing. Positive control: the same grep at `HEAD~0` before the step printed hits.
  - Run `php bin/phpunit`: green.
  - Run `bin/console cache:warmup && composer stan`: green.
  - Run `composer cs`, `composer md`, `composer tramp`: green.
  - Run `bin/console doctrine:schema:validate --skip-sync`, then on a scratch SQLite DB `DATABASE_URL=sqlite:///$PWD/var/migrate-check.db bin/console doctrine:migrations:migrate -n && … doctrine:schema:validate`: in sync. Delete the scratch file.

- [ ] **Step 8: Commit** `refactor(#1328): remove Pyroscope profiling from the backend`.

### Task 2: Remove tracing and the OTel packages (backend)

**Files:**
- Delete: `backend/src/Service/Logging/TraceContext/` (both files), `backend/tests/Service/Logging/TraceContext/OtelTraceContextTest.php`, `backend/tests/Service/Tracing/TracedServiceMethodsTest.php`, `backend/tests/ObservabilityBundleGateTest.php`
- Modify: `backend/src/Service/Logging/RequestLogProcessor.php`, `backend/config/services.yaml` (TraceContext alias), the 17 `#[WithSpan]` files, `backend/composer.json`, `backend/composer.lock`, `backend/symfony.lock`, `backend/tests/Service/Logging/{RequestLogProcessorTest,JsonLogFormatTest}.php`
- Removed by Flex: `backend/config/packages/http_discovery.yaml`

- [ ] **Step 1: `RequestLogProcessor`** keeps only the request id:

```php
final readonly class RequestLogProcessor implements ProcessorInterface
{
    public function __construct(private RequestIdProvider $requestId)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, 'request_id' => $this->requestId->current()]);
    }
}
```

`RequestLogProcessorTest` loses the trace cases and both anonymous `TraceContextInterface` builders. The client-errors case also goes, because the channel no longer behaves differently. It keeps one test that the request id lands in `extra` and one that existing `extra` keys survive (add the second if absent). `JsonLogFormatTest` builds the processor without the trace context.

- [ ] **Step 2: Remove `#[WithSpan]`** and its `use OpenTelemetry\API\Instrumentation\WithSpan;` from the 17 files listed by `git grep -l WithSpan -- backend/src`. Delete `TracedServiceMethodsTest` and `ObservabilityBundleGateTest`, and the services.yaml `TraceContextInterface` alias.

- [ ] **Step 3: Composer.**
  - In `composer.json`, delete the `ext-opentelemetry` platform pin and the `php-http/discovery` allow-plugin.
  - The four scripts lose their `@putenv OTEL_PHP_DISABLED_INSTRUMENTATIONS=all` line. `test` becomes `"@php bin/phpunit"`, `test:parallel` becomes `"paratest"`, `infection` and `infection:diff` become plain strings.
  - Then run `composer remove open-telemetry/exporter-otlp open-telemetry/opentelemetry-auto-doctrine open-telemetry/opentelemetry-auto-symfony open-telemetry/sdk nyholm/psr7`.
  - Confirm `config/packages/http_discovery.yaml` is gone. Confirm `composer show | grep -E 'open-telemetry|php-http|protobuf|tbachert|ramsey|nyholm'` prints nothing.

- [ ] **Step 4: Verify.**
  - `git grep -n -i -E 'opentelemetry|OTEL_|WithSpan|TraceContext|trace_id|span_id' -- backend ':!backend/composer.lock'` prints nothing.
  - Run `php bin/phpunit 2>&1 | tail`: green, with no OTel autoload warning on STDERR.
  - Run `composer check` and `composer md`: green.
  - Run `composer infection:diff`: it must start without the putenv (it used to abort on STDERR).

- [ ] **Step 5: Commit** `refactor(#1328): remove OpenTelemetry tracing and its packages`.

### Task 3: Frontend Grafana section

**Files:** `frontend/src/app/settings/admin/grafana/{grafana-section.component.html,.ts,.spec.ts,grafana-settings.service.ts,.spec.ts}`, `frontend/public/i18n/{en,de}.json`

- [ ] **Step 1:**
  - Delete the third `<app-settings-group icon="speed">` block from the template.
  - Component: delete `pyroscopePushUrl`, `profilingEnabled`, `profilerAvailable`, `profilingContainerPresent`, `pyroscopePushUrlDefault`, `pyroscopePushUrlEffective`, `onPyroscopePushUrl()` and `onProfilingToggled()`. Drop the `ToggleComponent` import. The class doc ends `…from #541. Every field is typed and waits behind the shared save bar.`
  - Service: delete the six state fields and the two body fields. `TypedGrafanaEdits = Partial<Omit<SaveGrafanaSettings, 'removeToken'>>`; `bodyFromState` drops the two lines.
  - Delete `settings.grafana.profiling` from both i18n files.

- [ ] **Step 2: Specs.**
  - Drop the fields from both `state()` fixtures and the expected PUT bodies.
  - Delete the profiling/Pyroscope helpers and the five profiling `it`s (lines 277–343).
  - `'renders all three groups'` becomes `'renders both groups'` and asserts `not.toContain('Profiling')`.
  - The hide-hint test passes only `containerPresent: false`.

- [ ] **Step 3: Verify.**
  - `git grep -n -i -E 'profil|pyroscope' -- frontend/src/app/settings/admin/grafana frontend/public/i18n | grep -i -E 'pyroscope|grafana.profiling|profilingEnabled|profiler'` prints nothing.
  - Run `docker compose exec -T frontend npm test -- grafana` and `docker compose exec -T frontend npm run check`: green.

- [ ] **Step 4: Commit** `refactor(#1328): drop the profiling settings from the Grafana section`.

### Task 4: Containers, installer, CI

**Files:** `docker-compose.yml`, `docker-compose.prod.yml`, `docker/php/Dockerfile`, `docker/php/conf.d/{app,prod}.ini`, `docker/grafana/provisioning/datasources/{loki,tempo,pyroscope}.yaml`, `docker/grafana/dashboards/application-performance.json`, `docker/tempo/`, `deploy/strato/.htaccess`, `.github/workflows/{ci,e2e-rot-check}.yml`, `scripts/{lib.sh,prod-start.sh,update.sh}`, `scripts/test/{configure-grafana,observability-build-arg}.test.sh`, `.env.prod.example`

- [ ] **Step 1: Compose.**
  - Dev: delete the `tempo` and `pyroscope` services, their volumes, and on `php` and `worker` the `PYROSCOPE_PUSH_URL` line, the OTEL comment and the nine `OTEL_*` lines.
  - Prod: the same in `x-app-environment`, both services and volumes, and `WITH_OBSERVABILITY` with its comment on `php` and `worker` build args.
  - The loki service comment and the grafana-profile comment no longer mention tracing.

- [ ] **Step 2: Image.**
  - Dev stage: `install-php-extensions pdo_mysql intl opcache zip xdebug gd`.
  - Prod stage: `RUN install-php-extensions pdo_mysql intl opcache zip gd && apk add --no-cache su-exec`. Delete `ARG WITH_OBSERVABILITY` and the comment above it; keep the su-exec sentence.
  - Delete the `opentelemetry.attr_hooks_enabled` line and its comment from both ini files.

- [ ] **Step 3: Grafana.**
  - `git rm` `tempo.yaml`, `pyroscope.yaml`, `application-performance.json` and `docker/tempo/`.
  - `loki.yaml` loses its whole `jsonData.derivedFields` block.
  - Confirm with `git grep -n -i 'application-performance\|tempo\|pyroscope' -- docker` that nothing is left.

- [ ] **Step 4: Strato and CI.**
  - Delete the OTel `<IfModule mod_env.c>` block and its comment from `.htaccess`.
  - `ci.yml`: delete the Infection step's `env:` and its comment, and the `observability_build_arg` step with its comment.
  - `e2e-rot-check.yml`: delete the two `OTEL_*` lines and their comment; the `env:` block keeps `MAILER_FORCE_FALLBACK`.

- [ ] **Step 5: Installer.**
  - `lib.sh`: delete `export_observability_build_arg` with its comment. `stop_disabled_grafana_containers` names `loki grafana`; its comment already says "the loki+grafana pair".
  - `configure_grafana` drops the extensions line.
  - `use_no_grafana` and `use_grafana` drop `PYROSCOPE_PUSH_URL`, the OTEL lines and their comments.
  - `prod-start.sh`: delete the `export_observability_build_arg` call with its comment, and add `--remove-orphans` to `prod_compose up -d --build`. Comment: `# --remove-orphans drops containers of services this file no longer defines (tempo and pyroscope, #1328); a service only switched off by profile is kept.`
  - `update.sh` dev branch: `compose up -d --build --remove-orphans`.
  - `git rm scripts/test/observability-build-arg.test.sh`. In `configure-grafana.test.sh`, delete every `PYROSCOPE_PUSH_URL` and `OTEL_*` assertion.
  - `.env.prod.example`: delete `PYROSCOPE_PUSH_URL=` and the OTEL block with its comment.

- [ ] **Step 6: Verify.**
  - Run `scripts/test/configure-grafana.test.sh` and every other `scripts/test/*.test.sh`: PASS.
  - Run `shellcheck scripts/*.sh scripts/test/*.sh`: no findings.
  - Run `docker compose config -q` and `docker compose -f docker-compose.prod.yml --env-file .env.prod.example config -q`: valid (the prod check needs the `:?` values; pass them inline if it refuses).
  - `git grep -n -i -E 'otel|opentelemetry|excimer|pyroscope|tempo\b|WITH_OBSERVABILITY' -- docker docker-compose*.yml deploy .github scripts .env.prod.example` prints nothing.
  - Rebuild the dev stack: `docker compose up -d --build --remove-orphans`. `docker compose ps` shows no tempo or pyroscope. `docker compose exec php php -m | grep -i -E 'opentelemetry|excimer'` prints nothing.
  - Apply the migration to the dev DB: `docker compose exec php bin/console doctrine:migrations:migrate -n`.
  - Run the MySQL leg: `docker compose exec php composer test`.
  - Smoke the admin Grafana page and an API request.
  - Scan the newest `backend/var/log/dev-*.log` for errors.

- [ ] **Step 7: Commit** `chore(#1328): remove the tempo and pyroscope containers and the OTel workarounds`.

### Task 5: Docs and memory

**Files:** `CLAUDE.md`, `README.md`, `docs/local-docker.md`, `docs/docker-production.md`

- [ ] **Step 1:**
  - `CLAUDE.md`: `composer test` reads `# phpunit — use this in Docker (see below)`. The "Run the MySQL leg with `composer test`" paragraph goes. The commands note keeps only that `composer test -- --filter=Foo` passes args. Dev runs "Grafana … and Loki, for viewing app logs."
  - `README.md:171`: "It runs Loki for logs beside Grafana;".
  - `docs/docker-production.md`: the stack intro, the Grafana question (Loki and Grafana only; no extensions sentence) and §8 volumes (`loki-data` and `grafana-data`). Add one line for upgraded installs: `docker volume rm simple-feed-reader-prod_tempo-data simple-feed-reader-prod_pyroscope-data` frees the old trace and profile data.
  - `docs/local-docker.md`: the services table (delete the Tempo and Pyroscope rows; the Grafana row names the "Application logs" dashboard only), the `composer test` reasoning, the #1262 tracing paragraph in the e2e rot section, the "(such as #1262's tracing gates)" aside, and both the "Profiling (Pyroscope)" and "Application performance dashboard" sections.

- [ ] **Step 2: Verify.** `git grep -n -i -E 'otel|opentelemetry|excimer|pyroscope|tempo\b|tracing|WithSpan' -- CLAUDE.md README.md docs ':!docs/superpowers'` prints nothing.

- [ ] **Step 3: Commit** `docs(#1328): drop tracing and profiling from the docs`.

- [ ] **Step 4: Memory.** Delete `otel-no-extension-autoload-warning.md` and its MEMORY.md line. Correct any other memory that states OTel or Pyroscope as current.

### Finish

- [ ] Both legs green (`php bin/phpunit`, `docker compose exec php composer test`).
- [ ] `composer infection:diff` green.
- [ ] Push, then open the PR into `develop` with `Closes #1328`.
- [ ] The PR body lists the deploy-time effects: the migration, the removed containers, and the leftover volumes.
