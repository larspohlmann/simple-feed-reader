<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Feed\Model;

use App\Service\Recommendation\Feed\Model\ViewerTimeZoneModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewerTimeZoneModel::class)]
final class ViewerTimeZoneModelTest extends TestCase
{
    public function testTakesTheZoneTheClientNamed(): void
    {
        self::assertSame('Europe/Berlin', ViewerTimeZoneModel::of('Europe/Berlin')->zone->getName());
    }

    public function testFallsBackToUtcWhenTheClientNamedNone(): void
    {
        self::assertSame('UTC', ViewerTimeZoneModel::of(null)->zone->getName());
    }

    public function testFallsBackToUtcOnAZoneNoDatabaseKnows(): void
    {
        self::assertSame('UTC', ViewerTimeZoneModel::of('Mars/Olympus_Mons')->zone->getName());
    }

    public function testFallsBackToUtcOnAnEmptyValue(): void
    {
        self::assertSame('UTC', ViewerTimeZoneModel::of('')->zone->getName());
    }
}
