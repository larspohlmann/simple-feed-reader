<?php

declare(strict_types=1);

namespace App\Service\Scraper;

/** One article-like item found on a scraped HTML page, before ParsedEntry mapping. */
final readonly class ScrapedItem
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $teaser = null,
        public ?string $imageUrl = null,
        public ?\DateTimeImmutable $publishedAt = null,
    ) {
    }
}
