<?php

declare(strict_types=1);

namespace App\Service\Image;

/**
 * A picture plus the dimensions its source declared: the URL, and the width and
 * height the origin stated for it.
 *
 * A feed usually declares the dimensions for its own item image; a scraped
 * og:image arrives with none. Roughly 60% of feeds declare neither, and the
 * Guardian declares width without height, so both are independently nullable.
 * Null means unknown, not square: a caller must treat "unknown" as a first-class
 * case and reserve no space rather than defaulting to a guess.
 */
final readonly class DeclaredImage
{
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
