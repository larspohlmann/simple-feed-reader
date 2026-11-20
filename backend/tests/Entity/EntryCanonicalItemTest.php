<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CanonicalItemKind;
use App\Tests\DbTestCase;

final class EntryCanonicalItemTest extends DbTestCase
{
    public function testLinkHandsCountsToTheItem(): void
    {
        $entry = $this->persistedEntry();
        $entry->discussionCounts()->recordCounts(DiscussionCounts::fromScoreAndComments(null, 7));
        $entry->discussionCounts()->recordSelfReplyCount(3);
        $item = $this->persistedItem();

        $entry->linkTo($item);
        $this->entityManager->flush();

        self::assertSame(7, $item->getCommentCount());
        self::assertSame(3, $item->getSelfReplyCount());
        self::assertSame(
            ['comment_count' => null, 'self_reply_count' => 0],
            $this->entityManager->getConnection()->fetchAssociative(
                'SELECT comment_count, self_reply_count FROM entry WHERE id = ?',
                [$entry->requireId()],
            ),
        );
    }

    public function testALinkedEntryReadsAndWritesItsCountsOnTheItem(): void
    {
        $entry = $this->persistedEntry();
        $item = $this->persistedItem();
        $entry->linkTo($item);

        $entry->discussionCounts()->recordCounts(DiscussionCounts::fromScoreAndComments(12, 4));
        $entry->discussionCounts()->recordSelfReplyCount(2);

        self::assertSame(12, $item->getScore());
        self::assertSame(4, $item->getCommentCount());
        self::assertSame(4, $entry->discussionCounts()->getCommentCount());
        self::assertSame(2, $item->getSelfReplyCount());
        self::assertSame(2, $entry->discussionCounts()->getSelfReplyCount());
    }

    public function testALinkedEntryReadsCountsAnotherCopyRecorded(): void
    {
        $item = $this->persistedItem();
        $copyA = $this->persistedEntry('a');
        $copyB = $this->persistedEntry('b');
        $copyA->linkTo($item);
        $copyB->linkTo($item);

        $copyB->discussionCounts()->recordCounts(DiscussionCounts::fromScoreAndComments(null, 5));

        self::assertSame(5, $copyA->discussionCounts()->getCommentCount());
    }

    public function testTheLinkSurvivesAReload(): void
    {
        $entry = $this->persistedEntry();
        $item = $this->persistedItem();
        $entry->linkTo($item);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Entry::class, $entry->requireId());

        self::assertSame($item->requireId(), $reloaded?->getCanonicalItem()?->requireId());
    }

    public function testAnUnlinkedEntryKeepsItsOwnCounts(): void
    {
        $entry = $this->persistedEntry();

        $entry->discussionCounts()->recordCounts(DiscussionCounts::fromScoreAndComments(12, 4));
        $entry->discussionCounts()->recordSelfReplyCount(2);

        self::assertNull($entry->getCanonicalItem());
        self::assertSame(4, $entry->discussionCounts()->getCommentCount());
        self::assertSame(2, $entry->discussionCounts()->getSelfReplyCount());
    }

    private function persistedEntry(string $guid = 'g'): Entry
    {
        $feed = new Feed('https://example.com/' . $guid . '.xml');
        $now = new \DateTimeImmutable('2026-11-20T12:00:00Z');
        $entry = new Entry($feed, $guid, 'https://example.com/' . $guid, 'Title', $now, $now);
        $this->entityManager->persist($feed);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function persistedItem(): CanonicalItem
    {
        $item = new CanonicalItem(CanonicalItemKind::Lemmy, 'https://lemmy.world/post/7');
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }
}
