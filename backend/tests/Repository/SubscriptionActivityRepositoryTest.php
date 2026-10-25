<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
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

    private function view(User $user, Entry $entry, string $viewedAt): void
    {
        $state = new EntryState($user, $entry);
        $state->markViewed(new \DateTimeImmutable($viewedAt));
        $this->entityManager->persist($state);
    }

    /**
     * @param list<array{day: string, count: int}> $rows
     *
     * @return array<string, int>
     */
    private function byDay(array $rows): array
    {
        $counts = array_column($rows, 'count', 'day');
        ksort($counts);

        return $counts;
    }

    public function testTracksEachSubscriptionWithItsFeedCreatedDayAndWhetherItIsSocial(): void
    {
        $user = $this->user('tracked@example.com');
        $social = $this->feed('https://bsky.app/profile/alice.bsky.social/rss');
        $plain = $this->subscribe($user, $this->feed('https://example.com/a.xml'), '2026-10-03 23:59:00');
        $timeline = $this->subscribe($user, $social, '2026-10-04 00:00:00');
        $this->subscribe(
            $this->user('someone@example.com'),
            $this->feed('https://example.com/b.xml'),
            '2026-10-05 08:00:00',
        );
        $this->entityManager->flush();

        $mine = $this->repository()->trackedSubscriptionsOf($user->requireId());
        usort($mine, static fn ($left, $right): int => $left->subscriptionId <=> $right->subscriptionId);

        self::assertCount(2, $mine);
        self::assertEquals(
            new TrackedSubscriptionModel($plain->requireId(), $plain->getFeed()->requireId(), '2026-10-03', false),
            $mine[0],
        );
        self::assertEquals(
            new TrackedSubscriptionModel($timeline->requireId(), $social->requireId(), '2026-10-04', true),
            $mine[1],
        );
        self::assertCount(3, $this->repository()->trackedSubscriptions());
    }

    public function testASavedFeedIsNotTracked(): void
    {
        $user = $this->user('saver@example.com');
        $saved = $this->feed('saved:1');
        $saved->setSourceFormat(SourceFormat::SAVED);
        $this->subscribe($user, $saved, '2026-10-03 00:00:00');
        $plain = $this->subscribe($user, $this->feed('https://example.com/plain.xml'), '2026-10-03 00:00:00');
        $this->entityManager->flush();

        self::assertSame(
            [$plain->requireId()],
            TrackedSubscriptionModel::idsOf($this->repository()->trackedSubscriptionsOf($user->requireId())),
        );
        self::assertSame(
            [$plain->requireId()],
            TrackedSubscriptionModel::idsOf($this->repository()->trackedSubscriptions()),
        );
    }

    public function testArrivalsAreCountedPerFeedAndUtcDayFromTheBound(): void
    {
        $feed = $this->feed('https://example.com/shared.xml');
        $other = $this->feed('https://example.com/other.xml');
        $this->subscribe($this->user('one@example.com'), $feed, '2026-10-01 00:00:00');
        $this->subscribe($this->user('two@example.com'), $feed, '2026-10-01 00:00:00');
        $this->entry($feed, 'before-bound', '2026-10-09 23:59:59');
        $this->entry($feed, 'at-bound', '2026-10-10 00:00:00');
        $this->entry($feed, 'late-evening', '2026-10-10 23:59:59');
        $this->entry($feed, 'next-day', '2026-10-11 00:00:00');
        $this->entry($other, 'elsewhere', '2026-10-10 12:00:00');
        $this->entityManager->flush();

        $rows = $this->repository()->arrivalCountsSince(
            [$feed->requireId()],
            new \DateTimeImmutable('2026-10-10 00:00:00'),
        );

        self::assertSame([$feed->requireId()], array_values(array_unique(array_column($rows, 'feedId'))));
        self::assertSame(['2026-10-10' => 2, '2026-10-11' => 1], $this->byDay($rows));
    }

    public function testReadsAreTheOwnersOpensOfThatFeedPerUtcDayFromTheBound(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $feed = $this->feed('https://example.com/read.xml');
        $mine = $this->subscribe($owner, $feed, '2026-10-01 00:00:00');
        $this->subscribe($stranger, $feed, '2026-10-01 00:00:00');
        $this->view($owner, $this->entry($feed, 'before-bound', '2026-10-01 00:00:00'), '2026-10-09 23:59:59');
        $this->view($owner, $this->entry($feed, 'morning', '2026-10-01 00:00:00'), '2026-10-11 09:00:00');
        $this->view($owner, $this->entry($feed, 'evening', '2026-10-01 00:00:00'), '2026-10-11 23:59:59');
        $this->view($owner, $this->entry($feed, 'next-day', '2026-10-01 00:00:00'), '2026-10-12 00:00:00');
        $this->view($stranger, $this->entry($feed, 'theirs', '2026-10-01 00:00:00'), '2026-10-11 10:00:00');
        $this->entityManager->flush();

        $rows = $this->repository()->readCountsSince(
            [$mine->requireId()],
            new \DateTimeImmutable('2026-10-10 00:00:00'),
        );

        self::assertSame([$mine->requireId()], array_values(array_unique(array_column($rows, 'subscriptionId'))));
        self::assertSame(['2026-10-11' => 2, '2026-10-12' => 1], $this->byDay($rows));
    }

    public function testNoIdsQueriesNothing(): void
    {
        self::assertSame([], $this->repository()->arrivalCountsSince([], new \DateTimeImmutable('2026-10-10')));
        self::assertSame([], $this->repository()->readCountsSince([], new \DateTimeImmutable('2026-10-10')));
    }
}
