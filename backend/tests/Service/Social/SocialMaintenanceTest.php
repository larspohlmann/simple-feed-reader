<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\ProfileImageSweep;
use App\Service\Social\SocialMaintenance;
use App\Tests\DbTestCase;
use App\Tests\Support\PrivateFeedSyncWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubSocialCandidates;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialMaintenanceTest extends DbTestCase
{
    private MockClock $clock;
    private StubSocialCandidates $network;
    private SocialMaintenance $maintenance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-17 12:00:00', 'UTC');
        $this->network = new StubSocialCandidates(
            new NetworkCandidatesModel([], [], [], false),
            new NetworkCollectionsModel([], []),
        );
        $profileImages = self::getContainer()->get(ProfileImageSweep::class);
        self::assertInstanceOf(ProfileImageSweep::class, $profileImages);
        $this->maintenance = new SocialMaintenance(
            $profileImages,
            PrivateFeedSyncWiring::sweep(self::getContainer(), $this->entityManager, $this->clock, $this->network),
            $this->clock,
        );

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('social-maintenance@example.test');
        $this->entityManager->persist(SocialAccounts::blueskyAs($user, 'did:plc:maintenance-1'));
        $this->entityManager->flush();
    }

    public function testADeadlineLessThanOneAccountAwayAsksTheNetworkNothing(): void
    {
        $report = $this->maintenance->syncPrivateFeeds($this->clock->now()->modify('+4 seconds'));

        self::assertSame(0, $report->checked);
        self::assertSame(0, $this->network->calls);
    }

    public function testADeadlineOneAccountAwayChecksOneDueAccount(): void
    {
        $report = $this->maintenance->syncPrivateFeeds($this->clock->now()->modify('+5 seconds'));

        self::assertSame(1, $report->checked);
        self::assertSame(1, $this->network->calls);
    }
}
