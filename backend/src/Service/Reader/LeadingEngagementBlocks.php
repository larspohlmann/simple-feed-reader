<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;

final class LeadingEngagementBlocks
{
    private const array BLOCK_TAGS = [
        'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figcaption', 'div', 'address', 'time',
    ];

    /** Tags that are article content in their own right and are never furniture. */
    private const array CONTENT_TAGS = ['figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** @return list<LeadingBlock> */
    public static function in(Element $root): array
    {
        $blocks = [];
        foreach ($root->getElementsByTagName('*') as $element) {
            if (!self::isLeafTextBlock($element)) {
                continue;
            }

            $text = LeadingEngagementRules::collapse($element->textContent);
            if ($text !== '') {
                $blocks[] = new LeadingBlock($element, $text);
            }
        }

        return $blocks;
    }

    public static function isProse(LeadingBlock $block): bool
    {
        return LeadingEngagementRules::isProse($block->text, BlockText::linkTextLength($block->element));
    }

    /** A caption, a heading or anything inside a <figure> is content, never furniture. */
    public static function isProtectedContent(Element $element): bool
    {
        return in_array($element->localName, self::CONTENT_TAGS, true)
            || self::hasFigureAncestor($element);
    }

    /**
     * A UI glyph rather than content: its URL carries the icon asset convention
     * — a path segment "icons" or an "icon" token in the file name. Matched as a
     * token so a content image like "silicon-valley.jpg" is left alone.
     */
    public static function isDecorativeIcon(Element $image): bool
    {
        return preg_match('~(?:^|[^a-z])icons?(?:[^a-z]|$)~i', $image->getAttribute('src') ?? '') === 1;
    }

    public static function isTimeOnly(Element $element): bool
    {
        if ($element->localName === 'time') {
            return true;
        }

        $times = $element->getElementsByTagName('time');

        return $times->length === 1
            && LeadingEngagementRules::collapse($element->textContent)
                === LeadingEngagementRules::collapse($times->item(0)?->textContent);
    }

    private static function hasFigureAncestor(Element $element): bool
    {
        for ($ancestor = $element->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if ($ancestor->localName === 'figure') {
                return true;
            }
        }

        return false;
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
}
