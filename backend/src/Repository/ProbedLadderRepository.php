<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryImage;
use App\Entity\ImageRendition;
use App\Entity\StoredList;
use App\Service\Image\ProbedLadder\ProbedLadderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Entry> */
final class ProbedLadderRepository extends ServiceEntityRepository implements ProbedLadderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    public function findProbedLadder(string $imageUrl): ?array
    {
        /** @var array{renditions: list<array<string, mixed>>|null}|null $judged */
        $judged = $this->createQueryBuilder('e')
            ->select('e.image.renditions AS renditions')
            ->andWhere('e.image.urlHash = :hash')
            ->andWhere('e.image.checkedAt IS NOT NULL')
            ->setParameter('hash', EntryImage::hashOf($imageUrl))
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($judged === null) {
            return null;
        }

        return StoredList::read(
            $judged['renditions'],
            ImageRendition::isComplete(...),
            ImageRendition::fromStored(...),
        );
    }
}
