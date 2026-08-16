<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The two reads behind the run cost history (#409): the account's newest
 * runs and the all-time total banked over every run it ever made.
 *
 * Its own class rather than two more methods on RecommendationRunRepository:
 * that repository was already at nine public methods (PHPMD's
 * TooManyPublicMethods ceiling is ten counting the constructor), each with a
 * distinct external caller, so none of them was a duplicate a merge could
 * remove. A spending report is a read concern of its own — the same
 * reasoning RecommendationRunHistoryController's class doc gives for not
 * becoming a seventh action on RecommendationRunController — so it gets a
 * second, focused ServiceEntityRepository for the same entity rather than
 * growing the first one past its ceiling.
 *
 * @extends ServiceEntityRepository<RecommendationRun>
 */
final class RecommendationRunHistoryRepository extends ServiceEntityRepository
{
    /**
     * How many runs the history endpoint answers with. The list is a
     * spending record a human reads, not a dataset — fifty rows is more than
     * anyone scrolls, and the total below is computed over every run anyway,
     * so this cap never makes the number on screen wrong.
     */
    public const int HISTORY_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRun::class);
    }

    /**
     * @return list<RecommendationRun> newest first, capped at HISTORY_LIMIT
     */
    public function historyForUser(User $user): array
    {
        /** @var list<RecommendationRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(self::HISTORY_LIMIT)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * The account's whole spend, summed in the database over every run it
     * ever made — deliberately not over the page historyForUser() returns. An
     * account whose runs all went unpriced sums to null, which is the honest
     * answer: nothing reported a price, as opposed to everything reporting
     * zero.
     */
    public function totalCostNanoCredits(User $user): ?int
    {
        $total = $this->createQueryBuilder('r')
            // Task 4 extracted the seven columns into a `ProviderUsage`
            // embeddable (PHPMD TooManyFields). The *column* names are
            // unprefixed and unchanged, but the DQL field path is not:
            // `r.costNanoCredits` throws "has no field or association named".
            ->select('SUM(r.providerUsage.costNanoCredits)')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? null : (int) $total;
    }
}
