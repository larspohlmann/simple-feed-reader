<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Enum\ShareKind;
use App\Tests\DbTestCase;
use App\Tests\Support\SharedLinks;

final class EntryShareReclassifyPersistenceTest extends DbTestCase
{
    public function testReclassifyingAStoredQuoteClearsItsColumns(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $feed = new Feed('https://example.org/feed');
        $entry = new Entry($feed, 'guid', null, 'Title', $now, $now);
        $entry->shareQuotedPost(
            new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, 'text', [], null),
            SharedLinks::of($this->entityManager, self::preview()),
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
        self::assertNull($this->entityManager->getConnection()->fetchOne(
            'SELECT quoted_shared_link_id FROM entry WHERE id = ?',
            [$entry->requireId()],
        ));
    }

    public function testAStoredQuoteReadsItsLinksPreviewBack(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $feed = new Feed('https://example.org/feed');
        $entry = new Entry($feed, 'guid', null, 'Title', $now, $now);
        $entry->shareQuotedPost(
            new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, 'text', [], null),
            SharedLinks::of($this->entityManager, self::preview()),
        );
        $this->entityManager->persist($feed);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Entry::class, $entry->requireId());

        self::assertNotNull($reloaded);
        self::assertEquals(self::preview(), $reloaded->getQuotedPost()?->linkCard);
    }

    private static function preview(): LinkCardContent
    {
        return new LinkCardContent('https://n.example/s', 'Story', 'D', null, 'n.example');
    }
}
