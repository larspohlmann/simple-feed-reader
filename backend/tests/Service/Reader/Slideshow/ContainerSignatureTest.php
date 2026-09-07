<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Slideshow;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Slideshow\ContainerSignature;
use Dom\HTMLDocument;
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

    public function testToSelectorJoinsTokensAsACompoundClassSelector(): void
    {
        $signature = ContainerSignature::fromClassAttribute('carousel gallery 0');

        self::assertNotNull($signature);
        self::assertSame('.carousel.gallery.\30 ', $signature->toSelector());
    }

    public function testSelectorFindsAnElementCarryingAllTokens(): void
    {
        $signature = ContainerSignature::fromClassAttribute('carousel gallery 0');

        self::assertNotNull($signature);
        self::assertNotNull(
            $this->documentWithSpan('carousel gallery 0 extra')->querySelector($signature->toSelector()),
        );
    }

    public function testSelectorFindsNothingWhenAnElementIsMissingAToken(): void
    {
        $signature = ContainerSignature::fromClassAttribute('carousel gallery 0');

        self::assertNotNull($signature);
        self::assertNull(
            $this->documentWithSpan('carousel gallery')->querySelector($signature->toSelector()),
        );
    }

    private function documentWithSpan(string $classAttribute): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull(
            \sprintf('<div><span class="%s">slides</span></div>', $classAttribute),
        );
        self::assertNotNull($document);

        return $document;
    }
}
