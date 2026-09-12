# Pyroscope profiling Implementation Plan (#993)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Continuous + per-request PHP profiling shipped to a Pyroscope container, linked from Tempo spans, gated by an admin toggle (default off), plus a performance dashboard.

**Architecture:** An Excimer-based sampler (guarded on the extension) is started per web request by a kernel listener and continuously by a worker listener; a fail-open `PyroscopeClient` pushes folded stacks to `POST /ingest` labelled with `service_name`/`process`/`trace_id`/`span_id`; the root OTel span gets `pyroscope.profile.id` so Tempo's `tracesToProfiles` opens that request's flame graph. The toggle and push URL live on the existing Grafana admin settings.

**Tech Stack:** PHP 8.4 / Symfony 7.4, `ext-excimer` via `install-php-extensions`, Pyroscope `2.3.1`, Tempo 2.7.1 TraceQL metrics, Grafana 11.5.2, Angular 20.

**Spec:** `docs/superpowers/specs/2026-09-12-pyroscope-profiling-design.md`

**Branch:** `feature/993-pyroscope-profiling` (already created off `develop`). PR body must say `Closes #993`.

## Global Constraints

- `declare(strict_types=1)`; `final readonly` + constructor promotion where possible; guard clauses; ≤3-line comments; no boolean-flag params; every touched `src` file PHPMD-clean; thin controller.
- **Fail-open:** a dead/absent Pyroscope, a DB error reading the toggle, or a missing extension must never break a request or the worker. `PyroscopeClient` never injects a logger.
- **No new Composer package for profiling.** Excimer via native classes + a PHPStan stub. (`symfony/clock` may be added only if absent — Task 6.)
- Frontend: standalone + signals; styles in sibling `.scss`; no hex/px/media literals outside `theme/`; tests run in the Docker frontend container (`docker compose exec -T frontend npm test`).
- Gates before each commit: backend `composer cs && composer stan && composer md && php bin/phpunit`; before PR: `composer infection:diff`, Docker MySQL leg, `npm run check`, `bash scripts/test/configure-grafana.test.sh`.
- Provisioning YAML: any `${…}` meant for Grafana runtime must be written `$${…}` (env-var interpolation gotcha, #992).
- Never deploy. Never `docker compose down -v`.

---

### Task 1: Docker image, containers, env defaults, installer

**Files:**
- Modify: `docker/php/Dockerfile` (both `install-php-extensions` lines)
- Modify: `docker-compose.yml` (php + worker env; new `pyroscope` service; volumes)
- Modify: `docker-compose.prod.yml` (`x-app-environment`; new `pyroscope` service; volumes)
- Modify: `backend/.env`, `.env.prod.example`
- Modify: `scripts/lib.sh` (`use_grafana`, `use_no_grafana`, `stop_disabled_grafana_containers`)
- Modify: `scripts/test/configure-grafana.test.sh`

**Interfaces:**
- Produces: env var `PYROSCOPE_PUSH_URL` (empty = no endpoint); container hostname `pyroscope`, port 4040.

- [ ] **Step 1: Extend the installer test first (it must fail).** In `scripts/test/configure-grafana.test.sh`, in the case that answers `y` add, next to the existing `GRAFANA_LOKI_PUSH_URL` assertion, an assertion that `env_prod_get PYROSCOPE_PUSH_URL` equals `http://pyroscope:4040`; in the `n` case assert it is empty. Mirror the file's existing assertion helper style exactly.

- [ ] **Step 2: Run it, expect failure.** `bash scripts/test/configure-grafana.test.sh` → the two new assertions fail.

- [ ] **Step 3: Installer.** In `scripts/lib.sh`:
  - `use_grafana`: add `env_prod_set PYROSCOPE_PUSH_URL 'http://pyroscope:4040'` after the `GRAFANA_URL` line.
  - `use_no_grafana`: add `env_prod_set PYROSCOPE_PUSH_URL ''` after the `GRAFANA_URL` line.
  - `stop_disabled_grafana_containers`: change both `loki grafana` lists to `loki grafana tempo pyroscope` and the message to `'Grafana is disabled -- removing its containers (data volumes are kept) ...'`.

- [ ] **Step 4: Run the test, expect pass.** `bash scripts/test/configure-grafana.test.sh`.

- [ ] **Step 5: Dockerfile.** Append `excimer` to both lines:
  `RUN install-php-extensions pdo_mysql intl opcache zip xdebug gd opentelemetry excimer` (dev) and `RUN install-php-extensions pdo_mysql intl opcache zip gd opentelemetry excimer \` (prod).

- [ ] **Step 6: dev compose.** In `docker-compose.yml` add `PYROSCOPE_PUSH_URL: "http://pyroscope:4040"` after `GRAFANA_URL` in **both** the `php` and `worker` environment blocks. After the `tempo` service add:
```yaml
  # Continuous profiling store. Receives Excimer samples from the app only while
  # the admin "Profiling" toggle is on (default off); the pusher fails open.
  pyroscope:
    image: grafana/pyroscope:2.3.1
    volumes:
      - pyroscope-data:/data
    ports:
      - "127.0.0.1:4040:4040"
```
  and add `pyroscope-data:` to the top-level `volumes:`.

- [ ] **Step 7: prod compose.** In `docker-compose.prod.yml` add `PYROSCOPE_PUSH_URL: ${PYROSCOPE_PUSH_URL:-}` to `x-app-environment` after `GRAFANA_URL`. After the `tempo` service add:
```yaml
  pyroscope:
    image: grafana/pyroscope:2.3.1
    profiles: ["grafana"]
    restart: unless-stopped
    volumes:
      - pyroscope-data:/data
```
  and `pyroscope-data:` to `volumes:`.

- [ ] **Step 8: env files.** `backend/.env`: add `PYROSCOPE_PUSH_URL=` after `GRAFANA_URL=`. `.env.prod.example`: add `PYROSCOPE_PUSH_URL=` after `GRAFANA_ADMIN_PASSWORD=` block (before `# GRAFANA_PORT`).

- [ ] **Step 9: Rebuild and verify the extension + container.**
```bash
docker compose build php worker && docker compose up -d
docker compose exec -T php php -m | grep -x excimer
docker compose exec -T php sh -c 'wget -qO- http://pyroscope:4040/ready'
```
  Expected: `excimer`; `ready`. Then `docker compose exec -T php sh -c 'ls /data' ` is not needed; instead confirm the volume mounts: `docker inspect $(docker compose ps -q pyroscope) --format '{{range .Mounts}}{{.Destination}} {{end}}'` contains `/data`.

- [ ] **Step 10: Commit.**
```bash
git add docker/php/Dockerfile docker-compose.yml docker-compose.prod.yml backend/.env .env.prod.example scripts/lib.sh scripts/test/configure-grafana.test.sh
git commit -m "feat(#993): excimer in the PHP image, a pyroscope container, and PYROSCOPE_PUSH_URL wiring"
```

---

### Task 2: Grafana settings gain the profiling toggle and push URL

**Files:**
- Modify: `backend/src/Entity/GrafanaSettings.php`
- Create: `backend/migrations/Version<timestamp>.php` (via `doctrine:migrations:diff`)
- Modify: `backend/src/Service/Grafana/GrafanaConnection.php`, `GrafanaEnvDefaults.php`, `GrafanaSettings.php`
- Modify: `backend/src/Dto/Admin/GrafanaSettingsRequest.php`
- Modify: `backend/src/Http/Admin/GrafanaSettingsJson.php`
- Create: `backend/src/Service/Profiling/ProfileSampler.php` (interface only, needed by `GrafanaSettings::view()`; Task 3 adds implementations) and `backend/src/Service/Profiling/NullProfileSampler.php`
- Tests: `backend/tests/Entity/GrafanaSettingsTest.php`, `backend/tests/Service/Grafana/GrafanaSettingsTest.php`, `backend/tests/Dto/Admin/GrafanaSettingsRequestTest.php`, `backend/tests/Http/Admin/GrafanaSettingsJsonTest.php`, `backend/tests/Controller/Admin/AdminGrafanaControllerTest.php`

**Interfaces:**
- Produces: `GrafanaSettings::effectivePyroscopePushUrl(): ?string`, `GrafanaSettings::profilingEnabled(): bool`, `GrafanaEnvDefaults::$pyroscopePushUrl`, `GrafanaConnection::$pyroscopePushUrl` / `$profilingEnabled`, JSON keys `pyroscopePushUrl`, `pyroscopePushUrlDefault`, `pyroscopePushUrlEffective`, `profilingEnabled`, `profilingContainerPresent`, `profilerAvailable`.
- Consumes: `ProfileSampler::isAvailable()` (interface introduced here).

- [ ] **Step 1: Interface + Null sampler (needed by view()).**
```php
<?php
declare(strict_types=1);
namespace App\Service\Profiling;

interface ProfileSampler
{
    public function isAvailable(): bool;
    public function start(float $periodSeconds): void;
    public function stop(): ?CollapsedProfile;
    public function isRunning(): bool;
}
```
```php
<?php
declare(strict_types=1);
namespace App\Service\Profiling;

final class NullProfileSampler implements ProfileSampler
{
    public function isAvailable(): bool { return false; }
    public function start(float $periodSeconds): void { }
    public function stop(): ?CollapsedProfile { return null; }
    public function isRunning(): bool { return false; }
}
```
  and the VO it references:
```php
<?php
declare(strict_types=1);
namespace App\Service\Profiling;

final readonly class CollapsedProfile
{
    public function __construct(
        public string $collapsedStacks,
        public int $sampleCount,
        public int $sampleRateHz,
        public int $startedAtUnix,
        public int $endedAtUnix,
    ) {
    }
}
```
  Register in `backend/config/services.yaml` for now: `App\Service\Profiling\ProfileSampler: '@App\Service\Profiling\NullProfileSampler'` (Task 3 replaces this with the factory).

- [ ] **Step 2: Failing tests.** Add to `GrafanaSettingsJsonTest`: `from()` with a settings row whose override is `'http://custom:4040'`, defaults `pyroscopePushUrl = 'http://pyroscope:4040'`, `profilerAvailable = true`, toggle on → asserts all six new keys (`pyroscopePushUrlEffective === 'http://custom:4040'`, `profilingContainerPresent === true`, `profilingEnabled === true`, `profilerAvailable === true`); and a second case with empty default and no row → `pyroscopePushUrlEffective === null`, `profilingContainerPresent === false`, `profilingEnabled === false`. Add to `GrafanaSettingsRequestTest`: `pyroscopePushUrl` longer than 255 and a non-URL both produce a violation; `profilingEnabled` defaults to false. Add to `GrafanaSettingsTest` (service): `effectivePyroscopePushUrl()` returns override, else non-empty env default, else null; `profilingEnabled()` reflects the row. Add to `AdminGrafanaControllerTest`: PUT `{"profilingEnabled": true, "pyroscopePushUrl": "http://custom:4040", …existing fields…}` then GET shows both, plus `profilerAvailable` is a bool. Run `php bin/phpunit --filter Grafana` → failures.

- [ ] **Step 3: Entity.** In `GrafanaSettings` entity add:
```php
#[ORM\Column(name: 'profiling_enabled', options: ['default' => false])]
private bool $profilingEnabled = false;

#[ORM\Column(name: 'pyroscope_push_url', length: 255, nullable: true)]
private ?string $pyroscopePushUrl = null;

public function isProfilingEnabled(): bool { return $this->profilingEnabled; }
public function getPyroscopePushUrlOverride(): ?string { return $this->pyroscopePushUrl; }
```
  and make the private connection-applying method (used by `apply()` and `applyWithoutToken()`) also set `$this->pyroscopePushUrl = $connection->pyroscopePushUrl; $this->profilingEnabled = $connection->profilingEnabled;`.

- [ ] **Step 4: Value objects + defaults.** `GrafanaConnection`: add promoted `public ?string $pyroscopePushUrl, public bool $profilingEnabled`. `GrafanaEnvDefaults`: add `#[Autowire('%env(PYROSCOPE_PUSH_URL)%')] public string $pyroscopePushUrl`.

- [ ] **Step 5: Request DTO.** Add to `GrafanaSettingsRequest`:
```php
#[Assert\Length(max: 255)] #[Assert\Url(requireTld: false)] public ?string $pyroscopePushUrl = null,
#[Assert\Type('bool')] public bool $profilingEnabled = false,
```

- [ ] **Step 6: JSON mapper refactor.** Change the signature to `public static function from(?GrafanaSettings $settings, GrafanaEnvDefaults $defaults, bool $profilerAvailable): array` (read the three defaults from `$defaults`), keep every existing key, and add:
```php
'pyroscopePushUrl' => $settings?->getPyroscopePushUrlOverride(),
'pyroscopePushUrlDefault' => $defaults->pyroscopePushUrl,
'pyroscopePushUrlEffective' => self::effective($settings?->getPyroscopePushUrlOverride(), $defaults->pyroscopePushUrl),
'profilingEnabled' => $settings?->isProfilingEnabled() ?? false,
'profilingContainerPresent' => '' !== $defaults->pyroscopePushUrl,
'profilerAvailable' => $profilerAvailable,
```
  Update the one existing call site in `GrafanaSettings::view()`.

- [ ] **Step 7: Service.** In `Service/Grafana/GrafanaSettings`: add `ProfileSampler $sampler` to the constructor; `view()` → `GrafanaSettingsJson::from($this->settings(), $this->defaults, $this->sampler->isAvailable())`; add
```php
public function effectivePyroscopePushUrl(): ?string
{
    return $this->settings()->getPyroscopePushUrlOverride()
        ?? ('' === $this->defaults->pyroscopePushUrl ? null : $this->defaults->pyroscopePushUrl);
}

public function profilingEnabled(): bool
{
    return $this->settings()->isProfilingEnabled();
}
```
  and extend `connectionFrom()` to pass `$this->blankToNull($request->pyroscopePushUrl)` and `$request->profilingEnabled`.

- [ ] **Step 8: Migration.** `docker compose exec -T php bin/console doctrine:migrations:diff --no-interaction`, then read the generated file: it must add exactly `profiling_enabled` (boolean, default false, NOT NULL) and `pyroscope_push_url` (VARCHAR(255) NULL) to `grafana_settings`, with a matching `down()`. Apply locally: `docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction` and `docker compose exec -T php bin/console doctrine:schema:validate`. Also run the SQLite leg the CI uses: `cd backend && php bin/console doctrine:migrations:migrate --no-interaction --env=test` is NOT how CI does it — instead run the same command CI's migration leg runs (see `.github/workflows/ci.yml`, job that migrates from empty on both dialects) locally against a scratch SQLite DB to confirm the SQL is dialect-safe.

- [ ] **Step 9: Run tests, expect pass.** `php bin/phpunit --filter Grafana`; then `composer cs && composer stan && composer md`.

- [ ] **Step 10: Commit.**
```bash
git add backend/src backend/migrations backend/tests backend/config/services.yaml
git commit -m "feat(#993): profiling toggle and Pyroscope push URL on the Grafana settings"
```

---

### Task 3: Sampler adapter, factory, PHPStan stub

**Files:**
- Create: `backend/src/Service/Profiling/ExcimerSampler.php`, `ProfileSamplerFactory.php`
- Create: `backend/tests/PhpStan/stubs/excimer.stub`
- Modify: `backend/phpstan.neon` (or `phpstan.dist.neon` — whichever exists) — add the stub under `parameters.stubFiles`
- Modify: `backend/config/services.yaml` (replace the Task 2 alias with the factory)
- Modify: `backend/infection.json5` — exclude `Service/Profiling/ExcimerSampler.php`
- Tests: `backend/tests/Service/Profiling/ProfileSamplerFactoryTest.php`, `ExcimerSamplerTest.php`, `NullProfileSamplerTest.php`

**Interfaces:**
- Produces: `ProfileSampler` service resolved via `ProfileSamplerFactory::create()`.

- [ ] **Step 1: Failing tests.**
```php
final class ProfileSamplerFactoryTest extends TestCase
{
    public function testPicksTheExcimerAdapterExactlyWhenTheExtensionIsLoaded(): void
    {
        $sampler = (new ProfileSamplerFactory())->create();
        self::assertSame(extension_loaded('excimer'), $sampler instanceof ExcimerSampler);
        self::assertSame(extension_loaded('excimer'), $sampler->isAvailable());
    }
}
```
```php
final class NullProfileSamplerTest extends TestCase
{
    public function testIsInertEndToEnd(): void
    {
        $sampler = new NullProfileSampler();
        $sampler->start(0.001);
        self::assertFalse($sampler->isRunning());
        self::assertNull($sampler->stop());
        self::assertFalse($sampler->isAvailable());
    }
}
```
```php
#[RequiresPhpExtension('excimer')]
final class ExcimerSamplerTest extends TestCase
{
    public function testCollectsCollapsedStacksWhileRunning(): void
    {
        $sampler = new ExcimerSampler();
        $sampler->start(0.001);
        self::assertTrue($sampler->isRunning());
        $spin = 0; $until = microtime(true) + 0.05; while (microtime(true) < $until) { $spin++; }
        $profile = $sampler->stop();
        self::assertNotNull($profile);
        self::assertGreaterThan(0, $profile->sampleCount);
        self::assertSame(1000, $profile->sampleRateHz);
        self::assertStringContainsString(';', $profile->collapsedStacks);
        self::assertFalse($sampler->isRunning());
    }

    public function testStopWithoutStartIsNull(): void
    {
        self::assertNull((new ExcimerSampler())->stop());
    }
}
```

- [ ] **Step 2: Adapter + factory.**
```php
<?php
declare(strict_types=1);
namespace App\Service\Profiling;

final class ExcimerSampler implements ProfileSampler
{
    private const int MAX_STACK_DEPTH = 250;

    private ?\ExcimerProfiler $profiler = null;
    private int $startedAtUnix = 0;
    private int $sampleRateHz = 0;

    public function isAvailable(): bool
    {
        return extension_loaded('excimer');
    }

    public function start(float $periodSeconds): void
    {
        if (null !== $this->profiler) {
            return;
        }
        $profiler = new \ExcimerProfiler();
        $profiler->setEventType(EXCIMER_REAL);
        $profiler->setPeriod($periodSeconds);
        $profiler->setMaxDepth(self::MAX_STACK_DEPTH);
        $profiler->start();
        $this->profiler = $profiler;
        $this->startedAtUnix = time();
        $this->sampleRateHz = (int) round(1 / $periodSeconds);
    }

    public function stop(): ?CollapsedProfile
    {
        if (null === $this->profiler) {
            return null;
        }
        $this->profiler->stop();
        $log = $this->profiler->getLog();
        $this->profiler = null;
        if (0 === count($log)) {
            return null;
        }

        return new CollapsedProfile($log->formatCollapsed(), count($log), $this->sampleRateHz, $this->startedAtUnix, time());
    }

    public function isRunning(): bool
    {
        return null !== $this->profiler;
    }
}
```
```php
final readonly class ProfileSamplerFactory
{
    public function create(): ProfileSampler
    {
        $excimer = new ExcimerSampler();

        return $excimer->isAvailable() ? $excimer : new NullProfileSampler();
    }
}
```
  `services.yaml`: replace the Task 2 alias with
```yaml
    App\Service\Profiling\ProfileSampler:
        factory: ['@App\Service\Profiling\ProfileSamplerFactory', 'create']
```

- [ ] **Step 3: PHPStan stub** `backend/tests/PhpStan/stubs/excimer.stub`:
```php
<?php
const EXCIMER_REAL = 0;
const EXCIMER_CPU = 1;

class ExcimerLog implements \Countable
{
    public function formatCollapsed(): string {}
    public function count(): int {}
}

class ExcimerProfiler
{
    public function setPeriod(float $period): void {}
    public function setEventType(int $eventType): void {}
    public function setMaxDepth(int $maxDepth): void {}
    public function start(): void {}
    public function stop(): void {}
    public function getLog(): ExcimerLog {}
}
```
  Add to the PHPStan config `parameters: stubFiles: [tests/PhpStan/stubs/excimer.stub]` (merge into the existing `parameters` block).

- [ ] **Step 4: Infection scope.** In `infection.json5` add `"Service/Profiling/ExcimerSampler.php"` to `source.excludes` (create the key if absent) with a ≤3-line comment: the adapter needs the extension, which the native mutation run lacks; it is tested in the Docker leg.

- [ ] **Step 5: Run.** Native: `php bin/phpunit --filter Profiling` (Excimer test skipped), `composer cs && composer stan && composer md`. Docker: `docker compose exec -T php vendor/bin/phpunit --filter ExcimerSampler` → passes.

- [ ] **Step 6: Commit.** `git commit -am "feat(#993): Excimer sampler adapter behind a factory, with a PHPStan stub"` (add new files first).

---

### Task 4: Endpoint, labels, fail-open Pyroscope client

**Files:**
- Create: `backend/src/Service/Profiling/PyroscopeEndpoint.php`, `ProfileLabels.php`, `PyroscopeClient.php`
- Create: `backend/src/Service/Grafana/SettingsPyroscopeEndpoint.php`
- Modify: `backend/config/services.yaml` (alias `PyroscopeEndpoint` → `SettingsPyroscopeEndpoint`)
- Tests: `backend/tests/Service/Profiling/ProfileLabelsTest.php`, `PyroscopeClientTest.php`, `backend/tests/Service/Grafana/SettingsPyroscopeEndpointTest.php`

**Interfaces:**
- Produces: `PyroscopeClient::push(CollapsedProfile, ProfileLabels): void`; `ProfileLabels::forWebRequest(string $traceId, string $spanId)`, `::forWorker()`, `->toNameParameter(string $application): string`.

- [ ] **Step 1: Failing tests.** `ProfileLabelsTest`: `forWebRequest('abc','def')->toNameParameter('simple-feed-reader')` === `simple-feed-reader{service_name=simple-feed-reader,process=web,trace_id=abc,span_id=def}`; `forWorker()` === `simple-feed-reader{service_name=simple-feed-reader,process=worker}`. `PyroscopeClientTest` (plain `TestCase`, `MockHttpClient` closure capturing `$method,$url,$options`, anonymous `PyroscopeEndpoint`):
  - posts to `http://pyroscope:4040/ingest` with query `name`, `from=<startedAt>`, `until=<endedAt>`, `sampleRate=1000`, `spyName=excimer`, body === collapsed stacks, `timeout` 1.0, `Content-Type: text/plain` header;
  - trailing slash on the push URL is tolerated;
  - no request when the endpoint returns null;
  - swallows a transport error (`MockResponse` throwing / `new MockResponse('', ['error' => 'boom'])`);
  - swallows an endpoint that throws from `pushUrl()`.
  `SettingsPyroscopeEndpointTest`: delegates to `GrafanaSettings::effectivePyroscopePushUrl()` (mock the service class or use the existing pattern in `tests/Service/Grafana`).

- [ ] **Step 2: Implement.**
```php
interface PyroscopeEndpoint
{
    public function pushUrl(): ?string;
}
```
```php
final readonly class SettingsPyroscopeEndpoint implements PyroscopeEndpoint
{
    public function __construct(private GrafanaSettings $settings) {}
    public function pushUrl(): ?string { return $this->settings->effectivePyroscopePushUrl(); }
}
```
```php
final readonly class ProfileLabels
{
    private const string SERVICE_NAME = 'simple-feed-reader';

    /** @param array<string, string> $labels */
    private function __construct(private array $labels) {}

    public static function forWebRequest(string $traceId, string $spanId): self
    {
        return new self(['service_name' => self::SERVICE_NAME, 'process' => 'web', 'trace_id' => $traceId, 'span_id' => $spanId]);
    }

    public static function forWorker(): self
    {
        return new self(['service_name' => self::SERVICE_NAME, 'process' => 'worker']);
    }

    public function toNameParameter(string $application): string
    {
        $pairs = array_map(static fn (string $key, string $value): string => $key . '=' . $value, array_keys($this->labels), $this->labels);

        return $application . '{' . implode(',', $pairs) . '}';
    }
}
```
```php
final readonly class PyroscopeClient
{
    private const float TIMEOUT_SECONDS = 1.0;
    private const string APPLICATION = 'simple-feed-reader';
    private const string SPY_NAME = 'excimer';

    // Never inject a logger here: a failed push must stay silent so a dead
    // Pyroscope can never reach a request or the worker.
    public function __construct(private HttpClientInterface $httpClient, private PyroscopeEndpoint $endpoint) {}

    public function push(CollapsedProfile $profile, ProfileLabels $labels): void
    {
        try {
            $pushUrl = $this->endpoint->pushUrl();
            if (null === $pushUrl) {
                return;
            }
            $this->httpClient->request('POST', rtrim($pushUrl, '/') . '/ingest', [
                'query' => [
                    'name' => $labels->toNameParameter(self::APPLICATION),
                    'from' => (string) $profile->startedAtUnix,
                    'until' => (string) $profile->endedAtUnix,
                    'sampleRate' => (string) $profile->sampleRateHz,
                    'spyName' => self::SPY_NAME,
                ],
                'headers' => ['Content-Type' => 'text/plain'],
                'body' => $profile->collapsedStacks,
                'timeout' => self::TIMEOUT_SECONDS,
            ])->getStatusCode();
        } catch (\Throwable) {
            // fail-open
        }
    }
}
```
  `services.yaml`: `App\Service\Profiling\PyroscopeEndpoint: '@App\Service\Grafana\SettingsPyroscopeEndpoint'`.

- [ ] **Step 3: Run tests + gates; commit** `feat(#993): fail-open Pyroscope client with request-scoped profile labels`.

---

### Task 5: Policy and the per-request listener (span-linked profiles)

**Files:**
- Create: `backend/src/Service/Profiling/ProfilingPolicy.php`
- Create: `backend/src/EventListener/RequestProfilingListener.php`
- Tests: `backend/tests/Service/Profiling/ProfilingPolicyTest.php`, `backend/tests/EventListener/RequestProfilingListenerTest.php` (unit, stubs), `backend/tests/Functional/RequestProfilingTest.php` (kernel, real span)

**Interfaces:**
- Produces: `ProfilingPolicy::isEnabled(): bool`; span attribute `pyroscope.profile.id`; profiles labelled `process=web,trace_id,span_id`.

- [ ] **Step 1: Failing tests.** `ProfilingPolicyTest` truth table with stub `ProfileSampler`/`PyroscopeEndpoint` and a `GrafanaSettings` test double: available+on+url → true; unavailable → false (settings never consulted); on but null url → false; settings throwing → false. `RequestProfilingListenerTest` (unit): with a stub sampler recording `start(0.001)`/`stop()`, a stub client recording pushes, a `TraceContext` stub returning ids → `onKernelRequest` (main request) starts and `onKernelTerminate` pushes with `name` containing `trace_id=…,span_id=…`; sub-request → no start; policy false → no start; no trace ids → no start; terminate without start → no push; a null profile from `stop()` → no push. Functional `RequestProfilingTest` (`WebTestCase`): install the `MockHttpClient` capture trick (see `ClientErrorControllerTest::installLokiCapture`, but swap `PyroscopeClient`), set the toggle on via the `GrafanaSettings` service with a push URL, replace `ProfileSampler` in the container with a stub returning a fixed `CollapsedProfile`, activate a real span (`TracerProvider` as in `OtelTraceContextTest`) around `$client->request('GET', '/api/health-or-any-public-route')`; assert exactly one push whose `name` query contains that span's id and trace id, and that the span's attributes include `pyroscope.profile.id` equal to the span id (read via the in-memory exporter / `SpanData`).

- [ ] **Step 2: Implement.**
```php
final readonly class ProfilingPolicy
{
    public function __construct(private GrafanaSettings $settings, private ProfileSampler $sampler, private PyroscopeEndpoint $endpoint) {}

    public function isEnabled(): bool
    {
        if (!$this->sampler->isAvailable()) {
            return false;
        }
        try {
            return $this->settings->profilingEnabled() && null !== $this->endpoint->pushUrl();
        } catch (\Throwable) {
            return false;
        }
    }
}
```
```php
#[AsEventListener(event: RequestEvent::class, method: 'onKernelRequest', priority: 4096)]
#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate', priority: 16)]
final class RequestProfilingListener
{
    public const float SAMPLE_PERIOD_SECONDS = 0.001;
    private const string PROFILE_ID_ATTRIBUTE = 'pyroscope.profile.id';

    private ?ProfileLabels $labels = null;

    public function __construct(
        private readonly ProfilingPolicy $policy,
        private readonly ProfileSampler $sampler,
        private readonly PyroscopeClient $client,
        private readonly TraceContext $trace,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->policy->isEnabled()) {
            return;
        }
        $traceId = $this->trace->traceId();
        $spanId = $this->trace->spanId();
        if (null === $traceId || null === $spanId) {
            return;
        }
        Span::getCurrent()->setAttribute(self::PROFILE_ID_ATTRIBUTE, $spanId);
        $this->labels = ProfileLabels::forWebRequest($traceId, $spanId);
        $this->sampler->start(self::SAMPLE_PERIOD_SECONDS);
    }

    public function onKernelTerminate(): void
    {
        if (null === $this->labels) {
            return;
        }
        $labels = $this->labels;
        $this->labels = null;
        $profile = $this->sampler->stop();
        if (null !== $profile) {
            $this->client->push($profile, $labels);
        }
    }
}
```

- [ ] **Step 3: Run tests + gates; commit** `feat(#993): profile each traced request and link it to its span`.

---

### Task 6: Worker continuous profiling

**Files:**
- Create: `backend/src/EventListener/WorkerProfilingListener.php`
- Tests: `backend/tests/EventListener/WorkerProfilingListenerTest.php`

**Interfaces:**
- Consumes: `Psr\Clock\ClockInterface` (Symfony's `Clock` service). Check first: `composer show symfony/clock`; if absent, `composer require symfony/clock` and commit `composer.json`/`composer.lock` in this task.

- [ ] **Step 1: Failing tests** with `Symfony\Component\Clock\MockClock`, stub sampler (records starts/stops, `isRunning` tracks state, `stop()` returns a fixed profile), stub client, stub policy (settable): started+enabled → one `start(0.01)`; running before 10 s → no push; `sleep(10)` then running → one push (`process=worker`) and a fresh start; policy flips to false, `sleep(30)`, running → push and no restart; stopped while running → push; started while disabled → nothing.

- [ ] **Step 2: Implement.**
```php
#[AsEventListener(event: WorkerStartedEvent::class, method: 'onWorkerStarted')]
#[AsEventListener(event: WorkerRunningEvent::class, method: 'onWorkerRunning')]
#[AsEventListener(event: WorkerStoppedEvent::class, method: 'onWorkerStopped')]
final class WorkerProfilingListener
{
    public const float SAMPLE_PERIOD_SECONDS = 0.01;
    public const int FLUSH_INTERVAL_SECONDS = 10;
    public const int TOGGLE_RECHECK_SECONDS = 30;

    private int $lastFlushAt = 0;
    private int $lastToggleCheckAt = 0;

    public function __construct(
        private readonly ProfilingPolicy $policy,
        private readonly ProfileSampler $sampler,
        private readonly PyroscopeClient $client,
        private readonly ClockInterface $clock,
    ) {
    }

    public function onWorkerStarted(): void
    {
        $this->refreshToggle();
    }

    public function onWorkerRunning(): void
    {
        $now = $this->now();
        if ($now - $this->lastToggleCheckAt >= self::TOGGLE_RECHECK_SECONDS) {
            $this->refreshToggle();
        }
        if ($this->sampler->isRunning() && $now - $this->lastFlushAt >= self::FLUSH_INTERVAL_SECONDS) {
            $this->rotate();
        }
    }

    public function onWorkerStopped(): void
    {
        $this->flush();
    }

    private function refreshToggle(): void
    {
        $this->lastToggleCheckAt = $this->now();
        $enabled = $this->policy->isEnabled();
        if ($enabled && !$this->sampler->isRunning()) {
            $this->startSampling();
        }
        if (!$enabled && $this->sampler->isRunning()) {
            $this->flush();
        }
    }

    private function rotate(): void
    {
        $this->flush();
        $this->startSampling();
    }

    private function startSampling(): void
    {
        $this->sampler->start(self::SAMPLE_PERIOD_SECONDS);
        $this->lastFlushAt = $this->now();
    }

    private function flush(): void
    {
        $profile = $this->sampler->stop();
        if (null !== $profile) {
            $this->client->push($profile, ProfileLabels::forWorker());
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
```

- [ ] **Step 3: Run tests + gates (PHPMD: the class stays under the method-count/complexity thresholds; if not, extract a `SamplingWindow` helper); commit** `feat(#993): continuous worker profiling with periodic rotation and a toggle re-check`.

---

### Task 7: Grafana datasources, Tempo metrics, and the trace→profile link

**Files:**
- Create: `docker/grafana/provisioning/datasources/pyroscope.yaml`
- Modify: `docker/grafana/provisioning/datasources/tempo.yaml`
- Modify: `docker/tempo/tempo-config.yaml`

- [ ] **Step 1: Pyroscope datasource.**
```yaml
apiVersion: 1

datasources:
  - name: Pyroscope
    type: grafana-pyroscope-datasource
    uid: pyroscope
    access: proxy
    url: http://pyroscope:4040
    editable: false
```

- [ ] **Step 2: Tempo TraceQL metrics.** Replace `docker/tempo/tempo-config.yaml` with:
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

# local-blocks alone powers TraceQL metrics (rate, quantiles); the other
# generator processors need a Prometheus remote-write we do not run.
metrics_generator:
  storage:
    path: /var/tempo/generator/wal
  traces_storage:
    path: /var/tempo/generator/traces
  processor:
    local_blocks:
      filter_server_spans: false
      flush_to_storage: true

overrides:
  defaults:
    metrics_generator:
      processors: [local-blocks]

compactor:
  compaction:
    block_retention: 72h
```
  `docker compose up -d --force-recreate tempo grafana`, generate a few requests, then verify a metrics query returns data:
```bash
curl -s -X POST 'http://admin:admin@localhost:3000/api/ds/query' -H 'Content-Type: application/json' -d '{"queries":[{"refId":"A","datasource":{"uid":"tempo","type":"tempo"},"queryType":"traceql","query":"{ kind = server } | rate()"}],"from":"now-15m","to":"now"}' | python3 -c "import sys,json;r=json.load(sys.stdin)['results']['A'];print('frames:',len(r.get('frames',[])),'error:',r.get('error',''))"
```
  Expected: `frames: ≥1, error:` empty. If `{ kind = server }` matches nothing, inspect one real trace's root span in Explore and adjust the selector (e.g. `{ span:kind = server }`), then use the working selector consistently in Task 8.

- [ ] **Step 3: Produce one profile and discover the profile type id.** With the dev stack up and Task 1–6 code deployed to the containers: turn the toggle on (`PUT /api/admin/grafana` as admin with `profilingEnabled: true`, or via the UI after Task 9), make an HTTP request, then:
```bash
curl -s -X POST http://127.0.0.1:4040/querier.v1.QuerierService/ProfileTypes -H 'Content-Type: application/json' -d '{}'
```
  (fallback: `curl -s 'http://admin:admin@localhost:3000/api/datasources/uid/pyroscope/resources/profileTypes'`). Copy the exact `id` of the excimer/simple-feed-reader profile type (shape `<name>:<sampleType>:<sampleUnit>:<periodType>:<periodUnit>`). Record it in the ledger.

- [ ] **Step 4: Trace→profiles.** In `tempo.yaml` add under `jsonData` (keep `tracesToLogsV2`):
```yaml
      tracesToProfiles:
        datasourceUid: pyroscope
        profileTypeId: '<the id from Step 3>'
        customQuery: true
        # $$ keeps the literal for Grafana; a single $ is expanded to empty at load.
        query: 'span_id="$${__span.spanId}"'
```
  Reload: `curl -s -X POST 'http://admin:admin@localhost:3000/api/admin/provisioning/datasources/reload'`, then `GET /api/datasources/uid/tempo` must show `tracesToProfiles.query` === `span_id="${__span.spanId}"` (literal). In Explore, open the traced request's span → **Profiles** shows a flame graph whose query resolved to that span id.

- [ ] **Step 5: Commit** `feat(#993): Pyroscope datasource, TraceQL metrics, and span-linked trace-to-profiles`.

---

### Task 8: Performance dashboard

**Files:**
- Create: `docker/grafana/dashboards/application-performance.json`
- Modify: `docker/grafana/dashboards/application-logs.json` (add a `links` entry to the new dashboard; bump `version`)

- [ ] **Step 1: Write the dashboard.** uid `application-performance`, title `Application performance`, tags `["simple-feed-reader"]`, `time: now-1h`, `refresh: 30s`, `links: [{"type":"dashboards","tags":["simple-feed-reader"],"asDropdown":true,"title":"Dashboards"}]`. Panels (all Tempo panels use `"datasource": {"type":"tempo","uid":"tempo"}` and `"queryType":"traceql"`; use the selector verified in Task 7 Step 2 wherever `{ kind = server }` appears):
  1. `timeseries` "Requests / s by status" — `{ kind = server } | rate() by (status)`; gridPos h8 w12 x0 y0.
  2. `stat` "Error rate" — `{ kind = server && status = error } | rate()`; unit `reqps`; thresholds green→red at 0.01; h8 w6 x12 y0.
  3. `stat` "p95 latency" — `{ kind = server } | quantile_over_time(duration, .95)`; unit `s`; h8 w6 x18 y0.
  4. `table` "p95 latency by route" — `{ kind = server } | quantile_over_time(duration, .95) by (span.http.route)`; h8 w24 x0 y8. If a real trace shows the route under a different attribute name, use that name.
  5. `traces` "Slowest traces (>100 ms)" — `{ kind = server && duration > 100ms }`, `limit: 20`; h10 w12 x0 y16.
  6. `traces` "Error traces" — `{ kind = server && status = error }`, `limit: 20`; h10 w12 x12 y16.
  7. `flamegraph` "Service profile (wall time)" — datasource `{"type":"grafana-pyroscope-datasource","uid":"pyroscope"}`, target `{"refId":"A","profileTypeId":"<Task 7 id>","labelSelector":"{service_name=\"simple-feed-reader\"}","queryType":"profile"}`; h12 w24 x0 y26.
  Keep the JSON valid (`python3 -m json.tool`), `schemaVersion: 39`, `editable: true`, `id: null`.

- [ ] **Step 2: Link from the logs dashboard.** In `application-logs.json` add the same `links` array and bump `"version"` to 3.

- [ ] **Step 3: Verify every panel returns data** (provisioning polls within ~10 s): for each Tempo expression run the `/api/ds/query` command from Task 7 Step 2 and confirm `frames ≥ 1`; for the flame graph confirm via `GET /api/dashboards/uid/application-performance` that the panel exists, then open it in the browser and confirm a flame graph renders (toggle on, worker running). Screenshot both dashboards for the PR.

- [ ] **Step 4: Commit** `feat(#993): application performance dashboard with TraceQL metrics, trace lists, and a flame graph`.

---

### Task 9: Frontend — Profiling section on the Grafana settings page

**Files:**
- Modify: `frontend/src/app/settings/admin/grafana/grafana-settings.service.ts`, `grafana-section.component.ts`, `grafana-section.component.html`, `grafana-section.component.scss`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Tests: `grafana-settings.service.spec.ts`, `grafana-section.component.spec.ts`

- [ ] **Step 1: Failing specs.** Component: renders a third group with `data-testid="grafana-profiling-toggle"` and `data-testid="grafana-pyroscope-push-url"`; toggling PUTs the full body with `profilingEnabled: true` immediately (no Save needed; `dirty()` stays false); editing the URL marks dirty and Save sends `pyroscopePushUrl`; with `profilingContainerPresent: true` the "Local container: …" hint shows `pyroscopePushUrlEffective`; with `profilerAvailable: false` the toggle is disabled and the unavailable hint shows; placeholder equals `pyroscopePushUrlDefault`. Service: `bodyFromState` includes `pyroscopePushUrl` and `profilingEnabled`.

- [ ] **Step 2: Service.** Extend `GrafanaSettingsState` with `readonly pyroscopePushUrl: string | null; readonly pyroscopePushUrlDefault: string; readonly pyroscopePushUrlEffective: string | null; readonly profilingEnabled: boolean; readonly profilingContainerPresent: boolean; readonly profilerAvailable: boolean;`. Extend `SaveGrafanaSettings` with `readonly pyroscopePushUrl: string | null; readonly profilingEnabled: boolean;`. Change `TypedGrafanaEdits = Partial<Omit<SaveGrafanaSettings, 'removeToken' | 'profilingEnabled'>>`. In `bodyFromState` add `pyroscopePushUrl: state.pyroscopePushUrl, profilingEnabled: state.profilingEnabled`.

- [ ] **Step 3: Component.** Add `pyroscopePushUrl = linkedSignal(() => this.svc.pending('pyroscopePushUrl') ?? this.svc.state()?.pyroscopePushUrl ?? '')`, `profilingEnabled = computed(() => this.svc.state()?.profilingEnabled ?? false)`, `profilerAvailable = computed(() => this.svc.state()?.profilerAvailable ?? false)`, `profilingContainerPresent`, `pyroscopePushUrlEffective`, `pyroscopePushUrlDefault`; handlers `onPyroscopePushUrl(value)` → `setTypedField('pyroscopePushUrl', this.emptyToNull(value))`, `onProfilingToggled(value: boolean)` → `this.svc.saveInstant({ profilingEnabled: value })`. Import `ToggleComponent` from `shared/toggle/toggle.component`.

- [ ] **Step 4: Template.** After the "Viewing" group add:
```html
<app-settings-group icon="speed" [title]="'settings.grafana.profiling.title' | transloco">
  <app-settings-row
    [stackable]="true"
    [title]="'settings.grafana.profiling.enabled' | transloco"
    [description]="(profilerAvailable() ? 'settings.grafana.profiling.caption' : 'settings.grafana.profiling.unavailable') | transloco"
  >
    <app-toggle
      data-testid="grafana-profiling-toggle"
      [checked]="profilingEnabled()"
      [disabled]="!profilerAvailable()"
      (toggled)="onProfilingToggled($event)"
    />
  </app-settings-row>
  <app-settings-row
    [stackable]="true"
    [title]="'settings.grafana.profiling.pyroscopePushUrl' | transloco"
    [description]="profilingContainerPresent() ? ('settings.grafana.profiling.localContainer' | transloco: { url: pyroscopePushUrlEffective() }) : ''"
  >
    <input
      class="url-field"
      type="url"
      data-testid="grafana-pyroscope-push-url"
      [placeholder]="pyroscopePushUrlDefault()"
      [value]="pyroscopePushUrl()"
      (input)="onPyroscopePushUrl($any($event.target).value)"
    />
  </app-settings-row>
</app-settings-group>
```
  Match the existing rows' exact attribute/binding style in the file (copy the Loki URL row and adapt). If `app-toggle` has no `disabled` input, guard the click instead (`(toggled)="profilerAvailable() && onProfilingToggled($event)"`) and add the `disabled` input to `ToggleComponent` only if trivial.

- [ ] **Step 5: i18n.** `en.json` under `settings.grafana`:
```json
"profiling": {
  "title": "Profiling",
  "enabled": "Profile requests and the worker",
  "caption": "Samples PHP stacks and ships them to Pyroscope; traces gain a Profiles link. Off by default.",
  "unavailable": "The profiler extension is not installed on this host, so profiling cannot run here.",
  "pyroscopePushUrl": "Pyroscope URL",
  "localContainer": "Local container: {{url}}"
}
```
  `de.json`: `"title": "Profiling"`, `"enabled": "Requests und Worker profilen"`, `"caption": "Erfasst PHP-Stacks und sendet sie an Pyroscope; Traces erhalten einen Profil-Link. Standardmäßig aus."`, `"unavailable": "Die Profiler-Erweiterung ist auf diesem Host nicht installiert, Profiling ist hier nicht möglich."`, `"pyroscopePushUrl": "Pyroscope-URL"`, `"localContainer": "Lokaler Container: {{url}}"`.

- [ ] **Step 6: Run** `docker compose exec -T frontend npm run check` (ESLint + Prettier 100-col + Stylelint + Jest). Fix until green.

- [ ] **Step 7: Commit** `feat(#993): profiling toggle and Pyroscope URL on the Grafana settings page`.

---

### Task 10: End-to-end verification, docs, PR

**Files:**
- Modify: `docs/local-docker.md` (Pyroscope container + toggle + where to see profiles), `CLAUDE.md` (the one-line dev note: "Dev also runs Grafana …, Loki, Tempo and Pyroscope").

- [ ] **Step 1: Rebuild containers from this branch** (`docker compose build php worker && docker compose up -d --force-recreate php worker nginx tempo grafana pyroscope`), apply migrations, clear cache. Remember the standing rule: verify the containers are current before trusting any result.

- [ ] **Step 2: Manual acceptance (record each result in the ledger):**
  1. Toggle **off** (default): make requests → `docker compose logs pyroscope` shows no `/ingest`; worker pushes nothing.
  2. Toggle **on** in the UI → within 30 s the worker starts pushing (`process=worker` visible in the Pyroscope UI at http://localhost:4040 or the dashboard flame graph).
  3. Make a request → Explore → Tempo → that trace's root span has `pyroscope.profile.id`; click **Profiles** → a flame graph appears and the query shows `span_id="<that id>"`.
  4. `docker compose stop pyroscope` → requests still return 200; worker keeps running; `docker compose start pyroscope`.
  5. Toggle **off** → no new `/ingest` calls after 30 s.
  6. Performance dashboard: all seven panels populated; link from the logs dashboard works.
  7. Native (no extension): `php bin/phpunit` green; `GET /api/admin/grafana` (native run) reports `profilerAvailable: false`.

- [ ] **Step 3: Gates.** Backend: `composer check && composer md && php bin/phpunit && composer infection:diff`; Docker MySQL leg: `docker compose exec -T php vendor/bin/phpunit`; frontend: `docker compose exec -T frontend npm run check`; installer: `bash scripts/test/configure-grafana.test.sh`; scan today's dev log for deprecations/errors (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .`).

- [ ] **Step 4: Docs + commit** `docs(#993): Pyroscope profiling in local-docker.md and CLAUDE.md`.

- [ ] **Step 5: PR** into `develop`, title `feat(#993): continuous and per-request profiling with Pyroscope`, body with `Closes #993`, the acceptance results, and the two dashboard screenshots. Merge only when CI is green and the user says so. Do not deploy.
