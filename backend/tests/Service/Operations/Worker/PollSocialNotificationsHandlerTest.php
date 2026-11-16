<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Service\Operations\Worker\Handler\PollSocialNotificationsHandler;
use App\Service\Operations\Worker\Message\PollSocialNotifications;
use App\Tests\DbTestCase;
use App\Tests\Support\NotificationPollWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubNotificationSource;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PollSocialNotificationsHandlerTest extends DbTestCase
{
    public function testSweepsTwentyAccounts(): void
    {
        $clock = new MockClock('2026-11-17 12:00:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = new UserFactory($this->entityManager, $hasher)->create('poll-handler-owner@example.test');
        for ($index = 1; $index <= 21; ++$index) {
            $this->entityManager->persist(SocialAccounts::blueskyAs($owner, 'did:plc:handler-' . $index));
        }
        $this->entityManager->flush();
        $source = new StubNotificationSource();
        $logger = new RecordingLogger();
        $sweep = NotificationPollWiring::sweep(
            self::getContainer(),
            $this->entityManager,
            $clock,
            NotificationPollWiring::throttle($clock),
            NotificationPollWiring::allNetworks($source),
            $logger,
        );

        (new PollSocialNotificationsHandler($sweep, $logger))(new PollSocialNotifications());

        self::assertCount(20, $source->fetchedIdentities);
        self::assertSame('Worker notification poll finished.', $logger->records[0]['message']);
        self::assertSame(
            ['accounts' => 20, 'failed' => 0, 'newRows' => 0, 'pruned' => 0],
            $logger->records[0]['context']['report'],
        );
    }
}
