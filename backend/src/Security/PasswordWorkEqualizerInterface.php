<?php

declare(strict_types=1);

namespace App\Security;

/**
 * One password hash's worth of CPU, spent on demand.
 *
 * Extracted from PasswordWorkEqualizer so its callers can be tested by counting
 * hashes rather than by timing them — the concrete class is `final readonly`
 * (deliberately: it holds a hasher factory and nothing else), so a test double
 * cannot subclass it, and a timing assertion would be flaky in exactly the way
 * this whole mechanism is meant to make attacks flaky.
 *
 * Deliberately one method wide. Anything a caller could ask beyond "spend the
 * work" — how long it took, whether it ran — would be a way to make the
 * equalisation conditional, which is the one thing it must never be.
 */
interface PasswordWorkEqualizerInterface
{
    public function spendOneHash(): void;
}
