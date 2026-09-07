<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Decides which leading blocks are article-head furniture rather than content,
 * and where the body begins past an optional standfirst. Holds the entry author
 * for the pass so no method forwards it.
 */
final readonly class LeadingFurniture
{
    public function __construct(private ?string $entryAuthor)
    {
    }

    /**
     * The block index the article body starts at. Exactly one leading standfirst
     * is allowed above the masthead: when furniture sits between the first prose
     * block and the next, the body starts at the next; otherwise at the first.
     *
     * @param list<LeadingBlock> $blocks
     */
    public function bodyStart(array $blocks): ?int
    {
        $firstProse = $this->firstProseIndex($blocks, 0);
        if ($firstProse === null) {
            return null;
        }

        $nextProse = $this->firstProseIndex($blocks, $firstProse + 1);
        if ($nextProse !== null && $this->furnitureBetween($blocks, $firstProse, $nextProse)) {
            return $nextProse;
        }

        return $firstProse;
    }

    public function matches(LeadingBlock $block): bool
    {
        if (LeadingEngagementBlocks::isProtectedContent($block->element)) {
            return false;
        }

        return $this->isNavigationalChrome($block)
            || $this->isEngagementMeta($block);
    }

    /** Breadcrumbs, section labels, kickers and bare separators. */
    private function isNavigationalChrome(LeadingBlock $block): bool
    {
        $linkTextLength = BlockText::linkTextLength($block->element);

        return LeadingEngagementRules::isSeparatorOnly($block->text)
            || LeadingEngagementRules::isNavigationLabel($block->text, $linkTextLength)
            || LeadingEngagementRules::isKicker($block->text, $linkTextLength);
    }

    /** Emoji rows, engagement counters, date and reading-time stamps and a duplicate byline. */
    private function isEngagementMeta(LeadingBlock $block): bool
    {
        return LeadingEngagementRules::isEmojiOnly($block->text)
            || LeadingEngagementRules::isCounter($block->text)
            || LeadingEngagementRules::isBareNumber($block->text)
            || LeadingEngagementRules::isReadingTime($block->text)
            || LeadingEngagementRules::isDateLine($block->text)
            || LeadingEngagementBlocks::isTimeOnly($block->element)
            || ($this->hasAuthor() && LeadingEngagementRules::isByline($block->text));
    }

    /** @param list<LeadingBlock> $blocks */
    private function firstProseIndex(array $blocks, int $from): ?int
    {
        $count = count($blocks);
        for ($index = $from; $index < $count; $index++) {
            if (LeadingEngagementBlocks::isProse($blocks[$index])) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<LeadingBlock> $blocks */
    private function furnitureBetween(array $blocks, int $from, int $to): bool
    {
        for ($index = $from + 1; $index < $to; $index++) {
            if ($this->matches($blocks[$index])) {
                return true;
            }
        }

        return false;
    }

    private function hasAuthor(): bool
    {
        return LeadingEngagementRules::hasAuthor($this->entryAuthor);
    }
}
