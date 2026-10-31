<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Support\BlockText;
use Dom\Element;

final readonly class LinkListDetector
{
    /** A block whose link text is at least this share of its text is a link list. */
    private const float LINK_TEXT_RATIO = 0.6;

    public const int MIN_LINKS_FOR_LIST = 3;

    public function isLinkList(Element $block): bool
    {
        return $block->getElementsByTagName('a')->length >= self::MIN_LINKS_FOR_LIST && $this->isLinkDominated($block);
    }

    public function isLinkDominated(Element $block): bool
    {
        $blockTextLength = mb_strlen(BlockText::collapsed($block));
        if ($blockTextLength === 0) {
            return false;
        }

        return BlockText::linkTextLength($block) / $blockTextLength >= self::LINK_TEXT_RATIO;
    }
}
