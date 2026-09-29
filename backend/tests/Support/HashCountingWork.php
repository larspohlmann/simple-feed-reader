<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\PasswordWorkEqualizerInterface;

/**
 * Counts equalising hashes instead of spending them: a stopwatch would flake, and tests hash in plaintext anyway.
 * Shared by LoginTimingEqualizerTest and LoginTest, which swaps it into the container.
 */
final class HashCountingWork implements PasswordWorkEqualizerInterface
{
    public int $calls = 0;

    public function spendOneHash(): void
    {
        ++$this->calls;
    }
}
