<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\EmbedProviders;
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
 * A recovered embed is left out of both comparisons: the reader injects one per
 * in-body player, so consecutive embeds are distinct media, never a responsive
 * duplicate. Their fingerprints collide anyway — every YouTube poster's filename
 * is the quality label `hqdefault.jpg`, and a posterless embed (Vimeo,
 * SoundCloud, Brightcove) is a bare `<a>` carrying the provider's fixed label —
 * so without the skip the second of a pair would collapse into the first (#1051).
 *
 * Mutates the shared document in place; ReaderBodyCleaner parses and serialises
 * once around it.
 */
final readonly class DuplicateBlockCollapser
{
    public function __construct(private EmbedProviders $embedProviders)
    {
    }

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
            if ($previousAsset !== null && $asset->isSameRendition($previousAsset)) {
                $this->removeBlock($image);
                continue;
            }
            $previousAsset = $asset;
        }
    }

    /**
     * Paragraphs that carry prose, not a wrapped figure, a blank line or a
     * posterless embed: an empty line never marks a duplicate, an image is
     * compared as an asset, and an embed is media the reader injected.
     *
     * @return list<Element>
     */
    private function prose(HTMLDocument $document): array
    {
        $paragraphs = [];
        foreach ($document->querySelectorAll('p') as $paragraph) {
            if (trim((string) $paragraph->textContent) === '') {
                continue;
            }
            if ($paragraph->querySelector('img, picture, video, iframe, audio') !== null) {
                continue;
            }
            if ($this->isRecoveredEmbedAnchor($paragraph->querySelector('a'))) {
                continue;
            }
            $paragraphs[] = $paragraph;
        }

        return $paragraphs;
    }

    /** @return list<Element> */
    private function images(HTMLDocument $document): array
    {
        $images = [];
        foreach ($document->querySelectorAll('img') as $image) {
            $hasSource = trim((string) $image->getAttribute('src')) !== '';
            if ($hasSource && !$this->isRecoveredEmbedAnchor($image->closest('a'))) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /** True when the anchor is a recovered embed link, keyed by the shared provider allow-list. */
    private function isRecoveredEmbedAnchor(?Element $anchor): bool
    {
        return $anchor !== null
            && $this->embedProviders->resolve((string) $anchor->getAttribute('href')) !== null;
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
