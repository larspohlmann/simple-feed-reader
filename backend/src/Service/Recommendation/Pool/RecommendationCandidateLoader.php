<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Pool;

use App\Repository\RecommendationCandidateRepository;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\CandidatePoolRequestModel;
use App\Service\Recommendation\Pool\Model\CandidatePoolSummaryModel;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Loads the candidate pool a run picks from, and re-resolves a checkpointed batch of ids. */
final readonly class RecommendationCandidateLoader
{
    public function __construct(private RecommendationCandidateRepository $candidates)
    {
    }

    /**
     * The newest $request->poolSize candidates in an order seeded by $request->orderSeed, so batches sample the pool
     * rather than cluster by recency; the same seed always gives the same order.
     *
     * @return list<ArticleLineModel>
     */
    public function load(int $userId, CandidatePoolRequestModel $request): array
    {
        $lines = array_map(
            ArticleLineModel::of(...),
            $this->candidates->newestPool($userId, $request->since, $request->poolSize),
        );

        /** @var list<ArticleLineModel> $shuffled shuffleArray() has no generic stub, so it widens to mixed */
        $shuffled = (new Randomizer(new Mt19937($request->orderSeed)))->shuffleArray($lines);

        return $shuffled;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, ArticleLineModel>
     */
    public function linesForIds(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $linesById = [];
        foreach ($this->candidates->forIds($userId, $entryIds) as $titled) {
            $line = ArticleLineModel::of($titled);
            $linesById[$line->entryId] = $line;
        }

        return $linesById;
    }

    /**
     * Null when the ids resolve to nothing: pruned or unsubscribed ids drop out of the total and the range alike.
     *
     * @param list<int> $entryIds
     */
    public function summarize(int $userId, array $entryIds): ?CandidatePoolSummaryModel
    {
        if ($entryIds === []) {
            return null;
        }

        return $this->hydrateSummary($this->candidates->span($userId, $entryIds));
    }

    /**
     * @param array{total: int, oldest: ?string, newest: ?string} $row an aggregate row over the scoped id set
     */
    private function hydrateSummary(array $row): ?CandidatePoolSummaryModel
    {
        $oldest = $row['oldest'];
        $newest = $row['newest'];
        if (!\is_string($oldest) || !\is_string($newest)) {
            return null;
        }

        return new CandidatePoolSummaryModel(
            total: (int) $row['total'],
            oldest: (new \DateTimeImmutable($oldest))->format('Y-m-d'),
            newest: (new \DateTimeImmutable($newest))->format('Y-m-d'),
        );
    }
}
