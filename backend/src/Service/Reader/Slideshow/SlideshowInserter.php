<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Replaces the publisher's original carousel (removed first by class signature)
 * with the recreated figure, seated after the anchor block or appended at the
 * body's end — never dropped.
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
            $this->seat($body, $textBlocks, $slideshow);
        }
    }

    private function removeOriginal(Element $root, ?ContainerSignature $container): void
    {
        if ($container === null) {
            return;
        }
        $root->querySelector($container->toSelector())?->remove();
    }

    private function seat(HTMLDocument $body, PageTextBlocks $textBlocks, Slideshow $slideshow): void
    {
        $root = $body->body;
        if ($root === null) {
            return;
        }

        $figure = $this->markup->figureFor($body, $slideshow);
        $anchor = $slideshow->precedingText === null ? null : $textBlocks->withText($slideshow->precedingText);
        if ($anchor === null) {
            $root->appendChild($figure);

            return;
        }

        $anchor->parentNode?->insertBefore($figure, $anchor->nextSibling);
    }
}
