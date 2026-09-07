<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Places each recreated slideshow into the cleaned body. The original carousel
 * may survive extraction as a broken pile of markup, so it is removed first by
 * its class signature; the recreated figure then lands after the prose block the
 * gallery followed, or at the body's end when that anchor did not survive — it
 * is never dropped.
 */
final readonly class SlideshowInserter
{
    public function __construct(private SlideshowMarkup $markup)
    {
    }

    /** @param list<Slideshow> $slideshows */
    public function insert(HTMLDocument $body, array $slideshows): void
    {
        $root = $body->body;
        if ($root === null || $slideshows === []) {
            return;
        }

        $textBlocks = PageTextBlocks::fromDocument($body);
        foreach ($slideshows as $slideshow) {
            $this->removeOriginal($root, $slideshow->container);
            $this->seat($body, $root, $textBlocks, $slideshow);
        }
    }

    private function removeOriginal(Element $root, ?ContainerSignature $container): void
    {
        if ($container === null) {
            return;
        }
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if ($container->matches($element)) {
                $element->remove();

                return;
            }
        }
    }

    private function seat(HTMLDocument $body, Element $root, PageTextBlocks $textBlocks, Slideshow $slideshow): void
    {
        $figure = $this->markup->figureFor($body, $slideshow);
        $anchor = $slideshow->precedingText === null ? null : $textBlocks->withText($slideshow->precedingText);
        if ($anchor === null) {
            $root->appendChild($figure);

            return;
        }

        $anchor->parentNode?->insertBefore($figure, $anchor->nextSibling);
    }
}
