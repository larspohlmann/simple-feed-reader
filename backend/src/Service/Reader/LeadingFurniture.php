<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Decides which leading blocks are article-head furniture rather than content,
 * and where — past an optional standfirst — the body begins. Holds the entry
 * author for the pass so no method has to forward it. The shapes are the
 * masthead readability keeps: breadcrumbs, section labels, kickers, bare
 * separators, emoji rows, counters, date and reading-time stamps and a byline.
 */
final readonly class LeadingFurniture
{
    private const array HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function __construct(private ?string $entryAuthor)
    {
    }

    /**
     * The block index the article body starts at. A single leading standfirst may
     * sit above masthead furniture, so when furniture still appears between the
     * first prose block and the next one, the first is a standfirst and the body
     * starts at the next. At most one such skip keeps the scan out of the body.
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
        if ($block->element->localName === 'figcaption') {
            return false;
        }

        return $this->isNavigationalChrome($block)
            || $this->isEngagementMeta($block);
    }

    /** Breadcrumbs, section labels, kickers and bare separators. */
    private function isNavigationalChrome(LeadingBlock $block): bool
    {
        $linkTextLength = LeadingEngagementBlocks::linkTextLength($block->element);

        return LeadingEngagementRules::isSeparatorOnly($block->text)
            || LeadingEngagementRules::isNavigationLabel($block->text, $linkTextLength)
            || ($this->isKicker($block, $linkTextLength));
    }

    /**
     * A heading is a real headline or subheading, never a kicker eyebrow, so the
     * kicker shape is not allowed to swallow a short leading <h1>-<h6>. A
     * duplicate title is dropped by LeadingTitleRemover on an exact match instead.
     */
    private function isKicker(LeadingBlock $block, int $linkTextLength): bool
    {
        return !in_array($block->element->localName, self::HEADING_TAGS, true)
            && LeadingEngagementRules::isKicker($block->text, $linkTextLength);
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
        foreach ($blocks as $index => $block) {
            if ($index >= $from && LeadingEngagementBlocks::isProse($block)) {
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
