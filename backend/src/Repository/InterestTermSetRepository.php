<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InterestTermSet;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Enum\ForYouStreamPrecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InterestTermSet> */
final class InterestTermSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterestTermSet::class);
    }

    public function findForUser(User $user): ?InterestTermSet
    {
        return $this->find($user);
    }

    public function hasEnabledStreamForUser(int $userId): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('1')
            ->andWhere('IDENTITY(s.user) = :userId')
            ->setParameter('userId', $userId)
            ->setMaxResults(1);

        return $this->restrictToEnabledStream($qb)->getQuery()->getOneOrNullResult() !== null;
    }

    /** @return array<int, int> user id => mark, for the active, stream-enabled accounts whose mark is below the ceiling */
    public function findBelowMark(int $ceiling, \DateTimeImmutable $now): array
    {
        $qb = $this->restrictToEnabledStream(
            $this->buildBelowMarkQuery($ceiling, $now)
                ->select('IDENTITY(s.user) AS userId', 's.matchedUpToEntryId AS mark'),
        );

        /** @var list<array{userId: int|string, mark: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $marksByUserId = [];
        foreach ($rows as $row) {
            $marksByUserId[(int) $row['userId']] = $row['mark'];
        }

        return $marksByUserId;
    }

    /** @return list<int> the active accounts that switched their stream off and whose mark is below the ceiling */
    public function findDisabledBelowMark(int $ceiling, \DateTimeImmutable $now): array
    {
        /** @var list<int|string> $userIds */
        $userIds = $this->buildBelowMarkQuery($ceiling, $now)
            ->select('IDENTITY(s.user)')
            ->join(RecommendationSettings::class, 'settings', 'WITH', 'settings.user = s.user')
            ->andWhere('settings.forYouStream.enabled = false')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $userIds);
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, ForYouStreamPrecision> only the owners that have a settings row
     */
    public function findStreamPrecisions(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<array{userId: int|string, precision: ForYouStreamPrecision}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.user) AS userId', 'settings.forYouStream.precision AS precision')
            ->join(RecommendationSettings::class, 'settings', 'WITH', 'settings.user = s.user')
            ->andWhere('IDENTITY(s.user) IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getArrayResult();

        $precisionsByUserId = [];
        foreach ($rows as $row) {
            $precisionsByUserId[(int) $row['userId']] = $row['precision'];
        }

        return $precisionsByUserId;
    }

    /** @param list<int> $userIds */
    public function advanceMarks(array $userIds, int $entryId): void
    {
        if ($userIds === []) {
            return;
        }

        $this->createQueryBuilder('s')
            ->update()
            ->set('s.matchedUpToEntryId', ':entryId')
            ->andWhere('IDENTITY(s.user) IN (:userIds)')
            ->andWhere('s.matchedUpToEntryId < :entryId')
            ->setParameter('entryId', $entryId)
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->execute();
    }

    /** @return list<InterestTermSet> of active accounts, whose rarities were never computed or are older than the cutoff */
    public function findWithStaleRarities(\DateTimeImmutable $refreshedBefore, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.user', 'owner')
            ->andWhere('s.raritiesRefreshedAt IS NULL OR s.raritiesRefreshedAt < :refreshedBefore')
            ->setParameter('refreshedBefore', $refreshedBefore)
            ->addOrderBy('s.raritiesRefreshedAt', 'ASC')
            ->addOrderBy('owner.id', 'ASC');
        ActiveAccountDql::restrict($qb, 'owner', $now);

        /** @var list<InterestTermSet> $sets */
        $sets = $qb->getQuery()->getResult();

        return $sets;
    }

    private function restrictToEnabledStream(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->leftJoin(RecommendationSettings::class, 'settings', 'WITH', 'settings.user = s.user')
            ->andWhere('settings.id IS NULL OR settings.forYouStream.enabled = true');
    }

    private function buildBelowMarkQuery(int $ceiling, \DateTimeImmutable $now): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.user', 'owner')
            ->andWhere('s.matchedUpToEntryId < :ceiling')
            ->setParameter('ceiling', $ceiling);
        ActiveAccountDql::restrict($qb, 'owner', $now);

        return $qb;
    }
}
