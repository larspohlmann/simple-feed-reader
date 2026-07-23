<?php

declare(strict_types=1);

namespace App\Service\Preview;

final readonly class FeedPreviewItem
{
    public function __construct(
        public string $title,
        public ?\DateTimeImmutable $publishedAt,
        public ?string $author,
        public bool $hasImage,
        public int $textLength,
        public string $snippet,
    ) {
    }
}
