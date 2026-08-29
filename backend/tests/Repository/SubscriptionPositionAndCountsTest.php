<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * SubscriptionRepository's positioning seed (nextPositionForUser()) and its
 * per-user counts (countsByUserIds(), findForUserWithTags()) — the queries
 * SubscriptionTagPositions and the admin user list build on. See
 * SubscriptionTagPositionsTest for the in-memory counters these seed.
 */
final class SubscriptionPositionAndCountsTest extends DbTestCase
{
    private SubscriptionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var SubscriptionRepository $repository */
        $repository = $this->em->getRepository(Subscription::class);
        $this->repository = $repository;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    private function subscribe(User $user, Feed $feed, int $position = 0): Subscription
    {
        $subscription = new Subscription($user, $feed, $this->now());
        $subscription->setPosition($position);
        $this->em->persist($subscription);

        return $subscription;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01T00:00:00Z');
    }

    public function testNextPositionForUserSeedsAtZeroWithNoSubscriptions(): void
    {
        $user = $this->user('next-position-empty@example.com');
        $this->em->flush();

        self::assertSame(0, $this->repository->nextPositionForUser((int) $user->getId()));
    }

    public function testNextPositionForUserIsOnePastTheCurrentMaximum(): void
    {
        $user = $this->user('next-position-seeded@example.com');
        $this->subscribe($user, $this->feed('https://a.example/feed.xml'), 4);
        $this->em->flush();

        self::assertSame(5, $this->repository->nextPositionForUser((int) $user->getId()));
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
        $this->em->flush();
        $second = $this->subscribe($user, $this->feed('https://b.example/feed.xml'));
        $this->em->flush();

        $rows = $this->repository->findForUserWithTags((int) $user->getId());

        self::assertSame(
            [(int) $first->getId(), (int) $second->getId()],
            array_map(static fn (Subscription $s): int => (int) $s->getId(), $rows),
        );
    }

    /**
     * findForUserByTagId() backs TagController::delete()'s detach loop —
     * every subscription carrying the tag must come back, not just the
     * first, or a tag deletion would leave later feeds still wearing a tag
     * that no longer exists.
     */
    public function testFindForUserByTagIdReturnsEveryCarryingSubscription(): void
    {
        $user = $this->user('find-by-tag-many@example.com');
        $tag = new Tag($user, 'Shared');
        $this->em->persist($tag);
        $first = $this->subscribe($user, $this->feed('https://a.example/feed.xml'));
        $first->addTag($tag, 0);
        $second = $this->subscribe($user, $this->feed('https://b.example/feed.xml'));
        $second->addTag($tag, 1);
        $this->em->flush();

        $rows = $this->repository->findForUserByTagId((int) $user->getId(), (int) $tag->getId());

        self::assertSame(
            [(int) $first->getId(), (int) $second->getId()],
            array_map(static fn (Subscription $s): int => (int) $s->getId(), $rows),
        );
    }

    public function testCountsByUserIdsReturnsAnEmptyArrayWithoutQueryingForAnEmptyIdList(): void
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        self::assertSame([], $this->repository->countsByUserIds([]));

        self::assertSame(
            [],
            $recorder->queriesMatching('subscription'),
            'an empty id list must short-circuit before any query runs — an empty IN () is a syntax error.',
        );
    }

    public function testCountsByUserIdsCountsEachUsersOwnSubscriptions(): void
    {
        $first = $this->user('counts-first@example.com');
        $second = $this->user('counts-second@example.com');
        $this->subscribe($first, $this->feed('https://a.example/feed.xml'));
        $this->subscribe($first, $this->feed('https://b.example/feed.xml'));
        $this->subscribe($second, $this->feed('https://c.example/feed.xml'));
        $this->em->flush();

        $counts = $this->repository->countsByUserIds([(int) $first->getId(), (int) $second->getId()]);

        self::assertSame(
            [(int) $first->getId() => 2, (int) $second->getId() => 1],
            $counts,
        );
    }
}
