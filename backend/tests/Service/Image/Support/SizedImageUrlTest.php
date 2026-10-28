<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\SizedImageUrl;
use PHPUnit\Framework\TestCase;

final class SizedImageUrlTest extends TestCase
{
    public function testRecognisesASizeSuffixBeforeTheExtension(): void
    {
        self::assertTrue(SizedImageUrl::hasSizeSuffix('https://h/u/a-202x202.jpg'));
        self::assertTrue(SizedImageUrl::hasSizeSuffix('https://h/u/a-768x432.webp?ssl=1'));
        self::assertFalse(SizedImageUrl::hasSizeSuffix('https://h/u/a.jpg'));
        self::assertFalse(SizedImageUrl::hasSizeSuffix('https://h/202x202/a.jpg'));
    }

    public function testStripsTheSuffixKeepingTheQuery(): void
    {
        self::assertSame('https://h/u/a.jpg?x=1', SizedImageUrl::withoutSizeSuffix('https://h/u/a-202x202.jpg?x=1'));
    }

    public function testSetsASize(): void
    {
        self::assertSame('https://h/u/a-768x403.jpg', SizedImageUrl::atSize('https://h/u/a-202x202.jpg', 768, 403));
        self::assertSame('https://h/u/a-768x403.jpg', SizedImageUrl::atSize('https://h/u/a.jpg', 768, 403));
        self::assertSame('https://h/u/a-300x158.png?v=2', SizedImageUrl::atSize('https://h/u/a.png?v=2', 300, 158));
    }

    public function testADottedDirectoryIsNotTakenForTheExtension(): void
    {
        self::assertSame('https://h/v1.2/a-300x200.jpg', SizedImageUrl::atSize('https://h/v1.2/a.jpg', 300, 200));
    }
}
