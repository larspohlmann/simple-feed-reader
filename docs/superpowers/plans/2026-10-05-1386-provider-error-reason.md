# A provider's error status carries the provider's reason — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this
> plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an OpenAI-compatible provider answers a completion request with an error status, the run log, the
run's error and the UI say why, in the provider's own words.

**Spec:** GitHub issue #1386. First of three: #1387 and #1388 build on it, in that order
(`2026-10-05-1387-…`, `2026-10-05-1388-…`).

**Architecture:** Every message a failed call shows already flows from one place, the exception's message
(`RecordedCall::abortAfterTransportFailure()`, `RecommendationTransportFailureRecorder::PROVIDER_FAILED`,
`ProfileRunFailure`). So the fix is in `OpenAiCompatibleChatClient` alone: on a status that today ends in
`ProviderUnreachableException::answeredWithStatus()`, it no longer throws at the first chunk but collects the
error body, bounded, to the last chunk and puts the provider's reason into that same exception. The JEV client
already extracts a reason (`Jev/Support/RefusalMessage`); the extraction moves to one shared helper both use.

**Tech stack:** PHP 8.4, Symfony HttpClient (`stream()`, `MockResponse`), PHPUnit 12. Commands run from `backend/`.

## Global constraints

- `CLAUDE.md` Clean Code rules; `composer check`, `composer md`, both suite legs, `composer infection:diff`.
- Branch `fix/1386-provider-error-reason` (exists, off `develop`, holds the three plans). Commits `type(#1386): …`.
- No classification change here: which statuses retry, strike or fail stays exactly as it is. That is #1387.
- The exception class stays `ProviderUnreachableException`; only its message gains the reason.

## Decisions (mine, not Lars's — say so in the PR body)

- **Which statuses:** every status that reaches `answeredWithStatus()` today (≥ 300, not 401/403, not
  429/502/503/504), not only 4xx. A 500 with a JSON error body is as undiagnosable as a 400.
- **Wording:** `That provider answered with status 400: <reason>`. Without a readable reason the sentence is
  the present one, unchanged, so every existing assertion on it stays valid.
- **Bound:** at most 16 KiB of error body is kept. A larger body is not parsed; the bare sentence is used.
- **Reason length:** 500 characters, as `RefusalMessage` clips today.
- **Key redaction:** the connection's API key, when it appears in the reason, is replaced by `[redacted]`.
  Applied in both clients (the JEV client has the same exposure).
- **Never the raw body:** only `detail` or `error.message`, as `RefusalMessage` already rules (OpenRouter's body
  carries the account's `user_id`).

## Files

| File | Change |
|---|---|
| `src/Service/Recommendation/Support/ProviderErrorReason.php` | Create: extracts and clips the reason from an error body |
| `src/Service/Recommendation/Jev/Support/RefusalMessage.php` | Delegates its extraction to `ProviderErrorReason` |
| `src/Service/Ai/Model/ProviderCredentialsModel.php` | `withoutApiKey(string $text): string` |
| `src/Service/Ai/Exception/ProviderUnreachableException.php` | `answeredWithStatus(int $status, ?string $reason = null)` |
| `src/Service/Recommendation/Llm/Completion/Pass/ErrorBody.php` | Create: one call's error status and its bounded body |
| `src/Service/Recommendation/Llm/Completion/Pass/CompletionCallSlot.php` | Carries an `ErrorBody` and the credentials |
| `src/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php` | Collects the body, throws with the reason |
| `src/Service/Recommendation/Jev/SystemOneClient/HttpSystemOneClient.php` | Redacts the key from its refusal message |
| `docs/security.md`, `docs/recommendations-runs.md` | The bound, the redaction, where the reason shows |

---

### Task 1: One extractor for a provider's reason

**Interfaces — produces:** `ProviderErrorReason::in(string $body): ?string` — the provider's `detail` (TypeSafe)
or `error.message` (OpenAI, OpenRouter), scrubbed to valid UTF-8 and clipped to 500 characters; null when the body
is not JSON or holds neither.

- [ ] **Step 1: Failing test** `tests/Service/Recommendation/Support/ProviderErrorReasonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Support;

use App\Service\Recommendation\Support\ProviderErrorReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderErrorReasonTest extends TestCase
{
    public function testItReadsAnOpenAiStyleErrorMessage(): void
    {
        self::assertSame(
            'Reasoning effort "none" is not supported by this model.',
            ProviderErrorReason::in(
                '{"error":{"message":"Reasoning effort \"none\" is not supported by this model.","code":400},'
                . '"user_id":"user_2abc"}',
            ),
        );
    }

    public function testItReadsADetailString(): void
    {
        self::assertSame('state is too long', ProviderErrorReason::in('{"detail":"state is too long"}'));
    }

    public function testAStructuredDetailIsShownAsCompactJson(): void
    {
        self::assertSame(
            '[{"loc":["body","state"],"msg":"too long"}]',
            ProviderErrorReason::in('{"detail":[{"loc":["body","state"],"msg":"too long"}]}'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesWithoutAReason(): iterable
    {
        yield 'not json' => ['<html>Bad Request</html>'];
        yield 'empty' => [''];
        yield 'a json string' => ['"nope"'];
        yield 'an error that is a bare string' => ['{"error":"nope"}'];
        yield 'an error without a message' => ['{"error":{"code":400}}'];
        yield 'a message that is not text' => ['{"error":{"message":42}}'];
    }

    #[DataProvider('bodiesWithoutAReason')]
    public function testABodyWithoutAReasonGivesNone(string $body): void
    {
        self::assertNull(ProviderErrorReason::in($body));
    }

    public function testALongReasonIsClippedToFiveHundredCharacters(): void
    {
        $reason = ProviderErrorReason::in(json_encode(['error' => ['message' => str_repeat('ä', 501)]], \JSON_THROW_ON_ERROR));

        self::assertSame(str_repeat('ä', 500) . '…', $reason);
    }

    public function testAReasonOfExactlyFiveHundredCharactersIsKeptWhole(): void
    {
        $reason = ProviderErrorReason::in(json_encode(['error' => ['message' => str_repeat('a', 500)]], \JSON_THROW_ON_ERROR));

        self::assertSame(str_repeat('a', 500), $reason);
    }
}
```

  Check `'an error that is a bare string'` against today's `RefusalMessage::detailIn()` before relying on it: it
  returns null there (`$error` is not an array), and this test pins that.

- [ ] **Step 2: Run it, expect** "class not found".
- [ ] **Step 3: Implement** by moving `RefusalMessage::detailIn()` and its clip into the new class (the JSON
  decode with `JSON_INVALID_UTF8_SUBSTITUTE`, `SystemOneJson::encode()` for an array detail,
  `ClippedText::ofScrubbed(…, 500)`), and make `RefusalMessage::of()` read:

```php
    public static function of(int $status, string $body): string
    {
        $reason = ProviderErrorReason::in($body);

        return null === $reason
            ? sprintf('That provider refused the request (status %d).', $status)
            : sprintf('That provider refused the request (status %d): %s', $status, $reason);
    }
```

  `RefusalMessage`'s docblock keeps its "never the raw body" sentence; the `DETAIL_CHARACTERS` constant moves.
- [ ] **Step 4: Run** the new test and every test under `tests/Service/Recommendation/Jev`, expect green with no
  Jev assertion changed.
- [ ] **Step 5: Commit** — `refactor(#1386): one extractor reads a provider's error reason`.

---

### Task 2: The key never shows in a reason

**Interfaces — produces:** `ProviderCredentialsModel::withoutApiKey(string $text): string`.

- [ ] **Step 1: Failing tests** in the existing `ProviderCredentialsModel` test (find it under
  `tests/Service/Ai/Model/`; create it there if there is none):

```php
    public function testTheKeyIsRedactedWhereverATextRepeatsIt(): void
    {
        $credentials = ProviderCredentialsModel::fromStoredConfiguration('https://llm.example.test/v1', 'sk-secret-1');

        self::assertSame(
            'Invalid key [redacted] (got [redacted]).',
            $credentials->withoutApiKey('Invalid key sk-secret-1 (got sk-secret-1).'),
        );
    }

    public function testAKeylessEndpointLeavesTheTextAlone(): void
    {
        $credentials = ProviderCredentialsModel::fromStoredConfiguration('http://localhost:1234/v1', '');

        self::assertSame('No models loaded.', $credentials->withoutApiKey('No models loaded.'));
    }
```

  The second pins the guard: `str_replace('', …)` on an empty key must not run (it returns the text unchanged in
  PHP 8, so the test alone does not kill the guard's mutant — keep the guard for clarity and let Infection say
  whether it wants more).
- [ ] **Step 2: Run, expect** "undefined method".
- [ ] **Step 3: Implement.**

```php
    public function withoutApiKey(string $text): string
    {
        return '' === $this->apiKey ? $text : str_replace($this->apiKey, '[redacted]', $text);
    }
```

- [ ] **Step 4: Green.** **Step 5: Commit** — `feat(#1386): a provider's text is shown without the api key`.

---

### Task 3: The chat client reads the error body

**Files:** `ProviderUnreachableException`, `Pass/ErrorBody.php` (create), `Pass/CompletionCallSlot.php`,
`OpenAiCompatibleChatClient.php`; test `tests/Service/Recommendation/Llm/Completion/ChatCompletionClient/OpenAiCompatibleChatClientTest.php`.

**Interfaces — consumes:** `ProviderErrorReason::in()`, `withoutApiKey()`.
**Produces:** `ProviderUnreachableException::answeredWithStatus(int $status, ?string $reason = null): self`.

**What I read and what I did not run.** Today `guardStatus()` throws at the first chunk, before any body byte.
`HttpSystemOneClient::outcomeAfter()` shows the working pattern for the other order: read
`$response->getStatusCode()` at the first chunk (otherwise `stream()` throws the status itself), keep taking
chunks, act at the last one. The code below follows that pattern but collects the chunks itself, so it does not
depend on Symfony's `buffer` heuristics for the content type. **None of it has been executed.** If `MockResponse`
or `stream()` behaves differently from this reading, keep the behaviour the tests pin and change the mechanics.

- [ ] **Step 1: Failing tests.** Add to `OpenAiCompatibleChatClientTest`, using its existing helpers
  (`clientAnswering()`, `clientReturning()`, `soleOutcomeOf()`, `connection()`, `request()`, `sseStream()`,
  `ResponseCapturingHttpClient`):

```php
    public function testAnErrorStatusCarriesTheProvidersReason(): void
    {
        $client = $this->clientAnswering(new MockResponse(
            '{"error":{"message":"Reasoning effort \"none\" is not supported.","code":400},"user_id":"user_2abc"}',
            ['http_code' => 400],
        ));

        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage(
            'That provider answered with status 400: Reasoning effort "none" is not supported.',
        );
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testAnErrorBodyThatArrivesInPiecesIsReadWhole(): void
    {
        $body = static function (): \Generator {
            yield '{"error":{"message":"Unknown ';
            yield 'model."}}';
        };
        $client = $this->clientAnswering(new MockResponse($body(), ['http_code' => 404]));

        $this->expectExceptionMessage('That provider answered with status 404: Unknown model.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testAnErrorStatusWithoutAReadableReasonKeepsTheBareSentence(): void
    {
        $client = $this->clientAnswering(new MockResponse('<html>Bad Request</html>', ['http_code' => 400]));

        try {
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail('The error status was not reported.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('That provider answered with status 400.', $exception->getMessage());
        }
    }

    public function testAServerErrorCarriesItsReasonToo(): void
    {
        $client = $this->clientAnswering(new MockResponse(
            '{"error":{"message":"Upstream model crashed."}}',
            ['http_code' => 500],
        ));

        $this->expectExceptionMessage('That provider answered with status 500: Upstream model crashed.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testTheApiKeyIsRedactedFromTheReason(): void
    {
        $key = $this->credentials()->apiKey;
        self::assertNotSame('', $key);
        $client = $this->clientAnswering(new MockResponse(
            json_encode(['error' => ['message' => 'Bad key ' . $key . '.']], \JSON_THROW_ON_ERROR),
            ['http_code' => 400],
        ));

        try {
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail('The error status was not reported.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('That provider answered with status 400: Bad key [redacted].', $exception->getMessage());
        }
    }

    public function testAnErrorBodyLargerThanTheBoundIsNotParsed(): void
    {
        $oversized = json_encode(
            ['error' => ['message' => 'Too big.'], 'padding' => str_repeat('x', 16_384)],
            \JSON_THROW_ON_ERROR,
        );
        $client = $this->clientAnswering(new MockResponse($oversized, ['http_code' => 400]));

        try {
            $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
            self::fail('The error status was not reported.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('That provider answered with status 400.', $exception->getMessage());
        }
    }

    public function testAnErrorBodyOfExactlyTheBoundIsParsed(): void
    {
        $frame = '{"error":{"message":"Fits."},"padding":""}';
        $atTheBound = '{"error":{"message":"Fits."},"padding":"' . str_repeat('x', 16_384 - \strlen($frame)) . '"}';
        self::assertSame(16_384, \strlen($atTheBound));
        $client = $this->clientAnswering(new MockResponse($atTheBound, ['http_code' => 400]));

        $this->expectExceptionMessage('That provider answered with status 400: Fits.');
        $client->complete($this->connection(), $this->request(), new NullCompletionStreamObserver());
    }

    public function testAnErrorStatusNeverReachesTheObserverOrTheAnswer(): void
    {
        $observer = $this->recordingObserver();
        $client = $this->clientReportingTo(/* as the file's other observer tests build it */);
        // An error body is not an answer: no streamProgressed() report, no content.
    }

    public function testOneCallsErrorStatusLeavesItsSiblingsAnswerAlone(): void
    {
        $client = $this->clientReturning([
            new MockResponse('{"error":{"message":"No."}}', ['http_code' => 400]),
            $this->sseStream('{"recommendations":[]}'),
        ]);

        $outcomes = $client->completeMany($this->connection(), [
            $this->concurrentCall(new NullCompletionStreamObserver()),
            $this->concurrentCall(new NullCompletionStreamObserver()),
        ]);

        self::assertSame('That provider answered with status 400: No.', $outcomes[0]->cause()->getMessage());
        self::assertSame('{"recommendations":[]}', $outcomes[1]->content());
    }
```

  `testAnErrorStatusNeverReachesTheObserverOrTheAnswer` is a sketch: I have not read `clientReportingTo()` and
  `recordingObserver()` closely enough to write it. Write it from those helpers so it fails when the error body
  is fed to `CompletionStreamReader` (an observer that records any `streamProgressed()` call, asserted empty).

  The existing `testAStatusOfExactly300IsAlsoUnreachable`, `testAServerErrorIsUnreachable` and the 401 and
  retryable-status tests must pass **unchanged** — their bodies hold no `error.message`.

- [ ] **Step 2: Run the file, expect** the new tests to fail on the bare sentence.

- [ ] **Step 3: Implement.**

`ProviderUnreachableException`:

```php
    public static function answeredWithStatus(int $status, ?string $reason = null): self
    {
        return new self(
            null === $reason
                ? sprintf('That provider answered with status %d.', $status)
                : sprintf('That provider answered with status %d: %s', $status, $reason),
        );
    }
```

`Pass/ErrorBody.php` — per-call, mutable, built with `new` (hence `Pass/`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Completion\Pass;

/** One call's error status and as much of its body as a reason can sit in; a larger body is not kept at all. */
final class ErrorBody
{
    private const int MAXIMUM_BYTES = 16_384;

    private ?int $status = null;

    private string $collected = '';

    private bool $overflowed = false;

    public function open(int $status): void
    {
        $this->status = $status;
    }

    public function isOpen(): bool
    {
        return null !== $this->status;
    }

    public function collect(string $content): void
    {
        if ($this->overflowed) {
            return;
        }
        if (\strlen($this->collected) + \strlen($content) > self::MAXIMUM_BYTES) {
            $this->overflowed = true;
            $this->collected = '';

            return;
        }
        $this->collected .= $content;
    }

    public function status(): int
    {
        return $this->status ?? throw new \LogicException('No error status was opened for this call.');
    }

    public function text(): string
    {
        return $this->collected;
    }
}
```

`CompletionCallSlot` gains two constructor properties: `public ErrorBody $errorBody` and
`public ProviderCredentialsModel $credentials`. That makes six; PHPMD's parameter count will object. Resolve it
by passing the `ProviderConnectionModel` (it already holds `timeouts` and `credentials`) in place of
`$timeouts`, and read `$slot->connection->timeouts` at the two sites that use it. `fireRequests()` builds the
slot with `new ErrorBody()`.

`OpenAiCompatibleChatClient::consumeChunk()` — the status guard and the content step become:

```php
        if ($chunk->isFirst()) {
            $this->guardStatus($response, $slot->errorBody);
        }

        $content = $chunk->getContent();
        if ($slot->errorBody->isOpen()) {
            return $this->collectErrorBody($slot, $content, $chunk->isLast());
        }
```

```php
    /** Credentials and retryable statuses end the call here; any other error status opens its body for the reason. */
    private function guardStatus(ResponseInterface $response, ErrorBody $errorBody): void
    {
        $status = $response->getStatusCode();

        if (401 === $status || 403 === $status) {
            throw CredentialsRejectedException::refusedKey();
        }

        if (\in_array($status, [429, 502, 503, 504], true)) {
            throw new RetryableProviderException($status, RetryAfter::secondsIn($response));
        }

        if ($status >= 300) {
            $errorBody->open($status);
        }
    }

    private function collectErrorBody(CompletionCallSlot $slot, string $content, bool $isLast): bool
    {
        $slot->errorBody->collect($content);
        if (!$isLast) {
            return false;
        }

        $reason = ProviderErrorReason::in($slot->errorBody->text());

        throw ProviderUnreachableException::answeredWithStatus(
            $slot->errorBody->status(),
            null === $reason ? null : $slot->credentials->withoutApiKey($reason),
        );
    }
```

  `consumeChunk()` is already long; if PHPMD or its own readability says so, split the answer path into its own
  method rather than nest. `advance()` already catches `ProviderUnreachableException` and cancels the response.

- [ ] **Step 4: Run the whole client test file and `tests/Service/Recommendation`, expect green.**
- [ ] **Step 5: Break-test the bound.** Temporarily change `MAXIMUM_BYTES` to `16_385`; confirm
  `testAnErrorBodyLargerThanTheBoundIsNotParsed` or its at-the-bound sibling fails; restore by editing it back.
- [ ] **Step 6: Commit** — `fix(#1386): a provider's error status carries the provider's reason`.

---

### Task 4: The JEV client redacts the key too

- [ ] **Failing test** in `tests/Service/Recommendation/Jev/SystemOneClient/HttpSystemOneClientTest.php` (use
  the file's own helpers; I have not read it): a 400 whose `detail` repeats the connection's API key is reported
  with `[redacted]` in its place.
- [ ] **Implement** in `HttpSystemOneClient::outcomeOf()`: the 400/422 arm wraps the message in
  `$connection->credentials->withoutApiKey(…)`. `outcomeOf()` has no connection today — read how the wave
  reaches its credentials and thread the least: prefer giving `SystemOneWave` the credentials it already sends
  with over a new parameter chain (phptramp fails a chain of 4).
- [ ] **Commit** — `fix(#1386): the jev client's refusal message hides the api key`.

---

### Task 5: Docs, simplify, gates, real run, PR

- [ ] `docs/security.md#ai-provider-endpoints`: one paragraph — an error body is read up to 16 KiB, only
  `detail`/`error.message` is shown, clipped to 500 characters, with the API key redacted.
- [ ] `docs/recommendations-runs.md`: where it describes a transport failure's message, say it carries the
  provider's reason when the provider gave one.
- [ ] Run `/simplify` on the branch diff; apply what it finds; re-run the affected tests.
- [ ] `bin/console cache:warmup && composer check && composer md`; `composer test:parallel`;
  `docker compose exec php composer test`; `git add -A && composer infection:diff`; PhpStorm lint on changed PHP.
- [ ] **Real run** (check the container serves this branch first): on the dev stack, pick one of the models
  #1388 lists as rejecting suppressed reasoning (e.g. `google/gemini-3.8-flash`), keep "Ask the model not to
  reason" on, generate the profile. The run still fails after three attempts (that is #1387), but
  `recommendation_run_log.error_detail` and the run's error now hold the provider's sentence. **Quote that
  sentence verbatim in the PR body and in a comment on #1388** — #1388's plan is written without knowing it.
  Restore the dev account's connection and model afterwards.
- [ ] Scan today's `backend/var/log/dev-*.log`.
- [ ] PR into `develop`, body ending `Closes #1386`, listing the decisions above as the planner's. **Merge when
  all checks are green** (Lars approved merge-when-green for these three PRs on 2026-10-05), verify #1386
  auto-closed, then report to "Saved searches in For You profile" and go on to #1387.
