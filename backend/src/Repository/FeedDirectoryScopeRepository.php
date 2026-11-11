<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeedDirectoryScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FeedDirectoryScope> */
final class FeedDirectoryScopeRepository extends ServiceEntityRepository
{
    /** @use UserSettingsRowLookup<FeedDirectoryScope> */
    use UserSettingsRowLookup;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedDirectoryScope::class);
    }
}
