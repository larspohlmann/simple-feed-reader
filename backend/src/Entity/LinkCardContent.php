<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class LinkCardContent
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public ?string $imageUrl,
        public string $siteName,
    ) {
    }
}
