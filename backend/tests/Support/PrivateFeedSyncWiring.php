<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\KnownSocialCollection;
use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Repository\KnownSocialCollectionRepository;
use App\Repository\PrivateFeedSyncChangeRepository;
use App\Repository\SocialAccountRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\PrivateFeedSync;
use App\Service\Social\PrivateFeedSyncApplier;
use App\Service\Social\PrivateFeedSyncSkips;
use App\Service\Social\PrivateFeedSyncSweep;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** PrivateFeedSync over the test's entity manager, clock and network double; the container's SubscriptionService. */
final class PrivateFeedSyncWiring
{
    public static function sync(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        SocialCandidatesInterface $network,
        LoggerInterface $logger = new NullLogger(),
        ?ManagerRegistry $registry = null,
    ): PrivateFeedSync {
        $naiveClock = new NaiveUtcClock($clock);

        return new PrivateFeedSync(
            $network,
            new ActingAccountGuard($entityManager),
            self::applier($container, $entityManager, $clock),
            new PrivateFeedSyncSkips($naiveClock, $entityManager, $registry ?? self::registry($container), $logger),
        );
    }

    public static function sweep(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        SocialCandidatesInterface $network,
        LoggerInterface $logger = new NullLogger(),
        ?ManagerRegistry $registry = null,
    ): PrivateFeedSyncSweep {
        $registry ??= self::registry($container);
        /** @var SocialAccountRepository $socialAccounts */
        $socialAccounts = $entityManager->getRepository(SocialAccount::class);

        return new PrivateFeedSyncSweep(
            $socialAccounts,
            self::sync($container, $entityManager, $clock, $network, $logger, $registry),
            $entityManager,
            $registry,
            new NaiveUtcClock($clock),
            $logger,
        );
    }

    private static function applier(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): PrivateFeedSyncApplier {
        /** @var KnownSocialCollectionRepository $known */
        $known = $entityManager->getRepository(KnownSocialCollection::class);
        /** @var PrivateFeedSyncChangeRepository $changes */
        $changes = $entityManager->getRepository(PrivateFeedSyncChange::class);
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $entityManager->getRepository(Subscription::class);
        $subscriptionService = $container->get(SubscriptionService::class);
        Assert::assertInstanceOf(SubscriptionService::class, $subscriptionService);

        return new PrivateFeedSyncApplier(
            SocialFeeds::repository($entityManager),
            $known,
            $changes,
            PrivateFeedsWiring::build($container, $entityManager, $clock),
            $subscriptionService,
            $subscriptions,
            new NaiveUtcClock($clock),
            $entityManager,
        );
    }

    private static function registry(ContainerInterface $container): ManagerRegistry
    {
        $registry = $container->get('doctrine');
        Assert::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function __construct()
    {
    }
}
