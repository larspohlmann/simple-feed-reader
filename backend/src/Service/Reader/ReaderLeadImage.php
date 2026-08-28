<?php

declare(strict_types=1);

namespace App\Service\Reader;

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
 * It mutates the shared \Dom\HTMLDocument in place, like LeadingTitleRemover and
 * EdgeBoilerplateTrimmer, so ReaderBodyCleaner parses and serialises once around
 * it — the lead restore never re-parses the body or the page (#684).
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
 * "Drawn on the page" is answered by the PageImageInventory the caller built once
 * from the normalised page document, where LazyImageSources has already resolved
 * every lazy source — so this class no longer digs `data-*` attributes itself.
 *
 * Same-photo identity is the light ImageIdentity fingerprint, not the per-CDN
 * URL normalisation #657 deleted: a missed match simply skips the restore, so
 * the worst case is today's behaviour and never a duplicated photo. Measured
 * over 120 articles from 20 feeds (#681): fixes 54, duplicates none. A purely
 * positional rule (no identity) was measured to duplicate 45 of the 120.
 */
final readonly class ReaderLeadImage
{
    public function restore(HTMLDocument $document, LeadImageCandidate $lead): void
    {
        $leadUrl = $lead->url;
        if ($leadUrl === null || preg_match('#^https?://#i', $leadUrl) !== 1) {
            return;
        }

        $body = $document->body;
        if ($body === null) {
            return;
        }

        $leadIdentity = ImageIdentity::fromUrl($leadUrl);
        if ($this->opensWithImage($body) || $this->bodyShowsLead($leadIdentity, $body)) {
            return;
        }

        // A body that already carries some picture only takes the lead when the
        // page truly draws it; otherwise a meta-only share-render would double up.
        // A body with no picture has nothing to duplicate, so the lead goes in.
        if ($this->bodyHasImage($body) && !$lead->pageImages->draws($leadIdentity)) {
            return;
        }

        $body->insertBefore($this->figure($document, $leadUrl), $body->firstChild);
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
