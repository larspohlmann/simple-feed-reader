<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Pass\TimelineAccounts;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class TimelineAccountsTest extends TestCase
{
    use AssignsEntityIds;

    public function testAnAccountCountsAsRenewedOnceMarked(): void
    {
        $accounts = new TimelineAccounts();
        $alice = self::bluesky(3);

        self::assertFalse($accounts->isRenewed($alice));
        $accounts->markRenewed($alice);

        self::assertTrue($accounts->isRenewed($alice));
    }

    public function testARenewalBelongsToItsOwnAccount(): void
    {
        $accounts = new TimelineAccounts();

        $accounts->markRenewed(self::bluesky(3));

        self::assertFalse($accounts->isRenewed(self::bluesky(4)));
    }

    public function testAThrottledAccountMayNotAskAgain(): void
    {
        $accounts = new TimelineAccounts();
        $alice = self::bluesky(3);

        $accounts->settleFailure($alice, new SocialNetworkThrottledException('Bluesky is rate limiting.', 60));

        self::assertFalse($accounts->mayAsk($alice));
    }

    private static function bluesky(int $accountId): SocialAccount
    {
        return self::withId(SocialAccounts::bluesky(), $accountId);
    }
}
