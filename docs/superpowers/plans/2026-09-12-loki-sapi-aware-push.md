# SAPI-aware Loki log delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the Loki HTTP push off the web request path on SAPIs that cannot flush the response early (Strato `cgi-fcgi`), while keeping the direct push everywhere it is already free (PHP-FPM, CLI worker).

**Architecture:** Introduce a `LokiSink` seam behind `LokiPushHandler`. `DirectLokiSink` pushes over HTTP now (today's behaviour); `SpoolLokiSink` writes one JSON file per flush to `var/loki-spool/` with no network. A factory picks the sink once per process from the SAPI. `LokiSpoolShipper`, called from the machine-facing `MaintenanceTick`, drains the spool to Grafana Cloud out-of-band.

**Tech Stack:** PHP 8.4, Symfony 7.4, Monolog, `symfony/http-client` (`MockHttpClient` in tests), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-12-loki-sapi-aware-push-design.md`

## Global Constraints

- Fail-open everywhere: logging must never break or slow a real user request. Every new sink write and the shipper swallow `\Throwable`.
- No change to the file log handler (`prod-YYYY-MM-DD.log`) or to PHP-FPM / dev / Docker / CLI-worker push timing.
- Clean Code house style: `final readonly class`, constructor promotion, interfaces injected, no boolean flag parameters, guard clauses, no needless comments. Every touched `src` file must be PHPMD-clean and PHPStan level-max clean.
- `declare(strict_types=1);` in every file.
- Discriminator: **direct** when `\function_exists('fastcgi_finish_request')` is true OR `\PHP_SAPI === 'cli'`; **spool** otherwise. Decided once at service construction (SAPI is fixed per process). In the test SAPI (`cli`) the default is always `DirectLokiSink`, so the existing suite exercises unchanged behaviour.
- Spool directory: `%kernel.logs_dir%/loki-spool` (under `var/`, already git-ignored). Created on demand.
- Spool file: name `<unix-micros>-<hex>.json`, content `json_encode($lines)` where `$lines` is `list<array{ts: string, line: string, labels: array<string, string>}>` — the exact shape `LokiClient::push()` consumes.
- Run backend quality gates before the final review: `composer cs`, `composer stan`, `composer md`, `php bin/phpunit`, `composer infection:diff`.

---

## Task 1: `LokiSink` seam + `DirectLokiSink`, refactor `LokiPushHandler`

Introduce the interface and the direct implementation, and make the handler depend on the seam instead of `LokiClient`. Behaviour is unchanged in every environment after this task.

**Files:**
- Create: `backend/src/Service/Logging/Loki/LokiSink.php`
- Create: `backend/src/Service/Logging/Loki/DirectLokiSink.php`
- Create: `backend/tests/Service/Logging/Loki/DirectLokiSinkTest.php`
- Modify: `backend/src/Service/Logging/Loki/LokiPushHandler.php` (constructor dep `LokiClient` → `LokiSink`; `flush()` body)
- Modify: `backend/config/services.yaml` (add `LokiSink` alias → `DirectLokiSink`)
- Modify: `backend/tests/Service/Logging/Loki/LokiPushHandlerTest.php` (wrap `LokiClient` in `DirectLokiSink`)

**Interfaces:**
- Produces:
  - `interface LokiSink { public function write(array $lines): void; }` where `$lines` is `list<array{ts: string, line: string, labels: array<string, string>}>`.
  - `final readonly class DirectLokiSink implements LokiSink` — constructor `__construct(private LokiClient $client)`; `write()` calls `$this->client->push($lines)`.
  - `LokiPushHandler::__construct(LokiSink $sink, string $appLabel, string $envLabel, Level $level = Level::Info, int $flushThreshold = 100)`.
- Consumes: existing `LokiClient::push(array $lines): void`.

- [ ] **Step 1: Write the failing test** — `backend/tests/Service/Logging/Loki/DirectLokiSinkTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\DirectLokiSink;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DirectLokiSinkTest extends TestCase
{
    public function testForwardsLinesToLokiClientAsAnHttpPush(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('', ['http_code' => 204]);
        });
        $sink = new DirectLokiSink(new LokiClient($http, $this->endpoint()));

        $sink->write([[
            'ts' => '1700000000000000000',
            'line' => '{"message":"hello"}',
            'labels' => ['app' => 'sfr', 'env' => 'prod'],
        ]]);

        /** @var array{body: string} $seen */
        $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{streams: list<array{values: list<array{0: string, 1: string}>}>} $body */
        self::assertSame('{"message":"hello"}', $body['streams'][0]['values'][0][1]);
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): string
            {
                return 'http://loki:3100/loki/api/v1/push';
            }

            public function username(): ?string
            {
                return null;
            }

            public function token(): ?string
            {
                return null;
            }
        };
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/DirectLokiSinkTest.php`
Expected: FAIL — `LokiSink`/`DirectLokiSink` do not exist.

- [ ] **Step 3: Create the interface** — `backend/src/Service/Logging/Loki/LokiSink.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

interface LokiSink
{
    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     */
    public function write(array $lines): void;
}
```

- [ ] **Step 4: Create `DirectLokiSink`** — `backend/src/Service/Logging/Loki/DirectLokiSink.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

final readonly class DirectLokiSink implements LokiSink
{
    public function __construct(private LokiClient $client)
    {
    }

    public function write(array $lines): void
    {
        $this->client->push($lines);
    }
}
```

- [ ] **Step 5: Refactor `LokiPushHandler`** — depend on `LokiSink`, not `LokiClient`.

Change the constructor's first parameter and the `flush()` body. The buffer, threshold, formatter, `write()`, `reset()`, `close()`, `nanoTimestamp()`, `formattedLine()` stay exactly as they are.

```php
    public function __construct(
        private readonly LokiSink $sink,
        private readonly string $appLabel,
        private readonly string $envLabel,
        Level $level = Level::Info,
        private readonly int $flushThreshold = 100,
    ) {
        parent::__construct($level, true);
        $this->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, false, false, true));
    }
```

In `flush()`, replace `$this->client->push($lines);` with:

```php
        $this->sink->write($lines);
```

Remove the now-unused `use ...LokiClient;` import only if present (it is not imported by type today; the constructor referenced `LokiClient` — replace that reference). Do not change any other method.

- [ ] **Step 6: Wire the alias** — `backend/config/services.yaml`, next to the existing `LokiEndpoint` alias (around line 260):

```yaml
    App\Service\Logging\Loki\LokiSink: '@App\Service\Logging\Loki\DirectLokiSink'
```

Leave the existing `App\Service\Logging\Loki\LokiPushHandler:` block (its `$appLabel`/`$envLabel` arguments) unchanged; `$sink` autowires from the new alias.

- [ ] **Step 7: Update `LokiPushHandlerTest`** — every `new LokiPushHandler(new LokiClient($http, $this->endpoint()), ...)` becomes `new LokiPushHandler(new DirectLokiSink(new LokiClient($http, $this->endpoint())), ...)`. Add `use App\Service\Logging\Loki\DirectLokiSink;`. No assertions change.

- [ ] **Step 8: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/`
Expected: PASS (DirectLokiSinkTest + the updated LokiPushHandlerTest).

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Logging/Loki/LokiSink.php backend/src/Service/Logging/Loki/DirectLokiSink.php backend/src/Service/Logging/Loki/LokiPushHandler.php backend/config/services.yaml backend/tests/Service/Logging/Loki/DirectLokiSinkTest.php backend/tests/Service/Logging/Loki/LokiPushHandlerTest.php
git commit -m "refactor(#1003): route LokiPushHandler through a LokiSink seam"
```

---

## Task 2: `SpoolLokiSink`

Write buffered lines to one uniquely-named file per flush; no network, fail-open.

**Files:**
- Create: `backend/src/Service/Logging/Loki/SpoolLokiSink.php`
- Create: `backend/tests/Service/Logging/Loki/SpoolLokiSinkTest.php`

**Interfaces:**
- Produces: `final readonly class SpoolLokiSink implements LokiSink` — `__construct(private string $spoolDirectory)`; `write()` creates the directory if missing and writes `json_encode($lines)` to `<micros>-<hex>.json`; swallows `\Throwable`.
- Consumes: `LokiSink` (Task 1).

- [ ] **Step 1: Write the failing test** — `backend/tests/Service/Logging/Loki/SpoolLokiSinkTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\SpoolLokiSink;
use PHPUnit\Framework\TestCase;

final class SpoolLokiSinkTest extends TestCase
{
    private string $spoolDirectory;

    protected function setUp(): void
    {
        $this->spoolDirectory = sys_get_temp_dir() . '/loki-spool-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->spoolDirectory . '/*') ?: []);
        if (is_dir($this->spoolDirectory)) {
            rmdir($this->spoolDirectory);
        }
    }

    public function testWritesOneFilePerFlushDecodingToTheInputLines(): void
    {
        $sink = new SpoolLokiSink($this->spoolDirectory);
        $lines = [[
            'ts' => '1700000000000000000',
            'line' => '{"message":"hello"}',
            'labels' => ['app' => 'sfr', 'env' => 'prod'],
        ]];

        $sink->write($lines);
        $sink->write($lines);

        $files = glob($this->spoolDirectory . '/*.json') ?: [];
        self::assertCount(2, $files);
        $decoded = json_decode((string) file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($lines, $decoded);
    }

    public function testEmptyLinesWriteNothing(): void
    {
        $sink = new SpoolLokiSink($this->spoolDirectory);

        $sink->write([]);

        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    public function testSwallowsWriteErrors(): void
    {
        $file = sys_get_temp_dir() . '/loki-spool-not-a-dir-' . bin2hex(random_bytes(4));
        file_put_contents($file, 'x');
        $sink = new SpoolLokiSink($file);

        $sink->write([[
            'ts' => '1',
            'line' => '{}',
            'labels' => ['app' => 'sfr'],
        ]]);

        self::assertFileExists($file);
        unlink($file);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/SpoolLokiSinkTest.php`
Expected: FAIL — `SpoolLokiSink` does not exist.

- [ ] **Step 3: Implement `SpoolLokiSink`** — `backend/src/Service/Logging/Loki/SpoolLokiSink.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

/**
 * Writes each flush to its own file so concurrent cgi-fcgi processes never
 * contend: no lock, no shared offset. LokiSpoolShipper drains and deletes them
 * out-of-band. Fail-open: a spool write that fails drops that batch, exactly as
 * a failed HTTP push would.
 */
final readonly class SpoolLokiSink implements LokiSink
{
    public function __construct(private string $spoolDirectory)
    {
    }

    public function write(array $lines): void
    {
        if ([] === $lines) {
            return;
        }
        try {
            if (!is_dir($this->spoolDirectory) && !mkdir($this->spoolDirectory, 0770, true) && !is_dir($this->spoolDirectory)) {
                return;
            }
            file_put_contents($this->filePath(), json_encode($lines, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // fail-open: logging must never break the request
        }
    }

    private function filePath(): string
    {
        $micros = (int) (microtime(true) * 1_000_000);

        return sprintf('%s/%d-%s.json', $this->spoolDirectory, $micros, bin2hex(random_bytes(6)));
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/SpoolLokiSinkTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Logging/Loki/SpoolLokiSink.php backend/tests/Service/Logging/Loki/SpoolLokiSinkTest.php
git commit -m "feat(#1003): SpoolLokiSink writes one file per flush, no network"
```

---

## Task 3: `LokiSpoolShipper`

Drain the spool to Grafana Cloud; delete each file on success, keep it on push failure, drop a corrupt file.

**Files:**
- Create: `backend/src/Service/Logging/Loki/LokiSpoolShipper.php`
- Create: `backend/src/Service/Logging/Loki/LokiSpoolReport.php`
- Create: `backend/tests/Service/Logging/Loki/LokiSpoolShipperTest.php`

**Interfaces:**
- Produces:
  - `final readonly class LokiSpoolReport` — `__construct(public int $shipped, public int $failed)`; `toArray(): array{shipped: int, failed: int}`.
  - `final readonly class LokiSpoolShipper` — `__construct(private LokiClient $client, private string $spoolDirectory)`; `ship(): LokiSpoolReport`. A push failure throws out of `LokiClient`? No — `LokiClient::push()` is fail-open and never throws, so the shipper cannot see a failed push. To detect failure the shipper checks delivery via a thrown exception it raises itself is wrong. Instead: the shipper calls `LokiClient::push()` and treats a returned value as success — but `push()` returns void and swallows errors. Therefore the shipper cannot know if Loki was down. See Step 3 note: the shipper deletes on the assumption of delivery, EXCEPT it must not lose data when Loki is down. Resolve by using a delivery-reporting push. **This interface is finalised in Step 3.**
- Consumes: `LokiClient` (existing), `SpoolLokiSink` file format (Task 2).

> **Ruling for the implementer (resolves the note above):** `LokiClient::push()` is fail-open and returns `void`, so the shipper cannot observe a dead Loki through it. Rather than widen `LokiClient`'s contract, the shipper decodes each file and calls `LokiClient::push()`, then deletes the file. Because `push()` swallows transport errors, a dead Loki means the batch is dropped — identical to today's direct-push behaviour on a dead Loki (logs were already best-effort, never durable). "Keep the file when the push fails" is therefore **out of scope**; durability against a dead Loki was never a property of this pipeline. The shipper's only failure branch is a **corrupt/undecodable file**, which it deletes (counted as `failed`) so it cannot wedge the queue. This keeps `LokiClient` untouched and matches the spec's fail-open intent. Ledger this ruling.

- [ ] **Step 1: Write the failing test** — `backend/tests/Service/Logging/Loki/LokiSpoolShipperTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiSpoolShipper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LokiSpoolShipperTest extends TestCase
{
    private string $spoolDirectory;

    protected function setUp(): void
    {
        $this->spoolDirectory = sys_get_temp_dir() . '/loki-ship-test-' . bin2hex(random_bytes(4));
        mkdir($this->spoolDirectory, 0770, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->spoolDirectory . '/*') ?: []);
        rmdir($this->spoolDirectory);
    }

    public function testShipsEveryFileThenDeletesItAndPushesEachBatch(): void
    {
        $this->spool([['ts' => '1', 'line' => '{"m":"a"}', 'labels' => ['app' => 'sfr']]]);
        $this->spool([['ts' => '2', 'line' => '{"m":"b"}', 'labels' => ['app' => 'sfr']]]);
        $pushes = 0;
        $http = new MockHttpClient(function () use (&$pushes): MockResponse {
            ++$pushes;

            return new MockResponse('', ['http_code' => 204]);
        });
        $shipper = new LokiSpoolShipper(new LokiClient($http, $this->endpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(2, $report->shipped);
        self::assertSame(0, $report->failed);
        self::assertSame(2, $pushes);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    public function testDeletesACorruptFileAndCountsItFailed(): void
    {
        file_put_contents($this->spoolDirectory . '/1-deadbeef.json', 'not json');
        $shipper = new LokiSpoolShipper(new LokiClient(new MockHttpClient(), $this->endpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(1, $report->failed);
        self::assertSame([], glob($this->spoolDirectory . '/*.json') ?: []);
    }

    public function testEmptyDirectoryIsANoOp(): void
    {
        $shipper = new LokiSpoolShipper(new LokiClient(new MockHttpClient(), $this->endpoint()), $this->spoolDirectory);

        $report = $shipper->ship();

        self::assertSame(0, $report->shipped);
        self::assertSame(0, $report->failed);
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     */
    private function spool(array $lines): void
    {
        file_put_contents(
            sprintf('%s/%d-%s.json', $this->spoolDirectory, (int) (microtime(true) * 1_000_000), bin2hex(random_bytes(6))),
            json_encode($lines, JSON_THROW_ON_ERROR),
        );
        usleep(1000);
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): string
            {
                return 'http://loki:3100/loki/api/v1/push';
            }

            public function username(): ?string
            {
                return null;
            }

            public function token(): ?string
            {
                return null;
            }
        };
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiSpoolShipperTest.php`
Expected: FAIL — `LokiSpoolShipper` / `LokiSpoolReport` do not exist.

- [ ] **Step 3: Implement the report and the shipper**

`backend/src/Service/Logging/Loki/LokiSpoolReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

final readonly class LokiSpoolReport
{
    public function __construct(
        public int $shipped,
        public int $failed,
    ) {
    }

    /**
     * @return array{shipped: int, failed: int}
     */
    public function toArray(): array
    {
        return ['shipped' => $this->shipped, 'failed' => $this->failed];
    }
}
```

`backend/src/Service/Logging/Loki/LokiSpoolShipper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

/**
 * Drains the Loki spool out-of-band from the machine-facing maintenance tick.
 * LokiClient::push() is fail-open, so a dead Loki silently drops a batch —
 * logging was always best-effort, never durable. The only local failure is a
 * corrupt file, which is deleted so it cannot wedge the queue.
 */
final readonly class LokiSpoolShipper
{
    public function __construct(
        private LokiClient $client,
        private string $spoolDirectory,
    ) {
    }

    public function ship(): LokiSpoolReport
    {
        $shipped = 0;
        $failed = 0;
        foreach ($this->files() as $file) {
            if ($this->shipFile($file)) {
                ++$shipped;
            } else {
                ++$failed;
            }
            @unlink($file);
        }

        return new LokiSpoolReport($shipped, $failed);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->spoolDirectory . '/*.json');

        return false === $files ? [] : $files;
    }

    private function shipFile(string $file): bool
    {
        try {
            $lines = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($lines)) {
                return false;
            }
            /** @var list<array{ts: string, line: string, labels: array<string, string>}> $lines */
            $this->client->push($lines);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiSpoolShipperTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Logging/Loki/LokiSpoolShipper.php backend/src/Service/Logging/Loki/LokiSpoolReport.php backend/tests/Service/Logging/Loki/LokiSpoolShipperTest.php
git commit -m "feat(#1003): LokiSpoolShipper drains the spool to Grafana Cloud"
```

---

## Task 4: `LokiSinkFactory` and SAPI selection

Pick the sink per process; rewire the `LokiSink` alias to the factory.

**Files:**
- Create: `backend/src/Service/Logging/Loki/LokiSinkFactory.php`
- Create: `backend/tests/Service/Logging/Loki/LokiSinkFactoryTest.php`
- Modify: `backend/config/services.yaml` (spool dir bind on `SpoolLokiSink` + shipper; `LokiSink` alias → factory)

**Interfaces:**
- Produces:
  - `final readonly class LokiSinkFactory` — `__construct(private DirectLokiSink $direct, private SpoolLokiSink $spool)`; `create(): LokiSink`; pure static `public static function selects(string $sapi, bool $canFinishRequest): string` returning `'direct'` or `'spool'`.
- Consumes: `DirectLokiSink` (Task 1), `SpoolLokiSink` (Task 2).

- [ ] **Step 1: Write the failing test** — `backend/tests/Service/Logging/Loki/LokiSinkFactoryTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\DirectLokiSink;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiSinkFactory;
use App\Service\Logging\Loki\SpoolLokiSink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class LokiSinkFactoryTest extends TestCase
{
    public function testCliAlwaysSelectsDirectEvenWithoutFastcgiFinishRequest(): void
    {
        self::assertSame('direct', LokiSinkFactory::selects('cli', false));
        self::assertSame('direct', LokiSinkFactory::selects('cli', true));
    }

    public function testFpmWebSelectsDirect(): void
    {
        self::assertSame('direct', LokiSinkFactory::selects('fpm-fcgi', true));
    }

    public function testCgiFcgiWithoutFastcgiFinishRequestSelectsSpool(): void
    {
        self::assertSame('spool', LokiSinkFactory::selects('cgi-fcgi', false));
    }

    public function testCreateReturnsALokiSink(): void
    {
        $factory = new LokiSinkFactory(
            new DirectLokiSink(new LokiClient(new MockHttpClient(), $this->endpoint())),
            new SpoolLokiSink(sys_get_temp_dir()),
        );

        self::assertInstanceOf(DirectLokiSink::class, $factory->create());
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): ?string
            {
                return null;
            }

            public function username(): ?string
            {
                return null;
            }

            public function token(): ?string
            {
                return null;
            }
        };
    }
}
```

> Note: `testCreateReturnsALokiSink` runs under the `cli` SAPI, so `create()` must return `DirectLokiSink`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiSinkFactoryTest.php`
Expected: FAIL — `LokiSinkFactory` does not exist.

- [ ] **Step 3: Implement `LokiSinkFactory`** — `backend/src/Service/Logging/Loki/LokiSinkFactory.php`

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

/**
 * Direct HTTP push wherever it is free: PHP-FPM (the response is already
 * flushed via fastcgi_finish_request) and CLI (no client waits). Spool only on
 * a web SAPI that cannot detach the response — Strato's cgi-fcgi — so no user
 * ever waits on Grafana Cloud.
 */
final readonly class LokiSinkFactory
{
    public function __construct(
        private DirectLokiSink $direct,
        private SpoolLokiSink $spool,
    ) {
    }

    public function create(): LokiSink
    {
        return 'spool' === self::selects(\PHP_SAPI, \function_exists('fastcgi_finish_request'))
            ? $this->spool
            : $this->direct;
    }

    public static function selects(string $sapi, bool $canFinishRequest): string
    {
        if ('cli' === $sapi) {
            return 'direct';
        }

        return $canFinishRequest ? 'direct' : 'spool';
    }
}
```

- [ ] **Step 4: Rewire services** — `backend/config/services.yaml`. Bind the spool directory on both consumers and replace the `LokiSink` alias with the factory:

```yaml
    App\Service\Logging\Loki\SpoolLokiSink:
        arguments:
            $spoolDirectory: '%kernel.logs_dir%/loki-spool'

    App\Service\Logging\Loki\LokiSpoolShipper:
        arguments:
            $spoolDirectory: '%kernel.logs_dir%/loki-spool'

    App\Service\Logging\Loki\LokiSink:
        factory: ['@App\Service\Logging\Loki\LokiSinkFactory', 'create']
```

Remove the Task 1 alias line `App\Service\Logging\Loki\LokiSink: '@...DirectLokiSink'` (replaced by the factory block above).

- [ ] **Step 5: Run the tests + container check**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/ && php bin/console lint:container`
Expected: PASS; container lints clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Logging/Loki/LokiSinkFactory.php backend/tests/Service/Logging/Loki/LokiSinkFactoryTest.php backend/config/services.yaml
git commit -m "feat(#1003): pick Loki sink by SAPI (spool only on cgi-fcgi web)"
```

---

## Task 5: Drain the spool from the maintenance tick

Add the shipper to `MaintenanceTick` and fold its report into the response.

**Files:**
- Modify: `backend/src/Service/Maintenance/MaintenanceTick.php`
- Modify: `backend/src/Service/Maintenance/MaintenanceTickReport.php` (add `logShipping`)
- Modify: `backend/tests/Service/Maintenance/MaintenanceTickTest.php`
- Modify: `backend/tests/Service/Maintenance/MaintenanceTickReportTest.php`

**Interfaces:**
- Consumes: `LokiSpoolShipper::ship(): LokiSpoolReport` (Task 3).
- Produces: `MaintenanceTickReport::__construct(array $refresh, array $recommendations, array $digests, array $logShipping)` and `toArray()` gains a `logShipping` key.

- [ ] **Step 1: Update the report test** — `backend/tests/Service/Maintenance/MaintenanceTickReportTest.php`. Add `logShipping` to the constructor call and assert the new key round-trips in `toArray()`. (Read the existing test; extend its arrays with `['shipped' => 1, 'failed' => 0]` and assert `toArray()['logShipping']` equals it.)

- [ ] **Step 2: Update `MaintenanceTickReport`**

Add the fourth promoted property and key:

```php
    public function __construct(
        public array $refresh,
        public array $recommendations,
        public array $digests,
        public array $logShipping,
    ) {
    }
```

```php
    public function toArray(): array
    {
        return [
            'refresh' => $this->refresh,
            'recommendations' => $this->recommendations,
            'digests' => $this->digests,
            'logShipping' => $this->logShipping,
        ];
    }
```

Update the class docblock's `@return` array shape to include `logShipping: array<string,mixed>`.

- [ ] **Step 3: Update the tick test** — `backend/tests/Service/Maintenance/MaintenanceTickTest.php`. The tick's constructor gains a `LokiSpoolShipper`. Construct the tick with a shipper whose spool directory is an empty temp dir (real `LokiSpoolShipper` with a `MockHttpClient` `LokiClient`), and assert `run()->toArray()['logShipping']` is `['shipped' => 0, 'failed' => 0]`. Keep the existing refresh/recommendations/digests assertions; they now also carry `logShipping`. Follow the file's existing construction style for the other three collaborators.

- [ ] **Step 4: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Maintenance/`
Expected: FAIL — `MaintenanceTick` still has the 3-arg constructor and no shipper.

- [ ] **Step 5: Wire the shipper into `MaintenanceTick`**

Add the collaborator and run it once per tick, independent of the aborted-EM guard (the shipper touches no EntityManager). Fold its report into both `MaintenanceTickReport` constructions.

```php
    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private SendDueDigests $sendDueDigests,
        private LokiSpoolShipper $logSpoolShipper,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $logShipping = $this->logSpoolShipper->ship()->toArray();

        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        if ($refresh->isAborted()) {
            return new MaintenanceTickReport(
                $refresh->toArray(),
                $this->skippedRecommendations(),
                $this->skippedDigests(),
                $logShipping,
            );
        }

        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run()->toArray();

        return new MaintenanceTickReport($refresh->toArray(), $recommendations->toArray(), $digests, $logShipping);
    }
```

Add `use App\Service\Logging\Loki\LokiSpoolShipper;`. Leave the docblock's description accurate — add one clause noting the tick also drains the Loki spool (one sentence, only if it earns its place per the house comment rule; otherwise omit).

- [ ] **Step 6: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/Maintenance/ tests/Controller/`
Expected: PASS. (If a `MaintenanceController` functional test asserts the exact tick body shape, extend it with the `logShipping` key.)

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Maintenance/MaintenanceTick.php backend/src/Service/Maintenance/MaintenanceTickReport.php backend/tests/Service/Maintenance/MaintenanceTickTest.php backend/tests/Service/Maintenance/MaintenanceTickReportTest.php
git commit -m "feat(#1003): drain the Loki spool from the maintenance tick"
```

---

## Task 6: Quality gates and full-suite verification

No new production code — this task proves the branch is green end to end.

**Files:** none created; fixes only if a gate fails.

- [ ] **Step 1: Coding standard**

Run: `cd backend && composer cs`
Expected: no violations (run `composer cs:fix` for spacing, then re-run).

- [ ] **Step 2: Static analysis** (needs a warm cache)

Run: `cd backend && php bin/console cache:warmup && composer stan`
Expected: level max, no errors.

- [ ] **Step 3: Mess detector on touched files**

Run: `cd backend && composer md`
Expected: clean.

- [ ] **Step 4: Full unit/integration suite (SQLite)**

Run: `cd backend && php bin/phpunit`
Expected: all green.

- [ ] **Step 5: Mutation gate on the diff**

Run: `cd backend && composer infection:diff`
Expected: MSI at or above the `infection.json5` floor; no escaped mutants on the new files. Add a test if a mutant escapes.

- [ ] **Step 6: Scan the dev log**

Run: `cd backend && ls -t var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`
Expected: no new deprecations or swallowed errors from the change.

- [ ] **Step 7: Commit any gate fixes**

```bash
git add -A
git commit -m "test(#1003): satisfy quality gates for SAPI-aware Loki delivery"
```

(Skip if steps 1–6 required no changes.)

---

## Self-Review notes

- **Spec coverage:** discriminator (Task 4), `LokiSink`/`DirectLokiSink` (Task 1), `SpoolLokiSink` (Task 2), `LokiSpoolShipper` (Task 3), `MaintenanceTick` integration (Task 5), fail-open (Tasks 2, 3), file handler untouched (no task modifies it), testing (each task + Task 6). All covered.
- **Durability ruling:** Task 3 finalises that a dead Loki drops a batch (fail-open, matching today), so `LokiClient` is not widened; only corrupt files are counted `failed`. Recorded for the ledger.
- **Type consistency:** `write(array $lines)`, `push(array $lines)`, `ship(): LokiSpoolReport`, `selects(string, bool): string`, `create(): LokiSink`, `MaintenanceTickReport` 4-arg constructor — consistent across tasks.
- **Test SAPI:** `cli` → `DirectLokiSink`, so the whole existing suite keeps exercising direct push; the spool path is covered by unit tests that inject the directory directly.
