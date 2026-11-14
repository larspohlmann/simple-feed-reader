<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Exception\InvalidSelectionException;

/** A reorder must list exactly the set it reorders: a missing, extra or repeated id leaves the positions ambiguous. */
final readonly class ExactSetGuard
{
    /**
     * @param list<int> $requested
     * @param list<int> $owned
     */
    public function assertPermutation(array $requested, array $owned, string $message): void
    {
        // $owned comes from map keys (unique), so once both are sorted a plain
        // equality rejects missing ids, extras, AND duplicates in $requested.
        $sortedRequested = array_map('intval', $requested);
        sort($sortedRequested);
        $sortedOwned = array_map('intval', $owned);
        sort($sortedOwned);

        if ($sortedRequested !== $sortedOwned) {
            throw new InvalidSelectionException($message);
        }
    }
}
