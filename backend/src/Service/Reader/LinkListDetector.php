<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Support\BlockText;
use Dom\Element;

final readonly class LinkListDetector
{
    /** A block whose link text is at least this share of its text is a link list. */
    private const float LINK_TEXT_RATIO = 0.6;

    public function isLinkDominated(Element $block): bool
    {
        $blockTextLength = mb_strlen(BlockText::collapsed($block));
        if ($blockTextLength === 0) {
            return false;
        }

        return BlockText::linkTextLength($block) / $blockTextLength >= self::LINK_TEXT_RATIO;
    }
}
