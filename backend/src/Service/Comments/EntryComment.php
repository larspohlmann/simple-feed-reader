<?php

declare(strict_types=1);

namespace App\Service\Comments;

final readonly class EntryComment
{
    public function __construct(
        public ?string $author,
        public ?string $authorUrl,
        public ?string $url,
        public ?\DateTimeImmutable $publishedAt,
        public string $html,
        public bool $byEntryAuthor,
    ) {
    }
}
