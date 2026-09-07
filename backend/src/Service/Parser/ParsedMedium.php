<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * One visual media item a feed declared: an image or a video, the dimensions the
 * feed stated for it, and — for a video — the poster the feed pointed at. All
 * but the URL and kind are independently nullable; null means unknown.
 */
final readonly class ParsedMedium
{
    public function __construct(
        public string $url,
        public VisualMediaKind $kind,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $previewImageUrl = null,
    ) {
    }
}
