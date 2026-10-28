<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\Support;

use App\Service\Image\Support\RenditionTargets;
use PHPUnit\Framework\TestCase;

final class RenditionTargetsTest extends TestCase
{
    public function testEachTargetOwnsTheBandUpToTheNextOne(): void
    {
        self::assertNull(RenditionTargets::bandOf(263));
        self::assertSame(264, RenditionTargets::bandOf(264));
        self::assertSame(264, RenditionTargets::bandOf(519));
        self::assertSame(520, RenditionTargets::bandOf(520));
        self::assertSame(680, RenditionTargets::bandOf(1099));
        self::assertSame(1100, RenditionTargets::bandOf(1359));
        self::assertSame(1360, RenditionTargets::bandOf(2039));
        self::assertNull(RenditionTargets::bandOf(2040));
    }

    public function testATargetWithAKnownWidthInItsBandIsCovered(): void
    {
        self::assertSame([264, 520, 1100, 1360], RenditionTargets::uncovered([768]));
        self::assertSame([520, 680, 1100], RenditionTargets::uncovered([300, 1500, 4000]));
        self::assertSame(RenditionTargets::WIDTHS, RenditionTargets::uncovered([]));
    }
}
