<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Spends one password hash's worth of CPU on a code path that didn't need to
 * hash anything, so that path stops being distinguishable from the one that
 * did.
 *
 * Extracted from LoginTimingEqualizer, now one of two callers: login needs it
 * because an unknown address fails on a bare SELECT miss, and registration
 * needs it because a duplicate address returns before hashing the password a
 * fresh signup would hash. Same shape of leak, same remedy.
 *
 * Measured locally, `algorithm: auto` resolves to argon2id at ~174ms per hash
 * (bcrypt, the fallback without libsodium, ~58ms) — a gap large enough to be a
 * reliable oracle over the open internet, surviving byte-for-byte-equal
 * responses.
 *
 * Deliberately NOT constant time — unreachable in PHP, and not the bar. The
 * bar is removing the argon2-shaped cliff that turns enumeration into timing
 * one request instead of many.
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
