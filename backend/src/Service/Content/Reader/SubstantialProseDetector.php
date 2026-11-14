<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Support\BlockText;
use Dom\Element;

/**
 * Prose long enough to anchor an article's edge or mark its body. A link-dominated block of any length is a list,
 * not prose, so a teaser carousel cannot shield itself (#779).
 */
final readonly class SubstantialProseDetector
{
    private const int MIN_LENGTH = 200;

    public function __construct(private LinkListDetector $linkLists)
    {
    }

    public function isSubstantial(Element $block): bool
    {
        return mb_strlen(BlockText::collapsed($block)) >= self::MIN_LENGTH
            && !$this->linkLists->isLinkDominated($block);
    }
}
