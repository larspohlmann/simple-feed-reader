<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class QuotedPostContent
{
    public const int URL_MAX = 2048;
    public const int NAME_MAX = 255;
    public const int TEXT_MAX = 3000;
    public const int MEDIA_MAX = 4;

    /** @param list<EntryMedium> $media */
    public function __construct(
        public string $url,
        public string $authorName,
        public string $authorHandle,
        public ?string $authorAvatarUrl,
        public ?\DateTimeImmutable $postedAt,
        public string $text,
        public array $media,
        public ?QuotedPostReference $nestedQuote,
    ) {
    }
}
