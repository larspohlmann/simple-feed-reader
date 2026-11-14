<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\PasskeyCount\PasskeyCountInterface;
use App\Service\Passkey\PasskeyRemovalPolicy;
use App\Service\Passkey\SignInIdentities\SignInIdentitiesInterface;
use App\Tests\Support\PasskeyRegistrations;
use PHPUnit\Framework\TestCase;

/**
 * The lock-out guard's truth table: only a password-less, identity-less account's last passkey is refused. The
 * lookups are doubled, pinning each row's combination; `existsForUser` must not run once a password hash exists.
 */
final class PasskeyRemovalPolicyTest extends TestCase
{
    public function testAnotherPasskeyRemainsSoRemovalIsAllowed(): void
    {
        $passkeys = $this->createMock(PasskeyCountInterface::class);
        $passkeys->expects($this->once())->method('countForUser')->willReturn(2);
        $identities = $this->createMock(SignInIdentitiesInterface::class);
        $identities->expects($this->never())->method('existsForUser');

        (new PasskeyRemovalPolicy($passkeys, $identities))->guardRemoval($this->user(null), $this->passkey());

        $this->addToAssertionCount(1);
    }

    public function testTheLastPasskeyIsAllowedWhenAPasswordExists(): void
    {
        $passkeys = $this->createMock(PasskeyCountInterface::class);
        $passkeys->expects($this->once())->method('countForUser')->willReturn(1);
        $identities = $this->createMock(SignInIdentitiesInterface::class);
        $identities->expects($this->never())->method('existsForUser');

        (new PasskeyRemovalPolicy($passkeys, $identities))
            ->guardRemoval($this->user('hashed-password'), $this->passkey());

        $this->addToAssertionCount(1);
    }

    public function testTheLastPasskeyIsAllowedWhenAnOAuthIdentityExists(): void
    {
        $passkeys = $this->createMock(PasskeyCountInterface::class);
        $passkeys->expects($this->once())->method('countForUser')->willReturn(1);
        $identities = $this->createMock(SignInIdentitiesInterface::class);
        $identities->expects($this->once())->method('existsForUser')->willReturn(true);

        (new PasskeyRemovalPolicy($passkeys, $identities))->guardRemoval($this->user(null), $this->passkey());

        $this->addToAssertionCount(1);
    }

    /** The one refused row: no password hash and no OAuth identity, so the last passkey is the only way back in. */
    public function testTheLastPasskeyOnAPasswordLessIdentityLessAccountIsRefused(): void
    {
        $passkeys = $this->createMock(PasskeyCountInterface::class);
        $passkeys->expects($this->once())->method('countForUser')->willReturn(1);
        $identities = $this->createMock(SignInIdentitiesInterface::class);
        $identities->expects($this->once())->method('existsForUser')->willReturn(false);

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
            PasskeyRegistrations::any(label: 'Test key'),
        );
    }
}
