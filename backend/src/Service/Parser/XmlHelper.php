<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class XmlHelper
{
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
}
