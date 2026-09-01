<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

final readonly class LeadingEngagementCleaner
{
    public function removeFrom(HTMLDocument $document, ?string $entryAuthor): void
    {
        if ($document->body === null) {
            return;
        }

        $root = $this->contentRoot($document->body);
        $blocks = LeadingEngagementBlocks::in($root);
        $anchor = $this->firstProseAnchor($blocks);
        if ($anchor === null) {
            return;
        }

        $leading = array_slice($blocks, 0, $anchor);
        $removedEngagement = false;
        foreach ($leading as $block) {
            if (!$this->isEngagement($block, $entryAuthor)) {
                continue;
            }

            $block->remove();
            $removedEngagement = true;
        }

        $followingByline = $blocks[$anchor + 1] ?? null;
        if (
            $removedEngagement
            && $followingByline !== null
            && $this->isDuplicateByline($followingByline, $entryAuthor)
        ) {
            $followingByline->remove();
        }

        if ($removedEngagement) {
            $this->removeRemaindersBefore($root, $blocks[$anchor]);
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

    /** @param list<Element> $blocks */
    private function firstProseAnchor(array $blocks): ?int
    {
        foreach ($blocks as $index => $block) {
            if (LeadingEngagementRules::isProse((string) $block->textContent, $this->linkTextLength($block))) {
                return $index;
            }
        }

        return null;
    }

    private function linkTextLength(Element $element): int
    {
        $length = 0;
        foreach ($element->getElementsByTagName('a') as $link) {
            $length += mb_strlen(trim((string) $link->textContent));
        }

        return $length;
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

    private function isEngagement(Element $element, ?string $entryAuthor): bool
    {
        $text = (string) $element->textContent;

        return LeadingEngagementRules::isEmojiOnly($text)
            || LeadingEngagementRules::isCounter($text)
            || LeadingEngagementBlocks::isTimeOnly($element)
            || (LeadingEngagementRules::hasAuthor($entryAuthor) && LeadingEngagementRules::isByline($text));
    }

    private function isDuplicateByline(Element $element, ?string $entryAuthor): bool
    {
        return LeadingEngagementRules::hasAuthor($entryAuthor)
            && LeadingEngagementRules::isByline((string) $element->textContent);
    }

    private function isRemainder(Element $element): bool
    {
        return $element->localName === 'hr'
            || ($this->collapsed($element->textContent) === '' && !$this->hasMedia($element));
    }

    private function hasMedia(Element $element): bool
    {
        foreach (['img', 'audio', 'video', 'iframe', 'svg'] as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return true;
            }
        }

        return false;
    }

    private function collapsed(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
