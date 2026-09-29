<?php

declare(strict_types=1);

namespace App\Security;

/**
 * One password hash's worth of CPU, so callers are tested by counting hashes. Keep it one method wide: a result a
 * caller could branch on would make the equalisation conditional.
 */
interface PasswordWorkEqualizerInterface
{
    public function spendOneHash(): void;
}
