<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\YourStreamEntry;
use App\Repository\EntryListPage;
use App\Repository\YourStreamEntryRepository;
use App\Tests\DbTestCase;

final class YourStreamEntryRepositoryTest extends DbTestCase
{
    private YourStreamEntryRepository $streamEntries;
    private User $user;
    private Feed $firstFeed;
    private Feed $secondFeed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('stream-collapse@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->firstFeed = $this->subscribedFeed('first');
        $this->secondFeed = $this->subscribedFeed('second');
        $streamEntries = self::getContainer()->get(YourStreamEntryRepository::class);
        self::assertInstanceOf(YourStreamEntryRepository::class, $streamEntries);
        $this->streamEntries = $streamEntries;
    }

    public function testACopyOutsideTheStreamNeverHidesTheMemberCopyAndMemberCopiesCollapse(): void
    {
        $nonMemberCopy = $this->entry($this->firstFeed, 'https://example.com/shared-one');
        $memberCopy = $this->entry($this->secondFeed, 'https://example.com/shared-one');
        $earlierMember = $this->entry($this->firstFeed, 'https://example.com/shared-two');
        $laterMember = $this->entry($this->secondFeed, 'https://example.com/shared-two');
        foreach ([$memberCopy, $earlierMember, $laterMember] as $member) {
            $this->entityManager->persist(
                new YourStreamEntry($this->user, $member, new \DateTimeImmutable('2026-11-27 09:00:00'), 1.0, 'stem'),
            );
        }
        $this->entityManager->flush();
        self::assertLessThan($memberCopy->requireId(), $nonMemberCopy->requireId());

        $listed = array_map(
            static fn ($row): int => $row->entry->requireId(),
            $this->streamEntries->listMembers($this->user->requireId(), new EntryListPage()),
        );

        sort($listed);
        self::assertSame([$memberCopy->requireId(), $earlierMember->requireId()], $listed);
        $tally = $this->streamEntries->countMembersAndUnread($this->user->requireId());
        self::assertSame([2, 2], [$tally->unreadCount, $tally->entryCount]);
    }

    public function testTheTallyPicksOneSurvivorPerCopyGroupWhateverItsReadStateAndCountsItsUnreadOnly(): void
    {
        $readEarlier = $this->member($this->firstFeed, 'https://example.com/read-first');
        $this->member($this->secondFeed, 'https://example.com/read-first');
        $this->member($this->firstFeed, 'https://example.com/unread-first');
        $readLater = $this->member($this->secondFeed, 'https://example.com/unread-first');
        $readAlone = $this->member($this->firstFeed, 'https://example.com/read-alone');
        $this->member($this->secondFeed, 'https://example.com/unread');
        $markedFeed = $this->subscribedFeed('marked');
        $this->member($markedFeed, 'https://example.com/read-by-the-subscription-mark');
        foreach ([$readEarlier, $readLater, $readAlone] as $read) {
            $state = new EntryState($this->user, $read);
            $state->hide(new \DateTimeImmutable('2026-11-27 09:30:00'));
            $this->entityManager->persist($state);
        }
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->user, 'feed' => $markedFeed]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-11-27 08:30:00'));
        $this->entityManager->flush();

        $tally = $this->streamEntries->countMembersAndUnread($this->user->requireId());

        self::assertSame([2, 5], [$tally->unreadCount, $tally->entryCount]);
    }

    private function member(Feed $feed, string $url): Entry
    {
        $entry = $this->entry($feed, $url);
        $this->entityManager->persist(
            new YourStreamEntry($this->user, $entry, new \DateTimeImmutable('2026-11-27 09:00:00'), 1.0, 'stem'),
        );

        return $entry;
    }

    private function subscribedFeed(string $name): Feed
    {
        $feed = new Feed('https://example.com/collapse-' . $name . '.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(
            new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        return $feed;
    }

    private function entry(Feed $feed, string $url): Entry
    {
        $date = new \DateTimeImmutable('2026-11-27T08:00:00Z');
        $entry = new Entry($feed, 'guid-' . uniqid('', true), $url, 'Copy', $date, $date, hash('sha256', $url));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
