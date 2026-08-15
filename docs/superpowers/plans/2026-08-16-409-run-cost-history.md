# Run Cost Capture and History Implementation Plan (#409)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture the provider's own `usage` accounting on every recommendation
provider call, bank per-run totals independently of the debug switch, and show a
run history with an all-time cost total in AI settings.

**Architecture:** A new `CompletionUsage` value object is decoded out of the SSE
stream's final usage message by `CompletionBodyDecoder`, carried to the observer
on the existing `CompletionStreamProgress` object (no new parameters through the
call chain — that is this codebase's stated fix for tramp data), and banked by
`RecordedCall` into new `recommendation_run` columns using DBAL SQL arithmetic at
settle time. A new read-only endpoint and Angular card render the history.

**Tech Stack:** PHP 8.4 / Symfony 7.4 / Doctrine ORM 3.6 / DBAL 4.4, PHPUnit,
Angular 20 standalone + signals, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-08-16-run-cost-history-design.md`
**Issue:** https://github.com/larspohlmann/simple-feed-reader/issues/409
**Branch:** `feature/409-run-cost-history` (already checked out, off `develop`)

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- `final readonly class` with constructor promotion is the house style; `final`
  unless designed for extension.
- Comments explain **why**, never **what**. Match the density of the surrounding
  file — this codebase's existing comments are long and justify decisions.
- Names reveal intent. No `$data`/`$info`/`$tmp`. No boolean flag parameters.
- Guard clauses over nesting. Errors are typed exceptions, never `null` sentinels
  — except where a class already documents null as "absent", which
  `CompletionBodyDecoder` does ("Null means the JSON carried no content").
- Controllers hold no private methods that carry responsibility
  (`ThinControllerRule`, enforced by `composer stan`).
- Every `src` file you touch must be **PHPMD-clean** before commit, not merely
  free of *new* findings.
- Tests are production code: same naming, same structure, same standards.
- Frontend: standalone components and signals, no NgModules. Component styles
  live in a sibling `.scss` file via `styleUrl`, never inline. **No hex colours,
  no raw `px` spacing, no media-query literals** outside `src/app/theme/` — use
  the CSS custom properties and `@use '../theme/breakpoints' as bp;`.
- Prettier prints at 100 columns in `frontend/`.
- Dates render through `formatDateOr`/`formatLongDate`/`formatTime` from
  `src/app/reader/format.ts` — `DatePipe` always renders `en-US` here.
- Datetimes are stored as **naive UTC**; format with
  `->format('Y-m-d H:i:s')` for DBAL writes and
  `->format(\DateTimeInterface::ATOM)` on the wire.
- New i18n keys go into **both** `frontend/public/i18n/en.json` and
  `frontend/public/i18n/de.json`.
- Commit after each task. Do not merge, do not push a tag, do not deploy.

## File Structure

**Backend — created**

| Path | Responsibility |
|---|---|
| `backend/src/Service/Recommendation/CompletionUsage.php` | The provider's usage accounting for one call, as a value object |
| `backend/migrations/Version20260816120000.php` | The seven new `recommendation_run` columns, platform-aware |
| `backend/src/Http/RecommendationRunHistoryJson.php` | Wire shape of the history payload |
| `backend/src/Controller/Api/RecommendationRunHistoryController.php` | `GET /api/recommendations/runs/history` |
| `backend/tests/Service/Recommendation/CompletionUsageTest.php` | VO tests |
| `backend/tests/Http/RecommendationRunHistoryJsonTest.php` | Mapper tests |
| `backend/tests/Controller/Api/RecommendationRunHistoryControllerTest.php` | Functional endpoint test |

**Backend — modified**

| Path | Change |
|---|---|
| `backend/src/Service/Recommendation/CompletionBodyDecoder.php` | `usage()` public method; `streamEvent()` gains a `usage` key; one decode shared |
| `backend/src/Service/Recommendation/CompletionStreamReader.php` | Sticky `?CompletionUsage`, `usage()` accessor |
| `backend/src/Service/Recommendation/CompletionStreamProgress.php` | Fourth property `?CompletionUsage $usage` |
| `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php` | `stream_options: {include_usage: true}`; usage on the progress |
| `backend/src/Entity/RecommendationRun.php` | Seven columns, `stampProvider()`, read accessors |
| `backend/src/Service/Recommendation/RecordedCall.php` | Holds the usage, banks it at settle |
| `backend/src/Service/Recommendation/RecommendationRunStarter.php` | Stamps provider host + model on start and resume |
| `backend/src/Repository/RecommendationRunRepository.php` | `historyForUser()`, `totalCostNanoCredits()` |
| Corresponding tests under `backend/tests/**` | |

**Frontend — created**

| Path | Responsibility |
|---|---|
| `frontend/src/app/settings/recommendation-run-history.component.ts` | The card's state and formatting |
| `frontend/src/app/settings/recommendation-run-history.component.html` | Markup |
| `frontend/src/app/settings/recommendation-run-history.component.scss` | Styles |
| `frontend/src/app/settings/recommendation-run-history.component.spec.ts` | Jest spec |

**Frontend — modified**

| Path | Change |
|---|---|
| `frontend/src/app/reader/models.ts` | `RunHistoryRow`, `RunHistoryPayload` |
| `frontend/src/app/reader/reader-api.ts` | `runHistory()` |
| `frontend/src/app/settings/ai-section.component.html` | Mount the card inside `@if (activeReady())` |
| `frontend/src/app/settings/ai-section.component.ts` | Import the component |
| `frontend/public/i18n/en.json`, `de.json` | `settings.ai.recommendations.history*` keys |

---

### Task 1: `CompletionUsage` value object

**Files:**
- Create: `backend/src/Service/Recommendation/CompletionUsage.php`
- Test: `backend/tests/Service/Recommendation/CompletionUsageTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `final readonly class App\Service\Recommendation\CompletionUsage`
  with public promoted properties `int $promptTokens`, `int $completionTokens`,
  `int $reasoningTokens`, `int $cachedTokens`, `?int $costNanoCredits`. No
  methods.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Recommendation/CompletionUsageTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\CompletionUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionUsage::class)]
final class CompletionUsageTest extends TestCase
{
    public function testCarriesEveryFieldTheProviderReported(): void
    {
        $usage = new CompletionUsage(1200, 340, 280, 900, 41_230_000);

        self::assertSame(1200, $usage->promptTokens);
        self::assertSame(340, $usage->completionTokens);
        self::assertSame(280, $usage->reasoningTokens);
        self::assertSame(900, $usage->cachedTokens);
        self::assertSame(41_230_000, $usage->costNanoCredits);
    }

    public function testAnUnpricedCallCarriesTokensWithNoCost(): void
    {
        $usage = new CompletionUsage(10, 5, 0, 0, null);

        self::assertSame(10, $usage->promptTokens);
        self::assertNull($usage->costNanoCredits);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionUsageTest.php
```

Expected: FAIL — `Class "App\Service\Recommendation\CompletionUsage" not found`.

- [ ] **Step 3: Write the implementation**

`backend/src/Service/Recommendation/CompletionUsage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * What one provider call actually consumed, as the provider itself accounts
 * for it (#409). Read off the `usage` object OpenAI-compatible endpoints send
 * in the last message of a streamed reply — the only number in the whole
 * exchange that is the provider's own, rather than our guess at one. Wire
 * bytes are not a cost proxy: reasoning bytes and SSE framing inflate them.
 *
 * Cost is nano-credits as an integer because it is money, and money is never
 * a float. Null means the provider reported no price at all, which is the
 * same answer as "local model, free" — deliberately not zero, because zero
 * claims the call was free, and that is a different statement from unpriced.
 */
final readonly class CompletionUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $reasoningTokens,
        public int $cachedTokens,
        public ?int $costNanoCredits,
    ) {
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionUsageTest.php
```

Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Recommendation/CompletionUsage.php backend/tests/Service/Recommendation/CompletionUsageTest.php
git commit -m "feat(#409): add CompletionUsage value object"
```

---

### Task 2: Decode the provider's `usage` object

**Files:**
- Modify: `backend/src/Service/Recommendation/CompletionBodyDecoder.php`
- Test: `backend/tests/Service/Recommendation/CompletionBodyDecoderTest.php`

**Interfaces:**
- Consumes: `CompletionUsage` from Task 1.
- Produces:
  - `CompletionBodyDecoder::usage(string $json): ?CompletionUsage`
  - `CompletionBodyDecoder::streamEvent(string $payload): array` now returns
    `array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsage}`.

**Background — the wire shape.** OpenRouter's final SSE message looks like:

```
data: {"id":"gen-1","choices":[],"usage":{"prompt_tokens":118432,"completion_tokens":2216,"total_tokens":120648,"cost":0.04123,"prompt_tokens_details":{"cached_tokens":117000},"completion_tokens_details":{"reasoning_tokens":0}}}
```

`choices` is empty, which is why every existing field decodes to null today.
`cost` is a float in credits; nano-credits is `(int) round($cost * 1e9)`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Recommendation/CompletionBodyDecoderTest.php`
(keep the existing tests; match the file's existing style and namespace):

```php
    public function testReadsTheUsageObjectOfTheFinalStreamMessage(): void
    {
        $usage = $this->decoder->usage(
            '{"choices":[],"usage":{"prompt_tokens":118432,"completion_tokens":2216,'
            . '"cost":0.04123,"prompt_tokens_details":{"cached_tokens":117000},'
            . '"completion_tokens_details":{"reasoning_tokens":880}}}',
        );

        self::assertNotNull($usage);
        self::assertSame(118432, $usage->promptTokens);
        self::assertSame(2216, $usage->completionTokens);
        self::assertSame(880, $usage->reasoningTokens);
        self::assertSame(117000, $usage->cachedTokens);
        self::assertSame(41_230_000, $usage->costNanoCredits);
    }

    public function testReadsUsageWithoutACostAsUnpriced(): void
    {
        $usage = $this->decoder->usage('{"choices":[],"usage":{"prompt_tokens":40,"completion_tokens":9}}');

        self::assertNotNull($usage);
        self::assertSame(40, $usage->promptTokens);
        self::assertSame(9, $usage->completionTokens);
        self::assertSame(0, $usage->reasoningTokens);
        self::assertSame(0, $usage->cachedTokens);
        self::assertNull($usage->costNanoCredits);
    }

    public function testReadsAnIntegerCostTheSameWayAsAFloatOne(): void
    {
        $usage = $this->decoder->usage('{"usage":{"prompt_tokens":1,"completion_tokens":1,"cost":2}}');

        self::assertNotNull($usage);
        self::assertSame(2_000_000_000, $usage->costNanoCredits);
    }

    public function testHasNoUsageWhenTheProviderSentNone(): void
    {
        self::assertNull($this->decoder->usage('{"choices":[{"delta":{"content":"hi"}}]}'));
    }

    public function testHasNoUsageWhenTheMemberIsNotAnObject(): void
    {
        self::assertNull($this->decoder->usage('{"usage":"none"}'));
    }

    public function testHasNoUsageWhenThePayloadIsNotJson(): void
    {
        self::assertNull($this->decoder->usage('not json'));
    }

    public function testIgnoresNonNumericProviderFields(): void
    {
        $usage = $this->decoder->usage(
            '{"usage":{"prompt_tokens":"lots","completion_tokens":3,"cost":"free"}}',
        );

        self::assertNotNull($usage);
        self::assertSame(0, $usage->promptTokens);
        self::assertSame(3, $usage->completionTokens);
        self::assertNull($usage->costNanoCredits);
    }

    public function testStreamEventCarriesTheUsageAlongsideTheAnswerFields(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[],"usage":{"prompt_tokens":7,"completion_tokens":2}}');

        self::assertNull($event['content']);
        self::assertNull($event['finishReason']);
        self::assertNotNull($event['usage']);
        self::assertSame(7, $event['usage']->promptTokens);
    }

    public function testStreamEventHasNoUsageOnAnOrdinaryDelta(): void
    {
        $event = $this->decoder->streamEvent('{"choices":[{"delta":{"content":"hi"}}]}');

        self::assertSame('hi', $event['content']);
        self::assertNull($event['usage']);
    }
```

If the existing test file does not already hold `$this->decoder`, read the file
first and follow whatever construction idiom it uses.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionBodyDecoderTest.php
```

Expected: FAIL — `Call to undefined method ...::usage()`.

- [ ] **Step 3: Implement**

In `backend/src/Service/Recommendation/CompletionBodyDecoder.php`:

a) Replace the private `firstChoice()` with a root decode plus a choice walk, so
one `json_decode` serves both the choice fields and the root-level usage:

```php
    /**
     * The payload as an array, or null when it is not JSON at all. Decoded once
     * per payload and shared: `streamEvent()` reads the choice fields and the
     * root-level usage object off the same decode, and a second decode per
     * event is exactly the parse cost #327 removed.
     *
     * @return array<mixed>|null
     */
    private function decodeRoot(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The first choice as an array, or null when the shape is wrong. Every
     * step is guarded because the provider is untrusted — any of them can be
     * absent or the wrong type. The final usage message of a stream carries
     * `choices: []`, so null here is routine, not a fault.
     *
     * @param array<mixed>|null $root
     *
     * @return array<mixed>|null
     */
    private function firstChoiceIn(?array $root): ?array
    {
        $choices = null === $root ? null : ($root['choices'] ?? null);
        $firstChoice = \is_array($choices) ? ($choices[0] ?? null) : null;

        return \is_array($firstChoice) ? $firstChoice : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function firstChoice(string $json): ?array
    {
        return $this->firstChoiceIn($this->decodeRoot($json));
    }
```

b) Add the public reader:

```php
    /**
     * The provider's own accounting for the call, which OpenAI-compatible
     * endpoints send in the last message of a streamed reply — the message
     * whose `choices` is empty, which is why nothing here read it before
     * (#409). A blocking envelope carries the same object at its root, so one
     * reader covers both shapes.
     */
    public function usage(string $json): ?CompletionUsage
    {
        return $this->usageIn($this->decodeRoot($json));
    }
```

c) Add the private mappers. Keep them small — PHPMD's codesize ruleset gates this
file:

```php
    /**
     * @param array<mixed>|null $root
     */
    private function usageIn(?array $root): ?CompletionUsage
    {
        $usage = null === $root ? null : ($root['usage'] ?? null);

        if (!\is_array($usage)) {
            return null;
        }

        return new CompletionUsage(
            $this->intField($usage, 'prompt_tokens'),
            $this->intField($usage, 'completion_tokens'),
            $this->intField($this->detailsOf($usage, 'completion_tokens_details'), 'reasoning_tokens'),
            $this->intField($this->detailsOf($usage, 'prompt_tokens_details'), 'cached_tokens'),
            $this->nanoCreditsIn($usage),
        );
    }

    /**
     * A nested detail object of the usage report, or an empty array when the
     * provider sent none — the two detail objects are optional, and a provider
     * that omits them reports zero of what they count, not an unknown.
     *
     * @param array<mixed> $usage
     *
     * @return array<mixed>
     */
    private function detailsOf(array $usage, string $key): array
    {
        $details = $usage[$key] ?? null;

        return \is_array($details) ? $details : [];
    }

    /**
     * One counter of the usage report. Absent or non-numeric reads 0: the
     * provider is untrusted, and a token count it did not send is one it did
     * not spend as far as anything here can tell.
     *
     * @param array<mixed> $fields
     */
    private function intField(array $fields, string $key): int
    {
        $value = $fields[$key] ?? null;

        return \is_int($value) ? $value : 0;
    }

    /**
     * The price, converted from the provider's float credits to the integer
     * nano-credits everything downstream stores. Null — not zero — when the
     * provider reported no price: zero claims the call was free, which is a
     * different statement from unpriced (a local model, say).
     *
     * @param array<mixed> $usage
     */
    private function nanoCreditsIn(array $usage): ?int
    {
        $cost = $usage['cost'] ?? null;

        if (!\is_float($cost) && !\is_int($cost)) {
            return null;
        }

        return (int) round($cost * 1_000_000_000);
    }
```

d) Rewrite `streamEvent()` to decode once and carry the usage:

```php
    /**
     * Every field of one stream event from a single decode. The reader reads
     * an event's answer fragment, its finish reason and the provider's usage
     * report together, so decoding once here — rather than once per field —
     * halves the parse work over a reasoning model's thousands of thinking
     * events (#327).
     *
     * @return array{content: ?string, reasoning: ?string, finishReason: ?string, usage: ?CompletionUsage}
     */
    public function streamEvent(string $payload): array
    {
        $root = $this->decodeRoot($payload);
        $choice = $this->firstChoiceIn($root);

        return [
            'content' => $this->contentOf($choice, 'delta'),
            'reasoning' => $this->reasoningOf($choice, 'delta'),
            'finishReason' => $this->finishReasonOf($choice),
            'usage' => $this->usageIn($root),
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionBodyDecoderTest.php tests/Service/Recommendation/CompletionStreamReaderTest.php
```

Expected: PASS. `CompletionStreamReaderTest` must stay green — `streamEvent()`
only gained a key.

- [ ] **Step 5: Lint the touched file**

```bash
cd backend && composer cs && vendor/bin/phpmd src/Service/Recommendation/CompletionBodyDecoder.php text codesize
```

Expected: no findings. Fix the design if PHPMD complains; do not tune thresholds.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Recommendation/CompletionBodyDecoder.php backend/tests/Service/Recommendation/CompletionBodyDecoderTest.php
git commit -m "feat(#409): decode the provider's usage object"
```

---

### Task 3: Carry the usage to the observer

**Files:**
- Modify: `backend/src/Service/Recommendation/CompletionStreamReader.php`
- Modify: `backend/src/Service/Recommendation/CompletionStreamProgress.php`
- Modify: `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php`
- Test: `backend/tests/Service/Recommendation/CompletionStreamReaderTest.php`
- Test: `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`

**Interfaces:**
- Consumes: `CompletionUsage` (Task 1), `CompletionBodyDecoder::usage()` and the
  `usage` key of `streamEvent()` (Task 2).
- Produces:
  - `CompletionStreamReader::usage(): ?CompletionUsage`
  - `CompletionStreamProgress::__construct(string $answerSoFar, int $wireBytes, ?string $finishReason = null, ?CompletionUsage $usage = null)`
    with a public `?CompletionUsage $usage` property.
  - The request payload gains `stream_options => ['include_usage' => true]`.

- [ ] **Step 1: Write the failing reader tests**

Append to `backend/tests/Service/Recommendation/CompletionStreamReaderTest.php`,
following the file's existing construction idiom:

```php
    public function testKeepsTheUsageOfTheFinalStreamMessage(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());

        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n");
        $reader->consume(
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":12,\"completion_tokens\":3,\"cost\":0.000001}}\n\n",
        );
        $reader->consume("data: [DONE]\n\n");

        $usage = $reader->usage();
        self::assertNotNull($usage);
        self::assertSame(12, $usage->promptTokens);
        self::assertSame(1000, $usage->costNanoCredits);
    }

    public function testAnEventWithoutUsageNeverErasesTheUsageAlreadySeen(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());

        $reader->consume("data: {\"choices\":[],\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":1}}\n\n");
        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\"tail\"}}]}\n\n");

        self::assertSame(5, $reader->usage()?->promptTokens);
    }

    public function testReadsTheUsageOfABlockingEnvelope(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());

        $reader->consume(
            '{"choices":[{"message":{"content":"hi"}}],"usage":{"prompt_tokens":8,"completion_tokens":2}}',
        );

        self::assertSame(8, $reader->usage()?->promptTokens);
    }

    public function testHasNoUsageWhenTheProviderNeverSentOne(): void
    {
        $reader = new CompletionStreamReader(new CompletionBodyDecoder());

        $reader->consume("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n");

        self::assertNull($reader->usage());
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionStreamReaderTest.php
```

Expected: FAIL — `Call to undefined method ...::usage()`.

- [ ] **Step 3: Implement the reader**

In `CompletionStreamReader.php`, add the field next to `$finishReason`:

```php
    /**
     * The provider's own accounting for this call, sticky exactly as
     * $finishReason is: it arrives in one late message and every event after
     * it carries none, so a later null must never erase it (#409).
     */
    private ?CompletionUsage $usage = null;
```

Add the accessor next to `finishReason()`:

```php
    /**
     * What the provider says this call consumed, once it says so. Null until
     * a message carries it — and for a provider that reports none at all.
     *
     * Deliberately no salvage of an unterminated event left in $pendingLine,
     * the way trailingEventContent() salvages a last delta: that would mean a
     * JSON decode per chunk on a half-buffered event, which is the parse cost
     * #327 removed. Real SSE terminates its frames, and the usage message is
     * followed by `data: [DONE]`, so it is never the unterminated one.
     */
    public function usage(): ?CompletionUsage
    {
        if (!$this->sawStreamEvent) {
            return $this->decoder->usage($this->envelope . $this->pendingLine);
        }

        return $this->usage;
    }
```

And in `readEvent()`, after the `finishReason` line:

```php
        $this->usage = $event['usage'] ?? $this->usage;
```

- [ ] **Step 4: Run the reader tests**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/CompletionStreamReaderTest.php
```

Expected: PASS.

- [ ] **Step 5: Extend `CompletionStreamProgress`**

```php
/**
 * ... (keep the existing docblock, then append this paragraph)
 *
 * `usage` is the provider's own accounting for the call — tokens and price
 * (#409). It rides here rather than being threaded through the client, the
 * advancer and the wave as a new parameter: this object already travels from
 * the transport to the observer, and a value with no home is what phptramp
 * exists to catch. Null until the provider sends its usage message, and for a
 * provider that never sends one.
 */
final readonly class CompletionStreamProgress
{
    public function __construct(
        public string $answerSoFar,
        public int $wireBytes,
        public ?string $finishReason = null,
        public ?CompletionUsage $usage = null,
    ) {
    }
}
```

- [ ] **Step 6: Write the failing client tests**

Append to `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`,
following that file's existing `MockHttpClient` idiom (read it first — reuse its
helpers rather than inventing new ones):

```php
    public function testAsksTheProviderToIncludeUsageInTheStream(): void
    {
        $sentPayload = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sentPayload) {
            $sentPayload = json_decode($options['body'] ?? '{}', true);

            return new MockResponse("data: {\"choices\":[{\"delta\":{\"content\":\"{}\"}}]}\n\n");
        });

        $this->clientWith($client)->complete(
            $this->credentials(),
            $this->request(),
            new NullCompletionStreamObserver(),
        );

        self::assertSame(['include_usage' => true], $sentPayload['stream_options']);
    }

    public function testReportsTheProvidersUsageToTheObserver(): void
    {
        $client = new MockHttpClient(new MockResponse([
            "data: {\"choices\":[{\"delta\":{\"content\":\"{}\"}}]}\n\n",
            "data: {\"choices\":[],\"usage\":{\"prompt_tokens\":11,\"completion_tokens\":4,\"cost\":0.002}}\n\n",
            "data: [DONE]\n\n",
        ]));
        $observer = new RecordingObserver();

        $this->clientWith($client)->complete($this->credentials(), $this->request(), $observer);

        self::assertSame(11, $observer->lastUsage()?->promptTokens);
        self::assertSame(2_000_000, $observer->lastUsage()?->costNanoCredits);
    }
```

If the test file has no observer that records progress, add one as a private
fixture class at the bottom of the file:

```php
final class RecordingObserver implements CompletionStreamObserver
{
    private ?CompletionUsage $usage = null;

    public function streamProgressed(CompletionStreamProgress $progress): void
    {
        $this->usage = $progress->usage ?? $this->usage;
    }

    public function lastUsage(): ?CompletionUsage
    {
        return $this->usage;
    }
}
```

Adapt `clientWith()`, `credentials()` and `request()` to whatever the file
already provides.

- [ ] **Step 7: Run to verify they fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php
```

Expected: FAIL on the missing `stream_options` key and on a null usage.

- [ ] **Step 8: Implement the client changes**

In `OpenAiCompatibleChatClient::completionPayload()`, next to `'stream' => true`:

```php
            'stream' => true,
            // Ask for the usage message the stream would otherwise not carry
            // (#409). Unconditional because this is OpenAI spec, not a vendor
            // extension — unlike `reasoning` below, which is per-connection
            // for exactly that reason. OpenRouter documents it as inert; a
            // plain OpenAI-compatible endpoint sends no usage without it.
            'stream_options' => ['include_usage' => true],
```

In `consumeChunk()`, extend the progress construction:

```php
            $observer->streamProgressed(new CompletionStreamProgress(
                $reader->assistantContent() ?? '',
                $reader->wireBytes(),
                $reader->finishReason(),
                $reader->usage(),
            ));
```

- [ ] **Step 9: Run the whole recommendation suite**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation
```

Expected: PASS.

- [ ] **Step 10: Lint and commit**

```bash
cd backend && composer cs && vendor/bin/phpmd src/Service/Recommendation/CompletionStreamReader.php,src/Service/Recommendation/CompletionStreamProgress.php,src/Service/Recommendation/OpenAiCompatibleChatClient.php text codesize
```

```bash
git add backend/src/Service/Recommendation backend/tests/Service/Recommendation
git commit -m "feat(#409): request and carry the provider's usage report"
```

---

### Task 4: Run columns and the migration

**Files:**
- Modify: `backend/src/Entity/RecommendationRun.php`
- Create: `backend/migrations/Version20260816120000.php`
- Test: `backend/tests/Entity/RecommendationRunTest.php` (create if absent)

**Interfaces:**
- Consumes: nothing.
- Produces on `RecommendationRun`:
  - `stampProvider(?string $providerHost, ?string $model): void`
  - `getProviderHost(): ?string`, `getModel(): ?string`
  - `getPromptTokens(): int`, `getCompletionTokens(): int`,
    `getReasoningTokens(): int`, `getCachedTokens(): int`,
    `getCostNanoCredits(): ?int`
  - Columns: `provider_host`, `model`, `prompt_tokens`, `completion_tokens`,
    `reasoning_tokens`, `cached_tokens`, `cost_nano_credits`.

- [ ] **Step 1: Write the failing entity test**

`backend/tests/Entity/RecommendationRunTest.php` — if the file exists, append the
two tests; if not, create it:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationRun;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecommendationRun::class)]
final class RecommendationRunTest extends TestCase
{
    public function testStartsWithNoProviderAndNoSpend(): void
    {
        $run = new RecommendationRun(new User(), new \DateTimeImmutable('2026-08-16 09:00:00'));

        self::assertNull($run->getProviderHost());
        self::assertNull($run->getModel());
        self::assertSame(0, $run->getPromptTokens());
        self::assertSame(0, $run->getCompletionTokens());
        self::assertSame(0, $run->getReasoningTokens());
        self::assertSame(0, $run->getCachedTokens());
        self::assertNull($run->getCostNanoCredits());
    }

    public function testStampsTheProviderItWillCall(): void
    {
        $run = new RecommendationRun(new User(), new \DateTimeImmutable('2026-08-16 09:00:00'));

        $run->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');

        self::assertSame('openrouter.ai', $run->getProviderHost());
        self::assertSame('x-ai/grok-4-fast', $run->getModel());
    }

    public function testRestampsWhenTheConfigurationChangedBeforeAResume(): void
    {
        $run = new RecommendationRun(new User(), new \DateTimeImmutable('2026-08-16 09:00:00'));

        $run->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $run->stampProvider('localhost', 'bonsai-27b');

        self::assertSame('localhost', $run->getProviderHost());
        self::assertSame('bonsai-27b', $run->getModel());
    }
}
```

If `new User()` needs constructor arguments, read `backend/src/Entity/User.php`
and pass whatever it requires.

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit tests/Entity/RecommendationRunTest.php
```

Expected: FAIL — undefined method `getProviderHost()`.

- [ ] **Step 3: Add the columns and accessors**

In `backend/src/Entity/RecommendationRun.php`, after `$streamedChars`:

```php
    /**
     * The provider this run actually called, copied onto the run at start
     * rather than read back through the account's configuration (#409). The
     * configuration is editable, and a history that renames last month's runs
     * when the model changes is not a history. Null on runs that predate this
     * column, and on one that failed before it was ever stamped.
     *
     * The host only, not the whole base URL: the host is what identifies the
     * provider, and a path adds nothing a history row can use.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerHost = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    /**
     * The provider's own token accounting for this run, summed over every call
     * it made — retries and the discarded siblings of an aborted wave
     * included, because the provider billed those too (#344, #409).
     *
     * Written by RecordedCall through DBAL with SQL arithmetic, never through
     * this entity: concurrent calls of one wave would otherwise lose each
     * other's increments, and the advancer's EntityManager must not be flushed
     * mid-tick. This entity only ever reads them, which is why there is no
     * setter — a second writer is exactly the race the SQL avoids.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $promptTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $completionTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $reasoningTokens = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $cachedTokens = 0;

    /**
     * What this run cost, in nano-credits. Money, so an integer and never a
     * float. BIGINT because credits × 1e9 outgrows INT at 2.1 credits, and it
     * hydrates as a PHP int because DBAL 4's BigIntType returns one for every
     * value inside PHP's integer range — which nano-credits never leave.
     *
     * Null means no call of this run reported a price: a local model, or a
     * run that predates this column. Deliberately not 0, which would claim the
     * run was free.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $costNanoCredits = null;
```

And the accessors, next to `getStreamedChars()`:

```php
    /**
     * Records which provider and model this run is about to use. Called at
     * start and again at resume, so a run resumed after the account switched
     * models is stamped with the model it will actually call.
     */
    public function stampProvider(?string $providerHost, ?string $model): void
    {
        $this->providerHost = $providerHost;
        $this->model = $model;
    }

    public function getProviderHost(): ?string
    {
        return $this->providerHost;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getReasoningTokens(): int
    {
        return $this->reasoningTokens;
    }

    public function getCachedTokens(): int
    {
        return $this->cachedTokens;
    }

    public function getCostNanoCredits(): ?int
    {
        return $this->costNanoCredits;
    }
```

`Types` is already imported in this file.

- [ ] **Step 4: Run to verify it passes**

```bash
cd backend && php bin/phpunit tests/Entity/RecommendationRunTest.php
```

Expected: PASS.

- [ ] **Step 5: Write the migration**

`backend/migrations/Version20260816120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * recommendation_run gains the provider it called and what that call cost
 * (#409): provider_host and model stamped at start, the four token counters
 * and the price banked by RecordedCall as each call settles.
 *
 * cost_nano_credits is BIGINT and nullable. Nullable because null means the
 * provider reported no price at all — a local model, or any run that predates
 * this migration — and 0 would claim those runs were free. BIGINT because a
 * credit is 1e9 nano-credits, so INT would overflow at 2.1 credits.
 *
 * PLATFORM-AWARE DDL for the reason Version20260814140000 records: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain. SQLite
 * takes one ADD COLUMN per statement; MySQL takes them in one ALTER.
 */
final class Version20260816120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recommendation_run provider, token and cost columns (#409)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $run = $schema->getTable('recommendation_run');

        if ($run->hasColumn('provider_host')) {
            return;
        }

        if ($mysql) {
            $this->addSql(
                'ALTER TABLE recommendation_run '
                . 'ADD provider_host VARCHAR(255) DEFAULT NULL, '
                . 'ADD model VARCHAR(255) DEFAULT NULL, '
                . 'ADD prompt_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD completion_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD reasoning_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD cached_tokens INT DEFAULT 0 NOT NULL, '
                . 'ADD cost_nano_credits BIGINT DEFAULT NULL',
            );

            return;
        }

        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN provider_host VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN prompt_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN completion_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN reasoning_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN cached_tokens INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE recommendation_run ADD COLUMN cost_nano_credits BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $run = $schema->getTable('recommendation_run');

        if (!$run->hasColumn('provider_host')) {
            return;
        }

        $columns = [
            'provider_host',
            'model',
            'prompt_tokens',
            'completion_tokens',
            'reasoning_tokens',
            'cached_tokens',
            'cost_nano_credits',
        ];

        foreach ($columns as $column) {
            $this->addSql($mysql
                ? \sprintf('ALTER TABLE recommendation_run DROP %s', $column)
                : \sprintf('ALTER TABLE recommendation_run DROP COLUMN %s', $column));
        }
    }
}
```

- [ ] **Step 6: Verify the migration on a scratch database — never the dev one**

```bash
cd backend && APP_ENV=dev DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch409.db" php bin/console doctrine:migrations:migrate --no-interaction
```

Then:

```bash
cd backend && APP_ENV=dev DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch409.db" php bin/console doctrine:schema:validate
```

Expected: migration applies clean from empty and the mapping validates. Then
remove the scratch file:

```bash
rm -f backend/var/scratch409.db
```

**Do not run migrations against the real dev database and do not clear it.**

- [ ] **Step 7: Apply the migration to the running Docker MySQL**

The php-fpm container shares the code volume, so a mid-branch column that is not
in the live database 500s every request that touches it.

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 8: Run the suite and commit**

```bash
cd backend && php bin/phpunit && composer cs && vendor/bin/phpmd src/Entity/RecommendationRun.php text codesize
```

```bash
git add backend/src/Entity/RecommendationRun.php backend/migrations/Version20260816120000.php backend/tests/Entity/RecommendationRunTest.php
git commit -m "feat(#409): add provider, token and cost columns to recommendation_run"
```

---

### Task 5: `RecordedCall` banks the usage at settle

**Files:**
- Modify: `backend/src/Service/Recommendation/RecordedCall.php`
- Test: `backend/tests/Service/Recommendation/RecordedCallTest.php`

**Interfaces:**
- Consumes: `CompletionUsage` (Task 1), `CompletionStreamProgress::$usage`
  (Task 3), the `recommendation_run` columns (Task 4).
- Produces: no new public methods. `streamProgressed()`, `finish()` and
  `abortAfterTransportFailure()` gain the banking behaviour.

**Read first.** `RecordedCall::finish()` and `abortAfterTransportFailure()` both
`return` early when `$logId === null` (debug off). Banking must happen **before**
that guard, or the totals would only exist with debug on — which is the exact
defect this issue exists to fix.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Service/Recommendation/RecordedCallTest.php`, following
the file's existing construction idiom (read it first — it already builds a
`Connection` test double or an in-memory DBAL connection; reuse that):

```php
    public function testBanksTheProvidersUsageOntoTheRunWhenTheCallSettles(): void
    {
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop', new CompletionUsage(
            promptTokens: 1200,
            completionTokens: 340,
            reasoningTokens: 90,
            cachedTokens: 1100,
            costNanoCredits: 41_230_000,
        )));
        $call->settle('{}', true);

        self::assertSame([
            'promptTokens' => 1200,
            'completionTokens' => 340,
            'reasoningTokens' => 90,
            'cachedTokens' => 1100,
            'costNanoCredits' => 41_230_000,
        ], $this->runTotals());
    }

    public function testBanksTheUsageWithTheDebugSwitchOff(): void
    {
        $call = $this->recordedCall(logId: null);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop', new CompletionUsage(
            promptTokens: 10,
            completionTokens: 2,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: 5000,
        )));
        $call->settle('{}', true);

        self::assertSame(10, $this->runTotals()['promptTokens']);
        self::assertSame(5000, $this->runTotals()['costNanoCredits']);
    }

    public function testBanksTheUsageOfACallThatFailedInTransport(): void
    {
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('', 100, null, new CompletionUsage(
            promptTokens: 900,
            completionTokens: 0,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: 2000,
        )));
        $call->abortAfterTransportFailure('That address did not answer.');

        self::assertSame(900, $this->runTotals()['promptTokens']);
    }

    public function testLeavesTheCostNullWhenTheProviderReportedNone(): void
    {
        $call = $this->recordedCall(logId: null);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop', new CompletionUsage(
            promptTokens: 40,
            completionTokens: 9,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: null,
        )));
        $call->settle('{}', true);

        self::assertSame(40, $this->runTotals()['promptTokens']);
        self::assertNull($this->runTotals()['costNanoCredits']);
    }

    public function testBanksOneCallOnceHoweverManySettlePathsReachIt(): void
    {
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('', 100, null, new CompletionUsage(
            promptTokens: 900,
            completionTokens: 0,
            reasoningTokens: 0,
            cachedTokens: 0,
            costNanoCredits: 2000,
        )));
        $call->abortAfterTransportFailure('That address did not answer.');
        $call->abortAfterTransportFailure('That address did not answer.');

        self::assertSame(900, $this->runTotals()['promptTokens']);
        self::assertSame(2000, $this->runTotals()['costNanoCredits']);
    }

    public function testBanksNothingWhenTheProviderSentNoUsageAtAll(): void
    {
        $call = $this->recordedCall(logId: 7);

        $call->streamProgressed(new CompletionStreamProgress('{}', 100, 'stop'));
        $call->settle('{}', true);

        self::assertSame(0, $this->runTotals()['promptTokens']);
        self::assertNull($this->runTotals()['costNanoCredits']);
    }
```

Add the two helpers the tests need. `recordedCall()` builds the subject with the
existing idiom; `runTotals()` reads the five columns back off the same
connection. If the existing test uses a mocked `Connection`, switch the new tests
to a real in-memory SQLite DBAL connection with a `recommendation_run` table
created by hand — SQL arithmetic cannot be asserted against a mock, and asserting
the SQL string instead of its effect is the kind of test that passes while the
feature is broken. Example helper shape:

```php
    private function runTotals(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT prompt_tokens, completion_tokens, reasoning_tokens, cached_tokens, cost_nano_credits'
            . ' FROM recommendation_run WHERE id = 1',
        );

        return [
            'promptTokens' => (int) $row['prompt_tokens'],
            'completionTokens' => (int) $row['completion_tokens'],
            'reasoningTokens' => (int) $row['reasoning_tokens'],
            'cachedTokens' => (int) $row['cached_tokens'],
            'costNanoCredits' => null === $row['cost_nano_credits'] ? null : (int) $row['cost_nano_credits'],
        ];
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/RecordedCallTest.php
```

Expected: FAIL — totals stay 0.

- [ ] **Step 3: Implement**

In `RecordedCall.php`, add the state next to `$finishReason`:

```php
    /**
     * The provider's own accounting for this call, held like $finishReason
     * and banked when the call settles (#409). Sticky: it arrives in one late
     * message, so a later report without it must not erase it.
     */
    private ?CompletionUsage $usage = null;

    /**
     * One provider call is billed once. Every settle path — a verdict, a
     * transport abort, a wave that aborts a call the round already settled —
     * runs through bankUsage(), and without this flag a call reachable by two
     * of them would double its own spend. Per-instance on purpose: a retry and
     * the discarded sibling of an aborted wave are separate RecordedCalls, and
     * the provider billed each of them (#344).
     */
    private bool $usageBanked = false;
```

In `streamProgressed()`, next to the `finishReason` line:

```php
        $this->usage = $progress->usage ?? $this->usage;
```

In `finish()` and in `abortAfterTransportFailure()`, immediately after
`$this->resetLiveness();` and **before** the `$logId` guard:

```php
        $this->bankUsage();
```

And the private methods:

```php
    /**
     * Adds this call's consumption to the run's own totals — with SQL
     * arithmetic, not read-modify-write: a #344 wave settles several calls
     * against one run, and two PHP-side increments would silently lose one of
     * them. Through DBAL rather than the EntityManager for the reason every
     * write in this class is: the advancer holds other work dirty mid-tick,
     * and flushing it here would commit that too.
     *
     * Runs before the debug guard in both callers on purpose. The RecordedCall
     * exists whether or not the debug switch is on, and a spending record that
     * only exists with debug on is the defect #409 was filed about.
     */
    private function bankUsage(): void
    {
        $usage = $this->usage;

        if (null === $usage || $this->usageBanked) {
            return;
        }
        $this->usageBanked = true;

        $this->connection->executeStatement(
            'UPDATE recommendation_run SET'
            . ' prompt_tokens = prompt_tokens + :promptTokens,'
            . ' completion_tokens = completion_tokens + :completionTokens,'
            . ' reasoning_tokens = reasoning_tokens + :reasoningTokens,'
            . ' cached_tokens = cached_tokens + :cachedTokens'
            . ' WHERE id = :runId',
            [
                'promptTokens' => $usage->promptTokens,
                'completionTokens' => $usage->completionTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedTokens' => $usage->cachedTokens,
                'runId' => $this->runId,
            ],
        );

        $this->bankCost($usage->costNanoCredits);
    }

    /**
     * The price, kept out of the token statement so an unpriced call leaves
     * the column NULL rather than coercing it to 0 — null means "no provider
     * reported a price", and 0 would claim the run was free. COALESCE is what
     * makes the first priced call of a run initialise the column.
     */
    private function bankCost(?int $costNanoCredits): void
    {
        if (null === $costNanoCredits) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE recommendation_run'
            . ' SET cost_nano_credits = COALESCE(cost_nano_credits, 0) + :costNanoCredits'
            . ' WHERE id = :runId',
            ['costNanoCredits' => $costNanoCredits, 'runId' => $this->runId],
        );
    }
```

- [ ] **Step 4: Run to verify they pass**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/RecordedCallTest.php
```

Expected: PASS.

- [ ] **Step 5: Verify no other settle path double-banks**

Read `backend/src/Service/Recommendation/RecommendationBatchWave.php` around
`completeRound()` and `guardWaveTransport()` and confirm the `$usageBanked` flag
covers every path that can reach one `RecordedCall` twice. Record what you found
in the commit message body. If a path settles a call that was *never* opened, say
so — that is a separate defect, not this task's.

- [ ] **Step 6: Lint and commit**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation && composer cs && vendor/bin/phpmd src/Service/Recommendation/RecordedCall.php text codesize
```

```bash
git add backend/src/Service/Recommendation/RecordedCall.php backend/tests/Service/Recommendation/RecordedCallTest.php
git commit -m "feat(#409): bank each call's usage onto the run"
```

---

### Task 6: Stamp the provider and model on the run

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunStarter.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunStarterTest.php`

**Interfaces:**
- Consumes: `RecommendationRun::stampProvider()` (Task 4).
- Produces: no new public methods. `start()` stamps before the first flush;
  `resume()` re-stamps before its flush.

The starter already holds `AiProviderConfigurator` and already calls
`$this->configurator->settingsFor($user)` for its readiness guard, so the
`AiProviderSettings` row is in hand. `getBaseUrl()` gives the URL and
`getModel()` the model id.

- [ ] **Step 1: Write the failing tests**

Append to the existing starter test (read it first and reuse its fixtures — it
already builds a `User` with an active `AiProviderSettings`, or mocks the
configurator):

```php
    public function testStampsTheProviderAndModelOnANewRun(): void
    {
        $report = $this->starter->start($this->userWithProvider('https://openrouter.ai/api/v1', 'x-ai/grok-4-fast'));

        $run = $this->runs->findActiveForUser($this->user);
        self::assertNotNull($run);
        self::assertSame('openrouter.ai', $run->getProviderHost());
        self::assertSame('x-ai/grok-4-fast', $run->getModel());
        self::assertSame(RecommendationRun::STATUS_PENDING, $report->status);
    }

    public function testRestampsAResumedRunWithTheProviderItWillNowCall(): void
    {
        $user = $this->userWithProvider('https://openrouter.ai/api/v1', 'x-ai/grok-4-fast');
        $failed = $this->failedRunFor($user);
        $failed->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $this->switchProvider($user, 'http://localhost:1234/v1', 'bonsai-27b');

        $this->starter->resume($user);

        self::assertSame('localhost', $failed->getProviderHost());
        self::assertSame('bonsai-27b', $failed->getModel());
    }

    public function testStampsNoHostWhenTheBaseUrlHasNone(): void
    {
        $this->starter->start($this->userWithProvider('not a url', 'some-model'));

        self::assertNull($this->runs->findActiveForUser($this->user)?->getProviderHost());
    }
```

Adapt the helper names to whatever the existing test file provides; if it has no
integration harness, write the tests against the same doubles it already uses.

- [ ] **Step 2: Run to verify they fail**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationRunStarterTest.php
```

Expected: FAIL — `getProviderHost()` returns null.

- [ ] **Step 3: Implement**

In `RecommendationRunStarter.php`, in `start()`, between constructing the run and
persisting it:

```php
        $run = new RecommendationRun($user, $this->clock->now());
        $this->stampProvider($run, $user);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
```

In `resume()`, before the flush:

```php
        $latest->resume();
        // Re-stamped, not left as it was: an account that switched model
        // between the failure and the resume calls the new one, and a history
        // row naming the old one would be a lie about what was billed (#409).
        $this->stampProvider($latest, $user);
        $this->entityManager->flush();
```

And the private helper:

```php
    /**
     * Copies the provider this run is about to call onto the run itself
     * (#409). Copied rather than read back through the account later: the
     * configuration is editable, and a history that renames last month's runs
     * when the model changes is not a history.
     *
     * The host only. A base URL the account saved but that has no host at all
     * stamps null rather than a fragment of one — a history row is worth less
     * with a wrong provider in it than with an empty one.
     */
    private function stampProvider(RecommendationRun $run, User $user): void
    {
        $settings = $this->configurator->settingsFor($user);

        $run->stampProvider(
            parse_url($settings?->getBaseUrl() ?? '', \PHP_URL_HOST) ?: null,
            $settings?->getModel(),
        );
    }
```

`parse_url()` returns `false` on a malformed URL and `null` when there is no host
component; `?:` collapses both to null. Both callers have already passed the
`AiSettingsJson::isReady()` guard, so `$settings` is in practice never null — the
null-safe access is there because the type says it can be, not because the path
is expected.

- [ ] **Step 4: Run to verify they pass**

```bash
cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationRunStarterTest.php
```

Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
cd backend && composer cs && vendor/bin/phpmd src/Service/Recommendation/RecommendationRunStarter.php text codesize && composer tramp
```

```bash
git add backend/src/Service/Recommendation/RecommendationRunStarter.php backend/tests/Service/Recommendation/RecommendationRunStarterTest.php
git commit -m "feat(#409): stamp the provider and model on every run"
```

---

### Task 7: The history query and endpoint

**Files:**
- Modify: `backend/src/Repository/RecommendationRunRepository.php`
- Create: `backend/src/Http/RecommendationRunHistoryJson.php`
- Create: `backend/src/Controller/Api/RecommendationRunHistoryController.php`
- Test: `backend/tests/Http/RecommendationRunHistoryJsonTest.php`
- Test: `backend/tests/Controller/Api/RecommendationRunHistoryControllerTest.php`

**Interfaces:**
- Consumes: the run columns (Task 4).
- Produces:
  - `RecommendationRunRepository::HISTORY_LIMIT = 50`
  - `RecommendationRunRepository::historyForUser(User $user): array` returning
    `list<RecommendationRun>`, newest first, capped at `HISTORY_LIMIT`.
  - `RecommendationRunRepository::totalCostNanoCredits(User $user): ?int`
  - `RecommendationRunHistoryJson::payload(array $runs, ?int $totalCostNanoCredits): array`
  - Route `GET /api/recommendations/runs/history`, name
    `api_recommendations_run_history`.

**Route ordering.** `RecommendationRunController` declares
`#[Route('/api/recommendations/runs')]` at class level with an action on `''`.
A separate controller with the class-level prefix
`/api/recommendations/runs/history` cannot collide with it, which is why this is
its own controller rather than a seventh action on that one — and it also keeps
`RecommendationRunController` from growing another responsibility.

- [ ] **Step 1: Write the failing mapper test**

`backend/tests/Http/RecommendationRunHistoryJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecommendationRunHistoryJson::class)]
final class RecommendationRunHistoryJsonTest extends TestCase
{
    public function testRendersOneRowPerRunWithItsDuration(): void
    {
        $run = $this->completedRun();

        $payload = RecommendationRunHistoryJson::payload([$run], 918_200_000);

        self::assertSame(918_200_000, $payload['totalCostNanoCredits']);
        self::assertCount(1, $payload['runs']);
        self::assertSame('completed', $payload['runs'][0]['status']);
        self::assertSame('openrouter.ai', $payload['runs'][0]['providerHost']);
        self::assertSame('x-ai/grok-4-fast', $payload['runs'][0]['model']);
        self::assertSame(47, $payload['runs'][0]['durationSeconds']);
        self::assertSame('2026-08-16T09:12:00+00:00', $payload['runs'][0]['createdAt']);
        self::assertSame('2026-08-16T09:12:47+00:00', $payload['runs'][0]['completedAt']);
    }

    public function testAnUnfinishedRunHasNoDuration(): void
    {
        $run = new RecommendationRun(new User(), new \DateTimeImmutable('2026-08-16 09:12:00'));

        $payload = RecommendationRunHistoryJson::payload([$run], null);

        self::assertNull($payload['runs'][0]['durationSeconds']);
        self::assertNull($payload['runs'][0]['completedAt']);
        self::assertNull($payload['totalCostNanoCredits']);
    }

    public function testCarriesEveryTokenCounter(): void
    {
        $payload = RecommendationRunHistoryJson::payload([$this->completedRun()], null);

        self::assertSame(0, $payload['runs'][0]['promptTokens']);
        self::assertSame(0, $payload['runs'][0]['completionTokens']);
        self::assertSame(0, $payload['runs'][0]['reasoningTokens']);
        self::assertSame(0, $payload['runs'][0]['cachedTokens']);
        self::assertNull($payload['runs'][0]['costNanoCredits']);
    }

    private function completedRun(): RecommendationRun
    {
        $run = new RecommendationRun(new User(), new \DateTimeImmutable('2026-08-16 09:12:00'));
        $run->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $run->snapshot([[1]]);
        $run->recordBatchWinners([]);
        $run->complete(new \DateTimeImmutable('2026-08-16 09:12:47'));

        return $run;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd backend && php bin/phpunit tests/Http/RecommendationRunHistoryJsonTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the mapper**

`backend/src/Http/RecommendationRunHistoryJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationRun;

/**
 * The wire shape of the run history (#409): one row per run and the account's
 * all-time cost total.
 *
 * `durationSeconds` is computed here rather than left to the client, the rule
 * RecommendationRunStatusJson already follows — the client never subtracts
 * timestamps across machines.
 *
 * `status` goes out as the raw wire vocabulary, untranslated, the same
 * convention the #309 debug log records.
 */
final class RecommendationRunHistoryJson
{
    /**
     * @param list<RecommendationRun> $runs
     *
     * @return array{runs: list<array<string, mixed>>, totalCostNanoCredits: ?int}
     */
    public static function payload(array $runs, ?int $totalCostNanoCredits): array
    {
        return [
            'runs' => array_map(self::row(...), $runs),
            // The account's whole spend, not the sum of the page above it. A
            // total that silently means "of the last fifty" is a wrong number,
            // not a cheaper one.
            'totalCostNanoCredits' => $totalCostNanoCredits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(RecommendationRun $run): array
    {
        return [
            'id' => $run->getId(),
            'status' => $run->getStatus(),
            'providerHost' => $run->getProviderHost(),
            'model' => $run->getModel(),
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'completedAt' => $run->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'durationSeconds' => self::durationSeconds($run),
            'promptTokens' => $run->getPromptTokens(),
            'completionTokens' => $run->getCompletionTokens(),
            'reasoningTokens' => $run->getReasoningTokens(),
            'cachedTokens' => $run->getCachedTokens(),
            'costNanoCredits' => $run->getCostNanoCredits(),
        ];
    }

    /**
     * How long the run took, or null while it has not finished. Clamped at 0
     * so a clock skew can never surface as a negative duration.
     */
    private static function durationSeconds(RecommendationRun $run): ?int
    {
        $completedAt = $run->getCompletedAt();

        if (null === $completedAt) {
            return null;
        }

        return max(0, $completedAt->getTimestamp() - $run->getCreatedAt()->getTimestamp());
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
cd backend && php bin/phpunit tests/Http/RecommendationRunHistoryJsonTest.php
```

Expected: PASS.

- [ ] **Step 5: Add the repository queries**

In `backend/src/Repository/RecommendationRunRepository.php`:

```php
    /**
     * How many runs the history endpoint answers with. The list is a
     * spending record a human reads, not a dataset — fifty rows is more than
     * anyone scrolls, and the total below is computed over every run anyway,
     * so this cap never makes the number on screen wrong.
     */
    public const int HISTORY_LIMIT = 50;

    /**
     * @return list<RecommendationRun> newest first, capped at HISTORY_LIMIT
     */
    public function historyForUser(User $user): array
    {
        /** @var list<RecommendationRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(self::HISTORY_LIMIT)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * The account's whole spend, summed in the database over every run it
     * ever made — deliberately not over the page historyForUser() returns. An
     * account whose runs all went unpriced sums to null, which is the honest
     * answer: nothing reported a price, as opposed to everything reporting
     * zero.
     */
    public function totalCostNanoCredits(User $user): ?int
    {
        $total = $this->createQueryBuilder('r')
            ->select('SUM(r.costNanoCredits)')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? null : (int) $total;
    }
```

- [ ] **Step 6: Write the failing functional test**

`backend/tests/Controller/Api/RecommendationRunHistoryControllerTest.php` —
read a sibling functional test under `backend/tests/Controller/Api/` first and
copy its harness exactly (client boot, user fixture, JWT/bearer login helper).
The assertions:

```php
    public function testAnswersWithTheAccountsRunsNewestFirstAndTheAllTimeTotal(): void
    {
        // Two completed runs of the same account, priced 1000 and 2000
        // nano-credits, plus one run of a different account that must not
        // appear or be counted.
        ...

        $client->request('GET', '/api/recommendations/runs/history');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(3000, $payload['totalCostNanoCredits']);
        self::assertCount(2, $payload['runs']);
        self::assertSame('openrouter.ai', $payload['runs'][0]['providerHost']);
    }

    public function testAnAccountThatNeverRanGetsAnEmptyHistoryAndNoTotal(): void
    {
        $client->request('GET', '/api/recommendations/runs/history');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $payload['runs']);
        self::assertNull($payload['totalCostNanoCredits']);
    }

    public function testRefusesAnAnonymousRequest(): void
    {
        $client->request('GET', '/api/recommendations/runs/history');

        self::assertResponseStatusCodeSame(401);
    }
```

Fill the fixtures in with whatever the sibling test's helpers provide. **The test
must own the data it asserts on** — never read whatever the seeded account
happens to hold.

- [ ] **Step 7: Run to verify it fails**

```bash
cd backend && php bin/phpunit tests/Controller/Api/RecommendationRunHistoryControllerTest.php
```

Expected: FAIL — 404, no such route.

- [ ] **Step 8: Write the controller**

`backend/src/Controller/Api/RecommendationRunHistoryController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use App\Repository\RecommendationRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What every for-you run has cost this account (#409): the newest runs with
 * their provider, duration and price, and the all-time total above them.
 *
 * Read-only and cheap — two indexed queries scoped to the current user — so
 * it carries no rate limiter, the same call the #309 debug log endpoint makes.
 * Ownership is enforced in the repository: every query filters on the
 * authenticated user, and there is no id in the route to forge.
 *
 * Its own controller rather than a seventh action on RecommendationRunController:
 * that class is about driving a run, and reading a spending record is not that.
 */
#[Route('/api/recommendations/runs/history')]
final class RecommendationRunHistoryController extends AbstractController
{
    public function __construct(private readonly RecommendationRunRepository $runs)
    {
    }

    #[Route('', name: 'api_recommendations_run_history', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationRunHistoryJson::payload(
            $this->runs->historyForUser($user),
            $this->runs->totalCostNanoCredits($user),
        ));
    }
}
```

Match the base class and the `final readonly` shape of
`RecommendationDebugLogController` — read it and follow it exactly rather than
this sketch where they differ.

- [ ] **Step 9: Run to verify it passes**

```bash
cd backend && php bin/phpunit tests/Controller/Api/RecommendationRunHistoryControllerTest.php tests/Http/RecommendationRunHistoryJsonTest.php
```

Expected: PASS.

- [ ] **Step 10: Gates and commit**

```bash
cd backend && bin/console cache:warmup && composer check && vendor/bin/phpmd src/Http/RecommendationRunHistoryJson.php,src/Controller/Api/RecommendationRunHistoryController.php,src/Repository/RecommendationRunRepository.php text codesize
```

Expected: clean. `composer stan` includes `ThinControllerRule` — the controller
has no private methods at all, so it passes by construction.

```bash
git add backend/src/Controller/Api/RecommendationRunHistoryController.php backend/src/Http/RecommendationRunHistoryJson.php backend/src/Repository/RecommendationRunRepository.php backend/tests/Http/RecommendationRunHistoryJsonTest.php backend/tests/Controller/Api/RecommendationRunHistoryControllerTest.php
git commit -m "feat(#409): serve the run history with an all-time cost total"
```

---

### Task 8: The frontend run-history card

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Create: `frontend/src/app/settings/recommendation-run-history.component.ts`
- Create: `frontend/src/app/settings/recommendation-run-history.component.html`
- Create: `frontend/src/app/settings/recommendation-run-history.component.scss`
- Create: `frontend/src/app/settings/recommendation-run-history.component.spec.ts`
- Modify: `frontend/src/app/settings/ai-section.component.ts`
- Modify: `frontend/src/app/settings/ai-section.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `GET /api/recommendations/runs/history` (Task 7).
- Produces: `RunHistoryRow`, `RunHistoryPayload`, `ReaderApi.runHistory()`,
  `<app-recommendation-run-history />`.

- [ ] **Step 1: Add the models**

Append to `frontend/src/app/reader/models.ts`:

```ts
/** One finished (or in-flight) for-you run, as the history card shows it. The
 *  provider and model are the ones the run actually called, copied onto the run
 *  when it started -- not the account's current configuration, which is
 *  editable and would otherwise rename last month's runs. */
export interface RunHistoryRow {
  id: number;
  status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
  /** Null on runs that predate the column, and on one that failed before it
   *  was ever stamped. */
  providerHost: string | null;
  model: string | null;
  createdAt: string;
  completedAt: string | null;
  /** Computed server-side -- the client never subtracts timestamps across
   *  machines. Null while the run has not finished. */
  durationSeconds: number | null;
  promptTokens: number;
  completionTokens: number;
  reasoningTokens: number;
  cachedTokens: number;
  /** What the run cost, in nano-credits (1 credit = 1e9). Null means no call
   *  of the run reported a price -- a local model, say -- which is a different
   *  statement from a cost of zero. */
  costNanoCredits: number | null;
}

/** What the history route answers with: the newest runs and the account's
 *  all-time total. The total is a database sum over every run, not over the
 *  rows above it. */
export interface RunHistoryPayload {
  runs: RunHistoryRow[];
  totalCostNanoCredits: number | null;
}
```

- [ ] **Step 2: Add the API call**

In `frontend/src/app/reader/reader-api.ts`, after `debugLogEntry()`, and add
`RunHistoryPayload` to the `models` import:

```ts
  /** Every for-you run this account has made, newest first, with what each one
   *  cost -- plus the all-time total. Independent of the debug switch: the run
   *  totals are banked whether or not the call log is being kept. */
  runHistory(): Observable<RunHistoryPayload> {
    return this.http.get<RunHistoryPayload>(`${this.base}/api/recommendations/runs/history`);
  }
```

- [ ] **Step 3: Write the failing spec**

`frontend/src/app/settings/recommendation-run-history.component.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { of } from 'rxjs';
import { RecommendationRunHistoryComponent } from './recommendation-run-history.component';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';
import { RunHistoryPayload, RunHistoryRow } from '../reader/models';
import { provideTranslocoTesting } from '../../testing/transloco-testing';

const PRICED_RUN: RunHistoryRow = {
  id: 42,
  status: 'completed',
  providerHost: 'openrouter.ai',
  model: 'x-ai/grok-4-fast',
  createdAt: '2026-08-16T09:12:00+00:00',
  completedAt: '2026-08-16T09:12:47+00:00',
  durationSeconds: 47,
  promptTokens: 118432,
  completionTokens: 2216,
  reasoningTokens: 0,
  cachedTokens: 0,
  costNanoCredits: 41_230_000,
};

const UNPRICED_RUN: RunHistoryRow = {
  ...PRICED_RUN,
  id: 41,
  providerHost: 'localhost',
  model: 'bonsai-27b',
  costNanoCredits: null,
};

describe('RecommendationRunHistoryComponent', () => {
  let runHistory: jest.Mock;
  let completedStamp: ReturnType<typeof signal<number>>;

  function mount(payload: RunHistoryPayload) {
    runHistory.mockReturnValue(of(payload));
    const fixture = TestBed.createComponent(RecommendationRunHistoryComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    runHistory = jest.fn().mockReturnValue(of({ runs: [], totalCostNanoCredits: null }));
    completedStamp = signal(0);

    TestBed.configureTestingModule({
      imports: [RecommendationRunHistoryComponent, provideTranslocoTesting()],
      providers: [
        { provide: ReaderApi, useValue: { runHistory } },
        { provide: RecommendationsService, useValue: { completedStamp } },
      ],
    });
  });

  it('renders nothing until the account has run at least once', () => {
    const el = mount({ runs: [], totalCostNanoCredits: null });

    expect(el.querySelector('app-settings-card')).toBeNull();
  });

  it('renders one row per run', () => {
    const el = mount({ runs: [PRICED_RUN, UNPRICED_RUN], totalCostNanoCredits: 41_230_000 });

    expect(el.querySelectorAll('.run-history__row')).toHaveLength(2);
  });

  it('renders a priced run as credits with four decimals', () => {
    const el = mount({ runs: [PRICED_RUN], totalCostNanoCredits: 41_230_000 });

    expect(el.querySelector('.run-history__cost')?.textContent?.trim()).toBe('0.0412');
  });

  it('renders an em dash when the provider reported no price', () => {
    const el = mount({ runs: [UNPRICED_RUN], totalCostNanoCredits: null });

    expect(el.querySelector('.run-history__cost')?.textContent?.trim()).toBe('—');
  });

  it('shows the all-time total, not the sum of the rows on screen', () => {
    const el = mount({ runs: [PRICED_RUN], totalCostNanoCredits: 918_200_000 });

    expect(el.querySelector('.run-history__total-value')?.textContent).toContain('0.9182');
  });

  it('shows an em dash for a total no run ever priced', () => {
    const el = mount({ runs: [UNPRICED_RUN], totalCostNanoCredits: null });

    expect(el.querySelector('.run-history__total-value')?.textContent?.trim()).toBe('—');
  });

  it('names the provider and the model the run actually called', () => {
    const el = mount({ runs: [PRICED_RUN], totalCostNanoCredits: null });

    expect(el.querySelector('.run-history__provider')?.textContent).toContain('openrouter.ai');
    expect(el.querySelector('.run-history__provider')?.textContent).toContain('x-ai/grok-4-fast');
  });

  it('re-fetches when a run completes', () => {
    mount({ runs: [PRICED_RUN], totalCostNanoCredits: null });
    expect(runHistory).toHaveBeenCalledTimes(1);

    completedStamp.set(1);
    TestBed.tick();

    expect(runHistory).toHaveBeenCalledTimes(2);
  });
});
```

If `TestBed.tick()` is not available in this Angular version, flush the effect
the way the debug-log spec already does — read
`recommendation-debug-log.component.spec.ts` and follow it.

- [ ] **Step 4: Run to verify it fails**

```bash
cd frontend && npx jest src/app/settings/recommendation-run-history.component.spec.ts
```

Expected: FAIL — cannot resolve the component module.

- [ ] **Step 5: Write the component**

`frontend/src/app/settings/recommendation-run-history.component.ts`:

```ts
// src/app/settings/recommendation-run-history.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  effect,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoModule, TranslocoService } from '@jsverse/transloco';
import { ReaderApi } from '../reader/reader-api';
import { formatDateOr, formatTime } from '../reader/format';
import { RunHistoryRow } from '../reader/models';
import { RecommendationsService } from '../reader/recommendations.service';
import { LanguageService } from '../core/language.service';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';

/** Nano-credits per credit. The API stores money as an integer -- floats do
 *  not hold money -- and this is the one place it becomes a human figure. */
const NANO_PER_CREDIT = 1_000_000_000;

/** What no reported price renders as. The provider said nothing about cost
 *  (a local model, or a run older than the column), which is a different
 *  statement from a cost of zero -- so it must not render as one. */
const NO_PRICE = '—';

/** What every for-you run has cost (#409): one row per run with the provider
 *  and model it actually called, how long it took, the tokens it consumed and
 *  the price, under the account's all-time total.
 *
 *  Not gated on the debug switch, and not bounded by the debug log's retention
 *  window: the run totals are banked by every call whether or not its
 *  transcript is being kept, which is the whole point of the issue.
 *
 *  Self-hiding, like the debug log below it: an account that has never run has
 *  nothing to show, so the settings page needs no extra lookup to hide it.
 *
 *  No poll loop. A finished run is the only thing that changes this list, and
 *  `completedStamp` already announces exactly that. */
@Component({
  selector: 'app-recommendation-run-history',
  standalone: true,
  imports: [SettingsCardComponent, TranslocoModule],
  templateUrl: './recommendation-run-history.component.html',
  styleUrl: './recommendation-run-history.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecommendationRunHistoryComponent {
  private readonly api = inject(ReaderApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  readonly runs = signal<RunHistoryRow[]>([]);
  readonly totalCostNanoCredits = signal<number | null>(null);

  /** Fetched on creation and again whenever a run completes -- the only event
   *  that adds a row or moves the total. */
  private readonly refetchOnCompletion = effect(() => {
    this.recs.completedStamp();
    this.fetch();
  });

  /** Credits with four decimals, or an em dash when the provider reported no
   *  price at all. Four decimals is the granularity a single run is worth
   *  reading at; a run cheaper than that reads as 0.0000, which is honest. */
  cost(nanoCredits: number | null): string {
    if (nanoCredits === null) return NO_PRICE;
    return (nanoCredits / NANO_PER_CREDIT).toFixed(4);
  }

  /** The provider and model the run called, as one line. Falls back to the
   *  translated "unknown" for a run that was never stamped. */
  provider(run: RunHistoryRow): string {
    const host = run.providerHost ?? this.i18n.translate('settings.ai.recommendations.historyUnknown');
    return run.model === null ? host : `${host} · ${run.model}`;
  }

  /** The active UI language drives the date format (via Intl), not `LOCALE_ID`
   *  -- Transloco switches language at runtime and a static `LOCALE_ID` cannot
   *  follow it. */
  day(run: RunHistoryRow): string {
    return formatDateOr(run.createdAt, this.language.lang(), '');
  }

  time(run: RunHistoryRow): string {
    return formatTime(run.createdAt);
  }

  private fetch(): void {
    this.api
      .runHistory()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((payload) => {
        this.runs.set(payload.runs);
        this.totalCostNanoCredits.set(payload.totalCostNanoCredits);
      });
  }
}
```

`frontend/src/app/settings/recommendation-run-history.component.html`:

```html
<!-- `status` renders the raw API wire vocabulary untranslated, the convention
     the #309 debug log records: it is the server's own word for the state, and
     a translated copy would drift from it. -->
@if (runs().length > 0) {
  <app-settings-card
    class="run-history"
    [heading]="'settings.ai.recommendations.historyTitle' | transloco"
    [description]="'settings.ai.recommendations.historyHint' | transloco"
  >
    <p class="run-history__total">
      <span class="run-history__total-label">
        {{ 'settings.ai.recommendations.historyTotal' | transloco }}
      </span>
      <span class="run-history__total-value">{{ cost(totalCostNanoCredits()) }}</span>
    </p>

    <div class="run-history__wrap">
      <ul class="run-history__list">
        @for (run of runs(); track run.id) {
          <li class="run-history__row">
            <span class="run-history__when">{{ day(run) }} {{ time(run) }}</span>
            <span class="run-history__provider">{{ provider(run) }}</span>
            <span class="run-history__status run-history__status--{{ run.status }}">
              {{ run.status }}
            </span>
            <span class="run-history__duration">
              @if (run.durationSeconds !== null) {
                {{ 'settings.ai.recommendations.historyDuration' | transloco: { s: run.durationSeconds } }}
              }
            </span>
            <span class="run-history__tokens">
              {{
                'settings.ai.recommendations.historyTokens'
                  | transloco: { prompt: run.promptTokens, completion: run.completionTokens }
              }}
            </span>
            <span class="run-history__cost">{{ cost(run.costNanoCredits) }}</span>
          </li>
        }
      </ul>
    </div>
  </app-settings-card>
}
```

`frontend/src/app/settings/recommendation-run-history.component.scss` — mirror
the debug panel's tokens; **no hex, no raw px, no media-query literals**:

```scss
@use '../theme/breakpoints' as bp;

/* The spending record above the debug log: a total line, then one row per run
   as an aligned grid -- `when | provider · model | status | duration | tokens |
   cost`. Its own `app-settings-card`, a sibling of the recommendations card. */
:host {
  display: block;
}

.run-history {
  margin-block-start: var(--space-5);
  font-size: var(--fs-sm);
  color: var(--text-secondary);

  &__total {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    margin: var(--space-2) 0 var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-0);
  }

  &__total-label {
    color: var(--text-muted);
    font-size: var(--fs-xs);
  }

  &__total-value {
    color: var(--text-primary);
    font-size: var(--fs-md);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
  }

  &__wrap {
    overflow-x: auto;
  }

  &__list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    min-width: 34rem;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  &__row {
    display: grid;
    grid-template-columns: 8rem 1fr 5rem 4rem 8rem 5rem;
    align-items: center;
    column-gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-0);
  }

  /* On a phone the six fixed columns are wider than the viewport, so the card
     would overflow the page the way the debug panel's grid once did. Release
     the floor and let the row reflow to a wrapping line. */
  @media (width <= bp.$bp-sm) {
    &__list {
      min-width: 0;
    }

    &__row {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      row-gap: var(--space-1);
    }

    &__provider {
      flex: 1 1 auto;
      min-width: 0;
    }
  }

  &__when,
  &__duration,
  &__tokens,
  &__cost {
    font-variant-numeric: tabular-nums;
  }

  &__when {
    color: var(--text-muted);
    font-size: var(--fs-xs);
  }

  &__provider {
    overflow: hidden;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__status {
    padding: 0 var(--space-2);
    border-radius: var(--radius-pill);
    background: var(--surface-1);
    color: var(--text-secondary);
    font-size: var(--fs-xs);
    font-weight: 600;
    text-align: center;
    text-transform: uppercase;

    &--completed {
      background: var(--bg-success);
      color: var(--success);
    }

    &--failed,
    &--cancelled {
      background: var(--bg-danger);
      color: var(--danger);
    }
  }

  &__duration,
  &__tokens {
    color: var(--text-muted);
    font-size: var(--fs-xs);
    text-align: right;
  }

  &__cost {
    font-weight: 600;
    text-align: right;
  }
}
```

If `--fs-md` or `--text-primary` do not exist, read
`frontend/src/app/theme/` and substitute the tokens that do. Do not invent one.

- [ ] **Step 6: Add the i18n keys**

In `frontend/public/i18n/en.json`, inside `settings.ai.recommendations`:

```json
"historyTitle": "Run history",
"historyHint": "What each recommendation run consumed, and what it cost.",
"historyTotal": "Total spent",
"historyDuration": "{{ s }} s",
"historyTokens": "{{ prompt }} in / {{ completion }} out",
"historyUnknown": "unknown provider"
```

In `frontend/public/i18n/de.json`, the same keys:

```json
"historyTitle": "Verlauf der Läufe",
"historyHint": "Was jeder Empfehlungslauf verbraucht hat und was er gekostet hat.",
"historyTotal": "Gesamtkosten",
"historyDuration": "{{ s }} s",
"historyTokens": "{{ prompt }} rein / {{ completion }} raus",
"historyUnknown": "unbekannter Anbieter"
```

- [ ] **Step 7: Run the spec**

```bash
cd frontend && npx jest src/app/settings/recommendation-run-history.component.spec.ts
```

Expected: PASS.

- [ ] **Step 8: Mount the card**

In `frontend/src/app/settings/ai-section.component.ts`, import
`RecommendationRunHistoryComponent` and add it to the component's `imports`
array alongside `RecommendationDebugLogComponent`.

In `frontend/src/app/settings/ai-section.component.html`, inside the existing
`@if (activeReady())` block, **above** the debug log:

```html
  <app-recommendation-run-history />
  <app-recommendation-debug-log />
```

- [ ] **Step 9: Run the frontend gate**

```bash
cd frontend && npm run check
```

Expected: ESLint, Prettier, Stylelint and Jest all clean. `ai-section.component.spec.ts`
must stay green — if it fails because the new card now issues an HTTP call, add
`runHistory: jest.fn().mockReturnValue(of({ runs: [], totalCostNanoCredits: null }))`
to the `ReaderApi` double that spec already provides.

- [ ] **Step 10: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#409): show the run history and total cost in AI settings"
```

---

### Task 9: Full gates, live verification, PR

**Files:** none created; this task proves the branch.

- [ ] **Step 1: Warm the cache and run every backend gate**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && php bin/phpunit
```

Expected: cs, stan (level max, `ThinControllerRule`), phptramp, PHPMD and the
SQLite suite all clean.

If `composer tramp` fails, check `composer show larspohlmann/phptramp` first —
CI runs the tip of that tool's `develop`, so a red gate here can be caused by a
phptramp change with no commit in this repo to explain it.

- [ ] **Step 2: Run the MySQL leg**

```bash
docker compose exec php vendor/bin/phpunit
```

Expected: pass. A rate-limiter failure that passes in isolation is the known
order-dependent flake, not this branch's regression.

- [ ] **Step 3: Run the mutation gate over the changed files**

```bash
cd backend && composer infection:diff
```

Expected: MSI at or above `minMsi` in `infection.json5`. Escaped mutants mean a
missing assertion — add the test, never lower the threshold.

- [ ] **Step 4: PhpStorm inspections on the changed PHP**

Run `mcp__phpstorm__lint_files` over every PHP file this branch created or
modified. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 5: Restart the containers that hold stale code**

The worker daemon loads code once at startup and the dev DI container is
compiled; both go stale on a branch like this.

```bash
docker compose restart php worker frontend
```

Then confirm the running containers actually serve this tree before believing
anything you see in the browser.

- [ ] **Step 6: Live verification — the issue's own bar**

This is the deliverable, not the gates.

1. Turn the **debug switch off** in AI settings.
2. With an OpenRouter connection active, start a for-you run and let it finish.
3. Open AI settings and confirm the run history card shows that run with a
   **non-empty cost**, and that the total grew by exactly that amount.
4. Switch to an LM Studio connection, run again, and confirm the row shows
   **tokens** with `—` for cost.
5. Scan `backend/var/log/dev.log` for deprecations and swallowed errors.

Record what you observed — the actual numbers — in the PR body. If step 3 or 4
does not hold, the branch is not done; debug it before opening the PR.

- [ ] **Step 7: Open the pull request**

```bash
git push -u origin feature/409-run-cost-history
```

```bash
gh pr create --base develop --title "feat(#409): record what each recommendation run costs, and show a run history" --body "$(cat <<'EOF'
Closes #409

## What

Captures the provider's own `usage` accounting on every recommendation call,
banks per-run totals independently of the debug switch and the debug log's
retention window, and shows a run history with an all-time cost total in AI
settings.

## How

- `CompletionUsage`: prompt/completion/reasoning/cached tokens plus cost as
  integer nano-credits. Null cost means the provider reported no price.
- `CompletionBodyDecoder` reads the `usage` object off the payload root — the
  final SSE message carries `choices: []`, which is why nothing read it before.
  One decode per event serves both the choice fields and the usage (#327).
- The value rides on `CompletionStreamProgress`, the object that already travels
  from transport to observer, rather than as a new parameter through the call
  chain.
- `stream_options: {include_usage: true}` is sent unconditionally — OpenAI spec,
  not a vendor extension.
- `RecordedCall` banks at settle, before the debug guard, with SQL arithmetic so
  a #344 wave cannot lose an increment. Failed calls and retries are banked too:
  the provider billed them.
- `provider_host` and `model` are stamped on the run at start and re-stamped on
  resume, so a later configuration change cannot rename an old run.
- `GET /api/recommendations/runs/history`: the newest 50 runs plus an all-time
  `SUM` — the total is over every run, not over the page.

## Out of scope

Existing runs cannot be retro-filled: the OpenRouter generation ids were never
stored. They read `—`.

## Verification

<!-- Replace with the actual observed numbers from Task 9 Step 6. -->
EOF
)"
```

Fill the verification section with the real observed figures before creating the
PR. Do not merge.

---

## Self-Review

**Spec coverage**

| Spec section | Task |
|---|---|
| `CompletionUsage` value object, nano-credits | 1 |
| Decode the provider's usage; one decode per event | 2 |
| Value rides on `CompletionStreamProgress` | 3 |
| `stream_options: {include_usage: true}` | 3 |
| `RecordedCall` banks at settle, SQL arithmetic, debug-independent | 5 |
| Failed calls and retries banked; one call billed once | 5 |
| Run columns + migration, BIGINT nullable cost | 4 |
| `provider_host`/`model` stamped at start and resume | 6 |
| `GET .../history`, newest 50 + all-time SUM | 7 |
| Settings card, 4 decimals, `—`, `formatDateOr`, no debug gate | 8 |
| Verification bar (live OpenRouter + LM Studio) | 9 |

**Type consistency**: `CompletionUsage` property names (`promptTokens`,
`completionTokens`, `reasoningTokens`, `cachedTokens`, `costNanoCredits`) are
used identically in Tasks 1, 2, 3, 5. The entity accessors in Task 4
(`getPromptTokens()` …, `getCostNanoCredits()`) are the ones Task 7's mapper
calls. The wire field names in Task 7 match the TypeScript interface in Task 8.
`stampProvider(?string, ?string)` in Task 4 matches its caller in Task 6.
