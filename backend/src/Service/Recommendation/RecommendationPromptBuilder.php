<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Renders the prompt layers for the recommendation feature and partitions
 * the candidate pool into batches that fit the model's context window. Pure
 * computation: no collaborators, so every method is a straight function of
 * its arguments.
 */
final class RecommendationPromptBuilder
{
    private const int CHARS_PER_TOKEN = 4;
    private const int FIXED_OVERHEAD_TOKENS = 1500;

    /**
     * What one score-only pick costs in a batch reply: `{"id":123,"score":843}` — an id and an
     *  integer, no prose. About a fifth of a reason-bearing pick, which is the whole point of the
     *  coarse-filter batch: the answer reserve shrinks, so packBatches fits more candidates per
     *  batch and the run makes fewer calls (#493).
     *
     * Also lives on RecommendationAnswerBudget as its own copy: that class
     * prices the provider's reply bound, a different computation from this
     * one's packing budget, and coupling the two classes for one shared
     * integer would cost more than the duplication does (#493).
     */
    private const int TOKENS_PER_SCORE_PICK = 15;

    /** What packBatches assumes the not-yet-distilled profile block will cost, so it can budget the
     *  batch prompt before the distillation phase has run. An estimate on purpose — the real profile
     *  is bounded to roughly this by DISTILL_ROLE's word cap (#493). */
    private const int ESTIMATED_PROFILE_TOKENS = 700;

    /**
     * How much room over the estimate the provider is actually given.
     *
     * The estimate is a mean; a reply that runs long is not a runaway and must
     * not be truncated into one that cannot parse. Half again covers the
     * spread and still leaves the ceiling an order of magnitude below the
     * 33800 tokens that let a looping model generate for an hour.
     *
     * Duplicated on RecommendationAnswerBudget for the same reason as
     * TOKENS_PER_SCORE_PICK (#493).
     */
    private const int ANSWER_BOUND_PERCENT = 150;

    /**
     * Duplicated on RecommendationAnswerBudget for the same reason as
     * TOKENS_PER_SCORE_PICK (#493).
     */
    private const int MINIMUM_ANSWER_TOKENS = 1024;
    private const int MINIMUM_BATCH_SIZE = 10;

    /**
     * How much of an unusable reply the corrective tail quotes back. Wide
     * enough that an ordinary rejected reply is shown whole, and far short of
     * a runaway's tens of kilobytes of repetition (#437).
     */
    private const int QUOTED_REPLY_LIMIT_CHARS = 2000;

    /**
     * Neutral on purpose. The clip is a general property of quoting a reply
     * back, and it cannot know why the reply was long: a merely verbose one,
     * well inside its ceiling, would otherwise be told it "did not stop". That
     * is false, and it is false inside a prompt, where a wrong statement
     * changes what the model does next rather than merely reading badly.
     */
    private const string QUOTED_REPLY_ELLIPSIS = "\n… (this quote is truncated)";

    private const int DESCRIPTION_MIN_CHARS = 120;
    private const int DESCRIPTION_MAX_CHARS = 480;
    private const int DESCRIPTION_WINDOW_DIVISOR = 137;

    public function descriptionLength(int $contextWindow): int
    {
        return min(
            self::DESCRIPTION_MAX_CHARS,
            max(self::DESCRIPTION_MIN_CHARS, intdiv($contextWindow, self::DESCRIPTION_WINDOW_DIVISOR)),
        );
    }

    /**
     * @param list<PromptLine> $candidates
     *
     * @return list<list<int>>
     */
    public function packBatches(
        array $candidates,
        RecommendationHistory $history,
        EffectiveRecommendationSettings $settings,
    ): array {
        $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);
        // The batch call sees the not-yet-distilled profile plus FAVORITES only
        // (RecommendationRunAdvancer builds the distillation profile from the
        // full three-section history before the batch phase ever runs), so the
        // packer budgets for that shape rather than the full history (#493).
        $favoritesSection = $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength);
        $historyTokens = self::ESTIMATED_PROFILE_TOKENS + $this->tokens($favoritesSection);
        $cap = $this->batchCap(\count($candidates), $settings);
        // The reply scores one line per candidate, so its size is bounded by
        // the batch cap, not by the final list size. The batch reply is
        // score-only (id + score, no reason), so it is charged the score-only
        // rate rather than the reason-bearing pick rate (#493).
        $responseReserve = intdiv($cap * self::TOKENS_PER_SCORE_PICK * self::ANSWER_BOUND_PERCENT, 100);
        $responseReserve = max(self::MINIMUM_ANSWER_TOKENS, $responseReserve);
        $budget = $settings->packing->contextWindow - self::FIXED_OVERHEAD_TOKENS - $responseReserve - $historyTokens;

        $batches = [];
        $current = [];
        $used = 0;

        foreach ($candidates as $candidate) {
            $lineTokens = $this->tokens($this->candidateLine($candidate, $descriptionLength));
            $overBudget = $used + $lineTokens > $budget && \count($current) >= self::MINIMUM_BATCH_SIZE;
            $atCapacity = \count($current) >= $cap;
            if ([] !== $current && ($overBudget || $atCapacity)) {
                $batches[] = $current;
                $current = [];
                $used = 0;
            }
            $current[] = $candidate->entryId ?? 0;
            $used += $lineTokens;
        }

        if ([] !== $current) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * @param list<PromptLine> $candidateLines
     *
     * @return list<array{role: string, content: string}>
     */
    public function batchMessages(
        RecommendationHistory $history,
        array $candidateLines,
        EffectiveRecommendationSettings $settings,
        ?string $profile,
        ?CandidatePoolSummary $poolSummary = null,
    ): array {
        $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);
        $guidance = $settings->guidancePrompt ?? RecommendationPromptText::DEFAULT_GUIDANCE;
        $system = implode("\n\n", [
            RecommendationPromptText::BATCH_SYSTEM_ROLE,
            $guidance,
            RecommendationPromptText::BATCH_OUTPUT_CONTRACT,
        ]);

        $user = implode(
            "\n\n",
            $this->batchUserSections($history, $candidateLines, $descriptionLength, $profile, $poolSummary),
        );

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * The batch user message: the not-yet-distilled PROFILE (when one is
     * available), then FAVORITES only -- KEPT and VIEWED inform the profile
     * the distillation phase writes, but the batch call itself never sees them
     * -- then the global pool frame when one is present (#344 shuffles the
     * pool into random batches, so each batch names the whole set's size and
     * date span before its own local sample), then the candidate lines (#493).
     *
     * @param list<PromptLine> $candidateLines
     *
     * @return list<string>
     */
    private function batchUserSections(
        RecommendationHistory $history,
        array $candidateLines,
        int $descriptionLength,
        ?string $profile,
        ?CandidatePoolSummary $poolSummary,
    ): array {
        $sections = [];
        if ($this->hasContent($profile)) {
            $sections[] = "PROFILE:\n" . $profile;
        }
        $sections[] = $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength);
        $poolFrame = $this->poolFrameLine($poolSummary);
        if (null !== $poolFrame) {
            $sections[] = $poolFrame;
        }
        $sections[] = $this->candidateSection($candidateLines, $descriptionLength);

        return $sections;
    }

    private function poolFrameLine(?CandidatePoolSummary $poolSummary): ?string
    {
        if (null === $poolSummary) {
            return null;
        }

        return \sprintf(
            'The full candidate set has %d posts spanning %s to %s. This batch is a random sample of that set.',
            $poolSummary->total,
            $poolSummary->oldest,
            $poolSummary->newest,
        );
    }

    /**
     * The distillation call is the one place the model sees the reader's full
     * history: FAVORITES, KEPT and VIEWED together, so it can write a profile
     * that draws on all three. Every later phase (batch, consolidation) sees
     * only the profile this call produces plus FAVORITES (#493).
     *
     * @return list<array{role: string, content: string}>
     */
    public function distillMessages(RecommendationHistory $history, EffectiveRecommendationSettings $settings): array
    {
        $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);

        return [
            [
                'role' => 'system',
                'content' => RecommendationPromptText::DISTILL_ROLE
                    . "\n\n" . RecommendationPromptText::DISTILL_OUTPUT_CONTRACT,
            ],
            ['role' => 'user', 'content' => $this->historySections($history, $descriptionLength)],
        ];
    }

    /**
     * The consolidation call carries the same profile+FAVORITES fidelity as
     * the batch call -- KEPT and VIEWED never reach it, only the distillation
     * phase reads the full history -- plus the ranked shortlist, rendered
     * candidate-style so each line keeps the id a recommendation resolves
     * back to an Entry (#493, Q6 correction: an earlier draft of this call
     * re-sent the full history, which the packer never budgeted for).
     *
     * @param list<array{id: int, score: int, reason: string}> $rankedPool
     * @param array<int, PromptLine>                           $linesById
     *
     * @return list<array{role: string, content: string}>
     *
     * @throws \LogicException if called with an empty pool
     */
    public function consolidationMessages(
        array $rankedPool,
        array $linesById,
        RecommendationHistory $history,
        EffectiveRecommendationSettings $settings,
        ?string $profile,
    ): array {
        if ([] === $rankedPool) {
            throw new \LogicException('The consolidation phase requires at least one ranked winner.');
        }

        $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);

        // The shortlist in ranked order, with any winner whose line has since
        // been pruned simply dropped.
        $shortlistLines = array_values(array_filter(array_map(
            static fn (array $winner): ?PromptLine => $linesById[$winner['id']] ?? null,
            $rankedPool,
        )));

        $sections = [];
        if ($this->hasContent($profile)) {
            $sections[] = "PROFILE:\n" . $profile;
        }
        $sections[] = $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength);
        $sections[] = $this->candidateSection($shortlistLines, $descriptionLength);

        return [
            [
                'role' => 'system',
                'content' => RecommendationPromptText::CONSOLIDATION_ROLE
                    . "\n\n" . RecommendationPromptText::CONSOLIDATION_OUTPUT_CONTRACT,
            ],
            ['role' => 'user', 'content' => implode("\n\n", $sections)],
        ];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function correctiveTail(string $invalidReply, string $correction): array
    {
        return [
            ['role' => 'assistant', 'content' => $this->quotableReply($invalidReply)],
            ['role' => 'user', 'content' => $correction],
        ];
    }

    /**
     * As much of the model's own reply as is worth quoting back to it.
     *
     * A reply is normally short enough to quote whole, and the whole of it is
     * the clearest thing to correct against. A reply that ran away is not: it
     * repeats one line for tens of kilobytes, and echoing all of that spends
     * the retry's context on the loop and primes the model to continue it
     * (#437). The head shows the same mistake at a fraction of the cost, and
     * the marker tells the model it is seeing a fragment.
     */
    private function quotableReply(string $invalidReply): string
    {
        return self::clipped($invalidReply, self::QUOTED_REPLY_LIMIT_CHARS, self::QUOTED_REPLY_ELLIPSIS);
    }

    /**
     * One clip, in characters rather than bytes.
     *
     * `substr` would cut a multi-byte sequence in half, and this text goes
     * straight into a JSON request body: a German reply clipped mid-umlaut
     * makes `json_encode` fail and costs the retry the clip exists to enable.
     * Every other clip in this class was already `mb_`-safe; #437 added a
     * byte-based fourth, which is what this consolidates.
     */
    private static function clipped(string $value, int $lengthInCharacters, string $marker): string
    {
        if (mb_strlen($value) <= $lengthInCharacters) {
            return $value;
        }

        return mb_substr($value, 0, $lengthInCharacters) . $marker;
    }

    /**
     * Appends the corrective tail for a retry -- the model's own last invalid
     * reply and the correction instruction -- when there is one. Every phase
     * retries the same way; passing the reply in keeps the tail tied to the
     * call being retried: the batch phase passes each batch's own local last
     * invalid reply, the distillation and consolidation phases the run's
     * cross-tick one (#344).
     *
     * The correction comes in with it, because each phase rejects a reply for
     * different reasons and must ask for different things back (#396): only
     * the consolidation phase's reply carries duplicates, so only its
     * correction can ask for them to be named correctly.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public function messagesWithCorrectiveTail(
        array $messages,
        ?string $lastInvalidReply,
        string $correction,
    ): array {
        // Empty counts as absent. A blocking-shape runaway is cut before its
        // body ever parses, so its partial answer is '' — and quoting that back
        // put an empty assistant turn in the retry beside a correction naming a
        // reply the model cannot see (#437 review). There is nothing to correct
        // against; the retry goes out as the plain question.
        if (!$this->hasContent($lastInvalidReply)) {
            return $messages;
        }

        return [...$messages, ...$this->correctiveTail($lastInvalidReply, $correction)];
    }

    /** The explicit batch-count override wins over the #308 size ceiling: it is
     *  an expert setting, and the token budget below still protects the context
     *  window. Null means automatic packing under the connection's own ceiling,
     *  which is lower for an endpoint the account marked slow (#437). */
    private function batchCap(int $candidateCount, EffectiveRecommendationSettings $settings): int
    {
        if (null === $settings->packing->batchCount) {
            return $settings->packing->maximumBatchSize;
        }

        return max(1, (int) ceil($candidateCount / $settings->packing->batchCount));
    }

    /**
     * Whether a nullable string is worth acting on: not null, and not blank
     * once trimmed. Shared by every place that treats an absent profile the
     * same as an empty one, and an absent last-invalid-reply the same as a
     * blank one -- the three occurrences of this exact check are one concept,
     * not three (#493).
     *
     * @phpstan-assert-if-true string $value
     */
    private function hasContent(?string $value): bool
    {
        return null !== $value && '' !== trim($value);
    }

    /**
     * All three history sections, newest first within each: FAVORITES, KEPT,
     * then VIEWED. Only distillMessages() renders this -- every later phase
     * sees the profile it produces plus FAVORITES alone, not the full history
     * (#493).
     */
    private function historySections(RecommendationHistory $history, int $descriptionLength): string
    {
        return implode("\n\n", [
            $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength),
            $this->historySection('KEPT (newest first):', $history->kept, $descriptionLength),
            $this->historySection('VIEWED (newest first):', $history->viewed, $descriptionLength),
        ]);
    }

    /**
     * @param list<PromptLine> $lines
     */
    private function historySection(string $header, array $lines, int $descriptionLength): string
    {
        if ([] === $lines) {
            return $header . "\n- none";
        }

        $rendered = array_map(fn (PromptLine $line): string => $this->historyLine($line, $descriptionLength), $lines);

        return $header . "\n" . implode("\n", $rendered);
    }

    /**
     * @param list<PromptLine> $candidateLines
     */
    private function candidateSection(array $candidateLines, int $descriptionLength): string
    {
        if ([] === $candidateLines) {
            return "CANDIDATES:\n- none";
        }

        // The count is the model's own check on "score every candidate": the
        // instruction alone is unverifiable from inside the reply, and 3.2% of
        // candidates went unscored without it (#399).
        $candidateCount = \count($candidateLines);
        $header = \sprintf(
            'CANDIDATES (%d posts — return %d objects, one per line):',
            $candidateCount,
            $candidateCount,
        );

        $rendered = array_map(
            fn (PromptLine $line): string => $this->candidateLine($line, $descriptionLength),
            $candidateLines,
        );

        return $header . "\n" . implode("\n", $rendered);
    }

    private function historyLine(PromptLine $line, int $descriptionLength): string
    {
        $description = $this->truncatedDescription($line->description, $descriptionLength);

        return null === $description
            ? \sprintf('- %s — %s — %s', $line->title, $line->feedName, $line->date)
            : \sprintf('- %s — %s — %s — %s', $line->title, $line->feedName, $line->date, $description);
    }

    private function candidateLine(PromptLine $line, int $descriptionLength): string
    {
        $description = $this->truncatedDescription($line->description, $descriptionLength);

        $entryId = $line->entryId ?? 0;

        return null === $description
            ? \sprintf('- [%d] %s — %s — %s', $entryId, $line->title, $line->feedName, $line->date)
            : \sprintf('- [%d] %s — %s — %s — %s', $entryId, $line->title, $line->feedName, $line->date, $description);
    }

    private function truncatedDescription(?string $description, int $length): ?string
    {
        if (null === $description) {
            return null;
        }

        return self::clipped($description, $length, '…');
    }

    private function tokens(string $text): int
    {
        return intdiv(\strlen($text), self::CHARS_PER_TOKEN) + 1;
    }
}
