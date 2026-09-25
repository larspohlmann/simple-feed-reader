<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;

final class SubscriptionEntryCountsTest extends DbTestCase
{
    public function testCountsEveryEntryPerSubscriptionReadOrNot(): void
    {
        $user = $this->user('reader@example.com');
        $feed = $this->feed('https://example.com/f.xml');
        $sub = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $sub->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->persist($sub);
        $read = $this->entry($feed, 'a', '2026-07-20');
        $this->entry($feed, 'b', '2026-07-05');
        $this->entry($feed, 'c', '2026-07-21');
        $state = new EntryState($user, $read);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();

        $counts = $this->repo()->entryCountsForUser((int) $user->getId());

        self::assertSame([(int) $sub->getId() => 3], $counts);
    }

    public function testLeavesOutSubscriptionsWithoutEntriesAndOtherUsersFeeds(): void
    {
        $user = $this->user('reader@example.com');
        $stranger = $this->user('stranger@example.com');
        $empty = $this->feed('https://example.com/empty.xml');
        $theirs = $this->feed('https://example.com/theirs.xml');
        $this->em->persist(new Subscription($user, $empty, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->persist(new Subscription($stranger, $theirs, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entry($theirs, 'x', '2026-07-20');
        $this->em->flush();

        self::assertSame([], $this->repo()->entryCountsForUser((int) $user->getId()));
    }

    /**
     * A single-subscription fixture cannot tell a full map from one truncated
     * to its first entry — this asserts both subscriptions' counts survive.
     */
    public function testKeepsEveryOwnedSubscriptionsCount(): void
    {
        $user = $this->user('reader@example.com');
        $first = $this->feed('https://example.com/first.xml');
        $second = $this->feed('https://example.com/second.xml');
        $firstSub = new Subscription($user, $first, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $secondSub = new Subscription($user, $second, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($firstSub);
        $this->em->persist($secondSub);
        $this->entry($first, 'a', '2026-07-01');
        $this->entry($second, 'b', '2026-07-02');
        $this->entry($second, 'c', '2026-07-03');
        $this->em->flush();

        $counts = $this->repo()->entryCountsForUser((int) $user->getId());

        self::assertSame([(int) $firstSub->getId() => 1, (int) $secondSub->getId() => 2], $counts);
    }

    private function repo(): SubscriptionRepository
    {
        $repo = $this->em->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repo);

        return $repo;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid, string $day): Entry
    {
        $publishedAt = new \DateTimeImmutable($day . 'T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, new \DateTimeImmutable('2026-07-01T00:00:00Z'), $publishedAt);
        $entry->setPublishedAt($publishedAt);
        $this->em->persist($entry);

        return $entry;
    }
}
