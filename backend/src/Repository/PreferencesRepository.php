<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Preferences;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Preferences>
 */
class PreferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Preferences::class);
    }

    /**
     * Every preferences row with the digest enabled, joined to its user, for the
     * scheduler to test dueness against. Enabled is the only cheap pre-filter;
     * dueness needs the timezone maths, so it is applied in PHP (#636).
     *
     * @return list<Preferences>
     */
    public function findWithDigestEnabled(): array
    {
        /** @var list<Preferences> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.digestEnabled = true')
            ->getQuery()->getResult();

        return $rows;
    }
}
