<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Repository\SocialFeedRepository;
use Doctrine\ORM\EntityManagerInterface;

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
}
