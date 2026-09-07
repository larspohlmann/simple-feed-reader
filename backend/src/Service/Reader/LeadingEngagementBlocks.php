<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;

final class LeadingEngagementBlocks
{
    private const array BLOCK_TAGS = [
        'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figcaption', 'div', 'address', 'time',
    ];

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

    /** A leading block that is masthead furniture, not article content. */
    public static function isFurniture(LeadingBlock $block, ?string $entryAuthor): bool
    {
        if ($block->element->localName === 'figcaption') {
            return false;
        }

        return self::isNavigationalChrome($block)
            || self::isEngagementMeta($block, $entryAuthor);
    }

    /** Breadcrumbs, section labels, kickers, bare separators and icon-label buttons. */
    private static function isNavigationalChrome(LeadingBlock $block): bool
    {
        $linkTextLength = self::linkTextLength($block->element);

        return LeadingEngagementRules::isSeparatorOnly($block->text)
            || LeadingEngagementRules::isNavigationLabel($block->text, $linkTextLength)
            || LeadingEngagementRules::isKicker($block->text, $linkTextLength);
    }

    /** Emoji rows, engagement counters, date and reading-time stamps and a duplicate byline. */
    private static function isEngagementMeta(LeadingBlock $block, ?string $entryAuthor): bool
    {
        return LeadingEngagementRules::isEmojiOnly($block->text)
            || LeadingEngagementRules::isCounter($block->text)
            || LeadingEngagementRules::isBareNumber($block->text)
            || LeadingEngagementRules::isReadingTime($block->text)
            || LeadingEngagementRules::isDateLine($block->text)
            || self::isTimeOnly($block->element)
            || (LeadingEngagementRules::hasAuthor($entryAuthor) && LeadingEngagementRules::isByline($block->text));
    }

    public static function linkTextLength(Element $element): int
    {
        $length = 0;
        foreach ($element->getElementsByTagName('a') as $link) {
            $length += mb_strlen(LeadingEngagementRules::collapse($link->textContent));
        }

        return $length;
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
