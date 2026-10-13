<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Pass;

use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\RequestSlots;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\TestCase;

final class RequestSlotsTest extends TestCase
{
    private function attempt(string $url): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, FeedTickets::of($url));
    }

    public function testAFreshHostHasCapacity(): void
    {
        $slots = new RequestSlots(2);

        self::assertTrue($slots->hasCapacityFor($this->attempt('https://example.com/feed')));
    }

    public function testCapacityRemainsUntilTheCapIsReached(): void
    {
        $slots = new RequestSlots(2);
        $attempt = $this->attempt('https://example.com/feed');

        $slots->acquire($attempt);
        self::assertTrue($slots->hasCapacityFor($attempt), 'one of two slots taken');

        $slots->acquire($attempt);
        self::assertFalse($slots->hasCapacityFor($attempt), 'both slots taken');
    }

    public function testReleasingASlotRestoresCapacity(): void
    {
        $slots = new RequestSlots(2);
        $attempt = $this->attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);

        self::assertTrue($slots->hasCapacityFor($attempt));
    }

    public function testASingleReleaseFreesExactlyOneSlot(): void
    {
        $slots = new RequestSlots(2);
        $attempt = $this->attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);
        $slots->acquire($attempt);

        // Two acquired, one released, one re-acquired: the host is full again, so
        // the release must have freed one slot, not two.
        self::assertFalse($slots->hasCapacityFor($attempt));
    }

    public function testReleasingAnIdleSlotBanksNoCapacity(): void
    {
        $slots = new RequestSlots(1);
        $attempt = $this->attempt('https://example.com/feed');

        $slots->release($attempt);
        $slots->acquire($attempt);

        self::assertFalse($slots->hasCapacityFor($attempt));
    }

    public function testHostsAreCountedIndependently(): void
    {
        $slots = new RequestSlots(1);
        $one = $this->attempt('https://one.example.com/feed');
        $two = $this->attempt('https://two.example.com/feed');

        $slots->acquire($one);

        self::assertFalse($slots->hasCapacityFor($one));
        self::assertTrue($slots->hasCapacityFor($two));
    }

    public function testNormalisedHostsShareTheSameSlots(): void
    {
        $slots = new RequestSlots(1);

        $slots->acquire($this->attempt('https://www.example.com:443/feed'));

        self::assertFalse($slots->hasCapacityFor($this->attempt('https://example.com/other')));
    }

    public function testRejectsACapBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        new RequestSlots(0);
    }

    private function apiAttempt(string $url, string $slotName): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, RequestTicketModel::api(ApiRequestModel::get($url), $slotName));
    }

    public function testANamedSlotIsSharedWhateverTheHost(): void
    {
        $slots = new RequestSlots(1);

        $slots->acquire($this->apiAttempt('https://one.example.com/a', 'account-7'));

        self::assertFalse($slots->hasCapacityFor($this->apiAttempt('https://two.example.com/b', 'account-7')));
        self::assertTrue($slots->hasCapacityFor($this->apiAttempt('https://one.example.com/a', 'account-8')));
    }

    public function testANamedSlotNeverTakesAHostsCapacity(): void
    {
        $slots = new RequestSlots(1);

        $slots->acquire($this->apiAttempt('https://example.com/a', 'example.com'));

        self::assertTrue($slots->hasCapacityFor($this->attempt('https://example.com/feed')));
    }
}
