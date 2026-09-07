<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Single <img> per slide (EntrySanitizer strips <picture>/<source>).
 * class="reader-slideshow" is the client's carousel marker.
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
