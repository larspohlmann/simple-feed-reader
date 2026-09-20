<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

final readonly class Slideshow
{
    /** @param list<Slide> $slides */
    private function __construct(
        public array $slides,
        public ?string $title,
        public ?string $precedingText,
        public ?ContainerSignature $container,
    ) {
    }

    /**
     * The floor lives here so every recognizer inherits it: fewer than two
     * distinct images is not a slideshow. A lone image is a single picture, and
     * a row that repeats one image is a placeholder carousel, not a gallery (#1091).
     *
     * @param list<Slide> $slides
     */
    public static function fromSlides(
        array $slides,
        ?string $title,
        ?string $precedingText,
        ?ContainerSignature $container,
    ): ?self {
        if (self::distinctImageCount($slides) < 2) {
            return null;
        }

        return new self($slides, $title, $precedingText, $container);
    }

    /** @param list<Slide> $slides */
    private static function distinctImageCount(array $slides): int
    {
        return count(array_unique(array_map(static fn (Slide $slide): string => $slide->imageUrl, $slides)));
    }
}
