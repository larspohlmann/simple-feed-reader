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

        $furniture = new LeadingFurniture($entryAuthor);
        $root = $this->contentRoot($document->body);
        $blocks = LeadingEngagementBlocks::in($root);
        $anchor = $furniture->bodyStart($blocks);
        if ($anchor === null) {
            return;
        }

        $anchorElement = $blocks[$anchor]->element;
        $removed = $this->removeMatchedFurniture(array_slice($blocks, 0, $anchor), $furniture);
        $removed = $this->removeMastheadBadges($root, $anchorElement) || $removed;

        $followingByline = $blocks[$anchor + 1] ?? null;
        if (
            $removed
            && $followingByline !== null
            && $this->isDuplicateByline($followingByline, $entryAuthor)
        ) {
            $followingByline->element->remove();
        }

        if ($removed) {
            $this->removeRemaindersBefore($root, $anchorElement);
        }
    }

    /** @param list<LeadingBlock> $leading */
    private function removeMatchedFurniture(array $leading, LeadingFurniture $furniture): bool
    {
        $removed = false;
        foreach ($leading as $block) {
            if ($furniture->matches($block)) {
                $block->element->remove();
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * A leading <header> is the masthead; an image-only link inside it is a promo
     * badge, not a poster — a poster never sits in a <header>, so #627 is left be.
     */
    private function removeMastheadBadges(Element $root, Element $anchor): bool
    {
        $removed = false;
        foreach ($root->getElementsByTagName('header') as $header) {
            if (!$this->precedes($header, $anchor)) {
                continue;
            }
            foreach (iterator_to_array($header->getElementsByTagName('a')) as $link) {
                if ($this->isBadgeLink($link)) {
                    $link->remove();
                    $removed = true;
                }
            }
        }

        return $removed;
    }

    private function isBadgeLink(Element $link): bool
    {
        return $link->getElementsByTagName('img')->length >= 1
            && LeadingEngagementRules::collapse($link->textContent) === '';
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
            && !LeadingEngagementBlocks::isProse($block)
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
