<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\RecommendationCursor;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationItemRepository;
use App\Tests\DbTestCase;

final class RecommendationFeedTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->sub = new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->sub);

        $this->em->flush();
    }

    private function entry(string $guid): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            $createdAt,
            $createdAt,
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function seedRun(User $user, string $status): RecommendationRun
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));

        if ($status !== RecommendationRun::STATUS_PENDING) {
            $run->snapshot([[1]]);
        }

        if ($status === RecommendationRun::STATUS_COMPLETED) {
            $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        }

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function item(RecommendationRun $run, Entry $entry, int $position, string $reason): RecommendationItem
    {
        $item = new RecommendationItem($run, $entry, $position, $reason);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function repo(): RecommendationItemRepository
    {
        $repo = $this->em->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $repo);

        return $repo;
    }

    public function testDedupesToNewestRunAndHidesUnfinishedOrForeignRuns(): void
    {
        $entryA = $this->entry('a');
        $entryB = $this->entry('b');
        $entryC = $this->entry('c');
        $entryD = $this->entry('d');

        $run1 = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run1, $entryA, 1, 'run1 reason a');
        $this->item($run1, $entryB, 2, 'run1 reason b');

        $run2 = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run2, $entryB, 1, 'run2 reason b');
        $this->item($run2, $entryC, 2, 'run2 reason c');

        $runningRun = $this->seedRun($this->user, RecommendationRun::STATUS_RUNNING);
        $this->item($runningRun, $entryD, 1, 'running reason d');

        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($stranger);
        $strangerFeed = new Feed('https://stranger.example.com/feed.xml');
        $this->em->persist($strangerFeed);
        $this->em->persist(new Subscription($stranger, $strangerFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();
        $strangerCreatedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $strangerEntry = new Entry(
            $strangerFeed,
            'stranger-entry',
            'https://stranger.example.com/1',
            'Stranger',
            $strangerCreatedAt,
            $strangerCreatedAt,
        );
        $this->em->persist($strangerEntry);
        $this->em->flush();
        $strangerRun = $this->seedRun($stranger, RecommendationRun::STATUS_COMPLETED);
        $this->item($strangerRun, $strangerEntry, 1, 'stranger reason');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);

        self::assertCount(3, $rows);
        self::assertSame('b', $rows[0]->row->entry->getGuid());
        self::assertSame($run2->getId(), $rows[0]->runId);
        self::assertSame(1, $rows[0]->position);
        self::assertSame('run2 reason b', $rows[0]->reason);
        self::assertSame('c', $rows[1]->row->entry->getGuid());
        self::assertSame('a', $rows[2]->row->entry->getGuid());
        self::assertSame($run1->getId(), $rows[2]->runId);

        foreach ($rows as $row) {
            self::assertNotSame('d', $row->row->entry->getGuid());
            self::assertNotSame('stranger-entry', $row->row->entry->getGuid());
        }
    }

    public function testRowCarriesTheRunGenerationTime(): void
    {
        $entryA = $this->entry('a');
        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $entryA, 1, 'reason a');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 20), null);

        self::assertCount(1, $rows);
        self::assertEquals(
            new \DateTimeImmutable('2026-08-07T09:05:00Z'),
            $rows[0]->runGeneratedAt,
        );
    }

    public function testCarriesFlagsAndFoldsTheWatermark(): void
    {
        $entryOld = $this->entry('old');
        $entryFav = $this->entry('fav');
        $entryKept = $this->entry('kept');
        $entryViewed = $this->entry('viewed');
        $watermark = new \DateTimeImmutable('2026-08-07T00:00:00Z');
        $this->sub->setMarkedReadUntil($watermark);
        $this->em->flush();

        $favState = new EntryState($this->user, $entryFav);
        $favState->setIsFavorite(true);
        $keptState = new EntryState($this->user, $entryKept);
        $keptState->setIsKept(true);
        $viewedState = new EntryState($this->user, $entryViewed);
        $viewedState->markViewed(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
        $this->em->persist($favState);
        $this->em->persist($keptState);
        $this->em->persist($viewedState);
        $this->em->flush();

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $entryOld, 1, 'reason old');
        $this->item($run, $entryFav, 2, 'reason fav');
        $this->item($run, $entryKept, 3, 'reason kept');
        $this->item($run, $entryViewed, 4, 'reason viewed');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);
        $byGuid = [];
        foreach ($rows as $row) {
            $byGuid[$row->row->entry->getGuid()] = $row;
        }

        // The entry's effectiveDate (2026-07-01) falls under the watermark
        // (2026-08-07), so with no explicit EntryState it reads as read.
        self::assertTrue($byGuid['old']->row->isHidden);
        self::assertSame(
            $watermark->getTimestamp(),
            $byGuid['old']->row->markedReadUntil?->getTimestamp(),
        );
        self::assertSame('reason old', $byGuid['old']->reason);
        self::assertFalse($byGuid['old']->row->isFavorite);
        self::assertFalse($byGuid['old']->row->isKept);
        self::assertFalse($byGuid['old']->row->isViewed);

        self::assertTrue($byGuid['fav']->row->isFavorite);
        self::assertFalse($byGuid['fav']->row->isKept);

        self::assertTrue($byGuid['kept']->row->isKept);
        self::assertFalse($byGuid['kept']->row->isFavorite);

        self::assertTrue($byGuid['viewed']->row->isViewed);
        self::assertFalse($byGuid['old']->row->isViewed);

        self::assertSame($this->sub->getId(), $byGuid['old']->row->subscriptionId);
        self::assertSame('Example', $byGuid['old']->row->subscriptionTitle);
    }

    public function testUnreadOnlyDropsEveryPickTheReaderHasAlreadyRead(): void
    {
        $unread = $this->entry('unread');
        $hidden = $this->entry('hidden');
        $unreadAgain = $this->entry('explicitly-unread');

        $hiddenState = new EntryState($this->user, $hidden);
        $hiddenState->hide(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
        $this->em->persist($hiddenState);
        // An explicit unread row beats the watermark below, exactly as it does
        // in the main list — that is what UnreadDql's first clause is for.
        $unreadState = new EntryState($this->user, $unreadAgain);
        $unreadState->markUnread();
        $this->em->persist($unreadState);
        $this->sub->setMarkedReadUntil(new \DateTimeImmutable('2026-08-07T00:00:00Z'));
        $this->em->flush();

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $unread, 1, 'reason unread');
        $this->item($run, $hidden, 2, 'reason hidden');
        $this->item($run, $unreadAgain, 3, 'reason explicitly unread');

        $rows = $this->repo()->listForYou(
            new ForYouFeedQuery($this->user, null, 50, unreadOnly: true),
            null,
        );

        // 'unread' has no state row and an effectiveDate under the watermark,
        // so the watermark reads it as read and it drops out with 'hidden'.
        self::assertSame(
            ['explicitly-unread'],
            array_map(static fn ($row) => $row->row->entry->getGuid(), $rows),
        );
    }

    public function testWithoutTheUnreadFilterEveryPickStays(): void
    {
        $unread = $this->entry('unread');
        $hidden = $this->entry('hidden');
        $hiddenState = new EntryState($this->user, $hidden);
        $hiddenState->hide(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
        $this->em->persist($hiddenState);
        $this->em->flush();

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $unread, 1, 'reason unread');
        $this->item($run, $hidden, 2, 'reason hidden');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);

        self::assertCount(2, $rows);
    }

    public function testUnreadEntryIdsForYouSkipsReadPicksAndRunsThatFinishedTooLate(): void
    {
        $unread = $this->entry('unread');
        $read = $this->entry('read');
        $tooLate = $this->entry('too-late');

        $readState = new EntryState($this->user, $read);
        $readState->hide(new \DateTimeImmutable('2026-08-07T10:00:00Z'));
        $this->em->persist($readState);
        $this->em->flush();

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $unread, 1, 'reason unread');
        $this->item($run, $read, 2, 'reason read');

        $laterRun = $this->completedRunAt('2026-08-07T11:00:00Z');
        $this->item($laterRun, $tooLate, 1, 'reason too late');

        $ids = $this->repo()->unreadEntryIdsForYou(
            (int) $this->user->getId(),
            new \DateTimeImmutable('2026-08-07T10:30:00Z'),
        );

        self::assertSame([(int) $unread->getId()], $ids);
    }

    /** A completed run stamped at a time this test chooses — `seedRun` fixes
     *  one, and the mark-read cut-off is a question about that stamp. */
    private function completedRunAt(string $completedAt): RecommendationRun
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable($completedAt));
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    public function testSubscriptionTitleFallsBackToCustomTitleThenFeedTitle(): void
    {
        $customFeed = new Feed('https://custom.example.com/feed.xml');
        $customFeed->setTitle('Underlying Feed Title');
        $this->em->persist($customFeed);
        $customSub = new Subscription($this->user, $customFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $customSub->setCustomTitle('My Custom Title');
        $this->em->persist($customSub);
        $customCreatedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $customEntry = new Entry(
            $customFeed,
            'custom-titled',
            'https://custom.example.com/1',
            'Custom',
            $customCreatedAt,
            $customCreatedAt,
        );
        $this->em->persist($customEntry);
        $this->em->flush();

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $customEntry, 1, 'reason custom');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);
        self::assertCount(1, $rows);
        self::assertSame('My Custom Title', $rows[0]->row->subscriptionTitle);
    }

    public function testSubscriptionTitleFallsBackToFeedUrlWhenNothingElseIsSet(): void
    {
        $untitledFeed = new Feed('https://untitled.example.com/feed.xml');
        $this->em->persist($untitledFeed);
        $untitledSub = new Subscription($this->user, $untitledFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($untitledSub);
        $untitledCreatedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $untitledEntry = new Entry(
            $untitledFeed,
            'untitled',
            'https://untitled.example.com/1',
            'Untitled',
            $untitledCreatedAt,
            $untitledCreatedAt,
        );
        $this->em->persist($untitledEntry);
        $this->em->flush();

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $untitledEntry, 1, 'reason untitled');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);
        self::assertCount(1, $rows);
        self::assertSame('https://untitled.example.com/feed.xml', $rows[0]->row->subscriptionTitle);
    }

    public function testLimitIsClampedToAtLeastOne(): void
    {
        $entryA = $this->entry('a');
        $entryB = $this->entry('b');

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $entryA, 1, 'reason a');
        $this->item($run, $entryB, 2, 'reason b');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 0), null);
        self::assertCount(1, $rows);
    }

    public function testCursorReturnsTheSecondPage(): void
    {
        $entryA = $this->entry('a');
        $entryB = $this->entry('b');
        $entryC = $this->entry('c');

        $run1 = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run1, $entryA, 1, 'reason a');

        $run2 = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run2, $entryB, 1, 'reason b');
        $this->item($run2, $entryC, 2, 'reason c');

        $page1 = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 2), null);
        self::assertCount(2, $page1);
        self::assertSame('b', $page1[0]->row->entry->getGuid());
        self::assertSame('c', $page1[1]->row->entry->getGuid());

        $cursor = new RecommendationCursor($page1[1]->runId, $page1[1]->position);
        $page2 = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 2), $cursor);
        self::assertCount(1, $page2);
        self::assertSame('a', $page2[0]->row->entry->getGuid());
    }

    public function testUnsubscribingHidesItsItems(): void
    {
        $entry = $this->entry('gone');
        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $entry, 1, 'reason gone');

        $this->em->remove($this->sub);
        $this->em->flush();

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);
        self::assertCount(0, $rows);
    }

    public function testFeedExcludedFromForYouDropsFromListAndCount(): void
    {
        $entryA = $this->entry('a');

        $excludedFeed = new Feed('https://excluded.example.com/feed.xml');
        $this->em->persist($excludedFeed);
        $excludedSub = new Subscription(
            $this->user,
            $excludedFeed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $excludedSub->setIncludeInForYou(false);
        $this->em->persist($excludedSub);
        $excludedCreatedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entryB = new Entry(
            $excludedFeed,
            'b',
            'https://excluded.example.com/b',
            'Title b',
            $excludedCreatedAt,
            $excludedCreatedAt,
        );
        $this->em->persist($entryB);
        $this->em->flush();

        $run = $this->seedRun($this->user, RecommendationRun::STATUS_COMPLETED);
        $this->item($run, $entryA, 1, 'reason a');
        $this->item($run, $entryB, 2, 'reason b');

        $rows = $this->repo()->listForYou(new ForYouFeedQuery($this->user, null, 50), null);
        self::assertCount(1, $rows);
        self::assertSame('a', $rows[0]->row->entry->getGuid());

        self::assertSame(1, $this->repo()->countForYou((int) $this->user->getId()));
    }
}
