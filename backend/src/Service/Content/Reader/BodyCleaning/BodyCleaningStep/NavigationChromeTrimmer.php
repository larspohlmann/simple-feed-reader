<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Support\BlockText;
use Dom\Element;
use Dom\HTMLCollection;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Removes a masthead readability kept. From each navigation landmark outside <main>/<article>, and each leading list
 * of outbound links, it climbs to the outermost link-dominated ancestor and removes it, never crossing into <main>,
 * <article> or <body>.
 */
final readonly class NavigationChromeTrimmer implements BodyCleaningStepInterface
{
    /** Tag names and ARIA roles that mark an element as a navigation landmark. */
    private const array LANDMARK_TAGS = ['nav', 'header'];
    private const array LANDMARK_ROLES = ['navigation', 'banner'];

    /** Elements that hold the article body; the climb never crosses into them. */
    private const array CONTENT_BOUNDARIES = ['main', 'article', 'body'];

    /** A block whose text is link-dominated by at least this share is chrome. */
    private const float LINK_TEXT_RATIO = 0.6;

    /** A leading link list needs at least this many outbound links to be a menu. */
    private const int MENU_MIN_LINKS = 3;

    /** A paragraph at or above this length marks the article as started. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 120;

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->trimIn($pass->document);
    }

    private function trimIn(HTMLDocument $document): void
    {
        if ($document->body === null) {
            return;
        }

        foreach ($this->chromeRegions($document) as $region) {
            $region->remove();
        }
    }

    /**
     * The distinct outermost chrome containers, resolved before any removal so
     * the document is not mutated while it is still being walked.
     *
     * @return list<Element>
     */
    private function chromeRegions(HTMLDocument $document): array
    {
        $regions = [];
        foreach ([...$this->landmarkAnchors($document), ...$this->leadingMenuLists($document)] as $anchor) {
            $region = $this->outermostLinkDominatedAncestor($anchor);
            $regions[spl_object_id($region)] = $region;
        }

        return array_values($regions);
    }

    /** @return list<Element> */
    private function landmarkAnchors(HTMLDocument $document): array
    {
        $anchors = [];
        foreach ($this->navigationLandmarks($document) as $landmark) {
            if (!$this->sitsInsideArticleBody($landmark)) {
                $anchors[] = $landmark;
            }
        }

        return $anchors;
    }

    /** @return list<Element> */
    private function navigationLandmarks(HTMLDocument $document): array
    {
        $landmarks = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($this->isNavigationLandmark($element)) {
                $landmarks[] = $element;
            }
        }

        return $landmarks;
    }

    /** @return list<Element> */
    private function leadingMenuLists(HTMLDocument $document): array
    {
        $anchor = $this->leadingAnchor($document);
        $lists = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!in_array($element->localName, ['ul', 'ol'], true)) {
                continue;
            }
            if ($this->isMenuShaped($element) && $this->precedesInDocument($element, $anchor)) {
                $lists[] = $element;
            }
        }

        return $lists;
    }

    private function isMenuShaped(Element $list): bool
    {
        if ($this->sitsInsideArticleBody($list)) {
            return false;
        }
        if ($this->linkTextRatio($list) < self::LINK_TEXT_RATIO) {
            return false;
        }

        $links = $list->getElementsByTagName('a');
        if ($links->length < self::MENU_MIN_LINKS) {
            return false;
        }

        return $this->everyLinkLeavesThePage($links);
    }

    private function everyLinkLeavesThePage(HTMLCollection $links): bool
    {
        foreach ($links as $link) {
            $href = $link->getAttribute('href') ?? '';
            if ($href === '' || str_starts_with($href, '#')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The element a menu list must precede to count as leading chrome. Falls back
     * to the first prose of any length when none is substantial, so a trailing
     * link list on a prose-thin post is not mistaken for a masthead.
     */
    private function leadingAnchor(HTMLDocument $document): ?Element
    {
        return $this->firstProseCandidate($document, self::SUBSTANTIAL_PROSE_LENGTH)
            ?? $this->firstProseCandidate($document, 0);
    }

    private function firstProseCandidate(HTMLDocument $document, int $minLength): ?Element
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$this->isProseCandidate($element)) {
                continue;
            }
            if (mb_strlen(BlockText::collapsed($element)) >= $minLength) {
                return $element;
            }
        }

        return null;
    }

    private function isProseCandidate(Element $element): bool
    {
        $proseTags = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

        return in_array($element->localName, $proseTags, true)
            && $this->linkTextRatio($element) < self::LINK_TEXT_RATIO;
    }

    private function precedesInDocument(Element $list, ?Element $anchor): bool
    {
        if ($anchor === null) {
            return true;
        }

        return (bool) ($anchor->compareDocumentPosition($list) & Node::DOCUMENT_POSITION_PRECEDING);
    }

    private function isNavigationLandmark(Element $element): bool
    {
        return in_array($element->localName, self::LANDMARK_TAGS, true)
            || in_array(strtolower($element->getAttribute('role') ?? ''), self::LANDMARK_ROLES, true);
    }

    private function sitsInsideArticleBody(Element $element): bool
    {
        for ($ancestor = $element->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if (in_array($ancestor->localName, ['main', 'article'], true)) {
                return true;
            }
        }

        return false;
    }

    private function outermostLinkDominatedAncestor(Element $landmark): Element
    {
        $region = $landmark;
        while (
            ($parent = $region->parentElement) !== null
            && !in_array($parent->localName, self::CONTENT_BOUNDARIES, true)
            && $this->linkTextRatio($parent) >= self::LINK_TEXT_RATIO
        ) {
            $region = $parent;
        }

        return $region;
    }

    private function linkTextRatio(Element $element): float
    {
        $totalLength = mb_strlen(BlockText::collapsed($element));
        if ($totalLength === 0) {
            return 1.0;
        }

        return BlockText::linkTextLength($element) / $totalLength;
    }
}
