<?php

declare(strict_types=1);

namespace App\Service\Parser;

final readonly class ParsedEntry
{
    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
    ) {
    }
}
