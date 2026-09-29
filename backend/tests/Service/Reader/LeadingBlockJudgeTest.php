<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadingBlockJudge;
use App\Service\Reader\LeadingEngagementRules;
use App\Service\Reader\Model\LeadingBlockModel;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class LeadingBlockJudgeTest extends TestCase
{
    use ParsesHtml;

    public function testAnIconAssetIsDecorative(): void
    {
        $icon = $this->element('<img src="/assets/icons/share.svg">', 'img');

        self::assertTrue($this->judge()->isDecorativeIcon($icon));
    }

    public function testAPhotoNamedLikeAnIconIsNot(): void
    {
        self::assertFalse($this->judge()->isDecorativeIcon($this->element('<img src="/silicon-valley.jpg">', 'img')));
    }

    public function testAnImageInsideAFigureIsProtectedContent(): void
    {
        self::assertTrue(
            $this->judge()->isProtectedContent($this->element('<figure><img src="/a.jpg"></figure>', 'img')),
        );
    }

    public function testAHeadingIsProtectedContent(): void
    {
        self::assertTrue($this->judge()->isProtectedContent($this->element('<h2>Section</h2>', 'h2')));
    }

    public function testAPlainParagraphIsNotProtected(): void
    {
        self::assertFalse($this->judge()->isProtectedContent($this->element('<p>Text</p>', 'p')));
    }

    public function testALongLinkFreeBlockIsProse(): void
    {
        $text = str_repeat('Ein Satz mit Worten. ', 8);
        $element = $this->element('<p>' . $text . '</p>', 'p');

        self::assertTrue($this->judge()->isProse(new LeadingBlockModel($element, trim($text))));
    }

    private function judge(): LeadingBlockJudge
    {
        return new LeadingBlockJudge(new LeadingEngagementRules());
    }

    private function element(string $bodyHtml, string $selector): Element
    {
        $element = $this->document('<body>' . $bodyHtml . '</body>')->querySelector($selector);
        self::assertInstanceOf(Element::class, $element);

        return $element;
    }
}
