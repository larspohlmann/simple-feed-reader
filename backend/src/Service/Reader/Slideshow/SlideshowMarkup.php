<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Builds the one markup shape every recreated slideshow uses: a
 * <figure class="reader-slideshow"> with an <ol> of single-image slides. The
 * class on the figure is the mark the client upgrades to a swipeable carousel;
 * with no JavaScript the figure reads as a captioned vertical image stack.
 *
 * Single <img> per slide, not <picture>: EntrySanitizer strips srcset and
 * <source>, so the recognizer already picked one URL per slide.
 */
final readonly class SlideshowMarkup
{
    public function figureFor(HTMLDocument $document, Slideshow $slideshow): Element
    {
        $figure = $document->createElement('figure');
        $figure->setAttribute('class', 'reader-slideshow');

        if ($slideshow->title !== null && $slideshow->title !== '') {
            $caption = $document->createElement('figcaption');
            $caption->appendChild($document->createTextNode($slideshow->title));
            $figure->appendChild($caption);
        }

        $list = $document->createElement('ol');
        foreach ($slideshow->slides as $index => $slide) {
            $list->appendChild($this->item($document, $slide, $index === 0));
        }
        $figure->appendChild($list);

        return $figure;
    }

    private function item(HTMLDocument $document, Slide $slide, bool $eager): Element
    {
        $image = $document->createElement('img');
        $image->setAttribute('src', $slide->imageUrl);
        $image->setAttribute('alt', $slide->alt);
        // The first image loads at once; the rest wait so a 24-slide gallery is
        // not 24 immediate requests.
        $image->setAttribute('loading', $eager ? 'eager' : 'lazy');

        $item = $document->createElement('li');
        $item->appendChild($image);

        return $item;
    }
}
