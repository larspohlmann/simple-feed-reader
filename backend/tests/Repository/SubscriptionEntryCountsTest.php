<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class SubscriptionEntryCountsTest extends DbTestCase
{
    use SeedsUsers;

    public function testCountsEveryEntryPerSubscriptionReadOrNot(): void
    {
        $user = $this->user('reader@example.com');
        $feed = $this->feed('https://example.com/f.xml');
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->entityManager->persist($subscription);
        $read = $this->entry($feed, 'a', '2026-07-20');
        $this->entry($feed, 'b', '2026-07-05');
        $this->entry($feed, 'c', '2026-07-21');
        $state = new EntryState($user, $read);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $counts = $this->repository()->entryCountsForUser($user->requireId());

        self::assertSame([$subscription->requireId() => 3], $counts);
    }

    public function testLeavesOutSubscriptionsWithoutEntriesAndOtherUsersFeeds(): void
    {
        $user = $this->user('reader@example.com');
        $stranger = $this->user('stranger@example.com');
        $empty = $this->feed('https://example.com/empty.xml');
        $theirs = $this->feed('https://example.com/theirs.xml');
        $this->entityManager->persist(new Subscription($user, $empty, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->persist(
            new Subscription($stranger, $theirs, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entry($theirs, 'x', '2026-07-20');
        $this->entityManager->flush();

        self::assertSame([], $this->repository()->entryCountsForUser($user->requireId()));
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
        $firstSubscription = new Subscription($user, $first, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $secondSubscription = new Subscription($user, $second, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($firstSubscription);
        $this->entityManager->persist($secondSubscription);
        $this->entry($first, 'a', '2026-07-01');
        $this->entry($second, 'b', '2026-07-02');
        $this->entry($second, 'c', '2026-07-03');
        $this->entityManager->flush();

        $counts = $this->repository()->entryCountsForUser($user->requireId());

        self::assertSame([$firstSubscription->requireId() => 1, $secondSubscription->requireId() => 2], $counts);
    }

    private function repository(): SubscriptionRepository
    {
        $repository = $this->entityManager->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repository);

        return $repository;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid, string $day): Entry
    {
        $publishedAt = new \DateTimeImmutable($day . 'T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, new \DateTimeImmutable('2026-07-01T00:00:00Z'), $publishedAt);
        $entry->setPublishedAt($publishedAt);
        $this->entityManager->persist($entry);

        return $entry;
    }
}
