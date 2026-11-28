<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream;

use App\Service\Curation\YourStream\YourStreamWordSweep;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;

final class YourStreamWordSweepTest extends DbTestCase
{
    use RequiresServices;
    use SeedsProfiledAccounts;
    use SeedsUsers;

    public function testASweepMakesNoProviderCall(): void
    {
        $this->profiledAccount('terms-sweep-quiet@example.test');

        $report = $this->requireFromContainer(YourStreamWordSweep::class)->sweep(SweepBudgetModel::seconds(60));

        self::assertSame([], $this->requireFromContainer(StubChatClient::class)->calls());
        self::assertSame(['raritiesRefreshed' => 0], $report->toLogContext());
    }

    public function testASpentBudgetDoesNotEvenReadTheRarityWindow(): void
    {
        $report = $this->requireFromContainer(YourStreamWordSweep::class)->sweep(SweepBudgetModel::seconds(0));

        self::assertSame(['raritiesRefreshed' => 0], $report->toLogContext());
    }
}
