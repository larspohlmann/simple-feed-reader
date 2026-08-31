<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Removes hand-rolled social "share" links before readability sees them: an
 * `<a>` pointing at a known share endpoint (a sharer/intent URL, or a
 * `mailto:`) whose query string carries this page's own address. A link to a
 * share host that carries no page URL — POLITICO's WhatsApp "message the
 * hosts" contact link is the case that motivated the distinction — is
 * editorial, not a control, and stays.
 *
 * A share link rarely stands alone: it sits in a bar with sibling share links
 * and a "Share this article" label, often as a `<ul><li>` list. removeFrom()
 * climbs from each share intent link to the outermost ancestor that still
 * holds only share-intent links and no more than a short label's worth of
 * other text, so the whole bar goes with its label. The climb sees through a
 * plain `<ul>`/`<ol>`/`<li>` wrapper — a list is structure, not content — but
 * stops at any other container (a `<div>`, a `<p>`) that is not itself
 * link-only, so a real sibling paragraph is never swept in. It also stops at
 * <main>/<article>/<body>, so it never reaches into real prose.
 */
final readonly class ShareIntentLinkRemover
{
    /**
     * Host (minus `www.`) plus path prefixes that identify a share endpoint.
     * `mailto:` is checked separately — it carries no host at all.
     */
    private const array SHARE_ENDPOINTS = [
        'facebook.com/sharer', 'facebook.com/share.php', 'facebook.com/dialog/',
        'x.com/intent', 'twitter.com/intent', 'bsky.app/intent', 'threads.net/intent',
        'linkedin.com/sharearticle', 'linkedin.com/sharing/share-offsite',
        'reddit.com/submit', 'pinterest.com/pin/create', 'tumblr.com/share',
        'tumblr.com/widgets/share', 'vk.com/share.php', 'xing.com/spi/shares/new',
        'getpocket.com/edit', 'getpocket.com/save', 'flipboard.com/bookmarklet/popup',
        'api.whatsapp.com/send', 'wa.me/', 't.me/share', 'telegram.me/share',
    ];

    /** A query value that is itself an address — http(s), plain or percent-encoded. */
    private const string SHARED_ADDRESS_PATTERN = '#https?(://|%3a%2f%2f)#i';

    /** A cluster's own text (its label) stays under this many characters. */
    private const int CLUSTER_LABEL_LENGTH = 60;

    /** Elements that hold the article body; the climb never crosses into them. */
    private const array CONTENT_BOUNDARIES = ['main', 'article', 'body'];

    /** Pure list structure the climb sees through when looking for a cluster's links. */
    private const array LIST_WRAPPER_TAGS = ['ul', 'ol', 'li'];

    public function removeFrom(HTMLDocument $document): void
    {
        foreach ($this->shareIntentClusters($document) as $cluster) {
            $cluster->remove();
        }
    }

    /**
     * The distinct outermost share clusters, resolved before any removal so the
     * document is not mutated while it is still being walked.
     *
     * @return list<Element>
     */
    private function shareIntentClusters(HTMLDocument $document): array
    {
        $clusters = [];
        foreach ($this->shareIntentLinks($document) as $link) {
            $cluster = $this->outermostShareOnlyAncestor($link);
            $clusters[spl_object_id($cluster)] = $cluster;
        }

        return array_values($clusters);
    }

    /** @return list<Element> */
    private function shareIntentLinks(HTMLDocument $document): array
    {
        $links = [];
        foreach ($document->getElementsByTagName('a') as $link) {
            if ($this->isShareIntent($link)) {
                $links[] = $link;
            }
        }

        return $links;
    }

    private function outermostShareOnlyAncestor(Element $link): Element
    {
        $region = $link;
        while (
            ($parent = $region->parentElement) !== null
            && !in_array($parent->localName, self::CONTENT_BOUNDARIES, true)
            && $this->holdsShareControlsOnly($parent)
        ) {
            $region = $parent;
        }

        return $region;
    }

    /**
     * True when every link reachable through the element (directly, or through
     * a plain list wrapper) is a share intent and the rest of the element's own
     * text reads as a label, not as content of its own.
     */
    private function holdsShareControlsOnly(Element $element): bool
    {
        $links = $this->reachableLinks($element);
        if ($links === [] || !array_all($links, fn (Element $link): bool => $this->isShareIntent($link))) {
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

    private function isShareIntent(Element $link): bool
    {
        $href = $link->getAttribute('href');

        return $href !== null && $this->pointsAtAShareEndpoint($href) && $this->carriesTheSharedAddress($href);
    }

    private function pointsAtAShareEndpoint(string $href): bool
    {
        if (str_starts_with($href, 'mailto:')) {
            return true;
        }

        $hostAndPath = $this->hostAndPath($href);

        return array_any(
            self::SHARE_ENDPOINTS,
            fn (string $endpoint): bool => $this->matchesEndpointBoundary($hostAndPath, $endpoint),
        );
    }

    /**
     * A prefix match alone lets `reddit.com/submit-guidelines` match the
     * `reddit.com/submit` endpoint. The path must end there — the next
     * character is a separator or the string ends — so a longer, unrelated
     * path segment is rejected.
     */
    private function matchesEndpointBoundary(string $hostAndPath, string $endpoint): bool
    {
        $endpoint = rtrim($endpoint, '/');

        return $hostAndPath === $endpoint || str_starts_with($hostAndPath, $endpoint . '/');
    }

    private function hostAndPath(string $href): string
    {
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host . parse_url($href, PHP_URL_PATH);
    }

    private function carriesTheSharedAddress(string $href): bool
    {
        $queryStart = strpos($href, '?');
        if ($queryStart === false) {
            return false;
        }

        return preg_match(self::SHARED_ADDRESS_PATTERN, substr($href, $queryStart + 1)) === 1;
    }

    private function collapsedText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
