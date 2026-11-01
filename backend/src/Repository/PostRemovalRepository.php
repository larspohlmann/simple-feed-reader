<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Enum\SourceFormat;
use Doctrine\ORM\EntityManagerInterface;

/** Every stored copy of a post its network no longer has, and their deletion. */
final readonly class PostRemovalRepository
{
    public function __construct(private EntityManagerInterface $entityManager, private RowIds $rowIds)
    {
    }

    /** @return list<StoredPostCopy> the entry, and each copy of its post outside a saved feed, which is an archive */
    public function copiesOf(Entry $entry): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e.id AS entryId', 'IDENTITY(e.feed) AS feedId', 'e.guid.hash AS guidHash')
            ->from(Entry::class, 'e')
            ->join('e.feed', 'f')
            ->andWhere('e = :entry')
            ->setParameter('entry', $entry);
        $urlHash = $entry->getUrlHash();
        if (null !== $urlHash) {
            $qb->orWhere('e.location.urlHash = :urlHash AND f.sourceFormat <> :savedFormat')
                ->setParameter('urlHash', $urlHash)
                ->setParameter('savedFormat', SourceFormat::SAVED);
        }
        /** @var list<array{entryId: int|string, feedId: int|string, guidHash: string}> $rows */
        $rows = $qb->orderBy('e.id', 'ASC')->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): StoredPostCopy
                => new StoredPostCopy((int) $row['entryId'], (int) $row['feedId'], $row['guidHash']),
            $rows,
        );
    }

    /** @param list<int> $entryIds */
    public function deleteEntries(array $entryIds): void
    {
        $this->rowIds->delete(Entry::class, $entryIds);
    }
}
