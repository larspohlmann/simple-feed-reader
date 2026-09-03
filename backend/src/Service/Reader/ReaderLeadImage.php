<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Restores a page-drawn lead unless the body starts with an image, already
 * contains that asset, or a recovered player is about to be top-placed. A
 * share render — a subscribe card, a generated preview — is refused by what
 * it is: an imageless body takes any lead, so drawn-on-page cannot gate it
 * (#786).
 */
final readonly class ReaderLeadImage
{
    public function restore(HTMLDocument $document, LeadImageCandidate $lead, bool $willTopPlace): void
    {
        $body = $document->body;
        $leadUrl = $lead->url;
        if ($body === null || $leadUrl === null || preg_match('#^https?://#i', $leadUrl) !== 1) {
            return;
        }

        // A top-placed player becomes the article's lead visual and carries its
        // own poster; adding the hero above it would stack a second picture.
        if ($willTopPlace || !$this->belongsAbove($body, $lead)) {
            return;
        }

        $body->insertBefore($this->figure($document, $leadUrl), $body->firstChild);
    }

    private function belongsAbove(Element $body, LeadImageCandidate $lead): bool
    {
        $leadIdentity = ImageIdentity::fromUrl((string) $lead->url);
        if ($leadIdentity->isShareRender() || $this->opensWithImage($body)) {
            return false;
        }
        if ($this->bodyShowsLead($leadIdentity, $body)) {
            return false;
        }

        // A body that already carries some picture only takes the lead when the
        // page truly draws it; otherwise a meta-only share-render would double up.
        // A body with no picture has nothing to duplicate, so the lead goes in.
        return !$this->bodyHasImage($body) || $lead->pageImages->draws($leadIdentity);
    }

    private function bodyHasImage(Element $body): bool
    {
        return $body->getElementsByTagName('img')->length > 0;
    }

    private function bodyShowsLead(ImageIdentity $lead, Element $body): bool
    {
        foreach ($body->getElementsByTagName('img') as $image) {
            $source = $image->getAttribute('src') ?? '';
            if ($source !== '' && $lead->isSameAsset(ImageIdentity::fromUrl($source))) {
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
