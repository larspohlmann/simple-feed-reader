<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

trait SeedsDigestReaders
{
    private function verifiedUser(): User
    {
        $user = $this->unverifiedUser();
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

        return $user;
    }

    private function unverifiedUser(): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $email = \sprintf('digest-%s@example.com', uniqid('', true));
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
