<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Closes the timing side-channel between "unknown email" and "wrong password".
 *
 * Symfony performs NO dummy hash of its own: CheckCredentialsListener only
 * reaches the hasher once a user has been loaded, so an unknown address fails
 * on a bare SELECT miss while a known one pays for a full bcrypt/argon2 verify.
 * In production that gap is tens of milliseconds — comfortably measurable over
 * the network, and enough to enumerate the user table with a script, even
 * though the two responses are byte-for-byte identical.
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
        private PasswordWorkEqualizer $work,
    ) {
    }

    public function equalize(AuthenticationException $exception): void
    {
        if (!$this->isUserNotFound($exception)) {
            return;
        }

        $this->work->spendOneHash();
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
