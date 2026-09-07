<?php

declare(strict_types=1);

namespace App\Service\Reader\Slideshow;

/**
 * The text that sits with a slide and, when the slide is a link, the URL it
 * points at. A slide with no text renders no caption (#930).
 */
final readonly class SlideCaption
{
    public function __construct(
        public string $text,
        public ?string $link,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    public function hasLink(): bool
    {
        return $this->link !== null;
    }
}
