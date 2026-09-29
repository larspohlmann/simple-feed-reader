<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow\SlideshowRecognizer;

use App\Service\Reader\Media\Model\PageTextBlocksModel;
use App\Service\Reader\Slideshow\Model\ContainerSignatureModel;
use App\Service\Reader\Slideshow\Model\SlideModel;
use App\Service\Reader\Slideshow\Model\SlideshowModel;
use App\Service\Reader\Slideshow\SlideCaptionResolver;
use App\Service\Reader\Slideshow\SlideImageResolver;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Recognizes the CSS-class carousel libraries from one config row per library:
 * Swiper/Splide/Glide/Embla/Owl/Flickity/Slick/tiny-slider and the "purple" CMS
 * gallery (slideshowcontainer › slideshow-image) share the shape container-class
 * › slide-class, so the rule table replaces a class per library.
 */
final readonly class MarkupCarouselRecognizer implements SlideshowRecognizerInterface
{
    /** @var list<array{container: ?string, slide: ?string}> */
    private const array RULES = [
        ['container' => 'swiper', 'slide' => 'swiper-slide'],
        ['container' => 'splide', 'slide' => 'splide__slide'],
        ['container' => 'glide', 'slide' => 'glide__slide'],
        ['container' => 'embla', 'slide' => 'embla__slide'],
        ['container' => 'owl-carousel', 'slide' => null],
        ['container' => null, 'slide' => 'carousel-cell'],
        ['container' => 'slick-slider', 'slide' => 'slick-slide'],
        ['container' => null, 'slide' => 'tns-item'],
        ['container' => 'slideshowcontainer', 'slide' => 'slideshow-image'],
    ];

    public function __construct(
        private SlideImageResolver $images,
        private SlideCaptionResolver $captions,
    ) {
    }

    public function recognize(HTMLDocument $document, PageTextBlocksModel $textBlocks): array
    {
        $found = [];
        foreach (self::RULES as $rule) {
            foreach ($this->containers($document, $rule) as $container) {
                $show = $this->slideshow($container, $this->slidesOf($container, $rule), $textBlocks);
                if ($show !== null) {
                    $found[] = $show;
                }
            }
        }

        return $found;
    }

    /**
     * @param array{container: ?string, slide: ?string} $rule
     *
     * @return list<Element>
     */
    private function containers(HTMLDocument $document, array $rule): array
    {
        if ($rule['container'] !== null) {
            return $this->elementsMatching($document, $rule['container']);
        }

        // Container-less libraries: each set of slide-class siblings is a carousel.
        $byParent = [];
        foreach ($document->querySelectorAll('.' . $rule['slide']) as $slide) {
            $parent = $slide->parentElement;
            if ($parent !== null) {
                $byParent[spl_object_id($parent)] = $parent;
            }
        }

        return array_values($byParent);
    }

    /**
     * @param array{container: ?string, slide: ?string} $rule
     *
     * @return list<Element>
     */
    private function slidesOf(Element $container, array $rule): array
    {
        if ($rule['slide'] === null) {
            return $this->directChildren($container);
        }

        return $this->elementsMatching($container, $rule['slide']);
    }

    /**
     * \Dom\Element has no `children` property; walk element siblings instead.
     *
     * @return list<Element>
     */
    private function directChildren(Element $container): array
    {
        $children = [];
        for ($child = $container->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $children[] = $child;
        }

        return $children;
    }

    /** @param list<Element> $slideElements */
    private function slideshow(
        Element $container,
        array $slideElements,
        PageTextBlocksModel $textBlocks,
    ): ?SlideshowModel {
        return SlideshowModel::fromSlides(
            $this->slides($slideElements),
            null,
            $textBlocks->before($container),
            ContainerSignatureModel::fromElement($container),
        );
    }

    /**
     * @param list<Element> $slideElements
     *
     * @return list<SlideModel>
     */
    private function slides(array $slideElements): array
    {
        $urls = $this->images->resolveAll($slideElements);
        $slides = [];
        foreach ($slideElements as $index => $element) {
            $url = $urls[$index];
            if ($url !== null) {
                $slides[] = new SlideModel(
                    $url,
                    $element->getAttribute('title') ?? $this->altOf($element),
                    $this->captions->resolve($element),
                );
            }
        }

        return $slides;
    }

    private function altOf(Element $slide): string
    {
        foreach ($slide->getElementsByTagName('img') as $image) {
            $alt = $image->getAttribute('alt') ?? '';
            if ($alt !== '') {
                return $alt;
            }
        }

        return '';
    }

    /** @return list<Element> */
    private function elementsMatching(HTMLDocument|Element $scope, string $class): array
    {
        $elements = [];
        foreach ($scope->querySelectorAll('.' . $class) as $element) {
            $elements[] = $element;
        }

        return $elements;
    }
}
