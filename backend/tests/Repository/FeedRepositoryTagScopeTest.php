<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
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
        $factory = new UserFactory($this->em, $hasher);
        $owner = $factory->create('owner@example.com');

        $tag = new Tag($owner, 'news');
        $this->em->persist($tag);

        $tagged = new Feed('https://example.com/tagged.xml');
        $this->em->persist($tagged);
        $taggedSub = new Subscription($owner, $tagged, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $taggedSub->addTag($tag);
        $this->em->persist($taggedSub);

        $untagged = new Feed('https://example.com/untagged.xml');
        $this->em->persist($untagged);
        $this->em->persist(new Subscription($owner, $untagged, new \DateTimeImmutable('2026-01-01T00:00:00Z')));

        $this->em->flush();

        $repo = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repo);

        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');
        $ownerId = (int) $owner->getId();
        $tagId = (int) $tag->getId();

        $due = $repo->findDue($now, 50, $ownerId, tagId: $tagId, force: true);
        self::assertSame([$tagged->getId()], array_map(static fn (Feed $f): ?int => $f->getId(), $due));
        self::assertSame(1, $repo->countDue($now, $ownerId, tagId: $tagId, force: true));
    }

    public function testTagScopeExcludesAnotherUsersFeedWithTheSameTagName(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $owner = $factory->create('owner@example.com');
        $stranger = $factory->create('stranger@example.com');

        $ownerTag = new Tag($owner, 'news');
        $strangerTag = new Tag($stranger, 'news');
        $this->em->persist($ownerTag);
        $this->em->persist($strangerTag);

        $strangerFeed = new Feed('https://example.com/stranger.xml');
        $this->em->persist($strangerFeed);
        $strangerSub = new Subscription($stranger, $strangerFeed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $strangerSub->addTag($strangerTag);
        $this->em->persist($strangerSub);

        $this->em->flush();

        $repo = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repo);

        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');

        // The owner scoping their own tag id must not reach the stranger's feed,
        // even though the tag shares a name.
        $due = $repo->findDue($now, 50, (int) $owner->getId(), tagId: (int) $ownerTag->getId(), force: true);
        self::assertCount(0, $due);
        self::assertSame(0, $repo->countDue($now, (int) $owner->getId(), tagId: (int) $ownerTag->getId(), force: true));
    }
}
