<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestSchedule;
use PHPUnit\Framework\TestCase;

final class DigestScheduleTest extends TestCase
{
    private function prefs(DigestCadence $cadence, int $hour, int $weekday): Preferences
    {
        $prefs = (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();
        $prefs->setDigestEnabled(true);
        $prefs->setDigestCadence($cadence);
        $prefs->setDigestSendHour($hour);
        $prefs->setDigestWeekday($weekday);
        return $prefs;
    }

    public function testDailyBeforeSendHourHasNoOccurrenceToday(): void
    {
        $schedule = new DigestSchedule('UTC');
        // 07:00 UTC, send hour 8 → most recent occurrence is yesterday 08:00.
        $due = $schedule->mostRecentDue(
            $this->prefs(DigestCadence::Daily, 8, 1),
            new \DateTimeImmutable('2026-08-28 07:00:00'),
        );
        self::assertEquals(new \DateTimeImmutable('2026-08-27 08:00:00'), $due);
    }

    public function testDailyAtOrAfterSendHourIsToday(): void
    {
        $schedule = new DigestSchedule('UTC');
        $due = $schedule->mostRecentDue(
            $this->prefs(DigestCadence::Daily, 8, 1),
            new \DateTimeImmutable('2026-08-28 09:30:00'),
        );
        self::assertEquals(new \DateTimeImmutable('2026-08-28 08:00:00'), $due);
    }

    public function testInstanceTimezoneShiftsTheUtcInstant(): void
    {
        $schedule = new DigestSchedule('Europe/Berlin'); // +02:00 in August
        // Send hour 8 Berlin = 06:00 UTC. At 06:30 UTC that occurrence has passed.
        $due = $schedule->mostRecentDue(
            $this->prefs(DigestCadence::Daily, 8, 1),
            new \DateTimeImmutable('2026-08-28 06:30:00'),
        );
        self::assertEquals(new \DateTimeImmutable('2026-08-28 06:00:00'), $due);
    }

    public function testWeeklyReturnsMostRecentMatchingWeekday(): void
    {
        $schedule = new DigestSchedule('UTC');
        // Weekday 1 = Monday. 2026-08-28 is a Friday; most recent Monday 08:00 is 2026-08-24.
        $due = $schedule->mostRecentDue(
            $this->prefs(DigestCadence::Weekly, 8, 1),
            new \DateTimeImmutable('2026-08-28 09:00:00'),
        );
        self::assertEquals(new \DateTimeImmutable('2026-08-24 08:00:00'), $due);
    }

    public function testDisabledReturnsNull(): void
    {
        $schedule = new DigestSchedule('UTC');
        $prefs = $this->prefs(DigestCadence::Daily, 8, 1);
        $prefs->setDigestEnabled(false);
        self::assertNull($schedule->mostRecentDue($prefs, new \DateTimeImmutable('2026-08-28 09:00:00')));
    }
}
