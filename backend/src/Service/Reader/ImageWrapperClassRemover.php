<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Readability removes a text-less `…Media…`-classed <div>, taking its picture
 * with it (#789). A one-image text-less wrapper carries no scoring signal
 * worth keeping; a linked image is a card, so it keeps its classes.
 */
final readonly class ImageWrapperClassRemover
{
    public function removeFrom(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('img') as $image) {
            if ($image->closest('a') === null && !PageFurniture::holds($image)) {
                $this->stripWrappersOf($image);
            }
        }
    }

    private function stripWrappersOf(Element $image): void
    {
        $wrapper = $image->parentNode;
        while ($wrapper instanceof Element && $this->isSoleImageWrapper($wrapper)) {
            $wrapper->removeAttribute('class');
            $wrapper->removeAttribute('id');
            $wrapper = $wrapper->parentNode;
        }
    }

    private function isSoleImageWrapper(Element $element): bool
    {
        return $element->localName !== 'body'
            && trim((string) $element->textContent) === ''
            && $element->querySelectorAll('img')->length === 1;
    }
}
