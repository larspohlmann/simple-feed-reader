<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryLabel;
use App\Entity\Label;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryLabel>
 */
final class EntryLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryLabel::class);
    }

    public function exists(Label $label, Entry $entry): bool
    {
        return null !== $this->findOneBy(['label' => $label, 'entry' => $entry]);
    }

    public function removeFor(Label $label, Entry $entry): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(EntryLabel::class, 'entryLabel')
            ->where('entryLabel.label = :label')
            ->andWhere('entryLabel.entry = :entry')
            ->setParameter('label', $label)
            ->setParameter('entry', $entry)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, list<array{id: int, name: string}>> the user's own labels per entry, alphabetical
     */
    public function labelsByEntryIds(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int|string, id: int|string, name: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(entryLabel.entry) AS entryId', 'label.id AS id', 'label.name AS name')
            ->from(EntryLabel::class, 'entryLabel')
            ->join('entryLabel.label', 'label')
            ->where('entryLabel.entry IN (:entryIds)')
            ->andWhere('label.user = :user')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('user', $userId)
            ->orderBy('LOWER(label.name)')
            ->addOrderBy('label.id')
            ->getQuery()
            ->getScalarResult();

        $byEntry = [];
        foreach ($rows as $row) {
            $byEntry[(int) $row['entryId']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }

        return $byEntry;
    }

    /**
     * Moves this user's labels from each source entry onto its target; other users' labels on a source stay put.
     *
     * @param array<int, int> $targetByEntryId
     */
    public function moveToEntries(int $userId, array $targetByEntryId): void
    {
        if ($targetByEntryId === []) {
            return;
        }

        $sourceIds = array_keys($targetByEntryId);
        $labelIdsByTargetId = [];
        foreach ($this->labelIdPairsOn($userId, $sourceIds) as ['entryId' => $entryId, 'labelId' => $labelId]) {
            $labelIdsByTargetId[$targetByEntryId[$entryId]][] = $labelId;
        }
        $this->addMissing($labelIdsByTargetId);

        $this->getEntityManager()->createQuery(sprintf(
            'DELETE FROM %s entryLabel WHERE entryLabel.entry IN (:entryIds) '
            . 'AND entryLabel.label IN (SELECT label.id FROM %s label WHERE label.user = :user)',
            EntryLabel::class,
            Label::class,
        ))
            ->setParameter('entryIds', $sourceIds)
            ->setParameter('user', $userId)
            ->execute();
    }

    public function deleteForUser(int $userId): void
    {
        $this->getEntityManager()->createQuery(sprintf(
            'DELETE FROM %s entryLabel '
            . 'WHERE entryLabel.label IN (SELECT label.id FROM %s label WHERE label.user = :user)',
            EntryLabel::class,
            Label::class,
        ))
            ->setParameter('user', $userId)
            ->execute();
    }

    /**
     * Attaches each label to its entry, skipping a pair already stored or repeated.
     *
     * @param array<int, list<int>> $labelIdsByEntryId
     */
    public function addMissing(array $labelIdsByEntryId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $statement = sprintf(
            'INSERT %s INTO entry_label (label_id, entry_id) VALUES (?, ?)',
            DatabasePlatform::insertIgnoreClause($connection),
        );
        $connection->transactional(static function () use ($connection, $statement, $labelIdsByEntryId): void {
            foreach ($labelIdsByEntryId as $entryId => $labelIds) {
                foreach (array_unique($labelIds) as $labelId) {
                    $connection->executeStatement($statement, [$labelId, $entryId]);
                }
            }
        });
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<array{entryId: int, labelId: int}>
     */
    private function labelIdPairsOn(int $userId, array $entryIds): array
    {
        /** @var list<array{entryId: int|string, labelId: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(entryLabel.entry) AS entryId', 'label.id AS labelId')
            ->from(EntryLabel::class, 'entryLabel')
            ->join('entryLabel.label', 'label')
            ->where('entryLabel.entry IN (:entryIds)')
            ->andWhere('label.user = :user')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): array => ['entryId' => (int) $row['entryId'], 'labelId' => (int) $row['labelId']],
            $rows,
        );
    }
}
