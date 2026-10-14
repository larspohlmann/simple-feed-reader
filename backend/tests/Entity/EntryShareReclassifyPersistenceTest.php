<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\QuotedPostContent;
use App\Enum\ShareKind;
use App\Tests\DbTestCase;

final class EntryShareReclassifyPersistenceTest extends DbTestCase
{
    public function testReclassifyingAStoredQuoteClearsItsColumns(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $feed = new Feed('https://example.org/feed');
        $entry = new Entry($feed, 'guid', null, 'Title', $now, $now);
        $entry->shareQuotedPost(
            new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, 'text', [], null),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $entry->setShareKind(ShareKind::Link);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Entry::class, $entry->requireId());
        self::assertNotNull($reloaded);
        self::assertSame(ShareKind::Link, $reloaded->getShareKind());
        self::assertNull($reloaded->getQuotedPost());
    }
}
