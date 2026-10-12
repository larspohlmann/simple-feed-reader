<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;

final class FeedRepositoryFeedSetScopeTest extends DbTestCase
{
    private const string NOW = '2026-06-01T00:00:00Z';

    public function testAFeedSetScopeSelectsOnlyThoseFeeds(): void
    {
        $owner = $this->user('feed-set-owner@example.com');
        $inSet = $this->subscribedFeed($owner, 'https://example.com/in-set.xml');
        $this->subscribedFeed($owner, 'https://example.com/outside.xml');

        $criteria = $this->criteria($owner, [$inSet->requireId()]);

        self::assertSame([$inSet->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
        self::assertSame(1, $this->feeds()->countDue($criteria));
    }

    public function testAnEmptyFeedSetSelectsNothing(): void
    {
        $owner = $this->user('feed-set-empty@example.com');
        $this->subscribedFeed($owner, 'https://example.com/any.xml');

        $criteria = $this->criteria($owner, []);

        self::assertSame([], $this->feeds()->findDue($criteria, 50));
        self::assertSame(0, $this->feeds()->countDue($criteria));
    }

    public function testAFeedSetNeverReachesAFeedTheUserDoesNotSubscribeTo(): void
    {
        $owner = $this->user('feed-set-self@example.com');
        $stranger = $this->user('feed-set-stranger@example.com');
        $foreign = $this->subscribedFeed($stranger, 'https://example.com/foreign.xml');

        self::assertSame([], $this->feeds()->findDue($this->criteria($owner, [$foreign->requireId()]), 50));
    }

    /** @param list<int> $feedIds */
    private function criteria(User $owner, array $feedIds): DueFeedCriteria
    {
        return new DueFeedCriteria(
            new \DateTimeImmutable(self::NOW),
            $owner->requireId(),
            force: true,
            feedIds: $feedIds,
        );
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return list<int>
     */
    private function idsOf(array $feeds): array
    {
        return array_map(static fn (Feed $feed): int => $feed->requireId(), $feeds);
    }

    private function subscribedFeed(User $owner, string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($owner, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $this->entityManager->flush();

        return $feed;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function feeds(): FeedRepository
    {
        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
