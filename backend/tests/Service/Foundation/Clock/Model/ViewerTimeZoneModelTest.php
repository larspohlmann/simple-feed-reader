<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Clock\Model;

use App\Service\Foundation\Clock\Model\ViewerTimeZoneModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewerTimeZoneModel::class)]
final class ViewerTimeZoneModelTest extends TestCase
{
    public function testTakesTheZoneTheClientNamed(): void
    {
        self::assertSame('Europe/Berlin', ViewerTimeZoneModel::fromIdentifier('Europe/Berlin')->zone->getName());
    }

    public function testFallsBackToUtcWhenTheClientNamedNone(): void
    {
        self::assertSame('UTC', ViewerTimeZoneModel::fromIdentifier(null)->zone->getName());
    }

    public function testFallsBackToUtcOnAZoneNoDatabaseKnows(): void
    {
        self::assertSame('UTC', ViewerTimeZoneModel::fromIdentifier('Mars/Olympus_Mons')->zone->getName());
    }

    public function testFallsBackToUtcOnAnEmptyValue(): void
    {
        self::assertSame('UTC', ViewerTimeZoneModel::fromIdentifier('')->zone->getName());
    }
}
