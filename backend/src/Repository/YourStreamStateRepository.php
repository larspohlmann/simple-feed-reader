<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\YourStreamState;
use App\Entity\YourStreamTuning;
use App\Enum\YourStreamPrecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<YourStreamState> */
final class YourStreamStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, YourStreamState::class);
    }

    public function findForUser(User $user): ?YourStreamState
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
        YourStreamSettingsDql::restrictToEnabled($qb, 's.user');

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    /** @return array<int, int> user id => mark, for the active accounts whose mark is below the ceiling */
    public function findBelowMark(int $ceiling, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.user) AS userId', 's.matchedUpToEntryId AS mark')
            ->join('s.user', 'owner')
            ->andWhere('s.matchedUpToEntryId < :ceiling')
            ->setParameter('ceiling', $ceiling);
        ActiveAccountDql::restrict($qb, 'owner', $now);

        /** @var list<array{userId: int|string, mark: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $marksByUserId = [];
        foreach ($rows as $row) {
            $marksByUserId[(int) $row['userId']] = $row['mark'];
        }

        return $marksByUserId;
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, YourStreamTuning> one per asked owner with a state row, the defaults without a settings row
     */
    public function findStreamTunings(array $userIds): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select(
                'IDENTITY(s.user) AS userId',
                \sprintf('%s.forYouStream.enabled AS enabled', YourStreamSettingsDql::ALIAS),
                \sprintf('%s.forYouStream.precision AS precision', YourStreamSettingsDql::ALIAS),
            )
            ->andWhere('IDENTITY(s.user) IN (:userIds)')
            ->setParameter('userIds', $userIds);
        YourStreamSettingsDql::joinSettingsRow($qb, 's.user');

        /** @var list<array{userId: int|string, enabled: ?bool, precision: ?YourStreamPrecision}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $tuningsByUserId = [];
        foreach ($rows as $row) {
            $tuningsByUserId[(int) $row['userId']] = null === $row['enabled'] || null === $row['precision']
                ? YourStreamTuning::defaults()
                : new YourStreamTuning($row['enabled'], $row['precision']);
        }

        return $tuningsByUserId;
    }

    /**
     * @return list<int> the active accounts with the stream on whose word list was not checked since $checkedBefore,
     *                   the longest unchecked first
     */
    public function findUserIdsDueForCheck(\DateTimeImmutable $checkedBefore, \DateTimeImmutable $now): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('owner.id AS userId')
            ->from(User::class, 'owner')
            ->leftJoin(YourStreamState::class, 'state', Join::WITH, 'state.user = owner')
            ->andWhere('state.user IS NULL OR state.wordsCheckedAt IS NULL OR state.wordsCheckedAt < :checkedBefore')
            ->setParameter('checkedBefore', $checkedBefore)
            ->orderBy('state.wordsCheckedAt', 'ASC')
            ->addOrderBy('owner.id', 'ASC');
        ActiveAccountDql::restrict($qb, 'owner', $now);
        YourStreamSettingsDql::restrictToEnabled($qb, 'owner');

        /** @var list<array{userId: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['userId'], $rows);
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
}
