<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Service\Clock\NaiveUtcClock;

/**
 * Records that an account answered the one-time passkey offer. Idempotent: a retried or doubled answer must not move
 * a timestamp already set.
 */
final readonly class PasskeyOffer
{
    public function __construct(
        private NaiveUtcClock $clock,
    ) {
    }

    public function markAnswered(User $user): void
    {
        $preferences = $user->getPreferences();

        if (null !== $preferences->getPasskeyOfferAnsweredAt()) {
            return;
        }

        $preferences->markPasskeyOfferAnswered($this->clock->now());
    }
}
