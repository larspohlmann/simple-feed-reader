<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Enum\FeedStatus;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FeedRepositoryUserFeedScopeTest extends DbTestCase
{
    public function testPerFeedScopeStillHonoursTheSubscriptionCheck(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $owner = $factory->create('owner@example.com');
        $stranger = $factory->create('stranger@example.com');

        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $sub = new Subscription($owner, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($sub);
        $this->em->flush();

        $repo = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repo);

        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');

        // Owner CAN reach the feed by id.
        $ownerResult = $repo->findDue($now, 50, (int) $owner->getId(), (int) $feed->getId(), force: true);
        self::assertCount(1, $ownerResult);

        // Stranger CANNOT reach it by id — the subscription EXISTS clause must still apply.
        $strangerResult = $repo->findDue($now, 50, (int) $stranger->getId(), (int) $feed->getId(), force: true);
        self::assertCount(0, $strangerResult);
        self::assertSame(0, $repo->countDue($now, (int) $stranger->getId(), (int) $feed->getId(), force: true));
    }

    public function testOwnerCanStillRetryAGoneFeedById(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $owner = $factory->create('owner@example.com');
        $stranger = $factory->create('stranger@example.com');

        $feed = new Feed('https://example.com/gone.xml');
        $feed->setStatus(FeedStatus::Gone);
        $this->em->persist($feed);
        $sub = new Subscription($owner, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($sub);
        $this->em->flush();

        $repo = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repo);

        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');

        // Manual per-feed retry must ignore the "gone" filter: the owner can still
        // reach a dead feed by id. This guards against a refactor hoisting the
        // `status != gone` clause out of the else-branch.
        $ownerResult = $repo->findDue($now, 50, (int) $owner->getId(), (int) $feed->getId(), force: true);
        self::assertCount(1, $ownerResult);

        // The subscription scope still applies to the gone-feed retry path.
        $strangerResult = $repo->findDue($now, 50, (int) $stranger->getId(), (int) $feed->getId(), force: true);
        self::assertCount(0, $strangerResult);
    }
}
