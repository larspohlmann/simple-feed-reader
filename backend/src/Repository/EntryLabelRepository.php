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
}
