<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class EntryShareFixtures
{
    public static function share(
        EntityManagerInterface $entityManager,
        User $user,
        Entry $entry,
        \DateTimeImmutable $sharedAt,
    ): EntryShare {
        $share = new EntryShare($user, $entry, $sharedAt);
        $entityManager->persist($share);

        return $share;
    }

    private function __construct()
    {
    }
}
