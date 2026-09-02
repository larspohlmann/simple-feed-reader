<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;

/**
 * The two text measurements the edge trimmer reads off a block, both taken on
 * whitespace-collapsed text: indentation between list items is markup, not
 * content, and left in it dilutes a teaser list's link share below the bar (#779).
 */
final class BlockText
{
    /** A block whose link text is at least this share of its text is a link list. */
    private const float LINK_TEXT_RATIO = 0.6;

    public static function collapsed(Element $element): string
    {
        return LeadingEngagementRules::collapse((string) $element->textContent);
    }

    public static function isLinkDominated(Element $block): bool
    {
        $blockTextLength = mb_strlen(self::collapsed($block));
        if ($blockTextLength === 0) {
            return false;
        }

        $linkTextLength = 0;
        foreach ($block->getElementsByTagName('a') as $link) {
            $linkTextLength += mb_strlen(self::collapsed($link));
        }

        return $linkTextLength / $blockTextLength >= self::LINK_TEXT_RATIO;
    }
}
