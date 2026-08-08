# Stream Provider Responses (#312) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The AI provider client reads the model's answer as an SSE stream (`stream: true`) so a dead connection fails after a short inactivity timeout instead of eating the whole 120 s request budget.

**Architecture:** `OpenAiCompatibleChatClient` keeps its `ChatCompletionClient::complete()` interface unchanged — both run drivers (#308 poll ticks, #311 worker) and the whole #308 parse/salvage/retry pipeline inherit streaming for free. The client sends `stream: true`, reads the response chunk-by-chunk via `HttpClientInterface::stream()` (the same idiom `ConcurrentFeedFetcher` already uses), and accumulates the raw bytes. A new pure class `CompletionBodyDecoder` turns the accumulated body into the assistant content: it joins SSE `delta.content` fragments, and falls back to the plain (blocking) JSON envelope so a provider that ignores `stream: true` keeps working. A stalled stream (no chunk for `INACTIVITY_TIMEOUT_SECONDS`) throws `ProviderUnreachableException` — the same typed exception as any transport failure, so #308 retry/checkpoint semantics apply unchanged.

**Tech Stack:** PHP 8.4, Symfony 7.4 HttpClient (`stream()`, `MockHttpClient`/`MockResponse` for tests), PHPUnit.

## Global Constraints

- `declare(strict_types=1)` in every PHP file; PSR-12 (`composer cs`); PHPStan level max (`composer stan`, warm the cache with `bin/console cache:warmup` first).
- Every touched `src` file must be PHPMD-clean (`composer md`) before commit — the standing rule, not just "no new findings".
- House style: `final readonly class`, constructor promotion, guard clauses, no boolean flag parameters, comments explain *why*.
- Mutation testing gates the touched files: `composer infection:diff` must meet `minMsi` (needs pcov or xdebug). Write tests that pin messages and boundaries, not just exception types.
- The outbound HTTP boundary rules stay as they are: `max_redirects: 0`, `Accept-Encoding: identity`, the wire cap via `on_progress`, and the recorded `ProviderCredentials` SSRF exception (do NOT add or remove guards there).
- `OpenAiCompatibleChatClient::TIMEOUT_SECONDS` stays `120.0` and stays `public` — `WorkerPresence::FRESH_SECONDS` is sized against it (#311). Streaming must not change the wall-clock bound.
- Per-call wall time still fits the Strato FastCGI window via #308 batch sizing — nothing in this plan touches batch sizing.
- Out of scope: token-level streaming to the browser; any frontend change; any migration.
- Branch: `feature/312-stream-provider-responses` off `develop`. Run `git status` first — concurrent sessions can share this checkout.
- Commit messages follow the existing pattern: `feat(#312): …`, `test(#312): …`, `refactor(#312): …`.

## Background for an engineer with zero context

- The client under change: [backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php](../../../backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php). It makes the ONE `POST {baseUrl}/chat/completions` call a run tick performs. Its interface is `ChatCompletionClient` (same directory).
- In Symfony HttpClient, the `timeout` option is an **idle/inactivity** timeout; `max_duration` is the **wall-clock** cap. Today both are 120 s. With a blocking (`stream: false`) completion the server sends *nothing* until the whole answer is ready, so a short idle timeout would kill healthy slow generations — that is exactly why this issue turns on streaming: with `stream: true`, deltas arrive continuously while the model generates, so silence means a genuinely dead connection.
- The streaming read idiom to copy: `ConcurrentFeedFetcher::awaitNext()`/`advance()` ([backend/src/Service/Fetch/ConcurrentFeedFetcher.php:111-173](../../../backend/src/Service/Fetch/ConcurrentFeedFetcher.php)). Note its load-bearing comment: on a timeout chunk `isTimeout()` returns `true` while other accessors throw — always ask `isTimeout()` **first**.
- SSE wire format of an OpenAI-compatible streamed completion: a sequence of events, each `data: {json}\n\n`; content arrives as `choices[0].delta.content` string fragments; some events carry no content (role-only first delta, usage events with empty `choices`); the stream ends with `data: [DONE]`.
- Testing a timeout: in a `MockResponse`, a body **generator that yields `''`** is turned into a timeout chunk by `MockHttpClient` — this is the documented Symfony way to simulate a stalled stream.
- `$userAgent` is bound globally in `services.yaml` (`string $userAgent: '%outbound_user_agent%'`); new constructor dependencies on autowirable classes need no YAML edit.
- Inactivity timeout value: `30.0` s. The binding constraint is time-to-first-token — a local provider evaluating a large ranking prompt sends nothing until the first token. 30 s covers that for the batch sizes #308 produces; if a real local setup proves slower, raising the constant is a one-line change. Do not invent a second "first token" timeout (YAGNI).

## File Structure

| File | Responsibility |
|---|---|
| Create `backend/src/Service/Recommendation/CompletionBodyDecoder.php` | Pure decoder: accumulated response body → assistant content (`?string`). Owns SSE delta joining AND the blocking-envelope fallback (moves `assistantContent()` out of the client). |
| Create `backend/tests/Service/Recommendation/CompletionBodyDecoderTest.php` | Unit tests for the decoder. |
| Modify `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php` | Sends `stream: true`; reads chunk-by-chunk with a 30 s inactivity timeout; delegates body decoding; keeps status handling, wire cap, redirect refusal unchanged. |
| Modify `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php` | SSE happy path, `stream: true` payload pin, stall-abort test; existing tests adapted where the body shape changed. |

No changes to: `ChatCompletionClient` (interface), `StubChatClient`, `RecommendationRunAdvancer`, `WorkerPresence`, `services.yaml`, `services_test.yaml`, frontend, migrations.

---

### Task 1: `CompletionBodyDecoder`

**Files:**
- Create: `backend/src/Service/Recommendation/CompletionBodyDecoder.php`
- Test: `backend/tests/Service/Recommendation/CompletionBodyDecoderTest.php`

**Interfaces:**
- Consumes: nothing (pure class, no constructor dependencies).
- Produces: `CompletionBodyDecoder::assistantContent(string $body): ?string` — Task 2 injects this class into `OpenAiCompatibleChatClient` and treats `null` as "answered without a completion".

- [ ] **Step 0: Branch off develop**

```bash
git status   # concurrent sessions can share this checkout — abort if another session is mid-edit
git checkout develop && git pull && git checkout -b feature/312-stream-provider-responses
```

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Recommendation/CompletionBodyDecoderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CompletionBodyDecoder;
use PHPUnit\Framework\TestCase;

final class CompletionBodyDecoderTest extends TestCase
{
    private CompletionBodyDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new CompletionBodyDecoder();
    }

    public function testJoinsTheContentDeltasOfAStreamedAnswer(): void
    {
        $body = 'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"{\"recommend"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"ations\":[]}"}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        self::assertSame('{"recommendations":[]}', $this->decoder->assistantContent($body));
    }

    /**
     * Providers routed through some proxies deliver CRLF line endings; the
     * split must not leave a trailing "\r" glued to the JSON payload.
     */
    public function testACrlfSeparatedStreamDecodesToo(): void
    {
        $body = "data: {\"choices\":[{\"delta\":{\"content\":\"one\"}}]}\r\n\r\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\" two\"}}]}\r\n\r\n"
            . "data: [DONE]\r\n\r\n";

        self::assertSame('one two', $this->decoder->assistantContent($body));
    }

    /**
     * Usage events arrive with an empty choices list and the terminal event
     * is the literal [DONE]; neither may abort the join or contribute text.
     */
    public function testEventsWithoutAContentDeltaAreSkipped(): void
    {
        $body = 'data: {"choices":[{"delta":{"content":"kept"}}]}' . "\n\n"
            . 'data: {"choices":[],"usage":{"total_tokens":9}}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        self::assertSame('kept', $this->decoder->assistantContent($body));
    }

    public function testAMalformedEventIsSkippedNotFatal(): void
    {
        $body = 'data: not json at all' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"still here"}}]}' . "\n\n";

        self::assertSame('still here', $this->decoder->assistantContent($body));
    }

    public function testAStreamWithNoContentAtAllIsNull(): void
    {
        $body = 'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n";

        self::assertNull($this->decoder->assistantContent($body));
    }

    /**
     * A provider that ignores `stream: true` and answers with the blocking
     * envelope must keep working — the fallback is the pre-#312 parse.
     */
    public function testABlockingEnvelopeStillDecodes(): void
    {
        $body = '{"choices":[{"message":{"content":"plain answer"}}]}';

        self::assertSame('plain answer', $this->decoder->assistantContent($body));
    }

    public function testABlockingEnvelopeWithoutContentIsNull(): void
    {
        self::assertNull($this->decoder->assistantContent('{"choices":[]}'));
    }

    public function testANonJsonBodyIsNull(): void
    {
        self::assertNull($this->decoder->assistantContent('not json'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionBodyDecoderTest.php
```

Expected: ERROR — `Class "App\Service\Recommendation\CompletionBodyDecoder" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/src/Service/Recommendation/CompletionBodyDecoder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one accumulated /chat/completions response body into the assistant
 * content, whichever shape the provider chose: the SSE transcript a
 * `stream: true` request produces, or the blocking JSON envelope from a
 * provider that ignores the flag. Null means the body carried no completion.
 */
final readonly class CompletionBodyDecoder
{
    public function assistantContent(string $body): ?string
    {
        // A raw newline cannot occur inside a JSON string (it must be escaped),
        // so a line-initial "data:" cannot appear in a blocking envelope: this
        // detection cannot misread one shape as the other.
        if (1 === preg_match('/^data:/m', $body)) {
            return $this->joinedStreamDeltas($body);
        }

        return $this->blockingEnvelopeContent($body);
    }

    private function joinedStreamDeltas(string $body): ?string
    {
        $deltas = [];

        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, \strlen('data:')));

            if ('' === $payload || '[DONE]' === $payload) {
                continue;
            }

            $event = json_decode($payload, true);
            $content = \is_array($event) ? $this->deltaContent($event) : null;

            if (\is_string($content)) {
                $deltas[] = $content;
            }
        }

        return [] === $deltas ? null : implode('', $deltas);
    }

    /** @param array<mixed> $event */
    private function deltaContent(array $event): mixed
    {
        $choices = $event['choices'] ?? null;
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;
        $delta = \is_array($firstChoice) ? ($firstChoice['delta'] ?? null) : null;

        return \is_array($delta) ? ($delta['content'] ?? null) : null;
    }

    private function blockingEnvelopeContent(string $body): ?string
    {
        $decoded = json_decode($body, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $choices = $decoded['choices'] ?? null;
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;
        $message = \is_array($firstChoice) ? ($firstChoice['message'] ?? null) : null;
        $content = \is_array($message) ? ($message['content'] ?? null) : null;

        return \is_string($content) ? $content : null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionBodyDecoderTest.php
```

Expected: OK, 8 tests.

- [ ] **Step 5: Lint the new files**

```bash
cd backend && composer cs && bin/console cache:warmup --env=dev && composer stan && composer md
```

Also run PhpStorm inspections (`mcp__phpstorm__lint_files`) on both new files; fix ERROR and WARNING findings.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Recommendation/CompletionBodyDecoder.php backend/tests/Service/Recommendation/CompletionBodyDecoderTest.php
git commit -m "feat(#312): decode streamed and blocking completion bodies in one place"
```

---

### Task 2: Streamed read with inactivity abort in `OpenAiCompatibleChatClient`

**Files:**
- Modify: `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php`
- Test: `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`

**Interfaces:**
- Consumes: `CompletionBodyDecoder::assistantContent(string $body): ?string` from Task 1 (constructor-injected; autowiring covers it, no YAML edit — `$userAgent` stays bound globally).
- Produces: `ChatCompletionClient::complete()` unchanged in signature and exception contract (`CredentialsRejectedException`, `ProviderUnreachableException`) — nothing downstream changes.
- New constant: `OpenAiCompatibleChatClient::INACTIVITY_TIMEOUT_SECONDS = 30.0` (private). `TIMEOUT_SECONDS` stays `public const float 120.0`.

- [ ] **Step 1: Update the test file to the streamed contract (failing first)**

Apply these changes to `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`:

**(a)** Replace `testReturnsTheAssistantContent` — the happy path is now a streamed answer, and the request pin gains `'stream' => true`:

```php
public function testReturnsTheAssistantContentJoinedFromTheStream(): void
{
    $seen = [];
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
        $seen = [
            'method' => $method,
            'url' => $url,
            'headers' => $options['headers'] ?? [],
            'body' => $options['body'] ?? null,
        ];

        return new MockResponse([
            'data: {"choices":[{"delta":{"role":"assistant"}}]}' . "\n\n",
            'data: {"choices":[{"delta":{"content":"{\"recommend"}}]}' . "\n\n",
            'data: {"choices":[{"delta":{"content":"ations\":[]}"}}]}' . "\n\n",
            'data: [DONE]' . "\n\n",
        ]);
    });

    $content = (new OpenAiCompatibleChatClient($client, new CompletionBodyDecoder(), 'SimpleFeedReader/1.0'))
        ->complete($this->credentials(), 'm', $this->messages());

    self::assertSame('{"recommendations":[]}', $content);

    /** @var array{method: string, url: string, headers: array<int, string>, body: string} $seen */
    self::assertSame('POST', $seen['method']);
    self::assertSame('https://api.example.test/v1/chat/completions', $seen['url']);
    self::assertContains('Authorization: Bearer sk-test', $seen['headers']);
    self::assertContains('Accept-Encoding: identity', $seen['headers']);

    $decodedBody = json_decode($seen['body'], true);
    self::assertSame([
        'model' => 'm',
        'messages' => $this->messages(),
        'response_format' => ['type' => 'json_object'],
        'stream' => true,
    ], $decodedBody);
}
```

**(b)** Add the blocking-envelope fallback pin (replaces the old happy path's coverage of that shape):

```php
/**
 * A provider that ignores `stream: true` answers with the blocking envelope;
 * the client must accept it exactly as it did before #312.
 */
public function testABlockingEnvelopeAnswerStillWorks(): void
{
    $client = $this->clientAnswering(
        new MockResponse('{"choices":[{"message":{"content":"{\"recommendations\":[]}"}}]}'),
    );

    self::assertSame(
        '{"recommendations":[]}',
        $client->complete($this->credentials(), 'm', $this->messages()),
    );
}
```

**(c)** Add the stall test — this is the feature:

```php
/**
 * The point of #312: a stream that goes silent is aborted after the
 * inactivity window and surfaces as the same typed transport failure the
 * #308 retry pipeline already handles — not after the full 120 s budget.
 *
 * MockHttpClient turns an empty string yielded by a body generator into a
 * timeout chunk, the documented way to simulate a stalled stream.
 */
public function testASilentStreamIsAbortedAsUnreachable(): void
{
    $body = static function (): \Generator {
        yield 'data: {"choices":[{"delta":{"content":"par"}}]}' . "\n\n";
        yield '';
    };
    $client = $this->clientAnswering(new MockResponse($body()));

    $this->expectException(ProviderUnreachableException::class);
    $this->expectExceptionMessage('That provider stopped streaming for more than 30 seconds.');
    $client->complete($this->credentials(), 'm', $this->messages());
}
```

**(d)** Update `clientAnswering()` and the redirect test's direct construction to pass the new constructor argument `new CompletionBodyDecoder()` (second parameter, before the user agent), and add the import `use App\Service\Recommendation\CompletionBodyDecoder;`. All other existing tests (`401`, `403`, `300`, `500`, redirect, non-JSON, empty envelope, oversize, transport error) keep their bodies and assertions unchanged — their inputs contain no `data:` line, so they exercise the fallback path and must stay green as-is. Keep the oversize test's chunked body exactly as it is; the wire cap must keep firing on a streamed read.

- [ ] **Step 2: Run the test to verify the new/changed tests fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php
```

Expected: FAIL — constructor argument mismatch, missing `stream: true` in the pinned payload, and the stall test failing (currently the generic "That address did not answer." message, not the stall message).

- [ ] **Step 3: Implement the streamed read**

Modify `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php` to:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ProviderCredentials;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends `POST {baseUrl}/chat/completions`, the one call a tick makes to turn
 * a prompt into a ranking.
 *
 * The caps are not an SSRF boundary — see ProviderCredentials for why there is
 * none — they keep one hostile or broken endpoint from holding a request open
 * or filling memory.
 */
final readonly class OpenAiCompatibleChatClient implements ChatCompletionClient
{
    // A ranking over a large batch can legitimately generate for minutes; this
    // is also why the tick endpoint performs exactly one call — the whole tick
    // must fit one FastCGI request.
    //
    // Public because it is a published bound, not an implementation detail:
    // WorkerPresence::FRESH_SECONDS has to outlast one call of this length or
    // the worker looks dead while it is merely thinking (#311).
    public const float TIMEOUT_SECONDS = 120.0;

    // The answer arrives as an SSE stream (#312), so silence — no delta for
    // this long — means a dead connection, not a thinking model. The binding
    // constraint on this value is time-to-first-token: the provider sends
    // nothing while it evaluates the prompt, and a local model on a large
    // #308 batch needs the headroom. Raise it before inventing a second
    // "first token" timeout.
    private const float INACTIVITY_TIMEOUT_SECONDS = 30.0;
    private const int MAXIMUM_RESPONSE_BYTES = 2_097_152;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CompletionBodyDecoder $decoder,
        private string $userAgent,
    ) {
    }

    public function complete(ProviderCredentials $credentials, string $model, array $messages): string
    {
        $content = $this->decoder->assistantContent($this->readBody($credentials, $model, $messages));

        if (!\is_string($content)) {
            throw new ProviderUnreachableException('That provider answered without a completion.');
        }

        return $content;
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function readBody(ProviderCredentials $credentials, string $model, array $messages): string
    {
        try {
            $response = $this->request($credentials, $model, $messages);
            $status = $response->getStatusCode();

            if (401 === $status || 403 === $status) {
                throw new CredentialsRejectedException('That provider refused the API key.');
            }

            if ($status >= 300) {
                throw new ProviderUnreachableException(sprintf('That provider answered with status %d.', $status));
            }

            return $this->streamedBody($response);
        } catch (ExceptionInterface $e) {
            throw new ProviderUnreachableException('That address did not answer.', 0, $e);
        }
    }

    /**
     * Accumulates the SSE body chunk by chunk. Passing the timeout to
     * stream() makes a stall arrive as a timeout chunk instead of an
     * exception, so it can carry its own message: the distinction between
     * "never answered" and "went silent mid-answer" is real to a user
     * deciding whether their provider is down or their network dropped.
     *
     * @throws ExceptionInterface
     */
    private function streamedBody(ResponseInterface $response): string
    {
        $body = '';

        foreach ($this->httpClient->stream($response, self::INACTIVITY_TIMEOUT_SECONDS) as $chunk) {
            // isTimeout() first — on a timeout chunk the other accessors
            // throw; same ordering hazard ConcurrentFeedFetcher documents.
            if ($chunk->isTimeout()) {
                $response->cancel();

                throw new ProviderUnreachableException(sprintf(
                    'That provider stopped streaming for more than %d seconds.',
                    (int) self::INACTIVITY_TIMEOUT_SECONDS,
                ));
            }

            $body .= $chunk->getContent();
        }

        return $body;
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function request(ProviderCredentials $credentials, string $model, array $messages): ResponseInterface
    {
        return $this->httpClient->request('POST', $credentials->baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials->apiKey,
                'Accept' => 'text/event-stream, application/json',
                // Refuse transparent compression so the wire cap below also bounds
                // the buffered body — gzip would otherwise let a small reply
                // decompress unbounded after the cap has already passed it. Same
                // reasoning as ConcurrentFeedFetcher::headers().
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
            ],
            'json' => [
                'model' => $model,
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
                'stream' => true,
            ],
            // Idle bound only: with a streamed answer, deltas tick this over
            // continuously, so it fires on dead connections, not slow models.
            // max_duration stays the published wall-clock bound.
            'timeout' => self::INACTIVITY_TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
            // Capped on the wire, the one size-cap mechanism this codebase has
            // (ConcurrentFeedFetcher::send(), HtmlPageFetcher, CatalogFaviconFetcher):
            // a provider answering with gigabytes is refused as the bytes arrive,
            // rather than truncated into a body that can only fail to parse. The
            // transport re-reports the aborted download as its own failure, which
            // readBody() translates back into this domain's refusal.
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAXIMUM_RESPONSE_BYTES) {
                    throw new ProviderUnreachableException(sprintf(
                        'That provider answered with more than %d bytes.',
                        self::MAXIMUM_RESPONSE_BYTES,
                    ));
                }
            },
        ]);
    }
}
```

Implementation notes for the engineer:

- `assistantContent()` (the old private method) is deleted from the client — it now lives in `CompletionBodyDecoder::blockingEnvelopeContent()`.
- `ProviderUnreachableException` and `CredentialsRejectedException` are **not** Symfony `ExceptionInterface`, so the throws inside the `try` (status checks, the stall throw inside `streamedBody()`) pass through the `catch` untouched. Only transport-level failures get rewritten to "That address did not answer."
- The `timeout` request option now also bounds the wait for response *headers* at 30 s (it used to be 120 s). That is intended: a provider that takes >30 s to even send headers is stalled by this issue's definition.
- Do not change `TIMEOUT_SECONDS`, its visibility, or `WorkerPresence` — the 120 s wall bound is unchanged.

- [ ] **Step 4: Run the full client test file**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php tests/Service/Recommendation/CompletionBodyDecoderTest.php
```

Expected: PASS — including every pre-existing test (401/403/300/500/redirect/non-JSON/empty-envelope/oversize/transport-error) with unchanged assertions.

- [ ] **Step 5: Run the whole SQLite suite**

```bash
cd backend && php bin/phpunit
```

Expected: green. (`StubChatClient` satisfies the unchanged interface; nothing else compiles against the client's constructor except the DI container, which autowires the decoder.)

- [ ] **Step 6: Lint**

```bash
cd backend && composer cs && composer stan && composer md
```

Also run PhpStorm inspections (`mcp__phpstorm__lint_files`) on the two modified files; fix ERROR and WARNING findings. Both touched `src` files must be fully PHPMD-clean.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php
git commit -m "feat(#312): stream provider responses and abort a silent stream early"
```

---

### Task 3: Verification sweep and PR

**Files:** none created — this task runs the gates the branch must pass.

**Interfaces:**
- Consumes: the finished code from Tasks 1–2.
- Produces: a green branch and a PR into `develop` whose body says `Closes #312`.

- [ ] **Step 1: Mutation gate on the changed files**

```bash
cd backend && composer infection:diff
```

Expected: MSI at or above `minMsi` in `infection.json5`. If mutants escape in `CompletionBodyDecoder` or the client, add pinning tests (message and boundary assertions, per the existing test file's style) — do not lower the gate. Commit any added tests as `test(#312): pin the assertions escaped mutants showed were absent`.

- [ ] **Step 2: MySQL leg**

```bash
docker compose up -d
docker compose exec php vendor/bin/phpunit
```

Expected: green, except the known order-dependent rate-limiter flake (pre-existing, passes in isolation — verify by re-running the failing test alone before dismissing it).

- [ ] **Step 3: Scan the dev log**

```bash
tail -n 200 backend/var/log/dev.log
```

Expected: no new deprecations or swallowed errors from the recommendation namespace.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin feature/312-stream-provider-responses
gh pr create --base develop --title "Stream provider responses in the AI client for early stall detection" --body "$(cat <<'EOF'
Closes #312

The #305 provider client now consumes the completion as an SSE stream (`stream: true`).

- Deltas are accumulated into the full response body; the #308 parse/salvage/retry pipeline operates on the accumulated text unchanged, and a provider that ignores `stream: true` still works via the blocking-envelope fallback.
- A stream with no chunk for 30 s is aborted and surfaces as `ProviderUnreachableException`, the same typed path as a transport failure, so #308 retry/checkpoint semantics apply.
- The wall-clock bound is untouched: `TIMEOUT_SECONDS` stays 120 s as `max_duration`, so #308 batch sizing and `WorkerPresence::FRESH_SECONDS` (#311) are unaffected.
- The outbound boundary is unchanged: `max_redirects: 0`, identity encoding, and the wire cap all still apply to the streamed read.

Out of scope (per the issue): token-level streaming to the browser.
EOF
)"
```

After CI is green and the PR merges, verify #312 closed automatically. Do **not** tag a deploy.

---

## Self-Review (performed while writing)

- **Spec coverage:** stream:true consumption (Task 2 request payload); early stall detection via inactivity timeout with the typed transport exception (Task 2 `streamedBody()` + stall test); deltas accumulated so #308 parsing is unchanged (Task 1 decoder + unchanged `complete()` return contract); wall-clock budget untouched (`TIMEOUT_SECONDS` constraint, pinned in Global Constraints); outbound boundary rules retained (identity encoding, wire cap, `max_redirects: 0` all kept, existing tests stay as regression pins); both drivers inherit it because the `ChatCompletionClient` interface is unchanged; out-of-scope browser streaming excluded.
- **Placeholder scan:** all code steps carry complete code; no TBDs.
- **Type consistency:** `assistantContent(string): ?string` is used identically in Task 1 (definition), Task 2 (constructor injection and call); constant names match between tasks and the PR body.
