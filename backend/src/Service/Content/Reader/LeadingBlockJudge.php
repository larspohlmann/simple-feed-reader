<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Model\LeadingBlockModel;
use App\Service\Reader\Support\BlockText;
use Dom\Element;

/** The tuned verdicts on a block above the article body; LeadingEngagementBlocks only finds the blocks. */
final readonly class LeadingBlockJudge
{
    /** Tags that are article content in their own right and are never furniture. */
    private const array CONTENT_TAGS = ['figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function __construct(private LeadingEngagementRules $rules)
    {
    }

    public function isProse(LeadingBlockModel $block): bool
    {
        return $this->rules->isProse($block->text, BlockText::linkTextLength($block->element));
    }

    /** A caption, a heading or anything inside a <figure> is content, never furniture. */
    public function isProtectedContent(Element $element): bool
    {
        return \in_array($element->localName, self::CONTENT_TAGS, true)
            || self::hasFigureAncestor($element);
    }

    /**
     * A UI glyph rather than content: its URL carries the icon asset convention
     * — a path segment "icons" or an "icon" token in the file name. Matched as a
     * token so a content image like "silicon-valley.jpg" is left alone.
     */
    public function isDecorativeIcon(Element $image): bool
    {
        return preg_match('~(?:^|[^a-z])icons?(?:[^a-z]|$)~i', $image->getAttribute('src') ?? '') === 1;
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
}
