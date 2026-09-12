<?php

declare(strict_types=1);

namespace App\Service\Reader\AuthorBio;

use App\Service\Reader\BlockText;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Wraps the "about the author" furniture that trails an article — the bio, its
 * profile link, an adjacent affiliate disclosure — in a marked figure, so the
 * reader can set it apart from the body instead of running it on as prose.
 *
 * The article body is found by descending into whichever child holds the bulk
 * of the substantial paragraphs; its following siblings are the trailing
 * furniture. A link to an author-profile page (host-agnostic path segment) is
 * the signal that those siblings are a bio and not a second article section.
 */
final readonly class AuthorBioSeparator
{
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;

    public function separateIn(HTMLDocument $document): void
    {
        if ($document->body === null) {
            return;
        }

        $articleBody = $this->dominantProseBlock($document->body);
        if ($articleBody === null) {
            return;
        }

        $tail = $this->followingElements($articleBody);
        if ($tail === [] || !$this->isAuthorBioTail($tail)) {
            return;
        }

        $this->wrap($document, $tail);
    }

    private function dominantProseBlock(Element $root): ?Element
    {
        $node = $root;
        while (($denser = $this->majorityProseChild($node)) !== null) {
            $node = $denser;
        }

        return $node === $root ? null : $node;
    }

    /** The child that holds a strict majority of this node's substantial paragraphs, if any. */
    private function majorityProseChild(Element $node): ?Element
    {
        $total = $this->substantialParagraphCount($node);
        if ($total < 2) {
            return null;
        }

        return array_find(
            $this->elementChildren($node),
            fn (Element $child): bool => $this->substantialParagraphCount($child) * 2 > $total,
        );
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

    private function substantialParagraphCount(Element $element): int
    {
        $count = $this->isSubstantialParagraph($element) ? 1 : 0;
        foreach ($element->getElementsByTagName('p') as $paragraph) {
            if ($this->isSubstantialParagraph($paragraph)) {
                ++$count;
            }
        }

        return $count;
    }

    private function isSubstantialParagraph(Element $element): bool
    {
        return $element->localName === 'p'
            && mb_strlen(BlockText::collapsed($element)) >= self::SUBSTANTIAL_PROSE_LENGTH
            && !BlockText::isLinkDominated($element);
    }

    /** @return list<Element> */
    private function followingElements(Element $block): array
    {
        $elements = [];
        for ($sibling = $block->nextElementSibling; $sibling !== null; $sibling = $sibling->nextElementSibling) {
            $elements[] = $sibling;
        }

        return $elements;
    }

    /** @param list<Element> $tail */
    private function isAuthorBioTail(array $tail): bool
    {
        if (array_any($tail, fn (Element $block): bool => $this->substantialParagraphCount($block) >= 2)) {
            return false;
        }

        return array_any($tail, static fn (Element $block): bool => AuthorProfileLink::isPresentIn($block));
    }

    /** @param non-empty-list<Element> $tail */
    private function wrap(HTMLDocument $document, array $tail): void
    {
        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'reader-author-bio');
        $tail[0]->parentNode?->insertBefore($figure, $tail[0]);

        foreach ($tail as $block) {
            $figure->appendChild($block);
        }
    }
}
