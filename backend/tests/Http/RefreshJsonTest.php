<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RefreshJson;
use App\Service\Refresh\RefreshReport;
use App\Service\Refresh\RefreshRunProgress;
use App\Service\Refresh\TrackedRefreshReport;
use PHPUnit\Framework\TestCase;

final class RefreshJsonTest extends TestCase
{
    public function testMapsEveryCounterToItsOwnKeyAndDropsTotal(): void
    {
        $report = RefreshReport::finished(
            total: 9,
            fetched: 1,
            notModified: 2,
            failed: 3,
            throttled: 4,
            skippedForBudget: 5,
            remaining: 6,
            pruned: 7,
        );
        $tracked = new TrackedRefreshReport($report, RefreshRunProgress::resumed(10, 20));

        self::assertSame(
            [
                'status' => 'partial',
                'progress' => ['done' => 10, 'total' => 20],
                'fetched' => 1,
                'notModified' => 2,
                'failed' => 3,
                'throttled' => 4,
                'skippedForBudget' => 5,
                'remaining' => 6,
                'pruned' => 7,
            ],
            RefreshJson::slice($tracked),
        );
    }
}
