<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Model\ParsedCategoryModel;

/**
 * Reads a feed item's declared categories into a flat list, across RSS 2.0
 * <category>, Atom <category term= scheme=>, and Dublin Core <dc:subject>.
 */
final class ItemCategoryExtractor
{
    /** @return list<ParsedCategoryModel> */
    public static function extract(\DOMElement $item): array
    {
        $categories = [];
        foreach ($item->childNodes as $child) {
            $category = self::fromChild($child);
            if ($category !== null) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    private static function fromChild(\DOMNode $child): ?ParsedCategoryModel
    {
        if (!$child instanceof \DOMElement) {
            return null;
        }
        if ($child->localName === 'category') {
            return self::fromCategoryElement($child);
        }
        if ($child->localName === 'subject' && $child->namespaceURI === XmlHelper::DUBLIN_CORE_NAMESPACE) {
            $label = trim($child->textContent);

            return $label === '' ? null : new ParsedCategoryModel($label);
        }

        return null;
    }

    private static function fromCategoryElement(\DOMElement $element): ?ParsedCategoryModel
    {
        $term = trim($element->getAttribute('term'));
        $label = $term !== '' ? $term : trim($element->textContent);
        if ($label === '') {
            return null;
        }

        $scheme = trim($element->getAttribute('scheme'));
        if ($scheme === '') {
            $scheme = trim($element->getAttribute('domain'));
        }

        return new ParsedCategoryModel($label, $scheme === '' ? null : $scheme);
    }
}
