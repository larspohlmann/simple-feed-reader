<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\NetworkExclusion;
use App\Entity\User;
use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\NetworkExclusionRepository;
use App\Service\Library\Reading\NetworkExclusions;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NetworkExclusionsTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('network-exclusions@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    /** @return iterable<string, array{InclusionSurface}> */
    public static function surfaces(): iterable
    {
        yield 'for you' => [InclusionSurface::ForYou];
        yield 'all items' => [InclusionSurface::AllItems];
    }

    /** @return iterable<string, array{InclusionSurface, InclusionSurface}> */
    public static function surfaceAndTheOther(): iterable
    {
        yield 'for you' => [InclusionSurface::ForYou, InclusionSurface::AllItems];
        yield 'all items' => [InclusionSurface::AllItems, InclusionSurface::ForYou];
    }

    #[DataProvider('surfaces')]
    public function testEveryNetworkStartsIncluded(InclusionSurface $surface): void
    {
        self::assertSame([], $this->excludedFrom($surface));
    }

    #[DataProvider('surfaces')]
    public function testExcludingTwiceStoresOneRowAndIncludingRemovesIt(InclusionSurface $surface): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky, $surface);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky, $surface);
        self::assertSame([SocialNetwork::Bluesky], $this->excludedFrom($surface));

        $this->networks()->include($this->user, SocialNetwork::Bluesky, $surface);
        $this->networks()->include($this->user, SocialNetwork::Bluesky, $surface);
        self::assertSame([], $this->excludedFrom($surface));
    }

    public function testExcludedNetworksAreListedInNetworkOrder(): void
    {
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon, InclusionSurface::ForYou);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky, InclusionSurface::ForYou);

        self::assertSame(
            [SocialNetwork::Bluesky, SocialNetwork::Mastodon],
            $this->excludedFrom(InclusionSurface::ForYou),
        );
    }

    public function testAnotherUsersExclusionIsNeitherListedNorFound(): void
    {
        $other = new User('other-network-exclusions@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->networks()->exclude($other, SocialNetwork::Bluesky, InclusionSurface::ForYou);
        $this->networks()->exclude($this->user, SocialNetwork::Mastodon, InclusionSurface::ForYou);

        self::assertSame([SocialNetwork::Mastodon], $this->excludedFrom(InclusionSurface::ForYou));
        self::assertNull($this->forYouExclusionOf(SocialNetwork::Bluesky));
        self::assertNotNull($this->forYouExclusionOf(SocialNetwork::Mastodon));
    }

    #[DataProvider('surfaceAndTheOther')]
    public function testTheOtherSurfacesExclusionNeitherCountsNorIsRemoved(
        InclusionSurface $surface,
        InclusionSurface $other,
    ): void {
        $this->entityManager->persist(new NetworkExclusion($this->user, SocialNetwork::Bluesky, $other));
        $this->entityManager->flush();

        $this->networks()->include($this->user, SocialNetwork::Bluesky, $surface);
        self::assertSame([], $this->excludedFrom($surface));
        self::assertSame([SocialNetwork::Bluesky], $this->excludedFrom($other));

        $this->networks()->exclude($this->user, SocialNetwork::Bluesky, $surface);
        self::assertSame([SocialNetwork::Bluesky], $this->excludedFrom($surface));
    }

    /** @return list<SocialNetwork> */
    private function excludedFrom(InclusionSurface $surface): array
    {
        return $this->exclusions()->excludedNetworks($this->user->requireId(), $surface);
    }

    private function forYouExclusionOf(SocialNetwork $network): ?NetworkExclusion
    {
        return $this->exclusions()->findOneFor($this->user->requireId(), $network, InclusionSurface::ForYou);
    }

    private function networks(): NetworkExclusions
    {
        $service = self::getContainer()->get(NetworkExclusions::class);
        self::assertInstanceOf(NetworkExclusions::class, $service);

        return $service;
    }

    private function exclusions(): NetworkExclusionRepository
    {
        $repository = self::getContainer()->get(NetworkExclusionRepository::class);
        self::assertInstanceOf(NetworkExclusionRepository::class, $repository);

        return $repository;
    }
}
