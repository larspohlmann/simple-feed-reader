<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Entity\User;
use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Mail\Digest\DigestEnablement;
use App\Service\Mail\Digest\Model\DigestConfigurationModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DigestEnablementTest extends TestCase
{
    private function preferences(): Preferences
    {
        return (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();
    }

    public function testItAppliesAllFourSettings(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $preferences = $this->preferences();

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Weekly,
            sendHour: 9,
            weekday: 3,
            format: DigestFormat::Html,
        ));

        self::assertTrue($preferences->isDigestEnabled());
        self::assertSame(DigestCadence::Weekly, $preferences->getDigestCadence());
        self::assertSame(9, $preferences->getDigestSendHour());
        self::assertSame(3, $preferences->getDigestWeekday());
    }

    public function testFirstOffToOnTransitionSeedsDigestLastSentAtToNow(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T12:00:00Z');
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock($now)));
        $preferences = $this->preferences();
        self::assertFalse($preferences->isDigestEnabled());
        self::assertNull($preferences->getDigestLastSentAt());

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertEquals($now, $preferences->getDigestLastSentAt());
    }

    public function testANonTransitioningEnabledWriteDoesNotMoveDigestLastSentAt(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $preferences = $this->preferences();
        $preferences->setDigestEnabled(true);
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $preferences->setDigestLastSentAt($seededAt);

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Weekly,
            sendHour: 10,
            weekday: 5,
            format: DigestFormat::Html,
        ));

        self::assertEquals($seededAt, $preferences->getDigestLastSentAt());
    }

    public function testAnAlreadyDisabledWriteThatStaysDisabledDoesNotSeed(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $preferences = $this->preferences();
        self::assertFalse($preferences->isDigestEnabled());
        self::assertNull($preferences->getDigestLastSentAt());

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: false,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertFalse($preferences->isDigestEnabled());
        self::assertNull($preferences->getDigestLastSentAt());
    }

    public function testDisablingDoesNotSeedOrClearDigestLastSentAt(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $preferences = $this->preferences();
        $preferences->setDigestEnabled(true);
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $preferences->setDigestLastSentAt($seededAt);

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: false,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertFalse($preferences->isDigestEnabled());
        self::assertEquals($seededAt, $preferences->getDigestLastSentAt());
    }

    public function testReenablingAfterDisableDoesNotReseedBecauseLastSentAtIsAlreadySet(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $preferences = $this->preferences();
        $preferences->setDigestEnabled(false);
        // Simulates an account that already received at least one digest before
        // being turned off: digestLastSentAt is set, even though isDigestEnabled
        // is currently false.
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $preferences->setDigestLastSentAt($seededAt);

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertEquals($seededAt, $preferences->getDigestLastSentAt());
    }

    public function testANonUtcClockIsNormalisedToNaiveUtcBeforeSeeding(): void
    {
        $clock = new MockClock('2026-08-28T12:00:00+02:00');
        $enablement = new DigestEnablement(new NaiveUtcClock($clock));
        $preferences = $this->preferences();

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        $seededAt = $preferences->getDigestLastSentAt();
        self::assertNotNull($seededAt);
        self::assertSame('UTC', $seededAt->getTimezone()->getName());
        self::assertEquals(new \DateTimeImmutable('2026-08-28T10:00:00Z'), $seededAt);
    }

    public function testApplyToSetsTheDigestFormat(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $preferences = $this->preferences();

        $enablement->applyTo($preferences, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Text,
        ));

        self::assertSame(DigestFormat::Text, $preferences->getDigestFormat());
    }
}
