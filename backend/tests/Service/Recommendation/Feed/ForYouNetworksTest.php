<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Feed;

use App\Entity\NetworkExclusion;
use App\Entity\User;
use App\Enum\NetworkExclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\NetworkExclusionRepository;
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
        self::assertSame([], $this->excludedFromForYou());
    }

    public function testExcludingTwiceStoresOneRowAndIncludingRemovesIt(): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        self::assertSame([SocialNetwork::Bluesky], $this->excludedFromForYou());

        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        self::assertSame([], $this->excludedFromForYou());
    }

    public function testExcludedNetworksAreListedInNetworkOrder(): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);

        self::assertSame([SocialNetwork::Bluesky, SocialNetwork::Mastodon], $this->excludedFromForYou());
    }

    public function testAnotherUsersExclusionIsNeitherListedNorFound(): void
    {
        $other = new User('other-for-you-networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->networks()->exclude($other, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon);

        self::assertSame([SocialNetwork::Mastodon], $this->excludedFromForYou());
        self::assertNull($this->forYouExclusionOf(SocialNetwork::Bluesky));
        self::assertNotNull($this->forYouExclusionOf(SocialNetwork::Mastodon));
    }

    public function testAnAllItemsExclusionNeitherCountsNorIsRemovedForForYou(): void
    {
        $this->entityManager->persist(
            new NetworkExclusion($this->user, SocialNetwork::Bluesky, NetworkExclusionSurface::AllItems),
        );
        $this->entityManager->flush();

        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        self::assertSame([], $this->excludedFromForYou());
        self::assertSame(
            [SocialNetwork::Bluesky],
            $this->exclusions()->excludedNetworks($this->user->requireId(), NetworkExclusionSurface::AllItems),
        );

        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        self::assertSame([SocialNetwork::Bluesky], $this->excludedFromForYou());
    }

    /** @return list<SocialNetwork> */
    private function excludedFromForYou(): array
    {
        return $this->exclusions()->excludedNetworks($this->user->requireId(), NetworkExclusionSurface::ForYou);
    }

    private function forYouExclusionOf(SocialNetwork $network): ?NetworkExclusion
    {
        return $this->exclusions()->findOneFor($this->user->requireId(), $network, NetworkExclusionSurface::ForYou);
    }

    private function networks(): ForYouNetworks
    {
        $service = self::getContainer()->get(ForYouNetworks::class);
        self::assertInstanceOf(ForYouNetworks::class, $service);

        return $service;
    }

    private function exclusions(): NetworkExclusionRepository
    {
        $repository = self::getContainer()->get(NetworkExclusionRepository::class);
        self::assertInstanceOf(NetworkExclusionRepository::class, $repository);

        return $repository;
    }
}
