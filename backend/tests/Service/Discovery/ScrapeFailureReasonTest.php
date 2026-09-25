<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\ScrapeFailureReason;
use PHPUnit\Framework\TestCase;

final class ScrapeFailureReasonTest extends TestCase
{
    public function testTheWireValuesAreTheOnesTheSubscribeDialogRenders(): void
    {
        self::assertSame(
            ['blocked', 'throttled', 'unreachable', 'not_scrapable'],
            array_map(static fn (ScrapeFailureReason $reason): string => $reason->value, ScrapeFailureReason::cases()),
        );
    }
}
