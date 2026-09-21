<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Service\Image\DeclaredImage;
use PHPUnit\Framework\TestCase;

final class DeclaredImageTest extends TestCase
{
    public function testDeclaresBeaconIsTrueForAOnePixelImage(): void
    {
        self::assertTrue((new DeclaredImage('https://i/x.jpg', 1, 1))->declaresBeacon());
    }

    public function testDeclaresBeaconIsTrueWhenBothEdgesEqualTheCeiling(): void
    {
        self::assertTrue((new DeclaredImage('https://i/x.jpg', 100, 100))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWhenAnEdgeExceedsTheCeiling(): void
    {
        self::assertFalse((new DeclaredImage('https://i/x.jpg', 101, 100))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWithOnlyOneDimension(): void
    {
        self::assertFalse((new DeclaredImage('https://i/x.jpg', 1, null))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWithNoDimensions(): void
    {
        self::assertFalse((new DeclaredImage('https://i/x.jpg', null, null))->declaresBeacon());
    }
}
