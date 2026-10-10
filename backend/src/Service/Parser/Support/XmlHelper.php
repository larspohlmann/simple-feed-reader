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

    public static function childText(\DOMElement $parent, string $localName, string $namespaceUri): ?string
    {
        return self::firstText(self::childElements($parent, $localName, $namespaceUri));
    }

    public static function childElement(
        \DOMElement $parent,
        string $localName,
        string $namespaceUri,
    ): ?\DOMElement {
        return self::firstElement(self::childElements($parent, $localName, $namespaceUri));
    }

    public static function childHttpUrl(\DOMElement $parent, string $localName, string $namespaceUri): ?string
    {
        return self::firstHttpUrl(self::childElements($parent, $localName, $namespaceUri));
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
     * Trimmed text of the first element that HAS text.
     *
     * @param iterable<\DOMElement> $elements
     */
    public static function firstText(iterable $elements): ?string
    {
        foreach ($elements as $element) {
            $text = trim($element->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /** @param iterable<\DOMElement> $elements */
    public static function firstHttpUrl(iterable $elements): ?string
    {
        foreach ($elements as $element) {
            $text = trim($element->textContent);
            if (AbsoluteHttpUrl::matches($text)) {
                return $text;
            }
        }

        return null;
    }

    /** @param iterable<\DOMElement> $elements */
    public static function firstElement(iterable $elements): ?\DOMElement
    {
        foreach ($elements as $element) {
            return $element;
        }

        return null;
    }

    private function __construct()
    {
    }
}
