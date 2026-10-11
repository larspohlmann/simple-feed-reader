<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class LinkCardContent
{
    public const int URL_MAX = 2048;
    public const int TITLE_MAX = 500;
    public const int SITE_NAME_MAX = 255;

    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public ?string $imageUrl,
        public string $siteName,
    ) {
    }
}
