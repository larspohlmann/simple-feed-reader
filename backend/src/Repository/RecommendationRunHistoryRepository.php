<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\StoryAiRun;
use App\Entity\User;
use App\Enum\AiRunKind;
use App\Enum\ProfileRunOutcome;
use App\Enum\RunStatus;
use App\Service\Curation\Recommendation\Feed\Model\MonthWindowModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryCursorModel;
use App\Service\Curation\Recommendation\Feed\Model\RunHistoryFilterModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The run cost history's reads over every run kind: one month's page, the spend timeline, and the all-time total.
 *
 * @extends ServiceEntityRepository<RecommendationRun>
 *
 * @phpstan-type HistoryRow array{
 *     id: int,
 *     kind: AiRunKind,
 *     status: ?RunStatus,
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
 * @phpstan-type SpendRow array{createdAt: \DateTimeImmutable, costNanoCredits: int|string|null}
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
     * Each kind reads one row past the limit, which says another page exists; the caller cuts the merged rows.
     *
     * @return list<HistoryRow>
     */
    public function pageForMonth(User $user, MonthWindowModel $window, RunHistoryFilterModel $filter): array
    {
        $rows = [];
        foreach (self::resolveKinds($filter->kind) as $kind) {
            $query = $this->buildHistoryRowsQuery($kind, $user)
                ->andWhere('r.createdAt >= :start')->setParameter('start', $window->startUtc)
                ->andWhere('r.createdAt < :end')->setParameter('end', $window->endUtc)
                ->orderBy('r.createdAt', 'DESC')
                ->addOrderBy('r.id', 'DESC')
                ->setMaxResults(self::HISTORY_LIMIT + 1);
            if (null !== $filter->before) {
                self::applyCursor($query, $kind, $filter->before);
            }

            /** @var list<array<string, mixed>> $kindRows */
            $kindRows = $query->getQuery()->getArrayResult();
            $rows = [...$rows, ...self::tagRows($kind, $kindRows)];
        }

        usort($rows, self::compareNewestFirst(...));

        return $rows;
    }

    /**
     * Every run's creation time and price, newest first, grouped by month in PHP: DQL has no month extraction, and the
     * buckets are cut in the viewer's zone while the column holds naive UTC. A null kind reads every kind.
     *
     * @return list<SpendRow>
     */
    public function spendTimeline(User $user, ?AiRunKind $kind): array
    {
        $rows = [];
        foreach (self::resolveKinds($kind) as $runKind) {
            /** @var list<SpendRow> $kindRows */
            $kindRows = $this->buildRunQuery($runKind, $user)
                ->select('r.createdAt AS createdAt', 'r.providerUsage.costNanoCredits AS costNanoCredits')
                ->getQuery()
                ->getArrayResult();
            $rows = [...$rows, ...$kindRows];
        }

        usort($rows, static fn (array $left, array $right): int => $right['createdAt'] <=> $left['createdAt']);

        return $rows;
    }

    /** Summed over every run of every kind, not one page; null when no run reported a price, which is not zero. */
    public function totalCostNanoCredits(User $user): ?int
    {
        $totals = [];
        foreach (AiRunKind::cases() as $kind) {
            $total = $this->buildRunQuery($kind, $user)
                ->select('SUM(r.providerUsage.costNanoCredits)')
                ->getQuery()
                ->getSingleScalarResult();
            if (null !== $total) {
                $totals[] = (int) $total;
            }
        }

        return [] === $totals ? null : array_sum($totals);
    }

    private function buildHistoryRowsQuery(AiRunKind $kind, User $user): QueryBuilder
    {
        $query = $this->buildRunQuery($kind, $user)->select(
            'r.id AS id',
            'r.createdAt AS createdAt',
            'r.providerUsage.providerHost AS providerHost',
            'r.providerUsage.model AS model',
            'r.providerUsage.promptTokens AS promptTokens',
            'r.providerUsage.completionTokens AS completionTokens',
            'r.providerUsage.reasoningTokens AS reasoningTokens',
            'r.providerUsage.cachedTokens AS cachedTokens',
            'r.providerUsage.costNanoCredits AS costNanoCredits',
        );
        if (AiRunKind::Story === $kind) {
            return $query;
        }

        return $query->addSelect('r.status AS status', 'r.completedAt AS completedAt');
    }

    private function buildRunQuery(AiRunKind $kind, User $user): QueryBuilder
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->from(self::resolveEntityClass($kind), 'r')
            ->andWhere('r.user = :user')->setParameter('user', $user);
        if (AiRunKind::Profile !== $kind) {
            return $query;
        }

        return $query->andWhere('r.outcome IS NULL OR r.outcome NOT IN (:withoutCall)')
            ->setParameter('withoutCall', ProfileRunOutcome::withoutCall());
    }

    /** @return class-string */
    private static function resolveEntityClass(AiRunKind $kind): string
    {
        return match ($kind) {
            AiRunKind::ForYou => RecommendationRun::class,
            AiRunKind::Profile => ProfileRun::class,
            AiRunKind::Story => StoryAiRun::class,
        };
    }

    /** @return list<AiRunKind> */
    private static function resolveKinds(?AiRunKind $kind): array
    {
        return null === $kind ? AiRunKind::cases() : [$kind];
    }

    /** Within the cursor's second the kind rank orders the tables, so a page break inside one second loses no run. */
    private static function applyCursor(QueryBuilder $query, AiRunKind $kind, RunHistoryCursorModel $before): void
    {
        $query->setParameter('at', $before->createdAt, Types::DATETIME_IMMUTABLE);
        $rankOrder = $kind->tieRank() <=> $before->kind->tieRank();
        if (0 !== $rankOrder) {
            $query->andWhere(1 === $rankOrder ? 'r.createdAt < :at' : 'r.createdAt <= :at');

            return;
        }

        $query->andWhere('r.createdAt < :at OR (r.createdAt = :at AND r.id < :id)')
            ->setParameter('id', $before->id);
    }

    /**
     * A story run has no status or completion, and DQL cannot select a typed null, so those keys are added here.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<HistoryRow>
     */
    private static function tagRows(AiRunKind $kind, array $rows): array
    {
        /** @var list<HistoryRow> $tagged */
        $tagged = array_map(
            static fn (array $row): array => ['status' => null, 'completedAt' => null, ...$row, 'kind' => $kind],
            $rows,
        );

        return $tagged;
    }

    /**
     * @param HistoryRow $left
     * @param HistoryRow $right
     */
    private static function compareNewestFirst(array $left, array $right): int
    {
        return [$right['createdAt'], $right['kind']->tieRank(), $right['id']]
            <=> [$left['createdAt'], $left['kind']->tieRank(), $left['id']];
    }
}
