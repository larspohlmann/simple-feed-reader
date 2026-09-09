<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\ContainerSignature;
use PHPUnit\Framework\TestCase;

final class ContainerSignatureTest extends TestCase
{
    public function testClassStringProducesASignature(): void
    {
        self::assertNotNull(ContainerSignature::fromClassAttribute('carousel gallery 0'));
    }

    public function testEmptyClassAttributeProducesNoSignature(): void
    {
        self::assertNull(ContainerSignature::fromClassAttribute(''));
    }

    public function testWhitespaceOnlyClassAttributeProducesNoSignature(): void
    {
        self::assertNull(ContainerSignature::fromClassAttribute('   '));
    }

    public function testElementCarryingAllTokensMatches(): void
    {
        $signature = ContainerSignature::fromClassAttribute('carousel gallery 0');

        self::assertNotNull($signature);
        self::assertTrue($signature->matches($this->elementWithClass('carousel gallery 0 extra')));
    }

    public function testElementMissingATokenDoesNotMatch(): void
    {
        $signature = ContainerSignature::fromClassAttribute('carousel gallery 0');

        self::assertNotNull($signature);
        self::assertFalse($signature->matches($this->elementWithClass('carousel gallery')));
    }

    public function testMatchesByIdWhenTheClassChangedButTheIdSurvived(): void
    {
        $signature = ContainerSignature::fromElement(
            $this->element('<div class="slideshowcontainer fullwidthTarget" id="slideshow-1">x</div>'),
        );

        self::assertNotNull($signature);
        // Readability merged the wrapper chain: the class became the outer wrapper's,
        // but the inner id survived, so the original is still found for removal.
        $merged = $this->element('<div class="column slideshow-box" id="slideshow-1">x</div>');
        self::assertTrue($signature->matches($merged));
    }

    public function testIdOnlySignatureDoesNotMatchAnUnrelatedElement(): void
    {
        $signature = ContainerSignature::fromElement($this->element('<div id="slideshow-1">x</div>'));

        self::assertNotNull($signature);
        self::assertFalse($signature->matches($this->element('<div class="anything">y</div>')));
    }

    public function testElementWithNeitherClassNorIdProducesNoSignature(): void
    {
        self::assertNull(ContainerSignature::fromElement($this->element('<div>x</div>')));
    }

    private function elementWithClass(string $classAttribute): \Dom\Element
    {
        return $this->element(\sprintf('<span class="%s">slides</span>', $classAttribute));
    }

    private function element(string $markup): \Dom\Element
    {
        $document = HtmlDocumentParser::parseOrNull("<div>{$markup}</div>");
        self::assertNotNull($document);

        $element = $document->querySelector('div > *') ?? $document->querySelector('div');
        self::assertNotNull($element);

        return $element;
    }
}
