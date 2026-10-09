<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Url\Support\AbsoluteHttpUrl;

final class XmlHelper
{
    /** Dublin Core: RSS feeds, and Atom feeds without their dialect's own date, carry the entry date as <dc:date>. */
    public const string DUBLIN_CORE_NAMESPACE = 'http://purl.org/dc/elements/1.1/';
    public const string MEDIA_RSS_NAMESPACE = 'http://search.yahoo.com/mrss/';
    public const string ITUNES_NAMESPACE = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

    /** Trimmed text of the first matching direct child that HAS text. */
    public static function childText(\DOMElement $parent, string $localName, ?string $namespaceUri): ?string
    {
        return self::firstText(self::childElements($parent, $localName, $namespaceUri));
    }

    /** RSS 2.0 core elements share their parent's namespace: none, or the document's default one. */
    public static function childTextInOwnNamespace(\DOMElement $parent, string $localName): ?string
    {
        return self::childText($parent, $localName, $parent->namespaceURI);
    }

    public static function childElement(
        \DOMElement $parent,
        string $localName,
        ?string $namespaceUri,
    ): ?\DOMElement {
        foreach (self::childElements($parent, $localName, $namespaceUri) as $child) {
            return $child;
        }

        return null;
    }

    public static function childHttpUrl(\DOMElement $parent, string $localName, ?string $namespaceUri): ?string
    {
        foreach (self::childElements($parent, $localName, $namespaceUri) as $child) {
            $text = trim($child->textContent);
            if (AbsoluteHttpUrl::matches($text)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Direct children with this local name in exactly this namespace; null is no namespace.
     *
     * @return iterable<\DOMElement>
     */
    public static function childElements(
        \DOMElement $parent,
        string $localName,
        ?string $namespaceUri,
    ): iterable {
        foreach ($parent->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === $namespaceUri
            ) {
                yield $child;
            }
        }
    }

    /** @phpstan-assert-if-true =\DOMElement $node */
    public static function isElement(\DOMNode $node, string $localName, ?string $namespaceUri): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === $localName
            && $node->namespaceURI === $namespaceUri;
    }

    /**
     * @param iterable<\DOMElement> $elements
     */
    private static function firstText(iterable $elements): ?string
    {
        foreach ($elements as $element) {
            $text = trim($element->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
