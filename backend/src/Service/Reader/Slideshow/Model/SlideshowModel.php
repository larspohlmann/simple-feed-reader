<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow\Model;

final readonly class SlideshowModel
{
    /** @param list<SlideModel> $slides */
    private function __construct(
        public array $slides,
        public ?string $title,
        public ?string $precedingText,
        public ?ContainerSignatureModel $container,
    ) {
    }

    /**
     * The floor lives here so every recognizer inherits it: fewer than two
     * distinct images is not a slideshow. A lone image is a single picture, and
     * a row that repeats one image is a placeholder carousel, not a gallery (#1091).
     *
     * @param list<SlideModel> $slides
     */
    public static function fromSlides(
        array $slides,
        ?string $title,
        ?string $precedingText,
        ?ContainerSignatureModel $container,
    ): ?self {
        if (self::distinctImageCount($slides) < 2) {
            return null;
        }

        return new self($slides, $title, $precedingText, $container);
    }

    /** @param list<SlideModel> $slides */
    private static function distinctImageCount(array $slides): int
    {
        return count(array_unique(array_map(static fn (SlideModel $slide): string => $slide->imageUrl, $slides)));
    }
}
