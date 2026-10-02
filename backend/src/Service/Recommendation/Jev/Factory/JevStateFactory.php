<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Factory;

use App\Service\Recommendation\Jev\Support\JevArticle;
use App\Service\Recommendation\Jev\Support\JevTokenEstimate;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;

/**
 * The reader as System One's `state`: the guidance when set, then the weighted history, newest first. Over the budget
 * the history loses its oldest lines, viewed before kept before favorites, the weakest signal first.
 */
final readonly class JevStateFactory
{
    /** Leaves the questions over half of the 64k request; TypeSafe bounds state plus the longest question at 32k. */
    public const int STATE_TOKEN_BUDGET = 24_000;

    private const int HISTORY_DESCRIPTION_CHARACTERS = 280;

    private const array WEAKEST_SECTION_FIRST = ['viewed', 'kept', 'favorites'];

    /** @return array<string, mixed> */
    public function create(?string $guidance, RecommendationHistoryModel $history): array
    {
        $sections = [
            'favorites' => self::articles($history->favorites),
            'kept' => self::articles($history->kept),
            'viewed' => self::articles($history->viewed),
        ];

        foreach (self::WEAKEST_SECTION_FIRST as $section) {
            while (
                [] !== $sections[$section]
                && JevTokenEstimate::ofJson(self::stateOf($guidance, $sections)) > self::STATE_TOKEN_BUDGET
            ) {
                array_pop($sections[$section]);
            }
        }

        return self::stateOf($guidance, $sections);
    }

    /**
     * @param list<ArticleLineModel> $lines
     *
     * @return list<array<string, string>>
     */
    private static function articles(array $lines): array
    {
        return array_map(
            static fn (ArticleLineModel $line): array => JevArticle::of($line, self::HISTORY_DESCRIPTION_CHARACTERS),
            $lines,
        );
    }

    /**
     * @param array<string, list<array<string, string>>> $sections
     *
     * @return array<string, mixed>
     */
    private static function stateOf(?string $guidance, array $sections): array
    {
        $history = ['history' => $sections];

        return null === $guidance ? $history : ['guidance' => mb_scrub($guidance, 'UTF-8')] + $history;
    }
}
