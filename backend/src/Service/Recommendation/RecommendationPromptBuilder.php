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
     * What one scored pick costs in the reply: its id, its score, and the
     * prose `reason` that dominates the three. Measured rather than guessed —
     * the largest full-batch reply on record ran to 12068 characters for 43
     * items, about 70 tokens each.
     *
     * It was 40 while the reasoning headroom sat on top of it and hid the
     * shortfall. Once a non-reasoning connection stopped paying that headroom
     * (#437) this number became the whole answer bound, so it has to cover a
     * real reply with room to spare instead of half of one.
     */
    private const int TOKENS_PER_PICK = 100;
    private const int MINIMUM_ANSWER_TOKENS = 1024;
    private const int MINIMUM_BATCH_SIZE = 10;

    /**
     * A reasoning model's thinking is billed against the same `max_tokens` as
     * its answer, and can legitimately run to tens of thousands of tokens
     * before the JSON begins. This headroom rides on top of the answer reserve
     * so `max_tokens` bounds reasoning-plus-answer, not the answer alone —
     * without it a 45-item batch capped at 1800 tokens spent the whole budget
     * thinking and its answer was truncated (deepseek-flash, #327). Generous
     * and finite on purpose: a runaway model is still cut off here, and the
     * wall clock and wire cap sit behind it.
     */
    private const int REASONING_HEADROOM_TOKENS = 32000;

    /**
     * The description length a dedup line carries. Fixed rather than scaled
     * to the context window like the candidate lines: whether two entries
     * report the same event is visible in the opening sentences, and the
     * dedup call renders every line at once, so a generous per-line budget
     * multiplies straight into one prompt (#406).
     */
    private const int DEDUP_DESCRIPTION_CHARS = 250;

    /**
     * How much of an unusable reply the corrective tail quotes back. Wide
     * enough that an ordinary rejected reply is shown whole, and far short of
     * a runaway's tens of kilobytes of repetition (#437).
     */
    private const int QUOTED_REPLY_LIMIT_CHARS = 2000;

    private const string QUOTED_REPLY_ELLIPSIS = "\n… (reply cut here: it did not stop)";

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
        $historyTokens = $this->tokens($this->historySections($history, $descriptionLength));
        $cap = $this->batchCap(\count($candidates), $settings);
        // The reply scores one line per candidate, so its size is bounded by
        // the batch cap, not by the final list size.
        $responseReserve = $this->answerTokenReserve($cap);
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
        ?CandidatePoolSummary $poolSummary = null,
    ): array {
        $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);

        $guidance = $settings->guidancePrompt ?? RecommendationPromptText::DEFAULT_GUIDANCE;
        $contract = RecommendationPromptText::OUTPUT_CONTRACT;
        $system = implode("\n\n", [RecommendationPromptText::SYSTEM_ROLE, $guidance, $contract]);

        $user = implode("\n\n", $this->userSections($history, $candidateLines, $descriptionLength, $poolSummary));

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * The user message: the history, then the global pool frame when one is
     * present (#344 shuffles the pool into random batches, so each batch names
     * the whole set's size and date span before its own local sample), then the
     * candidate lines.
     *
     * @param list<PromptLine> $candidateLines
     *
     * @return list<string>
     */
    private function userSections(
        RecommendationHistory $history,
        array $candidateLines,
        int $descriptionLength,
        ?CandidatePoolSummary $poolSummary,
    ): array {
        $sections = [$this->historySections($history, $descriptionLength)];
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
            $line = $this->winnerLine($winner['id'], $linesById);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        return [
            [
                'role' => 'system',
                'content' => RecommendationPromptText::DEDUP_ROLE
                    . "\n\n" . RecommendationPromptText::DEDUP_OUTPUT_CONTRACT,
            ],
            [
                'role' => 'user',
                'content' => $this->dedupSizeFrame(\count($lines))
                    . "\n\nRANKED (best first):\n" . implode("\n", $lines),
            ],
        ];
    }

    /**
     * Names the size of the list and the most duplicates it can hold, both as
     * numbers. The model was previously asked for a judgement with no sense of
     * scale, and answered that 98 of 100 entries were duplicates (#396); the
     * ceiling is the same one PlausibleDuplicateShare enforces on the reply,
     * so the model is held only to a rule it was given.
     */
    private function dedupSizeFrame(int $entryCount): string
    {
        return \sprintf(
            'This list holds %d entries. Most lists hold few duplicates and many hold none, so expect to name '
                . 'none or a handful. Never name more than %d of them: a reply naming more is discarded whole, '
                . 'and the reader is then shown the list with its real duplicates still in it.',
            $entryCount,
            PlausibleDuplicateShare::maximumFor($entryCount),
        );
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
        if (\strlen($invalidReply) <= self::QUOTED_REPLY_LIMIT_CHARS) {
            return $invalidReply;
        }

        return substr($invalidReply, 0, self::QUOTED_REPLY_LIMIT_CHARS) . self::QUOTED_REPLY_ELLIPSIS;
    }

    /**
     * Appends the corrective tail for a retry -- the model's own last invalid
     * reply and the correction instruction -- when there is one. Both provider
     * phases retry the same way; passing the reply in keeps the tail tied to
     * the call being retried: the batch phase passes each batch's own local
     * last invalid reply, the dedup phase the run's cross-tick one (#344).
     *
     * The correction comes in with it, because the two phases reject a reply
     * for different reasons and must ask for different things back (#396):
     * telling a dedup model to use "candidate ids" names a section it was
     * never shown, and leaves the over-flagging it was rejected for unsaid.
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
        if (null === $lastInvalidReply) {
            return $messages;
        }

        return [...$messages, ...$this->correctiveTail($lastInvalidReply, $correction)];
    }

    /**
     * How many tokens the *answer alone* over `$replyItemCount` items may need.
     * packBatches() subtracts this from the context window so the prompt leaves
     * the model room to answer — and the answer is all that competes with the
     * prompt for the input context, so reasoning has no place in this number.
     *
     * The floor covers the JSON envelope and the short replies where a
     * per-item estimate under-counts: at one item, 40 tokens would not fit
     * the punctuation around it, let alone the item.
     */
    public function answerTokenReserve(int $replyItemCount): int
    {
        return max(self::MINIMUM_ANSWER_TOKENS, $replyItemCount * self::TOKENS_PER_PICK);
    }

    /**
     * What the provider call sends as `max_tokens`, which caps total output —
     * reasoning plus answer for a reasoning model, not the answer alone. It is
     * the answer reserve plus a reasoning headroom, so a model that thinks
     * before it answers still has room to finish the JSON. #321 sent the answer
     * reserve here directly and starved reasoning models (#327); this is the
     * one place the two budgets legitimately diverge.
     */
    public function outputTokenReserve(int $replyItemCount): int
    {
        return $this->answerTokenReserve($replyItemCount) + self::REASONING_HEADROOM_TOKENS;
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
        // candidates went unscored without it (#399). Same reason the dedup
        // call is told the size of its list (#396).
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

        if (mb_strlen($description) <= $length) {
            return $description;
        }

        return mb_substr($description, 0, $length) . '…';
    }

    /**
     * The title, the date and the description -- what deciding whether two
     * entries report the same event actually needs. It used to carry the feed
     * name and the reason the scoring call wrote, and the reason is about the
     * reader rather than about the article: two entries covering one story
     * tend to earn similar reasons, which made the field worse than useless
     * here (#406).
     *
     * Null when the entry was pruned since its batch ran, so the caller can
     * simply drop it from the rendered list.
     *
     * @param array<int, PromptLine> $linesById
     */
    private function winnerLine(int $entryId, array $linesById): ?string
    {
        $line = $linesById[$entryId] ?? null;
        if (null === $line) {
            return null;
        }

        $description = $this->truncatedDescription($line->description, self::DEDUP_DESCRIPTION_CHARS);

        return null === $description
            ? \sprintf('- [%d] %s — %s', $entryId, $line->title, $line->date)
            : \sprintf('- [%d] %s — %s — %s', $entryId, $line->title, $line->date, $description);
    }

    private function tokens(string $text): int
    {
        return intdiv(\strlen($text), self::CHARS_PER_TOKEN) + 1;
    }
}
