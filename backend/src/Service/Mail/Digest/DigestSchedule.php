<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\Preferences;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single owner of "what local time does this user's send hour mean, and has
 * that occurrence passed?". Interprets the send hour in the instance timezone
 * (APP_TIMEZONE) for v1; a per-user timezone would replace only this class.
 */
final readonly class DigestSchedule
{
    public function __construct(
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $timezone,
    ) {
    }

    /** The most recent scheduled occurrence at/before now, as naive UTC, or null. */
    public function mostRecentDue(Preferences $preferences, \DateTimeImmutable $nowUtc): ?\DateTimeImmutable
    {
        if (!$preferences->isDigestEnabled()) {
            return null;
        }

        $localNow = $nowUtc->setTimezone(new \DateTimeZone($this->timezone));

        $occurrence = match ($preferences->getDigestCadence()) {
            DigestCadence::Daily => $this->dailyOccurrence($localNow, $preferences->getDigestSendHour()),
            DigestCadence::Weekly => $this->weeklyOccurrence(
                $localNow,
                $preferences->getDigestSendHour(),
                $preferences->getDigestWeekday(),
            ),
        };

        return $this->toNaiveUtc($occurrence);
    }

    private function dailyOccurrence(\DateTimeImmutable $localNow, int $hour): \DateTimeImmutable
    {
        $today = $localNow->setTime($hour, 0, 0);

        return $today <= $localNow ? $today : $today->modify('-1 day');
    }

    private function weeklyOccurrence(\DateTimeImmutable $localNow, int $hour, int $weekday): \DateTimeImmutable
    {
        $candidate = $localNow->setTime($hour, 0, 0);

        // Walk back at most 7 days to the most recent matching weekday-at-hour.
        for ($back = 0; $back < 7; ++$back) {
            $day = $candidate->modify(\sprintf('-%d days', $back));
            if ((int) $day->format('N') === $weekday && $day <= $localNow) {
                return $day;
            }
        }

        return $candidate->modify('-7 days');
    }

    /** The same instant expressed as naive UTC, for comparison against digestLastSentAt. */
    private function toNaiveUtc(\DateTimeImmutable $local): \DateTimeImmutable
    {
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
