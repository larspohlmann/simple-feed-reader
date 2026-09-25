<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;

/**
 * EntryPartInspector's account entry ceiling reads the account's entries
 * through its subscriptions, not the whole table — an entry in a feed nobody
 * here reads any more, or a feed a stranger owns, must not count.
 */
final class CountInFeedsSubscribedByTest extends DbTestCase
{
    public function testCountsOnlyEntriesInFeedsTheUserSubscribesTo(): void
    {
        $user = new User('ceiling-count@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
        $this->em->persist($user);

        $subscribed = $this->feed('https://subscribed.example/feed.xml');
        $unsubscribed = $this->feed('https://unsubscribed.example/feed.xml');
        $this->em->persist(new Subscription($user, $subscribed, new \DateTimeImmutable('2026-07-01 00:00:00')));

        $this->entry($subscribed, 'a');
        $this->entry($subscribed, 'b');
        $this->entry($unsubscribed, 'c');
        $this->em->flush();

        self::assertSame(2, $this->repository()->countInFeedsSubscribedBy($user->requireId()));
    }

    public function testAUserWithNoSubscriptionsCountsZero(): void
    {
        $user = new User('ceiling-count-none@example.com', new \DateTimeImmutable('2026-07-01 00:00:00'));
        $this->em->persist($user);
        $this->em->flush();

        self::assertSame(0, $this->repository()->countInFeedsSubscribedBy($user->requireId()));
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-08-02 06:00:00'),
            new \DateTimeImmutable('2026-08-02 05:00:00'),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function repository(): EntryRepository
    {
        $repository = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repository);

        return $repository;
    }
}
