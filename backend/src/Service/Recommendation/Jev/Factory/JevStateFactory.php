<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Factory;

use App\Service\Recommendation\Jev\Support\FittingPrefix;
use App\Service\Recommendation\Jev\Support\JevTokenEstimate;

/** The reader as System One's `state`: the distilled profile, and the guidance when there is one. */
final readonly class JevStateFactory
{
    /** What JevBatchPacker reserves for the state in every request; the guidance wins it, the profile gets the rest. */
    public const int STATE_TOKEN_BUDGET = 4_000;

    /** @return array<string, string> */
    public function create(string $profile, ?string $guidance): array
    {
        // Fitted beside an empty profile: the profile's key must still fit once the guidance has taken the budget.
        $guidanceState = null === $guidance
            ? []
            : self::fitted('guidance', mb_scrub($guidance, 'UTF-8'), ['profile' => '']);

        return self::fitted('profile', mb_scrub($profile, 'UTF-8'), $guidanceState) + $guidanceState;
    }

    /**
     * @param array<string, string> $others
     *
     * @return array<string, string> $key and the longest prefix of $text that keeps the state within the budget
     */
    private static function fitted(string $key, string $text, array $others): array
    {
        $fits = static fn (string $prefix): bool
            => JevTokenEstimate::ofJson([$key => $prefix] + $others) <= self::STATE_TOKEN_BUDGET;

        return [$key => FittingPrefix::of($text, $fits)];
    }
}
