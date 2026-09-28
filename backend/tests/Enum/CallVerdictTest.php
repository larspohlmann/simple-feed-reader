<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallVerdict;
use PHPUnit\Framework\TestCase;

final class CallVerdictTest extends TestCase
{
    /** The stored `recommendation_run_log.verdict` strings: changing one needs a data migration. */
    public function testTheStoredValuesAreTheOldConstants(): void
    {
        self::assertSame(
            ['usable', 'unusable', 'transport-failed'],
            array_map(static fn (CallVerdict $verdict): string => $verdict->value, CallVerdict::cases()),
        );
    }
}
