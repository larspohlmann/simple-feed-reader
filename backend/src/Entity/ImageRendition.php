<?php

declare(strict_types=1);

namespace App\Entity;

/** One declared-width rendition of an entry's lead picture, as a `srcset` candidate names it. */
final readonly class ImageRendition
{
    public function __construct(
        public string $url,
        public int $width,
    ) {
    }
}
