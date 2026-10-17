<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Repository\SocialFeedRepository;
use App\Service\Social\PrivateFeeds;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Test access to social feeds on a real entity manager. */
final class SocialFeeds
{
    public static function repository(EntityManagerInterface $entityManager): SocialFeedRepository
    {
        /** @var SocialFeedRepository $repository */
        $repository = $entityManager->getRepository(SocialFeed::class);

        return $repository;
    }

    public static function persisted(EntityManagerInterface $entityManager, SocialFeed $socialFeed): SocialFeed
    {
        $entityManager->persist($socialFeed);
        $entityManager->flush();

        return $socialFeed;
    }

    public static function persistedAccount(
        EntityManagerInterface $entityManager,
        SocialAccount $account,
    ): SocialAccount {
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }

    public static function privateFeeds(ContainerInterface $container): PrivateFeeds
    {
        $privateFeeds = $container->get(PrivateFeeds::class);
        Assert::assertInstanceOf(PrivateFeeds::class, $privateFeeds);

        return $privateFeeds;
    }
}
