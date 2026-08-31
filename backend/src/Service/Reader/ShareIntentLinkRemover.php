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
 * and a "Share this article" label. removeFrom() climbs from each share
 * intent link to the outermost ancestor that still holds only share-intent
 * links (directly) and no more than a short label's worth of other text, so
 * the whole bar goes with its label. The climb stops at <main>/<article>/
 * <body>, so it never reaches into real prose.
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
     * True when every direct-child link is a share intent and the rest of the
     * element's own text reads as a label, not as content of its own.
     */
    private function holdsShareControlsOnly(Element $element): bool
    {
        $directLinks = $this->directLinkChildren($element);
        if ($directLinks === [] || !array_all($directLinks, fn (Element $link): bool => $this->isShareIntent($link))) {
            return false;
        }

        return $this->textLengthOutsideLinks($element) <= self::CLUSTER_LABEL_LENGTH;
    }

    /** @return list<Element> */
    private function directLinkChildren(Element $element): array
    {
        $links = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && $child->localName === 'a') {
                $links[] = $child;
            }
        }

        return $links;
    }

    private function textLengthOutsideLinks(Element $element): int
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if (!($child instanceof Element && $child->localName === 'a')) {
                $text .= $child->textContent;
            }
        }

        return mb_strlen($this->collapsedText($text));
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
            static fn (string $endpoint): bool => str_starts_with($hostAndPath, $endpoint),
        );
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
