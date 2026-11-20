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
     * @param \Closure(int): float $rarityOf
     *
     * @return array<int, float> the shared weights with the parts' capped weight added
     */
    public static function addedTo(array $shared, array $parts, \Closure $rarityOf): array
    {
        foreach ($parts as $earlierEntryId => $partsOfPair) {
            $shared[$earlierEntryId] = ($shared[$earlierEntryId] ?? 0.0) + (\count($partsOfPair) === 1
                ? self::computeOneCappedWeight($partsOfPair[0], $rarityOf)
                : self::computeCappedWeight($partsOfPair, $rarityOf));
        }

        return $shared;
    }

    /**
     * @param array{float, array<int, float>} $part
     * @param \Closure(int): float $rarityOf
     */
    private static function computeOneCappedWeight(array $part, \Closure $rarityOf): float
    {
        [$weight, $compounds] = $part;
        foreach ($compounds as $compoundStemId => $placeWeight) {
            $weight = min($weight, $placeWeight * $rarityOf($compoundStemId));
        }

        return $weight;
    }

    /**
     * A part coming through several compounds is held to the tightest of their caps.
     *
     * @param list<array{float, array<int, float>}> $parts
     * @param \Closure(int): float $rarityOf
     */
    private static function computeCappedWeight(array $parts, \Closure $rarityOf): float
    {
        $weightThrough = [];
        $ownWeights = [];
        foreach ($parts as [$weight, $compounds]) {
            foreach ($compounds as $compoundStemId => $placeWeight) {
                $weightThrough[$compoundStemId] = ($weightThrough[$compoundStemId] ?? 0.0) + $weight;
                $ownWeights[$compoundStemId] ??= $placeWeight * $rarityOf($compoundStemId);
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
