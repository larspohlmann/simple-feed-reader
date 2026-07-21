<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\AccountStatusException;
use App\Security\LoginTimingEqualizer;
use App\Security\PasswordWorkEqualizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Asserts the decision, not the clock. Timing assertions are flaky by nature,
 * and the test environment hashes in plaintext anyway — what has to hold is
 * that the not-found path performs exactly one hash and every other path
 * performs none.
 */
final class LoginTimingEqualizerTest extends TestCase
{
    private function equalizer(PasswordHasherFactoryInterface $factory): LoginTimingEqualizer
    {
        return new LoginTimingEqualizer(new PasswordWorkEqualizer($factory));
    }

    private function factoryExpectingHashes(int $times): PasswordHasherFactoryInterface
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::exactly($times))
            ->method('hash')
            ->willReturn('$2y$dummy');

        $factory = $this->createMock(PasswordHasherFactoryInterface::class);
        $factory->expects(self::exactly($times))
            ->method('getPasswordHasher')
            ->with(User::class)
            ->willReturn($hasher);

        return $factory;
    }

    public function testHashesOnceWhenTheUserWasNotFound(): void
    {
        $this->equalizer($this->factoryExpectingHashes(1))
            ->equalize(new UserNotFoundException());
    }

    /**
     * The realistic shape: AuthenticatorManager masks the not-found case behind
     * a BadCredentialsException, so the equalizer has to walk the chain. If it
     * only checked the outermost exception it would silently never fire, and
     * the timing channel would be wide open while looking closed.
     */
    public function testHashesWhenUserNotFoundIsWrappedByBadCredentials(): void
    {
        $this->equalizer($this->factoryExpectingHashes(1))
            ->equalize(new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()));
    }

    public function testDoesNotHashOnAWrongPasswordForAnExistingUser(): void
    {
        $this->equalizer($this->factoryExpectingHashes(0))
            ->equalize(new BadCredentialsException('The presented password is invalid.'));
    }

    public function testDoesNotHashOnANonActiveAccount(): void
    {
        $this->equalizer($this->factoryExpectingHashes(0))
            ->equalize(new AccountStatusException('suspended'));
    }
}
