<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

/** Within one pair, a compound adds through its parts at most its own weight: place weight times rarity. */
final class PartWeightCaps
{
    /**
     * @param array<int, float> $shared earlier entry id => the weight shared through words
     * @param array<int, list<array{float, array<int, float>}>> $parts earlier entry id => each part's weight, and the
     *     compounds it comes through with their place weight
     * @param \Closure(int): float $rarity
     *
     * @return array<int, float> the shared weights with the parts' capped weight added
     */
    public static function addedTo(array $shared, array $parts, \Closure $rarity): array
    {
        foreach ($parts as $earlierEntryId => $pairParts) {
            $shared[$earlierEntryId] = ($shared[$earlierEntryId] ?? 0.0) + (\count($pairParts) === 1
                ? self::computeOneCappedWeight($pairParts[0], $rarity)
                : self::computeCappedWeight($pairParts, $rarity));
        }

        return $shared;
    }

    /**
     * @param array{float, array<int, float>} $part
     * @param \Closure(int): float $rarity
     */
    private static function computeOneCappedWeight(array $part, \Closure $rarity): float
    {
        [$weight, $compounds] = $part;
        foreach ($compounds as $compoundStemId => $placeWeight) {
            $weight = min($weight, $placeWeight * $rarity($compoundStemId));
        }

        return $weight;
    }

    /**
     * A part coming through several compounds is held to the tightest of their caps.
     *
     * @param list<array{float, array<int, float>}> $parts
     * @param \Closure(int): float $rarity
     */
    private static function computeCappedWeight(array $parts, \Closure $rarity): float
    {
        $weightThrough = [];
        $ownWeights = [];
        foreach ($parts as [$weight, $compounds]) {
            foreach ($compounds as $compoundStemId => $placeWeight) {
                $weightThrough[$compoundStemId] = ($weightThrough[$compoundStemId] ?? 0.0) + $weight;
                $ownWeights[$compoundStemId] ??= $placeWeight * $rarity($compoundStemId);
            }
        }
        $capped = 0.0;
        foreach ($parts as [$weight, $compounds]) {
            $factor = 1.0;
            foreach (array_keys($compounds) as $compoundStemId) {
                $factor = min($factor, $ownWeights[$compoundStemId] / $weightThrough[$compoundStemId]);
            }
            $capped += $weight * $factor;
        }

        return $capped;
    }

    private function __construct()
    {
    }
}
