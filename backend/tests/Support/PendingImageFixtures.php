<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PendingImageFixtures
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function pendingImage(Feed $feed, string $guid, string $imageUrl): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-09-21 06:00:00'),
            new \DateTimeImmutable('2026-09-21 05:00:00'),
        );
        $entry->getImage()->storePending($imageUrl, null, null);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
