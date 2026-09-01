<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

final readonly class LeadingEngagementCleaner
{
    public function removeFrom(HTMLDocument $document, ?string $entryAuthor): void
    {
        if ($document->body === null) {
            return;
        }

        $blocks = $this->elementChildren($this->contentRoot($document->body));
        $anchor = $this->firstProseAnchor($blocks);
        if ($anchor === null) {
            return;
        }

        $leading = array_slice($blocks, 0, $anchor);
        $removedEngagement = false;
        foreach ($leading as $block) {
            $removed = $this->removeEngagementFrom($block, $this->hasAuthor($entryAuthor));
            $removedEngagement = $removed || $removedEngagement;
        }

        if ($removedEngagement) {
            $this->removeRemainders($leading);
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

    private function removeEngagementFrom(Element $element, bool $hasAuthor): bool
    {
        if ($this->isEngagement($element, $hasAuthor)) {
            $element->remove();

            return true;
        }

        $removedChild = false;
        foreach ($this->elementChildren($element) as $child) {
            $removedChild = $this->removeEngagementFrom($child, $hasAuthor) || $removedChild;
        }

        if ($removedChild && $this->isRemainder($element)) {
            $element->remove();
        }

        return $removedChild;
    }

    /** @param list<Element> $elements */
    private function removeRemainders(array $elements): void
    {
        foreach ($elements as $element) {
            $this->removeRemaindersFrom($element);
        }
    }

    private function removeRemaindersFrom(Element $element): void
    {
        foreach ($this->elementChildren($element) as $child) {
            $this->removeRemaindersFrom($child);
        }

        if ($element->parentNode !== null && $this->isRemainder($element)) {
            $element->remove();
        }
    }

    private function isEngagement(Element $element, bool $hasAuthor): bool
    {
        $text = (string) $element->textContent;

        return LeadingEngagementRules::isEmojiOnly($text)
            || LeadingEngagementRules::isCounter($text)
            || $this->isTimeOnly($element)
            || ($hasAuthor && LeadingEngagementRules::isByline($text));
    }

    private function isTimeOnly(Element $element): bool
    {
        $times = $element->getElementsByTagName('time');

        return $times->length === 1
            && $this->collapsed($element->textContent) === $this->collapsed($times->item(0)?->textContent);
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

    private function hasAuthor(?string $entryAuthor): bool
    {
        return $entryAuthor !== null && trim($entryAuthor) !== '';
    }

    private function collapsed(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
