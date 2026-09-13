<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Reads the categories a feed item declares into a flat list, across the three
 * XML dialects: RSS 2.0 <category> (text, optional domain), Atom <category
 * term= scheme=>, and Dublin Core <dc:subject>. Reads only what the parsed
 * document already holds — no fetch. Normalization happens in CategoryNormalizer.
 */
final class ItemCategoryExtractor
{
    /** @return list<ParsedCategory> */
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

    private static function fromChild(\DOMNode $child): ?ParsedCategory
    {
        if (!$child instanceof \DOMElement) {
            return null;
        }
        if ($child->localName === 'category') {
            return self::fromCategoryElement($child);
        }
        if ($child->localName === 'subject' && $child->namespaceURI === XmlHelper::DUBLIN_CORE_NAMESPACE) {
            $label = trim($child->textContent);

            return $label === '' ? null : new ParsedCategory($label);
        }

        return null;
    }

    private static function fromCategoryElement(\DOMElement $element): ?ParsedCategory
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

        return new ParsedCategory($label, $scheme === '' ? null : $scheme);
    }
}
