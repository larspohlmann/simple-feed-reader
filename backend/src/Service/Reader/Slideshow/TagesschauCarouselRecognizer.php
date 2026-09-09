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
            ContainerSignature::fromElement($carousel),
        );
    }

    private function slide(mixed $image): ?Slide
    {
        if (!is_array($image) || !is_array($image['imageUrls'] ?? null)) {
            return null;
        }

        foreach (self::RENDITIONS as $size) {
            $url = $image['imageUrls'][$size] ?? null;
            if (is_string($url) && $url !== '') {
                return new Slide(
                    $url,
                    $this->stringOf($image['alttext'] ?? null),
                    $this->captionFrom($image['description'] ?? null, $image['title'] ?? null),
                );
            }
        }

        return null;
    }

    /** The caption is the slide's description; the photo credit trails its title after a "|". */
    private function captionFrom(mixed $description, mixed $title): SlideCaption
    {
        $text = $this->stringOf($description);
        $credit = $this->creditFrom($this->stringOf($title));

        return new SlideCaption(trim($credit === '' ? $text : "$text ($credit)"), null);
    }

    private function creditFrom(string $title): string
    {
        if (!str_contains($title, '|')) {
            return '';
        }

        $parts = explode('|', $title);

        return trim((string) end($parts));
    }

    private function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
