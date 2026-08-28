<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\Srcset;
use PHPUnit\Framework\TestCase;

final class SrcsetTest extends TestCase
{
    public function testTakesTheFirstCandidateUrl(): void
    {
        self::assertSame('a.jpg', Srcset::firstUrl('a.jpg 1x, b.jpg 2x'));
    }

    public function testDropsTheWidthDescriptor(): void
    {
        self::assertSame('a.jpg', Srcset::firstUrl('a.jpg 800w'));
    }

    public function testTakesABareUrlWithoutADescriptor(): void
    {
        self::assertSame('a.jpg', Srcset::firstUrl('a.jpg'));
    }

    public function testReturnsNullForNullOrBlank(): void
    {
        self::assertNull(Srcset::firstUrl(null));
        self::assertNull(Srcset::firstUrl(''));
        self::assertNull(Srcset::firstUrl('   '));
    }
}
