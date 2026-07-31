<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Validates that a reorder request lists exactly the set it reorders.
 */
final readonly class ExactSetGuard
{
    /**
     * A reorder must be a permutation of the exact set it reorders — no missing,
     * extra, or duplicate ids — otherwise the resulting positions are ambiguous.
     *
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
            throw new UnprocessableEntityHttpException($message);
        }
    }
}
