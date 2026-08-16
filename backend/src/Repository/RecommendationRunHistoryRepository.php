<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Recommendation\MonthWindow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The read side of the run cost history (#409): one calendar month at a
 * time, the whole-account spend timeline behind it, and the all-time total.
 *
 * A previous review rejected a RecommendationRunHistoryRepository because it
 * existed only to hold a verbatim copy of findNewestForUser(), and the PHPMD
 * count that justified splitting it off was inflated by that duplicate. This
 * one holds three distinct queries — pageForMonth(), spendTimeline() and
 * totalCostNanoCredits() — plus the private projection they share, duplicates
 * nothing, and moving them here gives RecommendationRunRepository back the
 * headroom PHPMD's ten-method ceiling had run out of.
 *
 * @extends ServiceEntityRepository<RecommendationRun>
 *
 * @phpstan-type HistoryRow array{
 *     id: int,
 *     status: string,
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
    /**
     * How many runs one page of history holds (#409). The list is a spending
     * record a human reads, not a dataset — fifty rows is more than anyone
     * scrolls through in one month, and totalCostNanoCredits() below is
     * computed over every run anyway, so this cap never makes the number on
     * screen wrong.
     */
    public const int HISTORY_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRun::class);
    }

    /**
     * One month's runs, newest first, as the history payload needs them:
     * scalars, not entities. A RecommendationRun carries the frozen candidate
     * pool, every batch winner with its free-text reason, the last rejected
     * provider reply and the error text, and none of that belongs on the path
     * that formats twelve numbers.
     *
     * Reads one row more than the limit. The caller keeps the limit's worth
     * and reads the extra row purely as "there is another page" — a COUNT for
     * the same answer would be a second query on every page.
     *
     * $beforeRunId pages backwards within the month. Ids ascend with creation
     * time, so one integer expresses the whole keyset; the opaque composite
     * cursor RecommendationCursor encodes exists because the for-you feed
     * orders by two columns, and this does not.
     *
     * @return list<HistoryRow>
     */
    public function pageForMonth(User $user, MonthWindow $window, ?int $beforeRunId): array
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
     * Every run's creation time and price, newest first — the two scalars the
     * month summaries are built from.
     *
     * Grouped in PHP rather than by the database, and deliberately so: DQL has
     * no month extraction, and the buckets have to be cut in the viewer's
     * timezone while the column holds naive UTC, which no portable expression
     * can shift before grouping. The alternative is platform-branched native
     * SQL, which this codebase confines to migrations.
     *
     * The cost of that choice is this read: two scalars for every run the
     * account owns. It is the same shape #409's first pass removed from the
     * history page and the difference is the point — that one pulled twelve
     * fields plus the JSON and TEXT columns above.
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

    /**
     * The account's whole spend, summed in the database over every run it
     * ever made — deliberately not over one page of it. An account whose runs
     * all went unpriced sums to null, which is the honest answer: nothing
     * reported a price, as opposed to everything reporting zero.
     */
    public function totalCostNanoCredits(User $user): ?int
    {
        $total = $this->createQueryBuilder('r')
            // The seven usage columns live behind a ProviderUsage embeddable
            // (PHPMD TooManyFields on RecommendationRun). The *column* names
            // are unprefixed and unchanged, but the DQL field path is not:
            // `r.costNanoCredits` throws "has no field or association named".
            ->select('SUM(r.providerUsage.costNanoCredits)')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $total ? null : (int) $total;
    }

    /**
     * The twelve-field scalar select every history row needs, scoped to one
     * account. Extracted so pageForMonth() adds only a date range and a
     * limit on top of it, instead of the field list — and the embeddable's
     * DQL-path comment — existing twice.
     */
    private function historyRowsFor(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->select(
                'r.id AS id',
                'r.status AS status',
                'r.createdAt AS createdAt',
                'r.completedAt AS completedAt',
                // The embeddable's DQL field path, not the column name — see
                // totalCostNanoCredits() above for why the two differ.
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
