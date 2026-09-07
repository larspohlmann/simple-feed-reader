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

    private function elementWithClass(string $classAttribute): \Dom\Element
    {
        $document = HtmlDocumentParser::parseOrNull(
            \sprintf('<div><span class="%s">slides</span></div>', $classAttribute),
        );
        self::assertNotNull($document);

        $element = $document->querySelector('span');
        self::assertNotNull($element);

        return $element;
    }
}
