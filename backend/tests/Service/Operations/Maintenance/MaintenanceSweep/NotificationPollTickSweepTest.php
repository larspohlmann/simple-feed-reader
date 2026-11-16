<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Operations\Maintenance\MaintenanceSweep\NotificationPollTickSweep;
use App\Service\Social\Notification\Model\NotificationPollReportModel;
use App\Tests\DbTestCase;
use App\Tests\Support\NotificationPollWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubNotificationSource;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationPollTickSweepTest extends DbTestCase
{
    private MockClock $clock;
    private StubNotificationSource $source;
    private NotificationPollTickSweep $sweep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-17 12:00:00', 'UTC');
        $this->source = new StubNotificationSource();
        $this->sweep = new NotificationPollTickSweep(
            NotificationPollWiring::sweep(
                self::getContainer(),
                $this->entityManager,
                $this->clock,
                NotificationPollWiring::throttle($this->clock),
                NotificationPollWiring::allNetworks($this->source),
            ),
            $this->clock,
        );

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new UserFactory($this->entityManager, $hasher)->create('tick-sweep-notifications@example.test');
        $this->entityManager->persist(SocialAccounts::blueskyAs($user, 'did:plc:tick-poll-1'));
        $this->entityManager->persist(SocialAccounts::blueskyAs($user, 'did:plc:tick-poll-2'));
        $this->entityManager->flush();
    }

    public function testADeadlineLessThanOneAccountAwayAsksTheNetworkNothing(): void
    {
        $report = $this->sweep->run($this->clock->now()->modify('+4 seconds'));

        self::assertEquals(NotificationPollReportModel::none(), $report);
        self::assertSame([], $this->source->fetchedIdentities);
    }

    public function testALongDeadlineStillChecksOnlyOneAccount(): void
    {
        $report = $this->sweep->run($this->clock->now()->modify('+60 seconds'));

        self::assertSame(1, $report->accounts);
        self::assertCount(1, $this->source->fetchedIdentities);
    }

    public function testTheIdleReportIsEmpty(): void
    {
        self::assertEquals(NotificationPollReportModel::none(), $this->sweep->idleReport());
    }
}
