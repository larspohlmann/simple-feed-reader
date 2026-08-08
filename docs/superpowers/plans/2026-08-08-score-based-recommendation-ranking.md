# Score-Based Recommendation Ranking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The final recommendation list becomes the best `picksLimit` entries over the *total* candidate pool: batches score every candidate on an anchored 0–100 scale, code ranks the pooled scores globally, and the merge call shrinks to naming duplicate stories.

**Architecture:** The run state machine (snapshot → batch calls → one extra call → finalize) is untouched; what changes is the meaning of each step. Batch replies carry `{id, score, reason}` instead of an ordered shortlist. A new pure `RecommendationWinnerRanker` flattens, sorts and cuts the pooled winners in code. The merge phase becomes a dedup phase: it shows the score-ordered top `2 × picksLimit` lines and parses back only ids to drop. An exhausted dedup phase completes undeduped instead of failing.

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit (SQLite natively, MySQL via Docker), PHPStan level max, PHPMD codesize, Infection.

**Spec:** `docs/superpowers/specs/2026-08-08-score-based-recommendation-ranking-design.md` (issue #316).

## Global Constraints

- Branch: `feature/316-score-based-recommendation-ranking` (exists; spec committed).
- All backend commands run from `backend/`.
- Every file: `declare(strict_types=1);`, PSR-12 (`composer cs:fix` autofixes).
- House style: `final readonly class`, constructor promotion, intent-revealing names, guard clauses, no boolean flag parameters.
- Every touched `src` file must be PHPMD-clean (`composer md`), not merely free of new findings.
- PHPStan level max needs a warm dev cache: `bin/console cache:warmup` before `composer stan`.
- PHP 8.4 `new Foo()->bar()` chains must be written `(new Foo())->bar()`.
- Array shapes: batch winners are `array{id: int, score: int, reason: string}`; the entity's *stored* winners are `array{id: int, score?: int, reason: string}` (rows written before this change lack `score`).
- The suite must be green at the end of every task. Run the SQLite leg with `php bin/phpunit`.
- Commit messages follow the repo pattern: `feat(#316): …`, `refactor(#316): …`, `test(#316): …`.

---

### Task 1: Extract `ModelReplyJsonDecoder`

The pick parser's code-fence stripping moves into its own class so the new duplicate parser (Task 6) can share it instead of copying it.

**Files:**
- Create: `backend/src/Service/Recommendation/ModelReplyJsonDecoder.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPickParser.php`
- Test: `backend/tests/Service/Recommendation/ModelReplyJsonDecoderTest.php`
- Modify: `backend/tests/Service/Recommendation/RecommendationPickParserTest.php` (setUp only)

**Interfaces:**
- Consumes: nothing new.
- Produces: `ModelReplyJsonDecoder::decode(string $content): ?array` — strips a surrounding code fence, JSON-decodes, returns the array or `null`. `RecommendationPickParser` gains constructor `__construct(private ModelReplyJsonDecoder $decoder)`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Recommendation/ModelReplyJsonDecoderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use PHPUnit\Framework\TestCase;

final class ModelReplyJsonDecoderTest extends TestCase
{
    private ModelReplyJsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new ModelReplyJsonDecoder();
    }

    public function testPlainJsonObjectDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode('{"a": 1}'));
    }

    public function testFencedJsonWithLanguageTagDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```json\n{\"a\": 1}\n```"));
    }

    public function testFencedJsonWithoutLanguageTagDecodes(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```\n{\"a\": 1}\n```"));
    }

    public function testSurroundingWhitespaceIsTrimmedBeforeFenceDetection(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("  \n```json\n{\"a\": 1}\n```\n  "));
    }

    public function testFenceClosingImmediatelyAfterTheJsonIsStripped(): void
    {
        self::assertSame(['a' => 1], $this->decoder->decode("```json\n{\"a\": 1}```"));
    }

    public function testClosingFenceWithoutAnOpeningFenceIsNotStripped(): void
    {
        self::assertNull($this->decoder->decode('{"a": 1}```'));
    }

    public function testNonJsonReturnsNull(): void
    {
        self::assertNull($this->decoder->decode('not json'));
    }

    public function testScalarJsonReturnsNull(): void
    {
        self::assertNull($this->decoder->decode('42'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit --filter ModelReplyJsonDecoderTest`
Expected: ERROR — `Class "App\Service\Recommendation\ModelReplyJsonDecoder" not found`.

- [ ] **Step 3: Create the decoder by moving the fence code**

Create `backend/src/Service/Recommendation/ModelReplyJsonDecoder.php`. `stripCodeFence` moves **verbatim** from `RecommendationPickParser` (delete it there in the next step):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Decodes one raw assistant reply into a JSON array, tolerating the code
 * fence some models wrap around JSON output. Shared by the pick and
 * duplicate parsers so the fence handling exists exactly once.
 */
final readonly class ModelReplyJsonDecoder
{
    /**
     * @return array<mixed>|null null when the reply is not a JSON object or array
     */
    public function decode(string $content): ?array
    {
        $decoded = json_decode($this->stripCodeFence($content), true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function stripCodeFence(string $content): string
    {
        $trimmed = trim($content);

        if (!str_starts_with($trimmed, '```') || !str_ends_with($trimmed, '```')) {
            return $trimmed;
        }

        $withoutClosingFence = substr($trimmed, 0, -3);
        $firstLineEnd = strpos($withoutClosingFence, "\n");

        if (false === $firstLineEnd) {
            return $withoutClosingFence;
        }

        return substr($withoutClosingFence, $firstLineEnd + 1);
    }
}
```

- [ ] **Step 4: Make the pick parser delegate**

In `backend/src/Service/Recommendation/RecommendationPickParser.php`:

1. Add a constructor and replace the top of `parse()`:

```php
    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    /** @param list<int> $validIds */
    public function parse(string $content, array $validIds, int $limit): PickParseResult
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return PickParseResult::unusable();
        }

        $entries = $decoded['recommendations'] ?? null;
        // … rest of the method unchanged
```

2. Delete the private `stripCodeFence` method.

3. In `RecommendationPickParserTest::setUp()`, construct with the decoder:

```php
        $this->parser = new RecommendationPickParser(new ModelReplyJsonDecoder());
```

(add `use App\Service\Recommendation\ModelReplyJsonDecoder;`).

- [ ] **Step 5: Run the affected suites**

Run: `php bin/phpunit --filter 'ModelReplyJsonDecoderTest|RecommendationPickParserTest'`
Expected: PASS (Symfony autowires the new class for the container-driven tests — no service config needed).

- [ ] **Step 6: Full suite, then commit**

Run: `php bin/phpunit`
Expected: PASS.

```bash
git add src/Service/Recommendation/ModelReplyJsonDecoder.php src/Service/Recommendation/RecommendationPickParser.php tests/Service/Recommendation/ModelReplyJsonDecoderTest.php tests/Service/Recommendation/RecommendationPickParserTest.php
git commit -m "refactor(#316): extract the code-fence JSON decoding from the pick parser"
```

---

### Task 2: Scores in the pick parser

`RecommendationPick` gains `score`; the parser salvages it strictly (a scoreless pick is invalid). Every test that queues a model reply must gain scores, because the old shape no longer parses.

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationPick.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPickParser.php`
- Test: `backend/tests/Service/Recommendation/RecommendationPickParserTest.php`
- Modify (payloads only): `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`, `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`, `backend/tests/Controller/Api/RecommendationRunControllerTest.php`

**Interfaces:**
- Consumes: `ModelReplyJsonDecoder` from Task 1.
- Produces: `RecommendationPick` is `{public int $entryId, public int $score, public string $reason}` (constructor in that order). Salvage rule: `score` accepts int, float, or numeric string; it is rounded to the nearest int and clamped into `[0, 100]`; a missing or non-numeric score discards the pick. Task 8 relies on `$pick->score`.

- [ ] **Step 1: Write the failing tests**

Add to `RecommendationPickParserTest`:

```php
    public function testScoresAreSalvagedAndPreserved(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 90, 'reason' => 'First'],
                ['id' => 2, 'score' => 15, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2], 10);

        self::assertTrue($result->usable);
        self::assertSame([90, 15], array_map(static fn ($pick) => $pick->score, $result->picks));
    }

    public function testFloatAndNumericStringScoresRoundToInt(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 87.5, 'reason' => 'Float'],
                ['id' => 2, 'score' => '73', 'reason' => 'String'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2], 10);

        self::assertTrue($result->usable);
        self::assertSame([88, 73], array_map(static fn ($pick) => $pick->score, $result->picks));
    }

    public function testOutOfRangeScoresAreClampedIntoTheScale(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 150, 'reason' => 'Too high'],
                ['id' => 2, 'score' => -5, 'reason' => 'Too low'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2], 10);

        self::assertTrue($result->usable);
        self::assertSame([100, 0], array_map(static fn ($pick) => $pick->score, $result->picks));
    }

    public function testAPickWithoutAScoreIsDiscarded(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'Scoreless'],
                ['id' => 2, 'score' => 40, 'reason' => 'Scored'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2], 10);

        self::assertTrue($result->usable);
        self::assertSame([2], array_map(static fn ($pick) => $pick->entryId, $result->picks));
    }

    public function testANonNumericScoreDiscardsThePick(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'score' => 'high', 'reason' => 'Wordy'],
            ],
        ]);

        $result = $this->parser->parse($content, [1], 10);

        self::assertFalse($result->usable);
    }

    public function testAReplyInTheOldScorelessShapeIsUnusable(): void
    {
        $content = self::encode([
            'recommendations' => [
                ['id' => 1, 'reason' => 'First'],
                ['id' => 2, 'reason' => 'Second'],
            ],
        ]);

        $result = $this->parser->parse($content, [1, 2], 10);

        self::assertFalse($result->usable);
    }
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `php bin/phpunit --filter RecommendationPickParserTest`
Expected: the six new tests FAIL (no `score` property / picks not discarded); old tests still pass.

- [ ] **Step 3: Implement**

`RecommendationPick.php` (whole class body):

```php
/**
 * One entry the model scored, with the reason it gave. The reason is never
 * validated beyond "is it a non-blank string" — a bad reason is not worth
 * discarding an otherwise-valid pick over. The score is stricter: without a
 * numeric score the pick cannot take part in the cross-batch ranking, so
 * the parser discards it.
 */
final readonly class RecommendationPick
{
    public function __construct(
        public int $entryId,
        public int $score,
        public string $reason,
    ) {
    }
}
```

In `RecommendationPickParser::salvagePick()`, salvage the score between the id and reason:

```php
    private function salvagePick(mixed $entry, array $validIds, array $seenIds): ?RecommendationPick
    {
        if (!\is_array($entry)) {
            return null;
        }

        $entryId = $this->salvageEntryId($entry['id'] ?? null, $validIds);

        if (null === $entryId || isset($seenIds[$entryId])) {
            return null;
        }

        $score = $this->salvageScore($entry['score'] ?? null);

        if (null === $score) {
            return null;
        }

        return new RecommendationPick($entryId, $score, $this->salvageReason($entry['reason'] ?? null));
    }

    private function salvageScore(mixed $score): ?int
    {
        if (\is_int($score) || \is_float($score)) {
            $numeric = (float) $score;
        } elseif (\is_string($score) && is_numeric($score)) {
            $numeric = (float) $score;
        } else {
            return null;
        }

        return (int) min(100.0, max(0.0, round($numeric)));
    }
```

Update the class docblock's second paragraph: replace "some ids are invalid or duplicated" with "some ids are invalid, duplicated, or scoreless".

- [ ] **Step 4: Add scores to every queued model reply in the integration tests**

Find every JSON payload handed to `queueContent(...)`:

```bash
grep -n "'recommendations' =>" tests/Service/Recommendation/RecommendationRunAdvancerTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Controller/Api/RecommendationRunControllerTest.php
```

In **each** entry of each queued payload, add a `'score' => <int>` key between `'id'` and `'reason'`. Use descending values within one payload (e.g. first entry 90, second 80, third 70) so reply order and score order agree — Task 8 changes ordering assertions deliberately; this task must not. Do **not** touch arrays passed to `recordBatchWinners(...)` (entity-level fixtures; they change in Task 7). Do **not** change any assertion in this step: `asWinners` still drops the score, so `getWinners()`/items assertions are unaffected.

- [ ] **Step 5: Run the full suite**

Run: `php bin/phpunit`
Expected: PASS. If an advancer/worker/controller test fails with an unusable-reply retry it means a queued payload was missed — repeat the grep.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Recommendation/RecommendationPick.php src/Service/Recommendation/RecommendationPickParser.php tests/Service/Recommendation/RecommendationPickParserTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Controller/Api/RecommendationRunControllerTest.php
git commit -m "feat(#316): picks carry a 0-100 score, and a scoreless pick is invalid"
```

---

### Task 3: The batch prompt scores instead of ranking

Rewrite `SYSTEM_ROLE` and `OUTPUT_CONTRACT`, drop the `%d` pick cap, and make the packer's response reserve a constant (the reply now scales with the batch, not with `picksLimit`).

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationPromptText.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPromptBuilder.php`
- Test: `backend/tests/Service/Recommendation/RecommendationPromptBuilderTest.php`
- Modify (one assertion): `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `RecommendationPromptText::SYSTEM_ROLE` starts with `'You score candidate posts for one reader of an RSS reader.'`; `OUTPUT_CONTRACT` is a plain string (no `%d`, no `sprintf`). Packing: `responseReserve = MAXIMUM_BATCH_SIZE * TOKENS_PER_PICK` (a constant 1600). Task 8's tests rely on these strings.

- [ ] **Step 1: Update the two constants**

In `RecommendationPromptText.php` replace `SYSTEM_ROLE` and `OUTPUT_CONTRACT`:

```php
    public const string SYSTEM_ROLE = 'You score candidate posts for one reader of an RSS reader. The user '
        . "message holds four sections. FAVORITES, KEPT and VIEWED list posts from the reader's history, newest "
        . 'first. FAVORITES weighs strongest, KEPT next, VIEWED least. CANDIDATES lists unread posts; each line '
        . 'starts with the candidate id in square brackets. Score each candidate from 0 to 100 for how strongly '
        . "the reader's history suggests they would open it: 90-100 squarely inside a theme the history shows "
        . 'strong, repeated interest in; 60-89 clearly matches a visible interest; 30-59 plausibly interesting '
        . 'but the connection is loose; 0-29 no visible connection. Prefer recent posts. When several candidates '
        . 'cover the same story, score only the best source and omit the others.';

    public const string OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"recommendations": '
        . '[{"id": <candidate id>, "score": <0-100>, "reason": "<one short sentence>"}]}. Score every '
        . 'candidate. Use only ids that appear in the candidate lines.';
```

- [ ] **Step 2: Update the builder**

In `RecommendationPromptBuilder`:

1. `batchMessages()`: replace `$contract = \sprintf(RecommendationPromptText::OUTPUT_CONTRACT, $settings->picksLimit);` with `$contract = RecommendationPromptText::OUTPUT_CONTRACT;`.
2. `mergeMessages()`: same replacement (the method itself is deleted in Task 8; this keeps it compiling until then — `sprintf` on a string without `%d` would emit the placeholder-less text unchanged anyway).
3. `packBatches()`: replace the reserve line

```php
        $responseReserve = min($settings->picksLimit * self::TOKENS_PER_PICK, intdiv($settings->contextWindow, 4));
```

with

```php
        // The reply scores one line per candidate, so its size is bounded by
        // the batch cap, not by the final list size.
        $responseReserve = self::MAXIMUM_BATCH_SIZE * self::TOKENS_PER_PICK;
```

- [ ] **Step 3: Re-tune the builder tests**

The packing budget is `contextWindow − 1500 (overhead) − reserve − historyTokens` and the empty-history fixture costs 24 tokens. The reserve changes from `min(picksLimit × 40, window ÷ 4)` to the constant 1600, so each exact-split test keeps its old budget by raising the window by the reserve delta:

| Test | Old window (reserve) | New window |
|---|---|---|
| `testPackingSplitsWhenTheBudgetOverflows` | 2829 (400) | **4029** |
| `testPackingSplitsExactlyAtTheMinimumBatchSizeWhenTheBudgetOverflowsEarly` | 1565 (40) | **3125** |
| `testPackingResetsUsedTokensExactlyAtEachSplitBoundary` | 1629 (40) | **3189** |
| `testPackingBudgetIsSensitiveToEveryTermInItsFormula` | 1630 (40) | **3190** |

For each: change the `$this->settings(<window>, <picksLimit>)` window to the new value (leave `picksLimit` as-is — it no longer enters the formula) and update the test's explanatory comment to name the constant reserve (1600) instead of `picksLimit`. All expected split arrays stay identical. (The new windows stay below 16 440, so `descriptionLength` remains clamped at 120 and line sizes are unchanged.)

Then:
- **Delete** `testPackingReservesAQuarterOfTheContextWindowWhenPicksLimitIsLarge` — the quarter-window clamp no longer exists.
- In `testBatchMessagesLayerFixedGuidanceAndContract`: replace the `'Include at most 100 picks'` containment assert with `self::assertStringContainsString('Score every candidate.', $system);`.
- In `testBatchMessagesReturnsTheExactRoleContentStructure` (and any other expected-system construction): replace `\sprintf(RecommendationPromptText::OUTPUT_CONTRACT, <n>)` with `RecommendationPromptText::OUTPUT_CONTRACT`.

- [ ] **Step 4: Update the advancer test's role assertion**

In `RecommendationRunAdvancerTest::testBatchTickRecordsWinnersAndAdvances`, change `'You rank candidate posts for one reader of an RSS reader.'` to `'You score candidate posts for one reader of an RSS reader.'`.

- [ ] **Step 5: Run the affected suites, then the full suite**

Run: `php bin/phpunit --filter 'RecommendationPromptBuilderTest|RecommendationRunAdvancerTest'` then `php bin/phpunit`
Expected: PASS. (The multi-batch advancer fixture at window 2500 now has a negative budget, which the `MINIMUM_BATCH_SIZE` guard turns into batches of exactly 10 — the same 10/10 split as before, so no advancer fixture changes.)

- [ ] **Step 6: Commit**

```bash
git add src/Service/Recommendation/RecommendationPromptText.php src/Service/Recommendation/RecommendationPromptBuilder.php tests/Service/Recommendation/RecommendationPromptBuilderTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php
git commit -m "feat(#316): the batch prompt scores every candidate on an anchored scale"
```

---

### Task 4: `RecommendationWinnerRanker`

The pure class that does what the merge model used to do implicitly: compare entries across batches. Flatten, stable-sort by score, cut for the dedup call.

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationWinnerRanker.php`
- Test: `backend/tests/Service/Recommendation/RecommendationWinnerRankerTest.php`

**Interfaces:**
- Consumes: the stored winner shape `array{id: int, score?: int, reason: string}`.
- Produces: `ranked(array $batchWinners): list<array{id: int, score: int, reason: string}>` (flattened, score-descending, stable) and `cutForDedup(array $ranked, int $picksLimit): list<array{id: int, score: int, reason: string}>` (first `2 × picksLimit`). Task 8 calls both.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RecommendationWinnerRanker;
use PHPUnit\Framework\TestCase;

final class RecommendationWinnerRankerTest extends TestCase
{
    private RecommendationWinnerRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new RecommendationWinnerRanker();
    }

    public function testRankedFlattensAllBatchesAndSortsByScoreDescending(): void
    {
        $ranked = $this->ranker->ranked([
            [['id' => 1, 'score' => 40, 'reason' => 'a'], ['id' => 2, 'score' => 90, 'reason' => 'b']],
            [['id' => 3, 'score' => 70, 'reason' => 'c']],
        ]);

        self::assertSame([2, 3, 1], array_column($ranked, 'id'));
        self::assertSame([90, 70, 40], array_column($ranked, 'score'));
        self::assertSame(['b', 'c', 'a'], array_column($ranked, 'reason'));
    }

    public function testTiedScoresKeepBatchOrderWhichIsSnapshotRecencyOrder(): void
    {
        $ranked = $this->ranker->ranked([
            [['id' => 1, 'score' => 50, 'reason' => 'first batch, first line']],
            [['id' => 2, 'score' => 50, 'reason' => 'second batch']],
        ]);

        self::assertSame([1, 2], array_column($ranked, 'id'));
    }

    public function testAWinnerRecordedWithoutAScoreReadsAsZeroAndSortsLast(): void
    {
        $ranked = $this->ranker->ranked([
            [['id' => 1, 'reason' => 'legacy row'], ['id' => 2, 'score' => 10, 'reason' => 'scored']],
        ]);

        self::assertSame([2, 1], array_column($ranked, 'id'));
        self::assertSame(0, $ranked[1]['score']);
    }

    public function testEmptyBatchesRankToAnEmptyPool(): void
    {
        self::assertSame([], $this->ranker->ranked([[], []]));
    }

    public function testCutForDedupKeepsTwiceThePicksLimit(): void
    {
        $ranked = [];
        for ($id = 1; $id <= 10; ++$id) {
            $ranked[] = ['id' => $id, 'score' => 100 - $id, 'reason' => 'r'];
        }

        $cut = $this->ranker->cutForDedup($ranked, 3);

        self::assertSame([1, 2, 3, 4, 5, 6], array_column($cut, 'id'));
    }

    public function testCutForDedupLeavesAShortPoolUntouched(): void
    {
        $ranked = [['id' => 1, 'score' => 5, 'reason' => 'r']];

        self::assertSame($ranked, $this->ranker->cutForDedup($ranked, 100));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit --filter RecommendationWinnerRankerTest`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Ranks the pooled batch winners for the global cut: comparing entries
 * across batches, which the merge model used to do implicitly, done in code
 * on the scores the batches produced against a shared rubric. Pure
 * computation, no collaborators. PHP's sort has been stable since 8.0, so
 * tied scores keep flattening order — batch order, which is snapshot order,
 * which is the candidate loader's recency order.
 */
final readonly class RecommendationWinnerRanker
{
    /**
     * Twice the final list size goes to the dedup call, so an entry dropped
     * as a duplicate backfills from a line the dedup call has also checked.
     */
    private const int DEDUP_INPUT_FACTOR = 2;

    /**
     * @param list<list<array{id: int, score?: int, reason: string}>> $batchWinners
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function ranked(array $batchWinners): array
    {
        $pool = [];
        foreach ($batchWinners as $batch) {
            foreach ($batch as $winner) {
                // A winner recorded before scores existed (a run in flight
                // across the deploy) reads as 0: it sorts last, the run
                // still completes, and the next run self-heals.
                $pool[] = [
                    'id' => $winner['id'],
                    'score' => $winner['score'] ?? 0,
                    'reason' => $winner['reason'],
                ];
            }
        }

        usort($pool, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $pool;
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $ranked
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    public function cutForDedup(array $ranked, int $picksLimit): array
    {
        return \array_slice($ranked, 0, self::DEDUP_INPUT_FACTOR * $picksLimit);
    }
}
```

- [ ] **Step 4: Run the test, then the full suite**

Run: `php bin/phpunit --filter RecommendationWinnerRankerTest` then `php bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/RecommendationWinnerRanker.php tests/Service/Recommendation/RecommendationWinnerRankerTest.php
git commit -m "feat(#316): rank pooled batch winners by score in code"
```

---

### Task 5: The dedup prompt

New `DEDUP_ROLE` / `DEDUP_OUTPUT_CONTRACT` constants and a `dedupMessages()` builder method. `MERGE_ROLE` and `mergeMessages()` stay alive until Task 8 deletes them (the advancer still calls them).

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationPromptText.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPromptBuilder.php`
- Test: `backend/tests/Service/Recommendation/RecommendationPromptBuilderTest.php`

**Interfaces:**
- Consumes: the ranked-pool shape `array{id: int, score: int, reason: string}` from Task 4, `PromptLine` (`->title`, `->feedName`, `->date`).
- Produces: `dedupMessages(array $rankedPool, array $linesById): list<array{role: string, content: string}>` — system = `DEDUP_ROLE . "\n\n" . DEDUP_OUTPUT_CONTRACT` (deliberately **no** guidance prompt: guidance shapes what to recommend, and this call recommends nothing), user = `"RANKED (best first):\n"` + one `- [id] title — source — date — reason` line per pool entry; throws `\LogicException` on an empty pool. Task 8 calls it.

- [ ] **Step 1: Write the failing tests**

Add to `RecommendationPromptBuilderTest`:

```php
    public function testDedupMessagesReturnsTheExactRoleContentStructureWithoutGuidance(): void
    {
        $rankedPool = [
            ['id' => 2, 'score' => 90, 'reason' => 'Strong match'],
            ['id' => 1, 'score' => 40, 'reason' => 'Loose match'],
        ];
        $linesById = [
            1 => new PromptLine(1, 'Title One', 'Feed A', '2026-01-05', null),
            2 => new PromptLine(2, 'Title Two', 'Feed B', '2026-01-06', null),
        ];

        $messages = $this->builder->dedupMessages($rankedPool, $linesById);

        self::assertSame(
            [
                [
                    'role' => 'system',
                    'content' => RecommendationPromptText::DEDUP_ROLE
                        . "\n\n" . RecommendationPromptText::DEDUP_OUTPUT_CONTRACT,
                ],
                [
                    'role' => 'user',
                    'content' => "RANKED (best first):\n"
                        . "- [2] Title Two — Feed B — 2026-01-06 — Strong match\n"
                        . '- [1] Title One — Feed A — 2026-01-05 — Loose match',
                ],
            ],
            $messages,
        );
    }

    public function testDedupMessagesSkipsAPoolEntryWhoseLineIsMissing(): void
    {
        $rankedPool = [
            ['id' => 1, 'score' => 90, 'reason' => 'Present'],
            ['id' => 2, 'score' => 80, 'reason' => 'Pruned'],
        ];
        $linesById = [1 => new PromptLine(1, 'Title One', 'Feed A', '2026-01-05', null)];

        $messages = $this->builder->dedupMessages($rankedPool, $linesById);

        self::assertStringContainsString('- [1] ', $messages[1]['content']);
        self::assertStringNotContainsString('- [2] ', $messages[1]['content']);
    }

    public function testDedupMessagesRejectsAnEmptyPool(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The dedup phase requires at least one ranked winner.');

        $this->builder->dedupMessages([], []);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit --filter RecommendationPromptBuilderTest`
Expected: the three new tests ERROR — `dedupMessages` undefined.

- [ ] **Step 3: Implement**

In `RecommendationPromptText.php`, add below `MERGE_ROLE`:

```php
    public const string DEDUP_ROLE = 'You remove duplicate stories from a ranked list built for one reader of '
        . 'an RSS reader. The user message lists RANKED entries, best first; each line starts with the entry id '
        . 'in square brackets, followed by title, source, date and the reason it was chosen. When several '
        . 'entries cover the same story, keep the best-ranked source and name the others as duplicates.';

    public const string DEDUP_OUTPUT_CONTRACT = 'Reply with JSON only, no prose: {"duplicates": '
        . '[<entry id>, ...]}. List only ids of entries that duplicate a better-ranked entry. If there are no '
        . 'duplicates, reply {"duplicates": []}. Use only ids that appear in the lines.';
```

In `RecommendationPromptBuilder`, add below `mergeMessages()`:

```php
    /**
     * The dedup call carries no guidance prompt on purpose: guidance shapes
     * what to recommend, and this call recommends nothing.
     *
     * @param list<array{id: int, score: int, reason: string}> $rankedPool
     * @param array<int, PromptLine>                           $linesById
     *
     * @return list<array{role: string, content: string}>
     *
     * @throws \LogicException if called with an empty pool
     */
    public function dedupMessages(array $rankedPool, array $linesById): array
    {
        if ([] === $rankedPool) {
            throw new \LogicException('The dedup phase requires at least one ranked winner.');
        }

        $lines = [];
        foreach ($rankedPool as $winner) {
            $line = $linesById[$winner['id']] ?? null;
            if (null === $line) {
                continue;
            }
            $lines[] = \sprintf(
                '- [%d] %s — %s — %s — %s',
                $winner['id'],
                $line->title,
                $line->feedName,
                $line->date,
                $winner['reason'],
            );
        }

        return [
            [
                'role' => 'system',
                'content' => RecommendationPromptText::DEDUP_ROLE
                    . "\n\n" . RecommendationPromptText::DEDUP_OUTPUT_CONTRACT,
            ],
            ['role' => 'user', 'content' => "RANKED (best first):\n" . implode("\n", $lines)],
        ];
    }
```

- [ ] **Step 4: Run the builder tests, then the full suite**

Run: `php bin/phpunit --filter RecommendationPromptBuilderTest` then `php bin/phpunit`
Expected: PASS (merge tests still pass — nothing existing changed).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/RecommendationPromptText.php src/Service/Recommendation/RecommendationPromptBuilder.php tests/Service/Recommendation/RecommendationPromptBuilderTest.php
git commit -m "feat(#316): dedup prompt that names duplicates in a ranked list"
```

---

### Task 6: `RecommendationDuplicateParser`

The dedup reply's defensive boundary. Unlike the pick parser, an **empty list is usable** — "no duplicates" is a legitimate answer — which is why this is a separate class and not a flag parameter.

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationDuplicateParser.php`
- Create: `backend/src/Service/Recommendation/DuplicateParseResult.php`
- Test: `backend/tests/Service/Recommendation/RecommendationDuplicateParserTest.php`

**Interfaces:**
- Consumes: `ModelReplyJsonDecoder` (Task 1).
- Produces: `parse(string $content, array $shownIds): DuplicateParseResult` where `DuplicateParseResult` has `public bool $usable` and `public array $duplicateIds` (`list<int>`), with static constructors `usable(array $duplicateIds)` / `unusable()`. Task 8 branches on `->usable` and filters with `->duplicateIds`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ModelReplyJsonDecoder;
use App\Service\Recommendation\RecommendationDuplicateParser;
use PHPUnit\Framework\TestCase;

final class RecommendationDuplicateParserTest extends TestCase
{
    private RecommendationDuplicateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RecommendationDuplicateParser(new ModelReplyJsonDecoder());
    }

    public function testValidDuplicateIdsAreKept(): void
    {
        $result = $this->parser->parse('{"duplicates": [2, 3]}', [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([2, 3], $result->duplicateIds);
    }

    public function testAnEmptyDuplicatesArrayIsUsable(): void
    {
        $result = $this->parser->parse('{"duplicates": []}', [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([], $result->duplicateIds);
    }

    public function testIdsNotShownToTheModelAreIgnored(): void
    {
        $result = $this->parser->parse('{"duplicates": [2, 99]}', [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([2], $result->duplicateIds);
    }

    public function testNumericStringIdsAreAcceptedAndRepeatsCollapsed(): void
    {
        $result = $this->parser->parse('{"duplicates": ["2", 2]}', [1, 2]);

        self::assertTrue($result->usable);
        self::assertSame([2], $result->duplicateIds);
    }

    public function testNonNumericEntriesAreIgnoredRatherThanCrashing(): void
    {
        $result = $this->parser->parse('{"duplicates": ["x", [3], 2]}', [1, 2, 3]);

        self::assertTrue($result->usable);
        self::assertSame([2], $result->duplicateIds);
    }

    public function testAFencedReplyParses(): void
    {
        $result = $this->parser->parse("```json\n{\"duplicates\": [1]}\n```", [1]);

        self::assertTrue($result->usable);
        self::assertSame([1], $result->duplicateIds);
    }

    public function testMissingDuplicatesKeyIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('{"other": []}', [1])->usable);
    }

    public function testANonArrayDuplicatesValueIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('{"duplicates": "none"}', [1])->usable);
    }

    public function testUnparseableJsonIsUnusable(): void
    {
        self::assertFalse($this->parser->parse('not json', [1])->usable);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit --filter RecommendationDuplicateParserTest`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement both classes**

`DuplicateParseResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The outcome of parsing one dedup reply. Unlike PickParseResult, an empty
 * id list is usable: "no duplicates" is a legitimate answer, while zero
 * picks would mean the model recommended nothing.
 */
final readonly class DuplicateParseResult
{
    /** @param list<int> $duplicateIds */
    private function __construct(
        public array $duplicateIds,
        public bool $usable,
    ) {
    }

    /** @param list<int> $duplicateIds */
    public static function usable(array $duplicateIds): self
    {
        return new self($duplicateIds, true);
    }

    public static function unusable(): self
    {
        return new self([], false);
    }
}
```

`RecommendationDuplicateParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Turns one raw dedup reply into validated duplicate ids — the same
 * defensive boundary RecommendationPickParser is for pick replies. Ids the
 * model was never shown are ignored rather than poisoning the reply:
 * partial credit is still credit.
 */
final readonly class RecommendationDuplicateParser
{
    public function __construct(private ModelReplyJsonDecoder $decoder)
    {
    }

    /** @param list<int> $shownIds */
    public function parse(string $content, array $shownIds): DuplicateParseResult
    {
        $decoded = $this->decoder->decode($content);

        if (null === $decoded) {
            return DuplicateParseResult::unusable();
        }

        $duplicates = $decoded['duplicates'] ?? null;

        if (!\is_array($duplicates)) {
            return DuplicateParseResult::unusable();
        }

        return DuplicateParseResult::usable($this->salvageIds($duplicates, $shownIds));
    }

    /**
     * @param array<mixed> $duplicates
     * @param list<int>    $shownIds
     *
     * @return list<int>
     */
    private function salvageIds(array $duplicates, array $shownIds): array
    {
        $kept = [];
        foreach ($duplicates as $id) {
            if (\is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (\is_int($id) && \in_array($id, $shownIds, true)) {
                $kept[$id] = true;
            }
        }

        return array_keys($kept);
    }
}
```

- [ ] **Step 4: Run the test, then the full suite**

Run: `php bin/phpunit --filter RecommendationDuplicateParserTest` then `php bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/RecommendationDuplicateParser.php src/Service/Recommendation/DuplicateParseResult.php tests/Service/Recommendation/RecommendationDuplicateParserTest.php
git commit -m "feat(#316): parse dedup replies, where an empty list is a valid answer"
```

---

### Task 7: Rename the merge phase to the dedup phase in progress and entity shapes

Mechanical rename plus the honest winner shapes on the entity. No behavior change.

**Files:**
- Modify: `backend/src/Entity/RecommendationRunProgress.php`
- Modify: `backend/src/Entity/RecommendationRun.php` (phpdoc only)
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (two property reads)
- Test: `backend/tests/Entity/RecommendationRunTest.php`, `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Produces: `RecommendationRunProgress->needsDedup` and `->isDedupPhase` (renamed from `needsMerge` / `isMergePhase`; constructor argument names follow). `RecommendationRun::recordBatchWinners(list<array{id: int, score: int, reason: string}>)`; `getWinners(): list<list<array{id: int, score?: int, reason: string}>>`. Task 8 uses these names.

- [ ] **Step 1: Rename in the progress value object**

In `RecommendationRunProgress.php` rename the promoted properties `needsMerge` → `needsDedup` and `isMergePhase` → `isDedupPhase` (constructor, named arguments in `forBatchPlan`, local `$needsMerge` variable). Update the `batchesTotal` comment to "The dedup call counts as one extra batch for progress purposes."

- [ ] **Step 2: Update the two reads in the advancer**

`RecommendationRunAdvancer.php:130` → `if ($run->progress()->isDedupPhase) {` and `:381` → `if (!$run->progress()->needsDedup) {`.

- [ ] **Step 3: Update the entity phpdoc**

In `RecommendationRun.php`:

- `$batchWinners` property and `getWinners()` return: `list<list<array{id: int, score?: int, reason: string}>>`, with this comment on the property:

```php
    /**
     * @var list<list<array{id: int, score?: int, reason: string}>>
     *     `score` is optional only for rows written before scores existed
     *     (a run in flight across the deploy); the ranker reads those as 0
     */
```

- `recordBatchWinners()` param: `@param list<array{id: int, score: int, reason: string}> $picks`.

- [ ] **Step 4: Update the tests**

- In `RecommendationRunTest` and `RecommendationRunAdvancerTest`, rename every `->needsMerge` / `->isMergePhase` read to `->needsDedup` / `->isDedupPhase` (the Task 6 grep list: RecommendationRunTest lines ~21, 22, 37, 46, 62, 86; RecommendationRunAdvancerTest lines ~555, 617, 659).
- In `RecommendationRunTest`, add `'score' => <int>` (any value, e.g. 50) to every array passed to `recordBatchWinners(...)` and to the matching `getWinners()` expectation (lines ~55/58 assert the round trip).
- In `RecommendationRunAdvancerTest::testMergeRespectsThePerBatchCap`, add `'score' => 50` to the two `recordBatchWinners` array_map fixtures (the test itself is replaced in Task 8; this keeps PHPStan's shape check green now).

- [ ] **Step 5: Run the full suite and PHPStan**

Run: `php bin/phpunit` and `bin/console cache:warmup && composer stan`
Expected: both PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Entity/RecommendationRunProgress.php src/Entity/RecommendationRun.php src/Service/Recommendation/RecommendationRunAdvancer.php tests/Entity/RecommendationRunTest.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php
git commit -m "refactor(#316): the extra phase is dedup now, and winners carry scores"
```

---

### Task 8: Rewire the advancer

The core task: batch replies are uncapped, winners keep their scores, the single-batch path finalizes from the ranked pool, and the merge tick becomes the dedup tick with the degrade-not-fail ending. `mergeMessages` and `MERGE_ROLE` die here.

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php`
- Modify: `backend/src/Service/Recommendation/RecommendationPromptBuilder.php` (delete `mergeMessages` + `MERGE_WINNERS_PER_BATCH_FACTOR`)
- Modify: `backend/src/Service/Recommendation/RecommendationPromptText.php` (delete `MERGE_ROLE`)
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`, `backend/tests/Service/Recommendation/RecommendationPromptBuilderTest.php` (delete merge tests + `winnerBatch` helper)

**Interfaces:**
- Consumes: `RecommendationWinnerRanker::ranked()/cutForDedup()` (Task 4), `RecommendationPromptBuilder::dedupMessages()` (Task 5), `RecommendationDuplicateParser::parse()` / `DuplicateParseResult` (Task 6), `->needsDedup` / `->isDedupPhase` (Task 7), `$pick->score` (Task 2).
- Produces: the final advancer behavior the e2e/API layers see; no public signature changes on `advance()`.

- [ ] **Step 1: Rewrite the advancer test's merge-phase coverage (failing first)**

In `RecommendationRunAdvancerTest`:

**(a)** Replace `testSingleBatchRunFinalizesWithoutAMergeCall` with a version that proves score ordering beats reply ordering (rename to `testSingleBatchRunFinalizesWithoutADedupCallOrderedByScore`). Queue the reply so the *lower-scored* pick comes first, and assert items come out score-first:

```php
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $batch[1], 'score' => 55, 'reason' => 'weaker match'],
                ['id' => $batch[0], 'score' => 90, 'reason' => 'stronger match'],
            ],
        ], \JSON_THROW_ON_ERROR));
```

and after the tick assert positions `[1, 2]` map to `[$batch[0], $batch[1]]` with reasons `['stronger match', 'weaker match']` (keep the `assertCount(1, …->calls())` and `'completed'` asserts).

**(b)** Replace `testMergeTickRanksTheWinners` with:

```php
    public function testDedupTickDropsNamedDuplicatesAndFinalizesInScoreOrder(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $firstBatch[0], 'score' => 80, 'reason' => 'from batch one'],
                ['id' => $firstBatch[1], 'score' => 60, 'reason' => 'also batch one'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [
                ['id' => $secondBatch[0], 'score' => 95, 'reason' => 'from batch two'],
            ],
        ], \JSON_THROW_ON_ERROR));
        $afterBatches = $this->advancer()->advance($this->user);
        self::assertSame('running', $afterBatches->status);
        self::assertTrue($this->activeRun()->progress()->isDedupPhase);

        $this->stubChatClient()->queueContent(json_encode([
            'duplicates' => [$firstBatch[1]],
        ], \JSON_THROW_ON_ERROR));
        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);

        $dedupCall = $this->stubChatClient()->calls()[2];
        self::assertStringContainsString('You remove duplicate stories', $dedupCall['messages'][0]['content']);
        $dedupUserMessage = $dedupCall['messages'][1]['content'];
        self::assertStringContainsString('RANKED (best first):', $dedupUserMessage);
        // Score order, not batch order: batch two's 95 outranks batch one's 80.
        self::assertMatchesRegularExpression(
            \sprintf('/\[%d\].*\n.*\[%d\].*\n.*\[%d\]/', $secondBatch[0], $firstBatch[0], $firstBatch[1]),
            $dedupUserMessage,
        );

        $this->em->clear();
        $items = $this->recommendationItems($run);
        self::assertCount(2, $items);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
        self::assertSame(['from batch two', 'from batch one'], array_map(
            static fn (RecommendationItem $item): string => $item->getReason(),
            $items,
        ));
    }
```

**(c)** Replace `testMergeRespectsThePerBatchCap` with:

```php
    public function testDedupInputIsCutToTwiceThePicksLimitAcrossTheWholePool(): void
    {
        $this->seedMultiBatchFixture(picksLimit: 4);
        $this->starter()->start($this->user);
        $this->advancer()->advance($this->user);
        $run = $this->activeRun();
        self::assertCount(2, $run->getCandidateBatches());
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        // Batch one scores low across the board, batch two high: the global
        // cut must keep the eight best over BOTH batches, not per batch.
        $run->recordBatchWinners(array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 10, 'reason' => 'low ' . $id],
            $firstBatch,
        ));
        $run->recordBatchWinners(array_map(
            static fn (int $id): array => ['id' => $id, 'score' => 90, 'reason' => 'high ' . $id],
            $secondBatch,
        ));
        $this->em->flush();
        self::assertTrue($this->activeRun()->progress()->isDedupPhase);

        $this->stubChatClient()->queueContent(json_encode(['duplicates' => []], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $dedupUserMessage = $this->stubChatClient()->calls()[0]['messages'][1]['content'];
        // 2 × picksLimit(4) = 8 lines survive the cut — and because batch two
        // outscores batch one everywhere, all 8 come from batch two.
        self::assertSame(8, substr_count($dedupUserMessage, "\n- ["));
        self::assertSame(8, $this->lineCountForBatch($dedupUserMessage, $secondBatch));
        self::assertSame(0, $this->lineCountForBatch($dedupUserMessage, $firstBatch));
    }
```

(`lineCountForBatch` already exists at the bottom of the test class; keep it.)

**(d)** Replace `testUnusableMergeReplyRetriesThenFails` with:

```php
    public function testThreeUnusableDedupRepliesCompleteTheRunUndeduped(): void
    {
        $this->seedMultiBatchFixture();
        $run = $this->startAndSnapshot();
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

        $this->stubChatClient()->queueContent('garbage 1');
        $this->stubChatClient()->queueContent('garbage 2');
        $this->stubChatClient()->queueContent('garbage 3');

        $this->advancer()->advance($this->user);
        $secondTry = $this->advancer()->advance($this->user);
        self::assertSame('running', $secondTry->status);

        // The retry carries the corrective tail, same as a batch retry.
        $retryMessages = $this->stubChatClient()->calls()[3]['messages'];
        self::assertCount(4, $retryMessages);
        self::assertSame('garbage 1', $retryMessages[2]['content']);

        $report = $this->advancer()->advance($this->user);

        // Degraded, not failed: the batches' ranking work is kept and the
        // run completes with the undeduped score-ordered list.
        self::assertSame('completed', $report->status);
        self::assertNull($report->error);

        $this->em->clear();
        $items = $this->recommendationItems($run);
        self::assertSame([$secondBatch[0], $firstBatch[0]], array_map(
            fn (RecommendationItem $item): int => $this->entryIdOf($item),
            $items,
        ));
    }
```

**(e)** In `testMergeTickWithAllWinnersPrunedFinalizesWithoutAProviderCall`: rename to `testDedupTickWithAllWinnersPrunedFinalizesWithoutAProviderCall`, update its docblock's "merge" wording to "dedup", and change its `isMergePhase` read if any remains. Payloads already carry scores from Task 2.

**(f)** In `testBatchTickRecordsWinnersAndAdvances`, the persisted winners now keep scores — update the expectation to:

```php
        self::assertSame(
            [
                ['id' => $firstBatch[0], 'score' => 90, 'reason' => 'r1'],
                ['id' => $firstBatch[1], 'score' => 80, 'reason' => 'r2'],
            ],
            $persisted->getWinners()[0],
        );
```

(match the score values Task 2 put into this test's queued payload). Check `AdvanceRecommendationRunsHandlerTest` for `getWinners()` expectations and update them the same way if present.

- [ ] **Step 2: Run to verify the rewritten tests fail**

Run: `php bin/phpunit --filter RecommendationRunAdvancerTest`
Expected: the rewritten tests FAIL (dedup reply is parsed as picks / winners drop scores / degrade path fails the run). Pre-existing tests still pass.

- [ ] **Step 3: Rewire the advancer**

In `RecommendationRunAdvancer.php`:

1. Constructor: append two collaborators (the `@SuppressWarnings("PHPMD.ExcessiveParameterList")` already covers it; update the class docblock sentence "The twelve constructor collaborators" to "The fourteen constructor collaborators" and its paragraph describing the merge phase to describe the dedup phase — score-ordered cut in, duplicate ids out, degrade on exhausted attempts):

```php
        private readonly RecommendationWinnerRanker $ranker,
        private readonly RecommendationDuplicateParser $duplicateParser,
```

2. `tickActiveRun()`: `isDedupPhase` branch calls `$this->dedupTick($run, $user, $settings);` (rename done in Task 7; just rename the method here).

3. `providerTick()`: the parse limit becomes the batch size, and `recordReply` needs the picks limit for the single-batch ending:

```php
        $result = $this->parser->parse($content, $validIds, \count($validIds));

        return $this->recordReply($run, $content, $result, $effectiveSettings->picksLimit);
```

4. `asWinners()` keeps the score:

```php
    /**
     * The shape both the entity's winner list and finalize() speak.
     *
     * @param list<RecommendationPick> $picks
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private static function asWinners(array $picks): array
    {
        return array_map(
            static fn (RecommendationPick $pick): array => [
                'id' => $pick->entryId,
                'score' => $pick->score,
                'reason' => $pick->reason,
            ],
            $picks,
        );
    }
```

5. `recordReply()` finalizes a single-batch run from the ranked pool:

```php
    private function recordReply(
        RecommendationRun $run,
        string $content,
        PickParseResult $result,
        int $picksLimit,
    ): RecommendationRunReport {
        if (!$result->usable) {
            return $this->recordUnusableReply($run, $content);
        }

        $run->recordBatchWinners(self::asWinners($result->picks));

        if (!$run->progress()->needsDedup) {
            return $this->finalize($run, \array_slice($this->ranker->ranked($run->getWinners()), 0, $picksLimit));
        }

        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }
```

6. Replace `mergeTick()`, `mergeMessagesFor()` and `uniqueWinnerIds()` with:

```php
    private function dedupTick(
        RecommendationRun $run,
        User $user,
        AiProviderSettings $settings,
    ): RecommendationRunReport {
        $userId = $this->requireUserId($user);
        $picksLimit = $this->settingsResolver->forUser($user)->picksLimit;
        $pool = $this->ranker->cutForDedup($this->ranker->ranked($run->getWinners()), $picksLimit);
        $linesById = $this->candidateLoader->linesForIds($userId, array_column($pool, 'id'));
        $pool = array_values(array_filter(
            $pool,
            static fn (array $winner): bool => isset($linesById[$winner['id']]),
        ));

        if ([] === $pool) {
            // Every ranked entry was pruned since its batch ran: there is
            // nothing left to dedup, so this is progress, not failure --
            // mirrors providerTick's own all-pruned short-circuit.
            return $this->finalize($run, []);
        }

        $messages = $this->withCorrectiveTail($this->promptBuilder->dedupMessages($pool, $linesById), $run);

        $content = $this->callProvider($run, $settings, $messages);

        $result = $this->duplicateParser->parse($content, array_column($pool, 'id'));

        if (!$result->usable) {
            return $this->recordUnusableDedupReply($run, $content, $pool, $picksLimit);
        }

        return $this->finalize($run, $this->withoutDuplicates($pool, $result->duplicateIds, $picksLimit));
    }

    /**
     * A dedup reply that stays unusable after every retry degrades instead
     * of failing: the batch calls' ranking work is already done, and an
     * undeduped top list beats throwing the whole run away over a cosmetic
     * cleanup. Transport failures keep failing the run -- an unreachable
     * provider is not a degraded answer.
     *
     * @param list<array{id: int, score: int, reason: string}> $pool
     */
    private function recordUnusableDedupReply(
        RecommendationRun $run,
        string $content,
        array $pool,
        int $picksLimit,
    ): RecommendationRunReport {
        $run->recordInvalidReply($content);

        if ($run->attemptsExhausted()) {
            return $this->finalize($run, \array_slice($pool, 0, $picksLimit));
        }

        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }

    /**
     * Never reaches below the dedup cut for backfill: entries beyond it were
     * never shown to the dedup call, so pulling them in could reintroduce
     * unchecked duplicates. A final list shorter than the picks limit is the
     * accepted cost.
     *
     * @param list<array{id: int, score: int, reason: string}> $pool
     * @param list<int>                                        $duplicateIds
     *
     * @return list<array{id: int, score: int, reason: string}>
     */
    private function withoutDuplicates(array $pool, array $duplicateIds, int $picksLimit): array
    {
        $survivors = array_values(array_filter(
            $pool,
            static fn (array $winner): bool => !\in_array($winner['id'], $duplicateIds, true),
        ));

        return \array_slice($survivors, 0, $picksLimit);
    }
```

7. `finalize()`: update the `@param` to `list<array{id: int, score: int, reason: string}>` (body unchanged — it reads only `id` and `reason`).

8. `recordUnusableReply()`'s docblock: it now serves only batch replies — replace "Unusable batch and merge replies get the same treatment…" with "An unusable batch reply retries with a corrective tail and fails the run once attempts are exhausted; the dedup phase has its own, softer ending in recordUnusableDedupReply().".

9. Update the class-level docblock paragraph describing the phases (merge → dedup, degrade ending).

10. Delete from `RecommendationPromptBuilder`: `mergeMessages()` and `MERGE_WINNERS_PER_BATCH_FACTOR`. Delete from `RecommendationPromptText`: `MERGE_ROLE`. Delete from `RecommendationPromptBuilderTest`: `testMergeMessagesCapPerBatch`, `testMergeMessagesReturnsTheExactRoleContentStructureAndUsesGuidance`, `testMergeMessagesKeepsAtLeastOneWinnerPerBatchWhenTheCapWouldRoundToZero`, `testMergeMessagesRejectsAnEmptyWinnerSet`, and the now-unused `winnerBatch()` helper.

- [ ] **Step 4: Run the affected suites, then the full SQLite suite**

Run: `php bin/phpunit --filter 'RecommendationRunAdvancerTest|RecommendationPromptBuilderTest|AdvanceRecommendationRunsHandlerTest'` then `php bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/ src/Entity/ tests/
git commit -m "feat(#316): rank the total pool by score and narrow the extra call to dedup"
```

---

### Task 9: Quality gates, MySQL leg, PR

**Files:** none new — verification and delivery.

- [ ] **Step 1: Static gates**

From `backend/`:

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
```

Expected: all clean. Every touched `src` file must be PHPMD-clean outright — if `RecommendationRunAdvancer` trips a codesize rule, extract the offending seam (e.g. the pool filtering) rather than tuning thresholds.

- [ ] **Step 2: PhpStorm inspections on the changed PHP**

Run `mcp__phpstorm__lint_files` on every created/modified PHP file. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 3: Both phpunit legs**

```bash
php bin/phpunit
```

and from the repo root:

```bash
docker compose up -d
docker compose exec php vendor/bin/phpunit
```

Expected: green. (Known exception: the order-dependent rate-limiter flake in the full MySQL leg is pre-existing — verify any limiter failure passes in isolation before blaming this branch.) Scan `backend/var/log/dev.log` for new deprecations or swallowed errors.

- [ ] **Step 4: Mutation gate**

```bash
composer infection:diff
```

Expected: MSI at or above `minMsi` in `infection.json5`. Kill escaped mutants with targeted tests rather than lowering the gate.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feature/316-score-based-recommendation-ranking
```

Open a PR against `develop` titled `Score-based recommendation ranking (#316)`; body summarises the batch-scoring/global-cut/dedup-call design, links the spec, and says `Closes #316`. After merge, verify the issue closed itself.
