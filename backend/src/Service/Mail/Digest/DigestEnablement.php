<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Dto\Me\UpdateDigestRequest;
use App\Entity\Preferences;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applies a digest configuration write, including the one piece of business
 * logic that write carries: first-enable seeding (spec Q5). When the digest
 * transitions off→on for the first time, digestLastSentAt is seeded to "now"
 * so the first digest covers only entries that arrive after opt-in, rather
 * than replaying everything the account has ever accumulated. Kept out of the
 * controller — which has no room for a private method under ThinControllerRule
 * — and out of Preferences itself, which has no business reading a clock.
 */
final readonly class DigestEnablement
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function applyTo(Preferences $preferences, UpdateDigestRequest $request): void
    {
        $wasEnabled = $preferences->isDigestEnabled();

        $preferences->setDigestEnabled($request->enabled);
        $preferences->setDigestCadence($request->cadence);
        $preferences->setDigestSendHour($request->sendHour);
        $preferences->setDigestWeekday($request->weekday);
        $preferences->setDigestFormat($request->format);

        if ($this->isFirstEnable($wasEnabled, $request->enabled, $preferences)) {
            $preferences->setDigestLastSentAt($this->nowAsNaiveUtc());
        }
    }

    private function isFirstEnable(bool $wasEnabled, bool $isNowEnabled, Preferences $preferences): bool
    {
        return false === $wasEnabled
            && true === $isNowEnabled
            && null === $preferences->getDigestLastSentAt();
    }

    /** Doctrine persists naive wall-clock values, so a non-UTC clock must be normalised first. */
    private function nowAsNaiveUtc(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
