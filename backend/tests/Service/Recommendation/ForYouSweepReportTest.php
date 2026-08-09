<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ForYouSweepReport;
use PHPUnit\Framework\TestCase;

final class ForYouSweepReportTest extends TestCase
{
    public function testToArrayCarriesTheThreeCounts(): void
    {
        self::assertSame(
            ['startedRuns' => 2, 'advancedRuns' => 3, 'activeRuns' => 1],
            (new ForYouSweepReport(2, 3, 1))->toArray(),
        );
    }
}
