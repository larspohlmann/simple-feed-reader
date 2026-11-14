<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SavedSearchNetworkInclusionRepository;
use App\Service\Search\SavedSearchNetworks;
use App\Tests\DbTestCase;

final class SavedSearchNetworksTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testEveryNetworkStartsExcluded(): void
    {
        self::assertSame([], $this->inclusions()->includedNetworksFor($this->user->requireId()));
    }

    public function testIncludingTwiceStoresOneRowAndExcludingRemovesIt(): void
    {
        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        $this->networks()->include($this->user, SocialNetwork::Bluesky);
        self::assertSame(
            [SocialNetwork::Bluesky],
            $this->inclusions()->includedNetworksFor($this->user->requireId()),
        );

        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        $this->networks()->exclude($this->user, SocialNetwork::Bluesky);
        self::assertSame([], $this->inclusions()->includedNetworksFor($this->user->requireId()));
    }

    public function testIncludedNetworksAreListedInNetworkOrder(): void
    {
        $this->networks()->include($this->user, SocialNetwork::Mastodon);
        $this->networks()->include($this->user, SocialNetwork::Bluesky);

        self::assertSame(
            [SocialNetwork::Bluesky, SocialNetwork::Mastodon],
            $this->inclusions()->includedNetworksFor($this->user->requireId()),
        );
    }

    public function testAnotherUsersInclusionIsNeitherListedNorFound(): void
    {
        $other = new User('other-networks@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->networks()->include($other, SocialNetwork::Bluesky);
        $this->networks()->include($this->user, SocialNetwork::Mastodon);

        $userId = $this->user->requireId();

        self::assertSame([SocialNetwork::Mastodon], $this->inclusions()->includedNetworksFor($userId));
        self::assertNull($this->inclusions()->findOneFor($userId, SocialNetwork::Bluesky));
        self::assertNotNull($this->inclusions()->findOneFor($userId, SocialNetwork::Mastodon));
    }

    private function networks(): SavedSearchNetworks
    {
        $service = self::getContainer()->get(SavedSearchNetworks::class);
        self::assertInstanceOf(SavedSearchNetworks::class, $service);

        return $service;
    }

    private function inclusions(): SavedSearchNetworkInclusionRepository
    {
        $repository = self::getContainer()->get(SavedSearchNetworkInclusionRepository::class);
        self::assertInstanceOf(SavedSearchNetworkInclusionRepository::class, $repository);

        return $repository;
    }
}
