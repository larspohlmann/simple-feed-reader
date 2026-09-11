# Grafana Phase 2 — Monolog → Loki push handler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Push the app's own `info`+ JSON logs to a configured Loki endpoint, buffered and flushed out of band, and never let Loki touch request handling.

**Architecture:** A `LokiPushHandler` (Monolog) buffers records and, on flush, hands them to a `LokiClient` that groups them into a Loki push payload and POSTs with a hard timeout and full error-swallow. The endpoint (URL + optional basic-auth) comes from a `LokiEndpoint` seam — Phase 2 reads it from env; Phase 3 rebinds it to the admin settings. A `kernel.terminate` listener and messenger worker listeners drive the flush.

**Tech Stack:** Symfony 7.4, PHP 8.4, Monolog 3, `Symfony\Contracts\HttpClient\HttpClientInterface`.

**Spec:** `docs/superpowers/specs/2026-09-11-grafana-observability-design.md`

**Depends on:** Phase 1 (JSON records carry `request_id`; the log line is the JSON record).

## Global Constraints

- `declare(strict_types=1)`, `final readonly class` where stateless, Clean Code (CLAUDE.md).
- Gates green before commit: `composer cs`, `composer stan`, `composer md`, `php bin/phpunit`, `composer infection:diff`.
- **Fail-open is a hard requirement:** any Loki/network error, timeout, or misconfiguration must be swallowed — a failed push never throws into request handling.
- Outbound HTTP uses `HttpClientInterface`. The Loki URL is operator-configured (not user-supplied); still route through the injected client.
- Push level is `info`+, independent of the file handler's level.

---

### Task 1: LokiEndpoint seam + env-backed implementation

**Files:**
- Create: `backend/src/Service/Logging/Loki/LokiEndpoint.php` (interface)
- Create: `backend/src/Service/Logging/Loki/EnvLokiEndpoint.php`
- Test: `backend/tests/Service/Logging/Loki/EnvLokiEndpointTest.php`

**Interfaces:**
- Produces: `interface LokiEndpoint { public function pushUrl(): ?string; public function username(): ?string; public function token(): ?string; }` and `final readonly class EnvLokiEndpoint implements LokiEndpoint` reading three constructor-injected strings (bound to env), returning null for an empty string. Phase 3 rebinds the interface to a settings-backed resolver.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\EnvLokiEndpoint;
use PHPUnit\Framework\TestCase;

final class EnvLokiEndpointTest extends TestCase
{
    public function testReturnsNullForEmptyConfiguration(): void
    {
        $endpoint = new EnvLokiEndpoint('', '', '');

        self::assertNull($endpoint->pushUrl());
        self::assertNull($endpoint->username());
        self::assertNull($endpoint->token());
    }

    public function testReturnsConfiguredValues(): void
    {
        $endpoint = new EnvLokiEndpoint('http://loki:3100/loki/api/v1/push', 'user', 'secret');

        self::assertSame('http://loki:3100/loki/api/v1/push', $endpoint->pushUrl());
        self::assertSame('user', $endpoint->username());
        self::assertSame('secret', $endpoint->token());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/EnvLokiEndpointTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`LokiEndpoint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

interface LokiEndpoint
{
    public function pushUrl(): ?string;

    public function username(): ?string;

    public function token(): ?string;
}
```

`EnvLokiEndpoint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

final readonly class EnvLokiEndpoint implements LokiEndpoint
{
    public function __construct(
        private string $pushUrl,
        private string $username,
        private string $token,
    ) {
    }

    public function pushUrl(): ?string
    {
        return '' === $this->pushUrl ? null : $this->pushUrl;
    }

    public function username(): ?string
    {
        return '' === $this->username ? null : $this->username;
    }

    public function token(): ?string
    {
        return '' === $this->token ? null : $this->token;
    }
}
```

- [ ] **Step 4: Bind env values + interface**

In `backend/config/services.yaml`, under `services:`:

```yaml
    App\Service\Logging\Loki\EnvLokiEndpoint:
        arguments:
            $pushUrl: '%env(default::GRAFANA_LOKI_PUSH_URL)%'
            $username: '%env(default::GRAFANA_LOKI_USERNAME)%'
            $token: '%env(default::GRAFANA_LOKI_TOKEN)%'

    App\Service\Logging\Loki\LokiEndpoint: '@App\Service\Logging\Loki\EnvLokiEndpoint'
```

Add empty defaults so the env vars are always defined. In `backend/.env` add:

```
###> grafana ###
GRAFANA_LOKI_PUSH_URL=
GRAFANA_LOKI_USERNAME=
GRAFANA_LOKI_TOKEN=
GRAFANA_URL=
###< grafana ###
```

Note: `%env(default::VAR)%` yields `''` when the var is empty/unset, which `EnvLokiEndpoint` maps to null. (See the memory gotcha "env-default processor treats empty as unset" — an empty value is fine here because we map empty→null ourselves.)

- [ ] **Step 5: Run test + container lint; Commit**

```bash
cd backend && php bin/phpunit tests/Service/Logging/Loki/EnvLokiEndpointTest.php && php bin/console lint:container
```
```bash
git add backend/src/Service/Logging/Loki backend/config/services.yaml backend/.env backend/tests/Service/Logging/Loki
git commit -m "feat(#983): add LokiEndpoint seam with env-backed resolver"
```

---

### Task 2: LokiClient — build the push payload and POST fail-open

**Files:**
- Create: `backend/src/Service/Logging/Loki/LokiClient.php`
- Test: `backend/tests/Service/Logging/Loki/LokiClientTest.php`

**Interfaces:**
- Consumes: `LokiEndpoint` (Task 1), `HttpClientInterface`, a PSR `LoggerInterface` is NOT injected (would recurse — never log from here).
- Produces: `final readonly class LokiClient` with `push(array $lines): void` where each element is `array{ts: string, line: string, labels: array<string,string>}`. Groups by labels into Loki streams, POSTs to `pushUrl()` with `timeout: 1.0`, optional basic auth, and swallows every throwable.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LokiClientTest extends TestCase
{
    public function testPostsGroupedStreamsWithBasicAuthAndTimeout(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 204]);
        });

        $client = new LokiClient($http, $this->endpoint('http://loki:3100/loki/api/v1/push', 'u', 't'));
        $client->push([
            ['ts' => '1700000000000000000', 'line' => '{"m":"a"}', 'labels' => ['app' => 'sfr', 'level' => 'info']],
            ['ts' => '1700000000000000001', 'line' => '{"m":"b"}', 'labels' => ['app' => 'sfr', 'level' => 'info']],
        ]);

        self::assertSame('POST', $seen['method']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $seen['url']);
        self::assertSame(1.0, $seen['options']['timeout']);
        $body = json_decode((string) $seen['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['streams']); // both share the same labels
        self::assertSame(['app' => 'sfr', 'level' => 'info'], $body['streams'][0]['stream']);
        self::assertSame([['1700000000000000000', '{"m":"a"}'], ['1700000000000000001', '{"m":"b"}']], $body['streams'][0]['values']);
        self::assertArrayHasKey('auth_basic', $seen['options']);
    }

    public function testSwallowsTransportErrors(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            throw new \RuntimeException('loki down');
        });
        $client = new LokiClient($http, $this->endpoint('http://loki:3100/loki/api/v1/push', null, null));

        $client->push([['ts' => '1', 'line' => '{}', 'labels' => ['app' => 'sfr']]]);

        self::assertTrue(true); // reached here without throwing
    }

    public function testDoesNothingWhenNoUrlConfigured(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse();
        });
        $client = new LokiClient($http, $this->endpoint(null, null, null));

        $client->push([['ts' => '1', 'line' => '{}', 'labels' => ['app' => 'sfr']]]);

        self::assertSame(0, $calls);
    }

    private function endpoint(?string $url, ?string $user, ?string $token): LokiEndpoint
    {
        return new class($url, $user, $token) implements LokiEndpoint {
            public function __construct(private ?string $url, private ?string $user, private ?string $token)
            {
            }

            public function pushUrl(): ?string
            {
                return $this->url;
            }

            public function username(): ?string
            {
                return $this->user;
            }

            public function token(): ?string
            {
                return $this->token;
            }
        };
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiClientTest.php`
Expected: FAIL — `LokiClient` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Never inject a logger here: this runs inside the logging pipeline, and a log
 * call would recurse. Every failure is swallowed so a dead Loki cannot reach a
 * request.
 */
final readonly class LokiClient
{
    private const float TIMEOUT_SECONDS = 1.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LokiEndpoint $endpoint,
    ) {
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     */
    public function push(array $lines): void
    {
        $url = $this->endpoint->pushUrl();
        if (null === $url || [] === $lines) {
            return;
        }

        try {
            $this->httpClient->request('POST', $url, $this->options($lines))->getStatusCode();
        } catch (\Throwable) {
            // fail-open: logging must never break the request
        }
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     *
     * @return array<string, mixed>
     */
    private function options(array $lines): array
    {
        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['streams' => $this->streams($lines)], JSON_THROW_ON_ERROR),
        ];

        $username = $this->endpoint->username();
        $token = $this->endpoint->token();
        if (null !== $username && null !== $token) {
            $options['auth_basic'] = [$username, $token];
        }

        return $options;
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     *
     * @return list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>
     */
    private function streams(array $lines): array
    {
        $grouped = [];
        foreach ($lines as $entry) {
            $key = json_encode($entry['labels'], JSON_THROW_ON_ERROR);
            $grouped[$key]['stream'] = $entry['labels'];
            $grouped[$key]['values'][] = [$entry['ts'], $entry['line']];
        }

        return array_values($grouped);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiClientTest.php`
Expected: PASS (3 tests). If `auth_basic` shape differs in the installed Symfony HttpClient (some versions accept a `"user:pass"` string), adjust the assertion and implementation to the real accepted form and note it.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Logging/Loki/LokiClient.php backend/tests/Service/Logging/Loki/LokiClientTest.php
git commit -m "feat(#983): add fail-open LokiClient that builds and posts push payloads"
```

---

### Task 3: LokiPushHandler — buffer records, format lines, flush

**Files:**
- Create: `backend/src/Service/Logging/Loki/LokiPushHandler.php`
- Test: `backend/tests/Service/Logging/Loki/LokiPushHandlerTest.php`

**Interfaces:**
- Consumes: `LokiClient` (Task 2), a `Monolog\Formatter\JsonFormatter` for the line.
- Produces: `final class LokiPushHandler extends \Monolog\Handler\AbstractProcessingHandler` with a size-based auto-flush and a public `flush(): void`. It labels each line with `app`, `env`, `channel`, `level`, `source=backend`. Level floor is `info`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiPushHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LokiPushHandlerTest extends TestCase
{
    public function testBuffersUntilFlushThenPostsLabelledLines(): void
    {
        $seen = null;
        $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
            $seen = $o;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $handler->handle($this->record(Level::Info, 'app', 'hello'));
        self::assertNull($seen, 'must buffer, not post per record');

        $handler->flush();

        $body = json_decode((string) $seen['body'], true, 512, JSON_THROW_ON_ERROR);
        $stream = $body['streams'][0];
        self::assertSame('sfr', $stream['stream']['app']);
        self::assertSame('prod', $stream['stream']['env']);
        self::assertSame('app', $stream['stream']['channel']);
        self::assertSame('info', $stream['stream']['level']);
        self::assertSame('backend', $stream['stream']['source']);
        $decodedLine = json_decode($stream['values'][0][1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hello', $decodedLine['message']);
    }

    public function testAutoFlushesAtThreshold(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 2);

        $handler->handle($this->record(Level::Info, 'app', 'one'));
        $handler->handle($this->record(Level::Info, 'app', 'two'));

        self::assertSame(1, $posts);
    }

    public function testDropsBelowLevelFloor(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

        $handler->handle($this->record(Level::Debug, 'app', 'noise'));
        $handler->flush();

        self::assertSame(0, $posts);
    }

    private function record(Level $level, string $channel, string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), $channel, $level, $message, [], ['request_id' => '01TEST']);
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): ?string
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

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiPushHandlerTest.php`
Expected: FAIL — `LokiPushHandler` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class LokiPushHandler extends AbstractProcessingHandler
{
    /** @var list<array{ts: string, line: string, labels: array<string, string>}> */
    private array $buffer = [];

    public function __construct(
        private readonly LokiClient $client,
        private readonly string $appLabel,
        private readonly string $envLabel,
        Level $level = Level::Info,
        private readonly int $flushThreshold = 100,
    ) {
        parent::__construct($level, true);
        $this->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, false, false, true));
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = [
            'ts' => $this->nanoTimestamp($record->datetime),
            'line' => trim($record->formatted),
            'labels' => [
                'app' => $this->appLabel,
                'env' => $this->envLabel,
                'channel' => $record->channel,
                'level' => strtolower($record->level->getName()),
                'source' => 'backend',
            ],
        ];

        if (count($this->buffer) >= $this->flushThreshold) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ([] === $this->buffer) {
            return;
        }

        $lines = $this->buffer;
        $this->buffer = [];
        $this->client->push($lines);
    }

    public function reset(): void
    {
        $this->flush();
        parent::reset();
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    private function nanoTimestamp(\DateTimeInterface $time): string
    {
        return $time->format('U').str_pad($time->format('u'), 6, '0', STR_PAD_RIGHT).'000';
    }
}
```

Note: `AbstractProcessingHandler::handle()` applies the formatter and sets `$record->formatted` before calling `write()` — set the formatter with `appendNewline: false` so the buffered line has no trailing newline. If the installed Monolog assigns `formatted` differently, format the record explicitly in `write()` via `$this->getFormatter()->format($record)` and adjust the test.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiPushHandlerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Logging/Loki/LokiPushHandler.php backend/tests/Service/Logging/Loki/LokiPushHandlerTest.php
git commit -m "feat(#983): add buffering LokiPushHandler that labels and batches lines"
```

---

### Task 4: Register the handler in Monolog + wire flush listeners

**Files:**
- Modify: `backend/config/services.yaml` (handler service)
- Modify: `backend/config/packages/monolog.yaml` (dev + prod `loki` handler)
- Create: `backend/src/EventListener/LokiFlushListener.php`
- Test: `backend/tests/EventListener/LokiFlushListenerTest.php`

**Interfaces:**
- Consumes: `LokiPushHandler` (Task 3).
- Produces: `LokiFlushListener` flushing on `kernel.terminate`, `WorkerMessageHandledEvent`, and `WorkerMessageFailedEvent`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\LokiFlushListener;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiEndpoint;
use App\Service\Logging\Loki\LokiPushHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LokiFlushListenerTest extends TestCase
{
    public function testTerminateFlushesTheHandler(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function () use (&$posts): MockResponse {
            ++$posts;

            return new MockResponse('', ['http_code' => 204]);
        });
        $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'buffered'));
        $listener = new LokiFlushListener($handler);

        $listener->onKernelTerminate($this->terminateEvent());

        self::assertSame(1, $posts);
    }

    private function terminateEvent(): TerminateEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new TerminateEvent($kernel, new Request(), new Response());
    }

    private function endpoint(): LokiEndpoint
    {
        return new class implements LokiEndpoint {
            public function pushUrl(): ?string
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

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit tests/EventListener/LokiFlushListenerTest.php`
Expected: FAIL — `LokiFlushListener` not found.

- [ ] **Step 3: Implement the listener**

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Logging\Loki\LokiPushHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final readonly class LokiFlushListener
{
    public function __construct(private LokiPushHandler $handler)
    {
    }

    #[AsEventListener(event: TerminateEvent::class)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->handler->flush();
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->handler->flush();
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $this->handler->flush();
    }
}
```

- [ ] **Step 4: Register the handler service + Monolog handler**

In `backend/config/services.yaml`:

```yaml
    App\Service\Logging\Loki\LokiPushHandler:
        arguments:
            $appLabel: 'simple-feed-reader'
            $envLabel: '%kernel.environment%'
```

In `backend/config/packages/monolog.yaml`, add a `loki` handler to `when@dev` and `when@prod` (NOT `when@test`). Use `type: service`:

dev handlers gain:

```yaml
            loki:
                type: service
                id: App\Service\Logging\Loki\LokiPushHandler
                level: info
                channels: ["!event"]
```

prod handlers gain the same block.

- [ ] **Step 5: Verify + Commit**

Run:
```bash
cd backend && php bin/phpunit tests/EventListener/LokiFlushListenerTest.php \
  && php bin/console lint:container --env=dev \
  && php bin/console lint:container --env=prod
```
Expected: pass; both containers lint clean.

```bash
git add backend/src/EventListener/LokiFlushListener.php backend/tests/EventListener/LokiFlushListenerTest.php backend/config/services.yaml backend/config/packages/monolog.yaml
git commit -m "feat(#983): register LokiPushHandler and flush it on terminate and worker events"
```

---

## Phase-2 exit checks

```bash
cd backend && composer cs && composer stan && composer md && php bin/phpunit && composer infection:diff
```

All green. Kill escaped mutants on the handler's buffering/flush and the client's grouping with assertions.

## Self-review notes

- Spec coverage: implements the "Phase 2" section — buffered push, terminate + worker flush, fail-open, basic-auth, level `info`, inert when no URL. The endpoint is env-backed here; Phase 3 rebinds `LokiEndpoint` to the settings service, so no handler/client change is needed then.
- Not covered here (later phases): admin settings (Phase 3), the Loki/Grafana containers and the `GRAFANA_LOKI_PUSH_URL` the installer writes (Phase 4), tracing (Phase 5).
- Type consistency: `LokiEndpoint::pushUrl()/username()/token()`; `LokiClient::push(list<array{ts,line,labels}>)`; `LokiPushHandler::flush()`; listener calls `flush()`.
