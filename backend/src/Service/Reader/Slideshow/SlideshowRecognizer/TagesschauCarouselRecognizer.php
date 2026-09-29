<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow\SlideshowRecognizer;

use App\Service\Reader\Media\Model\PageTextBlocksModel;
use App\Service\Reader\Slideshow\Model\ContainerSignatureModel;
use App\Service\Reader\Slideshow\Model\SlideCaptionModel;
use App\Service\Reader\Slideshow\Model\SlideModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Decodes a tagesschau "Bildergalerie": its slides live only in the
 * HTML-entity-encoded JSON on a `[data-v-type="Carousel"]` element's `data-v`.
 */
final readonly class TagesschauCarouselRecognizer implements SlideshowRecognizerInterface
{
    /** Widest first: pick the largest rendition the sanitizer will keep as a bare src. */
    private const array RENDITIONS = ['l', 'm', 's', 'xs'];

    public function recognize(HTMLDocument $document, PageTextBlocksModel $textBlocks): array
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

    private function fromCarousel(Element $carousel, PageTextBlocksModel $textBlocks): ?SlideshowModel
    {
        $carouselConfig = json_decode($carousel->getAttribute('data-v') ?? '', true);
        if (!is_array($carouselConfig) || !isset($carouselConfig['images']) || !is_array($carouselConfig['images'])) {
            return null;
        }

        $slides = [];
        foreach ($carouselConfig['images'] as $image) {
            $slide = $this->slide($image);
            if ($slide !== null) {
                $slides[] = $slide;
            }
        }

        return SlideshowModel::fromSlides(
            $slides,
            is_string($carouselConfig['name'] ?? null) ? $carouselConfig['name'] : null,
            $textBlocks->before($carousel),
            ContainerSignatureModel::fromElement($carousel),
        );
    }

    private function slide(mixed $image): ?SlideModel
    {
        if (!is_array($image) || !is_array($image['imageUrls'] ?? null)) {
            return null;
        }

        foreach (self::RENDITIONS as $size) {
            $url = $image['imageUrls'][$size] ?? null;
            if (is_string($url) && $url !== '') {
                return new SlideModel(
                    $url,
                    $this->stringOf($image['alttext'] ?? null),
                    $this->captionFrom($image['description'] ?? null, $image['title'] ?? null),
                );
            }
        }

        return null;
    }

    /** The caption is the slide's description; the photo credit trails its title after a "|". */
    private function captionFrom(mixed $description, mixed $title): SlideCaptionModel
    {
        $text = $this->stringOf($description);
        $credit = $this->creditFrom($this->stringOf($title));

        return new SlideCaptionModel(trim($credit === '' ? $text : "$text ($credit)"), null);
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
