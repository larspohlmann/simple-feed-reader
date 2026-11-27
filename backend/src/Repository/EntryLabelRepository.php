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
}
