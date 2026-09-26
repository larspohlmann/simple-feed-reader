<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\RecommendationCandidateRepository;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Loads the candidate pool the recommendation prompt picks from, and re-resolves a checkpointed batch of ids. */
final readonly class RecommendationCandidateLoader
{
    public function __construct(private RecommendationCandidateRepository $candidates)
    {
    }

    /**
     * The newest $request->poolSize candidates in an order seeded by $request->orderSeed, so batches sample the pool
     * rather than cluster by recency (#344); the same seed always gives the same order.
     *
     * @return list<PromptLine>
     */
    public function load(int $userId, CandidatePoolRequest $request): array
    {
        $lines = array_map(
            PromptLine::of(...),
            $this->candidates->newestPool($userId, $request->since, $request->poolSize),
        );

        /** @var list<PromptLine> $shuffled shuffleArray() has no generic stub, so it widens to mixed */
        $shuffled = (new Randomizer(new Mt19937($request->orderSeed)))->shuffleArray($lines);

        return $shuffled;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, PromptLine>
     */
    public function linesForIds(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $linesById = [];
        foreach ($this->candidates->forIds($userId, $entryIds) as $titled) {
            $line = PromptLine::of($titled);
            $linesById[$line->entryId] = $line;
        }

        return $linesById;
    }

    /**
     * Null when the ids resolve to nothing: pruned or unsubscribed ids drop out of the total and the range alike.
     *
     * @param list<int> $entryIds
     */
    public function summarize(int $userId, array $entryIds): ?CandidatePoolSummary
    {
        if ($entryIds === []) {
            return null;
        }

        return $this->hydrateSummary($this->candidates->span($userId, $entryIds));
    }

    /**
     * @param array{total: int, oldest: ?string, newest: ?string} $row an aggregate row over the scoped id set
     */
    private function hydrateSummary(array $row): ?CandidatePoolSummary
    {
        $oldest = $row['oldest'];
        $newest = $row['newest'];
        if (!\is_string($oldest) || !\is_string($newest)) {
            return null;
        }

        return new CandidatePoolSummary(
            total: (int) $row['total'],
            oldest: (new \DateTimeImmutable($oldest))->format('Y-m-d'),
            newest: (new \DateTimeImmutable($newest))->format('Y-m-d'),
        );
    }
}
