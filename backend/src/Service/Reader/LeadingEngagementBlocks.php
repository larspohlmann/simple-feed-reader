<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;

final class LeadingEngagementBlocks
{
    private const array BLOCK_TAGS = ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figcaption', 'div'];

    /** @return list<Element> */
    public static function in(Element $root): array
    {
        $blocks = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!self::isLeafTextBlock($element)) {
                continue;
            }

            if (self::collapsed($element->textContent) !== '') {
                $blocks[] = $element;
            }
        }

        return $blocks;
    }

    public static function isTimeOnly(Element $element): bool
    {
        $times = $element->getElementsByTagName('time');

        return $times->length === 1
            && self::collapsed($element->textContent) === self::collapsed($times->item(0)?->textContent);
    }

    private static function isLeafTextBlock(Element $element): bool
    {
        return in_array($element->localName, self::BLOCK_TAGS, true)
            && !self::hasBlockDescendant($element);
    }

    private static function hasBlockDescendant(Element $element): bool
    {
        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (in_array($descendant->localName, self::BLOCK_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function collapsed(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
