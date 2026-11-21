<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @phpstan-type GroupRow array{
 *     accountId: int|string, kind: SocialNotificationKind, groupKey: string,
 *     seen: bool|int|string, latestId: int|string, rowCount: int|string
 * }
 * @phpstan-type Group array{accountId: int, kind: string, groupKey: string, seen: bool, latestId: int, count: int}
 * @extends ServiceEntityRepository<SocialNotification>
 */
final class SocialNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialNotification::class);
    }

    /**
     * @param list<string> $remoteIds
     * @return array<string, SocialNotification>
     */
    public function findByRemoteIds(SocialAccount $account, array $remoteIds): array
    {
        if ($remoteIds === []) {
            return [];
        }

        /** @var list<SocialNotification> $rows */
        $rows = $this->createQueryBuilder('notification')
            ->andWhere('notification.socialAccount = :account')
            ->andWhere('notification.remoteId IN (:remoteIds)')
            ->setParameter('account', $account)
            ->setParameter('remoteIds', $remoteIds)
            ->getQuery()
            ->getResult();

        $byRemoteId = [];
        foreach ($rows as $row) {
            $byRemoteId[$row->getRemoteId()] = $row;
        }

        return $byRemoteId;
    }

    /** @return list<SocialNotification> the account's unseen rows with an id up to $upTo, oldest first */
    public function findUnseenUpTo(SocialAccount $account, int $upTo): array
    {
        /** @var list<SocialNotification> $rows */
        $rows = $this->createQueryBuilder('notification')
            ->andWhere('notification.socialAccount = :account')
            ->andWhere('notification.seen = false')
            ->andWhere('notification.id <= :upTo')
            ->setParameter('account', $account)
            ->setParameter('upTo', $upTo)
            ->orderBy('notification.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function markSeenUpTo(SocialAccount $account, \DateTimeImmutable $until): void
    {
        $this->createQueryBuilder('notification')
            ->update()
            ->set('notification.seen', 'true')
            ->andWhere('notification.socialAccount = :account')
            ->andWhere('notification.createdAt <= :until')
            ->andWhere('notification.seen = false')
            ->setParameter('account', $account)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    public function deleteCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('notification')
            ->delete()
            ->andWhere('notification.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        return $deleted;
    }

    /**
     * @param list<int> $accountIds
     * @return list<Group> newest group first, one more than $limit when a further page exists
     */
    public function findGroupPage(array $accountIds, ?int $before, int $limit): array
    {
        if ($accountIds === []) {
            return [];
        }

        $qb = $this->groupedQuery()
            ->addGroupBy('seen')
            ->andWhere('notification.socialAccount IN (:accountIds)')
            ->setParameter('accountIds', $accountIds)
            ->orderBy('latestId', 'DESC')
            ->setMaxResults($limit + 1);
        if ($before !== null) {
            $qb->having('MAX(notification.id) < :before')->setParameter('before', $before);
        }

        /** @var list<GroupRow> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(self::groupRow(...), $rows);
    }

    /**
     * @param list<Group> $groups
     * @return array<int, list<SocialNotification>> latestId => the group's newest rows, newest first
     */
    public function findNewestPerGroup(array $groups, int $perGroup): array
    {
        if ($groups === []) {
            return [];
        }

        $latestIdByRowId = $this->findRankedRowIds($groups, $perGroup);
        $newest = array_fill_keys(array_column($groups, 'latestId'), []);
        foreach ($this->findNewestFirst(array_keys($latestIdByRowId)) as $row) {
            $newest[$latestIdByRowId[$row->requireId()]][] = $row;
        }

        return $newest;
    }

    /** @return array<string, int> network value => unseen groups; networks without any are absent */
    public function countUnseenGroups(User $user): array
    {
        /** @var list<array{network: SocialNetwork}> $rows */
        $rows = $this->groupedQuery()
            ->addSelect('account.network AS network')
            ->join('notification.socialAccount', 'account')
            ->andWhere('account.user = :user')
            ->andWhere('notification.seen = false')
            ->addGroupBy('account.network')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $network = $row['network']->value;
            $counts[$network] = ($counts[$network] ?? 0) + 1;
        }

        return $counts;
    }

    private function groupedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('notification')
            ->select('IDENTITY(notification.socialAccount) AS accountId')
            ->addSelect('notification.kind AS kind')
            ->addSelect('notification.seen AS seen')
            ->addSelect('notification.subject.groupKey AS groupKey')
            ->addSelect('MAX(notification.id) AS latestId')
            ->addSelect('COUNT(notification.id) AS rowCount')
            ->groupBy('accountId')
            ->addGroupBy('kind')
            ->addGroupBy('groupKey');
    }

    /**
     * @param GroupRow $row
     * @return Group
     */
    private static function groupRow(array $row): array
    {
        return [
            'accountId' => (int) $row['accountId'],
            'kind' => $row['kind']->value,
            'groupKey' => $row['groupKey'],
            'seen' => (bool) $row['seen'],
            'latestId' => (int) $row['latestId'],
            'count' => (int) $row['rowCount'],
        ];
    }

    /**
     * The database, not PHP, assigns each row to its group: the key columns compare under the
     * column collation, which is case- and accent-insensitive on MySQL.
     *
     * @param non-empty-list<Group> $groups
     * @return array<int, int> row id => latestId of the group it ranks in, at most $perGroup rows per group
     */
    private function findRankedRowIds(array $groups, int $perGroup): array
    {
        $inGroup = '(social_account_id = ? AND kind = ? AND seen = ? AND subject_group_key = ? AND id <= ?)';
        $tags = [];
        $filters = [];
        $tagParameters = [];
        $filterParameters = [];
        foreach ($groups as $group) {
            $matching = [$group['accountId'], $group['kind'], $group['seen'], $group['groupKey'], $group['latestId']];
            $tags[] = "WHEN $inGroup THEN ?";
            array_push($tagParameters, ...$matching);
            $tagParameters[] = $group['latestId'];
            $filters[] = $inGroup;
            array_push($filterParameters, ...$matching);
        }
        $tagged = implode(' ', $tags);
        $inAnyGroup = implode(' OR ', $filters);
        $sql = "SELECT id, group_latest_id FROM (
                SELECT id, group_latest_id,
                    ROW_NUMBER() OVER (PARTITION BY group_latest_id ORDER BY id DESC) AS position
                FROM (
                    SELECT id, CASE $tagged END AS group_latest_id
                    FROM social_notification
                    WHERE $inAnyGroup
                ) matched
            ) ranked
            WHERE position <= ?";
        $parameters = [...$tagParameters, ...$filterParameters, $perGroup];

        /** @var array<int|string, int|string> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            $sql,
            $parameters,
            array_map(self::parameterType(...), $parameters),
        );

        $latestIdByRowId = [];
        foreach ($rows as $rowId => $latestId) {
            $latestIdByRowId[(int) $rowId] = (int) $latestId;
        }

        return $latestIdByRowId;
    }

    private static function parameterType(int|string|bool $parameter): ParameterType
    {
        return match (true) {
            is_bool($parameter) => ParameterType::BOOLEAN,
            is_int($parameter) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        };
    }

    /**
     * @param list<int> $ids
     * @return list<SocialNotification>
     */
    private function findNewestFirst(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<SocialNotification> $rows */
        $rows = $this->createQueryBuilder('notification')
            ->andWhere('notification.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('notification.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
