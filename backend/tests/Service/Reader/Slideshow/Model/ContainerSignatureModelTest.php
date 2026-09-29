<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow\Model;

use App\Service\Reader\Slideshow\Model\ContainerSignatureModel;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class ContainerSignatureModelTest extends TestCase
{
    use ParsesHtml;

    public function testClassStringProducesASignature(): void
    {
        self::assertNotNull(ContainerSignatureModel::fromClassAttribute('carousel gallery 0'));
    }

    public function testExtraWhitespaceBetweenClassTokensDoesNotChangeTheSignature(): void
    {
        self::assertEquals(
            ContainerSignatureModel::fromClassAttribute('carousel gallery'),
            ContainerSignatureModel::fromClassAttribute('carousel   gallery'),
        );
    }

    public function testEmptyClassAttributeProducesNoSignature(): void
    {
        self::assertNull(ContainerSignatureModel::fromClassAttribute(''));
    }

    public function testWhitespaceOnlyClassAttributeProducesNoSignature(): void
    {
        self::assertNull(ContainerSignatureModel::fromClassAttribute('   '));
    }

    public function testElementCarryingAllTokensMatches(): void
    {
        $signature = ContainerSignatureModel::fromClassAttribute('carousel gallery 0');

        self::assertNotNull($signature);
        self::assertTrue($signature->matches($this->elementWithClass('carousel gallery 0 extra')));
    }

    public function testElementMissingATokenDoesNotMatch(): void
    {
        $signature = ContainerSignatureModel::fromClassAttribute('carousel gallery 0');

        self::assertNotNull($signature);
        self::assertFalse($signature->matches($this->elementWithClass('carousel gallery')));
    }

    public function testMatchesByIdWhenTheClassChangedButTheIdSurvived(): void
    {
        $signature = ContainerSignatureModel::fromElement(
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
        $signature = ContainerSignatureModel::fromElement($this->element('<div id="slideshow-1">x</div>'));

        self::assertNotNull($signature);
        self::assertFalse($signature->matches($this->element('<div class="anything">y</div>')));
    }

    public function testElementWithNeitherClassNorIdProducesNoSignature(): void
    {
        self::assertNull(ContainerSignatureModel::fromElement($this->element('<div>x</div>')));
    }

    private function elementWithClass(string $classAttribute): Element
    {
        return $this->element(\sprintf('<span class="%s">slides</span>', $classAttribute));
    }

    private function element(string $markup): Element
    {
        $document = $this->document("<div>{$markup}</div>");

        $element = $document->querySelector('div > *') ?? $document->querySelector('div');
        self::assertNotNull($element);

        return $element;
    }
}
