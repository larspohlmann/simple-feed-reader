<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\AccountStatusException;
use App\Security\LoginTimingEqualizer;
use App\Service\Auth\UserByEmail\UserByEmailInterface;
use App\Tests\Support\HashCountingWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Asserts the decision, not the clock (tests hash in plaintext): each path that skipped the hasher spends exactly one
 * hash, and each that already paid spends none.
 */
final class LoginTimingEqualizerTest extends TestCase
{
    public function testHashesOnceWhenTheUserWasNotFound(): void
    {
        $work = new HashCountingWork();

        (new LoginTimingEqualizer($work, $this->users(null)))
            ->equalize(new UserNotFoundException(), 'nobody@example.com');

        self::assertSame(1, $work->calls);
    }

    /**
     * AuthenticatorManager masks the not-found case behind BadCredentialsException: the equalizer must walk the chain,
     * or it never fires.
     */
    public function testHashesWhenUserNotFoundIsWrappedByBadCredentials(): void
    {
        $work = new HashCountingWork();

        (new LoginTimingEqualizer($work, $this->users(null)))->equalize(
            new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()),
            'nobody@example.com',
        );

        self::assertSame(1, $work->calls);
    }

    /** A null password hash skips the hasher, so without this hash the response would come back an argon2 faster. */
    public function testHashesOnAnOAuthOnlyAccountWithNoPassword(): void
    {
        $work = new HashCountingWork();

        (new LoginTimingEqualizer($work, $this->users($this->userWithoutPassword())))
            ->equalize(new BadCredentialsException(), 'oauth@example.com');

        self::assertSame(1, $work->calls);
    }

    public function testDoesNotHashOnAWrongPasswordForAnExistingUser(): void
    {
        // That path already paid for a real verify inside the security layer.
        // A second hash here would make the wrong-password case the SLOWEST of
        // the three and reopen the oracle pointing the other way.
        $work = new HashCountingWork();

        (new LoginTimingEqualizer($work, $this->users($this->userWithPassword())))
            ->equalize(new BadCredentialsException('The presented password is invalid.'), 'bob@example.com');

        self::assertSame(0, $work->calls);
    }

    public function testDoesNotHashOnANonActiveAccount(): void
    {
        // checkPostAuth runs only after the password verified, so the work was
        // already done.
        $work = new HashCountingWork();

        (new LoginTimingEqualizer($work, $this->users($this->userWithPassword())))
            ->equalize(new AccountStatusException('suspended'), 'bob@example.com');

        self::assertSame(0, $work->calls);
    }

    public function testHashesWhenTheRequestNamedNoUserAtAll(): void
    {
        // A malformed request body that never named a user must not be the
        // cheapest way to probe the endpoint.
        $work = new HashCountingWork();

        (new LoginTimingEqualizer($work, $this->users(null)))
            ->equalize(new BadCredentialsException(), null);

        self::assertSame(1, $work->calls);
    }

    /** Hit or miss, each BadCredentials path runs one findOneByEmail(), so only the added hash varies. */
    public function testTheEqualisingLookupRunsOnceOnEveryBadCredentialsPath(): void
    {
        foreach ([null, $this->userWithoutPassword(), $this->userWithPassword()] as $found) {
            $work = new HashCountingWork();
            $users = $this->createMock(UserByEmailInterface::class);
            $users->expects($this->once())->method('findOneByEmail')->willReturn($found);

            (new LoginTimingEqualizer($work, $users))
                ->equalize(new BadCredentialsException(), 'someone@example.com');
        }
    }

    /** A 429 is no credential outcome: hashing it would sell an argon2 of CPU for one cheap request. */
    public function testDoesNotHashOnAThrottledRequest(): void
    {
        $work = new HashCountingWork();
        $users = $this->createMock(UserByEmailInterface::class);
        $users->expects($this->never())->method('findOneByEmail');

        (new LoginTimingEqualizer($work, $users))->equalize(
            new TooManyLoginAttemptsAuthenticationException(),
            'bob@example.com',
        );

        self::assertSame(0, $work->calls);
    }

    private function userWithPassword(): User
    {
        $user = new User('bob@example.com', new \DateTimeImmutable());
        $user->setPasswordHash('a-hash', new \DateTimeImmutable());

        return $user;
    }

    private function userWithoutPassword(): User
    {
        return new User('oauth@example.com', new \DateTimeImmutable());
    }

    private function users(?User $user): UserByEmailInterface
    {
        $repository = $this->createStub(UserByEmailInterface::class);
        $repository->method('findOneByEmail')->willReturn($user);

        return $repository;
    }
}
