<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLCollection;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Removes a site header / navigation region that readability kept as article
 * content. Theme builders that assemble the masthead from scored content
 * <div>s rather than a semantic <header> — Avada/Fusion is the case that
 * prompted this — leave the logo, an un-rendered search widget and the whole
 * menu sitting in front of the real article.
 *
 * A navigation landmark (<nav>, <header>, role=navigation/banner) anchors the
 * region. From that anchor the trimmer climbs to the outermost ancestor whose
 * text is still link-dominated — the header container — and removes it whole,
 * so the logo and search widget beside the menu go with it. The climb stops at
 * the article body (<main>/<article>) and at <body>, so it never reaches into
 * real prose.
 *
 * A landmark that already sits inside <main>/<article> is in-content — an
 * article's own table of contents — and is left untouched. Runs on the shared
 * document ReaderBodyCleaner owns, before EntrySanitizer strips the roles this
 * step reads.
 *
 * A second anchor covers a masthead menu with no landmark at all: a bare
 * <ul>/<ol> of outbound links sitting before the first substantial paragraph
 * (Dissent, Democracy Now). The same link-dominated climb and content-body
 * guard apply.
 */
final readonly class NavigationChromeTrimmer
{
    /** Tag names and ARIA roles that mark an element as a navigation landmark. */
    private const array LANDMARK_TAGS = ['nav', 'header'];
    private const array LANDMARK_ROLES = ['navigation', 'banner'];

    /** Elements that hold the article body; the climb never crosses into them. */
    private const array CONTENT_BOUNDARIES = ['main', 'article', 'body'];

    /** A block whose text is link-dominated by at least this share is chrome. */
    private const float LINK_TEXT_RATIO = 0.6;

    /** A leading link list needs at least this many outbound links to be a menu. */
    private const int MENU_MIN_LINKS = 4;

    /** A paragraph at or above this length marks the article as started. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 120;

    public function trimIn(HTMLDocument $document): void
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
        $firstProse = $this->firstSubstantialParagraph($document);
        $lists = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!in_array($element->localName, ['ul', 'ol'], true)) {
                continue;
            }
            if ($this->isMenuShaped($element) && $this->precedesInDocument($element, $firstProse)) {
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

    private function firstSubstantialParagraph(HTMLDocument $document): ?Element
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$this->isProseCandidate($element)) {
                continue;
            }
            if (mb_strlen($this->collapsedText($element)) >= self::SUBSTANTIAL_PROSE_LENGTH) {
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

    private function precedesInDocument(Element $list, ?Element $firstProse): bool
    {
        if ($firstProse === null) {
            return true;
        }

        return (bool) ($firstProse->compareDocumentPosition($list) & Node::DOCUMENT_POSITION_PRECEDING);
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
        $totalLength = mb_strlen($this->collapsedText($element));
        if ($totalLength === 0) {
            return 1.0;
        }

        $linkLength = 0;
        foreach ($element->getElementsByTagName('a') as $link) {
            $linkLength += mb_strlen($this->collapsedText($link));
        }

        return $linkLength / $totalLength;
    }

    private function collapsedText(Element $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $element->textContent));
    }
}
