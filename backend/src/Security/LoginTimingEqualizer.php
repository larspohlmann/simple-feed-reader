<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Closes the timing side-channels between the three ways a password login can
 * fail on credentials: unknown address, wrong password, and — since Plan 3b — an
 * address whose account has no password at all.
 *
 * Symfony performs no dummy hash: CheckCredentialsListener reaches the hasher
 * only once a user is loaded, so an unknown address fails on a bare SELECT miss
 * while a known one pays for a full argon2 verify — a gap of tens of ms,
 * measurable over the network and enough to enumerate the user table, though the
 * two responses are byte-identical.
 *
 * The third case arrived with OAuth: OAuthAccountLinker creates accounts with a
 * null passwordHash, for which CheckCredentialsListener returns without hashing.
 * So an OAuth-only address was as fast as a nonexistent one and both faster than
 * a wrong password — sorting addresses into "has a password" and "does not",
 * which tells an attacker which accounts are worth a provider-named phishing mail.
 *
 * The throwaway hash now lives in PasswordWorkEqualizer, shared with
 * registration; this class only decides *when* to spend it on login.
 *
 * Invoked from LoginFailureHandler, not a LoginFailureEvent subscriber:
 * SecurityBundle copies globally registered listeners onto EVERY firewall, so a
 * subscriber would also fire on the api firewall and burn a hash on every
 * unauthenticated JWT request. The failure handler is bound to the login
 * firewall alone.
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
        // Unknown address: Symfony failed on a bare SELECT miss. Checked before
        // the BadCredentials gate because it is also the one case that can reach
        // the failure handler unmasked.
        if ($this->isUserNotFound($exception)) {
            return true;
        }

        // Only credential failures are this class's business. A status rejection
        // happens post-verify, so it already paid for its hash; a second would
        // make it the slowest outcome and flip the oracle. Hashing a throttled
        // request would let an attacker buy an argon2 of our CPU with one cheap
        // request.
        if (!$exception instanceof BadCredentialsException) {
            return false;
        }

        if (null === $submittedIdentifier) {
            return true;
        }

        // The remaining case, why this method exists: since Plan 3b a user may
        // have no password hash, and CheckCredentialsListener skips the hasher
        // for those. The extra SELECT runs on BOTH branches — hit and miss — so
        // it is not a side channel itself, and LoginFailureHandler is bound to
        // the already-throttled login firewall, so it cannot be abused for load.
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
