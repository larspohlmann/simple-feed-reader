<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Closes the timing side-channels between the three ways a password login can
 * fail on credentials: an unknown address, a wrong password, and — since Plan
 * 3b — an address whose account has no password at all.
 *
 * Symfony performs NO dummy hash of its own: CheckCredentialsListener only
 * reaches the hasher once a user has been loaded, so an unknown address fails
 * on a bare SELECT miss while a known one pays for a full bcrypt/argon2 verify.
 * In production that gap is tens of milliseconds — comfortably measurable over
 * the network, and enough to enumerate the user table with a script, even
 * though the two responses are byte-for-byte identical.
 *
 * The third case arrived with OAuth. OAuthAccountLinker creates accounts with a
 * null passwordHash, and CheckCredentialsListener returns immediately for those
 * without touching the hasher — so a password attempt against an OAuth-only
 * address was as fast as one against an address that does not exist, and both
 * were an argon2 faster than a wrong password. That does not just leak which
 * addresses are registered; it sorts them into "has a password" and "does not",
 * which tells an attacker exactly which accounts are worth a phishing mail
 * naming the right provider.
 *
 * Performing one throwaway hash on the not-found path costs the same order of
 * work as the verify it stands in for. That hash now lives in
 * PasswordWorkEqualizer, which registration shares — this class is only the
 * login-specific decision of *when* to spend it.
 *
 * Invoked from LoginFailureHandler rather than a LoginFailureEvent subscriber
 * on purpose: SecurityBundle copies globally registered security listeners onto
 * EVERY firewall dispatcher, so a subscriber would also fire on the api
 * firewall and burn a hash on every unauthenticated JWT request. The failure
 * handler is bound by security.yaml to the login firewall alone, so that
 * mistake is not available here.
 */
final readonly class LoginTimingEqualizer
{
    public function __construct(
        private PasswordWorkEqualizerInterface $work,
        private UserRepository $users,
    ) {
    }

    /**
     * @param string|null $submittedIdentifier the address the request tried to
     *                                         log in as, or null if the body
     *                                         did not carry a usable one
     */
    public function equalize(AuthenticationException $exception, ?string $submittedIdentifier): void
    {
        if (!$this->needsEqualizingWork($exception, $submittedIdentifier)) {
            return;
        }

        $this->work->spendOneHash();
    }

    private function needsEqualizingWork(AuthenticationException $exception, ?string $submittedIdentifier): bool
    {
        // The address does not exist: Symfony failed on a bare SELECT miss.
        // Checked before the BadCredentials gate below, because this is also
        // the one case that can reach the failure handler unmasked, depending
        // on how the authenticator was reached.
        if ($this->isUserNotFound($exception)) {
            return true;
        }

        // Anything that is not a credential failure is not this class's
        // business. A status rejection happens in checkPostAuth, i.e. after the
        // password has already been verified: that request paid for its hash,
        // and adding a second would make it the slowest outcome and hand back
        // an oracle pointing the other way. A throttled request is not a
        // credential outcome at all, and hashing for one would let an attacker
        // buy an argon2 of our CPU with a single cheap HTTP request.
        if (!$exception instanceof BadCredentialsException) {
            return false;
        }

        if (null === $submittedIdentifier) {
            return true;
        }

        // The remaining case, and the reason this method exists at all. Since
        // Plan 3b a user may have no password hash, and CheckCredentialsListener
        // bails out immediately for those without touching the hasher.
        //
        // One extra indexed SELECT on an already-failing request is a fair
        // price, and it is spent on BOTH remaining branches — hit and miss
        // alike — so the lookup cannot become a side channel of its own. It
        // cannot be abused for load either: LoginFailureHandler is bound to the
        // login firewall alone, which is already throttled, so this never runs
        // on the api firewall's unauthenticated traffic.
        $user = $this->users->findOneByEmail($submittedIdentifier);

        return null === $user || null === $user->getPassword();
    }

    /**
     * AuthenticatorManager masks UserNotFoundException behind a
     * BadCredentialsException (that masking is what keeps the two responses
     * identical), so the original survives only as the previous exception.
     */
    private function isUserNotFound(?\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof UserNotFoundException) {
                return true;
            }
        }

        return false;
    }
}
