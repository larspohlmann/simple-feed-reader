<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ActionToken;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Enum\TokenPurpose;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class IdentityTokenTest extends DbTestCase
{
    public function testProviderIdentityIsGloballyUnique(): void
    {
        $now = new \DateTimeImmutable();
        $userA = new User('a@example.com', $now);
        $userB = new User('b@example.com', $now);
        $this->em->persist($userA);
        $this->em->persist($userB);

        $this->em->persist(new UserIdentity($userA, 'google', 'google-uid-1', $now));
        $this->em->flush();

        $this->em->persist(new UserIdentity($userB, 'google', 'google-uid-1', $now));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSameProviderIdOnDifferentProvidersIsAllowed(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('c@example.com', $now);
        $this->em->persist($user);

        $this->em->persist(new UserIdentity($user, 'google', 'uid-x', $now));
        $this->em->persist(new UserIdentity($user, 'apple', 'uid-x', $now));
        $this->em->flush();

        $this->addToAssertionCount(1);
    }

    public function testActionTokenLifecycle(): void
    {
        $now = new \DateTimeImmutable('2026-07-21 12:00:00');
        $user = new User('d@example.com', $now);
        $this->em->persist($user);

        $token = new ActionToken(
            user: $user,
            purpose: TokenPurpose::VerifyEmail,
            tokenHash: hash('sha256', 'raw-token-value'),
            expiresAt: $now->modify('+24 hours'),
            createdAt: $now,
        );
        $this->em->persist($token);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(ActionToken::class)
            ->findOneBy(['tokenHash' => hash('sha256', 'raw-token-value')]);

        self::assertNotNull($reloaded);
        self::assertSame(TokenPurpose::VerifyEmail, $reloaded->getPurpose());
        self::assertNull($reloaded->getConsumedAt());
        self::assertFalse($reloaded->isExpiredAt($now->modify('+23 hours')));
        self::assertTrue($reloaded->isExpiredAt($now->modify('+25 hours')));
    }

    public function testActionTokenConsumption(): void
    {
        $now = new \DateTimeImmutable('2026-07-21 12:00:00');
        $user = new User('e@example.com', $now);
        $this->em->persist($user);

        $token = new ActionToken(
            user: $user,
            purpose: TokenPurpose::ResetPassword,
            tokenHash: hash('sha256', 'another-token'),
            expiresAt: $now->modify('+24 hours'),
            createdAt: $now,
        );
        $this->em->persist($token);
        $this->em->flush();

        self::assertFalse($token->isExpiredAt($now->modify('+24 hours')));

        $token->setConsumedAt($now->modify('+1 hour'));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(ActionToken::class)
            ->findOneBy(['tokenHash' => hash('sha256', 'another-token')]);

        self::assertNotNull($reloaded);
        self::assertEquals($now->modify('+1 hour'), $reloaded->getConsumedAt());
    }
}
