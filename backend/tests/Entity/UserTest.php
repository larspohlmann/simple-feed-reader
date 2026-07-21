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

    /**
     * Case and surrounding whitespace are normalised at construction, which is
     * the single seam every other lookup relies on. Without it the unique index
     * disagrees with itself across engines: SQLite compares case-sensitively
     * and MySQL's utf8mb4 _ci collation does not, so `Bob@` and `bob@` are two
     * accounts in dev and one collision in production.
     */
    public function testEmailIsNormalisedOnConstruction(): void
    {
        $user = new User('  Bob.Smith@Example.COM  ', new \DateTimeImmutable('2026-07-21 10:00:00'));

        self::assertSame('bob.smith@example.com', $user->getEmail());
        self::assertSame('bob.smith@example.com', $user->getUserIdentifier());
    }

    public function testAnEmailOfOnlyWhitespaceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User('   ', new \DateTimeImmutable('2026-07-21 10:00:00'));
    }

    public function testCaseVariantsCollideOnTheUniqueIndex(): void
    {
        $now = new \DateTimeImmutable();
        $this->em->persist(new User('casefold@example.com', $now));
        $this->em->flush();

        $this->em->persist(new User('CaseFold@Example.com', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
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
