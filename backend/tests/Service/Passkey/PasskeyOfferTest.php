<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Entity\Preferences;
use App\Entity\User;
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
        $offer = new PasskeyOffer(new MockClock($now));
        $prefs = $this->preferences();
        self::assertNull($prefs->getPasskeyOfferAnsweredAt());

        $offer->markAnswered($prefs->getUser());

        self::assertEquals($now, $prefs->getPasskeyOfferAnsweredAt());
    }

    /**
     * The only safety-critical behaviour of this service: a second answer —
     * a retried request, a client that fires the answer twice — must not
     * move an already-set timestamp. Advancing the clock between the two
     * calls is what makes this test able to fail: without it, a buggy
     * unconditional overwrite would still land on the same instant and the
     * assertion would pass by accident.
     */
    public function testASecondAnswerDoesNotMoveTheAlreadySetTimestamp(): void
    {
        $clock = new MockClock('2026-08-01T00:00:00Z');
        $offer = new PasskeyOffer($clock);
        $prefs = $this->preferences();
        $seededAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $prefs->markPasskeyOfferAnswered($seededAt);

        $clock->modify('+1 day');
        $offer->markAnswered($prefs->getUser());

        self::assertEquals($seededAt, $prefs->getPasskeyOfferAnsweredAt());
    }

    public function testANonUtcClockIsNormalisedToNaiveUtcBeforeRecording(): void
    {
        $clock = new MockClock('2026-08-28T12:00:00+02:00');
        $offer = new PasskeyOffer($clock);
        $prefs = $this->preferences();

        $offer->markAnswered($prefs->getUser());

        $answeredAt = $prefs->getPasskeyOfferAnsweredAt();
        self::assertNotNull($answeredAt);
        self::assertSame('UTC', $answeredAt->getTimezone()->getName());
        self::assertEquals(new \DateTimeImmutable('2026-08-28T10:00:00Z'), $answeredAt);
    }
}
