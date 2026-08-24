<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Image\DeclaredImage;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * Decides whether a candidate picture may lead an article as its hero.
 *
 * This is the only implementation of the rule (#592). It is applied to more than
 * one (candidate, body) pair — see ReaderHeroResolver — so it knows nothing
 * about where either side came from.
 *
 * A hero exists only to give a lead picture to an article whose body does not
 * already open with one. So the hero is suppressed when the extracted body
 * already *leads* with an image — the first rendered thing is a picture,
 * regardless of which photo it is — because a hero on top would then stack two
 * images at the article head. It is also suppressed when the body repeats the
 * hero photo further down (matched by CDN image identity), so an illustrated
 * article never shows the same picture twice.
 *
 * The candidate is guarded to http(s) so a javascript:/data: URL from the page
 * can never reach the client's <img src>.
 */
final class HeroImageSelector
{
    /** Standard layout whitespace, excluding U+00A0 which is visible text. */
    private const string LAYOUT_WHITESPACE = " \t\n\r\f\v\0";

    public function select(?DeclaredImage $candidate, string $bodyHtml): ?DeclaredImage
    {
        if ($candidate === null || preg_match('#^https?://#i', $candidate->url) !== 1) {
            return null;
        }

        // Blank or unparsable html leaves no body to judge, so the candidate
        // stands. The parser wraps a bare fragment in <html><body> on its own.
        $body = HtmlDocumentParser::parseOrNull($bodyHtml)?->body;
        if ($body === null) {
            return $candidate;
        }
        if ($this->bodyLeadsWithImage($body) || $this->bodyRepeatsImage($body, $candidate->url)) {
            return null;
        }

        return $candidate;
    }

    /** Whether the first rendered node in the body is an image. */
    private function bodyLeadsWithImage(Element $body): bool
    {
        foreach ($this->nodesInRenderOrder($body) as $node) {
            if ($node instanceof Element && $node->localName === 'img') {
                return true;
            }
            if ($node instanceof Text && $this->isVisibleText($node->data)) {
                return false;
            }
        }

        return false;
    }

    /** Whether the body shows the same photo as the candidate somewhere. */
    private function bodyRepeatsImage(Element $body, string $candidate): bool
    {
        $candidateIdentity = $this->imageIdentity($candidate);

        return array_any(
            $this->bodyImageUrls($body),
            fn (string $bodyImageUrl): bool => $this->imageIdentity($bodyImageUrl) === $candidateIdentity,
        );
    }

    /** @return list<string> every src the body's <img> tags point at */
    private function bodyImageUrls(Element $body): array
    {
        $urls = [];
        foreach ($this->nodesInRenderOrder($body) as $node) {
            if (!($node instanceof Element) || $node->localName !== 'img') {
                continue;
            }
            $source = $node->getAttribute('src');
            if ($source !== null && $source !== '') {
                $urls[] = $source;
            }
        }

        return $urls;
    }

    /**
     * Every node under a root, depth-first, in document order.
     *
     * @return iterable<Node>
     */
    private function nodesInRenderOrder(Node $root): iterable
    {
        foreach ($root->childNodes as $child) {
            yield $child;
            yield from $this->nodesInRenderOrder($child);
        }
    }

    private function isVisibleText(string $text): bool
    {
        return trim($text, self::LAYOUT_WHITESPACE) !== '';
    }

    /**
     * A CDN-agnostic identity for an image: its full path without the file
     * extension, the query string, or a size-variant token, lowercased. Size
     * variants of one photo collapse to it whichever CDN convention names them:
     *   - the id in the basename with the size in a query
     *     (`/4943510.jpg?width=1200` vs `/4943510.webp?width=960`, mopo.de);
     *   - the id in the directory with each size a whole basename of the form
     *     `<crop>__WIDTHxHEIGHT`, where the crop word varies between sizes of the
     *     same photo (`/…-image-group/original__640x360` vs
     *     `/…-image-group/wide__660x371`, zeit.de entry 477263) — the
     *     `__WIDTHxHEIGHT` basename is dropped whole, leaving the directory;
     *   - the id and slug in the path with the size a whole numeric segment
     *     between them (`/picture/8685793/1200/<slug>.jpeg` vs
     *     `/picture/8685793/14/<slug>.webp`, taz.de entry 1358489) — the numeric
     *     segment before the file name is dropped, leaving id and slug;
     *   - the id in the path and the size a `-WIDTHxHEIGHT` suffix on a basename
     *     whose stable part identifies the photo (`/…/<uuid>/ai-toys-100-1920x1080`
     *     vs `/…/<uuid>/ai-toys-100-1920x1920`, deutschlandfunk.de entry 1358618)
     *     — the trailing size token is dropped, leaving the uuid path and the
     *     basename's stable part. This differs from zeit, where the whole basename
     *     is a variant crop word: zeit's `__` marks the whole basename for
     *     removal, so it is handled first and never reaches this narrower rule.
     * Distinct photos keep distinct ids, directories or slugs, so they keep
     * distinct identities. A URL with no path (e.g. `https://cdn.test`) keeps its
     * whole form, so it matches only itself.
     */
    private function imageIdentity(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || trim($path, '/') === '') {
            return strtolower($url);
        }

        $withoutExtension = preg_replace('/\.[a-z0-9]+$/i', '', $path);
        $withoutCropBasename = preg_replace('#/[^/]*__\d+x\d+.*$#', '', (string) $withoutExtension);
        $withoutSizeSuffix = preg_replace('#[-_]\d+x\d+$#', '', (string) $withoutCropBasename);
        $withoutSizeSegment = preg_replace('#/\d+(?=/[^/]+$)#', '', (string) $withoutSizeSuffix);

        return strtolower((string) $withoutSizeSegment);
    }
}
