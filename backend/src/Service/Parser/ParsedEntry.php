<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;

final readonly class ParsedEntry
{
    /**
     * @param list<ParsedMedium>     $media       visual media the feed declared,
     *                                            excluding the lead the ingest
     *                                            path prepends
     * @param list<ParsedAttachment> $attachments playable or downloadable
     *                                            enclosures the feed declared
     */
    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
        public ?DeclaredImage $image = null,
        public array $media = [],
        public array $attachments = [],
    ) {
    }
}
