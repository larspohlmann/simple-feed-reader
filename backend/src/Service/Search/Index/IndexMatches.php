<?php

declare(strict_types=1);

namespace App\Service\Search\Index;

/**
 * What one search read answers with: the matching entry ids, in the engine's
 * own order, and the words it actually matched — which can differ from the
 * words the user typed once typo tolerance is in play.
 */
final readonly class IndexMatches
{
    /**
     * @param list<int>    $entryIds     newest first, exactly as the engine returned them
     * @param list<string> $matchedWords deduplicated, case as the engine highlighted it
     */
    public function __construct(
        public array $entryIds,
        public array $matchedWords,
    ) {
    }
}
