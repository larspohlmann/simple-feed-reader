<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Prompt;

use App\Service\Ai\Completion\Model\Reasoning;
use App\Service\Recommendation\Prompt\Model\CandidatePoolSummaryModel;
use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Prompt\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Prompt\Pass\PromptContext;
use App\Service\Recommendation\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;

/**
 * Renders the prompt layers for the recommendation feature and partitions
 * the candidate pool into batches that fit the model's context window. Its one
 * collaborator is the answer budget, so a batch's reserve and its request's
 * output bound come from one place.
 */
final readonly class RecommendationPromptBuilder
{
    private const int CHARS_PER_TOKEN = 4;
    private const int FIXED_OVERHEAD_TOKENS = 1500;

    /** What packBatches assumes the not-yet-distilled profile block will cost, so it can budget the
     *  batch prompt before the distillation phase has run. An estimate on purpose — the real profile
     *  is bounded to roughly this by DISTILL_ROLE's word cap (#493). */
    private const int ESTIMATED_PROFILE_TOKENS = 700;

    /**
     * The consolidation call re-scores, reasons, and dedups its input in one pass, so its
     * size is bounded at both ends. The floor is the old fixed cut -- twice the final list
     * -- so dedup keeps its backfill slack. The ceiling caps the false-negative recovery
     * against the reasoning cost of entries later dropped: six times the final list,
     * generous without letting consolidation become the whole pipeline. Between them, the
     * connection's context window decides (consolidationInputSize).
     */
    private const int CONSOLIDATION_MIN_INPUT_FACTOR = 2;
    private const int CONSOLIDATION_MAX_INPUT_FACTOR = 6;

    /**
     * The non-description characters a candidate line carries — its id in
     * brackets, the title, the feed, the date, and the separators between them.
     * A rough constant is enough: consolidationInputSize only needs a per-line
     * estimate to size the shortlist against the context window.
     */
    private const int CANDIDATE_LINE_FRAME_CHARS = 90;

    private const int MINIMUM_BATCH_SIZE = 10;

    /**
     * How much of an unusable reply the corrective tail quotes back. Wide
     * enough that an ordinary rejected reply is shown whole, and far short of
     * a runaway's tens of kilobytes of repetition (#437).
     */
    private const int QUOTED_REPLY_LIMIT_CHARS = 2000;

    /**
     * Neutral on purpose: the clip cannot know why the reply was long, so a merely verbose
     * reply well inside its ceiling must not be told it "did not stop" -- a false statement
     * that, inside a prompt, changes what the model does next rather than just reading badly.
     */
    private const string QUOTED_REPLY_ELLIPSIS = "\n… (this quote is truncated)";

    private const int DESCRIPTION_MIN_CHARS = 120;
    private const int DESCRIPTION_MAX_CHARS = 480;
    private const int DESCRIPTION_WINDOW_DIVISOR = 137;

    public function __construct(private RecommendationAnswerBudget $answerBudget)
    {
    }

    public function descriptionLength(int $contextWindow): int
    {
        return min(
            self::DESCRIPTION_MAX_CHARS,
            max(self::DESCRIPTION_MIN_CHARS, intdiv($contextWindow, self::DESCRIPTION_WINDOW_DIVISOR)),
        );
    }

    /**
     * @param list<PromptLineModel> $candidates
     *
     * @return list<list<int>>
     */
    public function packBatches(
        array $candidates,
        RecommendationHistoryModel $history,
        EffectiveRecommendationSettingsModel $settings,
    ): array {
        $descriptionLength = $this->descriptionLength($settings->packing->contextWindow);
        $favoritesSection = $this->favoritesSection($history, $descriptionLength);
        $historyTokens = self::ESTIMATED_PROFILE_TOKENS + $this->tokens($favoritesSection);
        $cap = $settings->packing->batchSize->batchItemCap($settings->packing->maximumBatchSize);
        $responseReserve = $this->answerBudget->answerBoundTokens(
            $cap,
            RecommendationResponseSchema::BatchScore,
        );
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
            $current[] = $candidate->entryId;
            $used += $lineTokens;
        }

        if ([] !== $current) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * How many ranked winners the consolidation call takes: the largest shortlist whose whole call, reply and
     * reasoning headroom included, fits the context window, clamped between the floor and ceiling factors.
     */
    public function consolidationInputSize(PromptContext $context, Reasoning $reasoning): int
    {
        $contextWindow = $context->settings->packing->contextWindow;
        $picksLimit = $context->settings->picksLimit;
        $descriptionLength = $this->descriptionLength($contextWindow);
        $fixedInputTokens = self::FIXED_OVERHEAD_TOKENS
            + $this->tokens((string) $context->profile)
            + $this->tokens($this->favoritesSection($context->history, $descriptionLength));
        $lineChars = $descriptionLength + self::CANDIDATE_LINE_FRAME_CHARS;
        $perCandidateInputTokens = intdiv($lineChars, self::CHARS_PER_TOKEN) + 1;

        $floor = self::CONSOLIDATION_MIN_INPUT_FACTOR * $picksLimit;
        $ceiling = self::CONSOLIDATION_MAX_INPUT_FACTOR * $picksLimit;

        for ($size = $ceiling; $size > $floor; --$size) {
            $callTokens = $fixedInputTokens
                + $size * $perCandidateInputTokens
                + $this->answerBudget->outputBoundTokens(
                    $size,
                    RecommendationResponseSchema::Consolidation,
                    $reasoning,
                );
            if ($callTokens <= $contextWindow) {
                return $size;
            }
        }

        return $floor;
    }

    /**
     * @param list<PromptLineModel> $candidateLines
     *
     * @return list<array{role: string, content: string}>
     */
    public function batchMessages(
        PromptContext $context,
        array $candidateLines,
        ?CandidatePoolSummaryModel $poolSummary = null,
    ): array {
        $system = implode("\n\n", [
            RecommendationPromptText::BATCH_SYSTEM_ROLE,
            $context->settings->guidancePrompt ?? RecommendationPromptText::DEFAULT_GUIDANCE,
            RecommendationPromptText::BATCH_OUTPUT_CONTRACT,
        ]);
        $user = implode("\n\n", $this->batchUserSections($context, $candidateLines, $poolSummary));

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * The profile when there is one, FAVORITES only (KEPT and VIEWED shape the profile, #493), the whole pool's
     * frame (#344 shuffles the pool into random batches), then the candidates.
     *
     * @param list<PromptLineModel> $candidateLines
     *
     * @return list<string>
     */
    private function batchUserSections(
        PromptContext $context,
        array $candidateLines,
        ?CandidatePoolSummaryModel $poolSummary,
    ): array {
        $descriptionLength = $this->descriptionLength($context->settings->packing->contextWindow);
        $sections = [];
        if ($this->hasContent($context->profile)) {
            $sections[] = "PROFILE:\n" . $context->profile;
        }
        $sections[] = $this->favoritesSection($context->history, $descriptionLength);
        $poolFrame = $this->poolFrameLine($poolSummary);
        if (null !== $poolFrame) {
            $sections[] = $poolFrame;
        }
        $sections[] = $this->candidateSection($candidateLines, $descriptionLength);

        return $sections;
    }

    private function poolFrameLine(?CandidatePoolSummaryModel $poolSummary): ?string
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
    public function distillMessages(
        RecommendationHistoryModel $history,
        EffectiveRecommendationSettingsModel $settings,
    ): array {
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
     * Profile and FAVORITES like the batch call, then the ranked shortlist rendered candidate-style, so each line
     * keeps the id a recommendation resolves back to; a winner pruned since its batch is dropped (#493).
     *
     * @param list<array{id: int, score: int, reason: string}> $rankedPool
     * @param array<int, PromptLineModel>                      $linesById
     *
     * @return list<array{role: string, content: string}>
     *
     * @throws \LogicException if called with an empty pool
     */
    public function consolidationMessages(PromptContext $context, array $rankedPool, array $linesById): array
    {
        if ([] === $rankedPool) {
            throw new \LogicException('The consolidation phase requires at least one ranked winner.');
        }

        $descriptionLength = $this->descriptionLength($context->settings->packing->contextWindow);
        $shortlistLines = array_values(array_filter(array_map(
            static fn (array $winner): ?PromptLineModel => $linesById[$winner['id']] ?? null,
            $rankedPool,
        )));

        $sections = [];
        if ($this->hasContent($context->profile)) {
            $sections[] = "PROFILE:\n" . $context->profile;
        }
        $sections[] = $this->favoritesSection($context->history, $descriptionLength);
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
     * As much of the model's own reply as is worth quoting back to it. A reply is normally
     * short enough to quote whole, the clearest thing to correct against. A reply that ran
     * away is not: it repeats one line for tens of kilobytes, and echoing it spends the
     * retry's context on the loop and primes the model to continue it (#437). The head shows
     * the same mistake at a fraction of the cost, and the marker tells the model it is
     * seeing a fragment.
     */
    private function quotableReply(string $invalidReply): string
    {
        return self::clipped($invalidReply, self::QUOTED_REPLY_LIMIT_CHARS, self::QUOTED_REPLY_ELLIPSIS);
    }

    /**
     * One clip, in characters rather than bytes. `substr` would cut a multi-byte sequence
     * in half, and this text goes straight into a JSON request body: a German reply clipped
     * mid-umlaut makes `json_encode` fail and costs the retry the clip exists to enable.
     * Every other clip in this class was already `mb_`-safe; #437 added a byte-based
     * fourth, which is what this consolidates.
     */
    private static function clipped(string $value, int $lengthInCharacters, string $marker): string
    {
        if (mb_strlen($value) <= $lengthInCharacters) {
            return $value;
        }

        return mb_substr($value, 0, $lengthInCharacters) . $marker;
    }

    /**
     * Appends the corrective tail for a retry -- the model's own last invalid reply and the
     * correction instruction -- when there is one. Every phase retries the same way; passing
     * the reply in keeps the tail tied to the call being retried: the batch phase passes each
     * batch's own local last invalid reply, distillation and consolidation the run's
     * cross-tick one (#344). The correction comes in with it because each phase rejects a
     * reply for different reasons and asks for different things back (#396): only the
     * consolidation reply carries duplicates, so only its correction can ask for them to be
     * named correctly.
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
        // body parses, so its answer is '' — quoting that back put an empty
        // assistant turn beside a correction naming a reply the model cannot
        // see (#437). Nothing to correct against; the retry goes as the plain question.
        if (!$this->hasContent($lastInvalidReply)) {
            return $messages;
        }

        return [...$messages, ...$this->correctiveTail($lastInvalidReply, $correction)];
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
    private function historySections(RecommendationHistoryModel $history, int $descriptionLength): string
    {
        return implode("\n\n", [
            $this->favoritesSection($history, $descriptionLength),
            $this->historySection('KEPT (newest first):', $history->kept, $descriptionLength),
            $this->historySection('VIEWED (newest first):', $history->viewed, $descriptionLength),
        ]);
    }

    private function favoritesSection(RecommendationHistoryModel $history, int $descriptionLength): string
    {
        return $this->historySection('FAVORITES (newest first):', $history->favorites, $descriptionLength);
    }

    /**
     * @param list<PromptLineModel> $lines
     */
    private function historySection(string $header, array $lines, int $descriptionLength): string
    {
        if ([] === $lines) {
            return $header . "\n- none";
        }

        $rendered = array_map(
            fn (PromptLineModel $line): string => $this->historyLine($line, $descriptionLength),
            $lines,
        );

        return $header . "\n" . implode("\n", $rendered);
    }

    /**
     * @param list<PromptLineModel> $candidateLines
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
            fn (PromptLineModel $line): string => $this->candidateLine($line, $descriptionLength),
            $candidateLines,
        );

        return $header . "\n" . implode("\n", $rendered);
    }

    private function historyLine(PromptLineModel $line, int $descriptionLength): string
    {
        $description = $this->truncatedDescription($line->description, $descriptionLength);

        return null === $description
            ? \sprintf('- %s — %s — %s', $line->title, $line->feedName, $line->date)
            : \sprintf('- %s — %s — %s — %s', $line->title, $line->feedName, $line->date, $description);
    }

    private function candidateLine(PromptLineModel $line, int $descriptionLength): string
    {
        $description = $this->truncatedDescription($line->description, $descriptionLength);
        $baseLine = \sprintf('- [%d] %s — %s — %s', $line->entryId, $line->title, $line->feedName, $line->date);

        return null === $description
            ? $baseLine
            : $baseLine . ' — ' . $description;
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
