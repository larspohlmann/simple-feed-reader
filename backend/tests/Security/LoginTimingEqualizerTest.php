<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountStatusException;
use App\Security\LoginTimingEqualizer;
use App\Tests\Support\HashCountingWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Asserts the decision, not the clock. Timing assertions are flaky by nature,
 * and the test environment hashes in plaintext anyway — what has to hold is
 * that every path which skipped the hasher performs exactly one hash, and every
 * path which already paid for one performs none.
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
     * The realistic shape: AuthenticatorManager masks the not-found case behind
     * a BadCredentialsException, so the equalizer has to walk the chain. If it
     * only checked the outermost exception it would silently never fire, and
     * the timing channel would be wide open while looking closed.
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

    /**
     * The gap this case closes. CheckCredentialsListener never reaches the
     * hasher for a null passwordHash, so without an extra hash here the
     * response comes back an argon2 faster and tells anyone holding a stopwatch
     * both that the address is registered and that it is OAuth-only.
     */
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

    /**
     * The equalising lookup must not become a side channel of its own. Both
     * BadCredentials paths that reach it run exactly one findOneByEmail(),
     * whether it hits or misses, so the only thing that varies between them is
     * the hash this class is here to add.
     */
    public function testTheEqualisingLookupRunsOnceOnEveryBadCredentialsPath(): void
    {
        foreach ([null, $this->userWithoutPassword(), $this->userWithPassword()] as $found) {
            $work = new HashCountingWork();
            $users = $this->createMock(UserRepository::class);
            $users->expects(self::once())->method('findOneByEmail')->willReturn($found);

            (new LoginTimingEqualizer($work, $users))
                ->equalize(new BadCredentialsException(), 'someone@example.com');
        }
    }

    /**
     * Throttling is not a credential outcome. Burning an argon2 on a request
     * the limiter already rejected would turn the login endpoint into a CPU
     * amplifier — the attacker pays for one HTTP request, we pay for a hash —
     * and there is nothing to hide anyway, since a 429 says the same thing to
     * everyone.
     */
    public function testDoesNotHashOnAThrottledRequest(): void
    {
        $work = new HashCountingWork();
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findOneByEmail');

        (new LoginTimingEqualizer($work, $users))->equalize(
            new \Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException(),
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

    private function users(?User $user): UserRepository
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByEmail')->willReturn($user);

        return $repository;
    }
}
