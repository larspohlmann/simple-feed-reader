<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Slideshow\Model\ContainerSignatureModel;
use App\Service\Reader\Support\BlockText;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Removes a related-articles grid from the raw page, since readability keeps its thumbnails but strips the links and
 * headlines that identify it: a container of 3+ image cards whose headlines link to distinct articles. A container a
 * slideshow recognizer claimed stays.
 */
final readonly class RelatedTeaserGridRemover
{
    /** A container needs at least this many distinct-destination cards to be a grid. */
    private const int MIN_CARDS = 3;

    /** A card holds a teaser line; a block this long is real prose, not a card. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;

    /** Elements a card walk never crosses: the article's structural landmarks. */
    private const array LANDMARKS = ['body', 'html', 'article', 'main', 'section'];

    /** Elements that are the article itself and must never be removed as a grid. */
    private const array ARTICLE_ROOTS = ['body', 'html', 'article', 'main'];

    /**
     * @param list<ContainerSignatureModel> $slideshowContainers
     */
    public function removeFrom(HTMLDocument $document, array $slideshowContainers): void
    {
        foreach ($this->gridContainers($document) as $container) {
            if (!$this->isClaimedBySlideshow($container, $slideshowContainers)) {
                $container->remove();
            }
        }
    }

    /**
     * @param list<ContainerSignatureModel> $slideshowContainers
     */
    private function isClaimedBySlideshow(Element $container, array $slideshowContainers): bool
    {
        for ($element = $container; $element !== null; $element = $element->parentElement) {
            if ($this->anySignatureMatches($slideshowContainers, $element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ContainerSignatureModel> $signatures
     */
    private function anySignatureMatches(array $signatures, Element $element): bool
    {
        return array_any(
            $signatures,
            static fn (ContainerSignatureModel $signature): bool => $signature->matches($element),
        );
    }

    /**
     * @return list<Element>
     */
    private function gridContainers(HTMLDocument $document): array
    {
        $containers = [];
        $destinations = [];
        foreach ($this->cards($document) as [$card, $href]) {
            $container = $card->parentElement;
            if ($container === null || $this->isArticleRoot($container)) {
                continue;
            }
            $id = spl_object_id($container);
            $containers[$id] = $container;
            $destinations[$id][$href] = true;
        }

        $grids = [];
        foreach ($destinations as $id => $distinct) {
            if (count($distinct) >= self::MIN_CARDS) {
                $grids[] = $containers[$id];
            }
        }

        return $grids;
    }

    /**
     * @return list<array{0: Element, 1: string}>
     */
    private function cards(HTMLDocument $document): array
    {
        $cards = [];
        foreach ($document->querySelectorAll('h2, h3, h4') as $heading) {
            $link = $heading->querySelector('a[href]');
            $href = trim($link?->getAttribute('href') ?? '');
            if ($href === '') {
                continue;
            }
            $card = $this->cardOf($heading);
            if ($card !== null) {
                $cards[] = [$card, $href];
            }
        }

        return $cards;
    }

    private function cardOf(Element $heading): ?Element
    {
        $ancestor = $heading->parentElement;
        while ($ancestor !== null && !$this->isLandmark($ancestor)) {
            if ($ancestor->querySelector('img') !== null) {
                return $this->containsSubstantialProse($ancestor) ? null : $ancestor;
            }
            $ancestor = $ancestor->parentElement;
        }

        return null;
    }

    private function containsSubstantialProse(Element $element): bool
    {
        foreach ($element->getElementsByTagName('p') as $paragraph) {
            if (mb_strlen(BlockText::collapsed($paragraph)) >= self::SUBSTANTIAL_PROSE_LENGTH) {
                return true;
            }
        }

        return false;
    }

    private function isLandmark(Element $element): bool
    {
        return in_array($element->localName, self::LANDMARKS, true);
    }

    private function isArticleRoot(Element $element): bool
    {
        return in_array($element->localName, self::ARTICLE_ROOTS, true);
    }
}
