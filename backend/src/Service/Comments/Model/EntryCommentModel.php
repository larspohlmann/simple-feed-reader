<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class EntryCommentModel
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
