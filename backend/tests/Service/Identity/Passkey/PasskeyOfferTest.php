<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity\Passkey;

use App\Entity\User;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Identity\Passkey\PasskeyOffer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PasskeyOfferTest extends TestCase
{
    private function user(): User
    {
        return new User('a@b.example', new \DateTimeImmutable());
    }

    public function testFirstAnswerRecordsNow(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T12:00:00Z');
        $offer = new PasskeyOffer(new NaiveUtcClock(new MockClock($now)));
        $user = $this->user();
        self::assertNull($user->getPasskeyOfferAnsweredAt());

        $offer->markAnswered($user);

        self::assertEquals($now, $user->getPasskeyOfferAnsweredAt());
    }

    /**
     * A second answer must not move the timestamp. The clock advances between the calls, or an unconditional
     * overwrite would land on the same instant and pass.
     */
    public function testASecondAnswerDoesNotMoveTheAlreadySetTimestamp(): void
    {
        $clock = new MockClock('2026-08-01T00:00:00Z');
        $offer = new PasskeyOffer(new NaiveUtcClock($clock));
        $user = $this->user();
        $seededAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user->markPasskeyOfferAnswered($seededAt);

        $clock->modify('+1 day');
        $offer->markAnswered($user);

        self::assertEquals($seededAt, $user->getPasskeyOfferAnsweredAt());
    }

    public function testANonUtcClockIsNormalisedToNaiveUtcBeforeRecording(): void
    {
        $clock = new MockClock('2026-08-28T12:00:00+02:00');
        $offer = new PasskeyOffer(new NaiveUtcClock($clock));
        $user = $this->user();

        $offer->markAnswered($user);

        $answeredAt = $user->getPasskeyOfferAnsweredAt();
        self::assertNotNull($answeredAt);
        self::assertSame('UTC', $answeredAt->getTimezone()->getName());
        self::assertEquals(new \DateTimeImmutable('2026-08-28T10:00:00Z'), $answeredAt);
    }
}
