<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SavedSearch;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A saved search's membership mark as the database holds it — read past the
 * identity map, since the sweep advances marks with DQL that a loaded entity
 * never reflects.
 */
final class StoredMark
{
    public static function of(EntityManagerInterface $em, SavedSearch $search): int
    {
        $mark = $em->getConnection()->fetchOne(
            'SELECT matched_up_to_entry_id FROM saved_search WHERE id = ?',
            [$search->getId()],
        );
        if (!\is_numeric($mark)) {
            throw new \LogicException('The saved search has no stored mark.');
        }

        return (int) $mark;
    }
}
