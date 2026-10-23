<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FeedRepositoryTagScopeTest extends DbTestCase
{
    public function testTagScopeSelectsOnlyFeedsCarryingThatTag(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->entityManager, $hasher);
        $owner = $factory->create('owner@example.com');

        $tag = new Tag($owner, 'news');
        $this->entityManager->persist($tag);

        $tagged = new Feed('https://example.com/tagged.xml');
        $this->entityManager->persist($tagged);
        $taggedSubscription = new Subscription($owner, $tagged, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $taggedSubscription->addTag($tag);
        $this->entityManager->persist($taggedSubscription);

        $untagged = new Feed('https://example.com/untagged.xml');
        $this->entityManager->persist($untagged);
        $this->entityManager->persist(
            new Subscription($owner, $untagged, new \DateTimeImmutable('2026-01-01T00:00:00Z')),
        );

        $this->entityManager->flush();

        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');
        $ownerId = $owner->requireId();
        $tagId = $tag->requireId();

        $due = $repository->findDue(new DueFeedCriteria($now, $ownerId, tagId: $tagId, force: true), 50);
        self::assertSame([$tagged->getId()], array_map(static fn (Feed $feed): ?int => $feed->getId(), $due));
        self::assertSame(1, $repository->countDue(new DueFeedCriteria($now, $ownerId, tagId: $tagId, force: true)));
    }

    public function testTagScopeExcludesAnotherUsersFeedWithTheSameTagName(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->entityManager, $hasher);
        $owner = $factory->create('owner@example.com');
        $stranger = $factory->create('stranger@example.com');

        $ownerTag = new Tag($owner, 'news');
        $strangerTag = new Tag($stranger, 'news');
        $this->entityManager->persist($ownerTag);
        $this->entityManager->persist($strangerTag);

        $strangerFeed = new Feed('https://example.com/stranger.xml');
        $this->entityManager->persist($strangerFeed);
        $strangerSubscription = new Subscription(
            $stranger,
            $strangerFeed,
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $strangerSubscription->addTag($strangerTag);
        $this->entityManager->persist($strangerSubscription);

        $this->entityManager->flush();

        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');

        // The owner scoping their own tag id must not reach the stranger's feed,
        // even though the tag shares a name.
        $due = $repository->findDue(
            new DueFeedCriteria($now, $owner->requireId(), tagId: $ownerTag->requireId(), force: true),
            50,
        );
        self::assertCount(0, $due);
        self::assertSame(0, $repository->countDue(
            new DueFeedCriteria($now, $owner->requireId(), tagId: $ownerTag->requireId(), force: true),
        ));
    }

    public function testAParentScopeIncludesItsChildrensFeedsAndAChildScopeOnlyItsOwn(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new UserFactory($this->entityManager, $hasher))->create('owner@example.com');
        $reading = new Tag($owner, 'reading');
        $this->entityManager->persist($reading);
        $longReads = new Tag($owner, 'long reads');
        $longReads->setParent($reading);
        $this->entityManager->persist($longReads);
        $parentFeed = $this->taggedFeed($owner, 'https://example.com/parent.xml', $reading);
        $childFeed = $this->taggedFeed($owner, 'https://example.com/child.xml', $longReads);
        $this->entityManager->flush();

        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);
        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');
        $ownerId = $owner->requireId();

        $parentScope = new DueFeedCriteria($now, $ownerId, tagId: $reading->requireId(), force: true);
        self::assertEqualsCanonicalizing(
            [$parentFeed->getId(), $childFeed->getId()],
            array_map(static fn (Feed $feed): ?int => $feed->getId(), $repository->findDue($parentScope, 50)),
        );
        self::assertSame(2, $repository->countDue($parentScope));

        $childScope = new DueFeedCriteria($now, $ownerId, tagId: $longReads->requireId(), force: true);
        self::assertSame(
            [$childFeed->getId()],
            array_map(static fn (Feed $feed): ?int => $feed->getId(), $repository->findDue($childScope, 50)),
        );
    }

    private function taggedFeed(User $owner, string $url, Tag $tag): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($owner, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $subscription->addTag($tag);
        $this->entityManager->persist($subscription);

        return $feed;
    }
}
