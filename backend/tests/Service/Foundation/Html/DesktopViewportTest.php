<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\DesktopViewport;
use PHPUnit\Framework\TestCase;

final class DesktopViewportTest extends TestCase
{
    private DesktopViewport $viewport;

    protected function setUp(): void
    {
        $this->viewport = new DesktopViewport();
    }

    public function testAdmitsASourceWithoutAMediaQuery(): void
    {
        self::assertTrue($this->viewport->admits(null));
        self::assertTrue($this->viewport->admits(''));
    }

    public function testRejectsAMaxWidthBelowTheDesktopViewport(): void
    {
        self::assertFalse($this->viewport->admits('(max-width: 767px)'));
        self::assertFalse($this->viewport->admits('(max-width:  1023px)'));
    }

    public function testRejectsAMinWidthAboveTheDesktopViewport(): void
    {
        self::assertFalse($this->viewport->admits('(min-width: 1921px)'));
    }

    public function testAdmitsARangeThatContainsTheDesktopViewport(): void
    {
        self::assertTrue($this->viewport->admits('(min-width: 1024px)'));
        self::assertTrue($this->viewport->admits('(min-width: 768px) and (max-width: 1919px)'));
    }

    public function testAdmitsABoundEqualToTheDesktopViewport(): void
    {
        self::assertTrue($this->viewport->admits('(max-width: 1280px)'));
        self::assertTrue($this->viewport->admits('(min-width: 1280px)'));
    }

    public function testEveryConditionMustAdmit(): void
    {
        self::assertFalse($this->viewport->admits('screen and (min-width: 768px) and (max-width: 1023px)'));
    }

    public function testAdmitsAQueryItCannotEvaluate(): void
    {
        self::assertTrue($this->viewport->admits('(orientation: landscape)'));
        self::assertTrue($this->viewport->admits('(max-width: 40em)'));
    }
}
