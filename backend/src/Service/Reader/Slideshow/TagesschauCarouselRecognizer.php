<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Decodes a tagesschau "Bildergalerie": its slides live only in the
 * HTML-entity-encoded JSON on a `[data-v-type="Carousel"]` element's `data-v` (#926).
 */
final readonly class TagesschauCarouselRecognizer implements SlideshowRecognizerInterface
{
    /** Widest first: pick the largest rendition the sanitizer will keep as a bare src. */
    private const array RENDITIONS = ['l', 'm', 's', 'xs'];

    public function recognize(HTMLDocument $document, PageTextBlocks $textBlocks): array
    {
        $found = [];
        foreach ($document->querySelectorAll('[data-v-type="Carousel"]') as $carousel) {
            $show = $this->fromCarousel($carousel, $textBlocks);
            if ($show !== null) {
                $found[] = $show;
            }
        }

        return $found;
    }

    private function fromCarousel(Element $carousel, PageTextBlocks $textBlocks): ?Slideshow
    {
        $data = json_decode($carousel->getAttribute('data-v') ?? '', true);
        if (!is_array($data) || !isset($data['images']) || !is_array($data['images'])) {
            return null;
        }

        $slides = [];
        foreach ($data['images'] as $image) {
            $slide = $this->slide($image);
            if ($slide !== null) {
                $slides[] = $slide;
            }
        }

        return Slideshow::fromSlides(
            $slides,
            is_string($data['name'] ?? null) ? $data['name'] : null,
            $textBlocks->before($carousel),
            ContainerSignature::fromClassAttribute($carousel->getAttribute('class')),
        );
    }

    /** @param mixed $image */
    private function slide($image): ?Slide
    {
        if (!is_array($image) || !is_array($image['imageUrls'] ?? null)) {
            return null;
        }

        foreach (self::RENDITIONS as $size) {
            $url = $image['imageUrls'][$size] ?? null;
            if (is_string($url) && $url !== '') {
                return new Slide($url, is_string($image['alttext'] ?? null) ? $image['alttext'] : '');
            }
        }

        return null;
    }
}
