<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Spends one password hash's worth of CPU on a code path that did not need to
 * hash anything, so that path stops being distinguishable from the one that
 * did.
 *
 * Extracted from LoginTimingEqualizer, which is now one caller of two: login
 * needed it because an unknown address fails on a bare SELECT miss, and
 * registration needs it because a duplicate address returns before hashing the
 * password a fresh signup would hash. Same shape of leak, same remedy.
 *
 * Measured on the development machine, `algorithm: auto` resolves to argon2id
 * at ~174 ms per hash (bcrypt, the fallback where libsodium is absent, ~58 ms).
 * A gap that size is not a subtle side channel; it is a reliable oracle over
 * the open internet, and it survives responses that are byte-for-byte equal.
 *
 * This is deliberately NOT constant time — unreachable in PHP, and not the bar.
 * The bar is removing the argon2-shaped cliff that makes enumeration a matter
 * of timing one request instead of many.
 */
final readonly class PasswordWorkEqualizer implements PasswordWorkEqualizerInterface
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
