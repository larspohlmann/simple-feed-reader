<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use Doctrine\ORM\EntityManagerInterface;

final class QueuedLinkPosts
{
    private function __construct()
    {
    }

    /** Persists a post sharing $contentHtml and its link-card row, queued at $queuedAt. */
    public static function persist(
        EntityManagerInterface $entityManager,
        Feed $feed,
        string $guid,
        string $contentHtml,
        \DateTimeImmutable $queuedAt,
    ): Entry {
        $entry = new Entry($feed, $guid, null, 'Post', $queuedAt, $queuedAt);
        $entry->setContentHtml($contentHtml);
        $entry->markTitleDerived();
        $entry->getImage()->storePending(null, null, null);
        $entityManager->persist($entry);
        $entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
        $entityManager->flush();

        return $entry;
    }
}
