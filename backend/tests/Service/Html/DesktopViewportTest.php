<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\DesktopViewport;
use PHPUnit\Framework\TestCase;

final class DesktopViewportTest extends TestCase
{
    public function testAdmitsASourceWithoutAMediaQuery(): void
    {
        self::assertTrue(DesktopViewport::admits(null));
        self::assertTrue(DesktopViewport::admits(''));
    }

    public function testRejectsAMaxWidthBelowTheDesktopViewport(): void
    {
        self::assertFalse(DesktopViewport::admits('(max-width: 767px)'));
        self::assertFalse(DesktopViewport::admits('(max-width:  1023px)'));
    }

    public function testRejectsAMinWidthAboveTheDesktopViewport(): void
    {
        self::assertFalse(DesktopViewport::admits('(min-width: 1921px)'));
    }

    public function testAdmitsARangeThatContainsTheDesktopViewport(): void
    {
        self::assertTrue(DesktopViewport::admits('(min-width: 1024px)'));
        self::assertTrue(DesktopViewport::admits('(min-width: 768px) and (max-width: 1919px)'));
    }

    public function testAdmitsABoundEqualToTheDesktopViewport(): void
    {
        self::assertTrue(DesktopViewport::admits('(max-width: 1280px)'));
        self::assertTrue(DesktopViewport::admits('(min-width: 1280px)'));
    }

    public function testEveryConditionMustAdmit(): void
    {
        self::assertFalse(DesktopViewport::admits('screen and (min-width: 768px) and (max-width: 1023px)'));
    }

    public function testAdmitsAQueryItCannotEvaluate(): void
    {
        self::assertTrue(DesktopViewport::admits('(orientation: landscape)'));
        self::assertTrue(DesktopViewport::admits('(max-width: 40em)'));
    }
}
