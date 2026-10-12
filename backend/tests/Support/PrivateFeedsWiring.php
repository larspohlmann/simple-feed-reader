<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\Social\PrivateFeeds;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/** PrivateFeeds over the container's subscription services, with the test's own clock. */
final class PrivateFeedsWiring
{
    public static function build(
        ContainerInterface $container,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): PrivateFeeds {
        $creator = $container->get(SubscriptionCreator::class);
        Assert::assertInstanceOf(SubscriptionCreator::class, $creator);
        $subscriptionService = $container->get(SubscriptionService::class);
        Assert::assertInstanceOf(SubscriptionService::class, $subscriptionService);
        $socialFeeds = SocialFeeds::repository($entityManager);
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $entityManager->getRepository(Subscription::class);

        return new PrivateFeeds($socialFeeds, $subscriptions, $creator, $subscriptionService, $entityManager, $clock);
    }

    private function __construct()
    {
    }
}
