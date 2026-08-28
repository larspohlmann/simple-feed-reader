<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Html\Srcset;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Puts the article's lead photo back at the top of the extracted body when
 * readability dropped it.
 *
 * Readability strips a page-header image as chrome (mopo's `<figure
 * class="headerImage">`), then reports it separately as the og:image. The reader
 * used to re-add it as a floating "hero", suppressed whenever the body held any
 * image (#657) — which lost the lead on every article that also carries a second
 * picture. This restores the lead into the body itself instead.
 *
 * The lead is left out only to avoid stacking a picture the body already shows.
 * So it is added whenever the body has no image at all, and otherwise only when:
 *
 *   - the body does not OPEN with an image (it already leads with a picture);
 *   - the body does not already SHOW that photo (readability kept it); and
 *   - the lead is actually DRAWN on the fetched page — not a meta-only
 *     share-render (beat.de's opengraph file lives in the <meta> alone), which
 *     against a body that has its own image would only duplicate it.
 *
 * Same-photo identity is the light ImageIdentity fingerprint, not the per-CDN
 * URL normalisation #657 deleted: a missed match simply skips the restore, so
 * the worst case is today's behaviour and never a duplicated photo. Measured
 * over 120 articles from 20 feeds (#681): fixes 54, duplicates none. A purely
 * positional rule (no identity) was measured to duplicate 45 of the 120.
 */
final readonly class ReaderLeadImage
{
    /** Attributes a lazy-loaded <img> may carry its real URL in, plus `src`. */
    private const array URL_ATTRIBUTES = ['src', 'data-src', 'data-lazy-src', 'data-original'];

    public function restore(string $bodyHtml, string $pageHtml, ?string $leadUrl): string
    {
        if ($leadUrl === null || preg_match('#^https?://#i', $leadUrl) !== 1) {
            return $bodyHtml;
        }

        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        $body = $document?->body;
        if ($body === null) {
            return $bodyHtml;
        }

        $lead = ImageIdentity::fromUrl($leadUrl);
        if ($this->opensWithImage($body) || $this->bodyShowsLead($lead, $body)) {
            return $bodyHtml;
        }

        // A body that already carries some picture only takes the lead when the
        // page truly draws it; otherwise a meta-only share-render would double up.
        // A body with no picture has nothing to duplicate, so the lead goes in.
        if ($this->bodyHasImage($body) && !$this->drawnOnPage($lead, $pageHtml)) {
            return $bodyHtml;
        }

        $body->insertBefore($this->figure($document, $leadUrl), $body->firstChild);

        return $body->innerHTML;
    }

    private function drawnOnPage(ImageIdentity $lead, string $pageHtml): bool
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return false;
        }

        foreach ($this->renderedUrls($document) as $url) {
            if ($lead->matches(ImageIdentity::fromUrl($url))) {
                return true;
            }
        }

        return false;
    }

    private function bodyHasImage(Element $body): bool
    {
        return $body->getElementsByTagName('img')->length > 0;
    }

    private function bodyShowsLead(ImageIdentity $lead, Element $body): bool
    {
        foreach ($body->getElementsByTagName('img') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($source !== '' && $lead->matches(ImageIdentity::fromUrl($source))) {
                return true;
            }
        }

        return false;
    }

    /** True when the first content in document order is an image, not text. */
    private function opensWithImage(Element $body): bool
    {
        $pending = iterator_to_array($body->childNodes);
        while ($pending !== []) {
            $node = array_shift($pending);
            if ($node instanceof Element && $node->localName === 'img') {
                return true;
            }
            if ($node->nodeType === \XML_TEXT_NODE && trim((string) $node->textContent) !== '') {
                return false;
            }
            if ($node instanceof Element) {
                $pending = array_merge(iterator_to_array($node->childNodes), $pending);
            }
        }

        return false;
    }

    /**
     * Every image URL the page draws, across lazy-load and <picture> spellings.
     *
     * @return \Generator<string>
     */
    private function renderedUrls(HTMLDocument $document): \Generator
    {
        foreach ($document->getElementsByTagName('img') as $image) {
            foreach (self::URL_ATTRIBUTES as $attribute) {
                $url = trim($image->getAttribute($attribute) ?? '');
                if ($url !== '') {
                    yield $url;
                }
            }
            yield from $this->srcsetUrl($image);
        }
        foreach ($document->getElementsByTagName('source') as $source) {
            yield from $this->srcsetUrl($source);
        }
    }

    /** @return \Generator<string> the first srcset candidate of an element, if any */
    private function srcsetUrl(Element $element): \Generator
    {
        $first = Srcset::firstUrl($element->getAttribute('srcset'));
        if ($first !== null) {
            yield $first;
        }
    }

    private function figure(HTMLDocument $document, string $leadUrl): Element
    {
        $image = $document->createElement('img');
        $image->setAttribute('src', $leadUrl);
        $image->setAttribute('alt', '');
        $figure = $document->createElement('figure');
        $figure->appendChild($image);

        return $figure;
    }
}
