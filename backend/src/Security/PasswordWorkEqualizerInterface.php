<?php

declare(strict_types=1);

namespace App\Security;

/**
 * One password hash's worth of CPU, spent on demand.
 *
 * Extracted from PasswordWorkEqualizer so callers can be tested by counting
 * hashes rather than timing them — the concrete class is deliberately `final
 * readonly` (it holds only a hasher factory), so a test double can't subclass
 * it, and a timing assertion would be flaky in exactly the way this mechanism
 * exists to make attacks flaky.
 *
 * Deliberately one method wide: anything a caller could ask beyond "spend the
 * work" — how long it took, whether it ran — would let the equalisation
 * become conditional, which is the one thing it must never be.
 */
interface PasswordWorkEqualizerInterface
{
    public function spendOneHash(): void;
}
