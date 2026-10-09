<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\XmlHelper;
use PHPUnit\Framework\TestCase;

final class XmlHelperTest extends TestCase
{
    private function firstChild(string $innerXml): \DOMNode
    {
        $document = new \DOMDocument();
        /** @noinspection XmlUnusedNamespaceDeclaration */
        $document->loadXML('<item xmlns:media="http://search.yahoo.com/mrss/">' . $innerXml . '</item>');
        $child = $document->documentElement?->firstChild;
        self::assertInstanceOf(\DOMNode::class, $child);

        return $child;
    }

    public function testAnElementMatchesItsLocalNameInItsNamespace(): void
    {
        self::assertTrue(XmlHelper::isElement(
            $this->firstChild('<media:title/>'),
            'title',
            XmlHelper::MEDIA_RSS_NAMESPACE,
        ));
    }

    public function testAnElementInAnotherNamespaceDoesNotMatch(): void
    {
        self::assertFalse(XmlHelper::isElement($this->firstChild('<title/>'), 'title', XmlHelper::MEDIA_RSS_NAMESPACE));
    }

    public function testNullMatchesOnlyAnElementWithoutNamespace(): void
    {
        self::assertTrue(XmlHelper::isElement($this->firstChild('<title/>'), 'title', null));
        self::assertFalse(XmlHelper::isElement($this->firstChild('<media:title/>'), 'title', null));
    }

    public function testAnotherLocalNameDoesNotMatch(): void
    {
        self::assertFalse(XmlHelper::isElement(
            $this->firstChild('<media:content/>'),
            'title',
            XmlHelper::MEDIA_RSS_NAMESPACE,
        ));
    }

    public function testATextNodeIsNoElement(): void
    {
        self::assertFalse(XmlHelper::isElement($this->firstChild('title'), 'title', null));
    }
}
