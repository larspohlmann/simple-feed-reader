<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Llm\Prompt;

use App\Service\Recommendation\Llm\Completion\Model\Reasoning;
use App\Service\Recommendation\Llm\Prompt\Model\RecommendationResponseSchema;
use App\Service\Recommendation\Llm\Prompt\Pass\PromptContext;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\CandidatePoolSummaryModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Settings\Model\EffectiveRecommendationSettingsModel;
use App\Service\Recommendation\Support\ClippedText;
use App\Service\Recommendation\Support\TokenEstimate;

/**
 * Renders the recommendation prompts and packs the candidate pool into batches that fit the context window.
 */
final readonly class RecommendationPromptBuilder
{
    private const int FIXED_OVERHEAD_TOKENS = 1500;

    /**
     * What packBatches() budgets for the profile before distillation has written it. An estimate: DISTILL_ROLE's word
     * cap bounds the real profile to roughly this.
     */
    private const int ESTIMATED_PROFILE_TOKENS = 700;

    /**
     * Consolidation re-scores, reasons and dedups in one pass. The floor (twice the final list) keeps dedup's backfill
     * slack, the ceiling (six times) bounds reasoning on entries later dropped; the context window picks between them.
     */
    private const int CONSOLIDATION_MIN_INPUT_FACTOR = 2;
    private const int CONSOLIDATION_MAX_INPUT_FACTOR = 6;

    /** A candidate line's characters besides its description: id, title, feed, date and separators, roughly. */
    private const int CANDIDATE_LINE_FRAME_CHARS = 90;

    private const int MINIMUM_BATCH_SIZE = 10;

    /** Quotes an ordinary rejected reply whole, and a runaway's tens of kilobytes of repetition only in part. */
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
     * @param list<ArticleLineModel> $candidates
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
        $historyTokens = self::ESTIMATED_PROFILE_TOKENS + TokenEstimate::of($favoritesSection);
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
            $lineTokens = TokenEstimate::of($this->candidateLine($candidate, $descriptionLength));
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
        $picksLimit = $context->settings->poolLimits->picksLimit;
        $descriptionLength = $this->descriptionLength($contextWindow);
        $fixedInputTokens = self::FIXED_OVERHEAD_TOKENS
            + TokenEstimate::of((string) $context->profile)
            + TokenEstimate::of($this->favoritesSection($context->history, $descriptionLength));
        $lineChars = $descriptionLength + self::CANDIDATE_LINE_FRAME_CHARS;
        $perCandidateInputTokens = TokenEstimate::ofLength($lineChars);

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
     * @param list<ArticleLineModel> $candidateLines
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
     * The profile when there is one, FAVORITES only (KEPT and VIEWED shaped the profile), the whole pool's frame (each
     * batch is a random sample of it), then the candidates.
     *
     * @param list<ArticleLineModel> $candidateLines
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
     * The only call that sees KEPT and VIEWED; every later phase gets the profile it writes plus FAVORITES.
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
     * keeps the id a recommendation resolves back to; a winner pruned since its batch is dropped.
     *
     * @param list<array{id: int, score: int, reason: string}> $rankedPool
     * @param array<int, ArticleLineModel>                      $linesById
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
            static fn (array $winner): ?ArticleLineModel => $linesById[$winner['id']] ?? null,
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
     * A runaway reply repeats one line for tens of kilobytes: quoting it whole spends the retry's context on the loop
     * and primes the model to continue it. Its head shows the same mistake, and the marker says it is a fragment.
     */
    private function quotableReply(string $invalidReply): string
    {
        return ClippedText::of($invalidReply, self::QUOTED_REPLY_LIMIT_CHARS, self::QUOTED_REPLY_ELLIPSIS);
    }

    /**
     * Appends the model's last invalid reply and the phase's own correction, when there is a reply: the batch phase
     * passes each batch's own, distillation and consolidation the run's; each phase asks for different things back.
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
        // Empty counts as absent: a runaway cut before its body parses answers '', and an empty assistant turn
        // beside a correction names a reply the model cannot see. The retry goes as the plain question.
        if (!$this->hasContent($lastInvalidReply)) {
            return $messages;
        }

        return [...$messages, ...$this->correctiveTail($lastInvalidReply, $correction)];
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function hasContent(?string $value): bool
    {
        return null !== $value && '' !== trim($value);
    }

    /** FAVORITES, KEPT, then VIEWED, newest first within each. */
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
     * @param list<ArticleLineModel> $lines
     */
    private function historySection(string $header, array $lines, int $descriptionLength): string
    {
        if ([] === $lines) {
            return $header . "\n- none";
        }

        $rendered = array_map(
            fn (ArticleLineModel $line): string => $this->historyLine($line, $descriptionLength),
            $lines,
        );

        return $header . "\n" . implode("\n", $rendered);
    }

    /**
     * @param list<ArticleLineModel> $candidateLines
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
            fn (ArticleLineModel $line): string => $this->candidateLine($line, $descriptionLength),
            $candidateLines,
        );

        return $header . "\n" . implode("\n", $rendered);
    }

    private function historyLine(ArticleLineModel $line, int $descriptionLength): string
    {
        $description = $this->truncatedDescription($line->description, $descriptionLength);

        return null === $description
            ? \sprintf('- %s — %s — %s', $line->title, $line->feedName, $line->date)
            : \sprintf('- %s — %s — %s — %s', $line->title, $line->feedName, $line->date, $description);
    }

    private function candidateLine(ArticleLineModel $line, int $descriptionLength): string
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

        return ClippedText::of($description, $length);
    }
}
