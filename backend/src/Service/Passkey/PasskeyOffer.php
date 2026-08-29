<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use Psr\Clock\ClockInterface;

/**
 * Records that the one-time passkey enrolment offer (#624) has been shown to
 * and answered by an account, so the client never presents it a second time.
 *
 * Idempotent by design: the offer is a single yes/no moment. A second call —
 * a retried request, a client that fires the answer twice — must not move an
 * already-set timestamp, or a race between two answers could make the
 * "since" marker drift forward on every retry.
 */
final readonly class PasskeyOffer
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function markAnswered(User $user): void
    {
        $preferences = $user->getPreferences();

        if (null !== $preferences->getPasskeyOfferAnsweredAt()) {
            return;
        }

        $preferences->markPasskeyOfferAnswered($this->nowAsNaiveUtc());
    }

    /** Doctrine persists naive wall-clock values, so a non-UTC clock must be normalised first. */
    private function nowAsNaiveUtc(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
