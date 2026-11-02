<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Feed;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\ForYouNetworkExclusionRepository;
use App\Service\Recommendation\Feed\ForYouNetworks;
use App\Tests\DbTestCase;

final class ForYouNetworksTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('for-you-networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testEveryNetworkStartsIncluded(): void
    {
        self::assertSame([], $this->exclusions()->excludedNetworksFor($this->user->requireId()));
    }

    public function testExcludingTwiceStoresOneRowAndIncludingRemovesIt(): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        self::assertSame(
            [SocialNetwork::Bluesky],
            $this->exclusions()->excludedNetworksFor($this->user->requireId()),
        );

        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        self::assertSame([], $this->exclusions()->excludedNetworksFor($this->user->requireId()));
    }

    public function testExcludedNetworksAreListedInNetworkOrder(): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);

        self::assertSame(
            [SocialNetwork::Bluesky, SocialNetwork::Mastodon],
            $this->exclusions()->excludedNetworksFor($this->user->requireId()),
        );
    }

    public function testAnotherUsersExclusionIsNeitherListedNorFound(): void
    {
        $other = new User('other-for-you-networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->networks()->exclude($other, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon);

        $userId = $this->user->requireId();

        self::assertSame([SocialNetwork::Mastodon], $this->exclusions()->excludedNetworksFor($userId));
        self::assertNull($this->exclusions()->findOneFor($userId, SocialNetwork::Bluesky));
        self::assertNotNull($this->exclusions()->findOneFor($userId, SocialNetwork::Mastodon));
    }

    private function networks(): ForYouNetworks
    {
        $service = self::getContainer()->get(ForYouNetworks::class);
        self::assertInstanceOf(ForYouNetworks::class, $service);

        return $service;
    }

    private function exclusions(): ForYouNetworkExclusionRepository
    {
        $repository = self::getContainer()->get(ForYouNetworkExclusionRepository::class);
        self::assertInstanceOf(ForYouNetworkExclusionRepository::class, $repository);

        return $repository;
    }
}
