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
    private const int TOKENS_PER_PICK = 40;
    private const int MINIMUM_BATCH_SIZE = 10;
    private const int DESCRIPTION_MIN_CHARS = 120;
    private const int DESCRIPTION_MAX_CHARS = 480;
    private const int DESCRIPTION_WINDOW_DIVISOR = 137;
    private const int MERGE_WINNERS_PER_BATCH_FACTOR = 2;

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
        $descriptionLength = $this->descriptionLength($settings->contextWindow);
        $historyTokens = $this->tokens($this->historySections($history, $descriptionLength));
        $responseReserve = min($settings->picksLimit * self::TOKENS_PER_PICK, intdiv($settings->contextWindow, 4));
        $budget = $settings->contextWindow - self::FIXED_OVERHEAD_TOKENS - $responseReserve - $historyTokens;

        $batches = [];
        $current = [];
        $used = 0;

        foreach ($candidates as $candidate) {
            $lineTokens = $this->tokens($this->candidateLine($candidate, $descriptionLength));
            if ([] !== $current && $used + $lineTokens > $budget && \count($current) >= self::MINIMUM_BATCH_SIZE) {
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
    ): array {
        $descriptionLength = $this->descriptionLength($settings->contextWindow);

        $guidance = $settings->guidancePrompt ?? RecommendationPromptText::DEFAULT_GUIDANCE;
        $contract = \sprintf(RecommendationPromptText::OUTPUT_CONTRACT, $settings->picksLimit);
        $system = implode("\n\n", [RecommendationPromptText::SYSTEM_ROLE, $guidance, $contract]);

        $user = implode("\n\n", [
            $this->historySections($history, $descriptionLength),
            $this->candidateSection($candidateLines, $descriptionLength),
        ]);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @param list<list<array{id: int, reason: string}>> $winners
     * @param array<int, PromptLine>                     $linesById
     *
     * @return list<array{role: string, content: string}>
     *
     * @throws \LogicException if called with no batches of winners to merge
     */
    public function mergeMessages(array $winners, array $linesById, EffectiveRecommendationSettings $settings): array
    {
        if ([] === $winners) {
            throw new \LogicException('The merge phase requires at least one batch of winners.');
        }

        $perBatchCap = max(1, intdiv(self::MERGE_WINNERS_PER_BATCH_FACTOR * $settings->picksLimit, \count($winners)));

        $guidance = $settings->guidancePrompt ?? RecommendationPromptText::DEFAULT_GUIDANCE;
        $contract = \sprintf(RecommendationPromptText::OUTPUT_CONTRACT, $settings->picksLimit);
        $system = implode("\n\n", [RecommendationPromptText::MERGE_ROLE, $guidance, $contract]);

        $lines = [];
        foreach ($winners as $batch) {
            foreach (\array_slice($batch, 0, $perBatchCap) as $winner) {
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
        }

        $user = "WINNERS:\n" . ([] === $lines ? '- none' : implode("\n", $lines));

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function correctiveTail(string $invalidReply): array
    {
        return [
            ['role' => 'assistant', 'content' => $invalidReply],
            ['role' => 'user', 'content' => RecommendationPromptText::CORRECTIVE],
        ];
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

        $rendered = array_map(
            fn (PromptLine $line): string => $this->candidateLine($line, $descriptionLength),
            $candidateLines,
        );

        return "CANDIDATES:\n" . implode("\n", $rendered);
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

    private function tokens(string $text): int
    {
        return intdiv(\strlen($text), self::CHARS_PER_TOKEN) + 1;
    }
}
