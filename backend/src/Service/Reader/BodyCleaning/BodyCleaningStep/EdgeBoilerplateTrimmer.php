<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\LinkListDetector;
use App\Service\Reader\Support\BlockText;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;

/**
 * Removes the blocks BoilerplateVerdict condemns before the first or after the last substantial paragraph, never
 * between them: mid-article the same shape is almost always a real subheading.
 */
final readonly class EdgeBoilerplateTrimmer implements BodyCleaningStepInterface
{
    /** Characters of text that mark a block as a real, substantial paragraph. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;

    public function __construct(
        private BoilerplateVerdict $verdict,
        private LinkListDetector $linkLists,
    ) {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->trimIn($pass->document);
    }

    private function trimIn(HTMLDocument $document): void
    {
        if ($document->body === null) {
            return;
        }

        $blocks = $this->topLevelBlocks($this->contentRoot($document->body));
        $this->removeBoilerplateBlocks($blocks, $this->edgeIndexes($blocks));
    }

    /**
     * @param list<Element> $blocks
     * @param list<int> $edgeIndexes
     */
    private function removeBoilerplateBlocks(array $blocks, array $edgeIndexes): void
    {
        foreach ($edgeIndexes as $index) {
            if ($this->verdict->condemns($blocks[$index])) {
                $blocks[$index]->remove();
            }
        }
    }

    /**
     * The element holding the article's blocks, below the single-child containers readability wraps its output in.
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
            } elseif ($child instanceof Text && trim($child->data) !== '') {
                return null;
            }
        }

        return $onlyElement !== null
            && in_array($onlyElement->localName, ['div', 'article', 'section', 'main'], true)
            ? $onlyElement
            : null;
    }

    /** @return list<Element> */
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
     * The blocks before the first substantial paragraph and after the last; none when there is no such paragraph.
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

        [$leadingEnd, $trailingStart] = $this->edgeBounds($substantial);

        $leading = $leadingEnd > 0 ? range(0, $leadingEnd - 1) : [];
        $trailing = $trailingStart <= $count - 1 ? range($trailingStart, $count - 1) : [];

        return array_merge($leading, $trailing);
    }

    /**
     * The leading edge's exclusive end and the trailing edge's first index.
     *
     * @param non-empty-list<int> $substantial
     * @return array{0: int, 1: int}
     */
    private function edgeBounds(array $substantial): array
    {
        $leadingEnd = $substantial[0];
        $trailingStart = $substantial[array_key_last($substantial)] + 1;

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
            if ($this->isSubstantialProse($block)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Prose long enough to anchor an edge. A link-dominated block of any length
     * is a list, not prose, so a teaser carousel cannot shield itself (#779).
     */
    private function isSubstantialProse(Element $block): bool
    {
        return mb_strlen(BlockText::collapsed($block)) >= self::SUBSTANTIAL_PROSE_LENGTH
            && !$this->linkLists->isLinkDominated($block);
    }
}
