<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserIdentityRepository;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\PasskeyRemovalPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The lock-out guard's truth table (#624 Task 8). Only the fourth row must
 * throw: a passkey-only, password-less, identity-less account whose last
 * passkey is being removed. Every other combination leaves the account with
 * some way back in, so removal is allowed.
 *
 * Both repositories are mocked rather than backed by a real database — this
 * is a pure decision over three inputs (passkey count, password hash,
 * identity existence), and mocking pins each test to exactly the combination
 * its row names, with no fixture noise from the other two. The expectation
 * on `existsForUser` also pins the short-circuit itself: it must never run
 * once a password hash already proves there is a fallback.
 */
final class PasskeyRemovalPolicyTest extends TestCase
{
    public function testAnotherPasskeyRemainsSoRemovalIsAllowed(): void
    {
        $passkeys = $this->createMock(UserPasskeyRepository::class);
        $passkeys->expects(self::once())->method('countForUser')->willReturn(2);
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects(self::never())->method('existsForUser');

        (new PasskeyRemovalPolicy($passkeys, $identities))->guardRemoval($this->user(null), $this->passkey());

        $this->addToAssertionCount(1);
    }

    public function testTheLastPasskeyIsAllowedWhenAPasswordExists(): void
    {
        $passkeys = $this->createMock(UserPasskeyRepository::class);
        $passkeys->expects(self::once())->method('countForUser')->willReturn(1);
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects(self::never())->method('existsForUser');

        (new PasskeyRemovalPolicy($passkeys, $identities))
            ->guardRemoval($this->user('hashed-password'), $this->passkey());

        $this->addToAssertionCount(1);
    }

    public function testTheLastPasskeyIsAllowedWhenAnOAuthIdentityExists(): void
    {
        $passkeys = $this->createMock(UserPasskeyRepository::class);
        $passkeys->expects(self::once())->method('countForUser')->willReturn(1);
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects(self::once())->method('existsForUser')->willReturn(true);

        (new PasskeyRemovalPolicy($passkeys, $identities))->guardRemoval($this->user(null), $this->passkey());

        $this->addToAssertionCount(1);
    }

    /**
     * The acceptance criterion. An OAuth-only account has a `null` password
     * hash — "has a password" is a different question than "has an
     * account" — and once its one passkey is gone there is no other way
     * back in, so this is the one row that must refuse.
     */
    public function testTheLastPasskeyOnAPasswordLessIdentityLessAccountIsRefused(): void
    {
        $passkeys = $this->createMock(UserPasskeyRepository::class);
        $passkeys->expects(self::once())->method('countForUser')->willReturn(1);
        $identities = $this->createMock(UserIdentityRepository::class);
        $identities->expects(self::once())->method('existsForUser')->willReturn(false);

        $this->expectException(LastSignInMethodException::class);

        (new PasskeyRemovalPolicy($passkeys, $identities))->guardRemoval($this->user(null), $this->passkey());
    }

    private function user(?string $passwordHash): User
    {
        $user = new User('locked-out@example.test', new \DateTimeImmutable());
        $user->setPasswordHash($passwordHash, new \DateTimeImmutable());

        return $user;
    }

    private function passkey(): UserPasskey
    {
        return new UserPasskey(
            $this->user(null),
            'Y3JlZC1hYmM',
            'aGFuZGxl',
            'cHVibGljLWtleQ',
            0,
            null,
            [],
            'Test key',
            new \DateTimeImmutable(),
        );
    }
}
