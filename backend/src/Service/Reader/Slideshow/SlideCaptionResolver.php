<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * Reads the caption that a markup carousel keeps beside each image: the slide's
 * visible text and, if the slide links somewhere, its first absolute link. One
 * place so every markup library inherits the same behaviour (#930).
 */
final readonly class SlideCaptionResolver
{
    public function resolve(Element $slide): SlideCaption
    {
        return new SlideCaption($this->visibleText($slide), $this->firstLink($slide));
    }

    private function visibleText(Element $slide): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->collectText($slide)));
    }

    /** Script and style text is not visible, so it never belongs in a caption. */
    private function collectText(Node $node): string
    {
        $text = '';
        for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling) {
            if ($child instanceof Text) {
                $text .= ' ' . $child->textContent;
            } elseif ($child instanceof Element && !$this->isHidden($child)) {
                $text .= ' ' . $this->collectText($child);
            }
        }

        return $text;
    }

    private function isHidden(Element $element): bool
    {
        $name = strtoupper($element->nodeName);

        return $name === 'SCRIPT' || $name === 'STYLE';
    }

    /** The slide can be the anchor (a link wrapping the image) or wrap one. */
    private function firstLink(Element $slide): ?string
    {
        foreach ($this->anchors($slide) as $anchor) {
            $href = $anchor->getAttribute('href');
            if ($href !== null && (str_starts_with($href, 'http://') || str_starts_with($href, 'https://'))) {
                return $href;
            }
        }

        return null;
    }

    /** @return list<Element> */
    private function anchors(Element $slide): array
    {
        $anchors = strtoupper($slide->nodeName) === 'A' ? [$slide] : [];
        foreach ($slide->querySelectorAll('a') as $anchor) {
            $anchors[] = $anchor;
        }

        return $anchors;
    }
}
