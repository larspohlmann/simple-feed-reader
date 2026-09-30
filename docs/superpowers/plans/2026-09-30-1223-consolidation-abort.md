# Consolidation Cut Short by the Provider Degrades to What It Finished (#1223) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1223 in one PR on `fix/1223-consolidation-abort`. When every consolidation attempt comes back cut short by the provider (`finish_reason` `error` or `content_filter`), the run completes with the recommendations the last reply finished, with the consolidation's own scores and reasons, instead of the batch-score pool with empty reasons. The batch-score pool stays the fallback for every other unusable reply, and for a cut reply that finished no recommendation.

**Architecture:**
- **`App\Service\Ai\Completion\Support\CompletionFinishReason::cutByProvider(?string)`** (new `Support/` helper). The Ai module knows the provider dialects: `error` (OpenRouter, an upstream that broke off mid-stream) and `content_filter` (OpenAI) mean the provider ended the answer. `length` does not: it is the caller's own `max_tokens` ceiling.
- **`RecordedCall::providerCutTheAnswer(): bool`**. The call's stream observer already keeps the finish reason for the run log. It now answers this one question from it.
- **`ModelReplyJsonDecoder::completeItemsOf(string $content, string $key)`**: the complete objects of a JSON array that the reply broke off inside. The existing brace walk behind `lastEmbeddedObject()` becomes the one shared walk, `completeObjectsFrom()`, so the two readers never drift.
- **`RecommendationConsolidationParser::parseCutReply()`**: those items through the existing `RecommendationPickSalvager`. No duplicates: the schema puts the `duplicates` list after the recommendations, so a cut reply never delivered it.
- **`RecommendationConsolidationResolver`**: an unusable reply still returns an unusable outcome, so `InvalidReplyRetry` still retries it (3 attempts, unchanged). Only the ranking the outcome carries for the degraded ending changes: the salvaged picks when the provider cut the reply and it finished at least one, else the batch-score pool as today. `ConsolidationOutcomeModel::requireFallbackPool()` becomes `requireFallbackRanking()`, because the fallback is no longer always the pool.
- No migration, no new persisted state, no prompt change, no frontend change.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit 12 (`#[DataProvider]`), `StubChatClient` (the test container's `ChatCompletionClientInterface`), PHPStan level max, PHPMD, phptramp, Infection (`composer infection:diff`, `minMsi` 80).

**Spec:**
- GitHub issue #1223 (`gh issue view 1223 --repo larspohlmann/simple-feed-reader`).
- CLAUDE.md, "PHP code style — Clean Code is mandatory"; `docs/architecture.md` §10 (role folders).

Written at **`2042b59a1`** (origin/develop). Line numbers are anchors at that commit, not contracts: every edit names the text it replaces.

---

## Decisions

- **D-1. The cause, confirmed as far as the rows allow (dev database, read-only SELECTs).** Runs 132 and 135 each made three `consolidate` calls. All six have `finish_reason = error`, `verdict = unusable`, `error_detail = NULL`, and replies of 32,478–37,213 bytes against 43,792–48,577 for the clean runs 133 and 134. All six break off at entry 549575. In 132 the cut falls inside that entry's reason: the reply ends `"id": 549575, "score": 520, "reason": "Xi Jinping’s use of` (attempt 1). In 135 it falls just after it: attempt 2 ends `"reason": "Xi Jinping festivals is Chinese politics." }, { "id": 549`. Run 136 shows the same pattern once more (attempt 1 cut at 549575 after 17,920 bytes). Its attempt 2 then scored 549575 without trouble and finished with `stop`. So 7 of the 8 attempts that reached the entry were cut there. The model was `qwen/qwen3.7-flash` through `openrouter.ai`. A provider-side output filter fits the pattern, but the rows cannot prove it. The fix below does not depend on the cause: it handles any cut the provider reports.
- **D-2. The issue's "the run gets no result" does not hold.** `recommendation_run` 132 and 135 are `completed` with `error = NULL`, `attempts = 3`, `transport_failures = 0`. Each holds 50 `recommendation_item` rows, all with an empty reason (`MAX(CHAR_LENGTH(reason)) = 0`), and they are exactly pool positions 0–49 in batch-score order. The run did not fail. It degraded, through the path that exists today: `ConsolidationPhase.php:29-34` → `InvalidReplyRetry::retryOrDegrade()` → `finalize($run, $outcome->requireFallbackPool())`. The defect is how poor that degraded list is, and that the reply's finished work is thrown away.
- **D-3. The client does not record the provider's error payload.** `CompletionBodyDecoder::streamEvent()` (`CompletionBodyDecoder.php:51-62`) reads only `content`, `reasoning`, `finishReason` and `usage` from each event. A top-level `error` object is dropped. An `error` finish with a clean end of stream is still an answer (`OpenAiCompatibleChatClient.php:173`, `CompletionOutcomeModel::answer(...)`), so `RecordedCall::finishUnusable()` settles the row without an `error_detail`. The `finish_reason` itself does reach the log (`RecordedCall.php:52`, `:99`), which is how the rows above show `error`. OpenRouter's documentation says the mid-stream error event carries `error.code` and `error.message` beside `finish_reason: "error"`, but this app never keeps them. Recording them is diagnostics, not the fix: see Q-1.
- **D-4. How a run degrades (the issue's step 2), decided with evidence: retry as today, then salvage, then the batch pool.**
  - **Salvage the finished items** is the general fix, and it wins on the data. A consolidation reply lists every candidate "in the order the lines appear" (`RecommendationPromptText::CONSOLIDATION_ROLE`). The lines are the pool in batch-score order, so a cut reply is a clean prefix of the full answer: 238, 238, 238, 230, 229, 229 and 125 finished items of 300 in the seven recorded cuts. Replaying the clean runs 133, 134 and 136 shows what a prefix keeps. A cut at item 230 keeps 45, 49 and 41 of the true top 50. A cut at 125 keeps 32, 29 and 31. The batch-score pool that runs get today keeps 18, 19 and 16, with no reasons. What salvage loses is dedup: the `duplicates` list comes last, and the clean runs named 24, 6 and 20 duplicates, 2, 0 and 10 of them inside the undeduped top 50. Today's fallback removes none either.
  - **Salvage only after the retries, not at once.** 1 of the 5 retries after a cut returned the full answer (136, attempt 2), and a transient provider error early in the list would otherwise ship a tiny feed. The retry model is untouched (`RecommendationRun::MAX_ATTEMPTS = 3`, `InvalidReplyRetry`). The degraded ending salvages the reply in hand at the third attempt, so no cross-tick state is needed.
  - **Salvage only what the provider cut** (`error`, `content_filter`). A `length` stop is the call's own ceiling. A runaway reaches the parser clipped to 4,000 characters (`OpenAiCompatibleChatClient::QUOTABLE_ANSWER_CHARS`). An implausible-duplicates reply is distrusted as a whole (`RecommendationConsolidationParser.php:45`). None of these is a finished prefix of a trustworthy answer, so each keeps today's batch-pool ending.
  - **"Drop the offending candidate and retry" is rejected.** The offender can be identified deterministically ("the last shown id the cut reply names": 549575 in all seven recorded cuts), but that says where the cut fell, not why. In run 136 the same entry passed on the retry, and a cut from any other provider error would drop an innocent candidate. Retries run one per tick, so the excluded id would need new persisted state (a column and a migration) and a second pool rule. It would also spend one more full call per cut.
  - **"Fall back to the batch winners"** is what the code does today (D-2). It stays the fallback of last resort.
- **D-5. What the user sees.**
  - A cut followed by a successful retry: the full consolidated feed, as today.
  - Every attempt cut, the last one after at least one finished item: a completed run whose feed is the finished items, ranked by the consolidation's scores, each with its reason. Candidates after the cut are not recommended, which is consistent with the resolver's rule that consolidation is the sole authority (`RecommendationConsolidationResolver::rankedFromReply()`). Duplicates are not removed.
  - Every attempt unusable for any other reason, or cut before one item finished: the batch-score list without reasons, as today.
  - The debug log looks as it does now: three `unusable` consolidate rows with `finish_reason` `error`.
- **D-6. The batch phase is out of scope.** The dev database holds 41 batch calls since run 131: 40 `stop`/`usable` and 1 `length`/`unusable`, and no `error`. Batch calls are small and retry inside their tick (`InvalidReplyRetry.php:11`).
- **D-7. A live provider cannot be made to cut on demand.** The final real run proves the happy path end to end on the dev stack. The salvage itself is proven by the tests and by Task 6, which replays the seven recorded cut replies through the new parser.

## Questions for the planner

- **Q-1.** Record the provider's mid-stream `error.message` in `recommendation_run_log.error_detail` (about six files across the Ai decoder, the stream reader, the progress model, `RecordedCall`, `CallOutcome` and `RecommendationCallRepository`)? The options are "in this PR" or "a follow-up issue". Planned: **a follow-up issue**. It would confirm the next cut's cause, but the fix does not need it. Task 10 offers it.

---

## What the code does at `2042b59a1`

`backend/src/Service/Recommendation/Run/RecommendationConsolidationResolver.php:72-78`, the unusable branch hands back the whole batch-score pool:

```php
        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ConsolidationOutcomeModel::unusable($content, $pool);
        }
```

`backend/src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php:28-34`, the degraded ending finalizes with it once the attempts run out:

```php
        if (!$outcome->usable) {
            return $this->invalidReplies->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReportModel
                    => $this->finalizer->finalize($run, $outcome->requireFallbackPool()),
            );
        }
```

`backend/src/Service/Recommendation/Prompt/ModelReplyJsonDecoder.php:33-56`. `lastEmbeddedObject()` only keeps objects that close at depth 0, and a cut reply's outer object never closes, so `decode()` returns `null` and `parse()` returns `unusable()`.

`backend/src/Service/Recommendation/Run/Pass/RecordedCall.php:30-31` and `:52` hold the finish reason the run log shows:

```php
    /** Held until the call settles: a `length` beside an empty answer is a truncation. */
    private ?string $finishReason = null;
```

`backend/tests/Support/StubChatClient.php` never calls the observer, so no test can hand `RecordedCall` a finish reason yet. Task 4 adds `queueStreamedReply()`.

---

### Task 0: Preflight, branch, plan copy, anchors, evidence

**Files:**
- Create: `docs/superpowers/plans/2026-09-30-1223-consolidation-abort.md` (this plan)
- Create (git-ignored): `backend/var/1223/*.txt`

- [ ] **Step 1: The checkout is free and the issue is open (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1223 --repo larspohlmann/simple-feed-reader --json state --jq .state
```
Expected: a clean tree, then `OPEN`. Another session may share this checkout (CLAUDE.md, "Workflow"). If the tree is not clean, or the planner has not confirmed that the checkout is free, stop and report before switching branches.

- [ ] **Step 2: Branch and commit the plan copy (from the repository root)**

```bash
git switch -c fix/1223-consolidation-abort origin/develop
cp <the plan file the planner handed you> docs/superpowers/plans/2026-09-30-1223-consolidation-abort.md
git add docs/superpowers/plans/2026-09-30-1223-consolidation-abort.md
git commit -m "docs(#1223): add plan"
```

- [ ] **Step 3: The anchors hold (from `backend/`)**

```bash
git grep -n -E 'return ConsolidationOutcomeModel::unusable\(\$content, \$pool\);' -- src/Service/Recommendation/Run
git grep -n -E 'function (requireFallbackPool|lastEmbeddedObject|decodeObject)\(' -- src
git grep -n -E 'function (providerCutTheAnswer|completeItemsOf|parseCutReply|queueStreamedReply|requireFallbackRanking|finishUnusable)\(' -- src tests
git grep -n -E 'requireFallbackPool\(' -- src tests | wc -l
git ls-files -- src/Service/Ai/Completion/Support src/Service/Ai/Support
```
Expected:
- One line: `RecommendationConsolidationResolver.php:77`.
- Three lines: `Run/Model/ConsolidationOutcomeModel.php:52`, `Prompt/ModelReplyJsonDecoder.php:33`, `Prompt/ModelReplyJsonDecoder.php:80`.
- One line: `src/Service/Recommendation/Run/Pass/RecordedCall.php:70` (`finishUnusable`, the positive control). None of the five new names exists yet.
- `8`: `ConsolidationOutcomeModel.php:52`, `ConsolidationPhase.php:33`, `ConsolidationOutcomeModelTest.php:19` and `:37`, `RecommendationConsolidationResolverTest.php:159`, `:164`, `:305` and `:308`.
- Only `src/Service/Ai/Support/AiReadiness.php` (the positive control): `Ai/Completion/Support` does not exist yet.

A different result is a reconcile gap. Stop and report it with the output.

- [ ] **Step 4: Freeze the recorded cuts before a new run trims them (from the repository root)**

The starter keeps the run log of the last 10 runs only (`RunLogRetention::RUNS`). The real run in Task 9 would begin trimming the rows Task 6 replays, so copy them now. The planner's scratchpad already holds a UTF-8 dump.

```bash
mkdir -p backend/var/1223
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/r1223/request_body-*.txt /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/r1223/response_text-*.txt backend/var/1223/
ls backend/var/1223 | wc -l
git -C backend check-ignore -v var/1223/response_text-1934.txt
```
Expected: `20`, then one line naming `.gitignore` and `/var/`. If the copy finds nothing (another scratchpad), dump the seven rows from the dev database instead, read-only:

```bash
for id in 1934 1935 1936 1954 1955 1956 1962; do for column in response_text request_body; do docker compose exec -T mysql mysql --default-character-set=utf8mb4 -ufeedreader -pfeedreader feedreader -N --raw -e "SELECT $column FROM recommendation_run_log WHERE id = $id" 2>/dev/null > backend/var/1223/$column-$id.txt; done; done
wc -c backend/var/1223/response_text-*.txt
```
Expected: seven files of about 32–37 KB and one (`1962`) of about 18 KB. If a file is empty, the run log is gone: write "Replay: rows purged" into the task report and skip Task 6.

---

### Task 1: The Ai module names a provider cut; `RecordedCall` answers it

**Files:**
- Create: `backend/src/Service/Ai/Completion/Support/CompletionFinishReason.php`
- Create: `backend/tests/Service/Ai/Completion/Support/CompletionFinishReasonTest.php`
- Modify: `backend/src/Service/Recommendation/Run/Pass/RecordedCall.php`
- Modify: `backend/tests/Service/Recommendation/Run/Pass/RecordedCallTest.php`

- [ ] **Step 1: The failing tests**

Create `backend/tests/Service/Ai/Completion/Support/CompletionFinishReasonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Completion\Support;

use App\Service\Ai\Completion\Support\CompletionFinishReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompletionFinishReasonTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function finishReasons(): iterable
    {
        yield 'an upstream error mid-stream' => ['error', true];
        yield 'a content filter' => ['content_filter', true];
        yield 'a natural end' => ['stop', false];
        yield 'the max_tokens ceiling' => ['length', false];
        yield 'no finish reason yet' => [null, false];
    }

    #[DataProvider('finishReasons')]
    public function testOnlyTheProviderEndingTheAnswerIsACut(?string $finishReason, bool $cut): void
    {
        self::assertSame($cut, CompletionFinishReason::cutByProvider($finishReason));
    }
}
```

`backend/tests/Service/Recommendation/Run/Pass/RecordedCallTest.php`. Before:
```php
    public function testACallThatNeverHeardAFinishReasonRecordsNone(): void
    {
        $call = $this->call();

        $call->finishUsable('the answer');

        self::assertNull($this->reload($this->log)->getFinishReason());
    }
```
After:
```php
    public function testACallThatNeverHeardAFinishReasonRecordsNone(): void
    {
        $call = $this->call();

        $call->finishUsable('the answer');

        self::assertNull($this->reload($this->log)->getFinishReason());
    }

    public function testACallTheProviderEndedWithAnErrorWasCutByTheProvider(): void
    {
        $call = $this->call();
        $call->streamProgressed(new CompletionStreamProgressModel('{"recommendations": [', 100, 'error'));
        $call->streamProgressed(new CompletionStreamProgressModel('{"recommendations": [', 120));

        self::assertTrue($call->providerCutTheAnswer());
    }

    public function testACallThatStoppedOnItsOwnWasNotCutByTheProvider(): void
    {
        $call = $this->call();
        $call->streamProgressed(new CompletionStreamProgressModel('{}', 100, 'stop'));

        self::assertFalse($call->providerCutTheAnswer());
    }
```

- [ ] **Step 2: They fail (from `backend/`)**

```bash
php bin/phpunit --filter '(CompletionFinishReasonTest|RecordedCallTest)'
```
Expected: 5 errors `Error: Class "App\Service\Ai\Completion\Support\CompletionFinishReason" not found` and 2 errors `Error: Call to undefined method App\Service\Recommendation\Run\Pass\RecordedCall::providerCutTheAnswer()`. The 14 existing `RecordedCallTest` tests pass.

- [ ] **Step 3: The helper**

Create `backend/src/Service/Ai/Completion/Support/CompletionFinishReason.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\Support;

/**
 * Who ended an answer. OpenRouter reports an upstream that broke off mid-stream as `error`, OpenAI a filtered answer
 * as `content_filter`; `length` is the caller's own `max_tokens` ceiling, not a cut.
 */
final readonly class CompletionFinishReason
{
    public static function cutByProvider(?string $finishReason): bool
    {
        return 'error' === $finishReason || 'content_filter' === $finishReason;
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: `RecordedCall` answers from the finish reason it keeps**

`backend/src/Service/Recommendation/Run/Pass/RecordedCall.php`, edit 1. Before:
```php
use App\Service\Ai\Completion\Model\CompletionUsageModel;
use Symfony\Component\Clock\ClockInterface;
```
After:
```php
use App\Service\Ai\Completion\Model\CompletionUsageModel;
use App\Service\Ai\Completion\Support\CompletionFinishReason;
use Symfony\Component\Clock\ClockInterface;
```

Edit 2. Before:
```php
    public function finishUnusable(string $content): void
    {
        $this->finish($content, CallVerdict::Unusable);
    }
```
After:
```php
    public function finishUnusable(string $content): void
    {
        $this->finish($content, CallVerdict::Unusable);
    }

    public function providerCutTheAnswer(): bool
    {
        return CompletionFinishReason::cutByProvider($this->finishReason);
    }
```

- [ ] **Step 5: They pass (from `backend/`)**

```bash
php bin/phpunit --filter '(CompletionFinishReasonTest|RecordedCallTest)'
```
Expected: `OK (21 tests, …)`.

- [ ] **Step 6: Deletion checks (from `backend/`)**

(a) In `CompletionFinishReason.php`, replace `return 'error' === $finishReason || 'content_filter' === $finishReason;` with `return 'error' === $finishReason;`. Run `php bin/phpunit --filter CompletionFinishReasonTest`. Expected FAIL: `with data set "a content filter"` … `Failed asserting that false is identical to true.` Restore the After of Step 3 with the Edit tool.

(b) In `RecordedCall.php`, replace `return CompletionFinishReason::cutByProvider($this->finishReason);` with `return false;`. Run `php bin/phpunit --filter RecordedCallTest`. Expected FAIL: `testACallTheProviderEndedWithAnErrorWasCutByTheProvider` … `Failed asserting that false is true.` Then replace `return false;` with `return true;`. Expected FAIL: `testACallThatStoppedOnItsOwnWasNotCutByTheProvider` … `Failed asserting that true is false.` Restore the After of Step 4 edit 2 with the Edit tool, and rerun Step 5.

- [ ] **Step 7: Per-task gates and commit (from `backend/`)**

```bash
php -l src/Service/Ai/Completion/Support/CompletionFinishReason.php
php -l src/Service/Recommendation/Run/Pass/RecordedCall.php
vendor/bin/phpcs src/Service/Ai/Completion/Support/CompletionFinishReason.php src/Service/Recommendation/Run/Pass/RecordedCall.php tests/Service/Ai/Completion/Support/CompletionFinishReasonTest.php tests/Service/Recommendation/Run/Pass/RecordedCallTest.php
git add src/Service/Ai/Completion/Support/CompletionFinishReason.php tests/Service/Ai/Completion/Support/CompletionFinishReasonTest.php src/Service/Recommendation/Run/Pass/RecordedCall.php tests/Service/Recommendation/Run/Pass/RecordedCallTest.php
git commit -m "fix(#1223): a recorded call knows when the provider cut the answer"
```
Expected: `No syntax errors detected` twice, phpcs silent.

---

### Task 2: The reply decoder recovers the complete items of a cut array

**Files:**
- Modify: `backend/src/Service/Recommendation/Prompt/ModelReplyJsonDecoder.php`
- Modify: `backend/tests/Service/Recommendation/Prompt/ModelReplyJsonDecoderTest.php`

- [ ] **Step 1: The failing tests**

`backend/tests/Service/Recommendation/Prompt/ModelReplyJsonDecoderTest.php`. Before:
```php
    public function testAnEmptyStringValueBeforeABraceInAStringIsHandled(): void
    {
        self::assertSame(
            ['a' => '', 'b' => '}'],
            $this->decoder->decode('answer: {"a": "", "b": "}"}'),
        );
    }
}
```
After:
```php
    public function testAnEmptyStringValueBeforeABraceInAStringIsHandled(): void
    {
        self::assertSame(
            ['a' => '', 'b' => '}'],
            $this->decoder->decode('answer: {"a": "", "b": "}"}'),
        );
    }

    public function testTheCompleteItemsOfAnArrayCutInsideAStringAreRecoveredInOrder(): void
    {
        self::assertSame(
            [['id' => 1, 'reason' => 'scored 10} points'], ['id' => 2, 'reason' => 'b']],
            $this->decoder->completeItemsOf(
                '{"recommendations": [{"id": 1, "reason": "scored 10} points"}, {"id": 2, "reason": "b"}, '
                . '{"id": 3, "reason": "cut o',
                'recommendations',
            ),
        );
    }

    public function testAnItemCutBetweenItsFieldsIsLeftOutOfACompactArray(): void
    {
        self::assertSame(
            [['id' => 1]],
            $this->decoder->completeItemsOf('{"recommendations":[{"id":1},{"id":2,"sc', 'recommendations'),
        );
    }

    public function testAnUndecodableObjectBetweenItemsIsSkipped(): void
    {
        self::assertSame(
            [['id' => 1], ['id' => 2]],
            $this->decoder->completeItemsOf(
                '{"recommendations": [{"id": 1}, {id: 7}, {"id": 2}, {"id"',
                'recommendations',
            ),
        );
    }

    /** Prose around the reply may name the key half-quoted; only the exact key opens the array. */
    public function testOnlyTheQuotedKeyOpensTheArray(): void
    {
        self::assertSame(
            [['id' => 1]],
            $this->decoder->completeItemsOf(
                'Plan: "recommendations first [a], then recommendations" [b].' . "\n"
                . '{"recommendations": [{"id": 1}, {"id": 2',
                'recommendations',
            ),
        );
    }

    public function testAReplyThatNeverOpenedTheArrayHasNoItems(): void
    {
        self::assertSame([], $this->decoder->completeItemsOf('{"recommendations": ', 'recommendations'));
        self::assertSame([], $this->decoder->completeItemsOf('{"profile": "x"}', 'recommendations'));
    }
}
```

- [ ] **Step 2: They fail (from `backend/`)**

```bash
php bin/phpunit --filter ModelReplyJsonDecoderTest
```
Expected: 5 errors `Error: Call to undefined method App\Service\Recommendation\Prompt\ModelReplyJsonDecoder::completeItemsOf()`; the 15 existing tests pass.

- [ ] **Step 3: One walk, two readers**

`backend/src/Service/Recommendation/Prompt/ModelReplyJsonDecoder.php`, edit 1. Before:
```php
        return $this->lastEmbeddedObject($content);
    }

    /**
     * The last complete `{...}` that decodes, the object the model settled on: LM Studio can route an answer through
     * thinking prose. String literals are skipped, so a brace inside a value cannot end an object early.
     *
     * @return array<mixed>|null
     */
    private function lastEmbeddedObject(string $text): ?array
    {
        $found = null;
        $depth = 0;
        $start = 0;
        $length = \strlen($text);

        for ($index = 0; $index < $length; ++$index) {
            $character = $text[$index];

            if ('"' === $character) {
                $index = $this->endOfString($text, $index);
            } elseif ('{' === $character) {
                if (0 === $depth) {
                    $start = $index;
                }
                ++$depth;
            } elseif ('}' === $character && $depth > 0 && 0 === --$depth) {
                $found = $this->decodeObject(substr($text, $start, $index - $start + 1)) ?? $found;
            }
        }

        return $found;
    }
```
After:
```php
        return $this->lastEmbeddedObject($content);
    }

    /**
     * The complete objects of the `$key` array in a reply cut off before that array closed, in order: the object the
     * cut split never closes, so it is not among them. Empty when the reply never opened the array.
     *
     * @return list<array<mixed>>
     */
    public function completeItemsOf(string $content, string $key): array
    {
        $keyAt = strpos($content, '"' . $key . '"');
        if (false === $keyAt) {
            return [];
        }

        $arrayAt = strpos($content, '[', $keyAt);
        if (false === $arrayAt) {
            return [];
        }

        return $this->completeObjectsFrom($content, $arrayAt);
    }

    /**
     * The last complete `{...}` that decodes, the object the model settled on: LM Studio can route an answer through
     * thinking prose.
     *
     * @return array<mixed>|null
     */
    private function lastEmbeddedObject(string $text): ?array
    {
        $objects = $this->completeObjectsFrom($text, 0);

        return [] === $objects ? null : $objects[array_key_last($objects)];
    }

    /**
     * Every outermost `{...}` from $offset on that closes and decodes, in order. String literals are skipped, so a
     * brace inside a value cannot end an object early.
     *
     * @return list<array<mixed>>
     */
    private function completeObjectsFrom(string $text, int $offset): array
    {
        $objects = [];
        $depth = 0;
        $start = $offset;
        $length = \strlen($text);

        for ($index = $offset; $index < $length; ++$index) {
            $character = $text[$index];

            if ('"' === $character) {
                $index = $this->endOfString($text, $index);
            } elseif ('{' === $character) {
                if (0 === $depth) {
                    $start = $index;
                }
                ++$depth;
            } elseif ('}' === $character && $depth > 0 && 0 === --$depth) {
                $objects = [...$objects, ...$this->decodeObject(substr($text, $start, $index - $start + 1))];
            }
        }

        return $objects;
    }
```

Edit 2. Before:
```php
    /** @return array<mixed>|null */
    private function decodeObject(string $candidate): ?array
    {
        $decoded = json_decode($candidate, true);

        return \is_array($decoded) ? $decoded : null;
    }
```
After:
```php
    /** @return list<array<mixed>> the object alone, or nothing when it does not decode */
    private function decodeObject(string $candidate): array
    {
        $decoded = json_decode($candidate, true);

        return \is_array($decoded) ? [$decoded] : [];
    }
```

- [ ] **Step 4: They pass, and so do the three parsers that share `decode()` (from `backend/`)**

```bash
php bin/phpunit --filter '(ModelReplyJsonDecoderTest|RecommendationPickParserTest|RecommendationProfileParserTest|RecommendationConsolidationParserTest)'
```
Expected: `OK`. The 15 existing decoder tests pin the refactor of `lastEmbeddedObject()`: the last decodable object wins, braces inside strings, a stray leading brace, an empty string before a brace.

- [ ] **Step 5: Deletion checks (from `backend/`, restoring each with the Edit tool)**

(a) In `completeItemsOf()`, replace `$arrayAt = strpos($content, '[', $keyAt);` with `$arrayAt = strpos($content, '[');`. Run `php bin/phpunit --filter testOnlyTheQuotedKeyOpensTheArray`. Expected FAIL: `Failed asserting that two arrays are identical.` Restore.

(b) Delete the three lines `if (false === $keyAt) {` / `return [];` / `}` (the first guard). Run `php bin/phpunit --filter testAReplyThatNeverOpenedTheArrayHasNoItems`. Expected ERROR: `TypeError: strpos(): Argument #3 ($offset) must be of type int, bool given`. Restore.

(c) Delete the second guard (`if (false === $arrayAt) {` / `return [];` / `}`). Same filter. Expected ERROR: `TypeError: App\Service\Recommendation\Prompt\ModelReplyJsonDecoder::completeObjectsFrom(): Argument #2 ($offset) must be of type int, bool given`. Restore.

(d) In `decodeObject()`, replace `return \is_array($decoded) ? [$decoded] : [];` with `return [$decoded];`. Run `php bin/phpunit --filter testAnUndecodableObjectBetweenItemsIsSkipped`. Expected FAIL: `Failed asserting that two arrays are identical.` Restore.

Rerun Step 4.

- [ ] **Step 6: Per-task gates and commit (from `backend/`)**

```bash
php -l src/Service/Recommendation/Prompt/ModelReplyJsonDecoder.php
vendor/bin/phpcs src/Service/Recommendation/Prompt/ModelReplyJsonDecoder.php tests/Service/Recommendation/Prompt/ModelReplyJsonDecoderTest.php
git add src/Service/Recommendation/Prompt/ModelReplyJsonDecoder.php tests/Service/Recommendation/Prompt/ModelReplyJsonDecoderTest.php
git commit -m "fix(#1223): the reply decoder recovers the complete items of a cut array"
```

---

### Task 3: The consolidation parser reads a cut reply's finished recommendations

**Files:**
- Modify: `backend/src/Service/Recommendation/Prompt/RecommendationConsolidationParser.php`
- Modify: `backend/tests/Service/Recommendation/Prompt/RecommendationConsolidationParserTest.php`

- [ ] **Step 1: The failing tests**

`backend/tests/Service/Recommendation/Prompt/RecommendationConsolidationParserTest.php`. Before:
```php
    public function testMissingRecommendationsKeyIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('{"duplicates":[]}', [5])->usable);
    }
}
```
After:
```php
    public function testMissingRecommendationsKeyIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('{"duplicates":[]}', [5])->usable);
    }

    public function testACutReplyKeepsTheRecommendationsItFinishedAndNamesNoDuplicates(): void
    {
        $result = $this->parser->parseCutReply(
            '{"recommendations":[{"id":5,"score":900,"reason":"On Rust."},{"id":6,"score":300,"reason":"We',
            [5, 6],
        );

        self::assertTrue($result->usable);
        self::assertSame([5], array_map(static fn ($pick) => $pick->entryId, $result->picks));
        self::assertSame('On Rust.', $result->picks[0]->reason);
        self::assertSame([], $result->duplicateIds);
    }

    public function testACutReplyThatFinishedNoShownRecommendationIsUnusable(): void
    {
        $result = $this->parser->parseCutReply(
            '{"recommendations":[{"id":999,"score":900,"reason":"x"},{"id":5,"sc',
            [5],
        );

        self::assertFalse($result->usable);
    }
}
```

- [ ] **Step 2: They fail (from `backend/`)**

```bash
php bin/phpunit --filter RecommendationConsolidationParserTest
```
Expected: 2 errors `Error: Call to undefined method App\Service\Recommendation\Prompt\RecommendationConsolidationParser::parseCutReply()`; the existing tests pass.

- [ ] **Step 3: `parseCutReply()`**

`backend/src/Service/Recommendation/Prompt/RecommendationConsolidationParser.php`. Before:
```php
        return ConsolidationParseResultModel::usable($picks, $duplicateIds);
    }

    /**
     * @param list<int> $shownIds
     *
     * @return list<int>
     */
    private function salvageDuplicateIds(mixed $duplicates, array $shownIds): array
```
After:
```php
        return ConsolidationParseResultModel::usable($picks, $duplicateIds);
    }

    /**
     * A reply the provider cut short, read for the recommendations it finished. The schema puts the duplicates after
     * them, so none arrived.
     *
     * @param list<int> $shownIds
     */
    public function parseCutReply(string $content, array $shownIds): ConsolidationParseResultModel
    {
        $picks = $this->salvager->salvage($this->decoder->completeItemsOf($content, 'recommendations'), $shownIds);

        if ([] === $picks) {
            return ConsolidationParseResultModel::unusable();
        }

        return ConsolidationParseResultModel::usable($picks, []);
    }

    /**
     * @param list<int> $shownIds
     *
     * @return list<int>
     */
    private function salvageDuplicateIds(mixed $duplicates, array $shownIds): array
```

- [ ] **Step 4: They pass (from `backend/`)**

```bash
php bin/phpunit --filter RecommendationConsolidationParserTest
```
Expected: `OK`.

- [ ] **Step 5: Deletion checks (from `backend/`, restoring each with the Edit tool)**

(a) In `parseCutReply()`, replace `$this->decoder->completeItemsOf($content, 'recommendations')` with `[]`. Run `php bin/phpunit --filter testACutReplyKeepsTheRecommendationsItFinishedAndNamesNoDuplicates`. Expected FAIL: `Failed asserting that false is true.` Restore.

(b) Delete the guard (`if ([] === $picks) {` / `return ConsolidationParseResultModel::unusable();` / `}` and the blank line after it). Run `php bin/phpunit --filter testACutReplyThatFinishedNoShownRecommendationIsUnusable`. Expected FAIL: `Failed asserting that true is false.` Restore, and rerun Step 4.

- [ ] **Step 6: Per-task gates and commit (from `backend/`)**

```bash
php -l src/Service/Recommendation/Prompt/RecommendationConsolidationParser.php
vendor/bin/phpcs src/Service/Recommendation/Prompt/RecommendationConsolidationParser.php tests/Service/Recommendation/Prompt/RecommendationConsolidationParserTest.php
git add src/Service/Recommendation/Prompt/RecommendationConsolidationParser.php tests/Service/Recommendation/Prompt/RecommendationConsolidationParserTest.php
git commit -m "fix(#1223): the consolidation parser reads a cut reply's finished recommendations"
```

---

### Task 4: The fallback is a ranking; the stub can stream a finish reason

No behaviour changes in this task. The rename says what Task 5 makes true, and the stub lets Task 5's tests hand `RecordedCall` a finish reason through the real call path (`RateLimitedCompletion::complete()` → `completeMany()`).

**Files:**
- Modify: `backend/src/Service/Recommendation/Run/Model/ConsolidationOutcomeModel.php`
- Modify: `backend/src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php`
- Modify: `backend/tests/Service/Recommendation/Run/Model/ConsolidationOutcomeModelTest.php`
- Modify: `backend/tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`
- Modify: `backend/tests/Support/StubChatClient.php`

- [ ] **Step 1: `requireFallbackRanking()`**

`backend/src/Service/Recommendation/Run/Model/ConsolidationOutcomeModel.php`, edit 1. Before:
```php
/**
 * What a consolidation call settled to: the final list, or the unusable reply ConsolidationPhase retries and the
 * batch-score pool it degrades to.
 */
final readonly class ConsolidationOutcomeModel
{
    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked usable: the final
     *                                                                  list; unusable: the undeduped pool to degrade to
     */
```
After:
```php
/**
 * What a consolidation call settled to: the final list, or the unusable reply ConsolidationPhase retries and the
 * ranking it degrades to once the retries run out.
 */
final readonly class ConsolidationOutcomeModel
{
    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked usable: the final
     *                                                                  list; unusable: the ranking to degrade to
     */
```

Edit 2. Before:
```php
    /**
     * @param list<array{id: int, score: int, reason: string}> $fallbackPool
     */
    public static function unusable(string $reply, array $fallbackPool): self
    {
        return new self(false, $fallbackPool, $reply);
    }
```
After:
```php
    /**
     * @param list<array{id: int, score: int, reason: string}> $fallbackRanking
     */
    public static function unusable(string $reply, array $fallbackRanking): self
    {
        return new self(false, $fallbackRanking, $reply);
    }
```

Edit 3. Before:
```php
    /**
     * The batch-score pool to degrade to once retries run out. Only an
     * unusable outcome carries one; a usable outcome's list is already final.
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function requireFallbackPool(): array
    {
        if ($this->usable) {
            throw new \LogicException('A usable consolidation outcome has no fallback pool to degrade to.');
        }

        return $this->ranked;
    }
```
After:
```php
    /**
     * Only an unusable outcome carries one; a usable outcome's list is already final.
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function requireFallbackRanking(): array
    {
        if ($this->usable) {
            throw new \LogicException('A usable consolidation outcome has no fallback ranking to degrade to.');
        }

        return $this->ranked;
    }
```

`backend/src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php`. Before:
```php
                    => $this->finalizer->finalize($run, $outcome->requireFallbackPool()),
```
After:
```php
                    => $this->finalizer->finalize($run, $outcome->requireFallbackRanking()),
```

`backend/tests/Service/Recommendation/Run/Model/ConsolidationOutcomeModelTest.php`, edit 1. Before:
```php
        self::assertSame([['id' => 1, 'score' => 10, 'reason' => 'r']], $outcome->requireFallbackPool());
```
After:
```php
        self::assertSame([['id' => 1, 'score' => 10, 'reason' => 'r']], $outcome->requireFallbackRanking());
```
Edit 2. Before:
```php
    public function testAUsableOutcomeHasNoFallbackPoolToDegradeTo(): void
    {
        $outcome = ConsolidationOutcomeModel::finalizeWith([['id' => 1, 'score' => 10, 'reason' => 'r']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A usable consolidation outcome has no fallback pool to degrade to.');
        $outcome->requireFallbackPool();
    }
```
After:
```php
    public function testAUsableOutcomeHasNoFallbackRankingToDegradeTo(): void
    {
        $outcome = ConsolidationOutcomeModel::finalizeWith([['id' => 1, 'score' => 10, 'reason' => 'r']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A usable consolidation outcome has no fallback ranking to degrade to.');
        $outcome->requireFallbackRanking();
    }
```

`backend/tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`: replace all four occurrences of `$outcome->requireFallbackPool()` with `$outcome->requireFallbackRanking()` (Edit tool, `replace_all: true`; lines 159, 164, 305, 308).

- [ ] **Step 2: No `requireFallbackPool` is left (from `backend/`)**

```bash
git grep -n -E 'requireFallback(Pool|Ranking)\(' -- src tests
```
Expected: 8 lines, all `requireFallbackRanking(` (the positive control: the pattern matches the new name); none `requireFallbackPool(`.

- [ ] **Step 3: `StubChatClient::queueStreamedReply()`**

`backend/tests/Support/StubChatClient.php`, edit 1. Before:
```php
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\Model\Reasoning;
```
After:
```php
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\Model\CompletionStreamProgressModel;
use App\Service\Ai\Completion\Model\Reasoning;
```

Edit 2. Before:
```php
    /** @var list<string|\RuntimeException> */
    private array $queue = [];
```
After:
```php
    /** @var list<string|\RuntimeException|CompletionStreamProgressModel> */
    private array $queue = [];
```

Edit 3. Before:
```php
    public function queueFailure(\RuntimeException $exception): void
    {
        $this->queue[] = $exception;
    }
```
After:
```php
    public function queueFailure(\RuntimeException $exception): void
    {
        $this->queue[] = $exception;
    }

    /**
     * Reports $lastReport to the call's observer, as the real client does with every chunk, then answers with its
     * text: how a test hands RecordedCall a finish reason.
     */
    public function queueStreamedReply(CompletionStreamProgressModel $lastReport): void
    {
        $this->queue[] = $lastReport;
    }
```

Edit 4. Before:
```php
        $next = $this->answer($request);

        // A spoiled reply is content, not an exception: the real client returns it for the caller's parser to judge.
        if ($next instanceof ProviderReplyFailureExceptionInterface) {
            return $next->partialAnswer();
        }
```
After:
```php
        $next = $this->answer($request);

        if ($next instanceof CompletionStreamProgressModel) {
            return $this->reportAndAnswer($observer, $next);
        }

        // A spoiled reply is content, not an exception: the real client returns it for the caller's parser to judge.
        if ($next instanceof ProviderReplyFailureExceptionInterface) {
            return $next->partialAnswer();
        }
```

Edit 5. Before:
```php
            $outcomes[] = match (true) {
                $next instanceof ProviderReplyFailureExceptionInterface => CompletionOutcomeModel::unusableReply($next),
```
After:
```php
            $outcomes[] = match (true) {
                $next instanceof CompletionStreamProgressModel
                    => CompletionOutcomeModel::answer($this->reportAndAnswer($call->observer, $next)),
                $next instanceof ProviderReplyFailureExceptionInterface => CompletionOutcomeModel::unusableReply($next),
```

Edit 6. Before:
```php
    /**
     * Records the prompt, runs the one-shot hook, and returns the next queued
     * response — a string answer or the failure to surface. Shared by both
     * read methods so they record and dequeue identically.
     */
    private function answer(CompletionRequestModel $request): string|\RuntimeException
    {
```
After:
```php
    /**
     * Records the prompt, runs the one-shot hook, and returns the next queued
     * response — a string answer, a streamed reply or the failure to surface.
     * Shared by both read methods so they record and dequeue identically.
     */
    private function answer(CompletionRequestModel $request): string|\RuntimeException|CompletionStreamProgressModel
    {
```

Edit 7. Before:
```php
        return array_shift($this->queue);
    }
}
```
After:
```php
        return array_shift($this->queue);
    }

    private function reportAndAnswer(
        CompletionStreamObserverInterface $observer,
        CompletionStreamProgressModel $lastReport,
    ): string {
        $observer->streamProgressed($lastReport);

        return $lastReport->answerSoFar;
    }
}
```

- [ ] **Step 4: Nothing changed behaviour (from `backend/`)**

```bash
php bin/phpunit --filter '(ConsolidationOutcomeModelTest|RecommendationConsolidationResolverTest|RecommendationRunAdvancerTest|RecommendationPipelineTest)'
bin/console cache:warmup && vendor/bin/phpstan analyse --memory-limit=512M src/Service/Recommendation/Run tests/Support/StubChatClient.php tests/Service/Recommendation/Run
```
Expected: `OK`; PHPStan `[OK] No errors`.

- [ ] **Step 5: Per-task gates and commits (from `backend/`)**

```bash
php -l src/Service/Recommendation/Run/Model/ConsolidationOutcomeModel.php
php -l src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php
php -l tests/Support/StubChatClient.php
vendor/bin/phpcs src/Service/Recommendation/Run/Model/ConsolidationOutcomeModel.php src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php tests/Service/Recommendation/Run/Model/ConsolidationOutcomeModelTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php tests/Support/StubChatClient.php
git add src/Service/Recommendation/Run/Model/ConsolidationOutcomeModel.php src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php tests/Service/Recommendation/Run/Model/ConsolidationOutcomeModelTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php
git commit -m "refactor(#1223): the consolidation fallback is a ranking, not always the pool"
git add tests/Support/StubChatClient.php
git commit -m "test(#1223): the stub chat client can stream a reply's last report"
```

---

### Task 5: A consolidation the provider keeps cutting degrades to the picks it finished

**Files:**
- Modify: `backend/src/Service/Recommendation/Run/RecommendationConsolidationResolver.php`
- Modify: `backend/src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php`
- Modify: `backend/tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`
- Modify: `backend/tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php`

- [ ] **Step 1: The resolver tests**

`backend/tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php`, edit 1. Before:
```php
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
```
After:
```php
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Completion\Model\CompletionStreamProgressModel;
use App\Service\Ai\Crypto\ApiKeyCipher;
```

Edit 2. Before (after Task 4's rename):
```php
        self::assertSame('not json', $outcome->requireUnusableReply());
        self::assertSame(['', ''], array_map(
            static fn (array $pick): string => $pick['reason'],
            $outcome->requireFallbackRanking(),
        ));
    }

    public function testAUsableReplySettlesItsCallAsUsable(): void
```
After:
```php
        self::assertSame('not json', $outcome->requireUnusableReply());
        self::assertSame(['', ''], array_map(
            static fn (array $pick): string => $pick['reason'],
            $outcome->requireFallbackRanking(),
        ));
    }

    public function testAReplyTheProviderCutFallsBackToTheRecommendationsItFinished(): void
    {
        [$firstEntry, $secondEntry, $thirdEntry] = $this->fixtures->seedFeedWithEntries($this->user, 3);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);
        $thirdId = $this->idOf($thirdEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 500, 'reason' => ''],
            ['id' => $thirdId, 'score' => 300, 'reason' => ''],
        ]);

        $cutReply = sprintf(
            '{"recommendations": [{"id": %d, "score": 410, "reason": "Weaker."}, '
            . '{"id": %d, "score": 880, "reason": "Stronger."}, {"id": %d, "score": 6',
            $firstId,
            $secondId,
            $thirdId,
        );
        $this->stubChatClient()->queueStreamedReply(new CompletionStreamProgressModel($cutReply, 100, 'error'));

        $outcome = $this->resolveConsolidation($run);

        self::assertFalse($outcome->usable);
        self::assertSame($cutReply, $outcome->requireUnusableReply());
        self::assertSame(
            [
                ['id' => $secondId, 'score' => 880, 'reason' => 'Stronger.'],
                ['id' => $firstId, 'score' => 410, 'reason' => 'Weaker.'],
            ],
            $outcome->requireFallbackRanking(),
        );
    }

    /** `length` is the call's own ceiling, not the provider's cut: such a reply keeps the batch-score fallback. */
    public function testAReplyCutByTheTokenCeilingFallsBackToTheBatchScorePool(): void
    {
        [$firstEntry, $secondEntry] = $this->fixtures->seedFeedWithEntries($this->user, 2);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 400, 'reason' => ''],
        ]);

        $this->stubChatClient()->queueStreamedReply(new CompletionStreamProgressModel(
            sprintf(
                '{"recommendations": [{"id": %d, "score": 910, "reason": "Finished."}, {"id": %d, "sco',
                $firstId,
                $secondId,
            ),
            100,
            'length',
        ));

        $outcome = $this->resolveConsolidation($run);

        self::assertSame(
            [['id' => $firstId, 'score' => 700, 'reason' => ''], ['id' => $secondId, 'score' => 400, 'reason' => '']],
            $outcome->requireFallbackRanking(),
        );
    }

    public function testAReplyCutBeforeItsFirstRecommendationFallsBackToTheBatchScorePool(): void
    {
        [$firstEntry, $secondEntry] = $this->fixtures->seedFeedWithEntries($this->user, 2);
        $firstId = $this->idOf($firstEntry);
        $secondId = $this->idOf($secondEntry);

        $run = $this->runWithWinners([
            ['id' => $firstId, 'score' => 700, 'reason' => ''],
            ['id' => $secondId, 'score' => 400, 'reason' => ''],
        ]);

        $this->stubChatClient()->queueStreamedReply(new CompletionStreamProgressModel(
            sprintf('{"recommendations": [{"id": %d, "score": 9', $firstId),
            100,
            'error',
        ));

        $outcome = $this->resolveConsolidation($run);

        self::assertSame(
            [['id' => $firstId, 'score' => 700, 'reason' => ''], ['id' => $secondId, 'score' => 400, 'reason' => '']],
            $outcome->requireFallbackRanking(),
        );
    }

    public function testAUsableReplySettlesItsCallAsUsable(): void
```

- [ ] **Step 2: The run-level test**

`backend/tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php`, edit 1. Before:
```php
use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use App\Service\Ai\Completion\Model\Reasoning;
```
After:
```php
use App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface;
use App\Service\Ai\Completion\Model\CompletionStreamProgressModel;
use App\Service\Ai\Completion\Model\Reasoning;
```

Edit 2. Before:
```php
        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    /**
     * The degrade ending still owes the reader the list size they asked for:
```
After:
```php
        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }

    public function testConsolidationRepliesTheProviderKeepsCuttingCompleteTheRunWithTheFinishedPicks(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startSnapshotAndDistill();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 70, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $cutReply = new CompletionStreamProgressModel(
            sprintf(
                '{"recommendations": [{"id": %d, "score": 640, "reason": "Finished."}, {"id": %d, "sc',
                $firstBatch[0],
                $secondBatch[0],
            ),
            100,
            'error',
        );
        for ($attempt = 1; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->stubChatClient()->queueStreamedReply($cutReply);
            self::assertSame('running', $this->advancer()->advance($this->user)->status);
        }

        $this->stubChatClient()->queueStreamedReply($cutReply);
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertNull($report->error);

        $this->entityManager->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
        self::assertSame('Finished.', $items[0]->getReason());
        self::assertSame(640, $items[0]->getScore());
    }

    /**
     * The degrade ending still owes the reader the list size they asked for:
```

- [ ] **Step 3: The new behaviour fails, the two guards pass (from `backend/`)**

```bash
php bin/phpunit --filter '(testAReplyTheProviderCutFallsBackToTheRecommendationsItFinished|testAReplyCutByTheTokenCeilingFallsBackToTheBatchScorePool|testAReplyCutBeforeItsFirstRecommendationFallsBackToTheBatchScorePool|testConsolidationRepliesTheProviderKeepsCuttingCompleteTheRunWithTheFinishedPicks)'
```
Expected: 2 failures, both `Failed asserting that two arrays are identical.`: `testAReplyTheProviderCutFallsBackToTheRecommendationsItFinished` (the fallback is the three-entry pool) and `testConsolidationRepliesTheProviderKeepsCuttingCompleteTheRunWithTheFinishedPicks` (the items are `[$secondBatch[0], $firstBatch[0]]`). The token-ceiling and cut-before-first tests pass already: they pin today's pool fallback, which the fix must keep.

- [ ] **Step 4: The resolver salvages a cut reply for the degraded ending**

`backend/src/Service/Recommendation/Run/RecommendationConsolidationResolver.php`, edit 1. Before:
```php
/**
 * The consolidation phase's one provider call: re-score, reason and dedupe the top of the pool in one pass. A pool
 * pruned to nothing finalizes free; an unusable reply comes back with the batch-score pool to degrade to.
 */
```
After:
```php
/**
 * The consolidation phase's one provider call: re-score, reason and dedupe the top of the pool in one pass. A pool
 * pruned to nothing finalizes free; an unusable reply comes back with the ranking to degrade to: what a reply the
 * provider cut short finished, else the batch-score pool.
 */
```

Edit 2. Before:
```php
        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ConsolidationOutcomeModel::unusable($content, $pool);
        }
```
After:
```php
        $result = $this->consolidationParser->parse($content, array_column($pool, 'id'));
        if (!$result->usable) {
            $recordedCall->finishUnusable($content);
            $this->checkpoint->guard($run);

            return ConsolidationOutcomeModel::unusable(
                $content,
                $recordedCall->providerCutTheAnswer() ? $this->salvagedRankingOrPool($content, $pool) : $pool,
            );
        }
```

Edit 3. Before:
```php
            static fn (array $winner): bool => isset($linesById[$winner['id']]),
        ));
    }

    /**
     * Exactly the entries the reply scored, minus its named duplicates, best first: consolidation is the sole
```
After:
```php
            static fn (array $winner): bool => isset($linesById[$winner['id']]),
        ));
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $pool
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private function salvagedRankingOrPool(string $content, array $pool): array
    {
        $salvaged = $this->consolidationParser->parseCutReply($content, array_column($pool, 'id'));

        return $salvaged->usable ? self::rankedFromReply($salvaged) : $pool;
    }

    /**
     * Exactly the entries the reply scored, minus its named duplicates, best first: consolidation is the sole
```

`backend/src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php`. Before:
```php
/** Finalizes the consolidated list; an unusable reply is retried, then the run completes with the batch-score pool. */
```
After:
```php
/** Finalizes the consolidated list; an unusable reply is retried, then the run completes with its fallback ranking. */
```

- [ ] **Step 5: They pass (from `backend/`)**

```bash
php bin/phpunit --filter '(RecommendationConsolidationResolverTest|RecommendationRunAdvancerTest|RecommendationPipelineTest|ConsolidationOutcomeModelTest)'
```
Expected: `OK`.

- [ ] **Step 6: Deletion checks (from `backend/`, restoring each with the Edit tool)**

(a) In the resolver, replace `$recordedCall->providerCutTheAnswer() ? $this->salvagedRankingOrPool($content, $pool) : $pool,` with `$pool,`. Run the Step 3 filter. Expected FAIL: the same two tests as in Step 3, `Failed asserting that two arrays are identical.` Restore.

(b) Replace it with `$this->salvagedRankingOrPool($content, $pool),`. Run `php bin/phpunit --filter testAReplyCutByTheTokenCeilingFallsBackToTheBatchScorePool`. Expected FAIL: `Failed asserting that two arrays are identical.` (the fallback is the one finished pick). Restore.

(c) In `salvagedRankingOrPool()`, replace `return $salvaged->usable ? self::rankedFromReply($salvaged) : $pool;` with `return self::rankedFromReply($salvaged);`. Run `php bin/phpunit --filter testAReplyCutBeforeItsFirstRecommendationFallsBackToTheBatchScorePool`. Expected FAIL: `Failed asserting that two arrays are identical.` (the fallback is `[]`). Restore, and rerun Step 5.

- [ ] **Step 7: Per-task gates and commit (from `backend/`)**

```bash
php -l src/Service/Recommendation/Run/RecommendationConsolidationResolver.php
php -l src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php
vendor/bin/phpcs src/Service/Recommendation/Run/RecommendationConsolidationResolver.php src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php
git add src/Service/Recommendation/Run/RecommendationConsolidationResolver.php src/Service/Recommendation/Run/ProviderPhase/ConsolidationPhase.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php
git commit -m "fix(#1223): a consolidation the provider keeps cutting degrades to the picks it finished"
```

---

### Task 6: Replay the seven recorded cuts through the parser (no commit)

**Files:**
- Create (git-ignored): `backend/var/1223/replay.php`

- [ ] **Step 1: The replay script**

Skip this task if Task 0 Step 4 recorded "Replay: rows purged". Create `backend/var/1223/replay.php`:

```php
<?php

declare(strict_types=1);

use App\Service\Recommendation\Prompt\ModelReplyJsonDecoder;
use App\Service\Recommendation\Prompt\PlausibleDuplicateShare;
use App\Service\Recommendation\Prompt\RecommendationConsolidationParser;
use App\Service\Recommendation\Prompt\RecommendationPickSalvager;

require __DIR__ . '/../../vendor/autoload.php';

$parser = new RecommendationConsolidationParser(
    new ModelReplyJsonDecoder(),
    new RecommendationPickSalvager(),
    new PlausibleDuplicateShare(),
);

foreach ([1934, 1935, 1936, 1954, 1955, 1956, 1962] as $logId) {
    $request = json_decode(
        (string) file_get_contents(__DIR__ . "/request_body-$logId.txt"),
        true,
        512,
        \JSON_THROW_ON_ERROR,
    );
    $userMessages = array_values(array_filter(
        $request['messages'],
        static fn (array $message): bool => 'user' === $message['role'],
    ));
    preg_match_all('/^- \[(\d+)\]/m', $userMessages[0]['content'], $matches);
    $shownIds = array_map('intval', $matches[1]);
    $reply = (string) file_get_contents(__DIR__ . "/response_text-$logId.txt");

    $whole = $parser->parse($reply, $shownIds);
    $cut = $parser->parseCutReply($reply, $shownIds);
    $lastPick = $cut->picks[array_key_last($cut->picks)] ?? null;

    printf(
        "%d shown=%d whole=%s salvaged=%d last=%d reasons=%d\n",
        $logId,
        count($shownIds),
        $whole->usable ? 'usable' : 'unusable',
        count($cut->picks),
        $lastPick?->entryId ?? 0,
        count(array_filter($cut->picks, static fn ($pick): bool => '' !== $pick->reason)),
    );
}
```

- [ ] **Step 2: Run it (from `backend/`)**

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all php -d display_errors=stderr var/1223/replay.php
```
Expected, exactly (counted from the rows while this plan was written):

```
1934 shown=300 whole=unusable salvaged=238 last=549699 reasons=238
1935 shown=300 whole=unusable salvaged=238 last=549699 reasons=238
1936 shown=300 whole=unusable salvaged=238 last=549699 reasons=238
1954 shown=300 whole=unusable salvaged=230 last=549575 reasons=230
1955 shown=300 whole=unusable salvaged=229 last=549575 reasons=229
1956 shown=300 whole=unusable salvaged=229 last=549475 reasons=229
1962 shown=300 whole=unusable salvaged=125 last=549675 reasons=125
```
`1955` holds 230 complete objects, and the salvager drops one of them as a repeated or unshown id. Any other difference is a finding: stop and report the line. Quote the output in the task report and in the PR body.

---

### Task 7: PR gates

- [ ] **Step 1: Backend gates (from `backend/`)**

These may run in parallel, except `composer infection:diff`, which runs after the native leg:

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
composer test:parallel
docker compose exec php printenv APP_CACHE_DIR
docker compose exec php composer test
composer infection:diff
```
Expected: each passes. `printenv` prints `/app/var/cache-docker`. If it prints nothing, run `docker compose up -d php` and `docker compose restart nginx` first. If only `composer tramp` fails, run `composer show larspohlmann/phptramp` before looking at the code (CLAUDE.md: CI runs phptramp's `develop` tip). An escaped mutant on a touched line gets a killing test in the task that owns the line, never an `ignore`. Run every command in the foreground, one per Bash call, with a Bash timeout of up to 600000 ms. No background shells, and no `until`/`sleep` loops.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every changed PHP file (`git diff --name-only origin/develop -- '*.php'` from the repository root). ERROR and WARNING block; weak warnings are advisory.

- [ ] **Step 3: The dev log (from the repository root)**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq -c 'select(.level >= 300)'
```
Expected: nothing from `App\Service\Recommendation` or `App\Service\Ai`.

---

### Task 8: Independent review, then /simplify

- [ ] **Step 1: One independent reviewer (opus)**

Dispatch one fresh reviewer with the Agent tool (`subagent_type: general-purpose`, `model: opus`). Its prompt:

> Review branch `fix/1223-consolidation-abort` against `origin/develop` in `/Users/lars/Documents/work/eigenes/simple-feed-reader` (read-only: no commits, no checkout, no stash, no file edits outside your scratchpad). The plan is `docs/superpowers/plans/2026-09-30-1223-consolidation-abort.md`. Run each command in the foreground, one at a time, with a Bash timeout of up to 600000 ms. Never start background shells, `until`/`sleep` loops or pollers. Check adversarially: (1) Decisions D-1 to D-7 hold in the code. The retry model is unchanged (three attempts through `InvalidReplyRetry`). Salvage applies only to a reply whose finish reason is `error` or `content_filter`, only at the degraded ending, and only when it finished at least one shown recommendation. Every other unusable reply still ends on the batch-score pool. (2) `ModelReplyJsonDecoder::completeObjectsFrom()` behaves exactly as the old `lastEmbeddedObject()` walk for `decode()`: walk both by hand over every existing `ModelReplyJsonDecoderTest` input. (3) `completeItemsOf()` cannot return an object from outside the named array on a truncated reply, and a brace or bracket inside a string never misleads it. (4) CLAUDE.md: names, `final readonly`, §10 roles (`Support/` shape), comments (default none, three lines at most), no boolean flag parameters, no null signalling added, PHPMD, phptramp. (5) The tests: re-run at least one deletion check from each of Tasks 1, 2, 3 and 5, restoring with the Edit tool (never `git checkout --`), and quote each FAIL. Report findings as blocking or non-blocking, each with `path:line` and the fix.

Fix every blocking finding in the task that owns it, as a new commit `fix(#1223): …` with its own test and deletion check, then rerun Task 7. Put a non-blocking finding you do not fix into the PR body with the reason.

- [ ] **Step 2: /simplify**

Invoke the `simplify` skill on the branch diff (`git diff origin/develop`). Apply a finding only if it keeps every CLAUDE.md rule and every Decision. Commit applied changes as `refactor(#1223): …`, then rerun Task 7. List the findings you declined, each with its reason, in the task report.

---

### Task 9: A real recommendation run on the DEV stack

The run spends real provider credit on the account that ran the last recommendation run. That is the verification the memory "verify recommendation work with a real run" requires. Everything below runs from the repository root; every SQL statement is a read-only SELECT.

- [ ] **Step 1: The containers serve this branch**

```bash
git branch --show-current
docker compose ps --status running --services
docker compose exec -T php grep -c 'providerCutTheAnswer' src/Service/Recommendation/Run/Pass/RecordedCall.php
docker compose exec -T php bin/console cache:clear
```
Expected: `fix/1223-consolidation-abort`; the list includes `php`, `mysql` and `nginx`; `1` (the bind mount serves this checkout); `[OK] Cache for the "dev" environment (debug=true) was successfully cleared.` If `worker` is in the list, run `docker compose restart worker`: the daemon keeps the code it loaded at boot. If it is not in the list, leave it stopped. Another session may have stopped it on purpose, and Step 3 drives the run without it.

- [ ] **Step 2: Start a run through the API**

```bash
EMAIL=$(docker compose exec -T mysql mysql -N -ufeedreader -pfeedreader feedreader -e "SELECT u.email FROM app_user u JOIN recommendation_run r ON r.user_id = u.id ORDER BY r.id DESC LIMIT 1" 2>/dev/null) && TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token "$EMAIL" --ttl=900 | grep -E '^ey') && curl -sk -X POST https://localhost:8443/api/recommendations/runs -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | jq '{status, batchesTotal, batchesDone, error}'
docker compose exec -T mysql mysql -ufeedreader -pfeedreader feedreader -e "SELECT id, status, created_at FROM recommendation_run ORDER BY id DESC LIMIT 1" 2>/dev/null
```
Expected: `"status": "running"` and `"error": null`, then one row with a new id (`RUN_ID` below) and status `running`. Neither the email nor the token is printed. Issuing the token stamps the account's last sign-in (`StampLastLoginOnTokenIssueListener`), the one write besides the run itself. A `429` means the start limiter is spent: wait for it and retry once, never loop.

- [ ] **Step 3: Drive the run to its end in the foreground**

```bash
docker compose exec -T php bin/console app:recommendations:drain
docker compose exec -T mysql mysql -ufeedreader -pfeedreader feedreader -e "SELECT id, status FROM recommendation_run WHERE id = RUN_ID" 2>/dev/null
```
Run the drain with a Bash timeout of 600000 ms. It returns when no run is active (a run took 4–7 minutes on this stack). If the status is still `running`, a drainer spawned by the start request or the worker holds the drive. Then arm one `Monitor` (timeout 1800000 ms) that runs `docker compose exec -T mysql mysql -N -ufeedreader -pfeedreader feedreader -e "SELECT status FROM recommendation_run WHERE id = RUN_ID"` every 20 seconds and emits one line and exits when the value is not `running`. Do not write any other wait loop.

- [ ] **Step 4: Read the run and its log**

```bash
docker compose exec -T mysql mysql -ufeedreader -pfeedreader feedreader -e "SELECT id, status, batches_done, JSON_LENGTH(candidate_batches) + 2 AS expected_total, attempts, transport_failures, LEFT(error, 200) AS error FROM recommendation_run WHERE id = RUN_ID" 2>/dev/null
docker compose exec -T mysql mysql -ufeedreader -pfeedreader feedreader -e "SELECT id, phase, batch_number, attempt, verdict, finish_reason, LENGTH(response_text) AS reply_bytes, wire_bytes, LEFT(error_detail, 120) AS error_detail FROM recommendation_run_log WHERE run_id = RUN_ID ORDER BY id" 2>/dev/null
docker compose exec -T mysql mysql -ufeedreader -pfeedreader feedreader -e "SELECT COUNT(*) AS items, SUM(reason <> '') AS with_reason, MIN(score) AS lowest, MAX(score) AS highest FROM recommendation_item WHERE recommendation_run_id = RUN_ID" 2>/dev/null
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 300 | jq -c 'select(.level >= 300)'
```
Expected:
- `status = completed`, `batches_done = expected_total`, `attempts = 0`, `transport_failures = 0`, `error = NULL`.
- One `distill` row, one row per batch, and one `consolidate` row, each `usable` with `finish_reason = stop`.
- `items` up to the account's picks limit (50), with `with_reason = items`.
- Nothing from the recommendation or Ai services at warning level or above.

A retry row (`attempt` 2 or 3) is a warning even when the run completes: report it. If the `consolidate` rows end in `error` three times, the run is the live proof of the salvage. The run must still be `completed`, with `with_reason = items` and `items` no more than the finished recommendations. Report that as the headline. Any other ending is a finding: stop and report the three outputs.

- [ ] **Step 5: State the limit**

The report says: "A live provider cannot be made to cut a reply on demand. The happy path ran end to end on the dev stack (run RUN_ID). The salvage path is proven by the Task 5 tests and by Task 6's replay of the seven recorded cuts."

---

### Task 10: PR, merge when green, close, report

- [ ] **Step 1: Push and open the PR (from the repository root)**

Write the body to the scratchpad as `pr-1223.md`:

```markdown
## What

When every consolidation attempt comes back cut short by the provider (`finish_reason` `error` or `content_filter`), the run now completes with the recommendations the last reply finished, with the consolidation's own scores and reasons, instead of the batch-score list with empty reasons.

- `CompletionFinishReason::cutByProvider()` (Ai `Support/`) names the provider cuts; `length` is the call's own ceiling and is not one.
- `RecordedCall::providerCutTheAnswer()` answers from the finish reason it already keeps for the run log.
- `ModelReplyJsonDecoder::completeItemsOf()` recovers the complete objects of an array a reply broke off inside. The brace walk behind `decode()` is now shared, not copied.
- `RecommendationConsolidationParser::parseCutReply()` runs them through the existing pick salvager. A cut reply names no duplicates: the schema puts them last.
- `RecommendationConsolidationResolver` hands the degraded ending the salvaged ranking when the provider cut the reply and it finished at least one recommendation. Otherwise it hands the batch-score pool, as before. Retries are unchanged (three attempts).
- `ConsolidationOutcomeModel::requireFallbackPool()` is now `requireFallbackRanking()`.

## Why this degradation

On the dev stack, runs 132 and 135 were not failed. They completed on the batch-score pool: 50 items, all reasons empty. The six cut replies hold 229–238 of 300 finished items, cut at entry 549575 every time. Replaying the clean runs 133, 134 and 136 shows what each option keeps of the true top 50. A cut at item 230 keeps 41–49 of them, a cut at 125 keeps 29–32, and the batch-score pool keeps 16–19. Salvage waits for the retries because 1 of 5 retries after a cut returned the full answer. Dropping the entry at the cut and retrying was rejected: in run 136 the same entry passed on the retry, and carrying the exclusion across ticks needs new persisted state.

What the reader sees: a full feed when a retry succeeds; otherwise the finished items with their reasons, best first, without duplicate removal (the list that names duplicates never arrived); the batch-score list only when no item finished.

## Verification

- Replay of the seven recorded cuts through `parseCutReply()`: <Task 6 output>.
- Real run on the dev stack: run <RUN_ID>, <status, batches, attempts, transport failures, items/with_reason>.
- A live provider cannot be made to cut on demand, so the salvage path itself is proven by the tests and the replay.

## Gates

`composer cs`, `composer stan`, `composer md`, `composer tramp`, `composer test:parallel` (SQLite), `composer test` (MySQL), `composer infection:diff`, PhpStorm inspections: all green. Independent review: <summary>. /simplify: <summary>.

Follow-up offered, not done here: record the provider's mid-stream `error.message` in the run log's `error_detail`.

Closes #1223
```

Fill in every `<…>` from Tasks 6–9 before creating the PR. The body must end with `Closes #1223`.

```bash
git push -u origin fix/1223-consolidation-abort && gh pr create --repo larspohlmann/simple-feed-reader --base develop --head fix/1223-consolidation-abort --title "fix(#1223): a consolidation the provider keeps cutting degrades to the picks it finished" --body-file <scratchpad>/pr-1223.md
```

- [ ] **Step 2: The PR links the issue (checked once)**

```bash
gh pr view <PR> --repo larspohlmann/simple-feed-reader --json closingIssuesReferences --jq '.closingIssuesReferences[].number'
```
Expected: `1223`. If it prints nothing, re-save the body once with `gh pr edit <PR> --repo larspohlmann/simple-feed-reader --body-file <scratchpad>/pr-1223.md` and check once more. If it is still empty, report it to the planner and do not merge.

- [ ] **Step 3: Merge when CI is green**

Arm one `Monitor` (timeout 1800000 ms, re-armed once on expiry) that polls `gh pr checks <PR> --repo larspohlmann/simple-feed-reader --json name,bucket` every 30 seconds. It emits one line and exits when no check is pending, reporting GREEN or the failing check names. On a failure, fix it in the task that owns the code, push, and re-arm. On GREEN:

```bash
gh pr merge <PR> --repo larspohlmann/simple-feed-reader --merge
gh pr view <PR> --repo larspohlmann/simple-feed-reader --json mergeCommit --jq .mergeCommit.oid
gh issue view 1223 --repo larspohlmann/simple-feed-reader --json state,stateReason --jq '.state + " " + .stateReason'
```
Never use `--auto`. Expected: the merge SHA, then `CLOSED COMPLETED`. Only if the issue is still open, close it by hand:

```bash
gh issue close 1223 --repo larspohlmann/simple-feed-reader --reason completed --comment "Fixed by #<PR>, merged as <merge SHA>."
```

- [ ] **Step 4: Report to the planner**

Report the PR URL and merge SHA, the issue state, Task 6's replay output, Task 9's run (id, status, batches, attempts, transport failures, items and `with_reason`, and any retry row), the review's findings and how each was handled, and the declined /simplify findings. Offer Q-1 as a follow-up issue: "Record the provider's mid-stream `error.message` in `recommendation_run_log.error_detail`".
