<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\KnownSocialCollection;
use App\Repository\KnownSocialCollectionRepository;
use App\Service\Clock\NaiveUtcClock;
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
        /** @var KnownSocialCollectionRepository $knownCollections */
        $knownCollections = $entityManager->getRepository(KnownSocialCollection::class);

        return new PrivateFeeds(
            $socialFeeds,
            $knownCollections,
            $creator,
            $subscriptionService,
            $entityManager,
            new NaiveUtcClock($clock),
        );
    }

    private function __construct()
    {
    }
}
