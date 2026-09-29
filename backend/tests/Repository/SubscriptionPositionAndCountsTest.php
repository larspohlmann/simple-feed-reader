<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class SubscriptionPositionAndCountsTest extends DbTestCase
{
    use SeedsUsers;

    private SubscriptionRepository $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $this->entityManager->getRepository(Subscription::class);
        $this->subscriptions = $subscriptions;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed, int $position = 0): Subscription
    {
        $subscription = new Subscription($user, $feed, $this->now());
        $subscription->setPosition($position);
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T00:00:00Z');
    }

    public function testNextPositionForUserSeedsAtZeroWithNoSubscriptions(): void
    {
        $user = $this->user('next-position-empty@example.com');
        $this->entityManager->flush();

        self::assertSame(0, $this->subscriptions->nextPositionForUser($user->requireId()));
    }

    public function testNextPositionForUserIsOnePastTheCurrentMaximum(): void
    {
        $user = $this->user('next-position-seeded@example.com');
        $this->subscribe($user, $this->feed('https://a.example/feed.xml'), 4);
        $this->entityManager->flush();

        self::assertSame(5, $this->subscriptions->nextPositionForUser($user->requireId()));
    }

    /**
     * findForUserWithTags() must return every one of the user's subscriptions,
     * not just the first — a single-row assertion cannot tell "returns
     * everything" apart from "returns at most one".
     */
    public function testFindForUserWithTagsReturnsEveryOwnedSubscription(): void
    {
        $user = $this->user('find-with-tags-many@example.com');
        $first = $this->subscribe($user, $this->feed('https://a.example/feed.xml'));
        $this->entityManager->flush();
        $second = $this->subscribe($user, $this->feed('https://b.example/feed.xml'));
        $this->entityManager->flush();

        $rows = $this->subscriptions->findForUserWithTags($user->requireId());

        self::assertSame(
            [$first->requireId(), $second->requireId()],
            array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $rows),
        );
    }

    /** TagEditor::delete() detaches through it: a missed subscription would keep wearing the deleted tag. */
    public function testFindForUserByTagIdReturnsEveryCarryingSubscription(): void
    {
        $user = $this->user('find-by-tag-many@example.com');
        $tag = new Tag($user, 'Shared');
        $this->entityManager->persist($tag);
        $first = $this->subscribe($user, $this->feed('https://a.example/feed.xml'));
        $first->addTag($tag, 0);
        $second = $this->subscribe($user, $this->feed('https://b.example/feed.xml'));
        $second->addTag($tag, 1);
        $this->entityManager->flush();

        $rows = $this->subscriptions->findForUserByTagId($user->requireId(), $tag->requireId());

        self::assertSame(
            [$first->requireId(), $second->requireId()],
            array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $rows),
        );
    }
}
