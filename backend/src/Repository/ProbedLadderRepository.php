<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryImage;
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
        /** @var Entry|null $measured */
        $measured = $this->createQueryBuilder('e')
            ->andWhere('e.image.urlHash = :hash')
            ->andWhere('e.image.url = :url')
            ->andWhere('e.image.checkedAt IS NOT NULL')
            ->andWhere('e.image.verifyAttempts IS NULL')
            ->andWhere('e.image.width IS NOT NULL')
            ->setParameter('hash', EntryImage::hashOf($imageUrl))
            ->setParameter('url', $imageUrl)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $measured?->getImage()->getRenditions();
    }
}
