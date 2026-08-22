<?php

declare(strict_types=1);

namespace App\Service\Preview;

final readonly class FeedPreviewItem
{
    public function __construct(
        public string $title,
        public ?string $url,
        public ?string $author,
        public ?string $summary,
        public ?string $imageUrl,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public ?\DateTimeImmutable $publishedAt,
    ) {
    }
}
