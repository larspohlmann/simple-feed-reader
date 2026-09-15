<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\PageFurniture;
use PHPUnit\Framework\TestCase;

final class PageFurnitureTest extends TestCase
{
    private function holds(string $html): bool
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $element = $document->querySelector('#x');
        self::assertNotNull($element);

        return PageFurniture::holds($element);
    }

    public function testASidebarIsFurniture(): void
    {
        self::assertTrue($this->holds('<body><main><aside><div id="x"></div></aside></main></body>'));
    }

    public function testNavigationIsFurniture(): void
    {
        self::assertTrue($this->holds('<body><nav><ul><li id="x"></li></ul></nav></body>'));
    }

    public function testAFooterIsFurniture(): void
    {
        self::assertTrue($this->holds('<body><footer><p id="x"></p></footer></body>'));
    }

    public function testTheArticleIsNot(): void
    {
        self::assertFalse($this->holds('<body><main><article><div id="x"></div></article></main></body>'));
    }

    /** NDR (#1058): a "Mehr zum Thema" teaser is a plain <div class="teaser">, not a section element. */
    public function testARelatedContentTeaserIsFurniture(): void
    {
        self::assertTrue($this->holds('<article><div class="teaser"><p id="x"></p></div></article>'));
        self::assertTrue($this->holds('<article><div class="relatedbroadcast"><p id="x"></p></div></article>'));
    }

    /** NPR plays its own audio from a promo-module, so a `promo` class must never count. */
    public function testAPromoModuleIsNot(): void
    {
        self::assertFalse($this->holds('<article><div class="promo-module"><p id="x"></p></div></article>'));
    }

    /** A hero often sits in the article's own header; only the site's chrome tags count. */
    public function testAnArticleHeaderIsNot(): void
    {
        self::assertFalse($this->holds('<body><article><header><div id="x"></div></header></article></body>'));
    }

    public function testTheHeadIsNot(): void
    {
        $html = '<html lang="en"><head><script id="x"></script></head><body></body></html>';

        self::assertFalse($this->holds($html));
    }
}
