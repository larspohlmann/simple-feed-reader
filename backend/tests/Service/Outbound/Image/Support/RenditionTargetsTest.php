<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Image\Support;

use App\Service\Outbound\Image\Support\RenditionTargets;
use PHPUnit\Framework\TestCase;

final class RenditionTargetsTest extends TestCase
{
    public function testEachTargetOwnsTheBandUpToTheNextOne(): void
    {
        self::assertNull(RenditionTargets::pickBand(263));
        self::assertSame(264, RenditionTargets::pickBand(264));
        self::assertSame(264, RenditionTargets::pickBand(519));
        self::assertSame(520, RenditionTargets::pickBand(520));
        self::assertSame(680, RenditionTargets::pickBand(1099));
        self::assertSame(1100, RenditionTargets::pickBand(1359));
        self::assertSame(1360, RenditionTargets::pickBand(2039));
        self::assertNull(RenditionTargets::pickBand(2040));
    }

    public function testATargetWithAKnownWidthInItsBandIsCovered(): void
    {
        self::assertSame([264, 520, 1100, 1360], RenditionTargets::uncovered([768]));
        self::assertSame([520, 680, 1100], RenditionTargets::uncovered([300, 1500, 4000]));
        self::assertSame(RenditionTargets::WIDTHS, RenditionTargets::uncovered([]));
    }
}
