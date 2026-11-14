<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\DigestSettings;
use App\Enum\DigestCadence;
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
    public function mostRecentDue(DigestSettings $settings, \DateTimeImmutable $nowUtc): ?\DateTimeImmutable
    {
        if (!$settings->isEnabled()) {
            return null;
        }

        $localNow = $nowUtc->setTimezone(new \DateTimeZone($this->timezone));

        $occurrence = match ($settings->getCadence()) {
            DigestCadence::Daily => $this->dailyOccurrence($localNow, $settings->getSendHour()),
            DigestCadence::Weekly => $this->weeklyOccurrence(
                $localNow,
                $settings->getSendHour(),
                $settings->getWeekday(),
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

        // Step back to the most recent matching weekday: (today - target) mod 7.
        $daysBack = ((int) $candidate->format('N') - $weekday + 7) % 7;
        $occurrence = $candidate->modify(\sprintf('-%d days', $daysBack));

        // A same-weekday occurrence whose hour has not passed belongs to last week.
        return $occurrence <= $localNow ? $occurrence : $occurrence->modify('-7 days');
    }

    /** The same instant expressed as naive UTC, for comparison against lastSentAt. */
    private function toNaiveUtc(\DateTimeImmutable $local): \DateTimeImmutable
    {
        return $local->setTimezone(new \DateTimeZone('UTC'));
    }
}
