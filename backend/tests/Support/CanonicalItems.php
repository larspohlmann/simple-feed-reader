<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Repository\CanonicalItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Links a fixture entry to its canonical item the way ingestion does; the caller flushes. */
final class CanonicalItems
{
    private function __construct()
    {
    }

    public static function linkAsIngested(EntityManagerInterface $entityManager, Entry $entry): void
    {
        $key = SocialRuleWiring::canonicalKeys()->findKey($entry);
        if (null === $key) {
            return;
        }
        /** @var CanonicalItemRepository $items */
        $items = $entityManager->getRepository(CanonicalItem::class);
        $itemId = $items->ensure($key);
        $entry->linkTo($items->findByIds([$itemId])[$itemId]);
    }
}
