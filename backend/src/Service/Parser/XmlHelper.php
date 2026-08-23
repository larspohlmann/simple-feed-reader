<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class XmlHelper
{
    /**
     * Dublin Core elements namespace. RSS 1.0/2.0 and the Atom 0.3 feeds of
     * some publishers (tagesschau, NDR) carry the entry date as <dc:date> here
     * rather than in the feed dialect's own date element.
     */
    public const string DUBLIN_CORE_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    /**
     * Trimmed text content of the first matching direct child element that
     * HAS text, or null when none does. When $namespaceUri is null, any
     * namespace matches.
     *
     * The first-with-text rule is not fussiness. Matching runs on local name,
     * so an unqualified lookup for 'link' also matches <atom:link/>, and RSS
     * 2.0 feeds routinely open their channel with a self-referencing
     * <atom:link rel="self"/> before the real <link>. Returning on that first
     * match left Al Jazeera and every feed shaped like it with no site URL at
     * all.
     */
    public static function childText(\DOMElement $parent, string $localName, ?string $namespaceUri = null): ?string
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== $localName) {
                continue;
            }
            if ($namespaceUri !== null && $child->namespaceURI !== $namespaceUri) {
                continue;
            }
            $text = trim($child->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * First matching direct child element, or null when absent. When
     * $namespaceUri is null, any namespace matches. childText() cannot serve
     * here: a feed's <image> holds its address in a grandchild <url>, so the
     * element itself has to be handed back to be descended into.
     */
    public static function childElement(
        \DOMElement $parent,
        string $localName,
        ?string $namespaceUri = null,
    ): ?\DOMElement {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== $localName) {
                continue;
            }
            if ($namespaceUri !== null && $child->namespaceURI !== $namespaceUri) {
                continue;
            }

            return $child;
        }

        return null;
    }
}
