<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LinkListDetector;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class LinkListDetectorTest extends TestCase
{
    use ParsesHtml;

    public function testThreeFifthsLinkTextMakesALinkList(): void
    {
        self::assertTrue((new LinkListDetector())->isLinkDominated($this->paragraph('<a href="/a">abcdef</a>ghij')));
    }

    public function testHalfLinkTextIsProse(): void
    {
        self::assertFalse((new LinkListDetector())->isLinkDominated($this->paragraph('<a href="/a">abcde</a>fghij')));
    }

    public function testAnEmptyBlockIsNoLinkList(): void
    {
        self::assertFalse((new LinkListDetector())->isLinkDominated($this->paragraph('')));
    }

    private function paragraph(string $inner): Element
    {
        $paragraph = $this->document('<body><p>' . $inner . '</p></body>')->querySelector('p');
        self::assertInstanceOf(Element::class, $paragraph);

        return $paragraph;
    }
}
