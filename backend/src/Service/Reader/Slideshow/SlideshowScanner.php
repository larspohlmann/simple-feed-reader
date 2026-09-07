<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every slideshow recognizer over the raw normalized page and returns the
 * combined result, anchoring each to the text block it followed.
 */
final readonly class SlideshowScanner
{
    /** @param iterable<SlideshowRecognizerInterface> $recognizers */
    public function __construct(
        #[AutowireIterator('app.slideshow_recognizer')]
        private iterable $recognizers,
    ) {
    }

    /** @return list<Slideshow> */
    public function scan(HTMLDocument $document): array
    {
        $textBlocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($this->recognizers as $recognizer) {
            foreach ($recognizer->recognize($document, $textBlocks) as $slideshow) {
                $found[] = $slideshow;
            }
        }

        return $found;
    }
}
