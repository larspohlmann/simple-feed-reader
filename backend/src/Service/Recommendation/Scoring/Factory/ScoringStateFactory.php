<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Scoring\Support\FittingPrefix;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;
use App\Service\Recommendation\Support\TokenEstimate;

final readonly class ScoringStateFactory
{
    /** What ScoringBatchPacker reserves for the state in every request: guidance first, then profile, then favorites. */
    public const int STATE_TOKEN_BUDGET = 10_000;

    /**
     * @param list<ArticleLineModel> $favorites newest first
     *
     * @return array{profile: string, guidance?: string, favorites?: non-empty-list<array<string, string>>}
     */
    public function create(string $profile, ?string $guidance, array $favorites): array
    {
        // Fitted beside an empty profile: the profile's key must still fit once the guidance has taken the budget.
        $guidanceState = null === $guidance
            ? []
            : ['guidance' => self::fitted('guidance', mb_scrub($guidance, 'UTF-8'), ['profile' => ''])];
        $state = ['profile' => self::fitted('profile', mb_scrub($profile, 'UTF-8'), $guidanceState)] + $guidanceState;

        return $state + self::fittingFavorites($favorites, $state);
    }

    /**
     * @param array<string, string> $others
     *
     * @return string the longest prefix of $text that keeps the state within the budget under $key
     */
    private static function fitted(string $key, string $text, array $others): string
    {
        return FittingPrefix::of($text, static fn (string $prefix): bool => self::fits([$key => $prefix] + $others));
    }

    /**
     * @param list<ArticleLineModel> $favorites
     * @param array<string, string>  $others
     *
     * @return array{favorites?: non-empty-list<array<string, string>>} the newest whole favorites that still fit
     */
    private static function fittingFavorites(array $favorites, array $others): array
    {
        $fitting = [];
        foreach (array_map(ScoringArticle::of(...), $favorites) as $article) {
            $candidate = [...$fitting, $article];
            if (!self::fits($others + ['favorites' => $candidate])) {
                break;
            }
            $fitting = $candidate;
        }

        return [] === $fitting ? [] : ['favorites' => $fitting];
    }

    /** @param array<string, mixed> $state */
    private static function fits(array $state): bool
    {
        return TokenEstimate::of(CompactJson::encode($state)) <= self::STATE_TOKEN_BUDGET;
    }
}
