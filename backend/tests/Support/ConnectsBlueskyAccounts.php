<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

trait ConnectsBlueskyAccounts
{
    private function blueskyAccountOf(User $user): SocialAccount
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return SocialFeeds::persistedAccount($entityManager, SocialAccounts::blueskyOf($user));
    }
}
