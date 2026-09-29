<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Service\Recommendation\Feed\Model\MonthWindowModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The run cost history's reads: one month's page, the spend timeline, and the all-time total.
 *
 * @extends ServiceEntityRepository<RecommendationRun>
 *
 * @phpstan-type HistoryRow array{
 *     id: int,
 *     status: RunStatus,
 *     providerHost: ?string,
 *     model: ?string,
 *     createdAt: \DateTimeImmutable,
 *     completedAt: ?\DateTimeImmutable,
 *     promptTokens: int,
 *     completionTokens: int,
 *     reasoningTokens: int,
 *     cachedTokens: int,
 *     costNanoCredits: int|string|null,
 * }
 */
final class RecommendationRunHistoryRepository extends ServiceEntityRepository
{
    /** Runs per page. totalCostNanoCredits() sums every run, so the cap never changes the total shown. */
    public const int HISTORY_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRun::class);
    }

    /**
     * One month's runs as scalars, newest first: an entity drags the frozen pool, the winners and the provider replies.
     * One row past the limit says another page exists; $beforeRunId pages back, as ids ascend with creation time.
     *
     * @return list<HistoryRow>
     */
    public function pageForMonth(User $user, MonthWindowModel $window, ?int $beforeRunId): array
    {
        $query = $this->historyRowsFor($user)
            ->andWhere('r.createdAt >= :start')->setParameter('start', $window->startUtc)
            ->andWhere('r.createdAt < :end')->setParameter('end', $window->endUtc)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(self::HISTORY_LIMIT + 1);

        if (null !== $beforeRunId) {
            $query->andWhere('r.id < :before')->setParameter('before', $beforeRunId);
        }

        /** @var list<HistoryRow> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * Every run's creation time and price, newest first, grouped by month in PHP: DQL has no month extraction, and the
     * buckets are cut in the viewer's zone while the column holds naive UTC.
     *
     * @return list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}>
     */
    public function spendTimeline(User $user): array
    {
        /** @var list<array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.createdAt AS createdAt', 'r.providerUsage.costNanoCredits AS costNanoCredits')
            ->andWhere('r.user = :user')->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /** Summed over every run, not one page; null when no run reported a price, which is not the same as zero. */
    public function totalCostNanoCredits(User $user): ?int
    {
        $total = $this->createQueryBuilder('r')
            ->select('SUM(r.providerUsage.costNanoCredits)')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? null : (int) $total;
    }

    private function historyRowsFor(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->select(
                'r.id AS id',
                'r.status AS status',
                'r.createdAt AS createdAt',
                'r.completedAt AS completedAt',
                'r.providerUsage.providerHost AS providerHost',
                'r.providerUsage.model AS model',
                'r.providerUsage.promptTokens AS promptTokens',
                'r.providerUsage.completionTokens AS completionTokens',
                'r.providerUsage.reasoningTokens AS reasoningTokens',
                'r.providerUsage.cachedTokens AS cachedTokens',
                'r.providerUsage.costNanoCredits AS costNanoCredits',
            )
            ->andWhere('r.user = :user')->setParameter('user', $user);
    }
}
