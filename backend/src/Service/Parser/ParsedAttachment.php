<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * One playable or downloadable enclosure a feed declared — a podcast audio file,
 * a video, or another file. Everything but the URL is independently nullable;
 * null means the feed did not state it.
 */
final readonly class ParsedAttachment
{
    public function __construct(
        public string $url,
        public ?string $mimeType = null,
        public ?int $durationInSeconds = null,
        public ?int $sizeInBytes = null,
        public ?string $title = null,
    ) {
    }
}
