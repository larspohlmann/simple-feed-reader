<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

final class XmlHelper
{
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

    public static function isRoot(\DOMNode $node): bool
    {
        return $node->parentNode instanceof \DOMDocument;
    }

    /** @phpstan-assert-if-true =\DOMElement $node */
    public static function isElement(\DOMNode $node, string $localName, ?string $namespaceUri): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === $localName
            && $node->namespaceURI === $namespaceUri;
    }

    private function __construct()
    {
    }
}
