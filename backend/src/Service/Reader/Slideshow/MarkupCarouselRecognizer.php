<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Recognizes the CSS-class carousel libraries from one config row per library:
 * Swiper/Splide/Glide/Embla/Owl/Flickity/Slick/tiny-slider share the shape
 * container-class › slide-class, so the rule table replaces a class per library.
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
    ];

    public function __construct(private SlideImageResolver $images)
    {
    }

    public function recognize(HTMLDocument $document, PageTextBlocks $textBlocks): array
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
    private function slideshow(Element $container, array $slideElements, PageTextBlocks $textBlocks): ?Slideshow
    {
        $slides = [];
        foreach ($slideElements as $element) {
            $url = $this->images->resolve($element);
            if ($url !== null) {
                $slides[] = new Slide($url, $element->getAttribute('title') ?? $this->altOf($element));
            }
        }

        return Slideshow::fromSlides(
            $slides,
            null,
            $textBlocks->before($container),
            ContainerSignature::fromClassAttribute($container->getAttribute('class')),
        );
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
