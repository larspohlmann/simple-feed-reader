<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Removes hand-rolled social "share" links before readability sees them. Which
 * `<a>` counts as a share control is ShareLinkMatcher's call: a link to a share
 * endpoint carrying this page's address, or a link back to the page itself
 * carrying a share action (#627, #786).
 *
 * A share link rarely stands alone: it sits in a bar with sibling share links
 * and a "Share this article" label, often as a `<ul><li>` list. removeFrom()
 * climbs from each share link to the outermost ancestor that still holds only
 * share links and no more than a short label's worth of other text, so the
 * whole bar goes with its label. The climb sees through a plain
 * `<ul>`/`<ol>`/`<li>` wrapper — a list is structure, not content — but stops
 * at any other container (a `<div>`, a `<p>`) that is not itself link-only, so
 * a real sibling paragraph is never swept in. It also stops at
 * <main>/<article>/<body>, so it never reaches into real prose.
 */
final readonly class ShareIntentLinkRemover
{
    /** A cluster's own text (its label) stays under this many characters. */
    private const int CLUSTER_LABEL_LENGTH = 60;

    /** Elements that hold the article body; the climb never crosses into them. */
    private const array CONTENT_BOUNDARIES = ['main', 'article', 'body'];

    /** Pure list structure the climb sees through when looking for a cluster's links. */
    private const array LIST_WRAPPER_TAGS = ['ul', 'ol', 'li'];

    public function removeFrom(HTMLDocument $document): void
    {
        $matcher = ShareLinkMatcher::forPage($document);
        foreach ($this->shareClusters($document, $matcher) as $cluster) {
            $cluster->remove();
        }
    }

    /**
     * The distinct outermost share clusters, resolved before any removal so the
     * document is not mutated while it is still being walked.
     *
     * @return list<Element>
     */
    private function shareClusters(HTMLDocument $document, ShareLinkMatcher $matcher): array
    {
        $clusters = [];
        foreach ($this->shareLinks($document, $matcher) as $link) {
            $cluster = $this->outermostShareOnlyAncestor($link, $matcher);
            $clusters[spl_object_id($cluster)] = $cluster;
        }

        return array_values($clusters);
    }

    /** @return list<Element> */
    private function shareLinks(HTMLDocument $document, ShareLinkMatcher $matcher): array
    {
        $links = [];
        foreach ($document->getElementsByTagName('a') as $link) {
            if ($matcher->matches($link)) {
                $links[] = $link;
            }
        }

        return $links;
    }

    private function outermostShareOnlyAncestor(Element $link, ShareLinkMatcher $matcher): Element
    {
        $region = $link;
        while (
            ($parent = $region->parentElement) !== null
            && !in_array($parent->localName, self::CONTENT_BOUNDARIES, true)
            && $this->holdsShareControlsOnly($parent, $matcher)
        ) {
            $region = $parent;
        }

        return $region;
    }

    /**
     * True when every link reachable through the element (directly, or through
     * a plain list wrapper) is a share control and the rest of the element's
     * own text reads as a label, not as content of its own.
     */
    private function holdsShareControlsOnly(Element $element, ShareLinkMatcher $matcher): bool
    {
        $links = $this->reachableLinks($element);
        if ($links === [] || !array_all($links, static fn (Element $link): bool => $matcher->matches($link))) {
            return false;
        }

        return $this->textLengthOutsideLinks($element) <= self::CLUSTER_LABEL_LENGTH;
    }

    /**
     * The <a> children of $element, plus any reached by descending through a
     * <ul>/<ol>/<li> wrapper — list structure a share bar commonly uses. A
     * wrapper of any other kind (a <div>, a <p>) is opaque: its links do not
     * count here, so a genuine content container never gets treated as if its
     * links belonged to its parent.
     *
     * @return list<Element>
     */
    private function reachableLinks(Element $element): array
    {
        $links = [];
        foreach ($this->elementChildren($element) as $child) {
            if ($child->localName === 'a') {
                $links[] = $child;
            } elseif (in_array($child->localName, self::LIST_WRAPPER_TAGS, true)) {
                array_push($links, ...$this->reachableLinks($child));
            }
        }

        return $links;
    }

    private function textLengthOutsideLinks(Element $element): int
    {
        return mb_strlen($this->collapsedText($this->textOutsideLinks($element)));
    }

    /**
     * The element's own text minus the text of every link reachableLinks()
     * finds — recursing through the same list wrappers, so a label sitting
     * beside a <ul> is counted once, not swallowed as opaque list text.
     */
    private function textOutsideLinks(Element $element): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && $child->localName === 'a') {
                continue;
            }
            $text .= $child instanceof Element && in_array($child->localName, self::LIST_WRAPPER_TAGS, true)
                ? $this->textOutsideLinks($child)
                : $child->textContent;
        }

        return $text;
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

    private function collapsedText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
