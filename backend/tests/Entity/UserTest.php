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
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'lars@example.com']);

        self::assertNotNull($reloaded);
        self::assertSame(UserStatus::PendingVerification, $reloaded->getStatus());
        self::assertNull($reloaded->getPasswordHash());
        self::assertNull($reloaded->getApprovedAt());
        self::assertSame(['ROLE_USER'], $reloaded->getRoles());
    }

    /** Construction trims and lower-cases the address; every lookup relies on it (User::normalizeEmail()). */
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
        $this->entityManager->persist(new User('casefold@example.com', $now));
        $this->entityManager->flush();

        $this->entityManager->persist(new User('CaseFold@Example.com', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testEmailIsUnique(): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->persist(new User('dup@example.com', $now));
        $this->entityManager->flush();

        $this->entityManager->persist(new User('dup@example.com', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testANewAccountHasNeverLoggedIn(): void
    {
        $user = new User('nobody@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));

        // null is the "never" the admin UI renders — not epoch, not createdAt.
        self::assertNull($user->getLastLoginAt());
    }

    public function testTheLastLoginStampIsRecorded(): void
    {
        $user = new User('nobody@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $stamp = new \DateTimeImmutable('2026-07-30 08:15:00');

        $user->setLastLoginAt($stamp);

        self::assertEquals($stamp, $user->getLastLoginAt());
    }

    private function trialUser(): User
    {
        return new User('reader@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
    }

    public function testTrialEndsAtDefaultsToNull(): void
    {
        self::assertNull($this->trialUser()->getTrialEndsAt());
    }

    public function testTrialEndsAtRoundTrips(): void
    {
        $user = $this->trialUser();
        $ends = new \DateTimeImmutable('2026-08-01 10:00:00');
        $user->setTrialEndsAt($ends);
        self::assertSame($ends, $user->getTrialEndsAt());
        $user->setTrialEndsAt(null);
        self::assertNull($user->getTrialEndsAt());
    }

    public function testMaxSubscriptionsDefaultsToNull(): void
    {
        self::assertNull($this->trialUser()->getMaxSubscriptions());
    }

    public function testMaxSubscriptionsRoundTrips(): void
    {
        $user = $this->trialUser();
        $user->setMaxSubscriptions(25);
        self::assertSame(25, $user->getMaxSubscriptions());
        $user->setMaxSubscriptions(null);
        self::assertNull($user->getMaxSubscriptions());
    }

    public function testEmailVerifiedAtStartsNullAndIsStampedOnce(): void
    {
        $user = new User('a@b.example', new \DateTimeImmutable());
        self::assertNull($user->getEmailVerifiedAt());
        self::assertFalse($user->isEmailVerified());

        $first = new \DateTimeImmutable('2026-08-28 10:00:00');
        $user->markEmailVerified($first);
        self::assertSame($first, $user->getEmailVerifiedAt());
        self::assertTrue($user->isEmailVerified());

        // Idempotent: a later verification does not move the original instant.
        $user->markEmailVerified(new \DateTimeImmutable('2026-09-01 10:00:00'));
        self::assertSame($first, $user->getEmailVerifiedAt());
    }
}
