<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * An image attached to a feed item: the URL, plus the dimensions the feed
 * declared. Roughly 60% of feeds declare neither, and the Guardian declares
 * width without height, so both are independently nullable — a caller must
 * treat "unknown" as a first-class case rather than defaulting to a guess.
 */
final readonly class ParsedImage
{
    public function __construct(
        public string $url,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
