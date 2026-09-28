<?php

declare(strict_types=1);

namespace App\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Slideshow\ContainerSignature;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowMarkup;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Replaces the publisher's original carousel (removed first by class signature)
 * with the recreated figure, seated after the anchor block or appended at the
 * body's end — never dropped.
 */
final readonly class SlideshowInserter implements BodyCleaningStepInterface
{
    public function __construct(private SlideshowMarkup $markup)
    {
    }

    public function cleanIn(BodyCleaningPass $pass): void
    {
        $this->insert($pass->document, $pass->input->slideshows);
    }

    /** @param list<Slideshow> $slideshows */
    private function insert(HTMLDocument $body, array $slideshows): void
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
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if ($container->matches($element)) {
                $element->remove();

                return;
            }
        }
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
