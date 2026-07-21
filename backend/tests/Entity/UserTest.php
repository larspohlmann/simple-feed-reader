<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class UserTest extends DbTestCase
{
    public function testPersistAndReload(): void
    {
        $user = new User('lars@example.com', new \DateTimeImmutable('2026-07-21 10:00:00'));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(User::class)->findOneBy(['email' => 'lars@example.com']);

        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::PendingVerification, $reloaded->getStatus());
        self::assertNull($reloaded->getPasswordHash());
        self::assertNull($reloaded->getApprovedAt());
        self::assertSame(['ROLE_USER'], $reloaded->getRoles());
    }

    public function testEmailIsUnique(): void
    {
        $now = new \DateTimeImmutable();
        $this->em->persist(new User('dup@example.com', $now));
        $this->em->flush();

        $this->em->persist(new User('dup@example.com', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
