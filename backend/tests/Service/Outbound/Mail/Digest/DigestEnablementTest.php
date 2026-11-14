<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Mail\Digest;

use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Outbound\Mail\Digest\DigestEnablement;
use App\Service\Outbound\Mail\Digest\Model\DigestConfigurationModel;
use App\Tests\Support\UnsavedUserSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DigestEnablementTest extends TestCase
{
    public function testItAppliesAllFourSettings(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $settings = UnsavedUserSettings::digest();

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Weekly,
            sendHour: 9,
            weekday: 3,
            format: DigestFormat::Html,
        ));

        self::assertTrue($settings->isEnabled());
        self::assertSame(DigestCadence::Weekly, $settings->getCadence());
        self::assertSame(9, $settings->getSendHour());
        self::assertSame(3, $settings->getWeekday());
    }

    public function testFirstOffToOnTransitionSeedsDigestLastSentAtToNow(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T12:00:00Z');
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock($now)));
        $settings = UnsavedUserSettings::digest();
        self::assertFalse($settings->isEnabled());
        self::assertNull($settings->getLastSentAt());

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertEquals($now, $settings->getLastSentAt());
    }

    public function testANonTransitioningEnabledWriteDoesNotMoveDigestLastSentAt(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $settings = UnsavedUserSettings::digest();
        $settings->setEnabled(true);
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $settings->setLastSentAt($seededAt);

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Weekly,
            sendHour: 10,
            weekday: 5,
            format: DigestFormat::Html,
        ));

        self::assertEquals($seededAt, $settings->getLastSentAt());
    }

    public function testAnAlreadyDisabledWriteThatStaysDisabledDoesNotSeed(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $settings = UnsavedUserSettings::digest();
        self::assertFalse($settings->isEnabled());
        self::assertNull($settings->getLastSentAt());

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: false,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertFalse($settings->isEnabled());
        self::assertNull($settings->getLastSentAt());
    }

    public function testDisablingDoesNotSeedOrClearDigestLastSentAt(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $settings = UnsavedUserSettings::digest();
        $settings->setEnabled(true);
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $settings->setLastSentAt($seededAt);

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: false,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertFalse($settings->isEnabled());
        self::assertEquals($seededAt, $settings->getLastSentAt());
    }

    public function testReenablingAfterDisableDoesNotReseedBecauseLastSentAtIsAlreadySet(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $settings = UnsavedUserSettings::digest();
        $settings->setEnabled(false);
        // Simulates an account that already received at least one digest before
        // being turned off: lastSentAt is set, even though isEnabled
        // is currently false.
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $settings->setLastSentAt($seededAt);

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        self::assertEquals($seededAt, $settings->getLastSentAt());
    }

    public function testANonUtcClockIsNormalisedToNaiveUtcBeforeSeeding(): void
    {
        $clock = new MockClock('2026-08-28T12:00:00+02:00');
        $enablement = new DigestEnablement(new NaiveUtcClock($clock));
        $settings = UnsavedUserSettings::digest();

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Html,
        ));

        $seededAt = $settings->getLastSentAt();
        self::assertNotNull($seededAt);
        self::assertSame('UTC', $seededAt->getTimezone()->getName());
        self::assertEquals(new \DateTimeImmutable('2026-08-28T10:00:00Z'), $seededAt);
    }

    public function testApplyToSetsTheDigestFormat(): void
    {
        $enablement = new DigestEnablement(new NaiveUtcClock(new MockClock('2026-08-28T12:00:00Z')));
        $settings = UnsavedUserSettings::digest();

        $enablement->applyTo($settings, new DigestConfigurationModel(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
            format: DigestFormat::Text,
        ));

        self::assertSame(DigestFormat::Text, $settings->getFormat());
    }
}
