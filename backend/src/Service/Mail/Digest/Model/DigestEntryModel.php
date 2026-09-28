<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Model;

final readonly class DigestEntryModel
{
    public function __construct(
        public string $title,
        public string $feedName,
        public string $shortDescription,
        public string $url,
        public ?\DateTimeImmutable $publishedAt,
        public ?string $imageUrl,
        public ?string $faviconUrl,
    ) {
    }
}
