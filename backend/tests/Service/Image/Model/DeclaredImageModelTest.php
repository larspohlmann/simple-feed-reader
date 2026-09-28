<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Model;

use App\Service\Image\Model\DeclaredImageModel;
use PHPUnit\Framework\TestCase;

final class DeclaredImageModelTest extends TestCase
{
    public function testDeclaresBeaconIsTrueForAOnePixelImage(): void
    {
        self::assertTrue((new DeclaredImageModel('https://i/x.jpg', 1, 1))->declaresBeacon());
    }

    public function testDeclaresBeaconIsTrueWhenBothEdgesEqualTheCeiling(): void
    {
        self::assertTrue((new DeclaredImageModel('https://i/x.jpg', 100, 100))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWhenAnEdgeExceedsTheCeiling(): void
    {
        self::assertFalse((new DeclaredImageModel('https://i/x.jpg', 101, 100))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWithOnlyOneDimension(): void
    {
        self::assertFalse((new DeclaredImageModel('https://i/x.jpg', 1, null))->declaresBeacon());
    }

    public function testDeclaresBeaconIsFalseWithNoDimensions(): void
    {
        self::assertFalse((new DeclaredImageModel('https://i/x.jpg', null, null))->declaresBeacon());
    }
}
