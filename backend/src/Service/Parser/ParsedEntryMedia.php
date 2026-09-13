<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;

final readonly class ParsedEntryMedia
{
    public function __construct(
        public ?DeclaredImage $image = null,
        public ?ParsedMediaBundle $mediaBundle = null,
    ) {
    }
}
