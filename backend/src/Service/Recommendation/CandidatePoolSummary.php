<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * A one-query summary of the whole candidate snapshot pool: how many entries it
 * still holds and the date span they cover. Because the pool is shuffled into
 * random batches (#344), each batch carries this global frame so the model can
 * judge a candidate's recency and rarity against the whole set, not against the
 * few local dates of its own random sample.
 *
 * The dates are pre-formatted `Y-m-d`, matching the candidate-line date format,
 * and every field is scoped through the same subscription gate the candidate
 * lines are — a pruned or unsubscribed entry counts in none of them.
 */
final readonly class CandidatePoolSummary
{
    public function __construct(
        public int $total,
        public string $oldest,
        public string $newest,
    ) {
    }
}
