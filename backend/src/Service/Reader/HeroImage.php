<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * A picture offered to lead an article, with the dimensions its source declared.
 *
 * Null dimensions mean unknown, not square: the client then reserves no space
 * for the image. A feed usually declares them for its own picture; readability
 * reports none for an og:image it finds on the page.
 */
final readonly class HeroImage
{
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
