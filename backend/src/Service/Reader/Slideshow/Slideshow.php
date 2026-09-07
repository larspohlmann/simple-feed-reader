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
     * The two-slide floor lives here so every recognizer inherits it: a lone
     * image is not a slideshow.
     *
     * @param list<Slide> $slides
     */
    public static function fromSlides(
        array $slides,
        ?string $title,
        ?string $precedingText,
        ?ContainerSignature $container,
    ): ?self {
        if (count($slides) < 2) {
            return null;
        }

        return new self($slides, $title, $precedingText, $container);
    }
}
