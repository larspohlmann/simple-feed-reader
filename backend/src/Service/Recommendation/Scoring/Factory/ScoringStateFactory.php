<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Support\FittingPrefix;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;
use App\Service\Recommendation\Support\CompactJson;
use App\Service\Recommendation\Support\TokenEstimate;

/** The reader as one `state` within a token budget: guidance first, then profile, then the newest whole favorites. */
final readonly class ScoringStateFactory
{
    /** @return array{profile: string, guidance?: string, favorites?: non-empty-list<array<string, string>>} */
    public function create(ScoringReaderModel $reader, int $tokenBudget): array
    {
        $fits = static fn (array $state): bool => TokenEstimate::of(CompactJson::encode($state)) <= $tokenBudget;
        $guidanceState = self::guidanceState($reader->guidance, $fits);
        $state = [
            'profile' => FittingPrefix::of(
                mb_scrub($reader->profile, 'UTF-8'),
                static fn (string $prefix): bool => $fits(['profile' => $prefix] + $guidanceState),
            ),
        ] + $guidanceState;

        return $state + self::fittingFavorites($reader->favorites, $state, $fits);
    }

    /**
     * Fitted beside an empty profile: the profile's key must still fit once the guidance has taken the budget.
     *
     * @param \Closure(array<string, mixed>): bool $fits
     *
     * @return array{guidance?: string}
     */
    private static function guidanceState(?string $guidance, \Closure $fits): array
    {
        if (null === $guidance) {
            return [];
        }

        return ['guidance' => FittingPrefix::of(
            mb_scrub($guidance, 'UTF-8'),
            static fn (string $prefix): bool => $fits(['guidance' => $prefix] + ['profile' => '']),
        )];
    }

    /**
     * @param list<ArticleLineModel>               $favorites
     * @param array<string, string>                $others
     * @param \Closure(array<string, mixed>): bool $fits
     *
     * @return array{favorites?: non-empty-list<array<string, string>>} the newest whole favorites that still fit
     */
    private static function fittingFavorites(array $favorites, array $others, \Closure $fits): array
    {
        $fitting = [];
        foreach (array_map(ScoringArticle::of(...), $favorites) as $article) {
            $candidate = [...$fitting, $article];
            if (!$fits($others + ['favorites' => $candidate])) {
                break;
            }
            $fitting = $candidate;
        }

        return [] === $fitting ? [] : ['favorites' => $fitting];
    }
}
