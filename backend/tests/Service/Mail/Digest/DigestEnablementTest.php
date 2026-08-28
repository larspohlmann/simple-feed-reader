<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Dto\Me\UpdateDigestRequest;
use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestEnablement;
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
        $enablement = new DigestEnablement(new MockClock('2026-08-28T12:00:00Z'));
        $prefs = $this->preferences();

        $enablement->applyTo($prefs, new UpdateDigestRequest(
            enabled: true,
            cadence: DigestCadence::Weekly,
            sendHour: 9,
            weekday: 3,
        ));

        self::assertTrue($prefs->isDigestEnabled());
        self::assertSame(DigestCadence::Weekly, $prefs->getDigestCadence());
        self::assertSame(9, $prefs->getDigestSendHour());
        self::assertSame(3, $prefs->getDigestWeekday());
    }

    public function testFirstOffToOnTransitionSeedsDigestLastSentAtToNow(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T12:00:00Z');
        $enablement = new DigestEnablement(new MockClock($now));
        $prefs = $this->preferences();
        self::assertFalse($prefs->isDigestEnabled());
        self::assertNull($prefs->getDigestLastSentAt());

        $enablement->applyTo($prefs, new UpdateDigestRequest(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
        ));

        self::assertEquals($now, $prefs->getDigestLastSentAt());
    }

    public function testANonTransitioningEnabledWriteDoesNotMoveDigestLastSentAt(): void
    {
        $enablement = new DigestEnablement(new MockClock('2026-08-28T12:00:00Z'));
        $prefs = $this->preferences();
        $prefs->setDigestEnabled(true);
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $prefs->setDigestLastSentAt($seededAt);

        $enablement->applyTo($prefs, new UpdateDigestRequest(
            enabled: true,
            cadence: DigestCadence::Weekly,
            sendHour: 10,
            weekday: 5,
        ));

        self::assertEquals($seededAt, $prefs->getDigestLastSentAt());
    }

    public function testAnAlreadyDisabledWriteThatStaysDisabledDoesNotSeed(): void
    {
        $enablement = new DigestEnablement(new MockClock('2026-08-28T12:00:00Z'));
        $prefs = $this->preferences();
        self::assertFalse($prefs->isDigestEnabled());
        self::assertNull($prefs->getDigestLastSentAt());

        $enablement->applyTo($prefs, new UpdateDigestRequest(
            enabled: false,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
        ));

        self::assertFalse($prefs->isDigestEnabled());
        self::assertNull($prefs->getDigestLastSentAt());
    }

    public function testDisablingDoesNotSeedOrClearDigestLastSentAt(): void
    {
        $enablement = new DigestEnablement(new MockClock('2026-08-28T12:00:00Z'));
        $prefs = $this->preferences();
        $prefs->setDigestEnabled(true);
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $prefs->setDigestLastSentAt($seededAt);

        $enablement->applyTo($prefs, new UpdateDigestRequest(
            enabled: false,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
        ));

        self::assertFalse($prefs->isDigestEnabled());
        self::assertEquals($seededAt, $prefs->getDigestLastSentAt());
    }

    public function testReenablingAfterDisableDoesNotReseedBecauseLastSentAtIsAlreadySet(): void
    {
        $enablement = new DigestEnablement(new MockClock('2026-08-28T12:00:00Z'));
        $prefs = $this->preferences();
        $prefs->setDigestEnabled(false);
        // Simulates an account that already received at least one digest before
        // being turned off: digestLastSentAt is set, even though isDigestEnabled
        // is currently false.
        $seededAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $prefs->setDigestLastSentAt($seededAt);

        $enablement->applyTo($prefs, new UpdateDigestRequest(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
        ));

        self::assertEquals($seededAt, $prefs->getDigestLastSentAt());
    }

    public function testANonUtcClockIsNormalisedToNaiveUtcBeforeSeeding(): void
    {
        $clock = new MockClock('2026-08-28T12:00:00+02:00');
        $enablement = new DigestEnablement($clock);
        $prefs = $this->preferences();

        $enablement->applyTo($prefs, new UpdateDigestRequest(
            enabled: true,
            cadence: DigestCadence::Daily,
            sendHour: 8,
            weekday: 1,
        ));

        $seededAt = $prefs->getDigestLastSentAt();
        self::assertNotNull($seededAt);
        self::assertSame('UTC', $seededAt->getTimezone()->getName());
        self::assertEquals(new \DateTimeImmutable('2026-08-28T10:00:00Z'), $seededAt);
    }
}
