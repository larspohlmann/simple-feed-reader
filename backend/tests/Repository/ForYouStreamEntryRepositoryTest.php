<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ForYouStreamEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListPage;
use App\Repository\ForYouStreamEntryRepository;
use App\Tests\DbTestCase;

final class ForYouStreamEntryRepositoryTest extends DbTestCase
{
    private ForYouStreamEntryRepository $streamEntries;
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
        $streamEntries = self::getContainer()->get(ForYouStreamEntryRepository::class);
        self::assertInstanceOf(ForYouStreamEntryRepository::class, $streamEntries);
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
                new ForYouStreamEntry($this->user, $member, new \DateTimeImmutable('2026-11-27 09:00:00'), 1.0, 'stem'),
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
        self::assertSame(2, $this->streamEntries->countMembers($this->user->requireId()));
        self::assertSame(2, $this->streamEntries->countUnreadMembers($this->user->requireId()));
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
