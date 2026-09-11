# Frontend Error Logging → Loki Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture Angular frontend errors and ship them to Grafana Loki by POSTing them to a new backend ingest endpoint that reuses #983's Monolog→Loki pipeline, labelling the line `source=frontend`.

**Architecture:** The browser never talks to Loki. A root `ClientErrorReporter` (plus a pre-injector vanilla beacon) POSTs a small JSON batch to `POST /api/client-errors`. A thin controller rate-limits per IP, scrubs PII, and logs each item through a dedicated `client_errors` Monolog channel. The one #983 `LokiPushHandler` derives its `source` label from the channel (`frontend` for `client_errors`, else `backend`), buffers the line, and flushes it on `kernel.terminate` — exactly like a backend log. The endpoint returns `202 Accepted` immediately.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Monolog, Symfony RateLimiter, Symfony Validator/Serializer (`#[MapRequestPayload]`); Angular 20 standalone + signals, `fetch`+`keepalive` / `navigator.sendBeacon`. Backend tests PHPUnit (SQLite native); frontend tests Jest in the Docker frontend container.

**Spec:** `docs/superpowers/specs/2026-09-11-frontend-error-logging-design.md`

## Global Constraints

Every task's requirements implicitly include this section. Copied verbatim from the spec:

- Symfony 7.4 / PHP 8.4, `declare(strict_types=1)`, Clean Code (CLAUDE.md): `final readonly` DTOs/VOs, thin controllers (`ThinControllerRule`), guard clauses, ≤3-line comments, names reveal intent, no boolean-flag params.
- Angular 20 standalone + signals, no NgModules; component styles in sibling `.scss`; no hex/px/media-literals outside `theme/`.
- Native-iOS-viable: JSON in, `application/problem+json` out, bearer auth (optional here), no CSRF, no browser-only inputs, no `text/html` fallback.
- Quality gates stay green: `composer check` (cs + stan max + tramp), `composer md`, `php bin/phpunit`, `composer infection:diff`; frontend `npm run check`. Frontend tests run in the Docker frontend container.
- Secrets/PII never reach Loki unscrubbed (see PII scrub below).
- The Loki push path stays fail-open (a dead Loki never breaks a request) — inherited from #983's `LokiClient`.

---

## The wire contract (single source of truth)

Every POST site — the reporter, the boot-error beacon, and the `index.html` watchdog — sends **exactly** this JSON, and the DTO in Task 3 accepts **exactly** this. Change it in one place only.

```
POST {apiBaseUrl}/api/client-errors
Content-Type: application/json

{
  "errors": [
    {
      "message":      string,        // REQUIRED, ≤ 2000 chars
      "stack":        string | null, // ≤ 8000 chars
      "kind":         string | null, // error name/kind, ≤ 200 chars
      "url":          string | null, // page url, ≤ 2000 chars
      "route":        string | null, // Angular Router url, ≤ 500 chars
      "buildVersion": string | null, // "{version}+{commit}@{builtAt}", ≤ 200 chars
      "userAgent":    string | null, // ≤ 500 chars
      "at":           string | null  // client ISO timestamp, ≤ 40 chars
    }
  ]
}
```

- `errors` holds **1–10** items (a batch, so the reporter may coalesce a few).
- **`userId` is NOT a wire field.** The backend derives it from the bearer token via `#[CurrentUser] ?User`. The reporter attaches the bearer on the `fetch` path so an authed report gets tagged; the beacon path sends anonymously (still `202`, just untagged).
- `buildVersion` string is built identically at every site as `` `${version}+${commit}@${builtAt}` `` from `environments/version.ts`'s `buildVersion` (three fields — ruling #5). The `index.html` watchdog cannot import it and sends `null`.
- Response: `202 Accepted`, empty body. Validation failure → `422 application/problem+json` (existing `ApiExceptionListener`). Over budget → `429` + `Retry-After`.

---

## File structure

**Backend (create):**
- `backend/src/Dto/ClientError/ClientErrorItem.php` — one report item, `final readonly`, `#[Assert]` caps.
- `backend/src/Dto/ClientError/ClientErrorReportRequest.php` — the batch (`list<ClientErrorItem>`, `#[Assert\Count]`).
- `backend/src/Service/ClientError/ClientErrorScrubber.php` — `scrub(ClientErrorItem): ClientErrorItem`.
- `backend/src/Service/ClientError/ClientErrorRecorder.php` — `record(list<ClientErrorItem>, ?User): void`; owns the channel logger + scrubber.
- `backend/src/Controller/Api/ClientErrorController.php` — thin action.

**Backend (modify):**
- `backend/src/Service/Logging/Loki/LokiPushHandler.php` — channel→source map.
- `backend/config/packages/monolog.yaml` — declare `client_errors` channel.
- `backend/config/packages/rate_limiter.yaml` — `client_errors` limiter.
- `backend/config/packages/security.yaml` — `PUBLIC_ACCESS` access-control line.

**Frontend (create):**
- `frontend/src/app/core/client-error-beacon.ts` — Angular-import-free `ClientErrorItem` + `sendClientError` + `reportBootError`.
- `frontend/src/app/core/client-error-reporter.ts` — root `ClientErrorReporter`.
- `frontend/src/app/core/global-error-handler.ts` — `ReportingErrorHandler`.

**Frontend (modify):**
- `frontend/src/app/app.config.ts` — register `ErrorHandler` + `unhandledrejection` initializer.
- `frontend/src/app/core/auth.interceptor.ts` — report non-401 failures.
- `frontend/src/app/core/navigation-failure.ts` — also report.
- `frontend/src/app/core/boot-error-surface.ts` — call `reportBootError`.
- `frontend/src/index.html` — inline `sendBeacon` in the boot watchdog.

---

## Task 1: Loki `source` label by channel + `client_errors` channel

**Files:**
- Modify: `backend/src/Service/Logging/Loki/LokiPushHandler.php:28-45`
- Modify: `backend/config/packages/monolog.yaml:1-4`
- Test: `backend/tests/Service/Logging/Loki/LokiPushHandlerTest.php` (add), `backend/tests/Service/Logging/Loki/ClientErrorChannelSourceTest.php` (create)

**Interfaces:**
- Consumes: `LokiPushHandler::__construct(LokiClient, string $appLabel, string $envLabel, Level, int $flushThreshold)`, `->handle(LogRecord)`, `->flush()` (existing).
- Produces: the handler labels a line `'source' => 'frontend'` when `LogRecord->channel === 'client_errors'`, else `'backend'`. The Monolog channel `client_errors` exists (its logger service is `monolog.logger.client_errors`).

- [ ] **Step 1: Write the failing unit test** — append to `LokiPushHandlerTest.php`:

```php
public function testLabelsSourceFrontendForTheClientErrorsChannel(): void
{
    $seen = [];
    $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
        $seen = $o;

        return new MockResponse('', ['http_code' => 204]);
    });
    $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

    $handler->handle($this->record(Level::Error, 'client_errors', 'render blew up'));
    $handler->flush();

    /** @var array{body: string} $seen */
    $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
    /** @var array{streams: list<array{stream: array{source: string, channel: string}}>} $body */
    self::assertSame('frontend', $body['streams'][0]['stream']['source']);
    self::assertSame('client_errors', $body['streams'][0]['stream']['channel']);
}

public function testLabelsSourceBackendForEveryOtherChannel(): void
{
    $seen = [];
    $http = new MockHttpClient(function (string $m, string $u, array $o) use (&$seen): MockResponse {
        $seen = $o;

        return new MockResponse('', ['http_code' => 204]);
    });
    $handler = new LokiPushHandler(new LokiClient($http, $this->endpoint()), 'sfr', 'prod', Level::Info, 100);

    $handler->handle($this->record(Level::Info, 'app', 'ordinary'));
    $handler->flush();

    /** @var array{body: string} $seen */
    $body = json_decode($seen['body'], true, 512, JSON_THROW_ON_ERROR);
    /** @var array{streams: list<array{stream: array{source: string}}>} $body */
    self::assertSame('backend', $body['streams'][0]['stream']['source']);
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `cd backend && php bin/phpunit --filter 'testLabelsSource' tests/Service/Logging/Loki/LokiPushHandlerTest.php`
Expected: `testLabelsSourceFrontendForTheClientErrorsChannel` FAILS (`'backend'` !== `'frontend'`); the backend case passes already.

- [ ] **Step 3: Implement the channel→source map** — in `LokiPushHandler.php`, add a class constant and replace the hardcoded `'source' => 'backend'`:

```php
final class LokiPushHandler extends AbstractProcessingHandler
{
    private const array SOURCE_BY_CHANNEL = ['client_errors' => 'frontend'];
```

Then in `write()`:

```php
            'labels' => [
                'app' => $this->appLabel,
                'env' => $this->envLabel,
                'channel' => $record->channel,
                'level' => strtolower($record->level->getName()),
                'source' => self::SOURCE_BY_CHANNEL[$record->channel] ?? 'backend',
            ],
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/LokiPushHandlerTest.php`
Expected: PASS (all existing cases still green — `source=backend` unchanged for channel `app`).

- [ ] **Step 5: Declare the `client_errors` channel** — in `monolog.yaml`, extend the top-level `channels`:

```yaml
monolog:
    channels:
        - deprecation # Deprecations are logged in the dedicated "deprecation" channel when it exists
        - client_errors # Frontend error reports; LokiPushHandler labels this channel source=frontend
```

Leave every handler's `channels: ["!event"]` untouched: the one `loki` handler still handles `client_errors` and now labels it by channel (spec §Logging — no channel-exclusion surgery).

- [ ] **Step 6: Write the dispatcher-backed wiring test** — create `backend/tests/Service/Logging/Loki/ClientErrorChannelSourceTest.php`. It proves the container actually wires the `client_errors` channel logger to the real `LokiPushHandler`, and that a log through it lands in the buffer labelled `frontend` (no network — the buffer is inspected before flush, exactly like `LokiFlushListenerTest`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Loki;

use App\Service\Logging\Loki\LokiPushHandler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClientErrorChannelSourceTest extends KernelTestCase
{
    public function testAClientErrorsChannelLogIsBufferedWithSourceFrontend(): void
    {
        self::bootKernel();
        /** @var LoggerInterface $logger */
        $logger = self::getContainer()->get('monolog.logger.client_errors');

        $logger->error('render blew up', ['kind' => 'TypeError']);

        $labels = $this->firstBufferedLabels();
        self::assertSame('frontend', $labels['source']);
        self::assertSame('client_errors', $labels['channel']);
    }

    /** @return array<string, string> */
    private function firstBufferedLabels(): array
    {
        /** @var LokiPushHandler $handler */
        $handler = self::getContainer()->get(LokiPushHandler::class);
        $buffer = new \ReflectionProperty(LokiPushHandler::class, 'buffer');
        /** @var list<array{ts: string, line: string, labels: array<string, string>}> $lines */
        $lines = $buffer->getValue($handler);
        self::assertNotSame([], $lines, 'the channel logger must reach the container LokiPushHandler');

        return $lines[0]['labels'];
    }
}
```

- [ ] **Step 7: Run it and confirm it passes**

Run: `cd backend && php bin/phpunit tests/Service/Logging/Loki/ClientErrorChannelSourceTest.php`
Expected: PASS. (If the container has not registered `monolog.logger.client_errors`, the channel declaration in Step 5 is wrong — fix it, do not skip.)

- [ ] **Step 8: Commit**

```bash
cd backend && composer cs:fix && vendor/bin/phpstan analyse -c phpstan.dist.neon >/dev/null
git add src/Service/Logging/Loki/LokiPushHandler.php config/packages/monolog.yaml tests/Service/Logging/Loki
git commit -m "feat(#984): label Loki source by channel and add client_errors channel"
```

---

## Task 2: `ClientErrorScrubber`

**Files:**
- Create: `backend/src/Service/ClientError/ClientErrorScrubber.php`
- Depends on: `backend/src/Dto/ClientError/ClientErrorItem.php` — **create the DTO first** (see Task 3 Step 3 for its exact code; move that step here if executing Task 2 before Task 3, or execute Task 3 Step 3 now). The scrubber's signature needs the type.
- Test: `backend/tests/Service/ClientError/ClientErrorScrubberTest.php`

**Interfaces:**
- Consumes: `ClientErrorItem` (Task 3) — a `final readonly` with public string props `message`, `stack`, `kind`, `url`, `route`, `buildVersion`, `userAgent`, `at`.
- Produces: `ClientErrorScrubber::scrub(ClientErrorItem $item): ClientErrorItem` — returns a new item with `url` query/fragment stripped and `message`/`stack` redacted.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Service/ClientError/ClientErrorScrubberTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\ClientError;

use App\Dto\ClientError\ClientErrorItem;
use App\Service\ClientError\ClientErrorScrubber;
use PHPUnit\Framework\TestCase;

final class ClientErrorScrubberTest extends TestCase
{
    private ClientErrorScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new ClientErrorScrubber();
    }

    public function testStripsQueryAndFragmentFromUrl(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(url: 'https://app.example/reader?token=abc123#/entry/9'));

        self::assertSame('https://app.example/reader', $scrubbed->url);
    }

    public function testRedactsBearerTokensInStack(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(
            stack: 'at fetch (Authorization: Bearer eyJ0.aaa.bbb) line 3',
        ));

        self::assertStringNotContainsString('eyJ0.aaa.bbb', (string) $scrubbed->stack);
        self::assertStringContainsString('[REDACTED]', (string) $scrubbed->stack);
    }

    public function testRedactsEmailAddressesInMessage(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(message: 'save failed for jane.doe@example.com today'));

        self::assertStringNotContainsString('jane.doe@example.com', $scrubbed->message);
        self::assertStringContainsString('[REDACTED_EMAIL]', $scrubbed->message);
    }

    public function testRedactsQueryValuesEmbeddedInMessages(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(message: 'GET /api/x?apiKey=SECRETVALUE&page=2 failed'));

        self::assertStringNotContainsString('SECRETVALUE', $scrubbed->message);
        self::assertStringContainsString('page=2', $scrubbed->message, 'a low-risk key stays readable');
    }

    public function testLeavesAPlainMessageUntouched(): void
    {
        $scrubbed = $this->scrubber->scrub($this->item(message: 'Cannot read properties of undefined'));

        self::assertSame('Cannot read properties of undefined', $scrubbed->message);
    }

    private function item(
        string $message = 'boom',
        ?string $stack = null,
        ?string $url = null,
    ): ClientErrorItem {
        return new ClientErrorItem(
            message: $message,
            stack: $stack,
            kind: 'Error',
            url: $url,
            route: '/reader',
            buildVersion: 'dev+local@',
            userAgent: 'jest',
            at: '2026-09-11T00:00:00.000Z',
        );
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `cd backend && php bin/phpunit tests/Service/ClientError/ClientErrorScrubberTest.php`
Expected: FAIL (`ClientErrorScrubber` not found).

- [ ] **Step 3: Implement the scrubber** — create `backend/src/Service/ClientError/ClientErrorScrubber.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\ClientError;

use App\Dto\ClientError\ClientErrorItem;

/**
 * Redacts secrets before a client error reaches Loki. The patterns are a small
 * documented set derived from what browser stacks actually leak — bearer
 * tokens, query-string values, emails, JWTs and long hex/id runs — not an
 * attempt at exhaustive DLP.
 */
final readonly class ClientErrorScrubber
{
    /** @var array<string, string> */
    private const array PATTERNS = [
        '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/' => '[REDACTED_JWT]',
        '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [REDACTED]',
        '/[\w.+-]+@[\w-]+\.[\w.-]+/' => '[REDACTED_EMAIL]',
        '/([?&](?:token|api[_-]?key|key|secret|password|access[_-]?token)=)[^&\s\'"]+/i' => '$1[REDACTED]',
        '/\b[0-9a-fA-F]{32,}\b/' => '[REDACTED_HEX]',
    ];

    public function scrub(ClientErrorItem $item): ClientErrorItem
    {
        return new ClientErrorItem(
            message: $this->redact($item->message),
            stack: null === $item->stack ? null : $this->redact($item->stack),
            kind: $item->kind,
            url: $this->stripQueryAndFragment($item->url),
            route: $item->route,
            buildVersion: $item->buildVersion,
            userAgent: $item->userAgent,
            at: $item->at,
        );
    }

    private function redact(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    private function stripQueryAndFragment(?string $url): ?string
    {
        if (null === $url || '' === $url) {
            return $url;
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['path'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';

        return $scheme . $host . $parts['path'];
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `cd backend && php bin/phpunit tests/Service/ClientError/ClientErrorScrubberTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs:fix && composer md >/dev/null
git add src/Service/ClientError/ClientErrorScrubber.php tests/Service/ClientError src/Dto/ClientError
git commit -m "feat(#984): add ClientErrorScrubber"
```

---

## Task 3: `ClientErrorReportRequest` DTO + caps

**Files:**
- Create: `backend/src/Dto/ClientError/ClientErrorItem.php`
- Create: `backend/src/Dto/ClientError/ClientErrorReportRequest.php`
- Test: `backend/tests/Dto/ClientError/ClientErrorReportRequestTest.php`

**Interfaces:**
- Produces: `ClientErrorItem` (`final readonly`, 8 promoted props above); `ClientErrorReportRequest` with public `array $errors` typed `list<ClientErrorItem>`, `#[Assert\Count(min: 1, max: 10)]` + `#[Assert\Valid]`. Consumed by the scrubber (Task 2), the recorder and controller (Task 5), and hydrated by `#[MapRequestPayload]`.

- [ ] **Step 1: Write the failing validation test** — create `backend/tests/Dto/ClientError/ClientErrorReportRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\ClientError;

use App\Dto\ClientError\ClientErrorItem;
use App\Dto\ClientError\ClientErrorReportRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ClientErrorReportRequestTest extends KernelTestCase
{
    private function validator(): ValidatorInterface
    {
        self::bootKernel();
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);

        return $validator;
    }

    public function testAcceptsASingleValidItem(): void
    {
        $request = new ClientErrorReportRequest([$this->item('boom')]);

        self::assertCount(0, $this->validator()->validate($request));
    }

    public function testRejectsAnEmptyBatch(): void
    {
        self::assertGreaterThan(0, $this->validator()->validate(new ClientErrorReportRequest([]))->count());
    }

    public function testRejectsMoreThanTenItems(): void
    {
        $items = array_fill(0, 11, $this->item('boom'));

        self::assertGreaterThan(0, $this->validator()->validate(new ClientErrorReportRequest($items))->count());
    }

    public function testCascadesToRejectAnItemWithABlankMessage(): void
    {
        $request = new ClientErrorReportRequest([$this->item('')]);

        self::assertGreaterThan(0, $this->validator()->validate($request)->count());
    }

    public function testRejectsAnOversizedStack(): void
    {
        $request = new ClientErrorReportRequest([$this->item('boom', str_repeat('x', 8001))]);

        self::assertGreaterThan(0, $this->validator()->validate($request)->count());
    }

    private function item(string $message, ?string $stack = null): ClientErrorItem
    {
        return new ClientErrorItem(
            message: $message,
            stack: $stack,
            kind: 'Error',
            url: 'https://app.example/reader',
            route: '/reader',
            buildVersion: 'dev+local@',
            userAgent: 'jest',
            at: '2026-09-11T00:00:00.000Z',
        );
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `cd backend && php bin/phpunit tests/Dto/ClientError/ClientErrorReportRequestTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement `ClientErrorItem`** — create `backend/src/Dto/ClientError/ClientErrorItem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\ClientError;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClientErrorItem
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 2000)]
        public string $message = '',
        #[Assert\Length(max: 8000)]
        public ?string $stack = null,
        #[Assert\Length(max: 200)]
        public ?string $kind = null,
        #[Assert\Length(max: 2000)]
        public ?string $url = null,
        #[Assert\Length(max: 500)]
        public ?string $route = null,
        #[Assert\Length(max: 200)]
        public ?string $buildVersion = null,
        #[Assert\Length(max: 500)]
        public ?string $userAgent = null,
        #[Assert\Length(max: 40)]
        public ?string $at = null,
    ) {
    }
}
```

- [ ] **Step 4: Implement `ClientErrorReportRequest`** — create `backend/src/Dto/ClientError/ClientErrorReportRequest.php`. The `@param list<ClientErrorItem>` phpdoc is load-bearing: it is how the Serializer knows to hydrate each array element into a `ClientErrorItem` under `#[MapRequestPayload]`.

```php
<?php

declare(strict_types=1);

namespace App\Dto\ClientError;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClientErrorReportRequest
{
    /** @var list<ClientErrorItem> */
    public array $errors;

    /**
     * @param list<ClientErrorItem> $errors
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1, max: 10)]
        array $errors = [],
    ) {
        $this->errors = array_values($errors);
    }
}
```

- [ ] **Step 5: Run it and confirm it passes**

Run: `cd backend && php bin/phpunit tests/Dto/ClientError/ClientErrorReportRequestTest.php`
Expected: PASS. `testCascadesToRejectAnItemWithABlankMessage` proves `#[Assert\Valid]` cascades into hand-built items; the endpoint test in Task 5 proves the same cascade survives `#[MapRequestPayload]` hydration.

- [ ] **Step 6: Commit**

```bash
cd backend && composer cs:fix && vendor/bin/phpstan analyse -c phpstan.dist.neon >/dev/null
git add src/Dto/ClientError tests/Dto/ClientError
git commit -m "feat(#984): add ClientErrorReportRequest DTO with caps"
```

---

## Task 4: `client_errors` rate limiter

**Files:**
- Modify: `backend/config/packages/rate_limiter.yaml` (append a limiter)
- Test: covered by the `429` case in Task 5 (a limiter has no unit surface of its own; a config-only change is verified through the endpoint that consumes it).

**Interfaces:**
- Produces: a `client_errors` limiter that autowires as `RateLimiterFactoryInterface $clientErrorsLimiter`, consumed in Task 5 via `RateLimitGuard::enforceForClient($this->clientErrorsLimiter, $httpRequest)`.

- [ ] **Step 1: Add the limiter** — append to `rate_limiter.yaml` under `framework.rate_limiter`, matching the file's documented house style (sliding window, `cache.rate_limiter`, a comment saying why the number):

```yaml
        # Anonymous frontend error ingest (#984), so the only key is the client
        # IP — same trusted-proxy caveat as setup/registration above. Sized for a
        # genuinely broken client that fires a burst while it flails, but capped
        # so one wedged tab cannot flood the endpoint: 30 in 1 minute per IP. The
        # reporter also dedupes and throttles client-side, so this is the outer
        # backstop, not the primary throttle. Sliding window and the same pool as
        # its neighbours, for the reasons documented above.
        client_errors:
            policy: 'sliding_window'
            limit: 30
            interval: '1 minute'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 2: Verify the factory autowires** — confirm the container knows the named factory:

Run: `cd backend && bin/console debug:container --parameter-bag >/dev/null && bin/console lint:container`
Expected: no error. (The concrete `$clientErrorsLimiter` binding is exercised by Task 5's `429` test; a typo in the limiter name surfaces there as an unresolvable argument.)

- [ ] **Step 3: Commit**

```bash
cd backend && git add config/packages/rate_limiter.yaml
git commit -m "feat(#984): add client_errors rate limiter"
```

---

## Task 5: `/api/client-errors` endpoint + recorder + security + wiring

**Files:**
- Create: `backend/src/Service/ClientError/ClientErrorRecorder.php`
- Create: `backend/src/Controller/Api/ClientErrorController.php`
- Modify: `backend/config/packages/security.yaml:109-111` (insert access-control line)
- Test: `backend/tests/Controller/Api/ClientErrorControllerTest.php`

**Interfaces:**
- Consumes: `ClientErrorReportRequest`/`ClientErrorItem` (Task 3), `ClientErrorScrubber::scrub()` (Task 2), `RateLimitGuard::enforceForClient(RateLimiterFactoryInterface, Request)` (existing), the `client_errors` channel logger `monolog.logger.client_errors` (Task 1), `$clientErrorsLimiter` (Task 4).
- Produces: `POST /api/client-errors` → `202`; `ClientErrorRecorder::record(list<ClientErrorItem> $items, ?User $user): void`.

- [ ] **Step 1: Write the failing functional test** — create `backend/tests/Controller/Api/ClientErrorControllerTest.php`. It reboots-and-clears the filesystem rate-limiter pool (same reason as `SetupControllerTest`/`MeDigestTestControllerTest`), asserts anonymous `202`, an authed report tags the user and labels the buffered Loki line `frontend`, and the `422`/`429` guards:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Logging\Loki\LokiPushHandler;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ClientErrorControllerTest extends ApiTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $pool = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        self::ensureKernelShutdown();
    }

    public function testAnonymousReportIsAccepted(): void
    {
        $client = self::createClient();

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
    }

    public function testAcceptedReportIsBufferedForLokiAsFrontend(): void
    {
        $client = self::createClient();

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
        $labels = $this->firstBufferedLabels($client);
        self::assertSame('frontend', $labels['source']);
        self::assertSame('client_errors', $labels['channel']);
    }

    public function testAuthenticatedReportTagsTheUserId(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('reporter@example.com');
        $this->authenticate($client, $user);

        $this->post($client, [$this->item()]);

        self::assertResponseStatusCodeSame(202);
        $line = json_decode($this->firstBufferedLine($client), true, 512, \JSON_THROW_ON_ERROR);
        /** @var array{context: array<string, mixed>} $line */
        self::assertSame($user->getId(), $line['context']['userId']);
    }

    public function testRejectsTooManyItems(): void
    {
        $client = self::createClient();

        $this->post($client, array_fill(0, 11, $this->item()));

        $this->assertRejected($client, 422);
    }

    public function testRejectsAnItemWithABlankMessage(): void
    {
        $client = self::createClient();

        $this->post($client, [$this->item(message: '')]);

        $this->assertRejected($client, 422);
    }

    public function testRateLimitsAFlood(): void
    {
        $client = self::createClient();

        for ($i = 0; $i < 30; ++$i) {
            $this->post($client, [$this->item()]);
            self::assertResponseStatusCodeSame(202);
        }
        $this->post($client, [$this->item()]);

        $this->assertRejected($client, 429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }

    private function authenticate(KernelBrowser $client, User $user): void
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    /** @param list<array<string, mixed>> $errors */
    private function post(KernelBrowser $client, array $errors): void
    {
        $client->request(
            'POST',
            '/api/client-errors',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['errors' => $errors], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function item(string $message = 'Cannot read properties of undefined'): array
    {
        return [
            'message' => $message,
            'stack' => 'at Foo (main.js:1:2)',
            'kind' => 'TypeError',
            'url' => 'https://app.example/reader?token=secret#/entry/9',
            'route' => '/reader',
            'buildVersion' => 'dev+local@',
            'userAgent' => 'jest',
            'at' => '2026-09-11T00:00:00.000Z',
        ];
    }

    /** @return array<string, string> */
    private function firstBufferedLabels(KernelBrowser $client): array
    {
        return $this->firstBuffered($client)['labels'];
    }

    private function firstBufferedLine(KernelBrowser $client): string
    {
        return $this->firstBuffered($client)['line'];
    }

    /** @return array{ts: string, line: string, labels: array<string, string>} */
    private function firstBuffered(KernelBrowser $client): array
    {
        /** @var LokiPushHandler $handler */
        $handler = $client->getContainer()->get(LokiPushHandler::class);
        $buffer = new \ReflectionProperty(LokiPushHandler::class, 'buffer');
        /** @var list<array{ts: string, line: string, labels: array<string, string>}> $lines */
        $lines = $buffer->getValue($handler);
        self::assertNotSame([], $lines, 'the report must reach the Loki buffer');

        return $lines[0];
    }
}
```

Note: the buffered-line assertions require the JSON file/loki formatter to include `context`. `monolog.formatter.json` in the test env captures the `error`-level record; the `main` handler is `fingers_crossed` at `action_level: error`, so an `error` log flushes through — no extra config needed. The `LokiPushHandler`'s `info` floor passes an `error` record.

- [ ] **Step 2: Run it and confirm it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/ClientErrorControllerTest.php`
Expected: FAIL — first the route 404s / access-control 401s, then (after the controller lands) the wiring assertions.

- [ ] **Step 3: Implement the recorder** — create `backend/src/Service/ClientError/ClientErrorRecorder.php`. The scrub-then-log composition lives here, not on the controller (ThinControllerRule):

```php
<?php

declare(strict_types=1);

namespace App\Service\ClientError;

use App\Dto\ClientError\ClientErrorItem;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scrubs each reported item and logs it through the client_errors channel, the
 * one seam that feeds the #983 Loki pipeline with source=frontend. The channel
 * logger is injected by service id because it is a per-channel Monolog logger,
 * not the default autowired one.
 */
final readonly class ClientErrorRecorder
{
    public function __construct(
        private ClientErrorScrubber $scrubber,
        #[Autowire(service: 'monolog.logger.client_errors')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ClientErrorItem> $items
     */
    public function record(array $items, ?User $user): void
    {
        foreach ($items as $item) {
            $scrubbed = $this->scrubber->scrub($item);
            $this->logger->error($scrubbed->message, $this->context($scrubbed, $user));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ClientErrorItem $item, ?User $user): array
    {
        return array_filter([
            'kind' => $item->kind,
            'url' => $item->url,
            'route' => $item->route,
            'buildVersion' => $item->buildVersion,
            'userAgent' => $item->userAgent,
            'stack' => $item->stack,
            'at' => $item->at,
            'userId' => $user?->getId(),
        ], static fn (mixed $value): bool => null !== $value);
    }
}
```

- [ ] **Step 4: Implement the controller** — create `backend/src/Controller/Api/ClientErrorController.php`. The action reads, rate-limits, delegates, returns `202` — no private method carries work:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\ClientError\ClientErrorReportRequest;
use App\Entity\User;
use App\Service\ClientError\ClientErrorRecorder;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/client-errors', name: 'api_client_errors', methods: ['POST'])]
final readonly class ClientErrorController
{
    public function __construct(
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $clientErrorsLimiter,
        private ClientErrorRecorder $recorder,
    ) {
    }

    public function __invoke(
        #[MapRequestPayload] ClientErrorReportRequest $request,
        Request $httpRequest,
        #[CurrentUser] ?User $user,
    ): Response {
        $this->rateLimitGuard->enforceForClient($this->clientErrorsLimiter, $httpRequest);
        $this->recorder->record($request->errors, $user);

        return new Response(status: Response::HTTP_ACCEPTED);
    }
}
```

Note (spec ruling #3): `#[CurrentUser] ?User $user` is new ground in this repo — the `api` firewall runs `jwt: ~` on every `^/api` path, so a valid bearer resolves a user even on a `PUBLIC_ACCESS` route, and Symfony passes `null` when the request is anonymous.

- [ ] **Step 5: Open the route in `security.yaml`** — insert **above** the `^/api/` catch-all, after the favicon carve-out. `access_control` is first-match-wins:

```yaml
        - { path: '^/api/catalog/feeds/\d+/favicon$', roles: PUBLIC_ACCESS }
        # Frontend error ingest (#984). Anonymous is allowed — errors happen
        # while logged out — but the api firewall still runs the JWT
        # authenticator, so a logged-in report resolves a #[CurrentUser].
        - { path: ^/api/client-errors$, roles: PUBLIC_ACCESS }
        - { path: ^/api/admin/, roles: ROLE_ADMIN }
        - { path: ^/api/, roles: IS_AUTHENTICATED_FULLY }
```

- [ ] **Step 6: Run it and confirm it passes**

Run: `cd backend && php bin/phpunit tests/Controller/Api/ClientErrorControllerTest.php`
Expected: PASS (all six cases). If `422` fails on `testRejectsTooManyItems` but not the blank-message case, the `#[Assert\Valid]` cascade did not survive hydration — confirm `errors` carries the `@param list<ClientErrorItem>` phpdoc (Task 3 Step 4).

- [ ] **Step 7: Run the backend gates and scan the dev log**

Run: `cd backend && composer check && composer md && bin/console cache:warmup && bin/console lint:container`
Then: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .` — confirm no deprecation or swallowed error from the new wiring.
Expected: green; `ThinControllerRule` reports nothing on `ClientErrorController` (the action has no work-carrying private method).

- [ ] **Step 8: Commit**

```bash
cd backend && composer cs:fix
git add src/Service/ClientError/ClientErrorRecorder.php src/Controller/Api/ClientErrorController.php config/packages/security.yaml tests/Controller/Api/ClientErrorControllerTest.php
git commit -m "feat(#984): add client-errors ingest endpoint"
```

---

## Task 6: Frontend `ClientErrorReporter` + beacon

**Files:**
- Create: `frontend/src/app/core/client-error-beacon.ts`
- Create: `frontend/src/app/core/client-error-reporter.ts`
- Test: `frontend/src/app/core/client-error-reporter.spec.ts`

**Interfaces:**
- Produces:
  - `client-error-beacon.ts` (NO Angular imports): `interface ClientErrorItem` (matches the wire contract), `sendClientError(baseUrl: string, item: ClientErrorItem, bearerToken?: string | null): void`, `reportBootError(baseUrl: string, error: unknown): void`.
  - `client-error-reporter.ts`: `@Injectable({ providedIn: 'root' }) class ClientErrorReporter` with `report(error: unknown, kind?: string): void`.
- Consumes: `API_BASE_URL` (`core/api.ts`), `Router` (`@angular/router`), `TokenStore` (`core/token.store`), `buildVersion` (`environments/version.ts`), `POST /api/client-errors` (Task 5).

- [ ] **Step 1: Write the failing Jest test** — create `frontend/src/app/core/client-error-reporter.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { ClientErrorReporter } from './client-error-reporter';
import { TokenStore } from './token.store';

describe('ClientErrorReporter', () => {
  let fetchMock: jest.Mock;

  const setup = (token: string | null = null) => {
    TestBed.configureTestingModule({
      providers: [
        { provide: API_BASE_URL, useValue: '' },
        { provide: Router, useValue: { url: '/reader' } },
        { provide: TokenStore, useValue: { token: () => token } },
      ],
    });
    return TestBed.inject(ClientErrorReporter);
  };

  beforeEach(() => {
    fetchMock = jest.fn().mockResolvedValue({ ok: true });
    (globalThis as unknown as { fetch: jest.Mock }).fetch = fetchMock;
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  afterEach(() => jest.restoreAllMocks());

  it('POSTs a tagged batch of one to /api/client-errors', () => {
    setup().report(new TypeError('boom'));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/client-errors');
    expect(init.keepalive).toBe(true);
    const body = JSON.parse(init.body);
    expect(body.errors).toHaveLength(1);
    expect(body.errors[0]).toMatchObject({ message: 'boom', kind: 'TypeError', route: '/reader' });
    expect(typeof body.errors[0].buildVersion).toBe('string');
  });

  it('attaches the bearer token when the session has one', () => {
    setup('jwt-abc').report(new Error('boom'));

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer jwt-abc');
  });

  it('dedupes identical errors inside the throttle window', () => {
    const reporter = setup();
    reporter.report(new Error('same'));
    reporter.report(new Error('same'));

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('never throws back into the caller when delivery fails', () => {
    fetchMock.mockImplementation(() => {
      throw new Error('network down');
    });

    expect(() => setup().report(new Error('boom'))).not.toThrow();
  });
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/client-error-reporter.spec.ts`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement the beacon** — create `frontend/src/app/core/client-error-beacon.ts`:

```ts
// src/app/core/client-error-beacon.ts
// No Angular imports: this module is used from boot-error-surface.ts, which
// runs before the injector exists. `buildVersion` and `environment` are plain
// constants, so importing them here keeps that promise.
import { buildVersion } from '../../environments/version';

export interface ClientErrorItem {
  message: string;
  stack: string | null;
  kind: string | null;
  url: string | null;
  route: string | null;
  buildVersion: string | null;
  userAgent: string | null;
  at: string | null;
}

const ENDPOINT_PATH = '/api/client-errors';

/** The one place the three fields become the one wire string (ruling #5). */
export function buildVersionTag(): string {
  return `${buildVersion.version}+${buildVersion.commit}@${buildVersion.builtAt}`;
}

/** Fire-and-forget. Prefers fetch+keepalive; falls back to sendBeacon. Never throws. */
export function sendClientError(baseUrl: string, item: ClientErrorItem, bearerToken?: string | null): void {
  const url = `${baseUrl}${ENDPOINT_PATH}`;
  const body = JSON.stringify({ errors: [item] });
  try {
    if (typeof fetch === 'function') {
      const headers: Record<string, string> = { 'Content-Type': 'application/json' };
      if (bearerToken) {
        headers.Authorization = `Bearer ${bearerToken}`;
      }
      void fetch(url, { method: 'POST', keepalive: true, headers, body }).catch(() => undefined);
      return;
    }
    navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
  } catch {
    // Reporting an error must never raise one.
  }
}

/** Boot-time convenience for callers with no injector (boot-error-surface.ts). */
export function reportBootError(baseUrl: string, error: unknown): void {
  const normalized = error instanceof Error ? error : new Error(String(error));
  sendClientError(baseUrl, {
    message: normalized.message || String(error),
    stack: normalized.stack ?? null,
    kind: normalized.name || 'BootError',
    url: typeof location !== 'undefined' ? location.href : null,
    route: null,
    buildVersion: buildVersionTag(),
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : null,
    at: new Date().toISOString(),
  });
}
```

- [ ] **Step 4: Implement the reporter** — create `frontend/src/app/core/client-error-reporter.ts`:

```ts
// src/app/core/client-error-reporter.ts
import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { ClientErrorItem, buildVersionTag, sendClientError } from './client-error-beacon';
import { TokenStore } from './token.store';

/**
 * The one root sink for reportable frontend errors (#984). Tags each report
 * with the route, build version, and user agent, attaches the bearer so the
 * backend can resolve the user, and dedupes/throttles so one broken render
 * cannot flood the endpoint. Delivery is fire-and-forget and never throws back.
 */
@Injectable({ providedIn: 'root' })
export class ClientErrorReporter {
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly router = inject(Router);
  private readonly tokens = inject(TokenStore);

  private static readonly DEDUPE_WINDOW_MS = 10_000;
  private static readonly RATE_WINDOW_MS = 60_000;
  private static readonly MAX_PER_WINDOW = 20;

  private readonly lastSentBySignature = new Map<string, number>();
  private windowStartedAt = 0;
  private sentInWindow = 0;

  report(error: unknown, kind = 'Error'): void {
    try {
      const item = this.toItem(error, kind);
      if (this.suppressed(item)) {
        return;
      }
      sendClientError(this.baseUrl, item, this.tokens.token());
    } catch {
      // A reporter that throws would defeat the handler that called it.
    }
  }

  private toItem(error: unknown, kind: string): ClientErrorItem {
    const normalized = error instanceof Error ? error : new Error(String(error));
    return {
      message: normalized.message || String(error),
      stack: normalized.stack ?? null,
      kind: error instanceof Error ? error.name : kind,
      url: window.location.href,
      route: this.router.url,
      buildVersion: buildVersionTag(),
      userAgent: navigator.userAgent,
      at: new Date().toISOString(),
    };
  }

  private suppressed(item: ClientErrorItem): boolean {
    const now = Date.now();
    if (now - this.windowStartedAt > ClientErrorReporter.RATE_WINDOW_MS) {
      this.windowStartedAt = now;
      this.sentInWindow = 0;
    }
    if (this.sentInWindow >= ClientErrorReporter.MAX_PER_WINDOW) {
      return true;
    }

    const signature = `${item.kind}::${item.message}::${(item.stack ?? '').split('\n')[0]}`;
    const lastSentAt = this.lastSentBySignature.get(signature);
    if (lastSentAt !== undefined && now - lastSentAt < ClientErrorReporter.DEDUPE_WINDOW_MS) {
      return true;
    }
    this.lastSentBySignature.set(signature, now);
    this.sentInWindow += 1;
    return false;
  }
}
```

- [ ] **Step 5: Run it and confirm it passes**

Run: `docker compose exec -T frontend npx jest src/app/core/client-error-reporter.spec.ts`
Expected: PASS (all four cases).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/core/client-error-beacon.ts frontend/src/app/core/client-error-reporter.ts frontend/src/app/core/client-error-reporter.spec.ts
git commit -m "feat(#984): add ClientErrorReporter and beacon"
```

---

## Task 7: Global `ErrorHandler` + `unhandledrejection` + app.config wiring

**Files:**
- Create: `frontend/src/app/core/global-error-handler.ts`
- Modify: `frontend/src/app/app.config.ts:33-87` (providers)
- Test: `frontend/src/app/core/global-error-handler.spec.ts`

**Interfaces:**
- Consumes: `ClientErrorReporter.report()` (Task 6), Angular `ErrorHandler`.
- Produces: `ReportingErrorHandler implements ErrorHandler`; registered as `{ provide: ErrorHandler, useClass: ReportingErrorHandler }`; a `provideAppInitializer` that binds one `window` `unhandledrejection` listener.

- [ ] **Step 1: Write the failing test** — create `frontend/src/app/core/global-error-handler.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { ClientErrorReporter } from './client-error-reporter';
import { ReportingErrorHandler } from './global-error-handler';

describe('ReportingErrorHandler', () => {
  it('logs to the console and reports the error', () => {
    const report = jest.fn();
    const consoleError = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    TestBed.configureTestingModule({
      providers: [ReportingErrorHandler, { provide: ClientErrorReporter, useValue: { report } }],
    });

    const error = new Error('boom');
    TestBed.inject(ReportingErrorHandler).handleError(error);

    expect(consoleError).toHaveBeenCalledWith(error);
    expect(report).toHaveBeenCalledWith(error);
  });
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/global-error-handler.spec.ts`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement the handler** — create `frontend/src/app/core/global-error-handler.ts`:

```ts
// src/app/core/global-error-handler.ts
import { ErrorHandler, Injectable, inject } from '@angular/core';
import { ClientErrorReporter } from './client-error-reporter';

/**
 * Angular's default ErrorHandler only logs. This one keeps that console output
 * (developers and the boot-error trace still need it) and additionally reports
 * the error to Loki via the backend (#984).
 */
@Injectable()
export class ReportingErrorHandler implements ErrorHandler {
  private readonly reporter = inject(ClientErrorReporter);

  handleError(error: unknown): void {
    console.error(error);
    this.reporter.report(error);
  }
}
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `docker compose exec -T frontend npx jest src/app/core/global-error-handler.spec.ts`
Expected: PASS.

- [ ] **Step 5: Wire it into `app.config.ts`** — add the imports and two providers. Add near the other `core/` imports:

```ts
import { ErrorHandler } from '@angular/core';
import { ClientErrorReporter } from './core/client-error-reporter';
import { ReportingErrorHandler } from './core/global-error-handler';
```

(`ErrorHandler` joins the existing `@angular/core` import group.) Then, inside the `providers` array, after `provideBrowserGlobalErrorListeners(),`:

```ts
    { provide: ErrorHandler, useClass: ReportingErrorHandler },
```

And append, after the last `provideAppInitializer(...)`:

```ts
    // One global handler for promise rejections nothing awaited (#984). Runs in
    // an injection context so it can resolve the root reporter.
    provideAppInitializer(() => {
      const reporter = inject(ClientErrorReporter);
      window.addEventListener('unhandledrejection', (event) =>
        reporter.report(event.reason, 'UnhandledRejection'),
      );
    }),
```

- [ ] **Step 6: Run the frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: green (ESLint + Prettier + Stylelint + Jest).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/core/global-error-handler.ts frontend/src/app/core/global-error-handler.spec.ts frontend/src/app/app.config.ts
git commit -m "feat(#984): report uncaught errors and rejections"
```

---

## Task 8: Report non-401 HTTP failures from the interceptor

**Files:**
- Modify: `frontend/src/app/core/auth.interceptor.ts:12-39`
- Test: `frontend/src/app/core/auth.interceptor.spec.ts` (add cases)

**Interfaces:**
- Consumes: `ClientErrorReporter.report()` (Task 6).
- Produces: the interceptor reports 5xx and status-0 failures, does NOT report 401, and never reports failures of the `/api/client-errors` endpoint itself (no report loop).

- [ ] **Step 1: Write the failing tests** — add to `frontend/src/app/core/auth.interceptor.spec.ts`. Provide a `ClientErrorReporter` stub in the test module and drive the interceptor through `HttpClient` with `provideHttpClientTesting` (match the file's existing harness):

```ts
it('reports a 500 failure', () => {
  http.get('/api/entries').subscribe({ next: () => {}, error: () => {} });
  httpTesting.expectOne('/api/entries').flush('boom', { status: 500, statusText: 'Server Error' });

  expect(reportSpy).toHaveBeenCalledTimes(1);
});

it('reports a network failure (status 0)', () => {
  http.get('/api/entries').subscribe({ next: () => {}, error: () => {} });
  httpTesting.expectOne('/api/entries').error(new ProgressEvent('error'), { status: 0, statusText: '' });

  expect(reportSpy).toHaveBeenCalledTimes(1);
});

it('does NOT report a 401', () => {
  http.get('/api/entries').subscribe({ next: () => {}, error: () => {} });
  httpTesting.expectOne('/api/entries').flush('no', { status: 401, statusText: 'Unauthorized' });

  expect(reportSpy).not.toHaveBeenCalled();
});

it('does NOT report a failure of the client-errors endpoint itself', () => {
  http.post('/api/client-errors', {}).subscribe({ next: () => {}, error: () => {} });
  httpTesting.expectOne('/api/client-errors').flush('boom', { status: 500, statusText: 'Server Error' });

  expect(reportSpy).not.toHaveBeenCalled();
});
```

In the harness `beforeEach`, register the stub and capture the spy (add alongside the existing providers):

```ts
reportSpy = jest.fn();
TestBed.configureTestingModule({
  providers: [
    // ...existing providers (API_BASE_URL, TokenStore, Router, ReaderLocationService)...
    { provide: ClientErrorReporter, useValue: { report: reportSpy } },
    provideHttpClient(withInterceptors([authInterceptor])),
    provideHttpClientTesting(),
  ],
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/auth.interceptor.spec.ts`
Expected: the new cases FAIL (reporter never called).

- [ ] **Step 3: Implement the reporting branch** — edit `auth.interceptor.ts`. Add the import and one `inject`, then a guarded report in `catchError` (the 401 block is untouched):

```ts
import { ClientErrorReporter } from './client-error-reporter';
```

Inside the interceptor function, alongside the other `inject(...)` calls:

```ts
  const reporter = inject(ClientErrorReporter);
```

Then the `catchError` body becomes:

```ts
    catchError((err) => {
      if (err.status === 401) {
        const requestUsesCurrentToken = isApi && token !== null && token === tokens.token();
        tokens.clear();
        if (requestUsesCurrentToken) {
          readerLocation.rememberSavedReaderUrlForSignIn();
        }
        void router.navigate(['/login']);
        return throwError(() => err);
      }
      // Report real breakage, but never the report endpoint's own failure — that
      // would loop. 401 is handled above and is not breakage worth reporting.
      const isClientErrorEndpoint = req.url.includes('/api/client-errors');
      if (!isClientErrorEndpoint && (err.status === 0 || err.status >= 500)) {
        reporter.report(new Error(`HTTP ${err.status} ${req.method} ${req.url}`), 'HttpError');
      }
      return throwError(() => err);
    }),
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `docker compose exec -T frontend npx jest src/app/core/auth.interceptor.spec.ts`
Expected: PASS (new cases plus the file's existing 401 cases).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/auth.interceptor.ts frontend/src/app/core/auth.interceptor.spec.ts
git commit -m "feat(#984): report non-401 HTTP failures"
```

---

## Task 9: Fold in navigation + boot-error + index.html watchdog

**Files:**
- Modify: `frontend/src/app/core/navigation-failure.ts:25-32`
- Modify: `frontend/src/app/core/boot-error-surface.ts:15-20`
- Modify: `frontend/src/index.html:54-85`
- Test: `frontend/src/app/core/navigation-failure.spec.ts` (add), `frontend/src/app/core/boot-error-surface.spec.ts` (create)

**Interfaces:**
- Consumes: `ClientErrorReporter.report()` (Task 6), `reportBootError()`/`sendClientError()` (Task 6), `environment.apiBaseUrl`.
- Produces: a navigation failure reports through the reporter once the app has rendered; a boot-error reveal also beacons; the `index.html` watchdog beacons the same wire shape on a boot stall.

- [ ] **Step 1: Write the failing navigation test** — add to `frontend/src/app/core/navigation-failure.spec.ts`. Provide a reporter stub and assert `report` fires on the banner (post-render) path:

```ts
it('reports the failure through the client-error reporter', () => {
  const report = jest.fn();
  TestBed.configureTestingModule({ providers: [{ provide: ClientErrorReporter, useValue: { report } }] });
  const reporter = TestBed.inject(NavigationFailureReporter);
  reporter.noteNavigationSucceeded();

  const error = new Error('chunk load failed');
  reporter.report(error);

  expect(report).toHaveBeenCalledWith(error);
});
```

(Add `import { ClientErrorReporter } from './client-error-reporter';` and include the provider in the existing `beforeEach` module too, since `NavigationFailureReporter` will now inject it.)

- [ ] **Step 2: Run it and confirm it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/navigation-failure.spec.ts`
Expected: FAIL (reporter not injected / not called).

- [ ] **Step 3: Fold the reporter into `NavigationFailureReporter`** — inject it and report from `report()`. The pre-render path already reveals the static surface (which beacons in Step 5); the post-render path adds the reporter call:

```ts
import { Injectable, Signal, inject, signal } from '@angular/core';
import { revealBootErrorSurface } from './boot-error-surface';
import { ClientErrorReporter } from './client-error-reporter';
```

```ts
export class NavigationFailureReporter {
  private readonly reporter = inject(ClientErrorReporter);
  private readonly hasRendered = signal(false);
  private readonly bannerVisible = signal(false);

  readonly failed: Signal<boolean> = this.bannerVisible.asReadonly();

  report(error: unknown): void {
    if (!this.hasRendered()) {
      revealBootErrorSurface(error);
      return;
    }
    console.error(error);
    this.reporter.report(error);
    this.bannerVisible.set(true);
  }
```

(`noteNavigationSucceeded()` is unchanged.)

- [ ] **Step 4: Run it and confirm it passes**

Run: `docker compose exec -T frontend npx jest src/app/core/navigation-failure.spec.ts`
Expected: PASS.

- [ ] **Step 5: Beacon from `boot-error-surface.ts` + test** — the reveal now also fires a plain-function beacon (still Angular-import-free). Create `frontend/src/app/core/boot-error-surface.spec.ts`:

```ts
import * as beacon from './client-error-beacon';
import { revealBootErrorSurface } from './boot-error-surface';

describe('revealBootErrorSurface', () => {
  it('reveals the static surface and beacons the error', () => {
    const surface = document.createElement('div');
    surface.id = 'boot-error';
    surface.hidden = true;
    document.body.appendChild(surface);
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
    const reportBootError = jest.spyOn(beacon, 'reportBootError').mockImplementation(() => undefined);

    const error = new Error('bootstrap rejected');
    revealBootErrorSurface(error);

    expect(surface.hasAttribute('hidden')).toBe(false);
    expect(reportBootError).toHaveBeenCalledWith('', error);

    surface.remove();
    jest.restoreAllMocks();
  });
});
```

Then edit `boot-error-surface.ts` (`''` is `environment.apiBaseUrl` in dev/prod; `/reader` on Strato — both plain-const imports, so the Angular-free promise holds):

```ts
// src/app/core/boot-error-surface.ts
import { environment } from '../../environments/environment';
import { reportBootError } from './client-error-beacon';

export function revealBootErrorSurface(error: unknown): void {
  console.error(error);
  document.getElementById('boot-error')?.removeAttribute('hidden');
  reportBootError(environment.apiBaseUrl, error);
}
```

(Update the existing module docblock's "no Angular imports" note is still true — `environment` and the beacon are plain modules.)

Run: `docker compose exec -T frontend npx jest src/app/core/boot-error-surface.spec.ts`
Expected: PASS.

- [ ] **Step 6: Beacon from the `index.html` boot watchdog** — inside the `setTimeout` callback, after `surface.removeAttribute('hidden');`, add a vanilla `sendBeacon` (ruling #4 — no Angular/HttpClient here; `buildVersion` is unreachable so it sends `null`; the path is the literal the spec gives). The item shape matches the wire contract exactly:

```js
          var timer = setTimeout(function () {
            console.error('Boot watchdog: no content within ' + BOOT_DEADLINE_MS + ' ms.');
            surface.removeAttribute('hidden');
            try {
              navigator.sendBeacon(
                '/api/client-errors',
                new Blob(
                  [
                    JSON.stringify({
                      errors: [
                        {
                          message: 'Boot watchdog: no content within ' + BOOT_DEADLINE_MS + ' ms.',
                          stack: null,
                          kind: 'BootWatchdog',
                          url: location.href,
                          route: null,
                          buildVersion: null,
                          userAgent: navigator.userAgent,
                          at: new Date().toISOString(),
                        },
                      ],
                    }),
                  ],
                  { type: 'application/json' },
                ),
              );
            } catch (e) {}
          }, BOOT_DEADLINE_MS);
```

- [ ] **Step 7: Run the full frontend gate + a manual boot check**

Run: `docker compose exec -T frontend npm run check`
Then confirm the app still boots and a synthetic error reports: `docker compose up -d`, open `https://localhost:8443`, throw in the console (`throw new Error('manual #984 check')`), and confirm one `POST /api/client-errors` → `202` in the Network tab, and a `source=frontend` line in local Loki/Grafana.
Expected: gate green; one 202 per distinct error (dedupe collapses repeats).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/core/navigation-failure.ts frontend/src/app/core/navigation-failure.spec.ts frontend/src/app/core/boot-error-surface.ts frontend/src/app/core/boot-error-surface.spec.ts frontend/src/index.html
git commit -m "feat(#984): report boot and navigation failures"
```

---

## Self-review

**1. Spec coverage** — every spec section maps to a task:

| Spec requirement | Task |
|---|---|
| Endpoint `POST /api/client-errors`, thin controller | 5 |
| `PUBLIC_ACCESS` access-control above `^/api/` catch-all | 5 (Step 5) |
| Nullable `#[CurrentUser] ?User`, tag user only when present (ruling #3) | 5 |
| `ClientErrorReportRequest` DTO, `#[MapRequestPayload]`, count + length caps | 3 |
| `client_errors` rate limiter, `enforceForClient`, 429 + Retry-After | 4, 5 |
| `ClientErrorScrubber` (url strip, tokens, emails, JWT, hex) | 2 |
| `client_errors` Monolog channel; `LokiPushHandler` source-by-channel (ruling #1) | 1 |
| Buffered + 202, out-of-band flush (ruling #2) | 5 (202) + inherited #983 flush |
| Global `ErrorHandler` (log then report) | 7 |
| `unhandledrejection` listener at bootstrap | 7 |
| Interceptor reports non-401 (5xx/0), keeps 401, does not report 401 | 8 |
| `ClientErrorReporter`: route + buildVersion (3 fields, ruling #5) + userAgent, bearer for user tag, dedupe/throttle, fetch keepalive → sendBeacon | 6 |
| `NavigationFailureReporter` folds in | 9 |
| `revealBootErrorSurface` beacons via plain function, stays Angular-free | 9 |
| `index.html` watchdog vanilla `sendBeacon` (ruling #4) | 9 |
| Backend functional tests (anon 202, authed tag, 422, 429) + scrubber + channel-map unit + dispatcher-backed | 5, 2, 1 |
| Frontend Jest (reporter, ErrorHandler, interceptor 401-not-reported) | 6, 7, 8 |
| Out of scope (Faro, web-vitals, console mirroring) | not built — correct |

**2. Placeholder scan** — no "TBD/similar to/add error handling" steps; every code step carries real, complete code and a real command with an expected result.

**3. Type consistency (DTO ↔ the three POST sites):** all three sites send `{ errors: [ { message, stack, kind, url, route, buildVersion, userAgent, at } ] }` matching `ClientErrorItem` field-for-field:
- Reporter `toItem()` (Task 6) — all 8 fields, `buildVersion` via `buildVersionTag()`.
- `reportBootError()` (Task 6) — all 8 fields, `buildVersion` via `buildVersionTag()`, `route: null`.
- `index.html` watchdog (Task 9) — all 8 fields, `buildVersion: null` (no import possible), `route: null`.
`userId` is deliberately absent from the wire and added server-side from `#[CurrentUser]` (Task 5) — verified against the ground-truth touchpoint. `buildVersionTag()` is defined once (Task 6) and reused, so the reporter and boot beacon never drift. `ClientErrorScrubber::scrub` returns `ClientErrorItem` and `ClientErrorRecorder::record(list<ClientErrorItem>, ?User)` consumes exactly what the DTO produces.

**Known spec-faithful caveat:** the `index.html` watchdog beacons the literal path `/api/client-errors` (the spec's explicit text, ruling #4). Where the SPA is served under `/reader` on Strato (`environment.strato.apiBaseUrl === '/reader'`), that same-origin path may not carry the `/reader` prefix; the reporter and boot-error beacon use `apiBaseUrl` and are correct there. This is a last-resort total-boot-stall path, so the degradation is bounded — flagged rather than silently changed, since the spec fixed the literal.
