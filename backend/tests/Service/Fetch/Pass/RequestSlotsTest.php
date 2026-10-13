<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Pass;

use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\RequestSlots;
use App\Tests\Support\FeedTickets;
use App\Tests\Support\FixedKeySlot;
use PHPUnit\Framework\TestCase;

final class RequestSlotsTest extends TestCase
{
    public function testAFreshHostHasCapacity(): void
    {
        $slots = new RequestSlots();

        self::assertTrue($slots->hasCapacityFor(self::attempt('https://example.com/feed')));
    }

    public function testCapacityRemainsUntilTheCapIsReached(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed');

        $slots->acquire($attempt);
        self::assertTrue($slots->hasCapacityFor($attempt), 'one of two slots taken');

        $slots->acquire($attempt);
        self::assertFalse($slots->hasCapacityFor($attempt), 'both slots taken');
    }

    public function testReleasingASlotRestoresCapacity(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);

        self::assertTrue($slots->hasCapacityFor($attempt));
    }

    public function testASingleReleaseFreesExactlyOneSlot(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed');

        $slots->acquire($attempt);
        $slots->acquire($attempt);
        $slots->release($attempt);
        $slots->acquire($attempt);

        self::assertFalse($slots->hasCapacityFor($attempt));
    }

    public function testReleasingAnIdleSlotBanksNoCapacity(): void
    {
        $slots = new RequestSlots();
        $attempt = self::attempt('https://example.com/feed', 1);

        $slots->release($attempt);
        $slots->acquire($attempt);

        self::assertFalse($slots->hasCapacityFor($attempt));
    }

    public function testHostsAreCountedIndependently(): void
    {
        $slots = new RequestSlots();
        $one = self::attempt('https://one.example.com/feed', 1);
        $two = self::attempt('https://two.example.com/feed', 1);

        $slots->acquire($one);

        self::assertFalse($slots->hasCapacityFor($one));
        self::assertTrue($slots->hasCapacityFor($two));
    }

    public function testNormalisedHostsShareTheSameSlots(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::attempt('https://www.example.com:443/feed', 1));

        self::assertFalse($slots->hasCapacityFor(self::attempt('https://example.com/other', 1)));
    }

    public function testAFixedKeySlotIsSharedWhateverTheHost(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::apiAttempt('https://one.example.com/a', [new FixedKeySlot('account-7', 1)]));

        self::assertFalse($slots->hasCapacityFor(
            self::apiAttempt('https://two.example.com/b', [new FixedKeySlot('account-7', 1)]),
        ));
        self::assertTrue($slots->hasCapacityFor(
            self::apiAttempt('https://one.example.com/a', [new FixedKeySlot('account-8', 1)]),
        ));
    }

    public function testTwoSlotTypesWithAnEqualKeyNeverShareACap(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::apiAttempt('https://example.com/a', [new FixedKeySlot('example.com', 1)]));

        self::assertTrue($slots->hasCapacityFor(self::attempt('https://example.com/feed', 1)));
    }

    public function testATicketWaitsWhileAnyOfItsSlotsIsFull(): void
    {
        $slots = new RequestSlots();
        $slots->acquire(self::apiAttempt('https://a.example/x', [
            new FixedKeySlot('account:1', 3),
            new FixedKeySlot('server:a', 1),
        ]));

        self::assertFalse($slots->hasCapacityFor(self::apiAttempt('https://a.example/y', [
            new FixedKeySlot('account:2', 3),
            new FixedKeySlot('server:a', 1),
        ])), 'its account has room, its server has none');
        self::assertTrue($slots->hasCapacityFor(self::apiAttempt('https://b.example/y', [
            new FixedKeySlot('account:1', 3),
            new FixedKeySlot('server:b', 1),
        ])), 'its account and its server both have room');
    }

    public function testReleasingATicketFreesEverySlotItHeld(): void
    {
        $slots = new RequestSlots();
        $held = self::apiAttempt('https://a.example/x', [
            new FixedKeySlot('account:1', 1),
            new FixedKeySlot('server:a', 1),
        ]);
        $slots->acquire($held);
        $slots->release($held);

        self::assertTrue($slots->hasCapacityFor(
            self::apiAttempt('https://a.example/y', [new FixedKeySlot('account:1', 1)]),
        ));
        self::assertTrue($slots->hasCapacityFor(
            self::apiAttempt('https://a.example/z', [new FixedKeySlot('server:a', 1)]),
        ));
    }

    public function testEachSlotIsCappedAtItsOwnCapacity(): void
    {
        $slots = new RequestSlots();
        $roomy = [new FixedKeySlot('account:1', 2)];
        $slots->acquire(self::apiAttempt('https://a.example/x', $roomy));

        self::assertTrue($slots->hasCapacityFor(self::apiAttempt('https://a.example/y', $roomy)));
        self::assertFalse($slots->hasCapacityFor(
            self::apiAttempt('https://a.example/y', [new FixedKeySlot('account:1', 1)]),
        ));
    }

    private static function attempt(string $url, int $hostCapacity = 2): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, FeedTickets::cappedAt($url, $hostCapacity));
    }

    /** @param non-empty-list<RequestSlotModelInterface> $slots */
    private static function apiAttempt(string $url, array $slots): FetchAttemptModel
    {
        return FetchAttemptModel::start(1, RequestTicketModel::api(ApiRequestModel::get($url), $slots));
    }
}
