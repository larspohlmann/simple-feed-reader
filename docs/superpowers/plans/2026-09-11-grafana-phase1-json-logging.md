# Grafana Phase 1 — structured JSON logging + request-id Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every log line a stable per-request `request_id`, and emit JSON from the file handlers while keeping the dev console human-readable.

**Architecture:** A shared `RequestIdProvider` mints one ULID per request (HTTP request listener) or per worker message (`WorkerMessageReceivedEvent` listener). A Monolog `RequestLogProcessor` stamps `request_id` — and, when a span is active, `trace_id`/`span_id` — into every record's `extra`, reading trace ids through a `TraceContext` seam whose Phase-1 implementation returns null. The dev/prod rotating file handlers switch to `JsonFormatter`.

**Tech Stack:** Symfony 7.4, PHP 8.4, monolog-bundle ^4, `symfony/uid` (already present), Monolog 3 `LogRecord`/`ProcessorInterface`.

**Spec:** `docs/superpowers/specs/2026-09-11-grafana-observability-design.md`

## Global Constraints

- `declare(strict_types=1)` in every file; `final readonly class` where state is immutable.
- Clean Code (CLAUDE.md): names reveal intent, small methods, no boolean flag params, depend on interfaces, no comments that restate code.
- Gates green before commit: `composer cs`, `composer stan` (level max), `composer md`, `php bin/phpunit`, `composer infection:diff`.
- Tests are production code — same standards.
- Datetimes are naive UTC; JSON `datetime` stays ISO-8601.

---

### Task 1: RequestIdProvider

**Files:**
- Create: `backend/src/Service/Logging/RequestIdProvider.php`
- Test: `backend/tests/Service/Logging/RequestIdProviderTest.php`

**Interfaces:**
- Produces: `RequestIdProvider` with `current(): string` (mints a ULID on first call, then stable), `startNew(): string` (mints and stores a fresh id, returns it), `set(string $requestId): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\RequestIdProvider;
use PHPUnit\Framework\TestCase;

final class RequestIdProviderTest extends TestCase
{
    public function testCurrentIsStableAcrossCalls(): void
    {
        $provider = new RequestIdProvider();

        self::assertSame($provider->current(), $provider->current());
    }

    public function testStartNewReplacesTheCurrentId(): void
    {
        $provider = new RequestIdProvider();
        $first = $provider->current();

        $second = $provider->startNew();

        self::assertNotSame($first, $second);
        self::assertSame($second, $provider->current());
    }

    public function testSetOverridesTheCurrentId(): void
    {
        $provider = new RequestIdProvider();

        $provider->set('01J000000000000000000TEST');

        self::assertSame('01J000000000000000000TEST', $provider->current());
    }

    public function testCurrentIsALowercaseUlidShape(): void
    {
        $provider = new RequestIdProvider();

        self::assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/i', $provider->current());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/RequestIdProviderTest.php`
Expected: FAIL — class `App\Service\Logging\RequestIdProvider` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging;

use Symfony\Component\Uid\Ulid;

final class RequestIdProvider
{
    private ?string $requestId = null;

    public function current(): string
    {
        return $this->requestId ??= (string) new Ulid();
    }

    public function startNew(): string
    {
        return $this->requestId = (string) new Ulid();
    }

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Logging/RequestIdProviderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Logging/RequestIdProvider.php backend/tests/Service/Logging/RequestIdProviderTest.php
git commit -m "feat(#983): add RequestIdProvider for per-request log ids"
```

---

### Task 2: TraceContext seam + null implementation

**Files:**
- Create: `backend/src/Service/Logging/TraceContext.php` (interface)
- Create: `backend/src/Service/Logging/NullTraceContext.php`
- Test: `backend/tests/Service/Logging/NullTraceContextTest.php`

**Interfaces:**
- Produces: `interface TraceContext { public function traceId(): ?string; public function spanId(): ?string; }` and `final class NullTraceContext implements TraceContext` returning null for both. Phase 5 adds an OTel-backed implementation behind the same interface.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\NullTraceContext;
use PHPUnit\Framework\TestCase;

final class NullTraceContextTest extends TestCase
{
    public function testReturnsNoTraceOrSpanWhenTracingIsAbsent(): void
    {
        $context = new NullTraceContext();

        self::assertNull($context->traceId());
        self::assertNull($context->spanId());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/NullTraceContextTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementation**

`backend/src/Service/Logging/TraceContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging;

interface TraceContext
{
    public function traceId(): ?string;

    public function spanId(): ?string;
}
```

`backend/src/Service/Logging/NullTraceContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging;

final class NullTraceContext implements TraceContext
{
    public function traceId(): ?string
    {
        return null;
    }

    public function spanId(): ?string
    {
        return null;
    }
}
```

- [ ] **Step 4: Wire the default binding**

In `backend/config/services.yaml`, bind the interface to the null implementation (Phase 5 will re-point it). Add under `services:` after the `_defaults`/`_instanceof` block:

```yaml
    App\Service\Logging\TraceContext: '@App\Service\Logging\NullTraceContext'
```

- [ ] **Step 5: Run test + container lint**

Run: `cd backend && php bin/phpunit tests/Service/Logging/NullTraceContextTest.php && php bin/console lint:container`
Expected: PASS; container lints clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Logging/TraceContext.php backend/src/Service/Logging/NullTraceContext.php backend/config/services.yaml backend/tests/Service/Logging/NullTraceContextTest.php
git commit -m "feat(#983): add TraceContext seam with null implementation"
```

---

### Task 3: RequestLogProcessor

**Files:**
- Create: `backend/src/Service/Logging/RequestLogProcessor.php`
- Test: `backend/tests/Service/Logging/RequestLogProcessorTest.php`

**Interfaces:**
- Consumes: `RequestIdProvider` (Task 1), `TraceContext` (Task 2).
- Produces: `final readonly class RequestLogProcessor implements \Monolog\Processor\ProcessorInterface` with `__invoke(LogRecord $record): LogRecord` adding `extra['request_id']` always, and `extra['trace_id']`/`extra['span_id']` only when `TraceContext` returns non-null.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\NullTraceContext;
use App\Service\Logging\RequestIdProvider;
use App\Service\Logging\RequestLogProcessor;
use App\Service\Logging\TraceContext;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RequestLogProcessorTest extends TestCase
{
    public function testStampsRequestIdWithoutTraceWhenNoSpanIsActive(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');
        $processor = new RequestLogProcessor($provider, new NullTraceContext());

        $record = $processor($this->record());

        self::assertSame('01J000000000000000000TEST', $record->extra['request_id']);
        self::assertArrayNotHasKey('trace_id', $record->extra);
        self::assertArrayNotHasKey('span_id', $record->extra);
    }

    public function testStampsTraceAndSpanWhenASpanIsActive(): void
    {
        $provider = new RequestIdProvider();
        $processor = new RequestLogProcessor($provider, $this->tracingContext('trace-abc', 'span-xyz'));

        $record = $processor($this->record());

        self::assertSame('trace-abc', $record->extra['trace_id']);
        self::assertSame('span-xyz', $record->extra['span_id']);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello');
    }

    private function tracingContext(string $traceId, string $spanId): TraceContext
    {
        return new class($traceId, $spanId) implements TraceContext {
            public function __construct(private string $traceId, private string $spanId)
            {
            }

            public function traceId(): ?string
            {
                return $this->traceId;
            }

            public function spanId(): ?string
            {
                return $this->spanId;
            }
        };
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/RequestLogProcessorTest.php`
Expected: FAIL — `RequestLogProcessor` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final readonly class RequestLogProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestIdProvider $requestId,
        private TraceContext $trace,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $extra['request_id'] = $this->requestId->current();

        $traceId = $this->trace->traceId();
        $spanId = $this->trace->spanId();
        if (null !== $traceId && null !== $spanId) {
            $extra['trace_id'] = $traceId;
            $extra['span_id'] = $spanId;
        }

        return $record->with(extra: $extra);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Logging/RequestLogProcessorTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Register the processor as a Monolog processor**

In `backend/config/services.yaml`, add the tag so Monolog runs it (autoconfigure may already tag `ProcessorInterface`; the explicit tag is deterministic):

```yaml
    App\Service\Logging\RequestLogProcessor:
        tags:
            - { name: monolog.processor }
```

- [ ] **Step 6: Verify wiring**

Run: `cd backend && php bin/console lint:container`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Logging/RequestLogProcessor.php backend/tests/Service/Logging/RequestLogProcessorTest.php backend/config/services.yaml
git commit -m "feat(#983): stamp request_id and optional trace ids onto every log record"
```

---

### Task 4: Request + worker listeners that mint the id

**Files:**
- Create: `backend/src/EventListener/RequestIdListener.php`
- Test: `backend/tests/EventListener/RequestIdListenerTest.php`

**Interfaces:**
- Consumes: `RequestIdProvider` (Task 1).
- Produces: a listener with `onKernelRequest(RequestEvent $event): void` (only for the main request) and `onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void`, both calling `startNew()`. Registered via `#[AsEventListener]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\RequestIdListener;
use App\Service\Logging\RequestIdProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

final class RequestIdListenerTest extends TestCase
{
    public function testMainRequestStartsAFreshId(): void
    {
        $provider = new RequestIdProvider();
        $first = $provider->current();
        $listener = new RequestIdListener($provider);

        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));

        self::assertNotSame($first, $provider->current());
    }

    public function testSubRequestDoesNotChangeTheId(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');
        $listener = new RequestIdListener($provider);

        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));

        self::assertSame('01J000000000000000000TEST', $provider->current());
    }

    private function requestEvent(int $type): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent($kernel, new Request(), $type);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit tests/EventListener/RequestIdListenerTest.php`
Expected: FAIL — `RequestIdListener` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Logging\RequestIdProvider;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

final readonly class RequestIdListener
{
    public function __construct(private RequestIdProvider $requestId)
    {
    }

    #[AsEventListener(event: RequestEvent::class, priority: 4096)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestId->startNew();
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->requestId->startNew();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/EventListener/RequestIdListenerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/EventListener/RequestIdListener.php backend/tests/EventListener/RequestIdListenerTest.php
git commit -m "feat(#983): mint a fresh request id per HTTP request and worker message"
```

---

### Task 5: JSON formatter on the file handlers

**Files:**
- Modify: `backend/config/packages/monolog.yaml`
- Modify: `backend/config/services.yaml` (define the JSON formatter service)
- Test: `backend/tests/Service/Logging/JsonLogFormatTest.php`

**Interfaces:**
- Produces: a `monolog.formatter.json` service (`Monolog\Formatter\JsonFormatter`, `appendNewline: true`, `includeStacktraces: true`) referenced by the dev and prod `main` handlers.

- [ ] **Step 1: Write the failing test**

This proves the formatter emits one JSON object per line carrying the processor's `request_id`. It exercises the real classes without needing a container.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\NullTraceContext;
use App\Service\Logging\RequestIdProvider;
use App\Service\Logging\RequestLogProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class JsonLogFormatTest extends TestCase
{
    public function testFormattedLineIsJsonCarryingTheRequestId(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');
        $processor = new RequestLogProcessor($provider, new NullTraceContext());
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, false, true);

        $record = $processor(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', ['k' => 'v']));
        $line = $formatter->format($record);

        self::assertStringEndsWith("\n", $line);
        $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hello', $decoded['message']);
        self::assertSame('app', $decoded['channel']);
        self::assertSame('INFO', $decoded['level_name']);
        self::assertSame('01J000000000000000000TEST', $decoded['extra']['request_id']);
        self::assertSame('v', $decoded['context']['k']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails, then passes**

Run: `cd backend && php bin/phpunit tests/Service/Logging/JsonLogFormatTest.php`
Expected: this test uses only library classes, so it should PASS once written. If it fails, fix the assertions against the real `JsonFormatter` output (adjust `level_name` casing to what Monolog emits). It documents the contract the handlers rely on.

- [ ] **Step 3: Define the formatter service**

In `backend/config/services.yaml`, under `services:`:

```yaml
    monolog.formatter.json:
        class: Monolog\Formatter\JsonFormatter
        arguments:
            $batchMode: !php/const Monolog\Formatter\JsonFormatter::BATCH_MODE_JSON
            $appendNewline: true
            $ignoreEmptyContextAndExtra: false
            $includeStacktraces: true
```

- [ ] **Step 4: Point the file handlers at it**

In `backend/config/packages/monolog.yaml`, add `formatter: monolog.formatter.json` to the dev `main` handler and the prod `main` handler. Leave the dev `console` handler and the whole `when@test` block unchanged.

dev `main` becomes:

```yaml
            main:
                type: rotating_file
                path: "%kernel.logs_dir%/%kernel.environment%.log"
                max_files: 3
                level: debug
                channels: ["!event"]
                formatter: monolog.formatter.json
```

prod `main` becomes:

```yaml
            main:
                type: rotating_file
                path: '%kernel.logs_dir%/%kernel.environment%.log'
                level: info
                max_files: 7
                formatter: monolog.formatter.json
```

- [ ] **Step 5: Verify config + suite**

Run:
```bash
cd backend && php bin/console lint:container --env=dev \
  && php bin/console lint:container --env=prod \
  && php bin/phpunit tests/Service/Logging
```
Expected: both env containers lint clean; the logging tests pass.

- [ ] **Step 6: Manual smoke (dev)**

Run:
```bash
cd backend && php bin/console cache:clear --env=dev >/dev/null \
  && php -r 'require "vendor/autoload.php";' \
  && php bin/console debug:container monolog.formatter.json --env=dev | head -5
```
Then trigger one dev log write (e.g. run any console command) and confirm the newest `var/log/dev-*.log` line is a JSON object containing `"request_id"`:
```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 1 | python3 -m json.tool
```
Expected: valid JSON with an `extra.request_id`.

- [ ] **Step 7: Update the CLAUDE.md dev-log gotcha**

The "scan today's `dev-*.log`" gotcha now points at JSON. Add one sentence to that bullet in `CLAUDE.md`: dev files are JSON lines — read them with `jq` (e.g. `... | head -1 | xargs tail -n 50 | jq .`). Keep it to one line.

- [ ] **Step 8: Commit**

```bash
git add backend/config/packages/monolog.yaml backend/config/services.yaml backend/tests/Service/Logging/JsonLogFormatTest.php CLAUDE.md
git commit -m "feat(#983): emit JSON from the file log handlers"
```

---

## Phase-1 exit checks

Run the full gates before handing off to Phase 2:

```bash
cd backend && composer cs && composer stan && composer md && php bin/phpunit && composer infection:diff
```

Expected: all green. `infection:diff` mutates only the touched lines; escaped mutants on `RequestLogProcessor` (the trace branch) or `RequestIdProvider` must be killed by adding assertions, not by lowering `minMsi`.

## Self-review notes

- Spec coverage: this plan implements the "Phase 1" section of the spec — request id everywhere, JSON on the file handlers, the `trace_id` seam left null for Phase 5. It does **not** touch Loki (Phase 2) or the console formatter.
- Type consistency: `current()`/`startNew()`/`set()` on `RequestIdProvider`; `traceId()`/`spanId()` on `TraceContext`; the processor reads both and writes `extra['request_id'|'trace_id'|'span_id']`.
