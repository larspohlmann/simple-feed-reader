<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Enum\CanonicalItemKind;
use App\Repository\CanonicalItemRepository;
use App\Service\Foundation\CanonicalItem\CanonicalItemLinker;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Links a fixture entry to its canonical item the way ingestion does; the caller flushes. */
final class CanonicalItems
{
    private function __construct()
    {
    }

    public static function linkAsIngested(EntityManagerInterface $entityManager, Entry $entry): void
    {
        $key = SocialRuleWiring::buildCanonicalKeys()->findKey($entry);
        if (null === $key) {
            return;
        }
        $items = self::readRepository($entityManager);
        $itemId = $items->ensure($key, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $entry->linkTo($items->findByIds([$itemId])[$itemId]);
    }

    /** An item created well before any prune these tests run, so its age never decides whether it stays. */
    public static function buildItem(CanonicalItemKind $kind, string $canonicalKey): CanonicalItem
    {
        return new CanonicalItem($kind, $canonicalKey, new \DateTimeImmutable('2026-01-01 00:00:00'));
    }

    public static function buildLinker(
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): CanonicalItemLinker {
        return new CanonicalItemLinker(
            self::readRepository($entityManager),
            SocialRuleWiring::buildCanonicalKeys(),
            new NaiveUtcClock($clock),
        );
    }

    public static function readRepository(EntityManagerInterface $entityManager): CanonicalItemRepository
    {
        return $entityManager->getRepository(CanonicalItem::class);
    }
}
