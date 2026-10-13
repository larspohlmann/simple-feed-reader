<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelineOutcome;
use App\Service\Social\Model\TimelineOutcomeModel;
use App\Service\Social\Model\TimelinePageModel;
use PHPUnit\Framework\TestCase;

final class TimelineOutcomeModelTest extends TestCase
{
    public function testAFetchedOutcomeCarriesItsPage(): void
    {
        $page = TimelinePageModel::of([], KnownSharesModel::none(), '7');

        $outcome = TimelineOutcomeModel::fetched($page);

        self::assertSame(TimelineOutcome::Fetched, $outcome->outcome);
        self::assertSame($page, $outcome->page());
    }

    public function testAFailedOutcomeCarriesItsFailure(): void
    {
        $failure = new SocialNetworkUnreachableException('The Mastodon instance answered 502.');

        self::assertSame($failure, TimelineOutcomeModel::failed($failure)->failure());
    }

    public function testAThrottledOutcomeCarriesItsDelay(): void
    {
        self::assertSame(120, TimelineOutcomeModel::throttled(120)->retryAfterSeconds);
    }

    public function testAHeldBackOutcomeCarriesNoPage(): void
    {
        $this->expectException(\LogicException::class);

        TimelineOutcomeModel::accountThrottled()->page();
    }

    public function testAFetchedOutcomeCarriesNoFailure(): void
    {
        $this->expectException(\LogicException::class);

        TimelineOutcomeModel::fetched(TimelinePageModel::of([], KnownSharesModel::none(), null))->failure();
    }
}
