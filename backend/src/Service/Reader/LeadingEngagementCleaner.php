<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

final readonly class LeadingEngagementCleaner
{
    private const array MEDIA_TAGS = ['img', 'audio', 'video', 'iframe', 'svg'];

    public function removeFrom(HTMLDocument $document, ?string $entryAuthor): void
    {
        if ($document->body === null) {
            return;
        }

        $root = $this->contentRoot($document->body);
        $blocks = LeadingEngagementBlocks::in($root);
        $anchor = $this->bodyStart($blocks, $entryAuthor);
        if ($anchor === null) {
            return;
        }

        $leading = array_slice($blocks, 0, $anchor);
        $removedFurniture = false;
        foreach ($leading as $block) {
            if (!LeadingEngagementBlocks::isFurniture($block, $entryAuthor)) {
                continue;
            }

            $block->element->remove();
            $removedFurniture = true;
        }

        $followingByline = $blocks[$anchor + 1] ?? null;
        if (
            $removedFurniture
            && $followingByline !== null
            && $this->isDuplicateByline($followingByline, $entryAuthor)
        ) {
            $followingByline->element->remove();
        }

        if ($removedFurniture) {
            $this->removeRemaindersBefore($root, $blocks[$anchor]->element);
        }
    }

    private function contentRoot(Element $body): Element
    {
        $root = $body;
        while (($child = $this->soleContainerChild($root)) !== null) {
            $root = $child;
        }

        return $root;
    }

    private function soleContainerChild(Element $element): ?Element
    {
        $children = $this->elementChildren($element);

        return count($children) === 1
            && trim((string) $element->firstChild?->textContent) === trim((string) $element->textContent)
            && in_array($children[0]->localName, ['div', 'article', 'section', 'main'], true)
            ? $children[0]
            : null;
    }

    /** @return list<Element> */
    private function elementChildren(Element $element): array
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * The block the article body starts at. A single leading standfirst may sit
     * above masthead furniture, so when furniture still appears between the first
     * prose block and the next one, the first is a standfirst and the body starts
     * at the next. At most one such skip keeps the scan out of the body.
     *
     * @param list<LeadingBlock> $blocks
     */
    private function bodyStart(array $blocks, ?string $entryAuthor): ?int
    {
        $firstProse = $this->firstProseAnchor($blocks);
        if ($firstProse === null) {
            return null;
        }

        $nextProse = $this->nextProseAfter($blocks, $firstProse);
        if ($nextProse !== null && $this->furnitureBetween($blocks, $firstProse, $nextProse, $entryAuthor)) {
            return $nextProse;
        }

        return $firstProse;
    }

    /** @param list<LeadingBlock> $blocks */
    private function firstProseAnchor(array $blocks): ?int
    {
        foreach ($blocks as $index => $block) {
            if ($this->isProse($block)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<LeadingBlock> $blocks */
    private function nextProseAfter(array $blocks, int $from): ?int
    {
        foreach ($blocks as $index => $block) {
            if ($index > $from && $this->isProse($block)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<LeadingBlock> $blocks */
    private function furnitureBetween(array $blocks, int $from, int $to, ?string $entryAuthor): bool
    {
        for ($index = $from + 1; $index < $to; $index++) {
            if (LeadingEngagementBlocks::isFurniture($blocks[$index], $entryAuthor)) {
                return true;
            }
        }

        return false;
    }

    private function isProse(LeadingBlock $block): bool
    {
        return LeadingEngagementRules::isProse($block->text, LeadingEngagementBlocks::linkTextLength($block->element));
    }

    private function removeRemaindersBefore(Element $element, Element $anchor): void
    {
        foreach ($this->elementChildren($element) as $child) {
            $this->removeRemaindersBefore($child, $anchor);
        }

        if ($element->parentNode !== null && $this->precedes($element, $anchor) && $this->isRemainder($element)) {
            $element->remove();
        }
    }

    private function precedes(Element $element, Element $anchor): bool
    {
        return ($element->compareDocumentPosition($anchor) & Node::DOCUMENT_POSITION_FOLLOWING) !== 0;
    }

    /**
     * A byline block sitting right after the first prose block still duplicates
     * the reader meta line. Guard on non-prose so a real paragraph that merely
     * opens with "Von"/"By" is never mistaken for the byline and deleted.
     */
    private function isDuplicateByline(LeadingBlock $block, ?string $entryAuthor): bool
    {
        return LeadingEngagementRules::hasAuthor($entryAuthor)
            && !$this->isProse($block)
            && LeadingEngagementRules::isByline($block->text);
    }

    private function isRemainder(Element $element): bool
    {
        return $element->localName === 'hr'
            || (LeadingEngagementRules::collapse($element->textContent) === '' && !$this->hasMedia($element));
    }

    /** The element itself or any descendant: a bare <img> has no text and no children. */
    private function hasMedia(Element $element): bool
    {
        return in_array($element->localName, self::MEDIA_TAGS, true)
            || array_any(
                self::MEDIA_TAGS,
                static fn (string $tag): bool => $element->getElementsByTagName($tag)->length > 0,
            );
    }
}
