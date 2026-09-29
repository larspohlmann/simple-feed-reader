<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Spends one password hash's worth of CPU on a path that did not hash, so timing cannot tell it from one that did.
 * Not constant time: it removes the argon2-sized gap. Callers and measurements: docs/security.md#login-timing
 */
final readonly class PasswordWorkEqualizer implements PasswordWorkEqualizerInterface
{
    /**
     * Never a real credential — only the hasher's workload matters, and for
     * bcrypt/argon2 that is set by the cost parameters, not by the input.
     */
    private const string DUMMY_PASSWORD = 'timing-equalisation-placeholder';

    public function __construct(
        private PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    /**
     * hash(), not verify(): one bcrypt/argon2 computation either way, and it
     * stays correct if the configured algorithm or cost ever changes — a
     * hard-coded dummy hash would silently drift out of calibration.
     */
    public function spendOneHash(): void
    {
        $this->hasherFactory->getPasswordHasher(User::class)->hash(self::DUMMY_PASSWORD);
    }
}
