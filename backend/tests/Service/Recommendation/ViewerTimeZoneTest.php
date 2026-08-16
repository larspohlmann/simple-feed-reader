<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ViewerTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewerTimeZone::class)]
final class ViewerTimeZoneTest extends TestCase
{
    public function testTakesTheZoneTheClientNamed(): void
    {
        self::assertSame('Europe/Berlin', ViewerTimeZone::of('Europe/Berlin')->zone->getName());
    }

    public function testFallsBackToUtcWhenTheClientNamedNone(): void
    {
        self::assertSame('UTC', ViewerTimeZone::of(null)->zone->getName());
    }

    public function testFallsBackToUtcOnAZoneNoDatabaseKnows(): void
    {
        self::assertSame('UTC', ViewerTimeZone::of('Mars/Olympus_Mons')->zone->getName());
    }

    public function testFallsBackToUtcOnAnEmptyValue(): void
    {
        self::assertSame('UTC', ViewerTimeZone::of('')->zone->getName());
    }
}
