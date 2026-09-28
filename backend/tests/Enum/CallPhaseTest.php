<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallPhase;
use PHPUnit\Framework\TestCase;

final class CallPhaseTest extends TestCase
{
    /** The stored `recommendation_run_log.phase` strings: changing one needs a data migration. */
    public function testTheStoredValuesAreTheOldConstants(): void
    {
        self::assertSame(
            ['distill', 'batch', 'consolidate'],
            array_map(static fn (CallPhase $phase): string => $phase->value, CallPhase::cases()),
        );
    }
}
