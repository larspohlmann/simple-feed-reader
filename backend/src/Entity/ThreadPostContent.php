<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class ThreadPostContent
{
    /** @param list<EntryMedium> $media */
    public function __construct(
        public ?string $url,
        public ?\DateTimeImmutable $publishedAt,
        public string $html,
        public array $media,
    ) {
    }
}
