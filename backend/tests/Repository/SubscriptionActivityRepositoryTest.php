<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionActivityRepository;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Tests\DbTestCase;

final class SubscriptionActivityRepositoryTest extends DbTestCase
{
    private function repository(): SubscriptionActivityRepository
    {
        return new SubscriptionActivityRepository($this->entityManager);
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-09-01 00:00:00'));
        $this->entityManager->persist($user);

        return $user;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed, string $at): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable($at));
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function entry(Feed $feed, string $guid, string $createdAt): Entry
    {
        $created = new \DateTimeImmutable($createdAt);
        $entry = new Entry($feed, $guid, null, $guid, $created, $created);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * @param list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}> $rows
     *
     * @return list<string>
     */
    private function flatten(array $rows): array
    {
        $flat = array_map(
            static fn (array $row): string => $row['subscriptionId'] . '@' . $row['happenedAt']->format('Y-m-d H:i'),
            $rows,
        );
        sort($flat);

        return $flat;
    }

    public function testTracksEachSubscriptionWithItsCreatedDayAndWhetherItIsSocial(): void
    {
        $user = $this->user('tracked@example.com');
        $social = $this->feed('https://bsky.app/profile/alice.bsky.social/rss');
        $social->classifyAs(SocialNetwork::Bluesky);
        $plain = $this->subscribe($user, $this->feed('https://example.com/a.xml'), '2026-10-03 23:59:00');
        $timeline = $this->subscribe($user, $social, '2026-10-04 00:00:00');
        $other = $this->feed('https://example.com/b.xml');
        $this->subscribe($this->user('someone@example.com'), $other, '2026-10-05 08:00:00');
        $this->entityManager->flush();

        $mine = $this->repository()->trackedSubscriptionsOf($user->requireId());
        usort($mine, static fn ($left, $right): int => $left->subscriptionId <=> $right->subscriptionId);

        self::assertCount(2, $mine);
        self::assertEquals(new TrackedSubscriptionModel($plain->requireId(), '2026-10-03', false), $mine[0]);
        self::assertEquals(new TrackedSubscriptionModel($timeline->requireId(), '2026-10-04', true), $mine[1]);
        self::assertCount(3, $this->repository()->trackedSubscriptions());
    }

    public function testArrivalsAreTheFeedsEntriesSinceTheBoundPerSubscription(): void
    {
        $feed = $this->feed('https://example.com/shared.xml');
        $first = $this->subscribe($this->user('one@example.com'), $feed, '2026-10-01 00:00:00');
        $second = $this->subscribe($this->user('two@example.com'), $feed, '2026-10-01 00:00:00');
        $this->entry($feed, 'old', '2026-10-09 23:59:59');
        $this->entry($feed, 'new', '2026-10-10 00:00:00');
        $this->entityManager->flush();

        $bound = new \DateTimeImmutable('2026-10-10 00:00:00');
        $rows = $this->repository()->arrivalsSince([$first->requireId()], $bound);

        self::assertSame([$first->requireId() . '@2026-10-10 00:00'], $this->flatten($rows));
        self::assertNotContains($second->requireId(), array_column($rows, 'subscriptionId'));
    }

    public function testViewsAreTheOwnersOpensOfThatFeedSinceTheBound(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $feed = $this->feed('https://example.com/read.xml');
        $mine = $this->subscribe($owner, $feed, '2026-10-01 00:00:00');
        $this->subscribe($stranger, $feed, '2026-10-01 00:00:00');
        $entry = $this->entry($feed, 'a', '2026-10-01 00:00:00');
        $ownState = new EntryState($owner, $entry);
        $ownState->markViewed(new \DateTimeImmutable('2026-10-11 09:00:00'));
        $strangerState = new EntryState($stranger, $entry);
        $strangerState->markViewed(new \DateTimeImmutable('2026-10-11 10:00:00'));
        $this->entityManager->persist($ownState);
        $this->entityManager->persist($strangerState);
        $this->entityManager->flush();

        $rows = $this->repository()->viewsSince([$mine->requireId()], new \DateTimeImmutable('2026-10-10 00:00:00'));

        self::assertSame([$mine->requireId() . '@2026-10-11 09:00'], $this->flatten($rows));
    }

    public function testNoSubscriptionIdsQueriesNothing(): void
    {
        self::assertSame([], $this->repository()->arrivalsSince([], new \DateTimeImmutable('2026-10-10')));
        self::assertSame([], $this->repository()->viewsSince([], new \DateTimeImmutable('2026-10-10')));
    }
}
