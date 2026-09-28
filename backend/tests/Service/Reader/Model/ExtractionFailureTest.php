<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Model;

use App\Service\Reader\Model\ExtractionFailure;
use PHPUnit\Framework\TestCase;

final class ExtractionFailureTest extends TestCase
{
    /** The reader client (frontend/src/app/reader/models.ts) switches on exactly these strings. */
    public function testTheWireValuesAreTheOnesTheClientSwitchesOn(): void
    {
        self::assertSame(
            ['no_url', 'fetch', 'unextractable', 'empty', 'mismatch'],
            array_map(static fn (ExtractionFailure $failure): string => $failure->value, ExtractionFailure::cases()),
        );
    }
}
