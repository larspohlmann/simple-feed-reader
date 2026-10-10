<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Parser\FeedFormatParser\Atom10Parser;
use App\Service\Parser\Pass\CoreElement;
use App\Service\Parser\Support\XmlHelper;
use PHPUnit\Framework\Assert;

trait FeedItemFixtures
{
    private function rssItem(string $innerXml): CoreElement
    {
        return self::onlyElement(
            '<rss' . self::extensionNamespaces() . '><channel><item>' . $innerXml . '</item></channel></rss>',
            'item',
        );
    }

    private function rss1Item(string $innerXml): CoreElement
    {
        return self::onlyElement(
            '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/"'
            . self::extensionNamespaces() . '><item>' . $innerXml . '</item></rdf:RDF>',
            'item',
        );
    }

    private function atomEntry(string $innerXml): CoreElement
    {
        return self::onlyElement(
            '<feed xmlns="' . Atom10Parser::NAMESPACE . '"' . self::extensionNamespaces() . '><entry>'
            . $innerXml . '</entry></feed>',
            'entry',
        );
    }

    private static function extensionNamespaces(): string
    {
        return ' xmlns:media="' . XmlHelper::MEDIA_RSS_NAMESPACE . '"'
            . ' xmlns:itunes="' . XmlHelper::ITUNES_NAMESPACE . '"';
    }

    private static function onlyElement(string $xml, string $localName): CoreElement
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $element = $document->getElementsByTagNameNS('*', $localName)->item(0);
        Assert::assertInstanceOf(\DOMElement::class, $element);

        return CoreElement::inOwnNamespace($element);
    }
}
