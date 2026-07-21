<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
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
 * work as the verify it stands in for. This is deliberately NOT an attempt at
 * constant time — unreachable in PHP, and not the bar. The bar is removing the
 * bcrypt-shaped step that makes enumeration trivially scriptable.
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
    /**
     * Never a real credential — only the hasher's workload matters, and for
     * bcrypt/argon2 that is set by the cost parameters, not by the input.
     */
    private const DUMMY_PASSWORD = 'timing-equalisation-placeholder';

    public function __construct(
        private PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function equalize(AuthenticationException $exception): void
    {
        if (!$this->isUserNotFound($exception)) {
            return;
        }

        // hash(), not verify(): one bcrypt/argon2 computation either way, and
        // it stays correct if the configured algorithm or cost ever changes —
        // a hard-coded dummy hash would silently drift out of calibration.
        $this->hasherFactory->getPasswordHasher(User::class)->hash(self::DUMMY_PASSWORD);
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
