<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;

final class FeedRepositoryProfileImageDueTest extends DbTestCase
{
    private const string CHECKED_BEFORE = '2026-10-05T00:00:00Z';

    private User $subscriber;

    public function testBlueskyProfileFeedsAreDueNeverCheckedFirstThenTheOldestCheck(): void
    {
        $recent = $this->feed('https://bsky.app/profile/recent.bsky.social/rss', '2026-10-10T00:00:00Z');
        $old = $this->feed('https://bsky.app/profile/old.bsky.social/rss', '2026-09-01T00:00:00Z');
        $older = $this->feed('https://bsky.app/profile/older.bsky.social/rss', '2026-08-01T00:00:00Z');
        $never = $this->feed('https://bsky.app/profile/never.bsky.social/rss', null);
        $this->feed('https://mastodon.social/@Gargron.rss', null);
        $this->feed('https://example.com/feed.xml', null);
        $this->feed('https://bsky.app/profile/orphan.bsky.social/rss', null, subscribed: false);

        $due = $this->feeds()->findBlueskyProfileFeedsWithImageDue(new \DateTimeImmutable(self::CHECKED_BEFORE), 10);

        self::assertSame(
            [$never->requireId(), $older->requireId(), $old->requireId()],
            array_map(static fn (Feed $feed): int => $feed->requireId(), $due),
        );
        self::assertNotContains($recent, $due);
    }

    public function testItHandsOutAtMostTheLimit(): void
    {
        $this->feed('https://bsky.app/profile/a.bsky.social/rss', null);
        $this->feed('https://bsky.app/profile/b.bsky.social/rss', null);

        $due = $this->feeds()->findBlueskyProfileFeedsWithImageDue(new \DateTimeImmutable(self::CHECKED_BEFORE), 1);

        self::assertCount(1, $due);
    }

    public function testTheClassifiersSpellingsOfTheProfileUrlAreDueToo(): void
    {
        $plain = $this->feed('http://bsky.app/profile/plain.bsky.social/rss', null);
        $shouting = $this->feed('https://BSKY.app/profile/shouting.bsky.social/rss', null);

        $due = $this->feeds()->findBlueskyProfileFeedsWithImageDue(new \DateTimeImmutable(self::CHECKED_BEFORE), 10);

        self::assertSame(
            [$plain->requireId(), $shouting->requireId()],
            array_map(static fn (Feed $feed): int => $feed->requireId(), $due),
        );
    }

    private function feed(string $url, ?string $checkedAt, bool $subscribed = true): Feed
    {
        $feed = new Feed($url);
        if (null !== $checkedAt) {
            $feed->markImageCheckedAt(new \DateTimeImmutable($checkedAt));
        }
        $this->entityManager->persist($feed);
        if ($subscribed) {
            $subscribedAt = new \DateTimeImmutable(self::CHECKED_BEFORE);
            $this->entityManager->persist(new Subscription($this->subscriber(), $feed, $subscribedAt));
        }
        $this->entityManager->flush();

        return $feed;
    }

    private function subscriber(): User
    {
        if (!isset($this->subscriber)) {
            $this->subscriber = new User('profile-image-due@example.com', new \DateTimeImmutable(self::CHECKED_BEFORE));
            $this->entityManager->persist($this->subscriber);
        }

        return $this->subscriber;
    }

    private function feeds(): FeedRepository
    {
        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
