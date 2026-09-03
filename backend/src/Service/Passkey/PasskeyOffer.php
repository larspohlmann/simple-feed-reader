<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;

/**
 * Records that the one-time passkey enrolment offer (#624) has been shown to
 * and answered by an account, so the client never presents it a second time.
 *
 * Idempotent by design: the offer is a single yes/no moment. A second call —
 * a retried request, a client firing the answer twice — must not move an
 * already-set timestamp, or a race between two answers could drift the
 * "since" marker forward on every retry.
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
