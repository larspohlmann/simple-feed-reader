<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Removes a paragraph or image that repeats the one just before it. A responsive
 * publisher emits the dek and the lead figure once per breakpoint and hides all
 * but one with CSS; the scraper keeps every copy because it never runs the page
 * styles, so the reader would show the same words and the same photo twice
 * (#963, measured on The Verge). Only a block that repeats its immediate
 * predecessor is dropped, so a sentence or a photo reused elsewhere survives.
 *
 * Mutates the shared document in place; ReaderBodyCleaner parses and serialises
 * once around it.
 */
final readonly class DuplicateBlockCollapser
{
    public function collapseIn(HTMLDocument $document): void
    {
        $this->collapseParagraphs($document);
        $this->collapseImages($document);
    }

    private function collapseParagraphs(HTMLDocument $document): void
    {
        $previousText = null;
        foreach ($this->prose($document) as $paragraph) {
            $text = $this->normalize((string) $paragraph->textContent);
            if ($text === $previousText) {
                $this->removeBlock($paragraph);
                continue;
            }
            $previousText = $text;
        }
    }

    private function collapseImages(HTMLDocument $document): void
    {
        $previousAsset = null;
        foreach ($this->images($document) as $image) {
            $asset = ImageIdentity::fromUrl((string) $image->getAttribute('src'));
            if ($previousAsset !== null && $asset->isSameAsset($previousAsset)) {
                $this->removeBlock($image);
                continue;
            }
            $previousAsset = $asset;
        }
    }

    /**
     * Paragraphs that carry prose, not a wrapped figure or a blank line: an
     * empty line never marks a duplicate, and an image is compared as an asset.
     *
     * @return list<Element>
     */
    private function prose(HTMLDocument $document): array
    {
        $paragraphs = [];
        foreach ($document->querySelectorAll('p') as $paragraph) {
            $carriesText = trim((string) $paragraph->textContent) !== '';
            if ($carriesText && $paragraph->querySelector('img, picture, video, iframe, audio') === null) {
                $paragraphs[] = $paragraph;
            }
        }

        return $paragraphs;
    }

    /** @return list<Element> */
    private function images(HTMLDocument $document): array
    {
        $images = [];
        foreach ($document->querySelectorAll('img') as $image) {
            if (trim((string) $image->getAttribute('src')) !== '') {
                $images[] = $image;
            }
        }

        return $images;
    }

    /** Remove the node, then the wrappers it leaves empty, so no blank paragraph or box survives. */
    private function removeBlock(Element $node): void
    {
        $parent = $node->parentElement;
        $node->remove();
        while ($parent instanceof Element && $this->isEmptyWrapper($parent)) {
            $grandparent = $parent->parentElement;
            $parent->remove();
            $parent = $grandparent;
        }
    }

    /** A structural root is never dissolved; a wrapper with no text and no media is. */
    private function isEmptyWrapper(Element $element): bool
    {
        if (in_array(strtoupper($element->nodeName), ['BODY', 'ARTICLE', 'MAIN', 'SECTION'], true)) {
            return false;
        }

        return trim((string) $element->textContent) === ''
            && $element->querySelector('img, picture, video, iframe, audio, svg') === null;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
