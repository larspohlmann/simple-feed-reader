<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Auth\UserByEmail\UserByEmailInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Spends one hash on each credential failure the security layer answered without hashing (unknown address, no
 * password hash), so every failure costs the same. Called from LoginFailureHandler, never a global listener, which
 * would fire on every firewall. Why: docs/security.md#login-timing
 */
final readonly class LoginTimingEqualizer
{
    public function __construct(
        private PasswordWorkEqualizerInterface $work,
        private UserByEmailInterface $users,
    ) {
    }

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

        // Only credential failures: a status rejection already paid for its hash (a second would flip the oracle),
        // and hashing a throttled request would sell an argon2 of CPU for one cheap request.
        if (!$exception instanceof BadCredentialsException) {
            return false;
        }

        if (null === $submittedIdentifier) {
            return true;
        }

        // An account without a password hash skips the hasher too. The lookup runs on hit and miss alike, so it is
        // no side channel of its own.
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
