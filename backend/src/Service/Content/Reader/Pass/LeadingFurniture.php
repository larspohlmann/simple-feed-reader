<?php

declare(strict_types=1);

namespace App\Service\Reader\Pass;

use App\Service\Reader\DateLineRecognizer;
use App\Service\Reader\LeadingBlockJudge;
use App\Service\Reader\LeadingEngagementRules;
use App\Service\Reader\Model\LeadingBlockModel;
use App\Service\Reader\Support\BlockText;
use App\Service\Reader\Support\LeadingEngagementBlocks;

/**
 * Decides which leading blocks are article-head furniture rather than content,
 * and where the body begins past an optional standfirst. Holds the entry author
 * for the pass so no method forwards it.
 */
final readonly class LeadingFurniture
{
    public function __construct(
        private ?string $entryAuthor,
        private DateLineRecognizer $dateLines,
        private LeadingBlockJudge $judge,
        private LeadingEngagementRules $rules,
    ) {
    }

    /**
     * The block index the article body starts at. Exactly one leading standfirst
     * is allowed above the masthead: when furniture sits between the first prose
     * block and the next, the body starts at the next; otherwise at the first.
     *
     * @param list<LeadingBlockModel> $blocks
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

    public function matches(LeadingBlockModel $block): bool
    {
        if ($this->judge->isProtectedContent($block->element)) {
            return false;
        }

        return $this->isNavigationalChrome($block)
            || $this->isEngagementMeta($block);
    }

    /** Breadcrumbs, section labels, kickers and bare separators. */
    private function isNavigationalChrome(LeadingBlockModel $block): bool
    {
        $linkTextLength = BlockText::linkTextLength($block->element);

        return $this->rules->isSeparatorOnly($block->text)
            || $this->rules->isNavigationLabel($block->text, $linkTextLength)
            || $this->rules->isKicker($block->text, $linkTextLength);
    }

    /** Emoji rows, engagement counters, date and reading-time stamps and a duplicate byline. */
    private function isEngagementMeta(LeadingBlockModel $block): bool
    {
        return $this->rules->isEmojiOnly($block->text)
            || $this->rules->isCounter($block->text)
            || $this->rules->isBareNumber($block->text)
            || $this->rules->isReadingTime($block->text)
            || $this->dateLines->isDateLine($block->text)
            || LeadingEngagementBlocks::isTimeOnly($block->element)
            || ($this->hasAuthor() && $this->rules->isByline($block->text));
    }

    /** @param list<LeadingBlockModel> $blocks */
    private function firstProseIndex(array $blocks, int $from): ?int
    {
        $count = count($blocks);
        for ($index = $from; $index < $count; $index++) {
            if ($this->judge->isProse($blocks[$index])) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<LeadingBlockModel> $blocks */
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
        return $this->rules->hasAuthor($this->entryAuthor);
    }
}
