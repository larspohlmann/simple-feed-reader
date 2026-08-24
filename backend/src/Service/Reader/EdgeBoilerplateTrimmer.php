<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use Dom\Element;

/**
 * Removes boilerplate blocks that survive readability at the head or tail of an
 * extracted article — related-post grids, newsletter prompts, comment blocks
 * (#582). It never touches the middle of the article: the same "related-links"
 * shape in the body is almost always a real subheading, so position is the
 * first gate.
 *
 * Runs on the readability output BEFORE EntrySanitizer, because the sanitizer
 * strips the class attributes and <form> elements this step reads as signals.
 *
 * Conservative: a block is removed only when two or more independent objective
 * signals agree (see shouldRemove); an ambiguous block, an undefined edge or an
 * unparsable body all leave the input unchanged.
 */
final readonly class EdgeBoilerplateTrimmer
{
    /** Characters of text that mark a block as a real, substantial paragraph. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;

    /** The outer share of top-level blocks, at each end, that may count as edge. */
    private const float EDGE_FRACTION = 0.25;

    public function trim(string $bodyHtml): string
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        if ($document === null || $document->body === null) {
            return $bodyHtml;
        }

        $blocks = $this->topLevelBlocks($this->contentRoot($document->body));
        foreach ($this->edgeIndexes($blocks) as $index) {
            if ($this->shouldRemove($blocks[$index])) {
                $blocks[$index]->remove();
            }
        }

        return $document->saveHtml();
    }

    /**
     * The element that directly holds the article's blocks. Readability wraps
     * its output in one container; descend through single-element container
     * wrappers so the block list is the real one, not a one-item list holding
     * the wrapper.
     */
    private function contentRoot(Element $body): Element
    {
        $root = $body;
        while (($onlyChild = $this->soleContainerChild($root)) !== null) {
            $root = $onlyChild;
        }

        return $root;
    }

    private function soleContainerChild(Element $element): ?Element
    {
        $onlyElement = null;
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                if ($onlyElement !== null) {
                    return null;
                }
                $onlyElement = $child;
            } elseif (trim((string) $child->textContent) !== '') {
                return null;
            }
        }

        return $onlyElement !== null
            && in_array($onlyElement->localName, ['div', 'article', 'section', 'main'], true)
            ? $onlyElement
            : null;
    }

    /**
     * @return list<Element> the element children of the content root, in order
     */
    private function topLevelBlocks(Element $root): array
    {
        $blocks = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof Element) {
                $blocks[] = $child;
            }
        }

        return $blocks;
    }

    /**
     * The indexes of blocks that sit in the leading or trailing edge region.
     * Leading edge = blocks before the first substantial paragraph; trailing
     * edge = blocks after the last one; each capped at EDGE_FRACTION of the
     * block count so a short article cannot have its whole body classed as edge.
     * An article with no substantial paragraph has no defined edge.
     *
     * @param list<Element> $blocks
     * @return list<int>
     */
    private function edgeIndexes(array $blocks): array
    {
        $count = count($blocks);
        $substantial = $this->substantialIndexes($blocks);
        if ($substantial === []) {
            return [];
        }

        [$leadingEnd, $trailingStart] = $this->edgeBounds($count, $substantial);

        $leading = $leadingEnd > 0 ? range(0, $leadingEnd - 1) : [];
        $trailing = $trailingStart <= $count - 1 ? range($trailingStart, $count - 1) : [];

        return array_merge($leading, $trailing);
    }

    /**
     * The last index of the leading edge region and the first index of the
     * trailing edge region, both capped at EDGE_FRACTION of the block count.
     *
     * @param non-empty-list<int> $substantial
     * @return array{0: int, 1: int}
     */
    private function edgeBounds(int $count, array $substantial): array
    {
        $cap = (int) floor(self::EDGE_FRACTION * $count);
        $leadingEnd = min($substantial[0], $cap);
        $trailingStart = max($substantial[array_key_last($substantial)] + 1, $count - $cap);

        return [$leadingEnd, $trailingStart];
    }

    /**
     * @param list<Element> $blocks
     * @return list<int>
     */
    private function substantialIndexes(array $blocks): array
    {
        $indexes = [];
        foreach ($blocks as $index => $block) {
            if (mb_strlen(trim((string) $block->textContent)) >= self::SUBSTANTIAL_PROSE_LENGTH) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /** Task 5 fills this in with the multi-signal rule. */
    private function shouldRemove(Element $block): bool
    {
        return false;
    }
}
