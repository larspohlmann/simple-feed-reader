<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Subscription\SubscriptionTallyReader;
use App\Tests\DbTestCase;

final class SubscriptionTallyReaderTest extends DbTestCase
{
    public function testReadsUnreadEntryAndSurfaceCountsForTheUser(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies@example.com', $when);
        $this->em->persist($user);
        $feed = new Feed('https://example.com/tallies.xml');
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, $when);
        $this->em->persist($subscription);
        $read = $this->entry($feed, 'read');
        $this->entry($feed, 'unread');
        $state = new EntryState($user, $read);
        $state->hide($when);
        $state->markFavorite();
        $this->em->persist($state);
        $this->em->flush();

        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);
        $entryStates = $this->em->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $entryStates);
        $tallies = $reader->forUser($user->requireId());

        self::assertSame($entryStates->unreadCountsForUser($user->requireId()), $tallies->unreadCounts);
        self::assertSame([$subscription->requireId() => 2], $tallies->entryCounts);
        self::assertSame(['favorites' => 1, 'kept' => 0, 'viewed' => 0], $tallies->flags);
        self::assertNotSame($tallies->entryCounts, $tallies->unreadCounts);
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, $createdAt, $createdAt);
        $this->em->persist($entry);

        return $entry;
    }
}
