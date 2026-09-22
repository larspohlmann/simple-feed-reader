<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearchEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of saved_search_entry (#1116). Reads live on
 * SavedSearchEntryRepository, which projects entries, not memberships.
 *
 * @extends ServiceEntityRepository<SavedSearchEntry>
 */
final class SavedSearchEntryMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearchEntry::class);
    }
}
