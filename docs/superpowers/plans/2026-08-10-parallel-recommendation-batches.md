# Parallel Recommendation Batch Calls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a recommendation run's batch phase send several provider calls concurrently, bounded by a per-connection cap, without disturbing the resumable state machine, the dedup barrier, the #329 degrade logic, or the #336 ETA surface.

**Architecture:** Fan-out lives **inside one tick**. A tick runs one *wave* of `≤cap` concurrent batch calls through a new `completeMany()` on the chat client (Symfony HttpClient multiplexing — cooperative, single PHP process, no thread race), resolves every batch in the wave (bank / in-tick-retry / degrade / atomic-wave-on-transport-failure), then advances the single-integer `batchesDone` cursor by the wave size. Worker uses the full cap; the poll path clamps to `min(cap, 2)`. The dedup call stays one sequential barrier.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine, Symfony HttpClient (`stream(iterable $responses)`), Angular 20 signals, Transloco.

## Global Constraints

- **Spec:** [docs/superpowers/specs/2026-08-10-parallel-recommendation-batches-design.md](../specs/2026-08-10-parallel-recommendation-batches-design.md). Issue [#344](https://github.com/larspohlmann/simple-feed-reader/issues/344).
- **Cap:** per-connection `batchConcurrency`, integer, **default 1**, hard range **1..4**. The literal `4` appears once as `AiProviderSettings::MAX_BATCH_CONCURRENCY` and is referenced everywhere else (DTO range, defensive clamp).
- **Poll clamp:** `RecommendationRunAdvancer::POLL_MAX_CONCURRENCY = 2`. Effective cap = `TickDriver::Poll === $driver ? min($configured, 2) : $configured`, then defensively `min(…, MAX_BATCH_CONCURRENCY)`.
- **`cap == 1` is a pure no-op path** — the wave is one batch and the tick behaves byte-for-byte as today.
- **No new persisted run state.** The batch phase stops using the run's cross-tick `attempts`/`lastInvalidReply`; in-tick retries hold their corrective state in local variables. Those entity fields remain in use by the **dedup** phase only.
- **Clean Code house style** (CLAUDE.md): `final readonly` where possible, guard clauses, names reveal intent, no boolean-flag parameters, thin controllers (`ThinControllerRule`), errors are typed exceptions. Every touched `src` file must be **PHPMD-clean** and pass PhpStorm inspections (block on ERROR/WARNING).
- **`declare(strict_types=1)` in every file. PHPStan level max over `src` and `tests`.**
- **Migrations verified from empty on both SQLite and MySQL**, then `doctrine:schema:validate` clean (the standing rule — the test suite builds schema from metadata and never runs the migration).
- **Datetimes are naive UTC** (not relevant here, but do not introduce a clock).
- **Frontend:** standalone + signals, no NgModules; component styles in a sibling `.scss`; no hex/`px` literals outside `theme/`; run `npm run check` from `frontend/`.

---

## File Structure

| Path | Responsibility | Task |
|---|---|---|
| `backend/src/Entity/AiProviderSettings.php` | `batchConcurrency` column + accessors + `MAX_BATCH_CONCURRENCY` | 1 |
| `backend/migrations/VersionYYYYMMDDHHMMSS.php` | add `batch_concurrency` (both dialects) | 1 |
| `backend/src/Dto/Ai/SetBatchConcurrencyRequest.php` | validated write payload (Range 1..4) | 2 |
| `backend/src/Service/Ai/AiProviderConfigurator.php` | `setBatchConcurrency()` | 2 |
| `backend/src/Controller/Api/AiSettingsController.php` | `PUT /configs/{id}/batch-concurrency` | 2 |
| `backend/src/Http/AiSettingsJson.php` | `batchConcurrency` wire key | 2 |
| `backend/src/Service/Recommendation/CompletionOutcome.php` | answer-or-failure result of one concurrent call | 3 |
| `backend/src/Service/Recommendation/ConcurrentCompletion.php` | request + its observer, one wave member | 3 |
| `backend/src/Service/Recommendation/ChatCompletionClient.php` | `completeMany()` on the interface | 3 |
| `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php` | `completeMany()` implementation; shared per-response read helper | 3 |
| `backend/src/Service/Recommendation/TickDriver.php` | `Worker` / `Poll` enum | 4 |
| `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` | wave driver in `providerTick`; `advance(User, TickDriver)` | 4 |
| `backend/src/Service/Recommendation/RecommendationPollDriver.php` | pass `TickDriver::Poll` | 4 |
| `backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php` | pass `TickDriver::Worker` | 4 |
| `frontend/src/app/settings/ai-settings.service.ts` | `batchConcurrency` on `AiConfig` + `setBatchConcurrency()` | 5 |
| `frontend/src/app/settings/ai-section.component.{ts,html,scss}` | per-config concurrency input | 5 |
| `frontend/public/i18n/{en,de}.json` | labels | 5 |

---

### Task 1: Concurrency column on `AiProviderSettings` + migration

**Files:**
- Modify: `backend/src/Entity/AiProviderSettings.php`
- Create: `backend/migrations/Version<stamp>.php`
- Test: `backend/tests/Entity/AiProviderSettingsTest.php` (create if absent; otherwise add cases)

**Interfaces:**
- Produces: `AiProviderSettings::MAX_BATCH_CONCURRENCY` (int `4`), `batchConcurrency(): int`, `setBatchConcurrency(int): void`. New rows default to `1`. `replaceConnection()` does **not** touch it (a new endpoint keeps the chosen concurrency, like `suppressReasoning`).

- [ ] **Step 1: Write the failing test**

Add to `AiProviderSettingsTest` (mirror the existing construction helper; a fresh settings row is built the way other entity tests build one):

```php
public function testBatchConcurrencyDefaultsToOne(): void
{
    $settings = $this->newSettings();
    self::assertSame(1, $settings->batchConcurrency());
}

public function testSetBatchConcurrencyIsReadBack(): void
{
    $settings = $this->newSettings();
    $settings->setBatchConcurrency(3);
    self::assertSame(3, $settings->batchConcurrency());
}

public function testMaxBatchConcurrencyIsFour(): void
{
    self::assertSame(4, AiProviderSettings::MAX_BATCH_CONCURRENCY);
}
```

If `AiProviderSettingsTest` does not exist, create it with a `newSettings()` helper that constructs the entity exactly as `AiProviderConfiguratorTest` or `AiSettingsControllerTest` fixtures do (a real `User`, a `SealedApiKey`, a hint, and `new \DateTimeImmutable()`). Reuse whatever the nearest existing entity/fixture test already does — do not invent a new construction path.

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit --filter AiProviderSettingsTest`
Expected: FAIL — `batchConcurrency()` / `MAX_BATCH_CONCURRENCY` undefined.

- [ ] **Step 3: Add the column and accessors**

In `AiProviderSettings`, beside the `suppressReasoning` column (mirror it exactly):

```php
    public const int MAX_BATCH_CONCURRENCY = 4;

    /**
     * How many batch calls a run may send at once for this connection (#344).
     * Default 1: sequential, identical to the pre-#344 behaviour, so
     * parallelism is strictly opt-in per connection. A single-GPU local model
     * gains nothing from a higher value and the low ceiling keeps a wave from
     * a memory stampede; a hosted provider gets a real wall-clock cut. The
     * range is enforced at the API (SetBatchConcurrencyRequest); this column
     * is a plain int so a value written straight to the row is still read back.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $batchConcurrency = 1;
```

Accessors beside `suppressesReasoning()` / `setSuppressReasoning()`:

```php
    public function batchConcurrency(): int
    {
        return $this->batchConcurrency;
    }

    public function setBatchConcurrency(int $batchConcurrency): void
    {
        $this->batchConcurrency = $batchConcurrency;
    }
```

Leave `replaceConnection()` untouched.

- [ ] **Step 4: Run the entity test, verify it passes**

Run: `cd backend && php bin/phpunit --filter AiProviderSettingsTest`
Expected: PASS.

- [ ] **Step 5: Generate and hand-verify the migration**

Run: `cd backend && bin/console doctrine:migrations:diff`

Open the generated migration. It must add exactly the one column on the `up()` and drop it on `down()`. Rewrite the generated bodies so each dialect is explicit and dialect-guarded in the house style of the #323 migration (`Version20260809190406.php` is the reference). The MySQL and SQLite `up()` SQL:

```sql
-- MySQL
ALTER TABLE user_ai_settings ADD batch_concurrency INT DEFAULT 1 NOT NULL
-- SQLite
ALTER TABLE user_ai_settings ADD COLUMN batch_concurrency INTEGER DEFAULT 1 NOT NULL
```

Match the platform-guard structure (`$this->connection->getDatabasePlatform()` / `abortIf`) that the existing migrations in `backend/migrations/` use. Delete any unrelated statements the differ emits.

- [ ] **Step 6: Verify the migration from empty on both dialects**

Follow the standing migration rule — do **not** touch the real dev DB (memory: *never clear the dev database*). Run against a **named scratch database** on each dialect:

```bash
# SQLite scratch
cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch_344.db" \
  bin/console doctrine:migrations:migrate --no-interaction && \
  DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch_344.db" \
  bin/console doctrine:schema:validate
```

For MySQL, use the Docker stack against a scratch schema name (mirror how CI's migrate-from-empty leg is configured). Expected on both: migrate succeeds from empty; `schema:validate` reports the mapping and database are in sync. Remove the scratch DB afterwards.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Entity/AiProviderSettings.php backend/migrations backend/tests/Entity/AiProviderSettingsTest.php
git commit -m "feat(#344): batch_concurrency column on AiProviderSettings, default 1"
```

---

### Task 2: API — DTO, configurator, route, JSON key

**Files:**
- Create: `backend/src/Dto/Ai/SetBatchConcurrencyRequest.php`
- Modify: `backend/src/Service/Ai/AiProviderConfigurator.php`
- Modify: `backend/src/Controller/Api/AiSettingsController.php`
- Modify: `backend/src/Http/AiSettingsJson.php`
- Test: `backend/tests/Controller/Api/AiSettingsControllerTest.php`, `backend/tests/Http/AiSettingsJsonTest.php` (add cases to whichever exist)

**Interfaces:**
- Consumes: `AiProviderSettings::MAX_BATCH_CONCURRENCY` (Task 1).
- Produces: `PUT /api/me/ai/configs/{id}/batch-concurrency` (route name `api_me_ai_set_batch_concurrency`) returning the configuration JSON; wire key `batchConcurrency`.

- [ ] **Step 1: Write the failing controller test**

Mirror the `setReasoning` controller test (find it in `AiSettingsControllerTest`). Add:

```php
public function testSetBatchConcurrencyPersistsAndRoundTrips(): void
{
    $id = $this->seedConfiguration();               // reuse the test's existing seeding helper

    $this->putJson("/api/me/ai/configs/{$id}/batch-concurrency", ['batchConcurrency' => 3]);

    self::assertResponseIsSuccessful();
    self::assertSame(3, $this->lastJson()['batchConcurrency']);
}

public function testSetBatchConcurrencyRejectsOutOfRange(): void
{
    $id = $this->seedConfiguration();

    $this->putJson("/api/me/ai/configs/{$id}/batch-concurrency", ['batchConcurrency' => 5]);

    self::assertResponseStatusCodeSame(422);
}
```

Use the same request/assert helpers the sibling `setReasoning` test uses (method names above are placeholders for whatever that file already defines — match them exactly).

- [ ] **Step 2: Run it, verify it fails**

Run: `cd backend && php bin/phpunit --filter AiSettingsControllerTest`
Expected: FAIL — route 404 / method missing.

- [ ] **Step 3: The DTO**

`backend/src/Dto/Ai/SetBatchConcurrencyRequest.php` (mirror `SetReasoningRequest`):

```php
<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use App\Entity\AiProviderSettings;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetBatchConcurrencyRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: AiProviderSettings::MAX_BATCH_CONCURRENCY)]
        public int $batchConcurrency,
    ) {
    }
}
```

- [ ] **Step 4: The configurator method**

In `AiProviderConfigurator`, mirror `setSuppressReasoning`:

```php
    public function setBatchConcurrency(AiProviderSettings $settings, int $batchConcurrency): void
    {
        $settings->setBatchConcurrency($batchConcurrency);
        $this->entityManager->flush();
    }
```

- [ ] **Step 5: The route**

In `AiSettingsController`, add the action mirroring `setReasoning` (import `SetBatchConcurrencyRequest`):

```php
    #[Route(
        '/configs/{id}/batch-concurrency',
        name: 'api_me_ai_set_batch_concurrency',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function setBatchConcurrency(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SetBatchConcurrencyRequest $request,
    ): JsonResponse {
        try {
            $configuration = $this->configuration->require($user, $id);
        } catch (ConfigurationNotFoundException $e) {
            throw new AiConfigurationNotFoundApiException($e);
        }

        $this->configurator->setBatchConcurrency($configuration, $request->batchConcurrency);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }
```

This stays within `ThinControllerRule`: it reads, delegates, returns — the same shape as `setReasoning`, which the rule already accepts.

- [ ] **Step 6: The JSON key**

In `AiSettingsJson::configuration()`, add beside `suppressReasoning`:

```php
            'batchConcurrency' => $settings->batchConcurrency(),
```

Add a `AiSettingsJsonTest` case asserting the key is present with the entity's value (mirror the `suppressReasoning` assertion).

- [ ] **Step 7: Run the tests, verify they pass**

Run: `cd backend && php bin/phpunit --filter "AiSettingsControllerTest|AiSettingsJsonTest"`
Expected: PASS.

- [ ] **Step 8: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md`. Fix any finding in a file you touched.

```bash
git add backend/src/Dto/Ai/SetBatchConcurrencyRequest.php backend/src/Service/Ai/AiProviderConfigurator.php backend/src/Controller/Api/AiSettingsController.php backend/src/Http/AiSettingsJson.php backend/tests
git commit -m "feat(#344): PUT batch-concurrency endpoint and wire key"
```

---

### Task 3: `completeMany()` — the concurrent read

**Files:**
- Create: `backend/src/Service/Recommendation/CompletionOutcome.php`
- Create: `backend/src/Service/Recommendation/ConcurrentCompletion.php`
- Modify: `backend/src/Service/Recommendation/ChatCompletionClient.php`
- Modify: `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php`
- Test: `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php` (add cases; the existing single-call tests already stub `MockHttpClient`)

**Interfaces:**
- Consumes: `CompletionRequest`, `CompletionStreamObserver`, `ProviderCredentials`.
- Produces:
  - `ConcurrentCompletion` — `final readonly` pairing `public CompletionRequest $request` with `public CompletionStreamObserver $observer`.
  - `CompletionOutcome` — `final readonly`, one of two states: a decoded answer `string`, or a transport failure carrying the throwable. Shape:
    ```php
    public static function answer(string $content): self;
    public static function failure(\Throwable $cause): self;
    public function isFailure(): bool;
    public function content(): string;   // throws \LogicException if failure
    public function cause(): \Throwable;  // throws \LogicException if answer
    ```
  - `ChatCompletionClient::completeMany(ProviderCredentials $credentials, array $calls): array` — `@param non-empty-list<ConcurrentCompletion> $calls`, `@return list<CompletionOutcome>` aligned to `$calls` by index. **Never throws** for a per-call transport failure — it records that call's failure as its outcome so one bad call cannot abort the read for its siblings. (A programming error may still throw.)

**Why a result object rather than exceptions:** the atomic-wave rule (Task 4) needs *all* outcomes of a wave together — which succeeded, which failed — to decide whether to bank nothing and re-run. An exception from `completeMany` would lose the siblings' answers. So each call's transport failure becomes a `CompletionOutcome::failure`, and Task 4 inspects the list.

- [ ] **Step 1: Write the failing tests**

In `OpenAiCompatibleChatClientTest`, using `Symfony\Component\HttpClient\Response\MockResponse` and `MockHttpClient` (the file already does this for `complete()`):

```php
public function testCompleteManyReturnsAnswersAlignedByIndex(): void
{
    // Two mock responses, each a minimal SSE stream carrying a distinct JSON answer.
    $client = $this->clientReturning([
        $this->sseStream('{"picks":[]}'),
        $this->sseStream('{"picks":[{"id":1,"score":9,"reason":"x"}]}'),
    ]);

    $outcomes = $client->completeMany($this->credentials(), [
        new ConcurrentCompletion($this->batchRequest(), new NullCompletionStreamObserver()),
        new ConcurrentCompletion($this->batchRequest(), new NullCompletionStreamObserver()),
    ]);

    self::assertCount(2, $outcomes);
    self::assertFalse($outcomes[0]->isFailure());
    self::assertFalse($outcomes[1]->isFailure());
    self::assertStringContainsString('"picks"', $outcomes[0]->content());
}

public function testCompleteManyCarriesOneCallsTransportFailureWithoutAbortingSiblings(): void
{
    $client = $this->clientReturning([
        $this->sseStream('{"picks":[]}'),
        MockResponse::fromRequest(...),        // a 500, or a body that raises a TransportException
    ]);

    $outcomes = $client->completeMany($this->credentials(), [
        new ConcurrentCompletion($this->batchRequest(), new NullCompletionStreamObserver()),
        new ConcurrentCompletion($this->batchRequest(), new NullCompletionStreamObserver()),
    ]);

    self::assertFalse($outcomes[0]->isFailure());          // sibling still decoded
    self::assertTrue($outcomes[1]->isFailure());
    self::assertInstanceOf(ProviderUnreachableException::class, $outcomes[1]->cause());
}

public function testCompleteManyMapsAuthRejectionToCredentialsRejected(): void
{
    $client = $this->clientReturning([
        new MockResponse('', ['http_code' => 401]),
    ]);

    $outcomes = $client->completeMany($this->credentials(), [
        new ConcurrentCompletion($this->batchRequest(), new NullCompletionStreamObserver()),
    ]);

    self::assertTrue($outcomes[0]->isFailure());
    self::assertInstanceOf(CredentialsRejectedException::class, $outcomes[0]->cause());
}
```

Reuse or add small helpers (`sseStream()`, `batchRequest()`, `credentials()`, `clientReturning()`) consistent with the existing `complete()` tests in this file. The exact `MockResponse` shapes for a mid-stream failure follow whatever the existing transport-failure test for `complete()` already uses — copy that construction.

- [ ] **Step 2: Run them, verify they fail**

Run: `cd backend && php bin/phpunit --filter OpenAiCompatibleChatClientTest`
Expected: FAIL — `completeMany` undefined.

- [ ] **Step 3: Extract the shared per-response read**

Refactor the existing `complete()` so the per-response read/decision logic — status check, the `stream()` chunk loop, the timeout/size guards, the `assistantContent() ?? reasoningContent()` fallback — lives in one private helper that operates on a single `ResponseInterface` + `CompletionStreamReader` + `CompletionStreamObserver`. `complete()` becomes: build the request, call the helper, return the string (throwing on failure, exactly as today). Keep every existing `complete()` test green — this step must not change single-call behaviour.

The current single-response chunk loop already reads one response via `$this->httpClient->stream($response, …)`. The helper keeps that shape; the multiplexed version in Step 4 differs only in that it streams over *several* responses at once and routes each chunk to its response's reader.

- [ ] **Step 4: Implement `completeMany()`**

Fire every request up front (Symfony starts the transfer on `request()`), then read them in **one** combined loop:

```php
public function completeMany(ProviderCredentials $credentials, array $calls): array
{
    // Each response keeps its own reader (parsing state) and its own observer.
    // SplObjectStorage maps the response object the stream() loop yields back
    // to those, and to the $calls index so the outcomes stay aligned.
    $context = new \SplObjectStorage();
    $responses = [];
    foreach ($calls as $index => $call) {
        $response = $this->request($credentials, $call->request);
        $responses[] = $response;
        $context[$response] = ['index' => $index, 'reader' => new CompletionStreamReader($this->decoder), 'observer' => $call->observer];
    }

    $outcomes = array_fill(0, \count($calls), null);

    foreach ($this->httpClient->stream($responses, self::INACTIVITY_TIMEOUT_SECONDS) as $response => $chunk) {
        $slot = $context[$response];
        if (null !== $outcomes[$slot['index']]) {
            continue; // already settled (failed or finished); ignore trailing chunks
        }
        try {
            $done = $this->consumeChunk($response, $chunk, $slot['reader'], $slot['observer']);
            if ($done) {
                $outcomes[$slot['index']] = CompletionOutcome::answer($this->contentOf($slot['reader']));
            }
        } catch (CredentialsRejectedException | ProviderUnreachableException $failure) {
            $response->cancel();
            $slot['observer']->…; // no observer settle needed here; RecordedCall settling is Task 4's job
            $outcomes[$slot['index']] = CompletionOutcome::failure($failure);
        }
    }

    // Any response that never yielded an isLast (should not happen) settles as a
    // failure so the caller always has one outcome per call.
    foreach ($outcomes as $index => $outcome) {
        $outcomes[$index] = $outcome ?? CompletionOutcome::failure(
            new ProviderUnreachableException('That provider answered without a completion.'),
        );
    }

    return array_values($outcomes);
}
```

`consumeChunk()` is the per-chunk core factored from `complete()`'s loop: on `isFirst()` check the status (401/403 → `CredentialsRejectedException`, ≥300 → `ProviderUnreachableException`); on `isTimeout()` throw the "sent nothing for …" `ProviderUnreachableException`; feed non-empty content to the reader, run `guardRetainedSize()`, report to the observer; return `true` when `$chunk->isLast()`. A `TransportExceptionInterface` from any chunk accessor is caught here (or at the call site) and rethrown as `ProviderUnreachableException('That address did not answer.', 0, $e)`, matching `complete()`.

`contentOf()` is the shared `assistantContent() ?? reasoningContent()` fallback, throwing `ProviderUnreachableException` when both are null.

**Constraints for the implementer:**
- Route chunks strictly by the `$response` the loop yields — never assume ordering.
- One failing response must not `break` the loop; only `continue` after settling its outcome and cancelling it.
- Keep the byte caps and timeouts identical to `complete()` (they are set in `request()` and `guardRetainedSize()`); do not weaken them for the concurrent path.
- No `Date`/`random`. No new dependency.

- [ ] **Step 5: Add the interface method**

```php
    /**
     * Several JSON-mode chat completions at once, read in one multiplexed
     * stream. Returns one CompletionOutcome per call, aligned by index. A
     * per-call transport failure is carried in that call's outcome rather than
     * thrown, so one failed call never discards a sibling's answer (#344).
     *
     * @param non-empty-list<ConcurrentCompletion> $calls
     *
     * @return list<CompletionOutcome>
     */
    public function completeMany(ProviderCredentials $credentials, array $calls): array;
```

- [ ] **Step 6: Run the tests, verify they pass**

Run: `cd backend && php bin/phpunit --filter OpenAiCompatibleChatClientTest`
Expected: PASS — including every pre-existing `complete()` case (the Step 3 refactor kept them green).

- [ ] **Step 7: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md`. The new method must be PHPMD-clean — if the multiplex loop trips cyclomatic/length limits, extract `consumeChunk()`/`contentOf()`/`settleOutstanding()` as real private methods (that is the intended shape anyway), do not tune thresholds.

```bash
git add backend/src/Service/Recommendation backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php
git commit -m "feat(#344): completeMany reads concurrent completions in one stream"
```

---

### Task 4: The wave driver in `providerTick`

**Files:**
- Create: `backend/src/Service/Recommendation/TickDriver.php`
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPollDriver.php`
- Modify: `backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: `completeMany()` + `CompletionOutcome`/`ConcurrentCompletion` (Task 3), `AiProviderSettings::batchConcurrency()` + `MAX_BATCH_CONCURRENCY` (Task 1).
- Produces:
  - `TickDriver` enum: `case Worker; case Poll;`.
  - `RecommendationRunAdvancer::advance(User $user, TickDriver $driver = TickDriver::Poll): RecommendationRunReport`. The default is the **conservative** regime (clamps to 2), so any caller that forgets is safe; the two production callers pass their regime explicitly.
  - `RecommendationRunAdvancer::POLL_MAX_CONCURRENCY = 2`.

**Behaviour contract (this is the spec of the rewrite):**
1. `providerTick` computes `waveSize = min(effectiveCap, batchesRemaining)` where `batchesRemaining = batchesTotalBatchesOnly - nextBatchIndex` (batch plan length, dedup excluded), and `effectiveCap = (TickDriver::Poll === $driver ? min($settings->batchConcurrency(), self::POLL_MAX_CONCURRENCY) : $settings->batchConcurrency())`, then `min(…, AiProviderSettings::MAX_BATCH_CONCURRENCY)`.
2. For each batch index in `[nextBatchIndex, nextBatchIndex + waveSize)`: resolve its ids → lines (the existing `linesForIds` / `linesInSnapshotOrder`), skipping fully-pruned batches (they resolve as an empty winner set, exactly `providerTick`'s current all-pruned short-circuit, but per batch).
3. Run the wave with **in-tick retries**:
   - Round: build a `ConcurrentCompletion` per still-unresolved batch — its `RecordedCall` (via `callRecorder->begin(...)`, phase `PHASE_BATCH`, batch number = index+1) and its `CompletionRequest` (via `requestFor`), with messages = `batchMessages(...)` **plus that batch's own corrective tail** built from *its own* last invalid reply held in a local map (not `run->getLastInvalidReply()`).
   - Call `completeMany`. For each outcome:
     - **transport failure** anywhere in the wave → **atomic wave**: cancel is already done inside `completeMany`; record **one** `run->recordTransportFailure()`; on ceiling reached `run->fail(...)` with the real detail (reuse `recordTransportFailure()` helper's message shape); flush; then re-throw the first failure's cause so the controller/worker mapping is unchanged and the **next tick re-runs the whole wave** (no cursor advance, no winners banked). Settle each in-flight `RecordedCall` via `abortAfterTransportFailure()` as `callProvider` does today.
     - **usable answer** → parse; hold winners; settle `RecordedCall` usable.
     - **unusable answer** → keep the batch for the next round, remember its content as that batch's corrective-tail source; settle `RecordedCall` unusable.
   - After the round, `cancellation->guard($run)` (round-boundary Stop).
   - Stop when no unusable batches remain or the round count reached `RecommendationRun::MAX_ATTEMPTS`. A batch still unusable at the last round is **degraded** (dropped — empty winner set), the #329 batch-phase ending.
4. After the wave, append each resolved batch's winners with `run->recordBatchWinners($winners)` (per batch, advancing `batchesDone` by the wave size in total). Then pick the ending **once**: `!needsDedup && allBatchCallsDone` → `finalize(ranked)`, `needsDedup && allBatchCallsDone` → checkpoint into the dedup phase, else checkpoint mid-batch-phase. (This hoists today's `recordBatchWinners` wrapper decision to after the wave.)
5. `cap == 1` must take a path indistinguishable from today: one batch, `complete()` (not `completeMany`) is acceptable but **not required** — a `completeMany` of one call that succeeds is equivalent. Prefer routing `cap == 1` through the same wave code with `waveSize == 1`; only special-case if a test proves a behavioural difference. Keep it simple.
6. The dedup phase (`dedupTick`) is unchanged and still uses `complete()` and the run's cross-tick `attempts`/`lastInvalidReply`.

- [ ] **Step 1: Write the failing tests**

`RecommendationRunAdvancerTest` drives the real advancer against fakes (it does not mock — see its header). Add a fake/mock `ChatCompletionClient` capability to return staged answers **per concurrent call** (extend the existing test double so `completeMany` returns a scripted list of `CompletionOutcome`s; if the suite uses a hand-written fake client, add `completeMany` to it). New cases:

```php
public function testWaveBanksEveryBatchInOneTick(): void
{
    // pool that packs into >=2 batches; concurrency 2; worker driver.
    // snapshot tick, then ONE batch tick.
    // Assert: after one batch tick, batchesDone advanced by 2 and both batches'
    // winners are present.
}

public function testUnusableBatchRetriesInTickThenDegradesWithoutDroppingSiblings(): void
{
    // wave of 2; batch A usable first try, batch B unusable for MAX_ATTEMPTS.
    // Assert one tick: A's winners banked, B degraded (dropped), batchesDone += 2,
    // run not failed. The client saw B re-sent MAX_ATTEMPTS times, A once.
}

public function testTransportFailureInWaveAdvancesNothingAndIncrementsCeilingOnce(): void
{
    // wave of 3; one call fails transport, two would succeed.
    // Assert: batchesDone unchanged, no winners banked, getTransportFailures() == 1
    // (not 2 or 3), exception surfaced. Next tick re-runs the same batch indices.
}

public function testConcurrencyOneTakesTheSequentialPath(): void
{
    // concurrency 1: a multi-batch run advances one batch per tick exactly as before.
}

public function testPollDriverClampsConcurrencyToTwo(): void
{
    // concurrency 4 configured, TickDriver::Poll: a wave sends at most 2 calls.
    // Assert the client saw a first wave of 2, not 4.
}
```

Match the file's existing fixture helpers (`advancer()`, `$this->user`, the batch-packing seed). The double must let a test assert **how many calls a wave sent** and **script per-call verdicts**.

- [ ] **Step 2: Run them, verify they fail**

Run: `cd backend && php bin/phpunit --filter RecommendationRunAdvancerTest`
Expected: FAIL.

- [ ] **Step 3: Add the `TickDriver` enum**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Which driver is ticking the run, so the advancer can size a wave to the
 * regime it runs in (#344): the worker owns its process and may send the full
 * per-connection concurrency; a poll tick is a web request, so it clamps to
 * POLL_MAX_CONCURRENCY to keep one request bounded.
 */
enum TickDriver
{
    case Worker;
    case Poll;
}
```

- [ ] **Step 4: Thread `TickDriver` through and rewrite `providerTick`**

- `advance(User $user, TickDriver $driver = TickDriver::Poll)`, passing `$driver` down through `tick()` → `tickActiveRun()` → `providerTick()`. `snapshotTick`/`dedupTick` ignore it.
- `RecommendationPollDriver::poll()` calls `advance($user, TickDriver::Poll)`.
- `AdvanceRecommendationRunsHandler::advanceOne()` calls `advance($run->getUser(), TickDriver::Worker)`.
- Rewrite `providerTick` to the behaviour contract above. Extract helpers so the method stays PHPMD-clean and reads as *what*: e.g. `runWave()`, `resolveBatch()`, `handleTransportFailure()`, `pickEndingAfterWave()`. The per-batch corrective-tail map replaces `withCorrectiveTail`'s use of `run->getLastInvalidReply()` **for the batch phase only**.

- [ ] **Step 5: Run the advancer tests + the full recommendation suite**

Run: `cd backend && php bin/phpunit --filter Recommendation`
Expected: PASS — new wave cases and every pre-existing advancer case (cancellation, all-pruned, degrade, dedup, transport ceiling). If a pre-existing test asserted "one call per tick" semantics that the wave changes, update it **only** where the change is the intended #344 behaviour, and note it in the ledger.

- [ ] **Step 6: Update the callers' tests**

`RecommendationRunControllerTest`, `AdvanceRecommendationRunsHandlerTest`, `RecommendationPollDriver` tests: pass/verify the `TickDriver` argument. The handler test asserts the worker regime; a poll/controller test asserts the poll regime (clamp).

Run: `cd backend && php bin/phpunit --filter "RecommendationRunController|AdvanceRecommendationRuns|RecommendationPollDriver"`
Expected: PASS.

- [ ] **Step 7: Lint + commit**

Run: `cd backend && composer cs:fix && composer stan && composer md`. `RecommendationRunAdvancer` is already `@SuppressWarnings("PHPMD.ExcessiveParameterList")`; do not add new suppressions — extract methods instead.

```bash
git add backend/src/Service/Recommendation backend/src/Service/Worker backend/tests
git commit -m "feat(#344): providerTick fans out a wave of concurrent batch calls"
```

---

### Task 5: Frontend — per-connection concurrency control

**Files:**
- Modify: `frontend/src/app/settings/ai-settings.service.ts`
- Modify: `frontend/src/app/settings/ai-section.component.ts`
- Modify: `frontend/src/app/settings/ai-section.component.html`
- Modify: `frontend/src/app/settings/ai-section.component.scss` (only if a new class needs styling)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/ai-settings.service.spec.ts` (and component spec if one exists)

**Interfaces:**
- Consumes: `PUT /api/me/ai/configs/{id}/batch-concurrency` + `batchConcurrency` wire key (Task 2).
- Produces: `AiConfig.batchConcurrency: number`; `AiSettingsService.setBatchConcurrency(id, value)`.

- [ ] **Step 1: Write the failing service test**

Mirror the `setReasoning` service spec case:

```ts
it('posts batch concurrency and upserts the returned config', () => {
  service.setBatchConcurrency(7, 3);
  const req = httpMock.expectOne(`${base}/api/me/ai/configs/7/batch-concurrency`);
  expect(req.request.method).toBe('PUT');
  expect(req.request.body).toEqual({ batchConcurrency: 3 });
  req.flush({ ...configFixture, id: 7, batchConcurrency: 3 });
  expect(service.configs().find((c) => c.id === 7)?.batchConcurrency).toBe(3);
});
```

Add `batchConcurrency` to whatever `configFixture` the spec uses.

- [ ] **Step 2: Run it, verify it fails**

Run: `cd frontend && npx jest ai-settings.service`
Expected: FAIL.

- [ ] **Step 3: Extend the type and service**

In `ai-settings.service.ts`, add to `AiConfig`:

```ts
  readonly batchConcurrency: number;
```

and the method beside `setReasoning`:

```ts
  setBatchConcurrency(id: number, batchConcurrency: number): void {
    this.run(
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/batch-concurrency`, {
        batchConcurrency,
      }),
      (config) => this.upsert(config),
    );
  }
```

- [ ] **Step 4: The component handler + template control**

In `ai-section.component.ts`, beside `toggleReasoning`:

```ts
  setBatchConcurrency(config: AiConfig, event: Event): void {
    this.ai.setBatchConcurrency(config.id, Number((event.target as HTMLInputElement).value));
  }
```

In `ai-section.component.html`, in the config row beside the reasoning toggle (after line 57's closing `</label>`), add a small number input, `min="1" max="4"`, bound to `config.batchConcurrency`, disabled while `ai.busy()`, calling `setBatchConcurrency(config, $event)` on `change`, with a label + hint from i18n. Match the existing markup idiom (a `<label>` wrapping the control and a `.hint`). No hex/`px` in the `.scss`; reuse existing spacing tokens if any class is needed.

- [ ] **Step 5: i18n**

Add under `settings.ai.configs` in `en.json`:

```json
"batchConcurrency": "Parallel requests",
"batchConcurrencyHint": "How many batch prompts to send at once (1–4). Raise it for a hosted provider to speed up a run; leave it at 1 for a local model."
```

and the German equivalents in `de.json` (keep the ASD-STE100-neutral, concrete wording used by the sibling `reasoningHint`).

- [ ] **Step 6: Run the frontend gate**

Run: `cd frontend && npm run check`
Expected: PASS (ESLint + Prettier + Stylelint + Jest).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#344): per-connection parallel-requests control"
```

---

### Task 6: Whole-branch verification, mutation gate, live-run proof

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite, both DB legs**

Run: `cd backend && php bin/phpunit` (SQLite), then `docker compose exec php vendor/bin/phpunit` (MySQL). Both green. Watch for the known MySQL rate-limiter flake (memory) — it is not this branch's regression; confirm by re-running the failing test in isolation.

- [ ] **Step 2: Migration leg from empty, both dialects**

Re-confirm Task 1 Step 6 on a clean scratch DB per dialect and `doctrine:schema:validate` clean. Never touch the real dev DB.

- [ ] **Step 3: Static gates**

Run: `cd backend && composer check && composer md`, then PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file — block on ERROR/WARNING. `cd frontend && npm run check`.

- [ ] **Step 4: Mutation gate on the diff**

Run: `cd backend && composer infection:diff`. Must meet `minMsi` in `infection.json5`. Escaped mutants on the wave logic or `completeMany` mean a missing test — add it, do not lower the gate. Ensure `TEST_TOKEN` isolation holds (memory) — a broken-isolation run inflates the score.

- [ ] **Step 5: Live-run proof (the deliverable — standing rule)**

Bring up the Docker stack. On a real connection, set `batchConcurrency` > 1 (via the new UI). Start a For-You run and watch it to completion. Confirm through the debug panel:
- the batch phase sends **concurrent** calls (a wave shows several rows streaming at once),
- the run **completes** with **0 transport failures**,
- the #336 ETA/progress surface behaves (ETA counts down sanely; note any bar jank for a possible follow-up, do not fix here).

If a real provider rate-limits at the chosen concurrency, lower it and note it — that is the designed signal, not a bug. Record the run id / outcome in the ledger.

- [ ] **Step 6: Scan `backend/var/log/dev.log`**

Check for deprecations or swallowed errors from the run (memory: standing habit after backend work).

---

## Self-Review

- **Spec coverage:** cap setting (T1–2, T5), fan-out (T3), wave driver + atomic wave + in-tick retry + Stop + driver clamp (T4), ETA/debug untouched (asserted by omission + T6 Step 5), dedup barrier unchanged (T4 contract §6), verification bar (T6 Step 5). Covered.
- **Placeholders:** the two hard tasks (T3, T4) give a concrete skeleton + a full test contract rather than line-final code, because the multiplex read and the wave rewrite are integration that must be driven by the listed tests; the mechanical tasks give final code. Test names in T2/T5 marked "match the existing helper" are deliberate — the sibling `setReasoning`/`suppressReasoning` tests are the template.
- **Type consistency:** `batchConcurrency()` (entity) / `batchConcurrency` (wire + TS field) / `setBatchConcurrency()` (entity, configurator, service) / `SetBatchConcurrencyRequest.batchConcurrency` are used consistently. `TickDriver::Worker|Poll`, `POLL_MAX_CONCURRENCY = 2`, `MAX_BATCH_CONCURRENCY = 4` consistent across T1/T2/T4. `CompletionOutcome`/`ConcurrentCompletion`/`completeMany` consistent across T3/T4.
