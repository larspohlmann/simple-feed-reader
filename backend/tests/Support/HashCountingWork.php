<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\PasswordWorkEqualizerInterface;

/**
 * Counts equalising hashes instead of performing them.
 *
 * The property this stands in for is "the same amount of work on every failing
 * path", and the honest way to check that would be a stopwatch — which is
 * exactly the assertion that would be flaky in CI, and doubly useless here
 * because the test environment hashes in plaintext. Counting calls is the
 * decision behind the timing, asserted deterministically.
 *
 * Lives in Support rather than as an anonymous class because two suites need
 * it: LoginTimingEqualizerTest drives the class directly, and LoginTest swaps
 * it into the container to prove the wired-up request path spends the same
 * work — the unit test alone would stay green if the failure handler never
 * managed to recover the submitted address.
 */
final class HashCountingWork implements PasswordWorkEqualizerInterface
{
    public int $calls = 0;

    public function spendOneHash(): void
    {
        ++$this->calls;
    }
}
