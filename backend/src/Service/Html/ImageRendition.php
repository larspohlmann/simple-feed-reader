<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * One selectable rendition of an image: its URL and, when a width is declared,
 * the pixel width that lets callers compare renditions of the same picture and
 * keep the largest.
 */
final readonly class ImageRendition
{
    public function __construct(
        public string $url,
        public ?int $width,
    ) {
    }

    /** True when this rendition outsizes the other; an unmeasured one never does. */
    public function outsizes(self $other): bool
    {
        if ($this->width === null) {
            return false;
        }

        return $other->width === null || $this->width > $other->width;
    }
}
