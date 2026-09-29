<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Service\Url\Support\AbsoluteHttpUrl;

final class XmlHelper
{
    /** Dublin Core: RSS feeds, and Atom feeds without their dialect's own date, carry the entry date as <dc:date>. */
    public const string DUBLIN_CORE_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    /**
     * Trimmed text of the first matching direct child that HAS text. Matching is by local name, so an unqualified
     * 'link' also matches the empty <atom:link rel="self"/> RSS 2.0 channels often put before the real <link>.
     */
    public static function childText(\DOMElement $parent, string $localName, ?string $namespaceUri = null): ?string
    {
        foreach (self::childElements($parent, $localName, $namespaceUri) as $child) {
            $text = trim($child->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    public static function childElement(
        \DOMElement $parent,
        string $localName,
        ?string $namespaceUri = null,
    ): ?\DOMElement {
        foreach (self::childElements($parent, $localName, $namespaceUri) as $child) {
            return $child;
        }

        return null;
    }

    public static function childHttpUrl(\DOMElement $parent, string $localName, ?string $namespaceUri = null): ?string
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
     * Direct children with this local name; a null $namespaceUri matches any namespace.
     *
     * @return iterable<\DOMElement>
     */
    public static function childElements(
        \DOMElement $parent,
        string $localName,
        ?string $namespaceUri,
    ): iterable {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== $localName) {
                continue;
            }
            if ($namespaceUri !== null && $child->namespaceURI !== $namespaceUri) {
                continue;
            }

            yield $child;
        }
    }

    private function __construct()
    {
    }
}
