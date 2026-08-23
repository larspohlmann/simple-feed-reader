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
     * Trimmed text content of the first matching direct child element, or
     * null when absent/empty. When $namespaceUri is null, any namespace
     * matches.
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

            return $text === '' ? null : $text;
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
