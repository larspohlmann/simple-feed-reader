<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialNotification;
use App\Entity\SocialNotificationPoll;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountSyncRepository;
use App\Repository\SocialNotificationPollRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Outbound\Fetch\HostThrottle;
use App\Service\Social\Notification\NotificationContextLookup;
use App\Service\Social\Notification\NotificationContextSource\NotificationContextSourceInterface;
use App\Service\Social\Notification\NotificationPoll;
use App\Service\Social\Notification\NotificationPollSweep;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Notification\NotificationStore;
use App\Service\Social\Social\ActingAccountGuard;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** NotificationPollSweep over the test's entity manager, clock and throttle, its sources locators of doubles. */
final class NotificationPollWiring
{
    /** @param array<string, NotificationSourceInterface> $sources keyed by SocialNetwork value */
    public static function sweep(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        HostThrottle $hostThrottle,
        array $sources,
        LoggerInterface $logger = new NullLogger(),
        ?ManagerRegistry $registry = null,
        NotificationContextSourceInterface $contextSource = new RecordingContextSource(),
    ): NotificationPollSweep {
        $naiveClock = new NaiveUtcClock($clock);
        $polls = $entityManager->getRepository(SocialNotificationPoll::class);
        Assert::assertInstanceOf(SocialNotificationPollRepository::class, $polls);
        $socialAccounts = $container->get(SocialAccountSyncRepository::class);
        Assert::assertInstanceOf(SocialAccountSyncRepository::class, $socialAccounts);
        $store = new NotificationStore(
            $entityManager->getRepository(SocialNotification::class),
            $entityManager,
            $naiveClock,
        );
        $doctrine = $registry ?? $container->get('doctrine');
        Assert::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return new NotificationPollSweep(
            $polls,
            $socialAccounts,
            self::poll($entityManager, $clock, $hostThrottle, $sources, $contextSource),
            $store,
            $entityManager,
            $doctrine,
            $naiveClock,
            $logger,
        );
    }

    /** @param array<string, NotificationSourceInterface> $sources keyed by SocialNetwork value */
    public static function poll(
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        HostThrottle $hostThrottle,
        array $sources,
        NotificationContextSourceInterface $contextSource = new RecordingContextSource(),
    ): NotificationPoll {
        $naiveClock = new NaiveUtcClock($clock);
        $polls = $entityManager->getRepository(SocialNotificationPoll::class);
        Assert::assertInstanceOf(SocialNotificationPollRepository::class, $polls);
        $store = new NotificationStore(
            $entityManager->getRepository(SocialNotification::class),
            $entityManager,
            $naiveClock,
        );

        return new NotificationPoll(
            new ServiceLocator(array_map(
                static fn (NotificationSourceInterface $source): \Closure => static fn () => $source,
                $sources,
            )),
            new ActingAccountGuard($entityManager),
            $store,
            new NotificationContextLookup(self::contextSourcesOf($contextSource), $store),
            $hostThrottle,
            $polls,
            $entityManager,
            $naiveClock,
        );
    }

    public static function throttle(ClockInterface $clock): HostThrottle
    {
        return new HostThrottle(new ArrayAdapter(clock: $clock), $clock);
    }

    /**
     * One source for every network, so a test seeding any account is polled by it.
     *
     * @return array<string, NotificationSourceInterface>
     */
    public static function allNetworks(NotificationSourceInterface $source): array
    {
        $sources = [];
        foreach (SocialNetwork::cases() as $network) {
            $sources[$network->value] = $source;
        }

        return $sources;
    }

    private static function contextSourcesOf(NotificationContextSourceInterface $contextSource): ContainerInterface
    {
        $contextSources = [];
        foreach (SocialNetwork::cases() as $network) {
            $contextSources[$network->value] = static fn (): NotificationContextSourceInterface => $contextSource;
        }

        return new ServiceLocator($contextSources);
    }

    private function __construct()
    {
    }
}
