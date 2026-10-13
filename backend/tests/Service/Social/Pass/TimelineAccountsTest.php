<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Pass\TimelineAccounts;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class TimelineAccountsTest extends TestCase
{
    use AssignsEntityIds;

    public function testAnAccountStaysRenewedThroughALaterRenewal(): void
    {
        $accounts = new TimelineAccounts();
        $alice = self::bluesky(3);
        $carol = self::bluesky(4);
        $stale = new SocialSessionStaleException('Bluesky refused the session of this account.');

        $accounts->settleStale($alice, $stale);
        $accounts->renewSessions();
        $accounts->settleStale($carol, $stale);
        $accounts->renewSessions();

        self::assertInstanceOf(FailedTimelineModel::class, $accounts->settleStale($alice, $stale));
    }

    private static function bluesky(int $accountId): SocialAccount
    {
        return self::withId(SocialAccounts::bluesky(), $accountId);
    }
}
