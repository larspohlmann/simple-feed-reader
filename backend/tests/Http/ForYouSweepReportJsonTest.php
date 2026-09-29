<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ForYouSweepReportJson;
use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;
use PHPUnit\Framework\TestCase;

final class ForYouSweepReportJsonTest extends TestCase
{
    public function testItSendsTheThreeCounts(): void
    {
        self::assertSame(
            ['startedRuns' => 2, 'advancedRuns' => 3, 'activeRuns' => 1],
            ForYouSweepReportJson::report(new ForYouSweepReportModel(2, 3, 1)),
        );
    }
}
