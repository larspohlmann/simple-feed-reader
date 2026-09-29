<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Passkey\PasskeyOffer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PasskeyOfferTest extends TestCase
{
    private function preferences(): Preferences
    {
        return (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();
    }

    public function testFirstAnswerRecordsNow(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T12:00:00Z');
        $offer = new PasskeyOffer(new NaiveUtcClock(new MockClock($now)));
        $preferences = $this->preferences();
        self::assertNull($preferences->getPasskeyOfferAnsweredAt());

        $offer->markAnswered($preferences->getUser());

        self::assertEquals($now, $preferences->getPasskeyOfferAnsweredAt());
    }

    /**
     * A second answer must not move the timestamp. The clock advances between the calls, or an unconditional
     * overwrite would land on the same instant and pass.
     */
    public function testASecondAnswerDoesNotMoveTheAlreadySetTimestamp(): void
    {
        $clock = new MockClock('2026-08-01T00:00:00Z');
        $offer = new PasskeyOffer(new NaiveUtcClock($clock));
        $preferences = $this->preferences();
        $seededAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $preferences->markPasskeyOfferAnswered($seededAt);

        $clock->modify('+1 day');
        $offer->markAnswered($preferences->getUser());

        self::assertEquals($seededAt, $preferences->getPasskeyOfferAnsweredAt());
    }

    public function testANonUtcClockIsNormalisedToNaiveUtcBeforeRecording(): void
    {
        $clock = new MockClock('2026-08-28T12:00:00+02:00');
        $offer = new PasskeyOffer(new NaiveUtcClock($clock));
        $preferences = $this->preferences();

        $offer->markAnswered($preferences->getUser());

        $answeredAt = $preferences->getPasskeyOfferAnsweredAt();
        self::assertNotNull($answeredAt);
        self::assertSame('UTC', $answeredAt->getTimezone()->getName());
        self::assertEquals(new \DateTimeImmutable('2026-08-28T10:00:00Z'), $answeredAt);
    }
}
